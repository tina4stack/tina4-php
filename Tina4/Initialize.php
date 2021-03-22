<?php

use Phpfastcache\CacheManager;
use Phpfastcache\Config\ConfigurationOption;
use Tina4\HTMLElement;

//DEBUG & ERROR LOG CONSTANTS
define("TINA4_LOG_EMERGENCY","emergency");
define("TINA4_LOG_ALERT","alert");
define("TINA4_LOG_CRITICAL","critical");
define("TINA4_LOG_ERROR","error");
define("TINA4_LOG_WARNING","warning");
define("TINA4_LOG_NOTICE","notice");
define("TINA4_LOG_INFO","info");
define("TINA4_LOG_DEBUG","debug");
define("TINA4_LOG_ALL","all");

//TINA4 CONSTANTS
if (!defined("TINA4_DATABASE_TYPES")) define("TINA4_DATABASE_TYPES", ["Tina4\DataMySQL", "Tina4\DataFirebird", "Tina4\DataSQLite3"]);


if (!defined("HTTP_OK")) define("HTTP_OK", 200);
if (!defined("HTTP_CREATED")) define("HTTP_CREATED", 201);
if (!defined("HTTP_NO_CONTENT")) define("HTTP_NO_CONTENT", 204);
if (!defined("HTTP_PARTIAL_CONTENT")) define("HTTP_PARTIAL_CONTENT", 206);
if (!defined("HTTP_BAD_REQUEST")) define("HTTP_BAD_REQUEST", 400);
if (!defined("HTTP_UNAUTHORIZED")) define("HTTP_UNAUTHORIZED", 401);
if (!defined("HTTP_FORBIDDEN")) define("HTTP_FORBIDDEN", 403);
if (!defined("HTTP_NOT_FOUND")) define("HTTP_NOT_FOUND", 404);
if (!defined("HTTP_REQUEST_RANGE_NOT_SATISFIABLE")) define("HTTP_REQUEST_RANGE_NOT_SATISFIABLE", 416);
if (!defined("HTTP_METHOD_NOT_ALLOWED")) define("HTTP_METHOD_NOT_ALLOWED", 405);
if (!defined("HTTP_RESOURCE_CONFLICT")) define("HTTP_RESOURCE_CONFLICT", 409);
if (!defined("HTTP_MOVED_PERMANENTLY")) define("HTTP_MOVED_PERMANENTLY", 301);
if (!defined("HTTP_INTERNAL_SERVER_ERROR")) define("HTTP_INTERNAL_SERVER_ERROR", 500);
if (!defined("HTTP_NOT_IMPLEMENTED")) define("HTTP_NOT_IMPLEMENTED", 501);


if (!defined("TINA4_POST")) define("TINA4_POST", "POST");
if (!defined("TINA4_GET")) define("TINA4_GET", "GET");
if (!defined("TINA4_ANY")) define("TINA4_ANY", "ANY");
if (!defined("TINA4_PUT")) define("TINA4_PUT", "PUT");
if (!defined("TINA4_PATCH")) define("TINA4_PATCH", "PATCH");
if (!defined("TINA4_DELETE")) define("TINA4_DELETE", "DELETE");


if (!defined("TEXT_HTML")) define("TEXT_HTML", "text/html");
if (!defined("TEXT_PLAIN")) define("TEXT_PLAIN", "text/plain");
if (!defined("APPLICATION_JSON")) define("APPLICATION_JSON", "application/json");
if (!defined("APPLICATION_XML")) define("APPLICATION_XML", "application/xml");

