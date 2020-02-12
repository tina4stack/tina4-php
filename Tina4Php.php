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

/**
 * Class Tina4Php Main class used to set constants
 * @package Tina4
 */
class Tina4Php
{
    /**
     * @var resource Database connection
     */
    private $DBA;

    /**
     * @var false|string The place where Tina4 exists
     */
    private $documentRoot;

    /**
     * @var false|string The place where the website exists
     */
    private $webRoot;

    /**
     * Tina4Php constructor.
     * @param null $config
     * @throws \Phpfastcache\Exceptions\PhpfastcacheDriverCheckException
     * @throws \Phpfastcache\Exceptions\PhpfastcacheDriverException
     * @throws \Phpfastcache\Exceptions\PhpfastcacheDriverNotFoundException
     * @throws \Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException
     * @throws \Phpfastcache\Exceptions\PhpfastcacheInvalidConfigurationException
     * @throws \ReflectionException
     * @throws \Twig\Error\LoaderError
     */
    function __construct($config = null)
    {
        //define constants

        if (!defined("TINA4_POST")) define("TINA4_POST", "POST");
        if (!defined("TINA4_GET")) define("TINA4_GET", "GET");
        if (!defined("TINA4_ANY")) define("TINA4_ANY", "ANY");
        if (!defined("TINA4_PUT")) define("TINA4_PUT", "PUT");
        if (!defined("TINA4_PATCH")) define("TINA4_PATCH", "PATCH");
        if (!defined("TINA4_DELETE")) define("TINA4_DELETE", "DELETE");
        if (!defined("TINA4_DOCUMENT_ROOT")) define ("TINA4_DOCUMENT_ROOT", null);

        $debugBackTrace = debug_backtrace();
        $callerFile = $debugBackTrace[0]["file"]; //calling file /.../.../index.php
        $callerDir = str_replace("index.php", "", $callerFile);

        //root of the website
        if (defined ("TINA4_DOCUMENT_ROOT") && empty(TINA4_DOCUMENT_ROOT)) {
            $this->documentRoot = $callerDir;
            //$this->documentRoot = realpath(dirname(__FILE__) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR);
        } else {
            $this->documentRoot = TINA4_DOCUMENT_ROOT;
        }

        if (file_exists("Tina4Php.php")) {
            $this->documentRoot = realpath(dirname(__FILE__));
        }
        error_log("TINA4: document root " . $this->documentRoot);

        //root of tina4
        $this->webRoot = realpath(dirname(__FILE__));

        error_log("TINA4: web path " . $this->webRoot);

        //check for composer defines
        if (file_exists($this->webRoot . "/vendor/autoload.php")) {
            require_once $this->webRoot . "/vendor/autoload.php";
        }

        if (file_exists($this->documentRoot . "/vendor/autoload.php")) {
            require_once $this->documentRoot . "/vendor/autoload.php";
        }

        if (!defined("TINA4_DEBUG")) {
            define("TINA4_DEBUG", false);
        } else {
            if (TINA4_DEBUG) {
                error_log("TINA4 DEBUG: ON");
            }
        }

        if (!defined("TINA4_TEMPLATE_LOCATIONS")) {
            define("TINA4_TEMPLATE_LOCATIONS", ["templates", "assets", "templates/snippets"]);
        }

        if (!defined("TINA4_ROUTE_LOCATIONS")) {
            define("TINA4_ROUTE_LOCATIONS", ["api", "routes"]);
        }

        if (!defined("TINA4_INCLUDE_LOCATIONS")) {
            define("TINA4_INCLUDE_LOCATIONS", ["app", "objects"]);
        }

        if (!defined("TINA4_ALLOW_ORIGINS")) {
            define("TINA4_ALLOW_ORIGINS", ["*"]);
        }


        //Setup caching options
        $TINA4_CACHE_CONFIG =
            new ConfigurationOption([
                "path" => $this->documentRoot . "/cache"
            ]);

        /**
         * Global array to store the routes that are declared during runtime
         */
        global $arrRoutes;
        $arrRoutes = [];


        $foldersToCopy = ["assets", "app", "api", "routes", "templates", "objects"];

        foreach ($foldersToCopy as $id => $folder) {
            //Check if folder is there
            if (!file_exists($this->documentRoot . "/{$folder}") && !file_exists("Tina4Php.php")) {
                \Tina4\Routing::recurseCopy($this->webRoot . "/{$folder}", $this->documentRoot . "/{$folder}");
            }
        }

        //Add the .htaccess file for redirecting things
        if (!file_exists($this->documentRoot . "/.htaccess") && !file_exists("engine.php")) {
            copy($this->webRoot . "/.htaccess", $this->documentRoot . "/.htaccess");
        }

        global $cache;

        CacheManager::setDefaultConfig($TINA4_CACHE_CONFIG);

        $cache = CacheManager::getInstance("files");

        global $twig;

        $twigPaths = TINA4_TEMPLATE_LOCATIONS;

        //error_log("TINA4: Twig Paths\n" . print_r($twigPaths, 1));

        if (TINA4_DEBUG) {
            error_log("TINA4: Twig Paths\n" . print_r($twigPaths, 1));
        }

        foreach ($twigPaths as $tid => $twigPath) {
            if (!file_exists(str_replace("//", "/", $this->documentRoot . "/" . $twigPath))) {
                unset($twigPaths[$tid]);
            }
        }

        $twigLoader = new \Twig\Loader\FilesystemLoader();
        foreach ($twigPaths as $twigPath) {
            $twigLoader->addPath($twigPath, '__main__');
        }

        $twig = new \Twig\Environment($twigLoader, ["debug" => true]);
        $twig->addExtension(new \Twig\Extension\DebugExtension());
        $twig->addGlobal('Tina4', new \Tina4\Caller());

    }

