<?php
    require "common.php";

    try {
        init();
        mq_init();
        mq_init_sub();

        while($message = $amqp_sub->get()) {

            $data = unserialize($message->getBody());
            $filedata = $data['Data'];

            $link_prefix = substr($filedata['UUID'], 32, 2) .'/'. substr($filedata['UUID'], 30, 2);
            $link_file = $filedata['UUID'] .'.'. $filedata['Extension'];
            $link_dir = $config['node']['storage'] .'/'. $link_prefix;
            $link_path = $link_dir .'/'. $link_file;

            $stat = stat($link_path);

            if ($data['Action'] == 'copy' && !$stat) {

                $hash_prefix = substr($filedata['Hash'], 0, 2) .'/'. substr($filedata['Hash'], 2, 2) .'/'. substr($filedata['Hash'], 4, 2);
                $hash_file = substr($filedata['Hash'], 6) .':'. $filedata['Size'] .'.'. $filedata['Extension'];
                $hash_dir = $config['node']['hashstorage'] .'/'. $hash_prefix;
                $hash_path = $hash_dir .'/'. $hash_file;

                $lock = lock($filedata['Hash']);

                try {

                    if (!file_exists($hash_path)) {
                        if (!(is_dir($hash_dir) || mkdir ($hash_dir, 0755, true)))
                            throw new Exception("Could not create target directory '$hash_dir'");

                        if(!($ch = curl_init($data['Prefix'] .'/'. $link_prefix .'/'. $link_file))) {
                            throw new Exception('Could not init cURL');
                        }

                        if (!($fp = fopen($hash_path, "w"))) {
                            throw new Exception('Could not create file "' . $hash_path . '"');
                        }

                        curl_setopt($ch, CURLOPT_FILE, $fp);
                        curl_setopt($ch, CURLOPT_HEADER, 0);
                        curl_exec($ch);
                        $err = curl_errno($ch);
                        curl_close($ch);
                        fclose($fp);

                        if ($err) {
                            @unlink($hash_path);
                            throw new Exception('cURL error: ' . curl_error($ch));
                        }
                    }

                    if (!(is_dir($link_dir) || mkdir ($link_dir, 0755, true)))
                        throw new Exception("Could not create target directory '$link_dir'");

                    if (!link($link_path, $hash_path)) // $link_path <- $hash_path
                        throw new Exception("Could link '" . $hash_path ."' to '". $link_path ."'");

                } catch (Exception $exception) {

                    unlock($lock);
                    throw $exception;
                }
            }

            if ($data['Action'] == 'delete') {
                if($stat) {
                    if ($hash = xattr_get($link_path, 'md5'))
                        $lock = lock($hash);

                    if (!unlink($link_path)) throw new Exception("Cannot delete file '" . $link_path . '"');

                    if ($hash) {

                        $hash_prefix = substr($hash, 0, 2) .'/'. substr($hash, 2, 2) .'/'. substr($hash, 4, 2);
                        $hash_file = substr($hash, 6) .':'. $stat['size'] .'.'. $data['Data']['Extension'];
                        $hash_dir = $config['node']['hashstorage'] .'/'. $hash_prefix;
                        $hash_path = $hash_dir .'/'. $hash_file;

                        if ($stat = stat($hash_path))
                            if ($stat['nlink'] == 1)
                                unlink($hash_path);

                        unlock($lock);
                    }
                }
            }

            if ($data['Action'] == 'dedup') {
                $source = $config['node']['storage'] .'/'. $data['Files'][0];
                $target = $config['node']['storage'] .'/'. $data['Files'][1];
                if (file_exists($target) && file_exists($source)) {
                    unlink($source);
                    link($target, $source); // $target <- $source
                }
            }

            $amqp_sub->ack($message->getDeliveryTag());
        }

    } catch (Exception $exception) {
        print $exception->getMessage() . "\n";
    }
