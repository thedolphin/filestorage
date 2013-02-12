<?php

function init() {

    if (!phpversion('xattr'))
        throw new Exception('No xattr support in PHP');

    if (!phpversion('amqp'))
        throw new Exception('No AMQP support in PHP');

    global $config;

    $config = parse_ini_file('filestorage.ini', true, INI_SCANNER_RAW);

    if (!xattr_supported($config['node']['storage']))
        throw new Exception('No extended attributes support for "' . $config['node']['lockdir'] . '" or directory not readable');
}

function mq_init() {

    global $amqp_conn;
    global $config;

    $amqp_conn = new AMQPConnection();

    $amqp_conn->setHost($config['amqp']['host']);
    $amqp_conn->setPort($config['amqp']['port']);
    $amqp_conn->setLogin($config['amqp']['user']);
    $amqp_conn->setPassword($config['amqp']['pass']);
    $amqp_conn->setVhost($config['amqp']['vhost']);
    if (!$amqp_conn->connect()) throw new Exception('Could not connect to AMQP broker');
}

function mq_init_pub() {

    global $amqp_pub;
    global $amqp_conn;

    $amqp_pub = new AMQPExchange(new AMQPChannel($amqp_conn));
    $amqp_pub->SetName('filestorage');
}

function mq_init_sub() {

    global $amqp_sub;
    global $amqp_conn;
    global $config;

    $amqp_sub = new AMQPQueue(new AMQPChannel($amqp_conn));
    $amqp_sub->SetName('filestorage.replica.' . $config['node']['hostname']);
}

function mq_broadcast($message) {

    global $amqp_pub;
    return $amqp_pub->publish($message, '', 0, array('headers' => array('broadcast' => 'yes')));
}

function mq_send_to_group($message, $groupindex) {

    global $amqp_pub;
    return $amqp_pub->publish($message, '', 0, array('headers' => array('group' . $groupindex => 'yes', 'log' => 'yes')));
}

function mq_send_to_slaves($message) {

    global $amqp_pub;
    global $config;

    return $amqp_pub->publish($message, '', 0, array('headers' => array($config['node']['hostname'] => 'yes', 'log' => 'yes', 'db' => 'yes' )));
}

function lock($name) {

    global $config;

    $fn = $config['node']['lockdir'] .'/'. $name . '.lock';
    $fh = fopen($fn, 'c');
    flock($fh, LOCK_EX);

    return array('fn' => $fn, 'fh' => $fh);
}

function unlock($lock) {

    fclose($lock['fh']);
    unlink($lock['fn']);
}
