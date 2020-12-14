<?php
/**
 * Created by PhpStorm.
 * User: andrevanzuydam
 * Date: 2019-08-18
 * Time: 10:50
 */

require "vendor/autoload.php";
require "Tina4Php.php";
require "Tina4/Config.php";

define("TINA4_TEMPLATE_LOCATIONS", ["templates", "assets", "templates/snippets"]);
define("TINA4_ROUTE_LOCATIONS", ["api", "routes"]);
define("TINA4_INCLUDE_LOCATIONS", ["app", "objects", "services"]);

//Add an alias to ORM for legacy code
class_alias("\Tina4\ORM", "Tina4Object");
//define ("TINA4_GIT_MESSAGE", "Some useful text here");
//define ("TINA4_GIT_ENABLED", true);
global $DBA;

//$DBA = new \Tina4\DataMySQL(DATABASE_HOST . "/" . DATABASE_PORT . ":". DATABASE_NAME, DATABASE_USER, DATABASE_PASSWORD, "d/m/Y");


//$DBA = (new \Tina4\DataFirebird(FIREBIRD_DATABASE,FIREBIRD_USERNAME,FIREBIRD_PASSWORD));
//$DBA =  new \Tina4\DataSQLite3("auth.db");
//For mysql - docker-compose up in the relevant docker folder before running
//$DBA = new \Tina4\DataMySQL("127.0.0.1/5508:tina4", "tina4", "Password1234", "d/m/Y");

$config = new \Tina4\Config();

//Uncomment if you want to play with auth
//$config->setAuth((new MyAuth()));

//$config->addFilter("myFilter", function ($name) {
//    return str_shuffle($name);
//});
//Use this if you are running a hosted app
//define("TINA4_APP", "/templates/index.html");

//exec("ls -l", $output);

//$DBA->exec("delete from test_save");

//$testSave = new TestSave();

//print_r ($testSave->asArray());

//$testSave->save();

//print_r ($testSave->asArray());

//$testSave->age = "a";
//$testSave->email = "testingme@test.com";
//$testSave->ageDefault = 12;
//$testSave->date = 'xcxxnxnxnx';


echo new \Tina4\Tina4Php($config);
//WARNING NO CODE BELOW LINE 45 WILL BE RUN