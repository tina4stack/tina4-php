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

//root of the system
$root = dirname(__FILE__);

//We use code when we debug, if we turn this off a phar file will be build from the code library, which is great for deployments
define("USE_CODE", true);

//check for composer defines
if (file_exists($root."/vendor/autoload.php")) {
    require_once $root."/vendor/autoload.php";
}

use Phpfastcache\CacheManager;
use Phpfastcache\Config\ConfigurationOption;

//system defines, perhaps can be defined or overridden by a config.php file.
if (file_exists("{$root}/config.php")) {
    require_once ($root."/config.php");
}


if(!defined("TINA4_TEMPLATE_LOCATIONS")) {
    define("TINA4_TEMPLATE_LOCATIONS" , ["templates", "assets", "templates/snippets"]);
}

if(!defined("TINA4_ROUTE_LOCATIONS")) {
    define("TINA4_ROUTE_LOCATIONS"  , ["api"]);
}

if(!defined("TINA4_INCLUDE_LOCATIONS")) {
    define("TINA4_INCLUDE_LOCATIONS"  , ["app","objects"]);
}

//Setup caching options
$TINA4_CACHE_CONFIG =
        new ConfigurationOption([
            "path"      =>  $root."/cache"
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
        require_once "tina4/core.php";
    } else {
        die("tina4stack needs to be built for the system to run correctly.");
    }
}

//See if cache directory exists
if (!file_exists($root."/cache")) {
    mkdir($root."/cache");
}

global $cache;

CacheManager::setDefaultConfig($TINA4_CACHE_CONFIG);

$cache = CacheManager::getInstance("files");


if (isset($_SERVER["REQUEST_URI"]) && isset($_SERVER["REQUEST_METHOD"])) {
//do the routing and come up with a result, this should be the last line of code regardless
    echo new Routing($root, $_SERVER["REQUEST_URI"], $_SERVER["REQUEST_METHOD"]);
} else {
    echo new Routing($root, "/", "GET");
}