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
define ("TINA4_DEBUG", false);
define ("TINA4_DEBUG_LEVEL", DEBUG_CONSOLE);
//define ("TINA4_GIT_MESSAGE", "Some useful text here");
//define ("TINA4_GIT_ENABLED", true);
global $DBA;

//$DBA = (new \Tina4\DataFirebird("localhost:/home/SOMEDB.FDB","SYSDBA","masterkey"));
//$DBA =  new \Tina4\DataSQLite3("test.db");
//For mysql - docker-compose up in the relevant docker folder before running
//$DBA = new \Tina4\DataMySQL("127.0.0.1/5508:tina4", "tina4", "Password1234");


/**
 * Custom auth example, please put in the app folder in it's own file to make it neat!
 */
class MyAuth extends \Tina4\Auth {

    //Authorization header
    function validToken($token)
    {
        //echo "Write some auth code here to validate {$token} ";
        return true;
    }

    //Session token if no auth header
    function tokenExists()
    {
       // echo "Write some code to check if a token exists in the session ";
        return true;
    }

}

$config = (object)[];
//Uncomment if you want to play with auth
$config->auth = (new MyAuth());

//Use this if you are running a hosted app
//define("TINA4_APP", "/templates/index.html");
echo new \Tina4\Tina4Php($config);
