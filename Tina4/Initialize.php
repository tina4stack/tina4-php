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

if (isset($_SERVER, $_SERVER["REQUEST_URI"]) && strpos($_SERVER["REQUEST_URI"], "index.php") !== false)
{
    http_response_code(403);
    echo '<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN"><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>The requested URL was not found on this server.</p></body></html>';
    Debug::message("Tina4 stack should never be invoked by running index.php directly");
    exit;
}

//TINA4 CONSTANTS
if (!defined("TINA4_DATABASE_TYPES")) {
    define("TINA4_DATABASE_TYPES", ["Tina4\DataMySQL", "Tina4\DataFirebird", "Tina4\DataSQLite3", "Tina4\DataMongoDb", "Tina4\DataPostgresql", "Tina4\DataMSSQL"]);
}

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

if (!defined( "TINA4_RETURN_X_HEADERS")) {
    define("TINA4_RETURN_X_HEADERS", true);
}

Debug::$logLevel = TINA4_DEBUG_LEVEL;
Debug::message("Project Root: " . TINA4_PROJECT_ROOT, TINA4_LOG_DEBUG);
Debug::message("Document Root: " . TINA4_DOCUMENT_ROOT, TINA4_LOG_DEBUG);
Debug::message("SubFolder: " . TINA4_SUB_FOLDER, TINA4_LOG_DEBUG);

if (!defined("TINA4_DEBUG")) {
    define("TINA4_DEBUG", false);
}

if (!defined("TINA4_CACHE_ON")) {
    define("TINA4_CACHE_ON", false);
}

if (!defined("TINA4_SINGLE_USE_TOKENS")) {
    define("TINA4_SINGLE_USE_TOKENS", true);
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

//Initialize Secrets which starts the session
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
                \Tina4\Utilities::recurseCopy(TINA4_PROJECT_ROOT . DIRECTORY_SEPARATOR. "tina4php" . DIRECTORY_SEPARATOR . $folder, TINA4_DOCUMENT_ROOT . $folder);
            }
        }
    }

    if (file_exists(TINA4_PROJECT_ROOT . DIRECTORY_SEPARATOR. "tina4php" . DIRECTORY_SEPARATOR . ".htaccess")) {
        copy(TINA4_PROJECT_ROOT . DIRECTORY_SEPARATOR. "tina4php" . DIRECTORY_SEPARATOR. ".htaccess", TINA4_DOCUMENT_ROOT . ".htaccess");
    }
}

//Copy the bin folder if the vendor one has changed
if (TINA4_PROJECT_ROOT !== TINA4_DOCUMENT_ROOT) {
    $tina4Checksum = md5(file_get_contents(TINA4_PROJECT_ROOT. "tina4php-core". DIRECTORY_SEPARATOR . "bin" . DIRECTORY_SEPARATOR . "tina4").file_get_contents(TINA4_PROJECT_ROOT . "tina4php-core". DIRECTORY_SEPARATOR . "bin" . DIRECTORY_SEPARATOR . "tina4service"));
    $destChecksum = "";

    if (file_exists(TINA4_DOCUMENT_ROOT . "tina4php-core". DIRECTORY_SEPARATOR . "bin" . DIRECTORY_SEPARATOR . "tina4")) {
        $checkContent = file_exists(TINA4_DOCUMENT_ROOT . "tina4php-core". DIRECTORY_SEPARATOR . "bin" . DIRECTORY_SEPARATOR . "tina4") ? file_get_contents(TINA4_DOCUMENT_ROOT . "tina4php-core". DIRECTORY_SEPARATOR . "bin" . DIRECTORY_SEPARATOR . "tina4") : "";
        $checkContent .= file_exists(TINA4_DOCUMENT_ROOT . "tina4php-core". DIRECTORY_SEPARATOR . "bin" . DIRECTORY_SEPARATOR . "tina4service") ? file_get_contents(TINA4_DOCUMENT_ROOT . "tina4php-core". DIRECTORY_SEPARATOR . "bin" . DIRECTORY_SEPARATOR . "tina4service") : "";

        $destChecksum = md5($checkContent);
    }

    if ($tina4Checksum !== $destChecksum) {
        \Tina4\Utilities::recurseCopy(TINA4_PROJECT_ROOT . "tina4php-core". DIRECTORY_SEPARATOR . "bin", TINA4_DOCUMENT_ROOT . "bin");
    }
}

