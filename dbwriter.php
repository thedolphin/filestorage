<?php
    require "common.php";

    try {

        $config = new config();
        $queue = new queue($config, 'filestorage.dbwriter');

        if(!$db = mysql_connect ($config['db']['host'], $config['db']['user'], $config['db']['pass']))
            throw new Exception("Cannot connect to mysql");

        if(!mysql_select_db($config['db']['db'], $db))
            throw new Exception("Cannot connect to database");

        while($message = $queue->get()) {

            $data = unserialize($message->getBody());

            $uuid   = $data['meta']['UUID']; unset($data['meta']['UUID']);
            $hash   = $data['spec'][$config['node']['hashalgo']];

            $client = $data['clientip'];
            $groupindex = $data['group'];
            $time = $data['time'];

            if (isset($config['db']['skiplog']) && $config['db']['skiplog'] == 'yes')
                if (!mysql_query("SET sql_log_bin = 0"))
                    throw new Exception('Could not disable mysql binary logging: ' . mysql_error ($db));

            if (!mysql_query("BEGIN"))
                throw new Exception("Lack of transaction support detected");

            try {

                if ($data['action'] == 'copy') {

                    if(!mysql_query('INSERT IGNORE INTO files(`uuid`,`date`, `hash`, `group`) ' .
                        "VALUES (UNHEX(REPLACE('". $uuid ."', '-', '')), FROM_UNIXTIME(". $time ."), UNHEX('". $hash ."'), ". $groupindex .")"))
                        throw new Exception("Cannot insert file for UUID $uuid into table files: " . mysql_error ($db));

                    foreach ($data['meta'] as $attribute=>$value) {
                        if(!mysql_query("INSERT IGNORE INTO attributes(`uuid`, `attribute`, `value`) VALUES (UNHEX(REPLACE('". $uuid ."', '-', '')), '". $attribute ."', '". $value ."')", $db))
                            throw new Exception("Cannot insert attribute for UUID $uuid into table attributes: " . mysql_error ($db));
                    }
                }

                if ($data['action'] == 'delete') {
                    if ($config['db']['delete'] == 'no') {
                        if(!mysql_query("UPDATE IGNORE files SET deleted = TRUE, date = FROM_UNIXTIME(" .$time. ") WHERE `uuid` = UNHEX(REPLACE('". $uuid ."', '-', ''))"))
                            throw new Exception("Cannot update file with UUID $uuid: " . mysql_error ($db));
                    } else {
                        if(!mysql_query("DELETE IGNORE FROM files WHERE `uuid` = UNHEX(REPLACE('". $uuid ."', '-', ''))"))
                            throw new Exception("Cannot delete file with UUID $uuid: " . mysql_error ($db));

                        if(!mysql_query("DELETE IGNORE FROM attributes WHERE `uuid` = UNHEX(REPLACE('". $uuid ."', '-', ''))"))
                            throw new Exception("Cannot attributes with UUID $uuid into table files: " . mysql_error ($db));
                    }
                }

                if(!mysql_query("COMMIT")) throw new Exception("Cannot commit:" . mysql_error ($db));

            }

            catch(Exception $exception) {
                    mysql_query("ROLLBACK", $db);
                    throw $exception;
            }

            $queue->ack($message);
        }
    }

    catch (Exception $exception) {
        print $exception->getMessage() . "\n";
    }

