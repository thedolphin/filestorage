#!/usr/bin/php
<?php

/*
Upload:

File1='{ "Filename": "testfile1", "Extension": "jpeg", "UUID": "'$(uuidgen)'" }'
File2='{ "Filename": "testfile2", "Extension": "jpeg", "UUID": "'$(uuidgen)'" }'

curl -D - -F Data1=@testfile1 -F File1="$File1" -F File2="$File2" -F Data2=@testfile2 http://127.0.0.1/upload

Delete:

File1='{ "UUID": "6d8630a9-6287-4f49-8772-8f0810e9a86a", "Extension": "jpeg" }'
File2='{ "UUID": "1dded708-43f8-4289-988e-89d51a078416", "Extension": "jpeg" }'

curl -D - -F File1="$File1" -F File2="$File2" http://127.0.0.1/delete
*/

$url = 'http://127.0.0.1';
$filesnum = 5;
$fileext = 'bin';
$storageprefix = '/vol/storage';
$resultsfile = 'test-data.php';

function out($message) {
    foreach(explode("\n", $message) as $line) {
        trim($line);
        if ($line) {
            print date('d-m-Y H:i:s');
            print ': ' . $line . "\n";
        }
    }
}

function req($res, $postfields, &$curlerror, &$httpcode, &$httpbody) {
    global $url;


    $curl = curl_init($url . $res);
    curl_setopt_array($curl, array(
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Wikimart FileStorage Test Suit',
        CURLOPT_POSTFIELDS => $postfields ));

    $startms = microtime(true);
    $httpbody = curl_exec($curl);
    $stopms = microtime(true);
    $curlerror = curl_error($curl);
    $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    out('Curl call took ' . number_format($stopms - $startms, 2) . ' seconds');

    return ! ($curlerror || $httpcode >= 500);
}

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

out('Starting vanilla test');

foreach($filedata as $i => $v) {
    $postfields["File$i"] = json_encode(array(
            'Filename' => $v['filename'],
            'Extension' => $fileext,
            'UUID' => $v['uuid']));

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

out('Deleting');
unset($postfields);

foreach($filedata as $i => $v) {
    $postfields["File$i"] = json_encode(array(
            'UUID' => $v['uuid'],
            'Extension' => $fileext));

}

$retval = req('/delete', $postfields, $error, $code, $body);
out("Error: {$error}\nHttp code: {$code}\nBody: {$body}");

if ($retval) {
    $rethash = json_decode($body, true);
    if ($rethash['Status']['OK'] == $filesnum) {
        out('All files have been deleted successfully');
    } else {
        out('Deletion was not successful');
    }

    out('Waiting 10 secs for queue to be processed');
    sleep(10);

    for ($i = 1; $i <= $filesnum; $i++) {
        $filedata[$i]['delete_error'] = isset($rethash[$i]['FAIL']) ? $rethash[$i]['FAIL'] : NULL;
            if (file_exists($storageprefix . $filedata[$i]['path'])) {
                out('File ' . $filedata[$i]['filename'] . ', saved to ' . $filedata[$i]['path'] . ' found on local storage');
            } else {
                out('File ' . $filedata[$i]['filename'] . ', saved to ' . $filedata[$i]['path'] . ' was not found on local storage');
            }
    }
}

out('Done. Dumping full data');

if (file_exists($resultsfile)) {
    out('Found old data, merging');
    require $resultsfile;
    $filedata = array_merge($data, $filedata);
}

file_put_contents($resultsfile, "<?php\n" . '$data = ' . var_export($filedata, true) . ";\n");

out('All done');
