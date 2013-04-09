#!/usr/bin/php
<?php

if (!phpversion('xattr'))
    print("No xattr support in PHP\n");
else {
    if (!xattr_supported($config['node']['storage']))
        print("No extended attributes support for '{$config['node']['storage']}' or directory not readable\n");
}

if (!phpversion('amqp'))
    print("No AMQP support in PHP\n");

global $config;

$config = parse_ini_file('../filestorage.ini', true, INI_SCANNER_RAW);

if(!$config)
    print("Config not found\n");
else {
    if ($config['node']['hashalgo'] != 'md5' && $config['node']['hashalgo'] != 'sha256')
        print("Hash algorithm ' . {$config['node']['hashalgo']}' not supported\n");
}

print("Done\n");
