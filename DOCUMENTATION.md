# Filestorage FileStorage 4.0
## Общая информация
FS 4.0 представляет собой HTTP-сервис распределенного масштабируемого отказоустойчивого (при соответствующей конфигурации) хранения и отдачи файлов с функцией дедупликации на этапе сохранения.

Всё общение происходит методом JSON over HTTP POST. Формат JSON очень похож на JSON RPC, но им не является.

Запросы балансируются циклически через входящий балансер на пул серверов и попадает в группу.

Группа в терминах FS - это один или несколько серверов с единым номером группы и едиными префиксом возвращаемго URL. Номер группы сохраняется в БД.

Файл реплицируется подписчиками. Подписчиками должны быть члены группы, но не обязательно только они.

Спецификация протокола лежит здесь: (утеряно, можно понять по тестовым скриптам) (написано для FS3.0, но FS4.0 унаследовал протокол для совместимости)

При загрузке файла клиент передаёт обязательные UUID и Extension, которые становятся именем нового файла (UUID - это только UUID, Extension может быть любым), и любые другие метаданные, которые затем сохраняются в базу. Сервис возвращает URL сохраненного файла.

При удалении файла клиент передаёт UUID удаляемого файла. Поскольку мы не знаем, в какой группе лежит файл, то запрос на удаление передаётся через очередь во все группы. Теоретически можно опросить базу и получить номер группы, но на практике БД становится узким местом и значительно дешевле запросить удаление на всех группах. Это изначальная ошибка проектирования.

## Компоненты
1. nginx + nginx_upload_module - первичный приём файла, сохранение во временный каталог.
2. upload.php - обработка данных, полученных от nginx, перемещение файла в нужное место, отдача URL файла клиенту, дедупликация
3. rabbitmq - сервис очередей
4. delete.php - ничего не удаляет, а только ставить файл в очередь на удаление
5. replica.php - демон (здесь и далее тождественно равно скрипту под runit) - обработка очереди сообщений от upload.php и delete.php: реплицирует, если сконфигурировано, файл или удаляет.
6. dbwriter.php, logwriter.php - демоны, обрабатывающие очередь сообщений, сохраняющие активность FS в БД и в лог соответственно.

# Принципы работы
## Очередь
Каждая нода имеет свою очередь, так же отдельные очереди предназначены для logwriter и dbwriter. Входящ ие сообщения попадают в exchange типа headers и распределяются по очередям исходя из заголовков сообщения. В случае нового файла сообщение поподает в очереди для подписчиков ноды, базы и лога, а в случае удаления - во все очереди.
## Хранилище ноды
Состоит из трех каталогов:
- upload_store (/vol/storage.temp) - конфигурируется в nginx, временное хранение загруженных файлов перед обработкой, для ускорения используется tmpfs
- storage (/vol/storage) - конфигурируется в filestoage.ini, хранит имена файлов в виде UUID.ext
- hashstorage (/vol/storage.hash) - конфигурируется в filestorage.ini, хранит имена в виде хешей

Имена в storage и hashstorage указывают на одну и ту же inode (на одни и те же данные), т.е. hardlinked. Соответственно дубликаты в storage тоже получаются hardlinked.
Иными словами, один набор данных на ноде может иметь не одно имя (на практике у одного набора данных может быть более 50000 имен).
Путь к файлу генерируется из имени файла:
```
storage/01/02/05aa696c-1222-4110-876d-1c09ca02018e.jpeg hashstorage/3d/95/f1/3d95f1e6b636b1e62264f4ffc5201a9c45e86c09e473464956454b61ea7 38b58
```
Чтобы не дергать диск и не вычислять каждый раз, хеш сохраняется в расширенных атрибутах (extended attributes, хранится в метаданных).

Исходя из вышесказанного, копировать хранилище с машины на машину необходимо с сохранением хардлинков. Для этого использовать утилиту tools/fetch-n-dedup.pl, принимающую на вход список файлов, который генерируется с помощью find и передается на хост назначение через netcat

## База

Носит исключительно справочный характер и на данный момент имеет реальное применение только для отладки. Хранит имена файлов и метаданные.
```
mysql> show create table files\G
*************************** 1. row ***************************
Table: files
Create Table: CREATE TABLE `files` (
`uuid` binary(16) NOT NULL COMMENT 'Binary UUID value',
`date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
`hash` binary(32) NOT NULL COMMENT 'Hash of file content',
`group` int(11) NOT NULL COMMENT 'Group index',
        `deleted` tinyint(1) NOT NULL DEFAULT '0',

 `deleted` tinyint(1) NOT NULL DEFAULT '0',
