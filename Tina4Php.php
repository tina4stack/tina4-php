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

class Tina4Php
{
    private $DBA;
    private $documentRoot; //The place where Tina4 exists
    private $webRoot; //The place where the website exists


    function __construct($config=null)
    {
        //define constants

        if (!defined("TINA4_POST")) define("TINA4_POST" , "POST");
        if (!defined("TINA4_GET")) define("TINA4_GET"  , "GET");
        if (!defined("TINA4_ANY")) define("TINA4_ANY"  , "ANY");
        if (!defined("TINA4_PUT")) define("TINA4_PUT"  , "PUT");
        if (!defined("TINA4_PATCH")) define("TINA4_PATCH"  , "PATCH");
        if (!defined("TINA4_DELETE")) define("TINA4_DELETE"  , "DELETE");

        //root of the website
        $this->documentRoot = realpath(dirname(__FILE__).DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR);

        if (file_exists("Tina4Php.php")) {
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
            \Tina4\Routing::recurseCopy($this->webRoot."/assets", $this->documentRoot."/assets");
        }

        //Add the .htaccess file for redirecting things
        if (!file_exists($this->documentRoot."/.htaccess") && !file_exists("engine.php")) {
            copy($this->webRoot."/.htaccess", $this->documentRoot."/.htaccess");
        }


        global $cache;

        CacheManager::setDefaultConfig($TINA4_CACHE_CONFIG);

        $cache = CacheManager::getInstance("files");

        global $twig;

        $twigPaths = TINA4_TEMPLATE_LOCATIONS;

        if (TINA4_DEBUG) {
            error_log("TINA4: Twig Paths\n" . print_r($twigPaths, 1));
        }

        foreach ($twigPaths as $tid => $twigPath) {
            if (!file_exists($this->documentRoot."/".$twigPath)) {
                unset($twigPaths[$tid]);
            }
        }

        $twigLoader = new \Twig\Loader\FilesystemLoader();
        foreach ($twigPaths as $twigPath) {
            $twigLoader->addPath($twigPath, '__main__');
        }

        $twig = new \Twig\Environment($twigLoader, ["debug" => true]);
        $twig->addExtension(new \Twig\Extension\DebugExtension());

    }

    function __toString()
    {
        $string = "";
        if (isset($_SERVER["REQUEST_URI"]) && isset($_SERVER["REQUEST_METHOD"])) {
            $string .= new \Tina4\Routing($this->documentRoot, $_SERVER["REQUEST_URI"], $_SERVER["REQUEST_METHOD"]);

        } else {
            $string .= new \Tina4\Routing($this->documentRoot, "/", "GET");
        }
        return $string;
    }
}

/**
 * Helper functions below used by the whole system
 */

/**
 * Render twig template
 * @param $fileName
 * @param array $data
 * @return string
 * @throws \Twig\Error\LoaderError
 * @throws \Twig\Error\RuntimeError
 * @throws \Twig\Error\SyntaxError
 */
function renderTemplate ($fileName, $data=[]) {
    try {
        global $twig;

        return $twig->render($fileName, $data);
    } catch (Exception $exception) {
        return $exception->getMessage();
    }
}

/**
 * Redirect
 * @param $url
 * @param int $statusCode
 */
function redirect($url, $statusCode = 303)
{
    header('Location: ' . $url, true, $statusCode);
    die();
}

/**
 * Autoloader
 * @param $class
 */
function tina4_autoloader($class) {
    $root = dirname(__FILE__);

    $class = explode("\\", $class);
    $class = $class[count($class)-1];

    $fileName = "{$root}/".str_replace("_","/",$class). ".php";

    if (file_exists($fileName)) {
        include_once $fileName;
    }  else {
        if (defined("TINA4_INCLUDE_LOCATIONS") && is_array(TINA4_INCLUDE_LOCATIONS)) {
            foreach (TINA4_INCLUDE_LOCATIONS as $lid => $location) {
                if (file_exists($_SERVER["DOCUMENT_ROOT"]."/{$location}/{$class}.php")) {
                    require_once $_SERVER["DOCUMENT_ROOT"] . "/{$location}/{$class}.php";
                    break;
                }
            }
        }
    }

}

spl_autoload_register('Tina4\tina4_autoloader');
