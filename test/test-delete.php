#!/usr/bin/php
<?php

$url = 'http://imgtest1.lan';
$fileext = 'bin';
$storageprefix = '/vol/storage';
$resultsfile = 'test-data.php';

require 'functions.php';

if (isset($argv[1])) {
    if (file_exists($argv[1]))
        $resultsfile = $argv[1];
    else {
        out('cannot find file ' . $argv[1]);
    }
}

$filedata = require $resultsfile;

out('Deleting');

foreach($filedata as $i => $v) {
    $postfields["File$i"] = json_encode(array(
            'UUID' => $v['uuid'],
            'Extension' => $fileext));

}

$retval = req('/delete', $postfields, $error, $code, $body);
out("Error: {$error}\nHttp code: {$code}\nBody: {$body}");

if ($retval) {
    $rethash = json_decode($body, true);
    if ($rethash['Status']['OK'] == count($filedata)) {
        out('All files have been deleted successfully');
    } else {
        out('Deletion was not successful');
    }

    out('Waiting 3 secs for queue to be processed');
    sleep(3);

    foreach($filedata as &$v) {
        $v['delete_error'] = isset($rethash[$i]['FAIL']) ? $rethash[$i]['FAIL'] : NULL;
            if (file_exists($storageprefix . $v['path'])) {
                out('File ' . $v['filename'] . ', saved to ' . $v['path'] . ' found on local storage');
            } else {
                out('File ' . $v['filename'] . ', saved to ' . $v['path'] . ' was not found on local storage');
            }
    }
}

out('Dumping');

file_put_contents($resultsfile, "<?php\nreturn " . var_export($filedata, true) . ";\n");

out('All done');
