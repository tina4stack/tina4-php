<?php
/**
 * Created by PhpStorm.
 * User: andrevanzuydam
 * Date: 2019-07-28
 * Time: 10:34
 */
namespace Tina4;

use Phpfastcache\CacheManager;
use Phpfastcache\Config\ConfigurationOption;
use Tina4\Routing;

class Tina4Php
{
    private $DBA;
    private $documentRoot; //The place where Tina4 exists
    private $webRoot; //The place where the website exists


    function __construct($config=null)
    {
        //root of the website
        $this->documentRoot = realpath(dirname(__FILE__)."/../../../");
        if (file_exists("Tina4.php")) {
            $this->documentRoot = realpath(dirname(__FILE__));
        }
        error_log ("TINA4: document root ".$this->documentRoot);

        //root of tina4
        $this->webRoot = realpath(dirname(__FILE__));

        error_log ("TINA4: web root ".$this->webRoot);

        //check for composer defines
        if (file_exists($this->webRoot."/vendor/autoload.php")) {
            require_once $this->webRoot."/vendor/autoload.php";
        }

        if (file_exists($this->documentRoot."/vendor/autoload.php")) {
            require_once $this->documentRoot."/vendor/autoload.php";
        }



        //system defines, perhaps can be defined or overridden by a config.php file.
        if (file_exists("{$this->documentRoot}/config.php")) {
            require_once ($this->documentRoot."/config.php");
        }

        if (!defined("TINA4_DEBUG")) {
            define ("TINA4_DEBUG", false);
        } else {
            if (TINA4_DEBUG) {
                error_log("TINA4 DEBUG: ON");
            }
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
                "path"      =>  $this->documentRoot."/cache"
            ]);

        /**
         * Global array to store the routes that are declared during runtime
         */
        global $arrRoutes;
        $arrRoutes = [];

        //Check if assets folder is there
        if (!file_exists($this->documentRoot."/assets") && !file_exists("Tina4.php")) {
            Tina4\Routing::recurseCopy($this->webRoot."/assets", $this->documentRoot."/assets");
        }

        //Add the .htaccess file for redirecting things
        if (!file_exists($this->documentRoot."/.htaccess") && !file_exists("engine.php")) {
            copy($this->webRoot."/.htaccess", $this->documentRoot."/.htaccess");
        }


        global $cache;

        CacheManager::setDefaultConfig($TINA4_CACHE_CONFIG);

        $cache = CacheManager::getInstance("files");


        if (isset($_SERVER["REQUEST_URI"]) && isset($_SERVER["REQUEST_METHOD"])) {
            return new Routing($this->documentRoot, $_SERVER["REQUEST_URI"], $_SERVER["REQUEST_METHOD"]);
        } else {
            return new Routing($this->documentRoot, "/", "GET");
        }

    }
}
