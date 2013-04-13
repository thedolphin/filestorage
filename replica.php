<?php
require "common.php";

try {

    $config = new config();
    $queue = new queue($config);

    while($message = $queue->get()) {

        $data = unserialize($message->getBody());

        $link_prefix = substr($data['meta']['UUID'], 32, 2) .'/'. substr($data['meta']['UUID'], 30, 2);
        $link_file = $data['meta']['UUID'] .'.'. $data['meta']['Extension'];
        $link_dir = $config['node']['storage'] .'/'. $link_prefix;
        $link_path = $link_dir .'/'. $link_file;

        $stat = @stat($link_path);

        if ($data['action'] == 'copy' && !$stat) {

            $hash = $data['spec'][$config['node']['hashalgo']];

            $hash_prefix = substr($hash, 0, 2) .'/'. substr($hash, 2, 2) .'/'. substr($hash, 4, 2);
            $hash_file = $hash;
            $hash_dir = $config['node']['hashstorage'] .'/'. $hash_prefix;
            $hash_path = $hash_dir .'/'. $hash;

            $lock = new lock($config, $hash);

            try {

                if (file_exists($hash_path)) {
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

                    if(!($ch = curl_init($data['prefix'] .'/'. $link_prefix .'/'. $link_file))) {
                        throw new Exception('Could not init cURL');
                    }

                    curl_setopt_array($ch, array(
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; WikimartFileStorage@'. $config['node']['hostname'] .'/2.0; +alexander.rumyantsev@wikimart.ru)'
                    ));

                    $body = curl_exec($ch);

                    if ($err = curl_errno($ch))
                        $errtext = curl_error($ch);

                    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                    curl_close($ch);

                    if ($err || $httpcode != 200) {
                        throw new Exception('cURL error: ' . $errtext . ', http response code: ' . $httpcode, $httpcode);
                    }

                    if (hash($config['node']['hashalgo'], $body) != $hash) {
                        throw new Exception('Hash mismatch');
                    }

                    if (file_put_contents($hash_path, $body) != strlen($body)) {
                        throw new Exception('Error writing file "' . $hash_path . '"');
                    }

                    if (!xattr_set($hash_path, $config['node']['hashalgo'], $hash))
                        throw new Exception("Could not set attribute on '". $hash_path ."'");
                }

                if (!(is_dir($link_dir) || mkdir ($link_dir, 0755, true)))
                    throw new Exception("Could not create target directory '$link_dir'");

                if (!link($hash_path, $link_path))
                    throw new Exception("Could link '" . $hash_path ."' to '". $link_path ."'");

            }

            catch (Exception $exception) {
                if ($exception->getCode() != 404) {
                    unset($lock);
                    throw $exception;
                }
            }

            unset($lock);
        }

        if ($data['action'] == 'delete') {

            if ($stat) {

                try {

                    if ($hash = xattr_get($link_path, $config['node']['hashalgo'])) {

                        $lock = new lock($config, $hash);

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
                    unset($lock);
                    throw $exception;
                }

                unset($lock);
            }
        }

        $queue->ack($message);
    }

}

catch (Exception $exception) {
    print $exception->getMessage() . "\n";
}


