<?php
/**
 * Created by PhpStorm.
 * User: andrevanzuydam
 * Date: 2019-08-18
 * Time: 10:50
 */
use Tina4\ORM;

require "vendor/autoload.php";

//temporary for running under non composer
define("DEBUG_CONSOLE", 9001);
define("DEBUG_SCREEN", 9002);
define("DEBUG_ALL", 9003);
define("DEBUG_NONE", 9004);

//if (file_exists("assets/index.twig")) unlink("assets/index.twig");

//Add an alias to ORM for legacy code
class_alias("\Tina4\ORM", "Tina4Object");
define ("TINA4_DEBUG", true);
define ("TINA4_DEBUG_LEVEL", DEBUG_CONSOLE);

global $DBA;
$DBA =  new \Tina4\DataSQLite3("auth.db");
//For mysql - docker-compose up in the relevant docker folder before running
//$DBA = new \Tina4\DataMySQL("127.0.0.1/5508:tina4", "tina4", "Password1234");

echo new \Tina4\Tina4Php();
