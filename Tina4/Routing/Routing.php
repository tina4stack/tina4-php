<?php

namespace Tina4;
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Class Routing
 * @package Tina4
 */
class Routing
{
    use Utility;

    protected $auth;
    protected $params;
    protected $content;
    protected $root;
    protected $subFolder = ""; //Sub folder when stack runs under a directory

    /**
     * @var string Type of method
     */
    protected $method;


    /**
     * @var string Used to check if path matches route path in matchPath()
     */
    protected $pathMatchExpression = "/([a-zA-Z0-9\\%\\ \\! \\-\\}\\{\\.\\_]*)\\//";

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
    public function __construct($root = "", $subFolder = "", $urlToParse = "", $method = "", Config $config = null, $suppressed = false)
    {

        if (!empty($root)) {
            $_SERVER["DOCUMENT_ROOT"] = $root;
        }

        if (empty($subFolder)) {
            $subFolder = $this->getSubFolder();
        }

        $this->root = $root;

        if ($config !== null && $config->getAuthentication() !== null) {
            $this->auth = $config->getAuthentication();
        } else {
            $this->auth = new Auth($_SERVER["DOCUMENT_ROOT"]);
        }

        if (!empty($subFolder)) {
            $this->subFolder = $subFolder . "/";
            if (!defined("TINA4_BASE_URL")) {
                define("TINA4_BASE_URL", $subFolder);
            }
        } else if (!defined("TINA4_BASE_URL")) {
            define("TINA4_BASE_URL", "");
        }

        if (TINA4_DEBUG) {
            Debug::message("TINA4: URL to parse " . $urlToParse, TINA4_DEBUG_LEVEL);
        }

        global $arrRoutes;

        if (!$suppressed) {
            if ($method === "OPTIONS") {
                if (in_array("*", TINA4_ALLOW_ORIGINS) || (array_key_exists("HTTP_ORIGIN", $_SERVER) && in_array($_SERVER["HTTP_ORIGIN"], TINA4_ALLOW_ORIGINS))) {
                    if (array_key_exists("HTTP_ORIGIN", $_SERVER)) {
                        header('Access-Control-Allow-Origin: ' . $_SERVER["HTTP_ORIGIN"]);
                    } else {
                        header('Access-Control-Allow-Origin: ' . implode(",", TINA4_ALLOW_ORIGINS));
                    }
                    header('Vary: Origin');
                    header('Access-Control-Allow-Methods: GET, PUT, POST, PATCH, DELETE, OPTIONS');
                    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
                    header('Access-Control-Allow-Credentials: true');
                }


                http_response_code(200);
                $this->content = "";
                die();
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

            //Fix for sub folders and static files
            if (!empty($this->subFolder) && (strpos($urlToParse, ".twig") === false || strpos($urlToParse, ".php") === false) ) {
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
            if ($urlToParse !== "/" && file_exists($root . $urlToParse) &&  !is_dir($root . $urlToParse)) {
                $this->returnStatic($root . $urlToParse);
            } else if (defined("TINA4_TEMPLATE_LOCATIONS_INTERNAL"))
            {
                foreach (TINA4_TEMPLATE_LOCATIONS_INTERNAL as $tid => $templateLocation) {
                    if (is_array($templateLocation) && isset($templateLocation["path"])) {
                        $templateLocation = $templateLocation["path"];
                    }
                    Debug::message($root . $templateLocation . $urlToParse, TINA4_DEBUG_LEVEL);
                    if (file_exists($root . $templateLocation . $urlToParse) && !is_dir($root . DIRECTORY_SEPARATOR . $templateLocation . $urlToParse)) {
                        $this->returnStatic($root . $templateLocation . DIRECTORY_SEPARATOR . $urlToParse);
                    }
                }
            }

            if ($urlToParse !== "/") {
                $urlToParse .= "/";
                $urlToParse = str_replace("//", "/", $urlToParse);
            }

            $this->content = "";
            $this->method = $method;

            Debug::message("Root: {$root}", TINA4_DEBUG_LEVEL);
            Debug::message("URL: {$urlToParse}", TINA4_DEBUG_LEVEL);
            Debug::message("Method: {$method}", TINA4_DEBUG_LEVEL);

            if (defined("TINA4_ROUTE_LOCATIONS_INTERNAL")) {
                //include routes in routes folder
                foreach (TINA4_ROUTE_LOCATIONS_INTERNAL as $rid => $route) {
                    if (file_exists($route)) {
                        $this->includeDirectory($route);
                    } else
                        if (file_exists(getcwd() . "/" . $route)) {
                            $this->includeDirectory(getcwd() . "/" . $route);
                        } else if (TINA4_DEBUG) {
                            Debug::message("TINA4: " . getcwd() . "/" . $route . " not found!", TINA4_DEBUG_LEVEL);
                        }
                }
            }

            //determine what should be outputted and if the route is found
            $matched = false;

            $result = null;
            //iterate through the routes
            foreach ($arrRoutes as $rid => $route) {
                $result = "";
                if ($this->matchPath($urlToParse, $route["routePath"]) && ($route["method"] === $this->method || $route["method"] === TINA4_ANY)) {
                    //Look to see if we are a secure route
                    if (!empty($route["class"])) {
                        $reflectionClass =  new \ReflectionClass($route["class"]);
                        $reflection = $reflectionClass->getMethod($route["function"]);
                    } else {
                        $reflection = new \ReflectionFunction($route["function"]);
                    }
                    $doc = $reflection->getDocComment();
                    preg_match_all('#@(.*?)(\r\n|\n)#s', $doc, $annotations);

                    $params = $this->getParams($response, $route["inlineParamsToRequest"]);

                    if (in_array("secure", $annotations[1], true)) {
                        $headers = getallheaders();

                        if (isset($headers["Authorization"]) && $this->auth->validToken($headers["Authorization"])) {
                            //call closure with & without params
                            $this->auth = null; //clear the auth
                            $result = $this->getRouteResult($route["class"], $route["function"], $params);
                        } else {
                            $this->forbidden();
                        }

                        $matched = true;
                        break;
                    }

                    if (isset($_REQUEST["formToken"]) && in_array($route["method"], [\TINA4_POST, \TINA4_PUT, \TINA4_PATCH, \TINA4_DELETE], true)) {
                        //Check for the formToken request variable
                        if (!$this->auth->validToken($_REQUEST["formToken"])) {
                            $this->forbidden();
                        } else {
                            $this->auth = null; //clear the auth
                            $result = $this->getRouteResult($route["class"], $route["function"], $params);
                        }
                    } else if (!in_array($route["method"], [\TINA4_POST, \TINA4_PUT, \TINA4_PATCH, \TINA4_DELETE], true)) {
                        $this->auth = null; //clear the auth

                        $result = $this->getRouteResult($route["class"], $route["function"], $params);
                    } else {
                        $this->forbidden();
                    }

                    //check for an empty result
                    if (empty($result) && !is_array($result) && !is_object($result)) {
                        $result = "";
                    } else if (!is_string($result)) {
                        $result = json_encode($result);
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

                    Debug::message("TINA4: Variables\n" . implode("\n", $variables), TINA4_DEBUG_LEVEL);
                }


                $this->content .= new ParseTemplate($root, $fileName, get_defined_vars(), $this->subFolder);
            } else {
                $this->content = $result;
            }
        }


    }

    /**
     * Clean URL by splitting string at "?" to get actual URL
     * @param string $url URL to be cleaned that may contain "?"
     * @return mixed Part of the URL before the "?" if it existed
     */
    public function cleanURL(string $url):string
    {
        $url = explode("?", $url, 2);
        return $url[0];
    }

    /**
     * Get static files
     * @param $fileName
     */
    public function returnStatic($fileName): void
    {
        if (is_dir($fileName))
        {
            exit;
        }
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
        }

        header('Content-Type: ' . $mimeType);
        header('Cache-Control: max-age=' . (60 * 60) . ', public');
        header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + (60 * 60))); //1 hour expiry time

        $fh = fopen($fileName, 'rb');
        fpassthru($fh);
        fclose($fh);
        exit; //we are done here, file will be delivered
    }

