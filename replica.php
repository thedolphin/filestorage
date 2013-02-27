<?php
require "common.php";

try {
    init();
    mq_init();
    mq_init_sub();

    while($message = $amqp_sub->get()) {

        $data = unserialize($message->getBody());

        $link_prefix = substr($data['meta']['UUID'], 32, 2) .'/'. substr($data['meta']['UUID'], 30, 2);
        $link_file = $data['meta']['UUID'] .'.'. $data['meta']['Extension'];
        $link_dir = $config['node']['storage'] .'/'. $link_prefix;
        $link_path = $link_dir .'/'. $link_file;

        $stat = stat($link_path);

        if ($data['action'] == 'copy' && !$stat) {

            $hash = $data['spec'][$config['node']['hashalgo']];

            $hash_prefix = substr($hash, 0, 2) .'/'. substr($hash, 2, 2) .'/'. substr($hash, 4, 2);
            $hash_file = $hash;
            $hash_dir = $config['node']['hashstorage'] .'/'. $hash_prefix;
            $hash_path = $hash_dir .'/'. $hash;

            $lock = lock($hash);

            try {

                if (!file_exists($hash_path)) {
                    if (!(is_dir($hash_dir) || mkdir ($hash_dir, 0755, true)))
                        throw new Exception("Could not create target directory '$hash_dir'");

                    if(!($ch = curl_init($data['prefix'] .'/'. $link_prefix .'/'. $link_file))) {
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

            }

            catch (Exception $exception) {
                unlock($lock);
                throw $exception;
            }

            unlock($lock);
        }

        if ($data['action'] == 'delete') {

            if($stat) {

                try {

                    if ($hash = xattr_get($link_path, 'user.' . $config['node']['hashalgo'])) {

                        $lock = lock($hash);

                        $hash_prefix = substr($hash, 0, 2) .'/'. substr($hash, 2, 2) .'/'. substr($hash, 4, 2);
                        $hash_file = $hash;
                        $hash_dir = $config['node']['hashstorage'] .'/'. $hash_prefix;
                        $hash_path = $hash_dir .'/'. $hash_file;

                        if ($stat = stat($hash_path) && $stat['nlink'] == 2)
                            if (!unlink($hash_path))
                                throw new Exception("Cannot delete file '" . $hash_path . '"');
                    }

                    if (!unlink($link_path))
                        throw new Exception("Cannot delete file '" . $link_path . '"');
                }

                catch(Exception $exception) {
                    unlock($lock);
                    throw $exception;
                }

                unlock($lock);
            }
        }

        $amqp_sub->ack($message->getDeliveryTag());
    }

}

catch (Exception $exception) {
    print $exception->getMessage() . "\n";
}


