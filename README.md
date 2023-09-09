Данная интеграция основана на штатной интеграции ncrm и asterisk.

# Файлы

Перейти в `cd /var/www/html/` и распаковать проект в папку /var/www/html/ncrm_asterisk
ИЛИ
Склонить в `cd /var/www/html/ && git clone https://github.com/daulet2030/ncrm_asterisk.git && cd ncrm_asterisk` и распаковать или склонить проект в папку /var/www/html/ncrm_asterisk/

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

# NCRM HTTP

Файл отдает по HTTPS ответы на запросы ncrm, необходимые для работы плагина ncrm:
  * Запрос списка каналов (с IP ncrm)
  * Запрос на создание вызова (с IP ncrm)
  * Запрос детализации вызовов (с IP ncrm)
  * Запрос записи разговора (с IP пользователя)

## Требования

  * Роутер с поддержкой Hairpin NAT (перенаправление пакетов LAN->WAN->LAN)
  * Либо: локальный DNS сервис
  * Желательно - статический IP;
  * SSL сертификат (платный, letsencrypt)
  * Домен с настроенной DNS записью вашего статического IP
  * Интернет >1Mbps

Технические требования к платформе Asterisk: 
  * Поддержка AJAM или AMI
  * Работа вебсервера с поддержкой протокола https
  * PHP с поддержкой json_encode (7.4+)
  * PHP с расширением PDO для бэкэнда CDR
  * Сервер с Asterisk в одной сети с интеграцией

## Настройка apache
  - Настроить сертификат SSL в соответствии с инструкцией: https://www.digitalocean.com/community/tutorials/how-to-secure-apache-with-let-s-encrypt-on-centos-7

## Настройка asterisk

Настроим Asterisk:

```
ln -s ./contrib/manager_ncrm.conf /etc/asterisk/manager_ncrm.conf
echo \#include manager_ncrm.conf >> /etc/asterisk/manager.conf
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
Убедится что строка /asterisk/rawman есть в ответе команды `http show status` из CLI asterisk-а
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

## Настройка интеграции

```
cp ./config/config.php.sample ./config/config.php
vi ./config/config.php
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