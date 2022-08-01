<?php
require_once "vendor/autoload.php";

$config = new \Tina4\Config(function (\Tina4\Config $config) {
//    global $ME;
//    $ME = new \Tina4\DataSQLite3("data2.db");
//    global $MY;
//    $MY = new \Tina4\DataSQLite3("data2.db");
});

\Tina4\Initialize();
echo new \Tina4\Tina4Php($config);