`linked` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'True, if there is corresponding
valid records in main database',
PRIMARY KEY (`uuid`),
KEY `hash` (`hash`,`group`,`deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8
1 row in set (0.08 sec)
mysql> show create table attributes\G
*************************** 1. row ***************************
Table: attributes
Create Table: CREATE TABLE `attributes` (
`uuid` binary(16) NOT NULL COMMENT 'Binary UUID value',
`attribute` varchar(32) NOT NULL,
`value` varchar(256) NOT NULL,
KEY `uuid` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8
1 row in set (0.00 sec)
```
# На практике
```
[root@wm23 02]# stat /vol/storage/01/02/05aa696c-1222-4110-876d-1c09ca02018e.jpeg
File: `05aa696c-1222-4110-876d-1c09ca02018e.jpeg'
Size: 6869 Blocks: 16 IO Block: 4096 regular file
Device: 810h/2064d Inode: 4296568605 Links: 35652
Access: (0600/-rw-------) Uid: ( 80/ www) Gid: ( 80/ www)
Access: 2013-04-10 19:51:23.145243723 +0400
Modify: 2013-04-10 19:51:23.145243723 +0400
Change: 2013-07-20 02:30:12.063255984 +0400

[root@wm23 02]# attr -l 05aa696c-1222-4110-876d-1c09ca02018e.jpeg
Attribute "sha256" has a 64 byte value for 05aa696c-1222-4110-876d-1c09ca02018e.jpeg

[root@wm23 02]# attr -g sha256 05aa696c-1222-4110-876d-1c09ca02018e.jpeg
Attribute "sha256" had a 64 byte value for 05aa696c-1222-4110-876d-1c09ca02018e.jpeg: 3d95f1e6b636b1e62264f4ffc5201a9c45e86c09e473464956454b61ea738b58

[root@wm23 02]# stat /vol/storage.hash/3d/95/f1/3d95f1e6b636b1e62264f4ffc5201a9c45e86c09e473464956454b61ea738b58
File: `/vol/storage.hash/3d/95/f1/3d95f1e6b636b1e62264f4ffc5201a9c45e86c09e473464956454b61ea738b58'
Size: 6869 Blocks: 16 IO Block: 4096 regular file
Device: 810h/2064d Inode: 4296568605 Links: 35652
Access: (0600/-rw-------) Uid: ( 80/ www) Gid: ( 80/ www)
Access: 2013-04-10 19:51:23.145243723 +0400
Modify: 2013-04-10 19:51:23.145243723 +0400
Change: 2013-07-20 02:30:12.063255984 +0400
```
```
 [root@wm37 /]# mysql filestorage
mysql> select count(*) from files where `hash`=unhex('3d95f1e6b636b1e62264f4ffc5201a9 c45e86c09e473464956454b61ea738b58');
+----------+
| count(*) |
+----------+
| 70796    |
+----------+
1 row in set (1.33 sec)

mysql> select count(*), `group` from files where `hash`=unhex('3d95f1e6b636b1e62264f4 ffc5201a9c45e86c09e473464956454b61ea738b58') group by `group`;
+----------+-------+
| count(*) | group |
+----------+-------+
| 34286    | 8     |
| 36510    | 9     |
+----------+-------+
2 rows in set (0.07 sec)

mysql> select hex(`hash`), `date`, `group` from files where `uuid`=unhex('05aa696c122 24110876d1c09ca02018e');
+------------------------------------------------------------------+---------------------+-------+
| hex(`hash`)                                                      | date                | group |
+------------------------------------------------------------------+---------------------+-------+
| 3D95F1E6B636B1E62264F4FFC5201A9C45E86C09E473464956454B61EA738B58 | 2013-05-17 18:02:44 |     8 |
+------------------------------------------------------------------+---------------------+-------+
1 row in set (0.02 sec)

mysql> select attribute, value from attributes where `uuid`=unhex('05aa696c1222411087 6d1c09ca02018e');
+-----------+---------------------------------+
| attribute | value                           |
+-----------+---------------------------------+
| Extension | jpeg                            |
| Filename  | df_image7518263039386308517.jpg |
+-----------+---------------------------------+
2 rows in set (0.02 sec)

mysql> select fsurlbyhash(unhex('3D95F1E6B636B1E62264F4FFC5201A9C45E86C09E47346495645 4B61EA738B58'));
+----------------------------------------------------------------------------------------+
| fsurlbyhash(unhex('3D95F1E6B636B1E62264F4FFC5201A9C45E86C09E473464956454B61EA738B58') )|
+----------------------------------------------------------------------------------------+
| img08.wikimart.ru/f1/4c/000500de-a1f1-4d54-9265-c29d4d4cf1be.jpeg                      |
+----------------------------------------------------------------------------------------+
1 row in set (0.00 sec)
```

Найти все файлы по хешу можно только по базе. Хранимая процедура fsurlbyhash выдает первый попавшийся файл с нужным хешом.

# Загрузка файла
- клиент отправляет файл с метаданными (как минимум UUID и Extension)
- nginx принимает файл, вычисляет контрольную сумму (хеш) и сохраняет файл во временный каталог.
- upload.php смотрит, есть ли файл с именем хеша в hashstorage
  - если есть, то удаляет загруженный файл
  - если нет, то перемещает (rename) загруженный файл в hashstorage с именем в виде хеша
  - кладет hardlink в storage с именем UUID.Extension
  - отправляет сообщение в очередь с назначением "подписчики, база, лог"
  - replica.php, если настроен как подписчик, получает сообщение из очереди, скачивает файл с источника и, по тому же принципу, что и upload.php, сохраняет в hashstorage и storage.
  - dbwriter.php
    - получает сообщение из очереди
    - сохраняет имя файла и номер группы в табличку files
    - сохраняет все полученные метаданные (их может быть сколько угодно) в табличку attributes
  - logwriter.php получает сообщение из очереди и записывает минимальную информацию в лог

# Удаление файла
- клиент посылает запрос на удаление файла
- delete.php посылает сообщение в очередь с назначением "всем"
- replica.php
  - проверяет, есть ли файл в хранилище
  - проверяет количество ссылок на файл, и если оно меньше или равно 2, то удаляет файл из storage и hashstorage.
- dbwriter.php в зависимости от настроек либо удаляет записи, либо помечает запись в табличке files как удалённую (deleted=1)
- logwriter.php получает сообщение из очереди и записывает минимальную информацию в лог
# Установка новой ноды
## Hardware
RAID 10, no LVM
## Prerequisites
- centos 6.0+
- xfs
  - xfsprogs
  - xfsdump
- php
  - php-cli
  - php-fpm
  - php-fpm
  - php-mysql
  - php-pecl-xattr
  - php-pecl-amqp 1.0.9+
- python
  - python-pika
- nginx
  - nginx v1.2.x, не старше
  - nginx_upload_module v2.2, 12/08/2012+ runit
- git
## Storage Setup & Tuning
Understand and run tuneio.sh, enable on-boot startup via /etc/rc.local
## Deployment
- add user and fetch sources
```
groupadd -g 80 www; adduser -m -g 80 -u 80 wmww
mkdir /www; cd /www; git clone git@host:/filestorage
```
- symlink all files from /www/filestorage/etc to proper locations run nginx and php-fpm
- prepare directories
```
install -o www -g www -d /var/log/filestorage install -o www -g www -d /vol/storage
install -o www -g www -d /vol/storage.hash install -o www -g www -d /vol/storage.temp
```
- edit fstab and apply changes
```
tmpfs /vol/storage.temp tmpfs uid=80,gid=80 0 0
```  
- mount -a
- copy configuration template and edit it
- setup replication queue
- install replication service
```
cp /www/filestorage/setup/filestorage.ini-template /www/filestorage/filestorage.ini
cd /www/filestorage/setup; ./amqp-replica-setup.py
cd /www/filestorage/runit
for i in {1..16}; do ./install.sh fs.replica $i; done
chkconfig runsvdir on
service runsvdir start
```
- run tests
```
# ./test-upload.php
#!/usr/bin/php
19-07-2013 17:15:28: Generating some data
19-07-2013 17:15:29: Starting upload
19-07-2013 17:15:29: Curl call took 0.13 seconds
19-07-2013 17:15:29: Error:
19-07-2013 17:15:29: Http code: 200
19-07-2013 17:15:29: Body: {"Status":{"OK":5},"1":{"OK":"http://img04.wikimart.ru/6b/ed/abb02f84-5d27-42ba-a99a-0bd3b3ed6b16.bin" },"2":{"OK":"http://img04.wikimart.ru/e3/29/0aee552f-fef7-45ab-a570-e4012d29e361.bin"},"3":{"OK":"http://i mg04.wikimart.ru/5d/92/e27ca931-0de9-45f1-b084-2bb336925d57.bin"},"4":{"OK":"http://img04.wikimart.r u/e8/3d/82911723-688b-432d-9e08-fc63873de870.bin"},"5":{"OK":"http://img04.wikimart.ru/99/c3/e8b7923 c-f1ba-4174-a629-bc0437c39963.bin"}}
19-07-2013 17:15:29: All files have been uploaded successfully
19-07-2013 17:15:29: File testfile1.bin, saved to /6b/ed/abb02f84-5d27-42ba-a99a-0bd3b3ed6b16.bin found on local storage.
19-07-2013 17:15:29: Checksum is valid
19-07-2013 17:15:29: File testfile2.bin, saved to /e3/29/0aee552f-fef7-45ab-a570-e4012d29e361.bin found on local storage.
19-07-2013 17:15:29: Checksum is valid
19-07-2013 17:15:29: File testfile3.bin, saved to /5d/92/e27ca931-0de9-45f1-b084-2bb336925d57.bin found on local storage.
19-07-2013 17:15:29: Checksum is valid
19-07-2013 17:15:29: File testfile4.bin, saved to /e8/3d/82911723-688b-432d-9e08-fc63873de870.bin found on local storage.
19-07-2013 17:15:29: Checksum is valid
19-07-2013 17:15:29: File testfile5.bin, saved to /99/c3/e8b7923c-f1ba-4174-a629-bc0437c39963.bin found on local storage.
19-07-2013 17:15:29: Checksum is valid 19-07-2013 17:15:29: Done. Dumping full data 19-07-2013 17:15:29: All done
# ./test-upload.php
#!/usr/bin/php
19-07-2013 17:15:34: Generating some data
19-07-2013 17:15:34: Starting upload
19-07-2013 17:15:35: Curl call took 0.13 seconds
19-07-2013 17:15:35: Error:
19-07-2013 17:15:35: Http code: 200
19-07-2013 17:15:35: Body: {"Status":{"OK":5},"1":{"OK":"http://img04.wikimart.ru/02/2a/0f605b86-936b-438b-8407-9a25962a02b5.bin" },"2":{"OK":"http://img04.wikimart.ru/d9/05/78d7c5ee-3aca-4a62-a527-35a8e205d9e3.bin"},"3":{"OK":"http: //img04.wikimart.ru/d4/2f/7a2031b2-e744-4add-9afb-922c372fd463.bin"},"4":{"OK":"http://img04.wikimart.r u/6c/aa/d73b688c-e878-4d0c-9ffa-bb01e5aa6c17.bin"},"5":{"OK":"http://img04.wikimart.ru/91/04/f38de0af- 3306-46bd-a0a8-352f36049194.bin"}}
19-07-2013 17:15:35: All files have been uploaded successfully
19-07-2013 17:15:35: File testfile1.bin, saved to /02/2a/0f605b86-936b-438b-8407-9a25962a02b5.bin found on local storage.
19-07-2013 17:15:35: Checksum is valid
19-07-2013 17:15:35: File testfile2.bin, saved to /d9/05/78d7c5ee-3aca-4a62-a527-35a8e205d9e3.bin found on local storage.
19-07-2013 17:15:35: Checksum is valid
19-07-2013 17:15:35: File testfile3.bin, saved to /d4/2f/7a2031b2-e744-4add-9afb-922c372fd463.bin found on local storage.
19-07-2013 17:15:35: Checksum is valid
19-07-2013 17:15:35: File testfile4.bin, saved to /6c/aa/d73b688c-e878-4d0c-9ffa-bb01e5aa6c17.bin found on local storage.
19-07-2013 17:15:35: Checksum is valid
19-07-2013 17:15:35: File testfile5.bin, saved to /91/04/f38de0af-3306-46bd-a0a8-352f36049194.bin found on local storage.
19-07-2013 17:15:35: Checksum is valid
19-07-2013 17:15:35: Done. Dumping full data
19-07-2013 17:15:35: Found old data, merging
19-07-2013 17:15:35: All done
# ./test-delete.php
#!/usr/bin/php
19-07-2013 17:15:44: Deleting
19-07-2013 17:15:44: Curl call took 0.01 seconds
19-07-2013 17:15:44: Error:
19-07-2013 17:15:44: Http code: 200
19-07-2013 17:15:44: Body: {"Status":{"OK":10},"0":{"OK":1},"1":{"OK":1},"2":{"OK":1},"3":{"OK":1},"4":{"OK":1},"5":{"OK":1},"6":{"OK":1}, "7":{"OK":1},"8":{"OK":1},"9":{"OK":1}}
19-07-2013 17:15:44: All files have been deleted successfully
19-07-2013 17:15:44: Waiting 3 secs for queue to be processed
19-07-2013 17:15:47: File testfile1.bin, saved to /6b/ed/abb02f84-5d27-42ba-a99a-0bd3b3ed6b16.bin was not found on local storage
19-07-2013 17:15:47: File testfile2.bin, saved to /e3/29/0aee552f-fef7-45ab-a570-e4012d29e361.bin was not found on local storage
19-07-2013 17:15:47: File testfile3.bin, saved to /5d/92/e27ca931-0de9-45f1-b084-2bb336925d57.bin was not found on local storage
19-07-2013 17:15:47: File testfile4.bin, saved to /e8/3d/82911723-688b-432d-9e08-fc63873de870.bin was not found on local storage
19-07-2013 17:15:47: File testfile5.bin, saved to /99/c3/e8b7923c-f1ba-4174-a629-bc0437c39963.bin was not found on local storage
19-07-2013 17:15:47: File testfile1.bin, saved to /02/2a/0f605b86-936b-438b-8407-9a25962a02b5.bin was not found on local storage
19-07-2013 17:15:47: File testfile2.bin, saved to /d9/05/78d7c5ee-3aca-4a62-a527-35a8e205d9e3.bin was not found on local storage
19-07-2013 17:15:47: File testfile3.bin, saved to /d4/2f/7a2031b2-e744-4add-9afb-922c372fd463.bin was not found on local storage
19-07-2013 17:15:47: File testfile4.bin, saved to /6c/aa/d73b688c-e878-4d0c-9ffa-bb01e5aa6c17.bin was not found on local storage
19-07-2013 17:15:47: File testfile5.bin, saved to /91/04/f38de0af-3306-46bd-a0a8-352f36049194.bin was not found on local storage
19-07-2013 17:15:47: Dumping
19-07-2013 17:15:47: All done
# find /vol -type f
```

## Files
### tuneio.sh
```
#!/bin/sh
tunedev() {
  echo deadline > /sys/block/$1/queue/scheduler
  echo 150 > /sys/block/$1/queue/iosched/read_expire echo 500 > /sys/block/$1/queue/iosched/write_expire echo 0 > /sys/block/$1/queue/iosched/front_merges
}

tunecache() {
  echo 100 > /proc/sys/vm/dirty_expire_centisecs echo 5 > /proc/sys/vm/dirty_background_ratio echo 10 > /proc/sys/vm/dirty_ratio
  echo 300 > /proc/sys/vm/dirty_writeback_centisecs
}

tunexfs() {
  echo 100 > /proc/sys/fs/xfs/age_buffer_centisecs echo 50 > /proc/sys/fs/xfs/xfsbufd_centisecs
}

tuneflush() {
  xfsbufd=$(ps -e -o pid= -o comm= | awk '/xfsbufd\/sdb/ { print $1 }') ionice -c 2 -n 0 -p $xfsbufd
}

tunedev sdb
tunecache
tunexfs
tuneflush
``` 

# FileStorage 4.99
## Цель
Рефакторинг текущей реализации FileStorage
## Причины
- модуль nginx_file_upload более не поддерживается и не собирается с современными версиями nginx в текущей реализации отсутствует возможность межпроцессной синхронизации, необходимой для эффективной дедупликации (невозможно обеспечить атомарность операций дедупликации и удаления, сейчас реализовано с помощью Giant FLock)
- существует архитектурная ошибка с удалением файла: неизвестно с какой ноды надо удалить файл существующая реализация многокомпонентна и не удобна для массового разворачивания, кроме того нужно избавиться от последней лишней файловой операции, возникающей на стыке компонент (nginx и php)
- при массовой заливке все треды nginx могут быть заняты, провоцируя connection timeout и долгое время ожидания даже на отдачу.
## Архитектурные изменения
- Ссылка должна целиком генерироваться на стороне FS
- Клиент при удалении файла должен передавать полную ссылку
- Протокол должен быть стандартным, например JSON RPC
## Средства
- python3+
  - более полная поддержка ОС (в частности java не умеет работать с Extended Attributes без сторонних модулей)
  - out-of-the-box поддержка
    - socket server
    - extended attributes
    - threading
    - более стабильный модуль rabbitmq
    - минус: GIL, не позволяющий эффективно распараллелить процессы
  - Twisted framework
    - event-driven framework

С появлением Java7 и NIO.2, java, видимо, становится более эффективной платформой.

