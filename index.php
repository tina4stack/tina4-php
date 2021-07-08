<?php
require_once "vendor/autoload.php";

$config = new \Tina4\Config(function (\Tina4\Config $config) {

    $config->addTwigGlobal("HELLO", "ME");
    $config->addTwigFunction("getUsers", function($myvar) {
        return $myvar;
    });

});

echo new \Tina4\Tina4Php($config);