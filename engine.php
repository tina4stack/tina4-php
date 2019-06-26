<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/02/09
 * Time: 01:54 PM
 * Purpose: This is th engine room of the stack which glues everything together and returns results, Tina4Stackv2 will work on a "routerless" concept
 * where files stored under assets will be served and parsed.
 * You are welcome to read and modify this code but it should not be broken, if you find something to improve it, send me an email with the patch
 * andrevanzuydam@gmail.com
 */

//root of the website
$documentRoot = realpath(dirname(__FILE__)."/../../../");
if (file_exists("engine.php")) {
    $documentRoot = realpath(dirname(__FILE__));
}
error_log ("TINA4: document root ".$documentRoot);

//root of tina4
$root = realpath(dirname(__FILE__));

//We use code when we debug, if we turn this off a phar file will be build from the code library, which is great for deployments
define("USE_CODE", true);

//check for composer defines
if (file_exists($root."/vendor/autoload.php")) {
    require_once $root."/vendor/autoload.php";
}

if (file_exists($documentRoot."/vendor/autoload.php")) {
    require_once $documentRoot."/vendor/autoload.php";
}

use Phpfastcache\CacheManager;
use Phpfastcache\Config\ConfigurationOption;
use Tina4\Routing;

//system defines, perhaps can be defined or overridden by a config.php file.
if (file_exists("{$documentRoot}/config.php")) {
    require_once ($documentRoot."/config.php");
}

if(!defined("TINA4_TEMPLATE_LOCATIONS")) {
    define("TINA4_TEMPLATE_LOCATIONS" , ["templates", "assets", "templates/snippets"]);
}

if(!defined("TINA4_ROUTE_LOCATIONS")) {
    define("TINA4_ROUTE_LOCATIONS"  , ["api","routes"]);
}

if(!defined("TINA4_INCLUDE_LOCATIONS")) {
    define("TINA4_INCLUDE_LOCATIONS"  , ["app","objects"]);
}

if (!defined( "TINA4_ALLOW_ORIGINS")) {
    define("TINA4_ALLOW_ORIGINS", ["*"]);
}

//Setup caching options
$TINA4_CACHE_CONFIG =
    new ConfigurationOption([
        "path"      =>  $documentRoot."/cache"
    ]);

//include build
if (!USE_CODE && !file_exists($root."/tina4core/tina4stackv2.phar")) {
    include("{$root}/../build/build.php");
}


/**
 * Global array to store the routes that are declared during runtime
 */
global $arrRoutes;
$arrRoutes = [];

if (!USE_CODE && file_exists($root."/tina4core/tina4stackv2.phar")) {
    //include the library for database engine, routing & template system
    include $root . "/tina4core/tina4stackv2.phar";
} else {
    if (USE_CODE) {
        set_include_path('.' . PATH_SEPARATOR . "{$root}/../code");
        require_once "Tina4/core.php";
    } else {
        die("tina4stack needs to be built for the system to run correctly.");
    }
}

error_log("TINA4: Document root ".$documentRoot);

//Check if assets folder is there
if (!file_exists($documentRoot."/assets") && !file_exists("engine.php")) {
    Tina4\Routing::recurseCopy($root."/assets", $documentRoot."/assets");
}
//Add the .htaccess file for redirecting things
if (!file_exists($documentRoot."/.htaccess") && !file_exists("engine.php")) {
    copy($root."/.htaccess", $documentRoot."/.htaccess");
}


global $cache;

CacheManager::setDefaultConfig($TINA4_CACHE_CONFIG);

$cache = CacheManager::getInstance("files");


if (isset($_SERVER["REQUEST_URI"]) && isset($_SERVER["REQUEST_METHOD"])) {
//do the routing and come up with a result, this should be the last line of code regardless
    echo new Routing($documentRoot, $_SERVER["REQUEST_URI"], $_SERVER["REQUEST_METHOD"]);
} else {
    echo new Routing($documentRoot, "/", "GET");
}
