<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

use ScssPhp\ScssPhp\Compiler;
use ScssPhp\ScssPhp\Exception\CompilerException;
use Twig\Error\LoaderError;

/**
 * The main webserver initializer
 * @package Tina4
 */
class Tina4Php extends Data
{
    use Utility;

    public $config;

    public function __construct(?\Tina4\Config $config = null)
    {
        parent::__construct();
        if (!isset($config)) {
            $config = new \Tina4\Config();
        }

        //Get all the include folders
        if (!defined("TINA4_TEMPLATE_LOCATIONS_INTERNAL")) {
            if (defined("TINA4_TEMPLATE_LOCATIONS")) {
                define("TINA4_TEMPLATE_LOCATIONS_INTERNAL", array_merge(TINA4_TEMPLATE_LOCATIONS, \Tina4\Module::getTemplateFolders()));
            } else {
                define("TINA4_TEMPLATE_LOCATIONS_INTERNAL", array_merge(["cache", "src" . DIRECTORY_SEPARATOR . "templates", "src" . DIRECTORY_SEPARATOR . "public", "src" . DIRECTORY_SEPARATOR . "assets", "src" . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR . "snippets"], \Tina4\Module::getTemplateFolders()));
            }
        }

        if (!defined("TINA4_ROUTE_LOCATIONS_INTERNAL")) {
            if (defined("TINA4_ROUTE_LOCATIONS")) {
                define("TINA4_ROUTE_LOCATIONS_INTERNAL", array_merge(TINA4_ROUTE_LOCATIONS, \Tina4\Module::getRouteFolders()));
            } else {
                define("TINA4_ROUTE_LOCATIONS_INTERNAL", array_merge(["src" . DIRECTORY_SEPARATOR . "api", "src" . DIRECTORY_SEPARATOR . "routes"], \Tina4\Module::getRouteFolders()));
            }
        }

        if (!defined("TINA4_INCLUDE_LOCATIONS_INTERNAL")) {
            if (defined("TINA4_INCLUDE_LOCATIONS")) {
                define("TINA4_INCLUDE_LOCATIONS_INTERNAL", array_merge(TINA4_INCLUDE_LOCATIONS, \Tina4\Module::getIncludeFolders()));
            } else {
                define("TINA4_INCLUDE_LOCATIONS_INTERNAL", array_merge(["src" . DIRECTORY_SEPARATOR . "app", "src" . DIRECTORY_SEPARATOR . "objects", "src" . DIRECTORY_SEPARATOR . "orm", "src" . DIRECTORY_SEPARATOR . "services"], \Tina4\Module::getIncludeFolders()));
            }
        }

        if (!defined("TINA4_SCSS_LOCATIONS_INTERNAL")) {
            if (defined("TINA4_SCSS_LOCATIONS")) {
                define("TINA4_SCSS_LOCATIONS_INTERNAL", array_merge(TINA4_SCSS_LOCATIONS, \Tina4\Module::getSCSSFolders()));
            } else {
                define("TINA4_SCSS_LOCATIONS", array_merge(["src" . DIRECTORY_SEPARATOR . "scss"], \Tina4\Module::getSCSSFolders()));
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

            switch ($operator) {
                case "==":
                    return $dateA == $dateB;
                    break;
                case "!=":
                    return $dateA != $dateB;
                    break;
                case ">":
                    return $dateA > $dateB;
                    break;
                case "<":
                    return $dateA < $dateB;
                    break;
                case ">=":
                    return $dateA >= $dateB;
                    break;
                case "<=":
                    return $dateA <= $dateB;
                    break;
            }
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
            } catch (CompilerException $e) {
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

            Route::get("/phpinfo", function(Response $response){
                ob_start();
                phpinfo();
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
            return $response($tina4Php->getSwagger(SWAGGER_TITLE, SWAGGER_DESCRIPTION, SWAGGER_VERSION));
        });
    }

    /**
     * Initialize the CSS
     * @throws CompilerException
     */
    public function initCSS(): void
    {
        //Test for SCSS and existing default.css, only compile if it does not exist
        if (TINA4_DEBUG || !file_exists($this->documentRoot . "src" . DIRECTORY_SEPARATOR . "public" . DIRECTORY_SEPARATOR . "css" . DIRECTORY_SEPARATOR . "default.css")) {
            $scssContent = "";
            $usedPaths = [];
            foreach (TINA4_SCSS_LOCATIONS as $lId => $scssLocation) {
                $realPath = realpath($scssLocation);
                if (in_array($realPath, $usedPaths)) {
                    continue;
                }
                $usedPaths[] = $realPath;
                $scssFiles = $this->getFiles($scssLocation, ".scss");
                foreach ($scssFiles as $fId => $file) {
                    $scssContent .= file_get_contents($file);
                }
            }

            try {
                $scss = new Compiler();
                $scssDefault = $scss->compileString($scssContent)->getCss();
                if (file_exists($this->documentRoot . "src" . DIRECTORY_SEPARATOR . "public")) {
                    if (!file_exists($this->documentRoot . "src" . DIRECTORY_SEPARATOR . "public" . DIRECTORY_SEPARATOR . "css") && !mkdir($concurrentDirectory = $this->documentRoot . "src" . DIRECTORY_SEPARATOR . "public" . DIRECTORY_SEPARATOR . "css", 0777, true) && !is_dir($concurrentDirectory)) {
                        throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
                    }
                    file_put_contents($this->documentRoot . "src" . DIRECTORY_SEPARATOR . "public" . DIRECTORY_SEPARATOR . "css" . DIRECTORY_SEPARATOR . "default.css", $scssDefault);
                }
            } catch (\Exception $exception)
            {
                Debug::message("Could not build default.css ".$exception->getMessage(), TINA4_LOG_ERROR);
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
            $_SERVER["REQUEST_METHOD"] = TINA4_GET;
        }

        if (!isset($_SERVER["REQUEST_URI"])) {
            $_SERVER["REQUEST_URI"] = "";
        }

        $routerResponse = (new Router())->resolveRoute($_SERVER["REQUEST_METHOD"], $_SERVER["REQUEST_URI"], $this->config);

        $content = "";
        if ($routerResponse !== null) {
            if (!headers_sent()) {
                foreach ($routerResponse->headers as $hid => $header) {
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
        return (new Swagger($this->documentRoot, $title, $description, $version, $this->subFolder));
    }
}
