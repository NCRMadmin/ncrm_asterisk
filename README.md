Данная интеграция основана на штатной интеграции ncrm и asterisk.

# NCRM HTTP

Файл отдает по HTTPS ответы на запросы ncrm, необходимые для работы плагина ncrm:

- Запрос списка каналов (с IP ncrm)
- Запрос на создание вызова (с IP ncrm)
- Запрос детализации вызовов (с IP ncrm)
- Запрос записи разговора (с IP пользователя)

## Требования

- Роутер с поддержкой Hairpin NAT (перенаправление пакетов LAN->WAN->LAN)
- Либо: локальный DNS сервис
- Желательно - статический IP;
- SSL сертификат (платный, letsencrypt)
- Домен с настроенной DNS записью вашего статического IP
- Интернет >1Mbps

Технические требования к платформе Asterisk:

- Поддержка AJAM или AMI
- Работа вебсервера с поддержкой протокола https
- PHP с поддержкой json_encode (7.4+)
- PHP с расширением PDO для бэкэнда CDR
- Сервер с Asterisk в одной сети с интеграцией

# Файлы

Перейти в `cd /var/www/html/` и распаковать проект в папку /var/www/html/ncrm_asterisk
ИЛИ
Склонить в `cd /var/www/html/ && git clone <https://github.com/daulet2030/ncrm_asterisk.git> && cd ncrm_asterisk` и распаковать или склонить проект в папку /var/www/html/ncrm_asterisk/

Приложения и файлы настроек:

```
./ncrm.php			# Интеграции с NCRM по HTTP
./config
  config.php.sample		# Настройки интеграции, надо скопировать этот файл в config.php и заполнить данными для интерации
./contrib
  extensions_ncrm.conf	# Контексты Asterisk для реализации дополнительных функций
  manager_ncrm.conf		# Пример настройки AMI пользователя
./README.md			# Этот файл

```

## Настройка интеграции

```
cp ./config/config.php.sample ./config/config.php
vi ./config/config.php

```

Нужно поменять ‘AC_DB_UNAME’ и ‘AC_DB_UPASS’.  Чтобы узнать эти данные перейдите по команде:

```
cat /etc/freepbx.conf */
```

Обратно перейдите по команде

```
vi ./config/config.php
```

И  меняете значение ‘AC_DB_UNAME’ в “AMPDBUSER” и ‘AC_DB_UPASS’ в “AMPDBPASS”

## Настройка asterisk

Настроим Asterisk:

```
Скопировать все что внутри ./contrib/manager_ncrm.conf в /etc/asterisk/manager_ncrm.conf  или в /etc/asterisk/manager_custom.conf для freepbx
asterisk -rx "manager reload"

```

Убедится что AMI включен в /etc/asterisk/manager.conf

```
[general]
enabled = yes
port = 5038
bindaddr = 0.0.0.0
webenabled = yes

```

Убедится что AJAM включен в /etc/asterisk/http.conf или в /etc/asterisk/http_additional.conf для freepbx

```
[general]
enabled=yes
enablestatic=no
bindaddr=::
bindport=8088
prefix=asterisk

```

Убедится что строка /asterisk/rawman есть в ответе команды `asterisk -rx "http show status"` из CLI asterisk-а

```
freepbx*CLI> http show status
HTTP Server Status:
Prefix: /asterisk
Server: Asterisk/16.17.0
Server Enabled and Bound to 0.0.0.0:8088

Enabled URI's:
/asterisk/httpstatus => Asterisk HTTP General Status
/asterisk/amanager => HTML Manager Event Interface w/Digest authentication
/asterisk/arawman => Raw HTTP Manager Event Interface w/Digest authentication
/asterisk/manager => HTML Manager Event Interface
/asterisk/rawman => Raw HTTP Manager Event Interface
/asterisk/static/... => Asterisk HTTP Static Delivery
/asterisk/amxml => XML Manager Event Interface w/Digest authentication
/asterisk/mxml => XML Manager Event Interface
/asterisk/ari/... => Asterisk RESTful API
/asterisk/ws => Asterisk HTTP WebSocket

```

Для систем на базе freepbx необходимо создать аналогичные настройки используя веб-интерфейс

Узнать логин и пароль от asterisk можно по этой команде

```
nano /etc/asterisk/manager_ncrm.conf

```

# Настройка БД

```
mysql
use asteriskcdrdb

```

Добавить поле для имени файла записи разговора:

```sql
ALTER TABLE `cdr` ADD `recordingfile` VARCHAR(120) NOT NULL;

```

