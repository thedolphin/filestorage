<?php

class config extends ArrayObject {
    function __construct() {
        parent::__construct();

        $config = parse_ini_file('filestorage.ini', true, INI_SCANNER_RAW);

        if (!$config)
            throw new Exception('Cannot read or parse "filestorage.ini"');

        if ($config['node']['hashalgo'] != 'md5' && $config['node']['hashalgo'] != 'sha256')
            throw new Exception('Hash algorithm "' . $config['node']['hashalgo'] . '" not supported');

        $this->exchangeArray($config);

    }

    function __destruct() {
        parent::__destruct();
    }
}

class queue {
    private $amqp_conn;
    private $amqp_pub;
    private $amqp_sub;
    private $hostname;
    private $queuename;

    function __construct(&$config, $queuename = false) {

        $this->hostname = $config['node']['hostname'];
        $this->queuename = $queuename ? $queuename : 'filestorage.replica.' . $this->hostname;

        $this->amqp_conn = new AMQPConnection();

        $this->amqp_conn->setHost($config['amqp']['host']);
        $this->amqp_conn->setPort($config['amqp']['port']);
        $this->amqp_conn->setLogin($config['amqp']['user']);
        $this->amqp_conn->setPassword($config['amqp']['pass']);
        $this->amqp_conn->setVhost($config['amqp']['vhost']);
        if (!$this->amqp_conn->connect())
            throw new Exception('Could not connect to AMQP broker');
    }

    function _init_pub() {

        $this->amqp_pub = new AMQPExchange(new AMQPChannel($amqp_conn));
        $this->amqp_pub->SetName('filestorage');
    }

    function _init_sub() {

        $this->amqp_sub = new AMQPQueue(new AMQPChannel($amqp_conn));
        $this->amqp_sub->SetName($this->queuename);
    }

    function _enqueue($message, $headers) {
        if (!$this->amqp_pub)
            $this->_init_pub();

        return $this->amqp_pub->publish($message, '', 0, array('headers' => array_fill_keys($headers, 'yes')));
    }

    function broadcast($message) {

        return $this->_enqueue($message, array('broadcast'));
    }

    function multicast($message) {

        return $this->_enqueue($message, array($this->hostname, 'log', 'db'));
    }

    function get() {
        if (!$this->amqp_sub)
            $this->_init_sub();

        return $this->amqp_sub->get();
    }

    function ack(&$message) {

        return $this->amqp_sub->ack($message->getDeliveryTag());
    }

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
