<?php
/**
 * Created by PhpStorm.
 * User: andrevanzuydam
 * Date: 2019-08-18
 * Time: 10:50
 */
use Tina4\ORM;
require "vendor/autoload.php";
require "Tina4Php.php";

define("TINA4_TEMPLATE_LOCATIONS", ["templates", "assets", "templates/snippets"]);
define("TINA4_ROUTE_LOCATIONS", ["api", "routes"]);
define("TINA4_INCLUDE_LOCATIONS", ["app", "objects"]);

//Add an alias to ORM for legacy code
class_alias("\Tina4\ORM", "Tina4Object");
define ("TINA4_DEBUG", false);
define ("TINA4_DEBUG_LEVEL", DEBUG_CONSOLE);
//define ("TINA4_GIT_MESSAGE", "Some useful text here");
//define ("TINA4_GIT_ENABLED", true);
global $DBA;
//$DBA = (new \Tina4\DataFirebird("localhost:/home/SOMEDB.FDB","SYSDBA","masterkey"));
//$DBA =  new \Tina4\DataSQLite3("auth.db");
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
        $token = explode (":", base64_decode($token));
        $userName = $token[0];
        $password = $token[1];

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
//$config->auth = (new MyAuth());
$config->twigFilters["myFilter"] = function ($name) {
    return str_shuffle($name);
};
//Use this if you are running a hosted app
//define("TINA4_APP", "/templates/index.html");
echo new \Tina4\Tina4Php($config);
