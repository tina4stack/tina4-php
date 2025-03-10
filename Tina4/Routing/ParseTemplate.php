<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * A very simple template engine with limited functionality
 * @package Tina4
 */
class ParseTemplate
{
    use Utility;

    public $content;
    public $httpCode = HTTP_OK;
    public $headers = [];
    public $fileName;
    private $root;
    private $includeRegEx = "/\\{\\{include:(.*)\\}\\}/i";
    private $callRegEx = "/\\{\\{call:(.*)\\}\\}/i";
    private $objectRegEx = "/\\{\\{(.*)\\:(.*)\\}\\}/i";
    private $varRegEx = "";
    private $locations = ["public", "templates"];
    private $subFolder;
    private $definedVariables;
    private $evals = [];
    private $GUID;

    /**
     * ParseTemplate constructor.
     * @param string $fileName Name of the file
     * @param string $definedVariables
     * @throws \Exception Error on failure
     */
    public function __construct($fileName, $definedVariables = "", $guid="")
    {
        $this->GUID = $guid;
        $fileName = preg_replace('#/+#', '/', $fileName);
        if (TINA4_DEBUG) {
            Debug::message("$this->GUID TINA4 Filename: " . $fileName, TINA4_LOG_DEBUG);
        }

        if (defined("TINA4_TEMPLATE_LOCATIONS_INTERNAL")) {
            $this->locations = TINA4_TEMPLATE_LOCATIONS_INTERNAL;
        }

        if (defined("TINA4_APP_DOCUMENT_ROOT")) {
            $this->locations = array_merge($this->locations, [$_ENV["TINA4_APP_DOCUMENT_ROOT"]]);
        }

        //set the working folder for the site
        $this->root = TINA4_DOCUMENT_ROOT;
        $this->subFolder = TINA4_SUB_FOLDER;
        $this->definedVariables = $definedVariables;
        //parse the file for objects and tags
        $this->fileName = $fileName;
        $this->content = $this->parseFile($fileName);
    }

    /**
     * @param string $fileName Name of the file
     * @return false|mixed|string
     * @throws \Exception Error on failure
     */
    final public function parseFile(string $fileName)
    {
        if ($fileName === "") {
            return "";
        }
        //Trim off the first char if /
        if ($fileName[0] === "/") {
            $fileName = substr($fileName, 1);
        }
        //find a file in the public or templates folder which matches the route given
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);


        if (empty($ext) && (!defined("TINA4_APP_DOCUMENT_ROOT") && !defined("TINA4_APP_INDEX"))) {
            $possibleFiles = [$fileName . ".html", $fileName . ".twig", $fileName . "/index.twig", $fileName . "/index.html", str_replace("/index", "", $fileName) . ".twig", str_replace("/index", "", $fileName) . ".html"];
            $possibleFiles = array_unique($possibleFiles);
        } else {
            $possibleFiles = [$fileName];
            if (defined("TINA4_APP_DOCUMENT_ROOT")) {
                if (!defined("TINA4_APP_INDEX")) {
                    define("TINA4_APP_INDEX", $fileName . ".html");
                }
            }
        }

        $realFileName = $fileName;
        $found = false;
        $mimeType = self::getMimeType($fileName);
        foreach ($this->locations as $lid => $location) {
            foreach ($possibleFiles as $id => $parseFileName) {

                //@todo refactor
                if (is_array($location) && isset($location["path"])) {
                    $location = $location["path"];
                }

                if (file_exists($location)) {
                    $testFile = ($location . DIRECTORY_SEPARATOR . $parseFileName);
                } else {
                    $testFile = ($this->root . $location . DIRECTORY_SEPARATOR . $parseFileName);
                    $location = $this->root . $location . DIRECTORY_SEPARATOR;
                }

                $testFile = preg_replace('#/+#', DIRECTORY_SEPARATOR, $testFile);
                if (file_exists($testFile)) {
                    $found = true;
                    $realFileName = $testFile;
                    $mimeType = self::getMimeType($testFile);
                    break;
                }
            }
            if ($found) {
                break;
            }
        }

