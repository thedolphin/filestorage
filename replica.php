<?php
    require "common.php";

    try {
        init();
        mq_init();
        mq_init_sub();

        while($message = $amqp_sub->get()) {

            $data = unserialize($message->getBody());
            $targetfile = $config['node']['storage'] .'/'. $data['Data']['Source'];
            $targetdir = dirname($targetfile);

            if ($data['Action'] == 'copy') {
                if (!(is_dir($targetdir) || mkdir ($targetdir, 0755, true))) throw new Exception("Could not create target directory '$targetdir'");
                $ch = curl_init($data['Prefix'] .'/'. $data['Data']['Source']);
                $fp = fopen($targetfile, "w");
                curl_setopt($ch, CURLOPT_FILE, $fp);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_exec($ch);
                $err = curl_errno($ch);
                if ($err) throw new Exception('cURL error: ' . curl_error($ch));
                curl_close($ch);
                fclose($fp);
            }

            if ($data['Action'] == 'delete') {
                if($stat = stat($targetfile)) {
                    if ($hash = xattr_get($targetfile, 'md5'))
                        $lock = lock($hash);

                    if (!unlink($targetfile)) throw new Exception("Cannot delete file '" . $targetfile . '"');

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
                    link($target, $source);
                }
            }

            $amqp_sub->ack($message->getDeliveryTag());
        }

    } catch (Exception $exception) {
        print $exception->getMessage() . "\n";
    }
