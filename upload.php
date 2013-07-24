<?php
    require 'common.php';

    $result = array('Status' => array('OK' => 0));

    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $client = $_SERVER['HTTP_X_REAL_IP'];
    } else {
        $client = $_SERVER['REMOTE_ADDR'];
    }

    try {

        $config = new config();
        $queue = new queue($config);

        foreach($_POST as $key => $item) {
            $datatype = substr($key, 0, 4);
            $dataindex = substr($key, 4);
            if ($datatype == "File" && is_numeric($dataindex)) {
                $files[$dataindex]['meta'] = json_decode($item, true);
                $filefields++;
            }

            if ($datatype == "Data" && is_numeric($dataindex)) {
                $datafields++;
                $filespec = explode(' ', $item);
                $files[$dataindex]['spec']['source'] = $filespec[0];
                $files[$dataindex]['spec']['size'] = $filespec[1];
                $files[$dataindex]['spec']['md5'] = $filespec[2];
                $files[$dataindex]['spec']['sha256'] = $filespec[3];
            }
        }

        if (count($files) == 0) throw new Exception('No data received - possible protocol error');
        if ($filefields != $datafields) throw new Exception('Different count of fields');

        foreach($files as $fileindex => &$filedata) {

            try {
                if (!isset($filedata['meta']['Filename']))  throw new Exception('no Filename value');
                if (!isset($filedata['meta']['Extension'])) throw new Exception('no Extension value');
                if (!isset($filedata['meta']['UUID']))      throw new Exception('no UUID value');
                if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $filedata['meta']['UUID']))
                                                    throw new Exception('invalid UUID value');
                if (!isset($filedata['spec']['source']))    throw new Exception('no Source value - possible nginx misconfiguration');
                if (!isset($filedata['spec']['size']))      throw new Exception('no Size value - possible nginx misconfiguration');
                if (!isset($filedata['spec']['md5']))       throw new Exception('no MD5 value - possible nginx misconfiguration');
                if (!isset($filedata['spec']['sha256']))    throw new Exception('no SHA256 value - possible nginx misconfiguration');


                $filedata['meta']['Filename'] = trim($filedata['meta']['Filename']);
                if (!$filedata['meta']['Filename'])         throw new Exception('empty Filename value');

                $filedata['meta']['Extension'] = trim($filedata['meta']['Extension']);
                if (!$filedata['meta']['Extension'])        throw new Exception('empty Extension value');

                $link_prefix = substr($filedata['meta']['UUID'], 32, 2) .'/'. substr($filedata['meta']['UUID'], 30, 2);
                $link_file = $filedata['meta']['UUID'] .'.'. $filedata['meta']['Extension'];
                $link_dir = $config['node']['storage'] .'/'. $link_prefix;
                $link_path = $link_dir .'/'. $link_file;

                $hash = $filedata['spec'][$config['node']['hashalgo']];

                $hash_prefix = substr($hash, 0, 2) .'/'. substr($hash, 2, 2) .'/'. substr($hash, 4, 2);
                $hash_file = $hash;
                $hash_dir = $config['node']['hashstorage'] .'/'. $hash_prefix;
                $hash_path = $hash_dir .'/'. $hash;

                $new_hash = false;

                $lock = new lock($config, $hash);

                try {

                    if (file_exists($hash_path)) {
                        unlink($filedata['spec']['source']);

                        /*
                        compatibility:
                        we may have old version hash storage
                        with different hash attributes

                        if (!xattr_get($hash_path, $config['node']['hashalgo']))
                            if (!xattr_set($hash_path, $config['node']['hashalgo'], $hash))
                                throw new Exception("Could not set attribute on '". $hash_path ."'");
                        */

                    } else {
                        if (!(is_dir($hash_dir) || mkdir ($hash_dir, 0755, true)))
                            throw new Exception("Could not create target directory '$hash_dir'");

                        if (!rename($filedata['spec']['source'], $hash_path))
                            throw new Exception("Could not move '" . $filedata['source'] ."' to '". $hash_path ."'");

                        $new_hash = true;

                        if (!xattr_set($hash_path, $config['node']['hashalgo'], $hash))
                            throw new Exception("Could not set attribute on '". $hash_path ."'");

                    }

                    if (!(is_dir($link_dir) || mkdir ($link_dir, 0755, true)))
                        throw new Exception("Could not create target directory '$link_dir'");

                    if (!link($hash_path, $link_path))
                        throw new Exception("Could link '" . $hash_path ."' to '". $link_path ."'");

                    if ($config['node']['replication'] == 'yes')
                        $queue->multicast(serialize(array(
                            'action' => 'copy',
                            'time' => time(),
                            'host' => $config['node']['hostname'],
                            'group' => $config['node']['groupindex'],
                            'prefix' => $config['node']['hostprefix'],
                            'clientip' => $client,
                            'meta' => $filedata['meta'],
                            'spec' => array(
                                'size' => $filedata['spec']['size'],
                                $config['node']['hashalgo'] => $hash))));
                }

                catch(Exception $exception) {
                    unlink($link_path);
                    if ($new_hash) unlink($hash_path);
                    unset($lock);
                    throw $exception;
                }

                unset($lock);

                $result['Status']['OK']++;
                $result[$fileindex] = array('OK' => $config['node']['groupprefix'] .'/'. $link_prefix .'/'. $link_file);

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
