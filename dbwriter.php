<?php
    require "common.php";

    try {

        init();
        mq_init();
        mq_init_pub();

        if(!$db = mysql_connect ($config['db']['host'], $config['db']['user'], $config['db']['pass'])) throw new Exception("Cannot connect to mysql");
        if(!mysql_select_db($config['db']['db'], $db)) throw new Exception("Cannot connect to database");

        $amqp_sub = new AMQPQueue(new AMQPChannel($amqp_conn));
        $amqp_sub->SetName('filestorage.dbwriter');

        while($message = $amqp_sub->get()) {

            $data = unserialize($message->getBody());

            $uuid   = $data['Data']['UUID'];      unset($data['Data']['UUID']);
            $source = $data['Data']['Source'];    unset($data['Data']['Source']);
            $hash   = $data['Data']['Hash'];      unset($data['Data']['Hash']);
            $size   = $data['Data']['Size'];      unset($data['Data']['Size']);
            $ext    = $data['Data']['Extension']; unset($data['Data']['Extension']);

            $groupindex = $data['GroupIndex'];
            $time = $data['Time'];


            if (!mysql_query("BEGIN")) throw new Exception("Lack of transaction support detected");

            try {

                if ($data['Action'] == 'copy') {

                    unset($dup);
                    if($res = mysql_query("SELECT `uuid-text`, `ext` FROM files WHERE `hash` = UNHEX('" .$hash. "') AND `size` = " .$size. " AND `group` = " .$groupindex. " AND `deleted` = 0")) {
                        if (mysql_num_rows($res) > 0) {
                            $row = mysql_fetch_assoc($res);
                            $dst = substr($row['uuid-text'], 32, 2) .'/'. substr($row['uuid-text'], 30, 2) .'/'. $row['uuid-text'] .'.'. $row['ext'];
                            if ($source != $dst) {
                                $dup = array();
                                $dup['Action'] = 'dedup';
                                $dup['Time'] = time();
                                $dup['Files'][] = $source;
                                $dup['Files'][] = $dst;
                            }
                        }
                    } else {
                        throw new Exception("Query error:" . mysql_error());
                    }

                    if(!mysql_query("INSERT IGNORE INTO files(`uuid`,`uuid-text`,`date`, `hash`, `size`, `ext`, `group`) VALUES (UNHEX(REPLACE('". $uuid ."', '-', '')), '". $uuid ."', FROM_UNIXTIME(" .$time. "), UNHEX('" .$hash."'), " .$size. ", '" .$ext. "', " .$groupindex. ")"))
                        throw new Exception("Cannot insert file for UUID $uuid into table files: " . mysql_error ($db));

                    foreach ($data['Data'] as $attribute=>$value) {
                        if(!mysql_query("INSERT IGNORE INTO attributes(`uuid`, `attribute`, `value`) VALUES (UNHEX(REPLACE('". $uuid ."', '-', '')), '". $attribute ."', '". $value ."')", $db))
                            throw new Exception("Cannot insert attribute for UUID $uuid into table attributes: " . mysql_error ($db));
                    }

                    if (isset($dup)) {
                        mq_send_to_group(serialize($dup), $groupindex);
                    }
                }

                if ($data['Action'] == 'delete') {
                    if(!mysql_query("UPDATE IGNORE files SET deleted = TRUE, date = FROM_UNIXTIME(" .$time. ") WHERE `uuid` = UNHEX(REPLACE('". $uuid ."', '-', ''))"))
                        throw new Exception("Cannot update file for UUID $uuid into table files: " . mysql_error ($db));
                }

                if(!mysql_query("COMMIT")) throw new Exception("Cannot commit:" . mysql_error ($db));

            } catch(Exception $exception) {
                    mysql_query("ROLLBACK", $db);
                    throw $exception;
            }


            $amqp_sub->ack($message->getDeliveryTag());

        }

    } catch (Exception $exception) {
        print $exception->getMessage() . "\n";
    }

