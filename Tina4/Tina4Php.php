<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

use ScssPhp\ScssPhp\Compiler;
use Twig\Error\LoaderError;

/**
 * The main webserver initializer
 * @package Tina4
 */
class Tina4Php extends Data
{
    use Utility;

    public ?Config $config;

    public function __construct(?Config $config = null)
    {
        parent::__construct();
        if (!isset($config)) {
            $config = new Config();
        }

        //Get all the include folders
        if (!defined("TINA4_TEMPLATE_LOCATIONS_INTERNAL")) {
            if (defined("TINA4_TEMPLATE_LOCATIONS")) {
                define("TINA4_TEMPLATE_LOCATIONS_INTERNAL", array_merge(TINA4_TEMPLATE_LOCATIONS, Module::getTemplateFolders()));
            } else {
                define("TINA4_TEMPLATE_LOCATIONS_INTERNAL", array_merge(["cache", "src" . DIRECTORY_SEPARATOR . "templates", "src" . DIRECTORY_SEPARATOR . "public", "src" . DIRECTORY_SEPARATOR . "assets", "src" . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR . "snippets"], Module::getTemplateFolders()));
            }
        }

        if (!defined("TINA4_ROUTE_LOCATIONS_INTERNAL")) {
            if (defined("TINA4_ROUTE_LOCATIONS")) {
                define("TINA4_ROUTE_LOCATIONS_INTERNAL", array_merge(TINA4_ROUTE_LOCATIONS, Module::getRouteFolders()));
            } else {
                define("TINA4_ROUTE_LOCATIONS_INTERNAL", array_merge(["src" . DIRECTORY_SEPARATOR . "api", "src" . DIRECTORY_SEPARATOR . "routes"], Module::getRouteFolders()));
            }
        }

        if (!defined("TINA4_INCLUDE_LOCATIONS_INTERNAL")) {
            if (defined("TINA4_INCLUDE_LOCATIONS")) {
                define("TINA4_INCLUDE_LOCATIONS_INTERNAL", array_merge(TINA4_INCLUDE_LOCATIONS, Module::getIncludeFolders()));
            } else {
                define("TINA4_INCLUDE_LOCATIONS_INTERNAL", array_merge(["src" . DIRECTORY_SEPARATOR . "app", "src" . DIRECTORY_SEPARATOR . "objects", "src" . DIRECTORY_SEPARATOR . "orm", "src" . DIRECTORY_SEPARATOR . "services"], Module::getIncludeFolders()));
            }
        }

        if (!defined("TINA4_SCSS_LOCATIONS_INTERNAL")) {
            if (defined("TINA4_SCSS_LOCATIONS")) {
                define("TINA4_SCSS_LOCATIONS_INTERNAL", array_merge(TINA4_SCSS_LOCATIONS, Module::getSCSSFolders()));
            } else {
                define("TINA4_SCSS_LOCATIONS", array_merge(["src" . DIRECTORY_SEPARATOR . "scss"], Module::getSCSSFolders()));
            }
        }

        if (!defined("TINA4_SCSS_SPLIT_CSS_INTERNAL")) {
            if (defined("TINA4_SCSS_SPLIT_CSS")) {
                define("TINA4_SCSS_SPLIT_CSS_INTERNAL", false);
            } else {
                define("TINA4_SCSS_SPLIT_CSS", false);
            }
        }

        foreach (TINA4_ROUTE_LOCATIONS_INTERNAL as $includeId => $includeLocation) {
            if (!file_exists($includeLocation)) { //Modules have absolute paths
                $includeLocation = TINA4_DOCUMENT_ROOT . $includeLocation;
            }
            if (file_exists($includeLocation)) {
                self::includeDirectory($includeLocation);
            }
        }

        foreach (TINA4_INCLUDE_LOCATIONS_INTERNAL as $includeId => $includeLocation) {
            if (!file_exists($includeLocation)) { //Modules have absolute paths
                $includeLocation = TINA4_DOCUMENT_ROOT . $includeLocation;
            }
            if (file_exists($includeLocation)) {
                self::includeDirectory($includeLocation);
            }
        }

        //Check the configs for each module
        $configs = (new Module())::getModuleConfigs();
        foreach ($configs as $moduleId => $configMethod) {
            if ($config === null) {
                $config = new Config();
            }
            $configMethod($config);
        }

        $this->config = $config;

        //Add built in Tina4 functions
        $config->addTwigFunction("dateCompare", function ($dateA, $operator, $dateB = "now") {
            $dateA = str_replace("/", ".", $dateA);

            $dateA = strtotime($dateA);

            if ($dateB === "now") {
                $dateB = strtotime("today");
            } else {
                $dateB = str_replace("/", ".", $dateA);
                $dateB = strtotime($dateB);
            }

            return match ($operator) {
                "==" => $dateA == $dateB,
                "!=" => $dateA != $dateB,
                ">" => $dateA > $dateB,
                "<" => $dateA < $dateB,
                ">=" => $dateA >= $dateB,
                "<=" => $dateA <= $dateB,
                default => False,
            };

        });


        if (defined("TINA4_SUPPRESS") && TINA4_SUPPRESS) {
            $this->config->callInitFunction();
            try {
                TwigUtility::initTwig($config);
            } catch (LoaderError $e) {
                Debug::message("Could not initialize twig in Tina4PHP Constructor", TINA4_LOG_ERROR);
            }
        } else {
            $this->initRoutes($this);

            try {
                $this->initCSS();
            } catch (\Exception $e) {
                Debug::message("Could not create default.css twig in Tina4PHP Constructor", TINA4_LOG_ERROR);
            }
        }
    }

