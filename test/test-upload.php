#!/usr/bin/php
<?php

$url = 'http://127.0.0.1';
$filesnum = 5;
$fileext = 'bin';
$storageprefix = '/vol/storage';
$resultsfile = 'test-data.php';

require 'functions.php';

out('Generating some data');

for ($i = 1; $i <= $filesnum; $i++) {
    $filedata[$i]['uuid'] = exec('uuidgen');
    $filedata[$i]['filename'] = "testfile$i";
    if (file_exists($filedata[$i]['filename'])) {
        $filedata[$i]['size'] = filesize($filedata[$i]['filename']);
        $data = file_get_contents($filedata[$i]['filename']);
    } else {
        $filedata[$i]['size'] = rand(0,100) + 1048526;
        $fh = fopen('/dev/urandom', 'r');
        $data = fread($fh, $filedata[$i]['size']);
        fclose($fh);
        file_put_contents($filedata[$i]['filename'], $data);
    }
    $filedata[$i]['sha256'] = hash('sha256', $data);
}

out('Starting upload');

foreach($filedata as $i => $v) {
    $postfields["File$i"] = json_encode(array(
            'Filename' => $v['filename'],
            'Extension' => $fileext,
            'UUID' => $v['uuid'],
            /* Addtitional metadata to store in DB */
            'meta1' => 'value1',
            'meta2' => 'value2'
    ));

    $postfields["Data$i"] = '@' . $v['filename']; // no way to append ';filename=' to form field, except using file reference
}

$retval = req('/upload', $postfields, $error, $code, $body);
out("Error: {$error}\nHttp code: {$code}\nBody: {$body}");

if ($retval) {
    $rethash = json_decode($body, true);
    if ($rethash['Status']['OK'] == $filesnum) {
        out('All files have been uploaded successfully');
    } else {
        out('Upload was not successful');
    }

    for ($i = 1; $i <= $filesnum; $i++) {
        $filedata[$i]['url'] = isset($rethash[$i]['OK']) ? $rethash[$i]['OK'] : NULL ;
        $filedata[$i]['upload_error'] = isset($rethash[$i]['FAIL']) ? $rethash[$i]['FAIL'] : NULL;
        $filedata[$i]['delete_error'] = NULL;
        $filedata[$i]['url_error'] = NULL;
        if ($filedata[$i]['url']) {
            $urlparsed = parse_url($filedata[$i]['url']);
            if ($urlparsed) {
                $filedata[$i]['path'] = $urlparsed['path'];
                if (file_exists($storageprefix . $filedata[$i]['path'])) {
                    out('File ' . $filedata[$i]['filename'] . ', saved to ' . $filedata[$i]['path'] . ' found on local storage. ');
                    $filedata[$i]['checksum'] = $filedata[$i]['sha256'] == hash('sha256', file_get_contents($storageprefix . $filedata[$i]['path'])) ? 'valid' : 'invalid';
                    out('Checksum is ' . $filedata[$i]['checksum']);
                } else {
                    out('File ' . $filedata[$i]['filename'] . ', saved to ' . $filedata[$i]['path'] . ' was not found on local storage');
                }
            } else {
                out('Cannot parse returned url for file ' . $filedata[$i]['filename']);
                $filedata[$i]['url_error'] = true;
            }
        }
    }
}

out('Done. Dumping full data');

if (file_exists($resultsfile)) {
    out('Found old data, merging');
    $data = require $resultsfile;
    $filedata = array_merge($data, $filedata);
}

file_put_contents($resultsfile, "<?php\nreturn " . var_export($filedata, true) . ";\n");

out('All done');
