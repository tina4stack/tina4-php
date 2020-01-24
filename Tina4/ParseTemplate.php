<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/02/09
 * Time: 03:04 PM
 */
namespace Tina4;

/**
 * Class ParseTemplate
 * @package Tina4
 */
class ParseTemplate {
    private $root;
    private $includeRegEx = "/\\{\\{include:(.*)\\}\\}/i";
    private $callRegEx="/\\{\\{call:(.*)\\}\\}/i";
    private $objectRegEx="/\\{\\{(.*)\\:(.*)\\}\\}/i";
    private $varRegEx = "";
    private $locations = ["assets", "objects"];
    private $responseCode = 200;
    private $twig;
    private $definedVariables;
    private $evals = [];

    /**
     * ParseTemplate constructor.
     * @param $root
     * @param string $fileName Name of the file
     * @param string $definedVariables
     * @throws \Exception Error on failure
     */
    function __construct($root, $fileName, $definedVariables="") {

        if (TINA4_DEBUG) {
            error_log("TINA4 Filename: " . $fileName);
        }
        if (defined("TINA4_TEMPLATE_LOCATIONS")) {
            $this->locations = TINA4_TEMPLATE_LOCATIONS;
        }
        //set the working folder for the site
        $this->root = $root;
        //parse the file for objects and tags

        $this->content = $this->parseFile($fileName);

        $this->definedVariables = $definedVariables;
    }

    /**
     * Parse all includes that may exist
     * @param $content
     * @return mixed
     * @throws \Exception Error on failure
     */
    function parseSnippets($content) {
        preg_match_all($this->includeRegEx, $content, $matchIncludes);

        foreach ($matchIncludes[0] as $mid => $matchInclude) {
            $content = str_replace($matchInclude, $this->parseFile("/".$matchIncludes[1][$mid]), $content);
        }

        return $content;
    }

    /**
     * Parse all variables that might exist
     * @param $content
     * @return mixed
     */
    function parseVariables($content) {
        foreach ($this->evals as $id => $eval) {
            try {
                eval($eval);
            } catch (\ParseError $error) {
                if (TINA4_DEBUG) {
                    error_log("TINA4: Parse Error for " . $eval);
                }
            }
        }

        if (is_array($this->definedVariables)) {
            $this->definedVariables = array_merge($this->definedVariables, get_defined_vars());
        } else {
            $this->definedVariables = get_defined_vars();
        }

        if (!empty($this->definedVariables)) {
            foreach ($this->definedVariables as $varName => $value) {
                $content = str_replace('{{'.$varName.'}}', $value, $content);
            }
        }

        if (isset($_SESSION)) {
            foreach ($_SESSION as $varName => $value) {
                $content = str_replace('{{'.$varName.'}}', $value, $content);
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
     * @param string $params Parameters to be parsed
     * @return array Multidimensional array containing methods and their respective array of parameters
     */
    function parseParams ($params) {
        $paramArray = [];
        $method = explode("?", $params, 2);
        if (!empty($method[1])) {
            $params = $method[1];
        }
        $method = $method[0];

        $paramArray = explode(",", $params); //basic - needs to be enhanced

        //check for strings
        foreach ($paramArray as $pid => $param) {
            if (is_string($param) && $param[0] == "\"" && $param[strlen($param)-1] == "\"") {
                $paramArray[$pid] = substr($param, 1, strlen($param)-2);
                $paramArray[$pid] = $this->parseVariables($paramArray[$pid]);
            }
        }
        return ["method" => $method, "params" => $paramArray];
    }

    /**
     * @param object $object
     * @param $method
     * @return mixed|string
     * @throws \Exception Error on failure
     */
    function callMethod ($object, $method) {
        $parts = $this->parseParams($method);

        if ($object === "call") {
            error_reporting(E_ALL & ~ E_WARNING);
            try {
                $response = call_user_func_array($parts["method"], $parts["params"]);
                if ($response === false || empty($response)) {

                    throw new \Exception ("Failed to call method ");
                } else {
                    return $response;
                }
            } catch (\Exception $error) {
                $this->evals[] = $parts["method"];
            }
        }
        else
            if (!empty($parts["method"])) {
                eval('$object = (new ' . $object . '());');
                if (method_exists($object, $parts["method"] )) {
                    $response = call_user_func_array(array($object, $parts["method"]), $parts["params"]);
                    if ($response === false) {
                        throw new \Exception ("Failed to call method ");
                    } else {
                        return $response;
                    }
                }
                else {
                    return "[Method {$parts["method"]} not found on ".get_class($object)."]";
                }
            } else {
                $objectReflection = new ReflectionClass($object);
                return $objectReflection->newInstanceArgs($parts["params"]);
            }

    }

    /**
     * Parse all variables that might exist
     * @param $content
     * @return mixed
     * @throws \Exception Error on failure
     */
    function parseCalls($content) {
        preg_match_all($this->objectRegEx, $content, $matchIncludes);
        foreach ($matchIncludes[0] as $mid => $matchInclude) {
            $content = str_replace($matchInclude, $this->callMethod($matchIncludes[1][$mid], $matchIncludes[2][$mid]), $content);
        }
        return $content;
    }


    //parse files

    /**
     * @param string $fileName Name of the file
     * @return false|mixed|string
     * @throws \Exception Error on failure
     */
    function parseFile ($fileName) {
        //Trim off the first char if /
        if ($fileName[0] === "/") {
            $fileName = substr($fileName,1);
        }
        //find a file in the assets folder which matches the route given
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);



        if (empty($ext)) {
            $possibleFiles =  [ $fileName . ".html", $fileName.".twig" ];
        } else {
            $possibleFiles = [$fileName];

        }


        $realFileName = $fileName;
        foreach ($this->locations as $lid => $location) {
            foreach ($possibleFiles as $id => $fileName) {
                $testFile = $this->root . "/" . $location ."/". $fileName;
                $testFile = str_replace("//", "/", $testFile);
                if (file_exists($testFile)) {
                    $realFileName = $testFile;
                    break;
                }
            }
        }


        $ext = pathinfo($realFileName, PATHINFO_EXTENSION);

        if (file_exists($realFileName)) {
            //Render a twig file if the extension is twig
            if ($ext === "twig") {
                if (!isset($_SESSION["renderData"])) {
                    $_SESSION["renderData"] = [];
                }

                $content = renderTemplate($realFileName, $_SESSION["renderData"]);
            } else {
                $content = file_get_contents($realFileName);
                $content = $this->parseSnippets($content);
                $content = $this->parseCalls($content);
                $content = $this->parseVariables($content);
            }
        }   else {
            $this->responseCode = 404;
            $content = "<img src=\"/assets/images/404.jpg\">";
        }

        return $content;
    }

    /**
     * @return string
     */
    function __toString()
    {
        http_response_code($this->responseCode);
        return (string)$this->content;
    }

}