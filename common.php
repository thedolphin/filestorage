<?php

function init() {

    global $config;

    $config = parse_ini_file('filestorage.ini', true, INI_SCANNER_RAW);

    if ($config['node']['hashalgo'] != 'md5' && $config['node']['hashalgo'] != 'sha256')
        throw new Exception('Hash algorithm "' . $config['node']['hashalgo'] . '" not supported');
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

function mq_send_to_slaves($message) {

    global $amqp_pub;
    global $config;

    return $amqp_pub->publish($message, '', 0, array('headers' => array(
                                                                    $config['node']['hostname'] => 'yes',
                                                                    'log' => 'yes',
                                                                    'db' => 'yes' )));
}

class lock {
    private $filehandler;
    private $filename;

    function __construct($name) {

        global $config;

        $this->filename = $config['node']['lockdir'] .'/'. $name . '.lock';
        $this->filehandler = fopen($this->filename, 'c');

        if (!$this->filehandler)
            throw new Exception('Cannot create lock file: "' .$this->filename. '"');

        if (!flock($this->filehandler, LOCK_EX))
            throw new Exception('Cannot lock file: "' .$this->filename. '"');
    }

    function __destruct() {
        fclose($this->filehandler);
        unlink($this->filename);
    }
}
