<?php
    require "common.php";

    init();
    mq_init();
    mq_init_pub();

    $prefix = 'http://wm23.i';
    $host = 'wm23';

    die('Set $prefix and $host');

    $hit = 0;
    $miss = 0;

    if(!$db = mysql_connect ($config['db']['host'], $config['db']['user'], $config['db']['pass'])) throw new Exception("Cannot connect to mysql");
    if(!mysql_select_db($config['db']['db'], $db)) throw new Exception("Cannot connect to database");

    if($res = mysql_query("SELECT `uuid-text`, `ext` FROM files WHERE `group` = " .$config['group']['index']. " AND `deleted` = 0")) {
        while($rec = mysql_fetch_assoc($res)) {
            $dir = substr($rec['uuid-text'], 32, 2) .'/'. substr($rec['uuid-text'], 30, 2);
            $file = $rec['uuid-text'] .'.'. $rec['ext'];
            $root = $config['node']['storage'];
            $localfile = $root .'/'. $dir .'/'. $file;
            if (file_exists($localfile)) {
                $hit++;
            } else {
                print "$localfile NOT FOUND\n";
                $miss++;

                $message = serialize(array(
                         'Action' => 'copy',
                         'Prefix' => $prefix,
                         'Data' => array('Source' => $dir .'/'. $file)));
#                print $message . '\n';
#                $amqp_pub->publish($message, '', 0, array('headers' => array( $host => 'yes' )));
            }
        }
    }

    print "HITS: $hit, MISSES: $miss\n";
