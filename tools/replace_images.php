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

$url = 'http://img-ha.i';
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

function fetch($url, $filename, &$curlerror, &$httpcode) {
    $curl = curl_init($url . $res);
    curl_setopt_array($curl, array(
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Wikimart FileStorage Test Suit'));

    $startms = microtime(true);
    $httpbody = curl_exec($curl);
    $stopms = microtime(true);
    $curlerror = curl_error($curl);
    $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    out('Curl call took ' . number_format($stopms - $startms, 2) . ' seconds');

    if ($curlerror || $httpcode != 200) {
        return false;
    } else {
        file_put_contents($filename, $httpbody);
        return true;
    }
}

function upload_file($filename) {

    if ($fileext = strrchr($filename, '.')) {
        $fileext = substr($fileext, 1);
    } else {
        $fileext = 'bin';
    }

    $postfields["File1"] = json_encode(array(
            'Filename' => $filename,
            'Extension' => $fileext,
            'UUID' => exec('uuidgen')));

    $postfields["Data1"] = '@' . $filename; // no way to append ';filename=' to form field, except using file reference

    $retval = req('/upload', $postfields, $error, $code, $body);
    out("Upload {$filename}: error: {$error}, http code: {$code}, body: {$body}");

    if ($retval) {
        $rethash = json_decode($body, true);

        if ($rethash['Status']['OK'] == 1) {

            out('Upload was successfull');
            return $rethash['1']['OK'];

        } else {

            out('Upload was not successful: ' . $rethash['1']['FAIL']);
            return false;
        }
    }
}

function delete_file($filename) {
    if (preg_match('/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})\.(.+)$/', $filename, $match)) {
        $postfields["File1"] = json_encode(array(
            'UUID' => $match[1],
            'Extension' => $match[2]));

        $retval = req('/delete', $postfields, $error, $code, $body);
        out("Deleting $filename: error: {$error}, http code: {$code}, body: {$body}");

        if ($retval) {
            $rethash = json_decode($body, true);
            if ($rethash['Status']['OK'] == 1) {
                out('File have been deleted successfully');
                return true;
            } else {
                out('Deletion was not successful: ' . $rethash['1']['FAIL']);
                return false;
            }
        }
    } else {
        out ('Cannot parse filename, must be UUID.ext');
        return false;
    }
}



function replace_images($dbh, $id, $prefix, $filename) {

    $res = mysqli_query($dbh, "select thumbnail, url from image_storage_thumbnail where image_id = {$id}");
    $rows = mysqli_fetch_all($res, MYSQLI_ASSOC);

    foreach ($rows as $img) {

    /* Backup existing pix */
    /*
        if ( !($ext = strrchr($img['url'], '.')) ) $ext = '';
        $filename = $prefix .'_'. $img['thumbnail'] . $ext;
        out("image id {$id}: {$img['url']} -> {$filename}");
        delete_file(
        fetch ($img['url'], $filename, $error, $code);
        out("fetch: error: {$error}, http code: {$code}");
    /*
    /* Delete them from FS */
    /*
        out("Deleting: {$img['url']}");
        delete_file("{$img['url']}");
    */
    }

    $img = new Imagick($filename);
    $width = $img->getImageWidth();
    $height = $img->getImageHeight();
    $size = filesize($filename);

    $fileurl = upload_file($filename);
    out("Uploaded: {$fileurl}");

    out('Deleting thumbnails');
    $q = "delete from image_storage_thumbnail where image_id = {$id}";
    $res = mysqli_query($dbh, $q);

    $thumbnail = 1; /* Thumbnail Index */

    out('Adding thumbnail');
    $q = "insert into image_storage_thumbnail (`image_id`, `thumbnail`, `width`, `height`, `size`, `suffix`, `url`) values ({$id}, {$thumbnail}, {$width}, {$height}, {$size}, '', '{$fileurl}')";
    $res = mysqli_query($dbh, $q);

}


$dbh = mysqli_connect('wm15.i', 'wm', 'wikimart', 'wm2');

$entities = array(1, 30, 78, 132, 599, 1060, 1219, 1355, 1358, 1370, 1381, 1457, 1472, 1510, 1511, 1669, 1670, 1671, 1672, 1674, 2053, 2180, 2310, 2311, 3134, 3245, 6714);

foreach ($entities as $entity) {
    $q = "select id from image_storage where entity = 'catalog_category' AND entity_id = {$entity}";
    $res = mysqli_query($dbh, $q);
    list($id) = mysqli_fetch_row($res);

    $filename = "200/{$entity}_200.png";

    if (file_exists($filename)) {
        replace_images($dbh, $id, 'img', $filename);
    }
}
