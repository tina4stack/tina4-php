<?php

namespace Tina4;

use Phpfastcache\CacheManager;
use Phpfastcache\Config\ConfigurationOption;
use ScssPhp\ScssPhp\Compiler;

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Class Tina4Php Main class used to set constants
 * @package Tina4
 */
class Tina4Php extends \Tina4\Data
{
    use Utility;

    /**
     * @var resource PHP object / array
     */
    private $config;

    /**
     * @var false|string The place where Tina4 exists
     */
    private $documentRoot;

    /**
     * @var false|string The place where the website exists
     */
    private $webRoot;

    /**
     * Suppress output
     * @var bool
     */
    private $suppress = false;

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
    public function __construct(\Tina4\Config $config = null)
    {
        parent::__construct();

        //define constants
        if (!defined("TINA4_DEBUG_LEVEL")) {
            define("TINA4_DEBUG_LEVEL", DEBUG_NONE);
        }

        if (!defined("TINA4_DEBUG")) {
            define("TINA4_DEBUG", false);
        } else if (TINA4_DEBUG) {
            \Tina4\Debug::message("TINA4: DEBUG ACTIVE - Turn off in .env", TINA4_DEBUG_LEVEL);
        }

        Debug::message("TINA4: Setting config", TINA4_DEBUG_LEVEL);
        $this->config = $config;

        if (defined("TINA4_SUPPRESS")) {
            Debug::message("Check if Tina4 is being suppressed");
            $this->suppress = TINA4_SUPPRESS;
        }

        $debugBackTrace = debug_backtrace();
        $callerFile = $debugBackTrace[0]["file"]; //calling file /.../.../index.php
        $callerDir = str_replace("index.php", "", $callerFile);

        //root of the website
        if (defined("TINA4_DOCUMENT_ROOT") && empty(TINA4_DOCUMENT_ROOT)) {
            $this->documentRoot = $callerDir;
        } else {
            $this->documentRoot = TINA4_DOCUMENT_ROOT;
        }

        if (strpos($this->documentRoot, ".php") !== false) {
            $this->documentRoot = dirname($this->documentRoot) . "/";
        }

        if (file_exists("Tina4Php.php")) {
            $this->documentRoot = realpath(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
        }

        global $auth;

        if (empty($auth)) {
            if (!empty($config) && $config->getAuthentication() !== null) {
                $auth = $config->getAuthentication();
            } else {
                if (empty($config)) {
                    $config = new \Tina4\Config();
                }
                $auth = new \Tina4\Auth($this->documentRoot);
                $config->setAuthentication($auth);
                $this->config = $config;
            }
        }

        //Check security
        if (!file_exists($this->documentRoot . "secrets")) {
            $auth->generateSecureKeys();
        }

        \Tina4\Debug::message("TINA4: document root " . $this->documentRoot, TINA4_DEBUG_LEVEL);
        if (empty($_SERVER["DOCUMENT_ROOT"])) {
            $_SERVER["DOCUMENT_ROOT"] = $this->documentRoot;
        }

        //root of tina4
        $this->webRoot = realpath(__DIR__);

        \Tina4\Debug::message("TINA4: web path " . $this->webRoot);

        if (!defined("TINA4_TEMPLATE_LOCATIONS_INTERNAL")) {
            if (defined("TINA4_TEMPLATE_LOCATIONS")) {
                define("TINA4_TEMPLATE_LOCATIONS_INTERNAL", array_merge(TINA4_TEMPLATE_LOCATIONS, Module::getTemplateFolders()));
            } else {
                define("TINA4_TEMPLATE_LOCATIONS_INTERNAL", array_merge(["src".DIRECTORY_SEPARATOR."templates", "src".DIRECTORY_SEPARATOR."assets", "src".DIRECTORY_SEPARATOR."templates".DIRECTORY_SEPARATOR."snippets"], Module::getTemplateFolders()));
            }
        }

        if (!defined("TINA4_ROUTE_LOCATIONS_INTERNAL")) {
            if (defined("TINA4_ROUTE_LOCATIONS")) {
                define("TINA4_ROUTE_LOCATIONS_INTERNAL", array_merge(TINA4_ROUTE_LOCATIONS, Module::getRouteFolders()));
            } else {
                define("TINA4_ROUTE_LOCATIONS_INTERNAL", array_merge(["src".DIRECTORY_SEPARATOR."api", "src".DIRECTORY_SEPARATOR."routes"], Module::getRouteFolders()));
            }
        }

        if (!defined("TINA4_INCLUDE_LOCATIONS_INTERNAL")) {
            if (defined("TINA4_INCLUDE_LOCATIONS")) {
                define("TINA4_INCLUDE_LOCATIONS_INTERNAL", array_merge(TINA4_INCLUDE_LOCATIONS, Module::getIncludeFolders()));
            } else {
                define("TINA4_INCLUDE_LOCATIONS_INTERNAL", array_merge(["src".DIRECTORY_SEPARATOR."app", "src".DIRECTORY_SEPARATOR."objects", "src".DIRECTORY_SEPARATOR."services"], Module::getIncludeFolders()));
            }
        }

        if (!defined("TINA4_SCSS_LOCATIONS_INTERNAL")) {
            if (defined("TINA4_SCSS_LOCATIONS")) {
                define("TINA4_SCSS_LOCATIONS_INTERNAL", array_merge(TINA4_SCSS_LOCATIONS, Module::getSCSSFolders()));
            } else {
                define("TINA4_SCSS_LOCATIONS", array_merge(["src".DIRECTORY_SEPARATOR."scss"], Module::getSCSSFolders()));
            }
        }

        if (!defined("TINA4_ALLOW_ORIGINS")) {
            define("TINA4_ALLOW_ORIGINS", ["*"]);
        }

        /**
         * Global array to store the routes that are declared during runtime
         */
        global $arrRoutes;
        $arrRoutes = [];

        //Add the .htaccess file for redirecting things & copy the default src structure
        if (!file_exists($this->documentRoot . DIRECTORY_SEPARATOR.".htaccess") && !file_exists($this->documentRoot . DIRECTORY_SEPARATOR."src")) {
            if (!file_exists($this->documentRoot . DIRECTORY_SEPARATOR."src")) {
                $foldersToCopy = ["assets", "app", "api", "routes", "templates", "objects", "services", "scss"];
                foreach ($foldersToCopy as $id => $folder) {
                    if (!file_exists(realpath($this->documentRoot) . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . "{$folder}") && !file_exists("Tina4Php.php")) {
                        \Tina4\Routing::recurseCopy($this->webRoot . DIRECTORY_SEPARATOR . "{$folder}", $this->documentRoot . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . "{$folder}");
                    }
                }
            }

            copy($this->webRoot . DIRECTORY_SEPARATOR.".htaccess", $this->documentRoot . DIRECTORY_SEPARATOR.".htaccess");
        }

        //Copy the bin folder if the vendor one has changed
        if ($this->webRoot.DIRECTORY_SEPARATOR !== $this->documentRoot) {
            $tina4Checksum = md5(file_get_contents($this->webRoot.DIRECTORY_SEPARATOR."bin".DIRECTORY_SEPARATOR."tina4"));
            $destChecksum = "";
            if (file_exists($this->documentRoot."bin".DIRECTORY_SEPARATOR."tina4"))
            {
                $destChecksum = md5(file_get_contents($this->documentRoot."bin".DIRECTORY_SEPARATOR."tina4"));
            }

            if ($tina4Checksum !== $destChecksum)
            {
                \Tina4\Routing::recurseCopy($this->webRoot.DIRECTORY_SEPARATOR."bin", $this->documentRoot ."bin");
            }
        }

        //Add the icon file for making it look pretty
        if (!file_exists($this->documentRoot . "/favicon.ico")) {
            copy($this->webRoot . "/favicon.ico", $this->documentRoot . "/favicon.ico");
        }

        /**
         * Built in routes
         */

        $tina4PHP = $this;

        //Migration routes
        \Tina4\Route::get("/migrate|/migrations|/migration", function (\Tina4\Response $response, \Tina4\Request $request) use ($tina4PHP) {
            $result = (new \Tina4\Migration())->doMigration();
            $migrationFolders = (new \Tina4\Module())->getMigrationFolders();
            foreach ($migrationFolders as $id => $migrationFolder) {
                $result .= (new \Tina4\Migration($migrationFolder))->doMigration();
            }
            return $response ($result, HTTP_OK, TEXT_HTML);
        });

        \Tina4\Route::get("/cache/clear", function (\Tina4\Response $response, \Tina4\Request $request) use ($tina4PHP) {
            $tina4PHP->deleteFiles($tina4PHP->documentRoot."cache");
            return $response("OK");
        });

        //Some routes only are available with debugging
        if (defined("TINA4_DEBUG") && TINA4_DEBUG) {

            /**
             * @secure
             */
            \Tina4\Route::get("/code", function (\Tina4\Response $response, \Tina4\Request $request) use ($tina4PHP) {
                return $response (\Tina4\renderTemplate("code.twig", $request->asArray()), HTTP_OK, TEXT_HTML);
            });

            /**
             * @secure
             */
            \Tina4\Route::post("/code/files/tree", function (\Tina4\Response $response, \Tina4\Request $request) use ($tina4PHP) {
                //Read dir
                $html = $this->iterateDirectory($tina4PHP->documentRoot);

                return $response ($html, HTTP_OK, TEXT_HTML);
            });

            /**
             * @secure
             */
            \Tina4\Route::post("/code/files/{action}", function (\Tina4\Response $response, \Tina4\Request $request) use ($tina4PHP) {
                $action = $request->inlineParams[0];
                if ($action == "load") {
                    return $response (file_get_contents($tina4PHP->documentRoot . "/" . $request->params["fileName"]), HTTP_OK, TEXT_HTML);
                } else
                    if ($action == "save") {
                        $this->gitInit(true, $request->params["commitMessage"], true);
                        file_put_contents($_SERVER["DOCUMENT_ROOT"] . "/" . $request->params["fileName"], $request->params["fileContent"]);
                        return $response ("Ok!", HTTP_OK, TEXT_HTML);
                    }
                return $response("");
            });


            \Tina4\Route::get("/migrate/create|/migrations/create|/migration/create", function (\Tina4\Response $response, \Tina4\Request $request) use ($tina4PHP) {
                //  $html = '<html><body><style> body { font-family: Arial;  border: 1px solid black; padding:20px } label{ display:block; margin-top: 5px; } input, textarea { width: 100%; font-size: 14px; } button {font-size: 14px; border: 1px solid black; border-radius: 10px; background: #61affe; color: #fff; padding:10px; cursor: hand }</style><form class="form" method="post"><label>Migration Description</label><input type="text" name="description"><label>SQL Statement</label><textarea rows="20" name="sql"></textarea><br><button>Create Migration</button></form></body></html>';

                $html = _html(
                    _title("Tina4 Migrations"),
                    _head('<script
  src="https://code.jquery.com/jquery-3.5.1.min.js"
  integrity="sha256-9/aliU8dGd2tb6OSsuzixeV4y/faTqgFtohetphbbj0="
  crossorigin="anonymous"></script>
                           <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.12/ace.js" integrity="sha512-GZ1RIgZaSc8rnco/8CXfRdCpDxRCphenIiZ2ztLy3XQfCbQUSCuk8IudvNHxkRA3oUg6q0qejgN/qqyG1duv5Q==" crossorigin="anonymous"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.12/mode-twig.min.js" integrity="sha512-ZtTfixyUItifC8wzQ1PwinttMP5W02H6zYeC/cAU+YPCA88vcrIUMI+fCk27yWN5k92zm32PWjpKYPYR/npZzg==" crossorigin="anonymous"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.12/theme-monokai.min.js" integrity="sha512-S4i/WUGRs22+8rjUVu4kBjfNuBNp8GVsgcK2lbaFdws4q6TF3Nd00LxqnHhuxS9iVDfNcUh0h6OxFUMP5DBD+g==" crossorigin="anonymous"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.12/theme-sqlserver.min.js" integrity="sha512-TkNvDZzCp+GGiwfXNAOxt6JDzuELz8qquDcZrUzPXuKRvOcUA6kSZu2/uPhKbbjqeJIjoevYn10yrt8TS+qUXQ==" crossorigin="anonymous"></script>
                    '),
                    _body(_style(
                        "body { font-family: Arial; border: 1px solid black; padding: 20px; }
                        label{ display:block; margin-top: 5px; } 
                        input, textarea { width: 100%; font-size: 14px; } 
                        button {font-size: 14px; border: 1px solid black; border-radius: 10px; background: #61affe; color: #fff; padding:10px; cursor: hand }
                        "),
                        _form(["class" => "form", "method" => "post"],
                            _label("Migration Description"),
                            _input(["type" => "text", "name" => "description"]),
                            _label("SQL Statement"),
                            _textarea(["id" => "sql",
                                "name" => "sql", "style" => "display:none"]),
                            _div(["id" => "sqlContent", "style" => "height: 500px"]),
                            _script(' window.editorACE = ace.edit("sqlContent");
                                    window.editorACE.getSession().setUseWorker(false);
                                    window.editorACE.setTheme("ace/theme/sqlserver");
                                    window.editorACE.getSession().setMode("ace/mode/twig");
                    
                                    window.editorACE.getSession().on(\'change\', function() {
                                        $(\'#sql\').val(window.editorACE.getSession().getValue());
                    
                                    });'),
                            _br(),
                            _input(["type" => "hidden", "name" => "formToken", "value" => (new Auth)->getToken()]),
                            _button("Create Migration")
                        )
                    )
                );
                return $response ($html, HTTP_OK, TEXT_HTML);
            });

            \Tina4\Route::post("/migrate/create|/migrations/create|/migration/create", function (\Tina4\Response $response, \Tina4\Request $request) use ($tina4PHP) {
                $result = (new \Tina4\Migration())->createMigration($_REQUEST["description"], $_REQUEST["sql"]);
                return $response ($result, HTTP_OK, TEXT_HTML);
            });

            /**
             * End of routes
             */
        }

        if (defined("TINA4_GIT_ENABLED") && TINA4_GIT_ENABLED) {
            $this->gitInit(TINA4_GIT_ENABLED, (defined("TINA4_GIT_MESSAGE")) ? TINA4_GIT_MESSAGE : "");
        }

        global $cache;
        //On a rerun need to check if we have already instantiated the cache
        if (empty($cache)) {
            //Setup caching options
            $TINA4_CACHE_CONFIG =
                new ConfigurationOption([
                    "path" => $this->documentRoot . "cache"
                ]);

            CacheManager::setDefaultConfig($TINA4_CACHE_CONFIG);

            $cache = CacheManager::getInstance("files");
        }

        global $twig;

        //Twig initialization
        if (empty($twig)) {
            $twigPaths = TINA4_TEMPLATE_LOCATIONS_INTERNAL;

            if (TINA4_DEBUG) {
                \Tina4\Debug::message("TINA4: Twig Paths - " . str_replace("\n", "", print_r($twigPaths, 1)), TINA4_DEBUG_LEVEL);
            }

            foreach ($twigPaths as $tid => $twigPath) {

                if (!is_array($twigPath)) {
                    if (!file_exists(str_replace(DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, $this->documentRoot . DIRECTORY_SEPARATOR . $twigPath))) {
                        if (!file_exists($twigPath)) {
                            unset($twigPaths[$tid]);
                        }
                    }
                }
            }

            $twigLoader = new \Twig\Loader\FilesystemLoader();


            foreach ($twigPaths as $twigPath) {
                if (is_array($twigPath)) {
                    if (isset($twigPath["nameSpace"])) {
                        $twigLoader->addPath($twigPath["path"], $twigPath["nameSpace"]);
                        $twigLoader->addPath($twigPath["path"], "__main__");
                    }
                } else
                    if (file_exists($twigPath)) {
                        $twigLoader->addPath($twigPath, '__main__');
                    } else {
                        $twigLoader->addPath(str_replace(DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, $this->documentRoot . DIRECTORY_SEPARATOR . $twigPath), '__main__');
                    }
            }

            if (TINA4_DEBUG)
            {
                $twig = new \Twig\Environment($twigLoader, ["debug" => TINA4_DEBUG,"cache" => false]);
                $twig->addExtension(new \Twig\Extension\DebugExtension());
                $twig->addExtension(new \Twig\Extensions\I18nExtension());
            } else {
                $twig = new \Twig\Environment($twigLoader, ["cache" => "./cache"]);
                $twig->addExtension(new \Twig\Extensions\I18nExtension());
            }



            $twig->addGlobal('Tina4', new \Tina4\Caller());

            $subFolder = $this->getSubFolder();

            if (isset($_SERVER["HTTP_HOST"])) {
                $twig->addGlobal('url', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]");
            }

            $twig->addGlobal('baseUrl', $subFolder);
            $twig->addGlobal('baseURL', $subFolder);
            $twig->addGlobal('uniqId', uniqid('', true));
            $twig->addGlobal('formToken', $auth->getToken());

            if (isset($_COOKIE) && !empty($_COOKIE)) {
                $twig->addGlobal('cookie', $_COOKIE);
            }

            if (isset($_SESSION) && !empty($_SESSION)) {
                $twig->addGlobal('session', $_SESSION);
            }

            if (isset($_REQUEST) && !empty($_REQUEST)) {
                $twig->addGlobal('request', $_REQUEST);
            }

            if (isset($_SERVER) && !empty($_SERVER)) {
                $twig->addGlobal('server', $_SERVER);
            }

            //Check the configs for each module
            $configs = (new Module())->getModuleConfigs();
            foreach ($configs as $moduleId => $configMethod) {
                if (empty($config)) {
                    $config = new \Tina4\Config();
                }
                $configMethod ($config);
            }

            $this->addTwigMethods($config, $twig);

            //Add form Token
            $filter = new \Twig\TwigFilter("formToken", function ($payload) use ($auth) {
                if (!empty($_SERVER) && isset($_SERVER["REMOTE_ADDR"])) {
                    return _input(["type" => "hidden", "name" => "formToken", "value" => $auth->getToken(["formName" => $payload])]) . "";
                } else {
                    return "";
                }
            });
            $twig->addFilter($filter);

            $filter = new \Twig\TwigFilter("dateValue", function ($dateString) {
                global $DBA;
                if (!empty($DBA)) {
                    if (substr($dateString, -1, 1) == "Z") {
                        return substr($DBA->formatDate($dateString, $DBA->dateFormat, "Y-m-d"), 0, -1);
                    } else {
                        return $DBA->formatDate($dateString, $DBA->dateFormat, "Y-m-d");
                    }
                } else {
                    return $dateString;
                }
            });

            $twig->addFilter($filter);
        }

        //Test for SCSS and existing default.css, only compile if it does not exist
        if (TINA4_DEBUG || !file_exists($this->documentRoot."src".DIRECTORY_SEPARATOR."assets".DIRECTORY_SEPARATOR."css".DIRECTORY_SEPARATOR."default.css"))
        {
            $scssContent = "";
            foreach (TINA4_SCSS_LOCATIONS as $lId => $scssLocation) {
                $scssFiles = $this->getFiles($scssLocation, ".scss");
                foreach ($scssFiles as $fId => $file)
                {
                    $scssContent .= file_get_contents($file);
                }
            }
            $scss = new \ScssPhp\ScssPhp\Compiler();
            $scssDefault = $scss->compile($scssContent);
            if (file_exists($this->documentRoot."src".DIRECTORY_SEPARATOR."assets")) {
                if (!file_exists($this->documentRoot . "src" . DIRECTORY_SEPARATOR . "assets" . DIRECTORY_SEPARATOR . "css"))
                {
                    if (!mkdir($concurrentDirectory = $this->documentRoot . "src" . DIRECTORY_SEPARATOR . "assets" . DIRECTORY_SEPARATOR . "css", 0777, true) && !is_dir($concurrentDirectory)) {
                        throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
                    }
                }
                file_put_contents($this->documentRoot . "src" . DIRECTORY_SEPARATOR . "assets" . DIRECTORY_SEPARATOR . "css" . DIRECTORY_SEPARATOR . "default.css", $scssDefault);
            }
        }

        Debug::message("End of Tina4PHP Initialization");
    }

    /**
     * Iterate the directory
     * @param $path
     * @param string $relativePath
     * @param string $event On click
     * @return string
     */
    public static function iterateDirectory($path, $relativePath = "", $event="onclick=\"window.alert('implement event on iterateDirectory');\""): string
    {
        if (empty($relativePath)) $relativePath = $path;
        $files = scandir($path);
        asort($files);

        $dirItems = [];
        $fileItems = [];

        foreach ($files as $id => $fileName) {
            if ($fileName[0] == "." || $fileName == "cache" || $fileName == "vendor") continue;
            if (is_dir($path . "/" . $fileName) && $fileName != "." && $fileName != "..") {
                $html = '<li data-jstree=\'{"icon":"//img.icons8.com/metro/26/000000/folder-invoices.png"}\'>' . $fileName;
                $html .= self::iterateDirectory($path . "/" . $fileName, $relativePath, $event);
                $html .= "</li>";
                $dirItems[] = $html;

            } else {
                $fileItems[] = '<li '.$event.' file-data="'.str_replace("./", "/", $path . "/" . $fileName).'" data-jstree=\'{"icon":"//img.icons8.com/metro/26/000000/file.png"}\'>' . $fileName . '</li>';
            }

        }

        $html = "<ul>";
        $html .= join("", $dirItems);
        $html .= join("", $fileItems);
        $html .= "</ul>";
        return $html;
    }

    /**
     * Runs git on the repository
     * @param $gitEnabled
     * @param $gitMessage
     * @param $push
     */
    public function gitInit($gitEnabled, $gitMessage, $push = false)
    {
        global $GIT;
        if ($gitEnabled) {
            try {
                \Coyl\Git\Git::setBin("git");
                $GIT = \Coyl\Git\Git::open($this->documentRoot);

                $message = "";
                if (!empty($gitMessage)) {
                    $message = " " . $gitMessage;
                }

                try {
                    if (strpos($GIT->status(), "nothing to commit") === false) {
                        $GIT->add("*");
                        $GIT->commit("Tina4: Committed changes at " . date("Y-m-d H:i:s") . $message);
                        if ($push) {
                            $GIT->push();
                        }
                    }
                } catch (\Exception $e) {

                }

            } catch (\Exception $e) {
                echo $e->getMessage();
            }
        }
    }



    /**
     * Converts URL requests to string and determines whether or not it contains an alias
     * @return string Route URL
     * @throws \ReflectionException
     */
    public function __toString(): string
    {
        //No processing, we simply want the include to work
        if ($this->suppress) {
            Debug::message("Tina4 in suppressed mode");
            //Need to include the include locations here
            if (defined("TINA4_ROUTE_LOCATIONS")) {
                //include routes in routes folder
                $routing = new \Tina4\Routing($this->documentRoot, $this->getSubFolder(), "/", "NONE", $this->config, true);
            }
            return "";
        }
        $string = "";

        if (isset($_SERVER["REQUEST_URI"]) && isset($_SERVER["REQUEST_METHOD"])) {

            //Check if the REQUEST_URI contains an alias
            if (isset($_SERVER["CONTEXT_PREFIX"]) && $_SERVER["CONTEXT_PREFIX"] != "") {

                //Delete the first instance of the alias in the REQUEST_URI
                $newRequestURI = stringReplaceFirst($_SERVER["CONTEXT_PREFIX"], "", $_SERVER["REQUEST_URI"]);

                $string .= new \Tina4\Routing($this->documentRoot, $this->getSubFolder(), $newRequestURI, $_SERVER["REQUEST_METHOD"], $this->config);
            } else {
                $string .= new \Tina4\Routing($this->documentRoot, $this->getSubFolder(), $_SERVER["REQUEST_URI"], $_SERVER["REQUEST_METHOD"], $this->config);
            }

        } else {

            $string .= new \Tina4\Routing($this->documentRoot, $this->getSubFolder(), "/", "GET", $this->config);
        }

        return $string;
    }

    /**
     * @param Config $config
     * @param \Twig\Environment $twig
     * @return \Twig\TwigFilter
     */
    public function addTwigMethods(Config $config, \Twig\Environment $twig)
    {
        if (!empty($config) && !empty($config->getTwigGlobals())) {
            foreach ($config->getTwigGlobals() as $name => $method) {
                $twig->addGlobal($name, $method);
            }
        }

        if (!empty($config) && !empty($config->getTwigFilters())) {
            foreach ($config->getTwigFilters() as $name => $method) {
                $filter = new \Twig\TwigFilter($name, $method);
                $twig->addFilter($filter);
            }
        }

        if (!empty($config) && !empty($config->getTwigFunctions())) {
            foreach ($config->getTwigFunctions() as $name => $method) {
                $function = new \Twig\TwigFunction($name, $method);
                $twig->addFunction($function);
            }
        }

        return true;
    }
}
