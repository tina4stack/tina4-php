<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use Phpfastcache\CacheManager;
use Phpfastcache\Config\ConfigurationOption;
use Tina4\Debug;
use Tina4\Module;

//TINA4 CONSTANTS
if (!defined("TINA4_DATABASE_TYPES")) {
    define("TINA4_DATABASE_TYPES", ["Tina4\DataMySQL", "Tina4\DataFirebird", "Tina4\DataSQLite3", "Tina4\DataMongoDb", "Tina4\DataPostgresql"]);
}

//Get the sub folders etc using the data class
(new \Tina4\Data());

if (!defined("HTTP_OK")) {
    define("HTTP_OK", 200);
}

if (!defined("HTTP_CREATED")) {
    define("HTTP_CREATED", 201);
}

if (!defined("HTTP_NO_CONTENT")) {
    define("HTTP_NO_CONTENT", 204);
}

if (!defined("HTTP_PARTIAL_CONTENT")) {
    define("HTTP_PARTIAL_CONTENT", 206);
}

if (!defined("HTTP_BAD_REQUEST")) {
    define("HTTP_BAD_REQUEST", 400);
}

if (!defined("HTTP_UNAUTHORIZED")) {
    define("HTTP_UNAUTHORIZED", 401);
}

if (!defined("HTTP_FORBIDDEN")) {
    define("HTTP_FORBIDDEN", 403);
}

if (!defined("HTTP_NOT_FOUND")) {
    define("HTTP_NOT_FOUND", 404);
}

if (!defined("HTTP_REQUEST_RANGE_NOT_SATISFIABLE")) {
    define("HTTP_REQUEST_RANGE_NOT_SATISFIABLE", 416);
}

if (!defined("HTTP_METHOD_NOT_ALLOWED")) {
    define("HTTP_METHOD_NOT_ALLOWED", 405);
}

if (!defined("HTTP_RESOURCE_CONFLICT")) {
    define("HTTP_RESOURCE_CONFLICT", 409);
}

if (!defined("HTTP_MOVED_PERMANENTLY")) {
    define("HTTP_MOVED_PERMANENTLY", 301);
}

if (!defined("HTTP_INTERNAL_SERVER_ERROR")) {
    define("HTTP_INTERNAL_SERVER_ERROR", 500);
}

if (!defined("HTTP_NOT_IMPLEMENTED")) {
    define("HTTP_NOT_IMPLEMENTED", 501);
}

if (!defined("TINA4_POST")) {
    define("TINA4_POST", "POST");
}

if (!defined("TINA4_GET")) {
    define("TINA4_GET", "GET");
}

if (!defined("TINA4_ANY")) {
    define("TINA4_ANY", "ANY");
}

if (!defined("TINA4_PUT")) {
    define("TINA4_PUT", "PUT");
}

if (!defined("TINA4_PATCH")) {
    define("TINA4_PATCH", "PATCH");
}

if (!defined("TINA4_DELETE")) {
    define("TINA4_DELETE", "DELETE");
}

if (!defined("TEXT_HTML")) {
    define("TEXT_HTML", "text/html");
}
if (!defined("TEXT_PLAIN")) {
    define("TEXT_PLAIN", "text/plain");
}
if (!defined("APPLICATION_JSON")) {
    define("APPLICATION_JSON", "application/json");
}
if (!defined("APPLICATION_XML")) {
    define("APPLICATION_XML", "application/xml");
}

if (!defined("DATA_ARRAY")) {
    define("DATA_ARRAY", 0);
}
if (!defined("DATA_OBJECT")) {
    define("DATA_OBJECT", 1);
}
if (!defined("DATA_NUMERIC")) {
    define("DATA_NUMERIC", 2);
}
if (!defined("DATA_TYPE_TEXT")) {
    define("DATA_TYPE_TEXT", 0);
}
if (!defined("DATA_TYPE_NUMERIC")) {
    define("DATA_TYPE_NUMERIC", 1);
}
if (!defined("DATA_TYPE_BINARY")) {
    define("DATA_TYPE_BINARY", 2);
}
if (!defined("DATA_ALIGN_LEFT")) {
    define("DATA_ALIGN_LEFT", 0);
}
if (!defined("DATA_ALIGN_RIGHT")) {
    define("DATA_ALIGN_RIGHT", 1);
}
if (!defined("DATA_CASE_UPPER")) {
    define("DATA_CASE_UPPER", true);
}
if (!defined("DATA_NO_SQL")) {
    define("DATA_NO_SQL", "ERR001");
}

