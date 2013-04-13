<?php
    require "common.php";

    try {

        $config = new config();
        $queue = new queue($config, 'filestorage.logwriter');

        while($message = $queue->get()) {
            $data = unserialize($message->getBody());
            $time = $data['time'];
            $date = date('Y-m-d H:i:s', $time);

            if($data['action'] == 'copy') {
                file_put_contents(
                    $config['log']['commit'],
                    $date . ' [' . $time . '] ' .
                        $data['clientip'] .
                        ' => ' . $data['host'] .' upload ' .
                        $data['meta']['UUID'] .'.'. $data['meta']['Extension'] .' '.
                        $data['group'] .' '.
                        $data['spec']['size'] .' '. $data['spec'][$config['node']['hashalgo']] . "\n",
                    FILE_APPEND | LOCK_EX );
            }

            if($data['action'] == 'delete') {
                file_put_contents(
                    $config['log']['commit'],
                    $date . ' [' . $time . '] ' .
                        $data['clientip'] .
                        ' => ' . $data['host'] .' delete '.
                        $data['meta']['UUID'] .'.'. $data['meta']['Extension'] ."\n",
                    FILE_APPEND | LOCK_EX );
            }

            $queue->ack($message);
        }

    }

    catch (Exception $exception) {
        print $exception->getMessage() . "\n";
    }

