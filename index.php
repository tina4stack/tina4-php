<?php
/**
 * Created by PhpStorm.
 * User: andrevanzuydam
 * Date: 2019-08-18
 * Time: 10:50
 */
use Tina4\ORM;

require "vendor/autoload.php";
//Add an alias to ORM for legacy code
class_alias("\Tina4\ORM", "Tina4Object");
//define ("TINA4_DEBUG", true);

global $DBA;

//For mysql - docker-compose up in the relevant docker folder before running
//$DBA = new \Tina4\DataMySQL("127.0.0.1/5508:tina4", "tina4", "Password1234");

echo new \Tina4\Tina4Php();
