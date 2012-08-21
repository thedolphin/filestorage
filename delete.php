<?php
    require 'common.php';

    init();

    $result = array('Status' => array('OK' => 0));

    try {

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

                $path = substr($filedata['UUID'], 32, 2) .'/'. substr($filedata['UUID'], 30, 2);
                $filename = $filedata['UUID'] .'.'. $filedata['Extension'];
                $targetdir = $config['node']['storage'] .'/'. $path;
                $targetfile = $targetdir .'/'. $filename;

                $filedata['Source'] = $path .'/'. $filename;
                if (!mq_broadcast(serialize(array('Action' => 'delete', 'Time' => time(), 'Data' => $filedata))))
                        throw new Exception('AMQPExchange::publish returned FALSE');

                $result['Status']['OK']++;
                $result[$fileindex] = array('OK' => 1);

            } catch (Exception $exception) {
                $result[$fileindex] = array('FAIL' => $exception->getMessage());
            }
        }

        header('HTTP/1.1 200 Ok');
        print str_replace('\/', '/', json_encode($result));

    } catch (Exception $exception) {
        header('HTTP/1.1 500 Server Error');
        print $exception->getMessage() . "\n";
    }
