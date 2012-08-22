<?php
    require "common.php";

    try {

        init();
        mq_init();

        $amqp_sub = new AMQPQueue(new AMQPChannel($amqp_conn));
        $amqp_sub->SetName('filestorage.logwriter');

        while($message = $amqp_sub->get()) {
            $data = unserialize($message->getBody());
            $time = $data['Time'];
            $date = date('Y-m-d H:i:s', $time);

            if($data['Action'] == 'copy') {
                file_put_contents(
                    $config['log']['commit'],
                    $date . ' [' . $time . '] ' . $data['Action'] .' '. $data['Data']['UUID'] .'.'. $data['Data']['Extension'] .' '. $data['Data']['Size'] .' '. $data['Data']['Hash'] ."\n",
                    FILE_APPEND | LOCK_EX );
            }

            if($data['Action'] == 'delete') {
                file_put_contents(
                    $config['log']['commit'],
                    $date . ' [' . $time . '] ' . $data['Action'] .' '. $data['Data']['UUID'] .'.'. $data['Data']['Extension'] ."\n",
                    FILE_APPEND | LOCK_EX );
            }

            if($data['Action'] == 'dedup') {
                file_put_contents(
                    $config['log']['commit'],
                    $date . ' [' . $time . '] ' . $data['Action'] .' '. $data['Files'][0] .' => '. $data['Files'][1] ."\n",
                    FILE_APPEND | LOCK_EX );
            }


            $amqp_sub->ack($message->getDeliveryTag());
        }

    } catch (Exception $exception) {
        print $exception->getMessage() . "\n";
    }

