<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/02/09
 * Time: 02:02 PM
 * Purpose: Determine routing responses and data outputs based on the URL sent from the system
 * You are welcome to read and modify this code but it should not be broken, if you find something to improve it, send me an email with the patch
 * andrevanzuydam@gmail.com
 */

namespace Tina4;

/**
 * Class Routing
 * @package Tina4
 */
class Routing
{
    private $auth;
    private $params;
    private $content;
    private $root;
    private $subFolder = ""; //Sub folder when stack runs under a directory

    /**
     * @var string Type of method
     */
    private $method;


    /**
     * @var string Used to check if path matches route path in matchPath()
     */
    private $pathMatchExpression = "/([a-zA-Z0-9\\ \\! \\-\\}\\{\\.\\_]*)\\//";

    /**
     * Routing constructor.
     * @param string $root Where the document root is located
     * @param string $subFolder Subfolder where the project sits
     * @param string $urlToParse URL being parsed
     * @param string $method Type of method e.g. ANY, POST, DELETE, etc
     * @param Config|null $config Config containing configs from the initialization
     * @param bool $suppressed Don't output headers
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function __construct($root = "", $subFolder = "", $urlToParse = "", $method = "", Config $config = null, $suppressed=false)
    {
        if (!empty($root)) {
            $_SERVER["DOCUMENT_ROOT"] = $root;
        }

        $this->root = $root;

        if (!empty($config) && !empty($config->getAuthentication())) {
            $this->auth = $config->getAuthentication();
        } else {
            $this->auth = new Auth($_SERVER["DOCUMENT_ROOT"], $urlToParse);
        }

        if (!empty($subFolder)) {
            $this->subFolder = $subFolder . "/";
            if (!defined("TINA4_BASE_URL")) define("TINA4_BASE_URL", $subFolder);
        } else {
            if (!defined("TINA4_BASE_URL")) define("TINA4_BASE_URL", "");
        }

        if (TINA4_DEBUG) {
            DebugLog::message("TINA4: URL to parse " . $urlToParse, TINA4_DEBUG_LEVEL);
        }

        global $arrRoutes;

        if (!$suppressed) {
            if (in_array("*", TINA4_ALLOW_ORIGINS) || in_array($_SERVER["HTTP_ORIGIN"], TINA4_ALLOW_ORIGINS)) {
                if (key_exists("HTTP_ORIGIN", $_SERVER)) {
                    header('Access-Control-Allow-Origin: ' . $_SERVER["HTTP_ORIGIN"]);
                }
                header('Access-Control-Allow-Methods: GET, PUT, POST, PATCH, DELETE, OPTIONS');
                header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
                header('Access-Control-Allow-Credentials: true');
            }

            if ($method === "OPTIONS") {
                http_response_code(200);
                $this->content = "";
                return true;
            }
        }

        //Gives back things in an orderly fashion for API responses
        /**
         * Response method
         * @param $content
         * @param int $code
         * @param null $contentType
         * @return false|string
         */
        $response = new Response();

        $urlToParse = $this->cleanURL($urlToParse);


        if (!empty($this->subFolder)) {
            $urlToParse = str_replace($this->subFolder, "/", $urlToParse);
        }

        //Generate a filename just in case the routing doesn't find anything
        if ($urlToParse === "/") {
            $fileName = "index";
        } else {
            $fileName = $urlToParse;
        }

        //Clean up twig extensions
        $fileName = str_replace(".twig", "", $fileName);
        // if requested file isn't a php file
        if (file_exists($root . $urlToParse) && $urlToParse !== "/" && !is_dir($root . $urlToParse)) {
            $this->returnStatic($root . $urlToParse);
        } else {
            //Check the template locations
            foreach (TINA4_TEMPLATE_LOCATIONS_INTERNAL as $tid => $templateLocation) {
                DebugLog::message($root . DIRECTORY_SEPARATOR . $templateLocation . $urlToParse, TINA4_DEBUG_LEVEL);
                if (file_exists($root . DIRECTORY_SEPARATOR . $templateLocation . $urlToParse) && !is_dir($root . DIRECTORY_SEPARATOR . $templateLocation . $urlToParse)) {
                    $this->returnStatic($root . DIRECTORY_SEPARATOR . $templateLocation . DIRECTORY_SEPARATOR . $urlToParse);
                }
            }
        }


        if ($urlToParse !== "/") {
            $urlToParse .= "/";
            $urlToParse = str_replace("//", "/", $urlToParse);
        }

