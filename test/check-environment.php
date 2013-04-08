#!/usr/bin/php
<?php

try {

    if (!phpversion('xattr'))
        throw new Exception('No xattr support in PHP');

    if (!phpversion('amqp'))
        throw new Exception('No AMQP support in PHP');

    global $config;

    $config = parse_ini_file('../filestorage.ini', true, INI_SCANNER_RAW);

    if(!$config)
        throw new Exception('Config not found');


    if (!xattr_supported($config['node']['storage']))
        throw new Exception('No extended attributes support for "' . $config['node']['lockdir'] . '" or directory not readable');

    if ($config['node']['hashalgo'] != 'md5' && $config['node']['hashalgo'] != 'sha256')
        throw new Exception('Hash algorithm "' . $config['node']['hashalgo'] . '" not supported');

}

catch (Exception $e) {

    print($e->getMessage() . "\n");
}

print("Done\n");
