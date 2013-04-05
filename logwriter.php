<?php
    require "common.php";

    try {

        init();
        mq_init();

        $amqp_sub = new AMQPQueue(new AMQPChannel($amqp_conn));
        $amqp_sub->SetName('filestorage.logwriter');

        while($message = $amqp_sub->get()) {
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
                        $data['spec']['size'] .' '. $data['spec'][$config['node']['hashalgo']] ."\n",
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

            $amqp_sub->ack($message->getDeliveryTag());
        }

    }

    catch (Exception $exception) {
        print $exception->getMessage() . "\n";
    }

