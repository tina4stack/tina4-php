<?php
require_once "vendor/autoload.php";

$config = new \Tina4\Config(function (\Tina4\Config $config) {
    //Filter in Twig: {{ beep | 2 }}
    $config->addTwigFilter("beep", function($times = 1) {
        return str_repeat("beep ", $times);
    });

    //Global in Twig: {{MY_GLOBAL}}
    $config->addTwigGlobal("MY_GLOBAL", "IT WORKS!");

    //Function in Twig: {% set cars = getCars('*') %}
    $config->addTwigFunction("getCars", function($default="*") {
        return (new Store())->getCars($default);
    });
});

//ini_set('open_basedir', 'C:\Users\andre\AppData\Local\Temp;D:\projects\php\tina4-php');

//Hack to build css for documentation
$scss = new ScssPhp\ScssPhp\Compiler();
try {
    $scssDefault = $scss->compileString(file_get_contents("./src/templates/documentation/default.scss"))->getCss();
    file_put_contents("./src/templates/documentation/default.css", $scssDefault);
} catch (\ScssPhp\ScssPhp\Exception\SassException $e) {
    echo "Failed to compile documentation css";
}

\Tina4\Initialize();

echo new \Tina4\Tina4Php($config);