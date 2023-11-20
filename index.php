<?php
require_once "./vendor/autoload.php";

$config = new \Tina4\Config(static function (\Tina4\Config $config){
  //Your own config initializations

});

//echo "<pre>";
//$test = (new \Tina4\Slack())->postMessage("Hello <!channel> World, Tina4 notifications are working", "general");
//$test = (new \Tina4\Slack())->getChannels();
//print_r ($test);

echo new \Tina4\Tina4Php($config);