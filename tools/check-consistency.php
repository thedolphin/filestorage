<?php

require "../common.php";

$rep_host = 'wm25';
$rep_prefix = "http://$rep_host.i";

function scan($base) {
    global $config;

    $dh = opendir($config['node']['storage'] .'/'. $base);
    while (($file = readdir($dh)) !== false) {
        if (is_dir($config['node']['storage'] .'/'. $base .'/'. $file) && preg_match('/^[0-9a-f]{2}$/', $file)) {
            scan($base .'/'. $file );
        }
        if (is_file($config['node']['storage'] .'/'. $base .'/'. $file) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\..+$/', $file)) {
            check($base, $file);
        }
    }
}

function check($base, $file) {
    global $db;
    global $rep_prefix;
    global $config;

    $parts = explode('.', $file);
    $uuid = $parts[0]; $ext = $parts[1];
    $base = substr($base, 1);

    $dbmiss = 0; $repmiss = 0;

    if($res = mysql_query("SELECT `group`, HEX(`hash`) as `hash-text` FROM files WHERE `uuid` = UNHEX(REPLACE('". $uuid ."', '-', ''))", $db)) {
        if (mysql_num_rows($res) == 0) {
            $dbmiss = 1;
        }
    }

    $ch = curl_init("$rep_prefix/$base/$file");
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_exec($ch);
    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) > 400) {
        $repmiss = 1;
    }
    curl_close($ch);

    if ($dbmiss || $repmiss) {
        print "$file: ";
        if ($dbmiss) {
            print 'dbmiss ';
        }
        if ($repmiss) {
            print 'repmiss ';
        }
        print "\n";
    }

#a:6:{
#    s:6:"Action";s:4:"copy";
#    s:4:"Time";i:1347370784;
#    s:6:"Prefix";s:13:"http://wm23.i";
#    s:10:"GroupIndex";s:1:"3";
#    s:8:"ClientIP";s:9:"10.1.0.41";
#    s:4:"Data";a:7:{
#        s:9:"Extension";s:4:"jpeg";
#        s:4:"UUID";s:36:"3febaa44-ce28-4474-8421-b59dc64c1272";
#        s:8:"Filename";s:31:"df_image1984507891637075503.jpg";
#        s:6:"Source";s:47:"12/4c/3febaa44-ce28-4474-8421-b59dc64c1272.jpeg";
#        s:4:"Size";s:4:"3325";
#        s:4:"Hash";s:32:"f28cb3d637af07c5a1890dbb9d31af4a";
#        s:4:"Host";s:4:"wm23";}}

    if($repmiss || $dbmiss) {
        $hash = md5(file_get_contents($config['node']['storage'] .'/'. $base .'/'. $file));

        $data = array( 'Action' => 'copy',
            'Time' => time(),
            'Prefix' => $config['node']['hostprefix'],
            'GroupIndex' => $config['group']['index'],
            'ClientIP' => '127.0.0.1',
            'Data' => array(
                'Extension' => $ext,
                'UUID' => $uuid,
                'Filename' => 'restored_from_' . $config['node']['hostname'] .'.'. $ext,
                'Source' => "$base/$file",
                'Size' => filesize($config['node']['storage'] .'/'. $base .'/'. $file),
                'Hash' => md5(file_get_contents($config['node']['storage'] .'/'. $base .'/'. $file)),
                'Host' => $config['node']['hostname']
            )
        );
    }

    if($repmiss && $dbmiss) {
        if (!mq_send_to_slaves(serialize($data))){
            throw new Exception('AMQPExchange::publish returned FALSE');
        }
    }
}

init();
mq_init();
mq_init_pub();

if(!$db = mysql_connect ($config['db']['host'], $config['db']['user'], $config['db']['pass'])) throw new Exception("Cannot connect to mysql");
if(!mysql_select_db($config['db']['db'], $db)) throw new Exception("Cannot connect to database");

scan('');
