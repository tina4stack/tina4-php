<?php
require_once "vendor/autoload.php";

$config = new \Tina4\Config(function (\Tina4\Config $config) {});

$result = (new \Tina4\ReportEngine())->generate("test.rep", "test.pdf", "select * from table");

echo new \Tina4\Tina4Php($config);