        $ext = pathinfo($realFileName, PATHINFO_EXTENSION);

        Debug::message("$this->GUID Looking for file " . $realFileName, TINA4_LOG_DEBUG);
        if (file_exists($realFileName)) {
            $this->httpCode = HTTP_OK;
            //Render a twig file if the extension is twig
            if ($ext === "twig") {
                if (!isset($_SESSION["renderData"])) {
                    $_SESSION["renderData"] = [];
                }
                $this->headers[] = "Content-Type: " . TEXT_HTML;
                $this->headers[] = "Tina4-Debug: ".$this->GUID;

                $this->fileName = $realFileName;
                $content = renderTemplate($realFileName, $_SESSION["renderData"], $location);
            }
            else //we are only going to allow php files to run in debug mode
                if ($ext === "php") {
                    Debug::message("$this->GUID RUNNING SCRIPT DIRECTLY ".$realFileName, TINA4_LOG_CRITICAL);
                    if (TINA4_DEBUG) {
                        ob_start();
                        include $realFileName;
                        $content = ob_get_contents();
                        ob_clean();
                    } else {
                        Debug::message("$this->GUID Returning File not found {$fileName}", TINA4_LOG_DEBUG);
                        $this->headers[] = "Tina4-Debug: ".$this->GUID;
                        $this->httpCode = HTTP_NOT_FOUND;
                        $content = "";
                        $this->fileName = "";
                    }
                }
                else {
                    Debug::message("$this->GUID Found ".$realFileName, TINA4_LOG_DEBUG);
                    $this->headers[] = "Content-Type: " . $mimeType;
                    $this->headers[] = ('Cache-Control: max-age=' . (60 * 60 * 60) . ', public');
                    $this->headers[] = "Tina4-Debug: ".$this->GUID;
                    $this->headers[] = ('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + (60 * 60 * 60))); //60 hour expiry time
                    $this->headers[] = ('Pragma: cache');
                    $this->fileName = $realFileName;
                    if (!is_dir($realFileName)) {
                        $content = file_get_contents($realFileName);
                        $content = $this->parseSnippets($content);
                        $content = $this->parseCalls($content);
                        $content = $this->parseVariables($content);
                    } else {
                        Debug::message("$this->GUID Returning File not found {$fileName}", TINA4_LOG_DEBUG);
                        $this->headers[] = "Tina4-Debug: ".$this->GUID;
                        $this->httpCode = HTTP_NOT_FOUND;
                        $content = "";
                        $this->fileName = "";
                    }
                }
        } else {
            Debug::message("$this->GUID Returning File not found {$fileName}", TINA4_LOG_DEBUG);
            $this->headers[] = "Tina4-Debug: ".$this->GUID;
            $this->httpCode = HTTP_NOT_FOUND;
            $content = "";
            $this->fileName = "";
            //Mobile application TINA4_APP
            if (defined("TINA4_APP_DOCUMENT_ROOT")) {
                $content = file_get_contents("./" . $_ENV["TINA4_APP_DOCUMENT_ROOT"].DIRECTORY_SEPARATOR.$_ENV["TINA4_APP_INDEX"]);
                $this->httpCode = HTTP_OK;
            }
        }
        return $content;
    }

    /**
     * Parse all includes that may exist
     * @param $content
     * @return mixed
     * @throws \Exception Error on failure
     */
    public function parseSnippets($content)
    {
        preg_match_all($this->includeRegEx, $content, $matchIncludes);

        foreach ($matchIncludes[0] as $mid => $matchInclude) {
            $content = str_replace($matchInclude, $this->parseFile("/" . $matchIncludes[1][$mid]), $content);
        }

        return $content;
    }