        $this->content = "";
        $this->method = $method;
        DebugLog::message("Root: {$root}", TINA4_DEBUG_LEVEL);
        DebugLog::message("URL: {$urlToParse}", TINA4_DEBUG_LEVEL);
        DebugLog::message("Method: {$method}", TINA4_DEBUG_LEVEL);

        if (defined ("TINA4_ROUTE_LOCATIONS_INTERNAL")) {
            //include routes in routes folder
            foreach (TINA4_ROUTE_LOCATIONS_INTERNAL as $rid => $route) {
                if (file_exists($route)) {
                    $this->includeDirectory($route);
                }
                    else
                if (file_exists(getcwd() . "/" . $route)) {
                    $this->includeDirectory(getcwd() . "/" . $route);
                } else {
                    if (TINA4_DEBUG) {
                        DebugLog::message("TINA4: " . getcwd() . "/" . $route . " not found!", TINA4_DEBUG_LEVEL);
                    }
                }
            }
        }

        //determine what should be outputted and if the route is found
        $matched = false;

        if (TINA4_DEBUG) {
            DebugLog::message($arrRoutes, TINA4_DEBUG_LEVEL);
        }

        $result = null;
        //iterate through the routes
        foreach ($arrRoutes as $rid => $route) {
            $result = "";
            if ($this->matchPath($urlToParse, $route["routePath"]) && ($route["method"] === $this->method || $route["method"] == TINA4_ANY)) {
                //Look to see if we are a secure route
                $reflection = new \ReflectionFunction($route["function"]);
                $doc = $reflection->getDocComment();
                preg_match_all('#@(.*?)(\r\n|\n)#s', $doc, $annotations);

                $params = $this->getParams($response, $route["inlineParamsToRequest"]);
                if (in_array("secure", $annotations[1])) {
                    $headers = getallheaders();

                    if (isset($headers["Authorization"]) && $this->auth->validToken($headers["Authorization"])) {
                        //call closure with & without params
                        $result = call_user_func_array($route["function"], $params);
                    } else
                        if ($this->auth->tokenExists()) { //Tries to get a token from the session
                            $result = call_user_func_array($route["function"], $params);
                        } else {
                            $this->forbidden();
                        }

                    $matched = true;
                    break;
                } else {
                    if (isset($_REQUEST["formToken"]) && in_array($route["method"], [\TINA4_POST, \TINA4_PUT, \TINA4_PATCH, \TINA4_DELETE])) {
                        //Check for the formToken request variable
                        if (!$this->auth->validToken($_REQUEST["formToken"])) {
                            $this->forbidden();
                        } else {
                            $result = call_user_func_array($route["function"], $params);
                        }
                    } else {
                        if (!in_array($route["method"], [\TINA4_POST, \TINA4_PUT, \TINA4_PATCH, \TINA4_DELETE])) {
                            $result = call_user_func_array($route["function"], $params);
                        } else {
                            $this->forbidden();
                        }
                    }
                }

                //check for an empty result
                if (empty($result) && !is_array($result) && !is_object($result)) {
                    $result = "";
                } else {
                    if (!is_string($result)) {
                        $result = json_encode($result);
                    }
                    //After this point the JsonSerializable should take care of complex objects
                }

                $matched = true;
                break;
            }
        }

        //result was empty we can parse for templates
        if (!$matched) {
            //if there is no file passed, go for the default or make one up
            if (empty($fileName)) {
                $fileName = "index";
            } else if (strpos($fileName, "index") === false) {
                $fileName .= "/index";
            }

            if (TINA4_DEBUG) {
                $variables = [];
                foreach (get_defined_vars() as $varName => $variable) {
                    if (!is_array($variable) && !is_object($variable)) {
                        $variables[] = $varName . " => " . $variable;
                    }
                }

                DebugLog::message("TINA4: Variables\n" . join("\n", $variables), TINA4_DEBUG_LEVEL);
            }


            $this->content .= new ParseTemplate($root, $fileName, get_defined_vars(), $this->subFolder);
        } else {
            $this->content = $result;
        }

