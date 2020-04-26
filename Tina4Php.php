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
     * Runs git on the repository
     * @param $gitEnabled
     * @param $gitMessage
     * @param $push
     */
    function gitInit($gitEnabled, $gitMessage, $push=false) {
        global $GIT;
        if ($gitEnabled) {
            try {
                \Coyl\Git\Git::setBin("git");
                $GIT = \Coyl\Git\Git::open($this->documentRoot);

                $message = "";
                if (!empty($gitMessage)) {
                    $message = " ".$gitMessage;
                }

                try {
                    if (strpos( $GIT->status(), "nothing to commit") === false) {
                        $GIT->add("*");
                        $GIT->commit("Tina4: Committed changes at " . date("Y-m-d H:i:s") . $message);
                        if ($push) {
                            $GIT->push();
                        }
                    }
                } catch (Exception $e) {

                }

            } catch (Exception $e) {
                echo $e->getMessage();
            }
        }
    }

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
        global $DBA;
        if (!empty($DBA)) {
            $this->DBA = $DBA;
        }

        //define constants
        if (!defined("TINA4_DEBUG_LEVEL")) {
            if(!defined("DEBUG_NONE")) define("DEBUG_NONE", 9004);
            define ("TINA4_DEBUG_LEVEL", DEBUG_NONE);
        }

        if (!defined("TINA4_DEBUG")) {
            define("TINA4_DEBUG", false);
        } else {
            if (TINA4_DEBUG) {
                \Tina4\DebugLog::message("
  ___________   _____   __ __     ____  __________  __  ________
 /_  __/  _/ | / /   | / // /    / __ \/ ____/ __ )/ / / / ____/
  / /  / //  |/ / /| |/ // /_   / / / / __/ / __  / / / / / __  
 / / _/ // /|  / ___ /__  __/  / /_/ / /___/ /_/ / /_/ / /_/ /  
/_/ /___/_/ |_/_/  |_| /_/    /_____/_____/_____/\____/\____/   
============================================================                                                                
", TINA4_DEBUG_LEVEL);
            }
        }

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
        } else {
            $this->documentRoot = TINA4_DOCUMENT_ROOT;
        }

        if (file_exists("Tina4Php.php")) {
            $this->documentRoot = realpath(dirname(__FILE__));
        }

        \Tina4\DebugLog::message("TINA4: document root " . $this->documentRoot, TINA4_DEBUG_LEVEL);

        //root of tina4
        $this->webRoot = realpath(dirname(__FILE__));

        \Tina4\DebugLog::message("TINA4: web path " . $this->webRoot);

        //check for composer defines
        if (file_exists($this->webRoot . "/vendor/autoload.php")) {
            require_once $this->webRoot . "/vendor/autoload.php";
        }

        if (file_exists($this->documentRoot . "/vendor/autoload.php")) {
            require_once $this->documentRoot . "/vendor/autoload.php";
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

        if (!file_exists($this->documentRoot ."/assets/index.twig") && file_exists($this->documentRoot ."/assets/documentation.twig")) {
            file_put_contents("assets/index.twig", file_get_contents("assets/documentation.twig"));
        }

        //Add the .htaccess file for redirecting things
        if (!file_exists($this->documentRoot . "/.htaccess") && !file_exists("engine.php")) {
            copy($this->webRoot . "/.htaccess", $this->documentRoot . "/.htaccess");
        }

        /**
         * Built in routes
         */

        $tina4PHP = $this;

        \Tina4\Route::post("/auth/validate", function(\Tina4\Response $response, \Tina4\Request $request)  use ($tina4PHP)  {
            $redirect = (new Auth($tina4PHP->documentRoot))->validateAuth ($request->params);
            \Tina4\redirect($redirect, HTTP_MOVED_PERMANENTLY);
            return $response ("None", HTTP_OK, TEXT_HTML);
        });

        \Tina4\Route::get("/auth/login", function(\Tina4\Response $response, \Tina4\Request $request) use ($tina4PHP) {
            $tina4Auth = (new Tina4Auth())->load ('id = 1');
            if (empty($tina4Auth->username) && !(new Auth($tina4PHP->documentRoot))->tokenExists()) {
                \Tina4\redirect("/auth/wizard");
                exit;
            }
            $params = array_merge(["Action" => "Login"], $request->asArray());
            return $response (\Tina4\renderTemplate("auth/generic.twig", $params), HTTP_OK, TEXT_HTML);
        });

        \Tina4\Route::get("/auth/wizard", function(\Tina4\Response $response, \Tina4\Request $request)  use ($tina4PHP)  {
            $tina4Auth = (new Tina4Auth())->load ('id = 1');
            if (!empty($tina4Auth->username) && !(new Auth($tina4PHP->documentRoot))->tokenExists()) {
                \Tina4\redirect("/auth/login");
                exit;
            }
            $tina4Auth = (new Tina4Auth())->load ('id = 1');

            $params = array_merge(["Action" => "Wizard", "settings" => $tina4Auth->getTableData()], $request->asArray());
            return $response (\Tina4\renderTemplate("auth/generic.twig", $params), HTTP_OK, TEXT_HTML);
        });

        \Tina4\Route::post("/auth/wizard", function(\Tina4\Response $response, \Tina4\Request $request)  use ($tina4PHP)  {
            //Establish the auth module
            $redirectPath = (new Auth($tina4PHP->documentRoot))->setupAuth($request);

            \Tina4\redirect($redirectPath);
            return $response ("", HTTP_OK, TEXT_HTML);
        });

        \Tina4\Route::get("/auth/register", function(\Tina4\Response $response, \Tina4\Request $request) {
            return $response ("", HTTP_OK, TEXT_HTML);
        });



        //code routes


        \Tina4\Route::get("/delete-index", function(\Tina4\Response $response) {
            unlink("assets/index.twig");
            \Tina4\redirect("/");
        });

        /**
         * @secure
         */
        \Tina4\Route::get("/code", function(\Tina4\Response $response, \Tina4\Request $request)  use ($tina4PHP)  {
            return $response (\Tina4\renderTemplate("code.twig", $request->asArray()), HTTP_OK, TEXT_HTML);
        });

        /**
         * @secure
         */
        \Tina4\Route::post("/code/files/tree", function(\Tina4\Response $response, \Tina4\Request $request)  use ($tina4PHP)  {
            //Read dir
            $html = $this->iterateDirectory($tina4PHP->documentRoot);

            return $response ($html, HTTP_OK, TEXT_HTML);
        });

        /**
         * @secure
         */
        \Tina4\Route::post("/code/files/{action}", function(\Tina4\Response $response, \Tina4\Request $request) use ($tina4PHP) {
            $action = $request->inlineParams[0];
            if ($action == "load") {
                return $response (file_get_contents($tina4PHP->documentRoot."/".$request->params["fileName"]), HTTP_OK, TEXT_HTML);
            } else
                if ($action == "save") {
                    $this->gitInit(true, $request->params["commitMessage"], true);
                    file_put_contents($_SERVER["DOCUMENT_ROOT"]."/".$request->params["fileName"], $request->params["fileContent"]);
                    return $response ("Ok!", HTTP_OK, TEXT_HTML);
                }
        }) ;

        /**
         * End of routes
         */

        if (defined ("TINA4_GIT_ENABLED") && TINA4_GIT_ENABLED) {
            $this->gitInit(TINA4_GIT_ENABLED, (defined ("TINA4_GIT_MESSAGE")) ? TINA4_GIT_MESSAGE : "" );
        }

        global $cache;

        CacheManager::setDefaultConfig($TINA4_CACHE_CONFIG);

        $cache = CacheManager::getInstance("files");

        global $twig;

        $twigPaths = TINA4_TEMPLATE_LOCATIONS;

        \Tina4\DebugLog::message("TINA4: Twig Paths\n" . print_r($twigPaths, 1));

        if (TINA4_DEBUG) {
            \Tina4\DebugLog::message("TINA4: Twig Paths\n" . print_r($twigPaths, 1), TINA4_DEBUG_LEVEL);
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

    function iterateDirectory($path, $relativePath="")
    {
        if (empty($relativePath)) $relativePath = $path;
        $files = scandir($path);
        asort ($files);

        $dirItems = [];
        $fileItems = [];

        foreach ($files as $id => $fileName) {
            if ($fileName[0] == "." || $fileName == "cache" || $fileName == "vendor") continue;
            if (is_dir($path."/".$fileName) && $fileName != "." && $fileName != "..")
            {
                $html = '<li data-jstree=\'{"icon":"//img.icons8.com/metro/26/000000/folder-invoices.png"}\'>'.$fileName;
                $html .= $this->iterateDirectory($path."/".$fileName, $relativePath);
                $html .= "</li>";
                $dirItems[] = $html;

            } else {
                $fileItems[] = '<li ondblclick="loadFile(\''.str_replace($relativePath."/", "", $path."/".$fileName).'\', editor)" data-jstree=\'{"icon":"//img.icons8.com/metro/26/000000/file.png"}\'>'.$fileName.'</li>';
            }

        }

        $html = "<ul>";
        $html .= join ("", $dirItems);
        $html .= join ("", $fileItems);
        $html .= "</ul>";
        return $html;
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
 * Render a twig file or string
 * @param $fileNameString
 * @param array $data
 * @return string
 * @throws \Twig\Error\LoaderError
 */
function renderTemplate($fileNameString, $data = [])
{
    $fileName = str_replace($_SERVER["DOCUMENT_ROOT"].DIRECTORY_SEPARATOR, "", $fileNameString);
    try {
        global $twig;
        if ($twig->getLoader()->exists($fileNameString)) {
            return $twig->render($fileName, $data);
        }
        else
            if ($twig->getLoader()->exists(basename($fileNameString))) {
                return $twig->render(basename($fileName), $data);
            }
              else
            if (is_file($fileNameString)) {
                $renderFile = basename($fileNameString);
                $twigLoader = new \Twig\Loader\FilesystemLoader();
                $newPath = dirname($fileName).DIRECTORY_SEPARATOR;
                $twigLoader->addPath($_SERVER["DOCUMENT_ROOT"].DIRECTORY_SEPARATOR. $newPath );
                $twig->setLoader($twigLoader);
                $fileName = basename($renderFile);
                return $twig->render($renderFile, $data);
            }
              else {
                    $twig->setLoader(new \Twig\Loader\ArrayLoader(["template" => $fileNameString]));
                    return $twig->render("template", $data);
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

    $fileName = "{$root}".DIRECTORY_SEPARATOR . str_replace("_", DIRECTORY_SEPARATOR, $class) . ".php";

    if (file_exists($fileName)) {
        require_once $fileName;
    } else {
        if (defined("TINA4_INCLUDE_LOCATIONS") && is_array(TINA4_INCLUDE_LOCATIONS)) {
            foreach (TINA4_INCLUDE_LOCATIONS as $lid => $location) {
                if (file_exists($_SERVER["DOCUMENT_ROOT"] . DIRECTORY_SEPARATOR."{$location}".DIRECTORY_SEPARATOR."{$class}.php")) {
                    require_once $_SERVER["DOCUMENT_ROOT"] . DIRECTORY_SEPARATOR."{$location}".DIRECTORY_SEPARATOR."{$class}.php";
                    break;
                }
            }
        }
    }

}

spl_autoload_register('Tina4\tina4_autoloader');