//Initialize the ENV
if (!defined("TINA4_DEBUG_LEVEL")) {
    define("TINA4_DEBUG_LEVEL", [TINA4_LOG_INFO]);
}

Debug::$logLevel = TINA4_DEBUG_LEVEL;
Debug::message("Project Root: " . TINA4_PROJECT_ROOT);
Debug::message("Document Root: " . TINA4_DOCUMENT_ROOT);
Debug::message("SubFolder: " . TINA4_SUB_FOLDER);

if (!defined("TINA4_DEBUG")) {
    define("TINA4_DEBUG", false);
}

if (TINA4_DEBUG) {
    error_reporting(E_ALL);
}

//twig globals are on by default
if (!defined("TINA4_TWIG_GLOBALS")) {
    define("TINA4_TWIG_GLOBALS", true);
}

//DEFINE EXPIRY FOR TOKENS
if (!defined("TINA4_TOKEN_MINUTES")) {
    define("TINA4_TOKEN_MINUTES", 10);
}

//Initialize Secrets
(new \Tina4\Auth());

if (!defined("TINA4_ALLOW_ORIGINS")) {
    define("TINA4_ALLOW_ORIGINS", ["*"]);
}


//Run the auto loader
/**
 * Autoloader
 * @param $class
 */
if (!function_exists("tina4_auto_loader")) {
    function tina4_auto_loader($class)
    {
        if (!defined("TINA4_DOCUMENT_ROOT")) {
            define("TINA4_DOCUMENT_ROOT", $_SERVER["DOCUMENT_ROOT"]);
        }

        if (!defined("TINA4_INCLUDE_LOCATIONS_INTERNAL")) {
            if (defined("TINA4_INCLUDE_LOCATIONS")) {
                define("TINA4_INCLUDE_LOCATIONS_INTERNAL", array_merge(TINA4_INCLUDE_LOCATIONS, Module::getTemplateFolders()));
            } else {
                define("TINA4_INCLUDE_LOCATIONS_INTERNAL", array_merge(["src" . DIRECTORY_SEPARATOR . "app", "src" . DIRECTORY_SEPARATOR . "objects", "src" . DIRECTORY_SEPARATOR . "orm", "src" . DIRECTORY_SEPARATOR . "services"], Module::getIncludeFolders()));
            }
        }

        $root = __DIR__;

        $class = explode("\\", $class);
        $class = $class[count($class) - 1];

        $found = false;
        if (defined("TINA4_INCLUDE_LOCATIONS_INTERNAL") && is_array(TINA4_INCLUDE_LOCATIONS_INTERNAL)) {
            foreach (TINA4_INCLUDE_LOCATIONS_INTERNAL as $lid => $location) {
                if (file_exists($location . DIRECTORY_SEPARATOR . "{$class}.php")) {
                    require_once $location . DIRECTORY_SEPARATOR . "{$class}.php";
                    $found = true;
                    break;
                } elseif (file_exists(TINA4_DOCUMENT_ROOT . DIRECTORY_SEPARATOR . "{$location}" . DIRECTORY_SEPARATOR . "{$class}.php")) {
                    require_once TINA4_DOCUMENT_ROOT . DIRECTORY_SEPARATOR . "{$location}" . DIRECTORY_SEPARATOR . "{$class}.php";
                    $found = true;
                    break;
                } else {
                    autoLoadFolders(TINA4_DOCUMENT_ROOT, $location, $class);
                }
            }
        }

        if (!$found) {
            $fileName = (string)($root) . DIRECTORY_SEPARATOR . str_replace("_", DIRECTORY_SEPARATOR, $class) . ".php";

            if (file_exists($fileName)) {
                require_once $fileName;
            }
        }
    }
}


/**
 * Recursive include
 * @param $documentRoot
 * @param $location
 * @param $class
 */
if (!function_exists("autoLoadFolders")) {
    function autoLoadFolders($documentRoot, $location, $class)
    {
        if (is_dir($documentRoot . $location)) {
            $subFolders = scandir($documentRoot . $location);
            foreach ($subFolders as $id => $file) {
                if (is_dir(realpath($documentRoot . $location . DIRECTORY_SEPARATOR . $file)) && $file !== "." && $file !== "..") {
                    $fileName = realpath($documentRoot . $location . DIRECTORY_SEPARATOR . $file . DIRECTORY_SEPARATOR . "{$class}.php");
                    if (file_exists($fileName)) {
                        require_once $fileName;
                    } else {
                        autoLoadFolders($documentRoot, $location . DIRECTORY_SEPARATOR . $file, $class);
                    }
                }
            }
        }
    }
}

