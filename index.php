<?php
require_once "vendor/autoload.php";

$config = new \Tina4\Config(function (\Tina4\Config $config) {
    //global $DBA;
    //$DBA = new \Tina4\DataSQLite3("data.db");
});

\Tina4\Initialize();

echo new \Tina4\Tina4Php($config);