    /**
     * Parse all variables that might exist
     * @param $content
     * @return mixed
     * @throws \Exception Error on failure
     */
    public function parseCalls($content)
    {
        preg_match_all($this->objectRegEx, $content, $matchIncludes);
        foreach ($matchIncludes[0] as $mid => $matchInclude) {
            $content = str_replace($matchInclude, $this->callMethod($matchIncludes[1][$mid], $matchIncludes[2][$mid]), $content);
        }
        return $content;
    }

    /**
     * @param object $object
     * @param $method
     * @return mixed|string
     * @throws \Exception Error on failure
     */
    public function callMethod($object, $method): ?string
    {
        $parts = $this->parseParams($method);

        if ($object === "call") {
            error_reporting(E_ALL & ~E_WARNING);
            try {
                $response = call_user_func_array($parts["method"], $parts["params"]);
                if ($response === false || empty($response)) {
                    throw new \Exception("Failed to call method ");
                } else {
                    return $response;
                }
            } catch (\Exception $error) {
                $this->evals[] = $parts["method"];
            }
        } elseif (!empty($parts["method"])) {
            eval('$object = (new ' . $object . '());');
            if (method_exists($object, $parts["method"])) {
                $response = call_user_func_array(array($object, $parts["method"]), $parts["params"]);
                if ($response === false) {
                    throw new \Exception("Failed to call method ");
                } else {
                    return $response;
                }
            } else {
                return "[Method {$parts["method"]} not found on " . get_class($object) . "]";
            }
        } else {
            $objectReflection = new \ReflectionClass($object);
            return $objectReflection->newInstanceArgs($parts["params"]);
        }
        return null;
    }

    /**
     * @param string $params Parameters to be parsed
     * @return array Multidimensional array containing methods and their respective array of parameters
     */
    public function parseParams($params): array
    {
        $paramArray = [];
        $method = explode("?", $params, 2);
        if (!empty($method[1])) {
            $params = $method[1];
        }
        $method = $method[0];

        $paramArray = explode(",", $params); //basic - needs to be enhanced

        //check for strings
        foreach ($paramArray as $pid => $param) {
            if (is_string($param) && $param[0] == "\"" && $param[strlen($param) - 1] == "\"") {
                $paramArray[$pid] = substr($param, 1, strlen($param) - 2);
                $paramArray[$pid] = $this->parseVariables($paramArray[$pid]);
            }
        }
        return ["method" => $method, "params" => $paramArray];
    }


    //parse files

    /**
     * Parse all variables that might exist
     * @param $content
     * @return mixed
     */
    public function parseVariables($content)
    {

        foreach ($this->evals as $id => $eval) {
            try {
                eval($eval);
            } catch (\ParseError $error) {
                if (TINA4_DEBUG) {
                    Debug::message("TINA4: Parse Error for " . $eval, TINA4_LOG_ERROR);
                }
            }
        }

        if (is_array($this->definedVariables)) {
            $this->definedVariables = array_merge($this->definedVariables, get_defined_vars());
        } else {
            $this->definedVariables = get_defined_vars();
        }

        $this->definedVariables["baseUrl"] = $this->subFolder;
        $this->definedVariables["baseURL"] = $this->subFolder;

        if (!empty($this->definedVariables)) {
            foreach ($this->definedVariables as $varName => $value) {
                $content = str_replace('{{' . $varName . '}}', $value, $content);
            }
        }

        if (isset($_SESSION)) {
            foreach ($_SESSION as $varName => $value) {
                if (!is_array($value) && !is_object($value)) {
                    $content = str_replace('{{' . $varName . '}}', $value ?? '', $content);
                }
            }
        }

        if (isset($_REQUEST)) {
            foreach ($_REQUEST as $varName => $value) {
                $content = str_replace('{{' . $varName . '}}', $value, $content);
            }
        }

        return $content;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        http_response_code($this->responseCode);
        return (string)$this->content;
    }
}
