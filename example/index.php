<?php

require_once __DIR__ . "/../vendor/autoload.php";

global $DBA;
$DBA = new \Tina4\DataSQLite3("example.db");

$config = new \Tina4\Config(static function (\Tina4\Config $config) {
    // Application configuration
});

echo new \Tina4\Tina4Php($config);
