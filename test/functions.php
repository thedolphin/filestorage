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
