<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/02/09
 * Time: 03:04 PM
 */
class ParseTemplate {
    private $root;
    private $includeRegEx = "/\\{\\{include:(.*)\\}\\}/i";
    private $callRegEx="/\\{\\{call:(.*)\\}\\}/i";
    private $objectRegEx="/\\{\\{(.*)\\:(.*)\\}\\}/i";
    private $varRegEx = "";
    private $locations = ["assets", "objects"];
    private $responseCode = 200;

    function __construct($root, $fileName) {
        if (defined("TINA4_TEMPLATE_LOCATIONS")) {
            $this->locations = TINA4_TEMPLATE_LOCATIONS;
        }
        //set the working folder for the site
        $this->root = $root;
        //parse the file for objects and tags
        $this->content = $this->parseFile($fileName);
    }

    //we want to parse all includes that may exist
    function parseSnippets($content) {
        preg_match_all($this->includeRegEx, $content, $matchIncludes);

        foreach ($matchIncludes[0] as $mid => $matchInclude) {
            $content = str_replace($matchInclude, $this->parseFile("/".$matchIncludes[1][$mid]), $content);
        }

        return $content;
    }

    //we want to parse all variables that might exist
    function parseVariables($content) {

        return $content;
    }

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
            }
        }
        return ["method" => $method, "params" => $paramArray];
    }

    function callMethod ($object, $method) {
        $parts = $this->parseParams($method);

        if ($object === "call") {
            return call_user_func_array( $parts["method"], $parts["params"]);
        }
          else
        if (!empty($parts["method"])) {
            eval('$object = (new ' . $object . '());');
            if (method_exists($object, $parts["method"] )) {
               return call_user_func_array(array($object, $parts["method"]), $parts["params"]);
            }
              else {
                  return "[Method {$parts["method"]} not found on ".get_class($object)."]";
              }
        } else {
            $objectReflection = new ReflectionClass($object);
            return $objectReflection->newInstanceArgs($parts["params"]);
        }

    }

    //we want to parse all variables that might exist
    function parseCalls($content) {
        preg_match_all($this->objectRegEx, $content, $matchIncludes);
        foreach ($matchIncludes[0] as $mid => $matchInclude) {
            $content = str_replace($matchInclude, $this->callMethod($matchIncludes[1][$mid], $matchIncludes[2][$mid]), $content);
        }
        return $content;
    }


    //parse files
    function parseFile ($fileName) {
        //find a file in the assets folder which matches the route given
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        if (empty($ext)) {
             $fileName = $fileName . ".html";
        }

        $realFileName = $fileName;

        foreach ($this->locations as $lid => $location) {
            $testFile = $this->root."/".$location.$fileName;
            if (file_exists($testFile)) {
                $realFileName = $testFile;
                break;
            }
        }

        if (file_exists($realFileName)) {

            $content = file_get_contents($realFileName);
            $content = $this->parseSnippets($content);
            $content = $this->parseCalls($content);
            $content = $this->parseVariables($content);
        }   else {
            $this->responseCode = 404;
            $content = "<img src=\"/assets/images/404.jpg\">";
        }

        return $content;
    }

    function __toString()
    {
        http_response_code($this->responseCode);
        return (string)$this->content;
    }

}