        return false;
    }

    /**
     * Clean URL by splitting string at "?" to get actual URL
     * @param string $url URL to be cleaned that may contain "?"
     * @return mixed Part of the URL before the "?" if it existed
     */
    public function cleanURL($url)
    {
        $url = explode("?", $url, 2);
        return $url[0];
    }

    /**
     * Get static files
     * @param $fileName
     */
    function returnStatic($fileName)
    {
        $fileName = preg_replace('#/+#', '/', $fileName);
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        switch ($ext) {
            case "png":
            case "jpeg":
                $mimeType = "image/{$ext}";
                break;
            case "svg":
                $mimeType = "image/svg+xml";
                break;
            case "css":
                $mimeType = "text/css";
                break;
            case "pdf":
                $mimeType = "application/pdf";
                break;
            case "js":
                $mimeType = "application/javascript";
                break;
            case "mp4":
                $mimeType = "video/mp4";
                break;
            default:
                $mimeType = "text/html";
                break;
        }

        if (isset($_SERVER['HTTP_RANGE'])) { // do it for any device that supports byte-ranges not only iPhone
            $this->rangeDownload($fileName);
            exit;
        } else {
            header('Content-Type: ' . $mimeType);
            header('Cache-Control: max-age=' . (60 * 60) . ', public');
            header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + (60 * 60))); //1 hour expiry time

            $fh = fopen($fileName, 'r');
            fpassthru($fh);
            fclose($fh);
        }
        exit; //we are done here, file will be delivered
    }

    /**
     * Method to download / stream files
     * @param $file
     */
    function rangeDownload($file)
    {

        $fp = @fopen($file, 'rb');

        $size = filesize($file); // File size
        $length = $size;           // Content length
        $start = 0;               // Start byte
        $end = $size - 1;       // End byte
        // Now that we've gotten so far without errors we send the accept range header
        /* At the moment we only support single ranges.
         * Multiple ranges requires some more work to ensure it works correctly
         * and comply with the spesifications: http://www.w3.org/Protocols/rfc2616/rfc2616-sec19.html#sec19.2
         *
         * Multirange support annouces itself with:
         * header('Accept-Ranges: bytes');
         *
         * Multirange content must be sent with multipart/byteranges mediatype,
         * (mediatype = mimetype)
         * as well as a boundry header to indicate the various chunks of data.
         */
        header("Accept-Ranges: 0-$length");
        // header('Accept-Ranges: bytes');
        // multipart/byteranges
        // http://www.w3.org/Protocols/rfc2616/rfc2616-sec19.html#sec19.2
        if (isset($_SERVER['HTTP_RANGE'])) {

            $c_start = $start;
            $c_end = $end;
            // Extract the range string
            list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
            // Make sure the client hasn't sent us a multibyte range
            if (strpos($range, ',') !== false) {

                // (?) Should this be issued here, or should the first
                // range be used? Or should the header be ignored and
                // we output the whole content?
                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header("Content-Range: bytes $start-$end/$size");
                // (?) Echo some info to the client?
                exit;
            }
            // If the range starts with an '-' we start from the beginning
            // If not, we forward the file pointer
            // And make sure to get the end byte if specified
            if ($range == '-') {
                // The n-number of the last bytes is requested
                $c_start = $size - substr($range, 1);
            } else {

                $range = explode('-', $range);
                $c_start = $range[0];
                $c_end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $size;
            }
            /* Check the range and make sure it's treated according to the specs.
             * http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html
             */
            // End bytes can not be larger than $end.
            $c_end = ($c_end > $end) ? $end : $c_end;
            // Validate the requested range and return an error if it's not correct.
            if ($c_start > $c_end || $c_start > $size - 1 || $c_end >= $size) {

                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header("Content-Range: bytes $start-$end/$size");
                // (?) Echo some info to the client?
                exit;
            }
            $start = $c_start;
            $end = $c_end;
            $length = $end - $start + 1; // Calculate new content length
            fseek($fp, $start);
            header('HTTP/1.1 206 Partial Content');
        }
        // Notify the client the byte range we'll be outputting
        header("Content-Range: bytes $start-$end/$size");
        header("Content-Length: $length");

        // Start buffered download
        $buffer = 1024 * 8;
        while (!feof($fp) && ($p = ftell($fp)) <= $end) {

            if ($p + $buffer > $end) {

                // In case we're only outputtin a chunk, make sure we don't
                // read past the length
                $buffer = $end - $p + 1;
            }
            set_time_limit(0); // Reset time limit for big files
            echo fread($fp, $buffer);
            flush(); // Free up memory. Otherwise large files will trigger PHP's memory limit.
        }

        fclose($fp);

    }

    /**
     * Recursively includes directories
     * @param $dirName
     */
    public function includeDirectory($dirName)
    {
        $d = dir($dirName);
        while (($file = $d->read()) !== false) {
            $pathInfo = pathinfo($file);
            if (isset ($pathInfo["extension"]) && strtolower($pathInfo["extension"]) === "php") {
                $fileNameRoute = realpath($dirName) . DIRECTORY_SEPARATOR . $file;
                require $fileNameRoute;
            } else {
                $fileNameRoute = realpath($dirName) . DIRECTORY_SEPARATOR . $file;
                if (is_dir($fileNameRoute) && $file !== "." && $file !== "..") {
                    $this->includeDirectory($fileNameRoute);
                }
            }
        }
        $d->close();
    }

    /**
     * Check if path matches route path
     * @param string $path URL to parse
     * @param string $routePath Route path
     * @return bool Whether or not they match
     */
    public function matchPath($path, $routePath)
    {
        DebugLog::message("Matching {$path} with {$routePath}", TINA4_DEBUG_LEVEL);
        if ($routePath !== "/") {
            $routePath .= "/";
        }
        preg_match_all($this->pathMatchExpression, $path, $matchesPath);
        preg_match_all($this->pathMatchExpression, $routePath, $matchesRoute);

        $variables = [];
        if (count($matchesPath[1]) == count($matchesRoute[1])) {
            $matching = true;
            foreach ($matchesPath[1] as $rid => $matchPath) {
                if (!empty($matchesRoute[1][$rid]) && strpos($matchesRoute[1][$rid], "{") !== false) {
                    $variables[] = $matchPath;
                } else
                    if (!empty($matchesRoute[1][$rid])) {

                        if ($matchPath !== $matchesRoute[1][$rid]) {
                            $matching = false;
                        }
                    } else

                        if (empty($matchesRoute[1][$rid]) && $rid !== 0) {
                            $matching = false;
                        }
            }

        } else {
            $matching = false; //The path was totally different from the route
        }

        if ($matching) {
            $this->params = $variables;
            DebugLog::message("Found match {$path} with {$routePath}", TINA4_DEBUG_LEVEL);
        } else {
            DebugLog::message("No match for {$path} with {$routePath}", TINA4_DEBUG_LEVEL);
        }
        return $matching;
    }

    /**
     * Get the params
     * @param $response
     * @param false $inlineToRequest
     * @return array
     */
    public function getParams($response, $inlineToRequest = false)
    {
        $request = new Request(file_get_contents("php://input"));

        if ($inlineToRequest) { //Pull the inlineParams into the request by resetting the params
            $request->inlineParams = $this->params;
            $this->params = [];
        }

        $this->params[] = $response;
        $this->params[] = $request; //Check if header is JSON

        return $this->params;
    }

    /**
     * Throws a forbidden message
     */
    function forbidden()
    {
        if (file_exists("./assets/images/403.jpg")) {
            $content = "<img alt=\"403 Forbidden\" src=\"/assets/images/403.jpg\">";
        } else {
            $content = "<img alt=\"403 Forbidden\" src=\"/{$this->subFolder}src/assets/images/403.jpg\">";
        }
        http_response_code(HTTP_FORBIDDEN);
        echo $content;
        die();
    }

    /**
     * Recursively copy the files
     * @param $src
     * @param $dst
     */
    static function recurseCopy($src, $dst)
    {
        if (file_exists($src)) {
            $dir = opendir($src);
            if (!file_exists($dst)) {
                mkdir($dst, $mode = 0755, true);
            }
            while (false !== ($file = readdir($dir))) {
                if (($file != '.') && ($file != '..')) {
                    if (is_dir($src . '/' . $file)) {
                        self::recurseCopy($src . '/' . $file, $dst . '/' . $file);
                    } else {
                        copy($src . '/' . $file, $dst . '/' . $file);
                    }
                }
            }
            closedir($dir);
        }
    }


    /**
     * Convert the output to a string value
     * @return false|mixed|string|null
     */
    function __toString()
    {
        return $this->content;
    }

    /**
     * Returns the annotations for a class
     * @param $class
     * @return false|mixed
     */
    function getClassAnnotations($class)
    {
        try {
            $r = new \ReflectionClass($class);
            $doc = $r->getDocComment();
            preg_match_all('#@(.*?)\n#s', $doc, $annotations);
            return $annotations[1];
        } catch (\ReflectionException $e) {
            DebugLog::message("Could not get annotations for {$class}");
        }
        return false;
    }

    /**
     * Swagger
     * @param string $title
     * @param string $description
     * @param string $version
     * @return false|string
     * @throws \ReflectionException
     */
    function getSwagger($title = "Tina4", $description = "Swagger Documentation", $version = "1.0.0")
    {
        return (new Swagger($this->root, $title, $description, $version))->jsonSerialize();
    }

}