if (!defined("DATA_ARRAY")) define("DATA_ARRAY", 0);
if (!defined("DATA_OBJECT")) define("DATA_OBJECT", 1);
if (!defined("DATA_NUMERIC")) define("DATA_NUMERIC", 2);
if (!defined("DATA_TYPE_TEXT")) define("DATA_TYPE_TEXT", 0);
if (!defined("DATA_TYPE_NUMERIC")) define("DATA_TYPE_NUMERIC", 1);
if (!defined("DATA_TYPE_BINARY")) define("DATA_TYPE_BINARY", 2);
if (!defined("DATA_ALIGN_LEFT")) define("DATA_ALIGN_LEFT", 0);
if (!defined("DATA_ALIGN_RIGHT")) define("DATA_ALIGN_RIGHT", 1);
if (!defined("DATA_CASE_UPPER")) define("DATA_CASE_UPPER", true);
if (!defined("DATA_NO_SQL")) define("DATA_NO_SQL", "ERR001");

//Initialize error handler

//Initialize the ENV
(new \Tina4\Env());
if(!defined("TINA4_DEBUG_LEVEL")) {
    define("TINA4_DEBUG_LEVEL", [TINA4_LOG_DEBUG]);
}
\Tina4\Debug::$logLevel = TINA4_DEBUG_LEVEL;

if (!define("TINA4_DEBUG"))
{
    define("TINA4_DEBUG", false);
}

if (TINA4_DEBUG)
{
    error_reporting(E_ALL);
}