    /**
     * Initializes the built in routes
     * @param $tina4Php
     */
    public function initRoutes($tina4Php): void
    {
        //Migration routes
        Route::get("/migrate|/migrations|/migration", function (Response $response) {
            $result = (new Migration())->doMigration();
            $migrationFolders = (new Module())::getMigrationFolders();
            foreach ($migrationFolders as $id => $migrationFolder) {
                $result .= (new Migration($migrationFolder))->doMigration();
            }
            return $response($result, HTTP_OK, TEXT_HTML);
        });

        Route::get("/cache/clear", function (Response $response) use ($tina4Php) {
            $tina4Php->deleteFiles($tina4Php->documentRoot . "cache");
            return $response("OK");
        });

        //run the deploy in a thread
        Thread::onTrigger("tina4-run-deploy", static function () {
            (new \Tina4\GitDeploy())->log("Running a deployment");
            (new \Tina4\GitDeploy())->doDeploy();
        });

        /**
         * @secure
         */
        Route::post("/git/deploy", function (Response $response, Request $request) use ($tina4Php) {
            if ((new GitDeploy())->validateHook($response, $request))
            {
                (new \Tina4\GitDeploy())->log("Triggering a deployment");
                Thread::trigger("tina4-run-deploy", []);

            } else {
                Debug::message("Not running webhook as it is not valid for running", TINA4_LOG_NOTICE);
            }

            return $response("OK");
        });

        //Some routes only are available with debugging
        if (defined("TINA4_DEBUG") && TINA4_DEBUG) {
            Route::get("/migrate/create|/migrations/create|/migration/create", function (Response $response) {
                $html = _html(
                    _title("Tina4 Migrations"),
                    _head('<script src="https://code.jquery.com/jquery-3.5.1.min.js" integrity="sha256-9/aliU8dGd2tb6OSsuzixeV4y/faTqgFtohetphbbj0=" crossorigin="anonymous"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.12/ace.js" integrity="sha512-GZ1RIgZaSc8rnco/8CXfRdCpDxRCphenIiZ2ztLy3XQfCbQUSCuk8IudvNHxkRA3oUg6q0qejgN/qqyG1duv5Q==" crossorigin="anonymous"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.12/mode-twig.min.js" integrity="sha512-ZtTfixyUItifC8wzQ1PwinttMP5W02H6zYeC/cAU+YPCA88vcrIUMI+fCk27yWN5k92zm32PWjpKYPYR/npZzg==" crossorigin="anonymous"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.12/theme-monokai.min.js" integrity="sha512-S4i/WUGRs22+8rjUVu4kBjfNuBNp8GVsgcK2lbaFdws4q6TF3Nd00LxqnHhuxS9iVDfNcUh0h6OxFUMP5DBD+g==" crossorigin="anonymous"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.12/theme-sqlserver.min.js" integrity="sha512-TkNvDZzCp+GGiwfXNAOxt6JDzuELz8qquDcZrUzPXuKRvOcUA6kSZu2/uPhKbbjqeJIjoevYn10yrt8TS+qUXQ==" crossorigin="anonymous"></script>
                    '),
                    _body(
                        _style(
                            "body { font-family: Arial; border: 1px solid black; padding: 20px; }
                        label{ display:block; margin-top: 5px; }
                        input, textarea { width: 100%; font-size: 14px; }
                        button {font-size: 14px; border: 1px solid black; border-radius: 10px; background: #61affe; color: #fff; padding:10px; cursor: hand }
                        "
                        ),
                        _form(
                            ["class" => "form", "method" => "post"],
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
                            _input(["type" => "hidden", "name" => "formToken", "value" => (new Auth())->getToken()]),
                            _button("Create Migration")
                        )
                    )
                );
                return $response($html, HTTP_OK, TEXT_HTML);
            });

            Route::post("/migrate/create|/migrations/create|/migration/create", function (Response $response) {
                $result = (new Migration())->createMigration($_REQUEST["description"], $_REQUEST["sql"]);
                return $response($result, HTTP_OK, TEXT_HTML);
            });

            Route::get("/xdebuginfo", function(Response $response){
                ob_start();
                if (function_exists("xdebug_info")) {
                    xdebug_info();
                } else {
                    echo "Xdebug extension is not installed.";
                }
                $data = ob_get_contents();
                ob_clean();
                return $response($data, HTTP_OK, TEXT_HTML);
            });


        }

        \Tina4\Route::get('/swagger/json.json', function (\Tina4\Response $response) use ($tina4Php) {
            if (!defined("SWAGGER_TITLE")) {
                define("SWAGGER_TITLE", "Default Swagger");
                define("SWAGGER_DESCRIPTION", "Please declare in your .env values for SWAGGER_TITLE, SWAGGER_DESCRIPTION, SWAGGER_VERSION");
                define("SWAGGER_VERSION", "1.0.0");
            }
            return $response($tina4Php->getSwagger(SWAGGER_TITLE, SWAGGER_DESCRIPTION, SWAGGER_VERSION), HTTP_OK, APPLICATION_JSON);
        });
    }

    /**
     * Initialize the CSS
     * @throws CompilerException|\ScssPhp\ScssPhp\Exception\SassException
     */
    public function initCSS(): void
    {
        //Test for SCSS and existing default.css, only compile if it does not exist
        if (TINA4_DEBUG || (!TINA4_SCSS_SPLIT_CSS && !file_exists($this->documentRoot . "src" . DIRECTORY_SEPARATOR . "public" . DIRECTORY_SEPARATOR . "css" . DIRECTORY_SEPARATOR . "default.css"))) {
            $scssContent = [];
            $usedPaths = [];
            foreach (TINA4_SCSS_LOCATIONS as $lId => $scssLocation) {
                $realPath = realpath($scssLocation);
                if (in_array($realPath, $usedPaths, true)) {
                    continue;
                }
                $usedPaths[] = $realPath;
                $scssFiles = $this->getFiles($scssLocation, ".scss");
                foreach ($scssFiles as $fId => $file) {
                    // ignore files that start with an underscore, as they are likely imported in the SCSS files in specific places using @import
                    $pathParts = pathinfo($file);
                    if (strpos($pathParts['filename'], '_') === 0) continue;
                    $scssContent[$pathParts['filename']] = file_get_contents($file);
                }
            }

            try {
                $scss_compiler = new Compiler();
                foreach ($usedPaths as $uId => $scssLocation) {
                    $scss_compiler->setImportPaths($scssLocation);
                }
                if (!TINA4_SCSS_SPLIT_CSS) {
                    Debug::message('Creating CSS file: default.css', TINA4_LOG_DEBUG );
                    $scssDefault = $scss_compiler->compileString(implode(" ", $scssContent))->getCss();
                    if (file_exists($this->documentRoot . "src" . DIRECTORY_SEPARATOR . "public")) {
                        if (!file_exists($this->documentRoot . "src" . DIRECTORY_SEPARATOR . "public" . DIRECTORY_SEPARATOR . "css") && !mkdir($concurrentDirectory = $this->documentRoot . "src" . DIRECTORY_SEPARATOR . "public" . DIRECTORY_SEPARATOR . "css", 0777, true) && !is_dir($concurrentDirectory)) {
                            throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
                        }
                        file_put_contents($this->documentRoot . "src" . DIRECTORY_SEPARATOR . "public" . DIRECTORY_SEPARATOR . "css" . DIRECTORY_SEPARATOR . "default.css", $scssDefault);
                    }
                } else {
                    // Split the SCSS into seperate CSS files
                    foreach($scssContent as $filename => $scss) {
                        $outputname = trim($filename).".css";
                        Debug::message('Creating CSS file:' . $outputname, TINA4_LOG_DEBUG );
                        $scssDefault = $scss_compiler->compileString($scss)->getCss();
                        if (file_exists($this->documentRoot . "src" . DIRECTORY_SEPARATOR . "public")) {
                            if (!file_exists($this->documentRoot . "src" . DIRECTORY_SEPARATOR . "public" . DIRECTORY_SEPARATOR . "css") && !mkdir($concurrentDirectory = $this->documentRoot . "src" . DIRECTORY_SEPARATOR . "public" . DIRECTORY_SEPARATOR . "css", 0777, true) && !is_dir($concurrentDirectory)) {
                                throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
                            }
                            file_put_contents($this->documentRoot . "src" . DIRECTORY_SEPARATOR . "public" . DIRECTORY_SEPARATOR . "css" . DIRECTORY_SEPARATOR . $outputname, $scssDefault);
                        }
                    }
                }
            } catch (\Exception $exception)
            {
                $message =  $this->clean($exception->getMessage());

                Debug::message("Could not build css on ".$message, TINA4_LOG_ERROR);
            }

        }
    }

    /**
     * @throws \Twig\Error\SyntaxError
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     * @throws \Twig\Error\RuntimeError
     * @throws LoaderError
     * @throws \Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException
     */
    public function __toString(): string
    {
        if (!isset($_SERVER["REQUEST_METHOD"])) {
            $_SERVER["REQUEST_METHOD"] = "GET";
        }

        if (!isset($_SERVER["REQUEST_URI"])) {
            $_SERVER["REQUEST_URI"] = "";
        }

        $routerResponse = (new Router())->resolveRoute($_SERVER["REQUEST_METHOD"], $_SERVER["REQUEST_URI"], $this->config);

        $content = "";
        if ($routerResponse !== null) {
            if (!headers_sent()) {
                foreach ($routerResponse->headers as $header) {
                    if ($routerResponse->httpCode !== HTTP_OK && strpos($header, "FreshToken") !== false) {
                        continue;
                    }
                    header($header);
                }
            }
            http_response_code($routerResponse->httpCode);
            if ($routerResponse->content === "") {
                //try give back a response based on the error code - first templates then public
                $content = Utilities::renderErrorTemplate($routerResponse->httpCode);
            } else {
                $content = $routerResponse->content;
            }
        }

        if (TINA4_DEBUG) {
            $debugContent = DebugRender::render();
            if ($debugContent !== "") {
                header("Content-Type: text/html");
                $content = $debugContent . "\n" . $content;
            }
        }

        if (!empty($content)) {
            return $content;
        } else {
            return "";
        }
    }

    /**
     * Swagger
     * @param string $title
     * @param string $description
     * @param string $version
     * @return false|string
     * @throws \ReflectionException
     */
    public function getSwagger(string $title = "Tina4", string $description = "Swagger Documentation", string $version = "1.0.0"): string
    {
        return (new Swagger($this->documentRoot, $title, $description, $this->subFolder));
    }
}
