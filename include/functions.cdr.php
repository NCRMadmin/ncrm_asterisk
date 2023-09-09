<?php

function get_cdr($dbh, $date_from, $date_to, $minsec = 5, $blacklist = array()) {
	$stmt = 'SELECT calldate, src,dst,duration,billsec,uniqueid,recordingfile,dstchannel,dcontext FROM cdr WHERE disposition=\'ANSWERED\' AND billsec>=:minsec AND addtime>:from AND addtime<:to';
	$sth = $dbh->prepare($stmt);
	$sth->bindValue(':from', date('Y-m-d H:i:s',$date_from) );
	$sth->bindValue(':to', date('Y-m-d H:i:s',$date_to));
	$sth->bindValue(':minsec', $minsec, PDO::PARAM_INT);
	$sth->execute();
	$r = $sth->fetchAll(PDO::FETCH_ASSOC);

	foreach ($r as $k=>$v) {
		$r[$k]['calldate'] = date('Y-m-d H:i:s',strtotime($v['calldate'])-AC_TIME_DELTA*3600);
		if ($v['dcontext'] == 'ext-group') {
			if (preg_match("/SIP\/(\d+)\-/", $v['dstchannel'], $m)) {
				$r[$k]['dst'] = $m[1];
			}
		}
		if (in_array($v['dst'], $blacklist) || in_array($v['src'], $blacklist)) { /* Do not upload calls with particular number */
			unset($r[$k]);
			continue;
		}
		if (strlen($v['dst']) <=4 && strlen($v['src']) <= 4) { /* Do not upload internal calls */
			unset($r[$k]);
			continue;
		}
		unset($r[$k]['dstchannel']);
		unset($r[$k]['dcontext']);
	}
	return filter_cdr(array_values($r));
}

/* Send only unique calls to prevent having calls in ncrm loaded twice */
function filter_cdr($cdr) {
	$taken = array();

	foreach($cdr as $key => $item) {
    		if(!in_array($item['uniqueid'], $taken)) {
        		$taken[] = $item['uniqueid'];
    		} else {
        		unset($cdr[$key]);
    		}
	}
	return array_values($cdr);
}
