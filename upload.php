<?php
    require 'common.php';

    init();

    $result = array('Status' => array('OK' => 0));

    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $client = $_SERVER['HTTP_X_REAL_IP'];
    } else {
        $client = $_SERVER['REMOTE_ADDR'];
    }

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
                if (!isset($filedata['Source']))    throw new Exception('no Source value - possible nginx misconfiguration');
                if (!isset($filedata['Hash']))      throw new Exception('no Hash value - possible nginx misconfiguration');
                if (!isset($filedata['Size']))      throw new Exception('no Size value - possible nginx misconfiguration');

                $filedata['Filename'] = trim($filedata['Filename']);
                if (!$filedata['Filename'])         throw new Exception('empty Filename value');

                $filedata['Extension'] = trim($filedata['Extension']);
                if (!$filedata['Extension'])        throw new Exception('empty Extension value');

                $link_prefix = substr($filedata['UUID'], 32, 2) .'/'. substr($filedata['UUID'], 30, 2); //$path
                $link_file = $filedata['UUID'] .'.'. $filedata['Extension']; //$filename
                $link_dir = $config['node']['storage'] .'/'. $link_prefix; //$target_dir
                $link_path = $link_dir .'/'. $link_file; //$target_path

                $hash_prefix = substr($filedata['Hash'], 0, 2) .'/'. substr($filedata['Hash'], 2, 2) .'/'. substr($filedata['Hash'], 4, 2);
                $hash_file = substr($filedata['Hash'], 6) .':'. $filedata['Size'] .'.'. $filedata['Extension'];;
                $hash_dir = $config['node']['hashstorage'] .'/'. $hash_prefix;
                $hash_path = $hash_dir .'/'. $hash_file;

                $new_hash = false;

                $lock = lock($filedata['Hash']);

                if (!file_exists($hash_path)) {

                    if (!(is_dir($hash_dir) || mkdir ($hash_dir, 0755, true)))
                        throw new Exception("Could not create target directory '$hash_dir'");

                    if (!rename($filedata['Source'], $hash_path))
                        throw new Exception("Could not move '" . $filedata['Source'] ."' to '". $hash_path ."'");

                    if (!xattr_set($hash_path, 'md5', $filedata['Hash']))
                        throw new Exception("Could not set attribute on '". $hash_path ."'");

                    $new_hash = true;
                }

                if (!(is_dir($link_dir) || mkdir ($link_dir, 0755, true)))
                    throw new Exception("Could not create target directory '$link_dir'");

                if (!link($hash_path, $link_path))
                    throw new Exception("Could link '" . $hash_path ."' to '". $link_path ."'");

                try {
                    $filedata['Source'] = $link_prefix .'/'. $link_file;
                    $filedata['Host'] = $config['node']['hostname'];
                    if (!mq_send_to_slaves(serialize(array(
                                            'Action' => 'copy',
                                            'Time' => time(),
                                            'Prefix' => $config['node']['hostprefix'],
                                            'GroupIndex' => $config['group']['index'],
                                            'ClientIP' => $client,
                                            'Data' => $filedata))))

                        throw new Exception('AMQPExchange::publish returned FALSE');

                } catch(Exception $exception) {
                        unlink($link_path);
                        if ($new_hash) unlink($hash_path);
                        throw $exception;
                }

                unlock($lock);

                $result['Status']['OK']++;
                $result[$fileindex] = array('OK' => $config['group']['prefix'] .'/'. $link_prefix .'/'. $link_file);

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
