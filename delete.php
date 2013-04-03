<?php
    require 'common.php';

    $result = array('Status' => array('OK' => 0));

    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $client = $_SERVER['HTTP_X_REAL_IP'];
    } else {
        $client = $_SERVER['REMOTE_ADDR'];
    }

    try {

        init();

        mq_init();
        mq_init_pub();

        foreach($_POST as $key => $item) {
            $datatype = substr($key, 0, 4);
            $dataindex = substr($key, 4);
            if ($datatype == "File" && is_numeric($dataindex)) {
                $files[$dataindex] = json_decode($item, true);
            }
        }

        if (count($files) == 0) throw new Exception('No data received - possible protocol error');

        foreach($files as $fileindex => &$filedata) {
            try {
                if (!isset($filedata['Extension'])) throw new Exception('no Extension value');
                if (!isset($filedata['UUID']))      throw new Exception('no UUID value');
                if (!preg_match('/^[0-9a-z]{8}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{12}$/', $filedata['UUID']))
                                                    throw new Exception('invalid UUID value');

                if (!mq_broadcast(serialize(array(
                            'action' => 'delete',
                            'time' => time(),
                            'clientip' => $client,
                            'meta' => $filedata))))

                    throw new Exception('AMQPExchange::publish returned FALSE');

                $result['Status']['OK']++;
                $result[$fileindex] = array('OK' => 1);

            }

            catch (Exception $exception) {
                $result[$fileindex] = array('FAIL' => $exception->getMessage());
            }
        }

        header('HTTP/1.1 200 Ok');
        print str_replace('\/', '/', json_encode($result));

    }

    catch (Exception $exception) {
        header('HTTP/1.1 500 Server Error');
        print $exception->getMessage() . "\n";
    }