Добавить поле для хранения времени добавления cdr записи:

```sql
ALTER TABLE `cdr` ADD `addtime` TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

```

Установить значение поля для старой записи:

```sql
UPDATE cdr SET addtime=calldate;

```

# Доступ к файлам записи разговоров

Если файлы хранятся на другом сервере, в ./config/config.php заменить {{asterisk_domain}} на ваш домен
`http://{{asterisk_domain}}/monitor/%Y/%m/%d/#`

# Настройка отдачи имени абонента сidlookup

Для настройки cidlookup нам нужно во FreePBX в разделе Admin — CallerID Lookup Sources добавить источник откуда мы будем брать имя звонящего:

Хост: <NCRM_DOMAIN>.ncrm.kz
Порт: 443
Имя пользователя: <пусто>
Пароль: <пусто>
Путь: api/widgets/users_for_ip/<NCRM_APIKEY>
Запрос: phone_number=[NUMBER]

Далее данный источник нам нужно активировать во входящей маршрутизации (Inbound Routes — Source)

Прежде чем проверять callerid lookup (например забить в ncrm свой мобильный и звонить на ваш did) нужно проверить, отдает ли ncrm имя звонящего, для этого нужно выполнить запрос в браузере:
https://<NCRM_DOMAIN>.ncrm.kz/api/widgets/contact_for_ip/<NCRM_APIKEY>/?phone_number=<PHONE_NUMBER>
Если все правильно, должны увидеть имя клиента

# Умная переадресация

Умная переадресация позволяет перевести вызов на ответственного менеджера
Добавьте Custom Destination (меню admin) ncrmtransfer,151,1
Добавьте в файл /etc/asterisk/extensions_custom.conf модификацию диалплана ncrmtransfer.

```
; 151 виртуальный добавочный
; DEFEXT 101 куда перенаправить если внутренний не найден, например Очередь (Queues/Ring Groups)
[ncrmtransfer]
exten => 151,1,Set(DEFEXT=101);
exten => 151,n,Set(CHANNEL(hangup_handler_push)=ncrm-add-call,s,1);
exten => 151,n,Set(NCRM_DOMAIN=example)
exten => 151,n,Set(NCRM_APIKEY=c3344443000001b2ccccbcf7ecccc4b7)
exten => 151,n,Set(TOEXT=${CURL()})
exten => 151,n,Set(TOEXT=${SHELL(curl --silent ${NCRM_DOMAIN}/api/widgets/users_for_ip/${NCRM_APIKEY}/?phone_number=${CALLERID(num)})})
exten => 151,n,GotoIf($[${TOEXT}]?from-internal,${TOEXT},1:from-internal,${DEFEXT},1)

```

В настройке Входящая маршрутизация Установить направление  Custom Destination / ncrmtransfer
Примените изменения

# Добавления звонков в NCRM

Добавьте в файл /etc/asterisk/cdr.conf параметр endbeforehexten=yes и примените настройки Asterisk командой «core reload» в CLI Asterisk. Этот параметр нужен для того, чтобы CDR-записи формировались до начала выполнения экстеншена h и обработчиков завершения вызова, при этом во время выполнения экстеншена h и обработчиков завершения вызова, функции CDR(duration) и CDR(billsec) будут выдавать правильные значения.
Добавьте в диалплан Asterisk /etc/asterisk/extensions_custom.conf следующий контекст:

```
[ncrm-add-call]
exten => s,1,Set(NCRM_DOMAIN=http://example.com)
exten => s,n,Set(NCRM_APIKEY=c3344443000001b2ccccbcf7ecccc4b7)
exten => s,n,Set(URL=${NCRM_DOMAIN}/api/widgets/hangup_incoming/${NCRM_APIKEY})
exten => s,n,System(curl -X POST -s -m 1 -H 'Content-Type: application/json' --data '{"call_date":"${CDR(start)}","src":"${CDR(src)}","did":"${DIALEDPEERNUMBER}","bill_sec":"${CDR(billsec)}","recording_file":"${CDR(recordingfile)}","$

[macro-dialout-trunk-predial-hook]
exten => s,1,Set(CHANNEL(hangup_handler_push)=ncrm-add-call,s,1);
exten => s,n,MacroExit()

```

ПРИМЕЧАНИЕ: Поле CDR recordingfile используется в диалплане FreePBX, но не является стандартным полем CDR, возможно вам нужно будет указать другую переменную вместо CDR(recordingfile).

# Это вилка проекта

https://github.com/iqtek/amocrm_asterisk