    /**
     * Method to download / stream files
     * @param $file
     */
    public function rangeDownload($file): void
    {

        $fp = @fopen($file, 'rb');

        $size = filesize($file); // File size
        $length = $size;           // Content length
        $start = 0;               // Start byte
        $end = $size - 1;       // End byte
        // Now that we've gotten so far without errors we send the accept range header
        /* At the moment we only support single ranges.
         * Multiple ranges requires some more work to ensure it works correctly
         * and comply with the specifications: http://www.w3.org/Protocols/rfc2616/rfc2616-sec19.html#sec19.2
         *
         * Multi-range support announces itself with:
         * header('Accept-Ranges: bytes');
         *
         * Multi-range content must be sent with multipart/byteranges mediatype,
         * (mediatype = mimetype)
         * as well as a boundry header to indicate the various chunks of data.
         */
        header("Accept-Ranges: 0-$length");
        // header('Accept-Ranges: bytes');
        // multipart/byteranges
        // http://www.w3.org/Protocols/rfc2616/rfc2616-sec19.html#sec19.2
        if (isset($_SERVER['HTTP_RANGE'])) {

            $c_end = $end;
            // Extract the range string
            [, $range] = explode('=', $_SERVER['HTTP_RANGE'], 2);
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
            if ($range === '-') {
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
     * Check if path matches route path
     * @param string $path URL to parse
     * @param string $routePath Route path
     * @return bool Whether or not they match
     */
    public function matchPath(string $path, string $routePath): bool
    {

        if ($routePath !== "/") {
            $routePath .= "/";
        }
        preg_match_all($this->pathMatchExpression, $path, $matchesPath);
        preg_match_all($this->pathMatchExpression, $routePath, $matchesRoute);

        $variables = [];
        if (count($matchesPath[1]) === count($matchesRoute[1])) {
            $matching = true;
            foreach ($matchesPath[1] as $rid => $matchPath) {
                if (!empty($matchesRoute[1][$rid]) && strpos($matchesRoute[1][$rid], "{") !== false) {
                    $variables[] = urldecode($matchPath);
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
            Debug::message("Matching {$path} with {$routePath}", TINA4_DEBUG_LEVEL);
            $this->params = $variables;
            Debug::message("Found match {$path} with {$routePath}", TINA4_DEBUG_LEVEL);
        }

        return $matching;
    }

    /**
     * Get the params
     * @param $response
     * @param false $inlineToRequest
     * @return array
     */
    public function getParams($response, $inlineToRequest = false): array
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
    public function forbidden(): void
    {
        if (file_exists("./assets/images/403.png")) {
            $content = "<img alt=\"403 Forbidden\" src=\"./assets/images/403.png\">";
        } else {
            $content = "<img alt=\"403 Forbidden\" src=\"/{$this->subFolder}src/assets/images/403.png\">";
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
    public static function recurseCopy($src, $dst): void
    {
        if (file_exists($src)) {
            $dir = opendir($src);
            if (!file_exists($dst) && !mkdir($dst, $mode = 0755, true) && !is_dir($dst)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $dst));
            }
            while (false !== ($file = readdir($dir))) {
                if (($file !== '.') && ($file !== '..')) {
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
    public function __toString()
    {
        if (TINA4_DEBUG) {
            $debugInfo = Debug::render();
        } else {
            $debugInfo = "";
        }
        return $this->content . $debugInfo;
    }

    /**
     * Swagger
     * @param string $title
     * @param string $description
     * @param string $version
     * @return false|string
     * @throws \ReflectionException
     */
    public function getSwagger($title = "Tina4", $description = "Swagger Documentation", $version = "1.0.0"): string
    {
        return  (new Swagger($this->root, $title, $description, $version, $this->subFolder));
    }

    /**
     * @param $class
     * @param $method
     * @param $params
     * @return false|mixed
     * @throws \ReflectionException
     */
    public function getRouteResult ($class, $method, $params) : string
    {

        if (!empty($class)) {
            $methodCheck = new \ReflectionMethod($class, $method);

            if ($methodCheck->isStatic()) {
                return call_user_func_array([$class, $method], $params);
            }

            $classInstance = new \ReflectionClass($class);
            $class = $classInstance->newInstance();
            return call_user_func_array([$class, $method], $params);
        }


        return  call_user_func_array($method, $params);
    }

}