//On a rerun need to check if we have already instantiated the cache
if (!function_exists("createCache")) {
    function createCache()
    {
        //Initialize the Cache
        global $cache;

        if (!file_exists("." . DIRECTORY_SEPARATOR . "cache" . DIRECTORY_SEPARATOR) && !mkdir(
                "." . DIRECTORY_SEPARATOR . "cache" . DIRECTORY_SEPARATOR,
                0777,
                true
            )) {
            Debug::message("Could not create " . DIRECTORY_SEPARATOR . "cache");
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

        return $cache;
    }
}

if (defined("TINA4_CACHE_ON") && TINA4_CACHE_ON === true) {
    createCache();
} else {
    $cache = null;
}

//@todo Init Git Here
if (!class_exists("Get")) {
//Attributes for routing
// src/Attributes/Get.php
    #[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
    final class Get
    {
        public function __construct(public string $path)
        {
        }
    }
}

if (!class_exists("Post")) {
// src/Attributes/Post.php
    #[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
    final class Post
    {
        public function __construct(public string $path)
        {
        }
    }
}

if (!class_exists("Put")) {
// src/Attributes/Put.php
    #[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
    final class Put
    {
        public function __construct(public string $path)
        {
        }
    }
}

if (!class_exists("Patch")) {
// src/Attributes/Patch.php
    #[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
    final class Patch
    {
        public function __construct(public string $path)
        {
        }
    }
}

if (!class_exists("Delete")) {
// src/Attributes/Delete.php
    #[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
    final class Delete
    {
        public function __construct(public string $path)
        {
        }
    }
}

if (!class_exists("Any")) {
// src/Attributes/Any.php          ← matches ALL methods (very useful)
    #[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
    final class Any
    {
        public function __construct(public string $path)
        {
        }
    }
}


if (!function_exists("registerRouteFromAttributes")) {
    // ── Helper that does the actual registration ─────────────────────────────
    function registerRouteFromAttributes(ReflectionFunctionAbstract $ref, string $prefix = ''): void
    {
        foreach ($ref->getAttributes() as $attr) {
            $instance = $attr->newInstance();

            $path = $prefix . $instance->path;

            match ($attr->getName()) {
                Get::class => \Tina4\Get::add($path, $ref instanceof ReflectionMethod ? [$ref->class, $ref->name] : $ref->name),
                Post::class => \Tina4\Post::add($path, $ref instanceof ReflectionMethod ? [$ref->class, $ref->name] : $ref->name),
                Put::class => \Tina4\Put::add($path, $ref instanceof ReflectionMethod ? [$ref->class, $ref->name] : $ref->name),
                Patch::class => \Tina4\Patch::add($path, $ref instanceof ReflectionMethod ? [$ref->class, $ref->name] : $ref->name),
                Delete::class => \Tina4\Delete::add($path, $ref instanceof ReflectionMethod ? [$ref->class, $ref->name] : $ref->name),
                Any::class => \Tina4\Any::add($path, $ref instanceof ReflectionMethod ? [$ref->class, $ref->name] : $ref->name),
                default => null,
            };
        }
    }
}


$routeFolder = TINA4_DOCUMENT_ROOT. 'src';

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($routeFolder)) as $file) {
    if ($file->getExtension() !== 'php') continue;

    require_once $file->getRealPath();

    // ── Class methods (controllers) ─────────────────────────────────────
    foreach (get_declared_classes() as $class) {
        $refClass = new ReflectionClass($class);

        // Skip if this class wasn't in the one we just required
        if ($refClass->getFileName() !== $file->getRealPath()) continue;

        $prefix = '';
        foreach ($refClass->getAttributes(Prefix::class) as $attr) {
            $prefix = $attr->newInstance()->prefix;
        }

        foreach ($refClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            registerRouteFromAttributes($method, $prefix);
        }
    }

    // ── Global functions (flat Python style) ─────────────────────────────
    foreach (get_defined_functions()['user'] as $func) {
        $refFunc = new ReflectionFunction($func);
        if ($refFunc->getFileName() !== $file->getRealPath()) continue;

        registerRouteFromAttributes($refFunc);
    }
}


global $arrRoutes, $arrRouteIndex;


$arrRouteIndex = [];

foreach ($arrRoutes as $route) {
    $method = $route["method"] === TINA4_ANY ? "ANY" : $route["method"];
    $path = rtrim($route["routePath"], '/');
    $parts = $path === '' ? [] : explode('/', trim($path, '/'));

    $node = &$arrRouteIndex;
    if (!isset($node[$method])) $node[$method] = ['children' => [], 'routes' => []];
    $current = &$node[$method]['children'];

    foreach ($parts as $part) {
        if ($part === '') continue;
        if (!isset($current[$part])) {
            $current[$part] = ['children' => [], 'routes' => []];
        }
        $current = &$current[$part]['children'];
    }
    $current['routes'][] = $route;
}
// =====================================================================