    /**
     * Converts URL requests to string and determines whether or not it contains an alias
     * @return string Route URL
     * @throws \ReflectionException
     */
    function __toString()
    {
        $string = "";

        if (isset($_SERVER["REQUEST_URI"]) && isset($_SERVER["REQUEST_METHOD"])) {

            //Check if the REQUEST_URI contains an alias
            if (isset($_SERVER["CONTEXT_PREFIX"]) && $_SERVER["CONTEXT_PREFIX"] != "") {

                //Delete the first instance of the alias in the REQUEST_URI
                $newRequestURI = stringReplaceFirst($_SERVER["CONTEXT_PREFIX"], "", $_SERVER["REQUEST_URI"]);

                $string .= new \Tina4\Routing($this->documentRoot, $newRequestURI, $_SERVER["REQUEST_METHOD"]);
            } else {
                $string .= new \Tina4\Routing($this->documentRoot, $_SERVER["REQUEST_URI"], $_SERVER["REQUEST_METHOD"]);
            }

        } else {
            $string .= new \Tina4\Routing($this->documentRoot, "/", "GET");
        }
        return $string;
    }
}

/**
 * Allows replacement of only the first occurrence
 * @param string $search What you want to search for
 * @param string $replace What you want to replace it with
 * @param string $content What you are searching in
 * @return string|string[]|null
 */
function stringReplaceFirst($search, $replace, $content)
{
    $search = '/' . preg_quote($search, '/') . '/';

    return preg_replace($search, $replace, $content, 1);
}

/**
 * Helper functions below used by the whole system
 */

/**
 * Render twig template
 * @param string $fileName File name or path of twig template
 * @param array $data Array for data to be passed into twig template
 * @return string
 * @throws \Twig\Error\LoaderError
 * @throws \Twig\Error\RuntimeError
 * @throws \Twig\Error\SyntaxError
 * @example examples\exampleTina4PHPRenderTemplateInAPI.php
 */
function renderTemplate($fileName, $data = [])
{
    try {
        global $twig;
        if ($twig->getLoader()->exists($fileName)) {
            return $twig->render($fileName, $data);
        }
        else
            if ($twig->getLoader()->exists(basename($fileName))) {
                return $twig->render(basename($fileName), $data);
            }
            else {
                $fileName = basename($fileName);
                $twigLoader = new \Twig\Loader\FilesystemLoader();
                $newPath = str_replace($_SERVER["DOCUMENT_ROOT"], "", dirname($fileName)."/");
                $twigLoader->addPath( $newPath );
                $twig->setLoader($twigLoader);
                $fileName = basename($fileName);
                return $twig->render($fileName, $data);
            }
    } catch (Exception $exception) {
        return $exception->getMessage();
    }
}

/**
 * Redirect
 * @param string $url The URL to be redirected to
 * @param integer $statusCode Code of status
 * @example examples\exampleTina4PHPRedirect.php
 */
function redirect($url, $statusCode = 303)
{
    //Define URL to test from parsed string
    $testURL = parse_url($url);

    //Check if test URL contains a scheme (http or https) or if it contains a host name
    if ((!isset($testURL["scheme"]) || $testURL["scheme"] == "") && (!isset($testURL["host"]) || $testURL["host"] == "")) {

        //Check if the current page uses an alias and if the parsed URL string is an absolute URL
        if (isset($_SERVER["CONTEXT_PREFIX"]) && $_SERVER["CONTEXT_PREFIX"] != "" && substr($url, 0, 1) === '/') {

            //Append the prefix to the absolute path
            header('Location: ' . $_SERVER["CONTEXT_PREFIX"] . $url, true, $statusCode);
            die();
        } else {
            header('Location: ' . $url, true, $statusCode);
            die();
        }

    } else {
        header('Location: ' . $url, true, $statusCode);
        die();
    }

}

/**
 * Autoloader
 * @param $class
 */
function tina4_autoloader($class)
{
    $root = dirname(__FILE__);

    $class = explode("\\", $class);
    $class = $class[count($class) - 1];

    $fileName = "{$root}/" . str_replace("_", "/", $class) . ".php";

    if (file_exists($fileName)) {
        include_once $fileName;
    } else {
        if (defined("TINA4_INCLUDE_LOCATIONS") && is_array(TINA4_INCLUDE_LOCATIONS)) {
            foreach (TINA4_INCLUDE_LOCATIONS as $lid => $location) {
                if (file_exists($_SERVER["DOCUMENT_ROOT"] . "/{$location}/{$class}.php")) {
                    require_once $_SERVER["DOCUMENT_ROOT"] . "/{$location}/{$class}.php";
                    break;
                }
            }
        }
    }

}

spl_autoload_register('Tina4\tina4_autoloader');