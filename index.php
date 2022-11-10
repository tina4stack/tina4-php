<?php
require_once "vendor/autoload.php";

$config = new \Tina4\Config(function (\Tina4\Config $config) {
    //global $DBA;
    //$DBA = new \Tina4\DataSQLite3("data2.db");
});

//ini_set('open_basedir', 'C:\Users\andre\AppData\Local\Temp;D:\projects\php\tina4-php');

//Hack to build css for documentation
$scss = new ScssPhp\ScssPhp\Compiler();
$scssDefault = $scss->compileString(file_get_contents("./src/templates/documentation/default.scss"))->getCss();
file_put_contents("./src/templates/documentation/default.css", $scssDefault);

\Tina4\Initialize();

echo new \Tina4\Tina4Php($config);