//DEFINE EXPIRY FOR TOKENS
if (!defined("TINA4_TOKEN_MINUTES"))
{
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
function tina4_auto_loader($class)
{
    if (!defined("TINA4_DOCUMENT_ROOT")) {
        define("TINA4_DOCUMENT_ROOT", $_SERVER["DOCUMENT_ROOT"]);
    }

    if (!defined("TINA4_INCLUDE_LOCATIONS_INTERNAL")) {
        if (defined("TINA4_INCLUDE_LOCATIONS")) {
            define("TINA4_INCLUDE_LOCATIONS_INTERNAL", array_merge(TINA4_INCLUDE_LOCATIONS , Module::getTemplateFolders()));
        } else {
            define("TINA4_INCLUDE_LOCATIONS_INTERNAL", array_merge(["src".DIRECTORY_SEPARATOR."app", "src".DIRECTORY_SEPARATOR."objects", "src".DIRECTORY_SEPARATOR."orm", "src".DIRECTORY_SEPARATOR."services"], Module::getIncludeFolders()));
        }
    }

    $root = __DIR__;

    $class = explode("\\", $class);
    $class = $class[count($class) - 1];

    $found = false;
    if (defined("TINA4_INCLUDE_LOCATIONS_INTERNAL") && is_array(TINA4_INCLUDE_LOCATIONS_INTERNAL)) {
        foreach (TINA4_INCLUDE_LOCATIONS_INTERNAL as $lid => $location) {
            if (file_exists($location.DIRECTORY_SEPARATOR."{$class}.php")) {
                require_once $location.DIRECTORY_SEPARATOR."{$class}.php";
                $found = true;
                break;
            } else
                if (file_exists(TINA4_DOCUMENT_ROOT.DIRECTORY_SEPARATOR. "{$location}" . DIRECTORY_SEPARATOR . "{$class}.php")) {
                    require_once TINA4_DOCUMENT_ROOT.DIRECTORY_SEPARATOR. "{$location}" . DIRECTORY_SEPARATOR . "{$class}.php";
                    $found = true;
                    break;
                }
                else {
                    autoLoadFolders(TINA4_DOCUMENT_ROOT,$location,$class);
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

/**
 * Recursive include
 * @param $documentRoot
 * @param $location
 * @param $class
 */
function autoLoadFolders($documentRoot, $location, $class) {
    if (is_dir($documentRoot.$location))
    {
        $subFolders = scandir($documentRoot  . $location);
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

function tina4_exception_handler ($exception)
{
    \Tina4\Debug::exceptionHandler($exception);
}

function tina4_error_handler ($errorNo="",$errorString="",$errorFile="",$errorLine="")
{
    \Tina4\Debug::errorHandler($errorNo,$errorString,$errorFile,$errorLine);
}

//Initialize the Error handling
//We only want to fiddle with the defaults if we are developing
if (defined("TINA4_DEBUG") && TINA4_DEBUG)
{
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
            if (!file_exists(TINA4_DOCUMENT_ROOT  . $folder)) {
                \Tina4\Utility::recurseCopy(TINA4_PROJECT_ROOT . $folder, TINA4_DOCUMENT_ROOT . $folder);
            }
        }
    }
    copy(TINA4_PROJECT_ROOT . ".htaccess", TINA4_DOCUMENT_ROOT . ".htaccess");
}

//Copy the bin folder if the vendor one has changed
if (TINA4_PROJECT_ROOT !== TINA4_DOCUMENT_ROOT) {
    $tina4Checksum = md5(file_get_contents(TINA4_PROJECT_ROOT . "bin" . DIRECTORY_SEPARATOR . "tina4"));
    $destChecksum = "";
    if (file_exists(TINA4_DOCUMENT_ROOT . "bin" . DIRECTORY_SEPARATOR . "tina4")) {
        $destChecksum = md5(file_get_contents(TINA4_DOCUMENT_ROOT . "bin" . DIRECTORY_SEPARATOR . "tina4"));
    }

    if ($tina4Checksum !== $destChecksum) {
        \Tina4\Utility::recurseCopy(TINA4_PROJECT_ROOT . "bin", TINA4_DOCUMENT_ROOT . "bin");
    }
}

//Add the icon file for making it look pretty
if (!file_exists(TINA4_DOCUMENT_ROOT . "favicon.ico")) {
    copy(TINA4_PROJECT_ROOT . "favicon.ico", TINA4_DOCUMENT_ROOT . "favicon.ico");
}

//Initialize the Cache
global $cache;
//On a rerun need to check if we have already instantiated the cache
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
//Run migrations if needed

//@todo Init Git Here

/**
 * Encloses all shape variables
 * @param mixed ...$elements
 * @return HTMLElement
 */
function _shape (...$elements) {
    return new HTMLElement(":", $elements);
}

/**
 * HTML TAG _doctype
 * @param $elements
 * @return HTMLElement
 */
function _doctype (...$elements) {
    return new HTMLElement(":!DOCTYPE", $elements);
}
/**
 * HTML TAG comment
 * @param $elements
 * @return HTMLElement
 * @tests
 *   assert ("hello") == '<!--hello-->', "Comment test"
 */
function _comment (...$elements) {
    return new HTMLElement(":!--", $elements);
}
/**
 * HTML TAG _a
 * @param $elements
 * @return HTMLElement
 * @tests
 *   assert ("home")."" == "<a>home</a>", "Anchor Test"
 *   assert (["href" => "#"],"home")."" == '<a href="#">home</a>', "Anchor Test Href"
 */
function _a (...$elements) {
    return new HTMLElement(":a", $elements);
}
/**
 * HTML TAG _abbr
 * @param $elements
 * @return HTMLElement
 * @tests
 *   assert ("test")."" === "<abbr>test</abbr>","Abbr tag is not working"
 */
function _abbr (...$elements) {
    return new HTMLElement(":abbr", $elements);
}
/**
 * HTML TAG _acronym
 * @param $elements
 * @return HTMLElement
 */
function _acronym (...$elements) {
    return new HTMLElement(":acronym", $elements);
}
/**
 * HTML TAG _address
 * @param $elements
 * @return HTMLElement
 */
function _address (...$elements) {
    return new HTMLElement(":address", $elements);
}
/**
 * HTML TAG _applet
 * @param $elements
 * @return HTMLElement
 */
function _applet (...$elements) {
    return new HTMLElement(":applet", $elements);
}
/**
 * HTML TAG _area
 * @param $elements
 * @return HTMLElement
 */
function _area (...$elements) {
    return new HTMLElement(":area", $elements);
}
/**
 * HTML TAG _article
 * @param $elements
 * @return HTMLElement
 */
function _article (...$elements) {
    return new HTMLElement(":article", $elements);
}
/**
 * HTML TAG _aside
 * @param $elements
 * @return HTMLElement
 */
function _aside (...$elements) {
    return new HTMLElement(":aside", $elements);
}
/**
 * HTML TAG _audio
 * @param $elements
 * @return HTMLElement
 */
function _audio (...$elements) {
    return new HTMLElement(":audio", $elements);
}
/**
 * HTML TAG _b
 * @param $elements
 * @return HTMLElement
 */
function _b (...$elements) {
    return new HTMLElement(":b", $elements);
}
/**
 * HTML TAG _base
 * @param $elements
 * @return HTMLElement
 */
function _base (...$elements) {
    return new HTMLElement(":base", $elements);
}
/**
 * HTML TAG _basefont
 * @param $elements
 * @return HTMLElement
 */
function _basefont (...$elements) {
    return new HTMLElement(":basefont", $elements);
}
/**
 * HTML TAG _bb
 * @param $elements
 * @return HTMLElement
 */
function _bb (...$elements) {
    return new HTMLElement(":bb", $elements);
}
/**
 * HTML TAG _bdo
 * @param $elements
 * @return HTMLElement
 */
function _bdo (...$elements) {
    return new HTMLElement(":bdo", $elements);
}
/**
 * HTML TAG _big
 * @param $elements
 * @return HTMLElement
 */
function _big (...$elements) {
    return new HTMLElement(":big", $elements);
}
/**
 * HTML TAG _blockquote
 * @param $elements
 * @return HTMLElement
 */
function _blockquote (...$elements) {
    return new HTMLElement(":blockquote", $elements);
}
/**
 * HTML TAG _body
 * @param $elements
 * @return HTMLElement
 */
function _body (...$elements) {
    return new HTMLElement(":body", $elements);
}
/**
 * HTML TAG _br
 * @param $elements
 * @return HTMLElement
 */
function _br (...$elements) {
    return new HTMLElement(":br/", $elements);
}
/**
 * HTML TAG _button
 * @param $elements
 * @return HTMLElement
 */
function _button (...$elements) {
    return new HTMLElement(":button", $elements);
}
/**
 * HTML TAG _canvas
 * @param $elements
 * @return HTMLElement
 */
function _canvas (...$elements) {
    return new HTMLElement(":canvas", $elements);
}
/**
 * HTML TAG _caption
 * @param $elements
 * @return HTMLElement
 */
function _caption (...$elements) {
    return new HTMLElement(":caption", $elements);
}
/**
 * HTML TAG _center
 * @param $elements
 * @return HTMLElement
 */
function _center (...$elements) {
    return new HTMLElement(":center", $elements);
}
/**
 * HTML TAG _cite
 * @param $elements
 * @return HTMLElement
 */
function _cite (...$elements) {
    return new HTMLElement(":cite", $elements);
}
/**
 * HTML TAG _code
 * @param $elements
 * @return HTMLElement
 */
function _code (...$elements) {
    return new HTMLElement(":code", $elements);
}
/**
 * HTML TAG _col
 * @param $elements
 * @return HTMLElement
 */
function _col (...$elements) {
    return new HTMLElement(":col", $elements);
}
/**
 * HTML TAG _colgroup
 * @param $elements
 * @return HTMLElement
 */
function _colgroup (...$elements) {
    return new HTMLElement(":colgroup", $elements);
}
/**
 * HTML TAG _command
 * @param $elements
 * @return HTMLElement
 */
function _command (...$elements) {
    return new HTMLElement(":command", $elements);
}
/**
 * HTML TAG _datagrid
 * @param $elements
 * @return HTMLElement
 */
function _datagrid (...$elements) {
    return new HTMLElement(":datagrid", $elements);
}
/**
 * HTML TAG _datalist
 * @param $elements
 * @return HTMLElement
 */
function _datalist (...$elements) {
    return new HTMLElement(":datalist", $elements);
}
/**
 * HTML TAG _dd
 * @param $elements
 * @return HTMLElement
 */
function _dd (...$elements) {
    return new HTMLElement(":dd", $elements);
}
/**
 * HTML TAG _del
 * @param $elements
 * @return HTMLElement
 */
function _del (...$elements) {
    return new HTMLElement(":del", $elements);
}
/**
 * HTML TAG _details
 * @param $elements
 * @return HTMLElement
 */
function _details (...$elements) {
    return new HTMLElement(":details", $elements);
}
/**
 * HTML TAG _dfn
 * @param $elements
 * @return HTMLElement
 */
function _dfn (...$elements) {
    return new HTMLElement(":dfn", $elements);
}
/**
 * HTML TAG _dialog
 * @param $elements
 * @return HTMLElement
 */
function _dialog (...$elements) {
    return new HTMLElement(":dialog", $elements);
}
/**
 * HTML TAG _dir
 * @param $elements
 * @return HTMLElement
 */
function _dir (...$elements) {
    return new HTMLElement(":dir", $elements);
}
/**
 * HTML TAG _div
 * @param $elements
 * @return HTMLElement
 */
function _div (...$elements) {
    return new HTMLElement(":div", $elements);
}
/**
 * HTML TAG _dl
 * @param $elements
 * @return HTMLElement
 */
function _dl (...$elements) {
    return new HTMLElement(":dl", $elements);
}
/**
 * HTML TAG _dt
 * @param $elements
 * @return HTMLElement
 */
function _dt (...$elements) {
    return new HTMLElement(":dt", $elements);
}
/**
 * HTML TAG _em
 * @param $elements
 * @return HTMLElement
 */
function _em (...$elements) {
    return new HTMLElement(":em", $elements);
}
/**
 * HTML TAG _embed
 * @param $elements
 * @return HTMLElement
 */
function _embed (...$elements) {
    return new HTMLElement(":embed", $elements);
}
/**
 * HTML TAG _eventsource
 * @param $elements
 * @return HTMLElement
 */
function _eventsource (...$elements) {
    return new HTMLElement(":eventsource", $elements);
}
/**
 * HTML TAG _fieldset
 * @param $elements
 * @return HTMLElement
 */
function _fieldset (...$elements) {
    return new HTMLElement(":fieldset", $elements);
}
/**
 * HTML TAG _figcaption
 * @param $elements
 * @return HTMLElement
 */
function _figcaption (...$elements) {
    return new HTMLElement(":figcaption", $elements);
}
/**
 * HTML TAG _figure
 * @param $elements
 * @return HTMLElement
 */
function _figure (...$elements) {
    return new HTMLElement(":figure", $elements);
}
/**
 * HTML TAG _font
 * @param $elements
 * @return HTMLElement
 */
function _font (...$elements) {
    return new HTMLElement(":font", $elements);
}
/**
 * HTML TAG _footer
 * @param $elements
 * @return HTMLElement
 */
function _footer (...$elements) {
    return new HTMLElement(":footer", $elements);
}
/**
 * HTML TAG _form
 * @param $elements
 * @return HTMLElement
 */
function _form (...$elements) {
    return new HTMLElement(":form", $elements);
}
/**
 * HTML TAG _frame
 * @param $elements
 * @return HTMLElement
 */
function _frame (...$elements) {
    return new HTMLElement(":frame", $elements);
}
/**
 * HTML TAG _frameset
 * @param $elements
 * @return HTMLElement
 */
function _frameset (...$elements) {
    return new HTMLElement(":frameset", $elements);
}
/**
 * HTML TAG _h1
 * @param $elements
 * @return HTMLElement
 */
function _h1 (...$elements) {
    return new HTMLElement(":h1", $elements);
}
/**
 * HTML TAG _head
 * @param $elements
 * @return HTMLElement
 */
function _head (...$elements) {
    return new HTMLElement(":head", $elements);
}
/**
 * HTML TAG _header
 * @param $elements
 * @return HTMLElement
 */
function _header (...$elements) {
    return new HTMLElement(":header", $elements);
}
/**
 * HTML TAG _hgroup
 * @param $elements
 * @return HTMLElement
 */
function _hgroup (...$elements) {
    return new HTMLElement(":hgroup", $elements);
}
/**
 * HTML TAG _hr
 * @param $elements
 * @return HTMLElement
 */
function _hr (...$elements) {
    return new HTMLElement(":hr/", $elements);
}
/**
 * HTML TAG _html
 * @param $elements
 * @return HTMLElement
 */
function _html (...$elements) {
    return new HTMLElement(":html", $elements);
}
/**
 * HTML TAG _i
 * @param $elements
 * @return HTMLElement
 */
function _i (...$elements) {
    return new HTMLElement(":i", $elements);
}
/**
 * HTML TAG _iframe
 * @param $elements
 * @return HTMLElement
 */
function _iframe (...$elements) {
    return new HTMLElement(":iframe", $elements);
}
/**
 * HTML TAG _img
 * @param $elements
 * @return HTMLElement
 */
function _img (...$elements) {
    return new HTMLElement(":img/", $elements);
}
/**
 * HTML TAG _input
 * @param $elements
 * @return HTMLElement
 */
function _input (...$elements) {
    return new HTMLElement(":input", $elements);
}
/**
 * HTML TAG _ins
 * @param $elements
 * @return HTMLElement
 */
function _ins (...$elements) {
    return new HTMLElement(":ins", $elements);
}
/**
 * HTML TAG _isindex
 * @param $elements
 * @return HTMLElement
 */
function _isindex (...$elements) {
    return new HTMLElement(":isindex", $elements);
}
/**
 * HTML TAG _kbd
 * @param $elements
 * @return HTMLElement
 */
function _kbd (...$elements) {
    return new HTMLElement(":kbd", $elements);
}
/**
 * HTML TAG _keygen
 * @param $elements
 * @return HTMLElement
 */
function _keygen (...$elements) {
    return new HTMLElement(":keygen", $elements);
}
/**
 * HTML TAG _label
 * @param $elements
 * @return HTMLElement
 */
function _label (...$elements) {
    return new HTMLElement(":label", $elements);
}
/**
 * HTML TAG _legend
 * @param $elements
 * @return HTMLElement
 */
function _legend (...$elements) {
    return new HTMLElement(":legend", $elements);
}
/**
 * HTML TAG _li
 * @param $elements
 * @return HTMLElement
 */
function _li (...$elements) {
    return new HTMLElement(":li", $elements);
}
/**
 * HTML TAG _link
 * @param $elements
 * @return HTMLElement
 */
function _link (...$elements) {
    return new HTMLElement(":link", $elements);
}
/**
 * HTML TAG _map
 * @param $elements
 * @return HTMLElement
 */
function _map (...$elements) {
    return new HTMLElement(":map", $elements);
}
/**
 * HTML TAG _mark
 * @param $elements
 * @return HTMLElement
 */
function _mark (...$elements) {
    return new HTMLElement(":mark", $elements);
}
/**
 * HTML TAG _menu
 * @param $elements
 * @return HTMLElement
 */
function _menu (...$elements) {
    return new HTMLElement(":menu", $elements);
}
/**
 * HTML TAG _meta
 * @param $elements
 * @return HTMLElement
 */
function _meta (...$elements) {
    return new HTMLElement(":meta/", $elements);
}
/**
 * HTML TAG _meter
 * @param $elements
 * @return HTMLElement
 */
function _meter (...$elements) {
    return new HTMLElement(":meter", $elements);
}
/**
 * HTML TAG _nav
 * @param $elements
 * @return HTMLElement
 */
function _nav (...$elements) {
    return new HTMLElement(":nav", $elements);
}
/**
 * HTML TAG _noframes
 * @param $elements
 * @return HTMLElement
 */
function _noframes (...$elements) {
    return new HTMLElement(":noframes", $elements);
}
/**
 * HTML TAG _noscript
 * @param $elements
 * @return HTMLElement
 */
function _noscript (...$elements) {
    return new HTMLElement(":noscript", $elements);
}
/**
 * HTML TAG _object
 * @param $elements
 * @return HTMLElement
 */
function _object (...$elements) {
    return new HTMLElement(":object", $elements);
}
/**
 * HTML TAG _ol
 * @param $elements
 * @return HTMLElement
 */
function _ol (...$elements) {
    return new HTMLElement(":ol", $elements);
}
/**
 * HTML TAG _optgroup
 * @param $elements
 * @return HTMLElement
 */
function _optgroup (...$elements) {
    return new HTMLElement(":optgroup", $elements);
}
/**
 * HTML TAG _option
 * @param $elements
 * @return HTMLElement
 */
function _option (...$elements) {
    return new HTMLElement(":option", $elements);
}
/**
 * HTML TAG _output
 * @param $elements
 * @return HTMLElement
 */
function _output (...$elements) {
    return new HTMLElement(":output", $elements);
}
/**
 * HTML TAG _p
 * @param $elements
 * @return HTMLElement
 */
function _p (...$elements) {
    return new HTMLElement(":p", $elements);
}
/**
 * HTML TAG _param
 * @param $elements
 * @return HTMLElement
 */
function _param (...$elements) {
    return new HTMLElement(":param", $elements);
}
/**
 * HTML TAG _pre
 * @param $elements
 * @return HTMLElement
 */
function _pre (...$elements) {
    return new HTMLElement(":pre", $elements);
}
/**
 * HTML TAG _progress
 * @param $elements
 * @return HTMLElement
 */
function _progress (...$elements) {
    return new HTMLElement(":progress", $elements);
}
/**
 * HTML TAG _q
 * @param $elements
 * @return HTMLElement
 */
function _q (...$elements) {
    return new HTMLElement(":q", $elements);
}
/**
 * HTML TAG _rp
 * @param $elements
 * @return HTMLElement
 */
function _rp (...$elements) {
    return new HTMLElement(":rp", $elements);
}
/**
 * HTML TAG _rt
 * @param $elements
 * @return HTMLElement
 */
function _rt (...$elements) {
    return new HTMLElement(":rt", $elements);
}
/**
 * HTML TAG _ruby
 * @param $elements
 * @return HTMLElement
 */
function _ruby (...$elements) {
    return new HTMLElement(":ruby", $elements);
}
/**
 * HTML TAG _s
 * @param $elements
 * @return HTMLElement
 */
function _s (...$elements) {
    return new HTMLElement(":s", $elements);
}
/**
 * HTML TAG _samp
 * @param $elements
 * @return HTMLElement
 */
function _samp (...$elements) {
    return new HTMLElement(":samp", $elements);
}
/**
 * HTML TAG _script
 * @param $elements
 * @return HTMLElement
 */
function _script (...$elements) {
    return new HTMLElement(":script", $elements);
}
/**
 * HTML TAG _section
 * @param $elements
 * @return HTMLElement
 */
function _section (...$elements) {
    return new HTMLElement(":section", $elements);
}
/**
 * HTML TAG _select
 * @param $elements
 * @return HTMLElement
 */
function _select (...$elements) {
    return new HTMLElement(":select", $elements);
}
/**
 * HTML TAG _small
 * @param $elements
 * @return HTMLElement
 */
function _small (...$elements) {
    return new HTMLElement(":small", $elements);
}
/**
 * HTML TAG _source
 * @param $elements
 * @return HTMLElement
 */
function _source (...$elements) {
    return new HTMLElement(":source", $elements);
}
/**
 * HTML TAG _span
 * @param $elements
 * @return HTMLElement
 */
function _span (...$elements) {
    return new HTMLElement(":span", $elements);
}
/**
 * HTML TAG _strike
 * @param $elements
 * @return HTMLElement
 */
function _strike (...$elements) {
    return new HTMLElement(":strike", $elements);
}
/**
 * HTML TAG _strong
 * @param $elements
 * @return HTMLElement
 */
function _strong (...$elements) {
    return new HTMLElement(":strong", $elements);
}
/**
 * HTML TAG _style
 * @param $elements
 * @return HTMLElement
 */
function _style (...$elements) {
    return new HTMLElement(":style", $elements);
}
/**
 * HTML TAG _sub
 * @param $elements
 * @return HTMLElement
 */
function _sub (...$elements) {
    return new HTMLElement(":sub", $elements);
}
/**
 * HTML TAG _sup
 * @param $elements
 * @return HTMLElement
 */
function _sup (...$elements) {
    return new HTMLElement(":sup", $elements);
}
/**
 * HTML TAG _table
 * @param $elements
 * @return HTMLElement
 */
function _table (...$elements) {
    return new HTMLElement(":table", $elements);
}
/**
 * HTML TAG _tbody
 * @param $elements
 * @return HTMLElement
 */
function _tbody (...$elements) {
    return new HTMLElement(":tbody", $elements);
}
/**
 * HTML TAG _td
 * @param $elements
 * @return HTMLElement
 */
function _td (...$elements) {
    return new HTMLElement(":td", $elements);
}
/**
 * HTML TAG _textarea
 * @param $elements
 * @return HTMLElement
 */
function _textarea (...$elements) {
    return new HTMLElement(":textarea", $elements);
}
/**
 * HTML TAG _tfoot
 * @param $elements
 * @return HTMLElement
 */
function _tfoot (...$elements) {
    return new HTMLElement(":tfoot", $elements);
}
/**
 * HTML TAG _th
 * @param $elements
 * @return HTMLElement
 */
function _th (...$elements) {
    return new HTMLElement(":th", $elements);
}
/**
 * HTML TAG _thead
 * @param $elements
 * @return HTMLElement
 */
function _thead (...$elements) {
    return new HTMLElement(":thead", $elements);
}
/**
 * HTML TAG _time
 * @param $elements
 * @return HTMLElement
 */
function _time (...$elements) {
    return new HTMLElement(":time", $elements);
}
/**
 * HTML TAG _title
 * @param $elements
 * @return HTMLElement
 */
function _title (...$elements) {
    return new HTMLElement(":title", $elements);
}
/**
 * HTML TAG _tr
 * @param $elements
 * @return HTMLElement
 */
function _tr (...$elements) {
    return new HTMLElement(":tr", $elements);
}
/**
 * HTML TAG _track
 * @param $elements
 * @return HTMLElement
 */
function _track (...$elements) {
    return new HTMLElement(":track", $elements);
}
/**
 * HTML TAG _tt
 * @param $elements
 * @return HTMLElement
 */
function _tt (...$elements) {
    return new HTMLElement(":tt", $elements);
}
/**
 * HTML TAG _u
 * @param $elements
 * @return HTMLElement
 */
function _u (...$elements) {
    return new HTMLElement(":u", $elements);
}
/**
 * HTML TAG _ul
 * @param $elements
 * @return HTMLElement
 */
function _ul (...$elements) {
    return new HTMLElement(":ul", $elements);
}
/**
 * HTML TAG _var
 * @param $elements
 * @return HTMLElement
 */
function _var (...$elements) {
    return new HTMLElement(":var", $elements);
}
/**
 * HTML TAG _video
 * @param $elements
 * @return HTMLElement
 */
function _video (...$elements) {
    return new HTMLElement(":video", $elements);
}
/**
 * HTML TAG _wbr
 * @param $elements
 * @return HTMLElement
 */
function _wbr (...$elements) {
    return new HTMLElement(":wbr", $elements);
}
