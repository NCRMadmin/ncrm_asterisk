<?php

// Load configuration settings
require_once dirname(__FILE__) . "/config/config.php";

require_once dirname(__FILE__) . "/include/functions.cdr.php";
require_once dirname(__FILE__) . "/include/functions.astman.php";
require_once dirname(__FILE__) . "/include/functions.file.php";

if (AC_HOST < 1) {
    die('Please, configure settings first! Copy  config.php.sample to config.php and set all variables');
}

$db_cs = AC_DB_CS;
$db_u = !strlen(AC_DB_UNAME) ? NULL : AC_DB_UNAME;
$db_p = !strlen(AC_DB_UPASS) ? NULL : AC_DB_UPASS;
date_default_timezone_set('UTC');

// Check if AC_RECORD_PATH is defined and GETFILE parameter is present, this is a request for file
if (defined('AC_RECORD_PATH') && !empty($_GET['GETFILE'])) {
    $recordPath = AC_RECORD_PATH;

    // Handle empty record path
    if (empty($recordPath)) {
        die('Error while getting file from Asterisk');
    }

    try {
        $dbh = new PDO($db_cs, $db_u, $db_p);
        $sth = $dbh->prepare('SELECT calldate,recordingfile FROM cdr WHERE uniqueid = :uid LIMIT 1');
        $sth->bindValue(':uid', strval($_GET['GETFILE']));
        $sth->execute();
        $record = $sth->fetch(PDO::FETCH_ASSOC);

        // Handle invalid record or missing recordingfile
        if ($record === false || empty($record['recordingfile'])) {
            header("HTTP/1.0 404 Not Found");
            die();
        }

        // Replace placeholders in the record path with corresponding values
        $date = strtotime($record['calldate']);
        $replace = [
            '#' => $record['recordingfile'],
            '%d' => date('d', $date),
            '%m' => date('m', $date),
            '%Y' => date('Y', $date),
            '%y' => date('y', $date),
        ];
        $recordPath = trim(str_replace(array_keys($replace), array_values($replace), $recordPath));
    } catch (PDOException $e) {
        header("HTTP/1.0 Internal Server Error");
        die();
    }

    $directFileDownload = AC_DIRECT_FILE_DOWNLOAD;
    if ($directFileDownload) {
        redirectToFile($recordPath);
    } else {
        // Retrieve and process the file based on the scheme in the record path
        $tmpFile = getFileFromURL($recordPath);

        // Handle unsuccessful file retrieval
        if (!$tmpFile) {
            die('Unable to retrieve file');
        }

        // Encode the file to mp3 format (if required) and return the file
        $encodedFile = encodeToMp3($tmpFile);

        // Remove the temporary file
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }

        // Redirect or return the encoded file
        handleFileOutput($encodedFile, $recordPath);
    }
}



// if not a file request then it's an action request
// Filter and retrieve parameters from _GET
$requiredParams = ['login', 'secret', 'action'];
foreach ($requiredParams as $param) {
    if (empty($_GET['_' . $param])) {
        answer(['response' => 'error', 'message' => 'NO_PARAMS']);
    }
}

$login = strval($_GET['_login']);
$secret = strval($_GET['_secret']);
$action = strval($_GET['_action']);

// Attempt to check access
$loginArr = [
    'Action' => 'Login',
    'username' => $login,
    'secret' => $secret,
    'Events' => 'off',
];

$resp = asterisk_req($loginArr, true);

if ($resp[0]['response'] !== 'Success') {
    answer(array('status' => 'error', 'data' => $resp[0]));
}

switch ($action) {
    case 'status':
        $params = array('action' => 'status');
        $resp = asterisk_req($params);
        if ($resp[0]['response'] !== 'Success') {
            answer(array('status' => 'error', 'data' => $resp[0]));
        }
        unset($resp[end(array_keys($resp))], $resp[0]);
        $resp = array_values($resp);
        foreach ($resp as $k => $v) {
            /* Reduce traffic amount and send only calls, initiating popup */
            if ($v['state'] != 'Ringing' && $v['channelstatedesc'] != 'Ringing') {
                unset($resp[$k]);
                continue;
            }
            /* If connectedlinenum is not defined - js error occure in browser */
            if (!isset($resp[$k]['connectedlinenum'])) {
                $resp[$k]['connectedlinenum'] = $v['calleridnum'];
            }
        }
        answer(array('status' => 'ok', 'action' => $action, 'data' => array_values($resp)));
        break;
    case 'call':
        $to = $_GET['to'];
        $context = AC_INTEGRATION_OUTBOUND_CONTEXT;
        $from = AC_INTEGRATION_SIPCHANNEL . '/' . intval($_GET['from']);
        /* Get device for 'from' */
        $params = array(
            'action' => 'Originate',
            'channel' => $from,
            'Exten' => $to,
            'Context' => $context,
            'priority' => '1',
            'Callerid' => '"' . strval($_GET['as']) . '" <' . intval($_GET['from']) . '>',
            'Async' => 'Yes'
        );
        //var_dump($params);
        $resp = asterisk_req($params, true);
        if ($resp[0]['response'] !== 'Success') {
            answer(array('status' => 'error', 'data' => $resp[0]));
        }
        answer(array('status' => 'ok', 'action' => $action, 'data' => $resp[0]));
        break;
    case 'cdr':
        try {
            // Get date and time
            $date_from = (!empty($_GET['date_from'])) ? doubleval($_GET['date_from']) : 0;
            $date_to = (!empty($_GET['date_to'])) ? doubleval($_GET['date_to']) : 0;

            if ($date_from < 0) {
                $date_from = time() - $date_from;
            }

            // Set default date range if necessary
            $date_from = max($date_from, time() - 10 * 24 * 3600);
            $date_from = $date_from ? $date_from + AC_TIME_DELTA * 3600 : 0; // Default to 01-01-1970
            $date_to = $date_to ? $date_to + AC_TIME_DELTA * 3600 : time() + AC_TIME_DELTA * 3600; // Default to now()

            // Create a PDO connection
            $dbh = new PDO($db_cs, $db_u, $db_p);
            $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Fetch CDR records based on date range
            $r = get_cdr($dbh, $date_from, $date_to);

            // Set the custom response header
            header("X-REAL_DATE:" . gmdate('Y-m-d H:i:s', $date_from) . '@' . gmdate('Y-m-d H:i:s', $date_to));

            // Send the response as JSON with a callback
            answer(['status' => 'ok', 'data' => $r], true);
        } catch (PDOException $e) {
            // Handle any PDO exceptions and return the error message
            answer(['status' => 'error', 'data' => $e->getMessage()], true);
        }
        break;
}