//Initialize the Error handling
//We only want to fiddle with the defaults if we are developing
if (defined("TINA4_DEBUG") && TINA4_DEBUG) {
    set_exception_handler("tina4_exception_handler");
    set_error_handler("tina4_error_handler");
}

spl_autoload_register('tina4_auto_loader');
/**
 * Global array to store the routes that are declared during runtime
 */
global $arrRoutes;
$arrRoutes = [];

//Add the .htaccess file for redirecting things & copy the default src structure
if (!file_exists(TINA4_DOCUMENT_ROOT . ".htaccess") && !file_exists(TINA4_DOCUMENT_ROOT . "src")) {
    if (!file_exists(TINA4_DOCUMENT_ROOT . "src")) {
        $foldersToCopy = ["src/public", "src/app", "src/routes", "src/templates", "src/orm", "src/services", "src/scss"];
        foreach ($foldersToCopy as $id => $folder) {
            if (!file_exists(TINA4_DOCUMENT_ROOT . $folder)) {
                \Tina4\Utilities::recurseCopy(TINA4_PROJECT_ROOT . $folder, TINA4_DOCUMENT_ROOT . $folder);
            }
        }
    }

    if (file_exists(TINA4_PROJECT_ROOT . ".htaccess")) {
        copy(TINA4_PROJECT_ROOT . ".htaccess", TINA4_DOCUMENT_ROOT . ".htaccess");
    }
}

//Copy the bin folder if the vendor one has changed
if (TINA4_PROJECT_ROOT !== TINA4_DOCUMENT_ROOT) {
    $tina4Checksum = md5(file_get_contents(TINA4_PROJECT_ROOT . "bin" . DIRECTORY_SEPARATOR . "tina4").file_get_contents(TINA4_PROJECT_ROOT . "bin" . DIRECTORY_SEPARATOR . "tina4service"));
    $destChecksum = "";
    if (file_exists(TINA4_DOCUMENT_ROOT . "bin" . DIRECTORY_SEPARATOR . "tina4")) {
        $destChecksum = md5(file_get_contents(TINA4_DOCUMENT_ROOT . "bin" . DIRECTORY_SEPARATOR . "tina4").file_get_contents(TINA4_DOCUMENT_ROOT . "bin" . DIRECTORY_SEPARATOR . "tina4service"));
    }

    if ($tina4Checksum !== $destChecksum) {
        \Tina4\Utilities::recurseCopy(TINA4_PROJECT_ROOT . "bin", TINA4_DOCUMENT_ROOT . "bin");
    }
}

//Add the icon file for making it look pretty""
if (!file_exists(TINA4_DOCUMENT_ROOT . "favicon.ico")) {
    if (file_exists(TINA4_PROJECT_ROOT . "favicon.ico")) {
        copy(TINA4_PROJECT_ROOT . "favicon.ico", TINA4_DOCUMENT_ROOT . "favicon.ico");
    }
}

//Initialize the Cache
global $cache;
//On a rerun need to check if we have already instantiated the cache

if (defined("TINA4_CACHE_ON") && TINA4_CACHE_ON === true) {
    if (!file_exists("." . DIRECTORY_SEPARATOR . "cache" . DIRECTORY_SEPARATOR)) {
        if (!mkdir("." . DIRECTORY_SEPARATOR . "cache" . DIRECTORY_SEPARATOR, 0777, true)) {
            Debug::message("Could not create " . DIRECTORY_SEPARATOR . "cache");
        }
    }

    if (empty($cache)) {
        //Setup caching options
        try {
            $TINA4_CACHE_CONFIG =
                new ConfigurationOption([
                    "path" => TINA4_DOCUMENT_ROOT . "cache"
                ]);
            CacheManager::setDefaultConfig($TINA4_CACHE_CONFIG);
            $cache = CacheManager::getInstance("files");
        } catch (\Phpfastcache\Exceptions\PhpfastcacheInvalidConfigurationException $e) {
            \Tina4\Debug::message("Could not initialize cache", TINA4_LOG_ERROR);
        }
    }
} else {
    $cache = null;
}

//@todo Init Git Here
