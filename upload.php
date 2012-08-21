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
                $filefields++;
                $filespec = json_decode($item, true);
                if (is_array($files[$dataindex])) {
                    $files[$dataindex] = array_merge($files[$dataindex], $filespec);
                } else {
                    $files[$dataindex] = $filespec;
                }
            }
            if ($datatype == "Data" && is_numeric($dataindex)) {
                $datafields++;
                $filespec = explode(' ', $item);
                $files[$dataindex]['Source'] = $filespec[0];
                $files[$dataindex]['Size'] = $filespec[1];
                $files[$dataindex]['Hash'] = $filespec[2];
            }
        }

        if (count($files) == 0) throw new Exception('No data received - possible protocol error');
        if ($filefields != $datafields) throw new Exception('Different count of fields');

        foreach($files as $fileindex => &$filedata) {
            try {
                if (!isset($filedata['Filename']))  throw new Exception('no Filename value');
                if (!isset($filedata['Extension'])) throw new Exception('no Extension value');
                if (!isset($filedata['UUID']))      throw new Exception('no UUID value');
                if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $filedata['UUID']))
                                                    throw new Exception('invalid UUID value');
                if (!isset($filedata['Source']))    throw new Exception('no Source value - possible server error');

                $filedata['Filename'] = trim($filedata['Filename']);
                if (!$filedata['Filename'])         throw new Exception('empty Filename value');

                $filedata['Extension'] = trim($filedata['Extension']);
                if (!$filedata['Extension'])        throw new Exception('empty Extension value');

                $path = substr($filedata['UUID'], 32, 2) .'/'. substr($filedata['UUID'], 30, 2);
                $filename = $filedata['UUID'] .'.'. $filedata['Extension'];
                $targetdir = $config['node']['storage'] .'/'. $path;
                $targetfile = $targetdir .'/'. $filename;

                if (!(is_dir($targetdir) || mkdir ($targetdir, 0755, true)))
                    throw new Exception("Could not create target directory '$targetdir'");

                if (!rename($filedata['Source'], $targetfile))
                    throw new Exception("Could not move '" . $filedata['Source'] ."' to '". $targetfile ."'");

                try {
                    $filedata['Source'] = $path .'/'. $filename;
                    $filedata['Host'] = $config['node']['hostname'];
                    if (!mq_send_to_slaves(serialize(array(
                                            'Action' => 'copy',
                                            'Time' => time(),
                                            'Prefix' => $config['node']['hostprefix'],
                                            'GroupIndex' => $config['group']['index'],
                                            'Data' => $filedata))))

                        throw new Exception('AMQPExchange::publish returned FALSE');

                } catch(Exception $exception) {
                        unlink($targetfile);
                        throw $exception;
                }

                $result['Status']['OK']++;
                $result[$fileindex] = array('OK' => $config['group']['prefix'] .'/'. $path .'/'. $filename);

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
