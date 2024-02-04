<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

use Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException;
use Psr\Cache\InvalidArgumentException;
use Twig\Error\LoaderError;

/**
 * Router class which resolves routes in the Tina4 stack
 * @package Tina4
 */
class Router extends Data
{
    use Utility;

    /**
     * @var string Used to check if path matches route path in matchPath()
     */
    protected $pathMatchExpression = "/([a-zA-Z0-9\\%\\ \\! \\-\\}\\{\\.\\_]*)\\//";
    private $params;
    /**
     * @var Config|null
     */
    private $config;

    private $GUID;


    /**
     * Add CORS headers to the response
     * @param $headers
     * @return mixed
     */
    final public function addCORS($headers) {
        if (defined("TINA4_ALLOW_ORIGINS")) {

           if (is_array(TINA4_ALLOW_ORIGINS)) {
               $headers[] = ('Access-Control-Allow-Origin: ' . implode(",", TINA4_ALLOW_ORIGINS));
           } else {
               echo "TINA4_ALLOW_ORIGNS must be declared as an array! Example: <pre>TINA4_ALLOW_ORIGINS=['*']</pre>";
               \Tina4\Debug::message("TINA4_ALLOW_ORIGINS must be an array!" , TINA4_LOG_ERROR);
               die();
           }


        }

        $headers[] = ('Vary: Origin');
        $headers[] = ('Access-Control-Allow-Methods: GET, PUT, POST, PATCH, DELETE, OPTIONS');
        $headers[] = ('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        $headers[] = ('Access-Control-Allow-Credentials: True');

        return $headers;
    }

    /**
     * Resolves to a route that is registered in code and returns the result from that code
     * @param string|null $method
     * @param string|null $url
     * @param Config|null $config
     * @param array $customHeaders Used with different webservers when running headless
     * @param null $customRequest
     * @return RouterResponse|null
     * @throws LoaderError
     * @throws \Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    final public function resolveRoute(?string $method, ?string $url, ?Config $config, array $customHeaders=[], $customRequest=null): ?RouterResponse
    {
        $this->GUID = $this->getGUID();
        $this->config = $config;
        if ($url === "") {
            return null;
        }

        $url = $this->cleanURL($url);

        Debug::message("$this->GUID {$method} - {$url}", TINA4_LOG_DEBUG);
        //Clean the URL

        $content = "Page not found";
        $responseCode = HTTP_NOT_FOUND;

        //FIRST OPTIONS
        if ($routerResponse = $this->handleOptionsMethod($method)) {
            Debug::message("$this->GUID OPTIONS - " . $url, TINA4_LOG_DEBUG);
            return $routerResponse;
        }

        //SECOND STATIC FILES - ONLY GET
        if ($method === TINA4_GET) {
            $fileName = realpath(TINA4_DOCUMENT_ROOT . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . "public" . $url); //The most obvious request
            if (file_exists($fileName) && $routerResponse = $this->returnStatic($fileName)) {
                Debug::message("$this->GUID GET - " . $fileName, TINA4_LOG_DEBUG);
                if (!empty($routerResponse->content)) {
                    return $routerResponse;
                }
            }
        }

        //Initialize only after statics, initialize the twig engine
        $config->callInitFunction();
        try {
            TwigUtility::initTwig($config);
        } catch (LoaderError $e) {
            Debug::message("Could not initialize twig in Tina4PHP Constructor", TINA4_LOG_ERROR);
        }

        //THIRD ROUTING
        if ($routerResponse = $this->handleRoutes($method, $url, $customHeaders, $customRequest)) {
            if ($routerResponse->cached) {
                $this->createCacheResponse(
                    $url.$method,
                    $routerResponse->httpCode,
                    $routerResponse->content,
                    $this->addCORS($routerResponse->headers),
                    "",
                    $routerResponse->contentType
                );
            }
            return $routerResponse;
        }

        //LAST RESORT -> GO LOOKING IN TEMPLATES FOR FILE BY THE NAME
        if ($url === "/") {
            $url .= "index";
        }

        //GO THROUGH ALL THE TEMPLATE INCLUDE LOCATIONS AND SEE IF WE CAN FIND SOMETHING
        Debug::message("$this->GUID URL Last Resort {$method} - {$url}", TINA4_LOG_DEBUG);
        $parseFile = new ParseTemplate($url, "", $this->GUID);

        //TRY FIND THE TINA4 DOCUMENTATION
        if (TINA4_DEBUG) {
            if (empty($parseFile->content) && $parseFile->httpCode === HTTP_NOT_FOUND && $url === "/index") {
                $url = "documentation/index";
                $parseFile = new ParseTemplate($url, "", $this->GUID);
            }
        }

        //Caching of templates
        if (defined("TINA4_CACHE_ON") && TINA4_CACHE_ON) {
            $this->createCacheResponse($url, $parseFile->httpCode, $parseFile->content, $this->addCORS($parseFile->headers), $parseFile->fileName, TEXT_HTML);
        }

        return new RouterResponse($parseFile->content, $parseFile->httpCode, $this->addCORS($parseFile->headers));
    }



    /**
     * Handles an options request
     * @param string $method
     * @return false|RouterResponse
     */
    public function handleOptionsMethod(string $method): ?RouterResponse
    {
        if ($method !== "OPTIONS") {
            return null;
        }

        $headers = [];
        if (in_array("*", TINA4_ALLOW_ORIGINS) || (array_key_exists("HTTP_ORIGIN", $_SERVER) && in_array($_SERVER["HTTP_ORIGIN"], TINA4_ALLOW_ORIGINS))) {
            $headers = $this->addCORS($headers);
            $headers[] = ('Tina4-Debug: '.$this->GUID);
            $httpCode = HTTP_OK;
        } else {
            $httpCode = HTTP_METHOD_NOT_ALLOWED;
        }
        return new RouterResponse("", $httpCode, $headers);
    }

    /**
     * Get static files
     * @param $fileName
     * @return false|RouterResponse
     * @throws \Twig\Error\LoaderError
     */
    public function returnStatic($fileName)
    {
        if (is_dir($fileName)) {
            return false;
        }
        $fileName = preg_replace('#/+#', '/', $fileName);
        $mimeType = self::getMimeType($fileName);

        $headers = [];
        $headers[] = ('Content-Type: ' . $mimeType);
        $headers[] = ('Tina4-Debug: '.$this->GUID);
        $headers[] = ('Cache-Control: max-age=' . (60 * 60 * 60) . ', public');
        $headers[] = ('Pragma: cache');

        if (isset($_SERVER['HTTP_RANGE'])) { // do it for any device that supports byte-ranges not only iPhone
            return $this->rangeDownload($fileName, $headers);
        }

        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        if ($ext !== "twig") {
            $content = file_get_contents($fileName);
        } else {
            $content = "";
        }

        return new RouterResponse($content, HTTP_OK, $headers, false, $mimeType);
    }

    /**
     * Method to download / stream files
     * @param $file
     * @param $headers
     * @return RouterResponse
     */
    public function rangeDownload($file, $headers): RouterResponse
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
        $headers[] = ("Accept-Ranges: 0-$length");
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
                $headers[] = ('HTTP/1.1 416 Requested Range Not Satisfiable');
                $headers[] = ("Content-Range: bytes $start-$end/$size");
                // (?) Echo some info to the client?
                return new RouterResponse("", HTTP_REQUEST_RANGE_NOT_SATISFIABLE, $headers);
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
                $headers[] = ('HTTP/1.1 416 Requested Range Not Satisfiable');
                $headers[] = ("Content-Range: bytes ".($start-$end/$size));
                // (?) Echo some info to the client?
                return new RouterResponse("", HTTP_REQUEST_RANGE_NOT_SATISFIABLE, $headers);
            }
            $start = $c_start;
            $end = $c_end;
            $length = $end - $start + 1; // Calculate new content length
            fseek($fp, $start);
            $headers[] = ('HTTP/1.1 206 Partial Content');
            return new RouterResponse("", HTTP_PARTIAL_CONTENT, $headers);
        }
        // Notify the client the byte range we'll be outputting
        $headers[] = ("Content-Range: bytes " . ($start - $end / $size));
        $headers[] = ("Content-Length: {$length}");

        // Start buffered download
        $buffer = 1024 * 8;
        $content = "";
        while (!feof($fp) && ($p = ftell($fp)) <= $end) {
            if ($p + $buffer > $end) {
                // In case we're only outputting a chunk, make sure we don't
                // read past the length
                $buffer = $end - $p + 1;
            }
            set_time_limit(0); // Reset time limit for big files
            $content .= fread($fp, $buffer);
            flush(); // Free up memory. Otherwise large files will trigger PHP's memory limit.
        }
        fclose($fp);
        return new RouterResponse($content, HTTP_OK, $headers);
    }

    /**
     * Create cache response
     * @param $url
     * @param $httpCode
     * @param $content
     * @param $headers
     * @param $fileName
     * @param $contentType
     * @return bool
     * @throws InvalidArgumentException
     * @throws PhpfastcacheInvalidArgumentException
     */
    public function createCacheResponse($url, $httpCode, $content, $headers, $fileName, $contentType): bool
    {
        global $cache;
        if (empty($cache)) {
            $cache = createCache();
        }

        $key = "url_" . md5($url);
        if (defined("TINA4_DEBUG") && TINA4_DEBUG && (strpos($url, ".twig") !== false || strpos($url, "/public/") !== false)) {
            return false;
        }
        return (new Cache())->set($key, ["url" => $url, "fileName" => $fileName, "httpCode" => $httpCode, "content" => $content, "headers" => $headers, "contentType" => $contentType], 360);
    }

    /**
     * Get cache response
     * @param $url
     * @return array|null
     * @throws \Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getCacheResponse($url): ?array
    {
        global  $cache;
        if (empty($cache)) {
            $cache = createCache();
        }
        $key = "url_" . md5($url);

        $response = (new Cache())->get($key);
        if (defined("TINA4_DEBUG") && TINA4_DEBUG && $response !== null && (strpos($response["fileName"], ".twig") !== false || strpos($response["fileName"], "/public/") !== false)) {
            return null;
        }

        return $response;
    }


    /**
     * Get all variations of X headers
     * @param $headers
     * @return array
     */
    function getXHeaders($headers): array
    {
        //regex for BLAH-X BLAH_X
        $xHeaders = [];
        foreach ($_SERVER as $headerName => $headerValue)
        {
            if (stripos($headerName, "X") !== false) {
                $re = '/.*X[\_|\-](.*)/i';
                preg_match_all($re, $headerName, $matches, PREG_SET_ORDER, 0);

                if (count($matches) > 0)
                {
                    if (isset($matches[0][1]))
                    {
                        $headerName = str_replace("\r", "", str_replace("\n", "", $matches[0][1]));
                        $xHeaders[] = "X-".$headerName.":".$headerValue;
                        $xHeaders[] = "X_".$headerName.":".$headerValue;
                    }

                }
            }
        }
        return array_merge($xHeaders, $headers);
    }

    /**
     * Handles the routes
     * @param $method
     * @param $url
     * @param array $customHeaders
     * @param null $customRequest
     * @return RouterResponse|null
     * @throws \ReflectionException
     */
    public function handleRoutes($method, $url, array $customHeaders=[], $customRequest=null): ?RouterResponse
    {
        Debug::message("$this->GUID Looking in routes for {$method} - {$url}", TINA4_LOG_DEBUG);
        global $arrRoutes;
        $response = new Response();

        if ($this->config->getAuthentication() === null) {
            $auth = new Auth();
            $this->config->setAuthentication($auth);
        }

        $headers = [];
        $headers[] = "Tina4Debug: $this->GUID";
        $headers[] = "FreshToken: ".$this->config->getAuthentication()->getToken(["payload" => $url]);
        $headers = $this->addCORS($headers); //Adding CORS headers on response

        //iterate through the routes

        foreach ($arrRoutes as $rid => $route) {
            $result = null;
            Debug::message("$this->GUID Method match {$method} -> {$route["method"]}", TINA4_LOG_DEBUG);
            if (($route["method"] === $method || $route["method"] === TINA4_ANY) && $this->matchPath($url, $route["routePath"])) {
                if (!empty($route["class"])) {
                    $reflectionClass = new \ReflectionClass($route["class"]);
                    $reflection = $reflectionClass->getMethod($route["function"]);
                } else {
                    $reflection = new  \ReflectionFunction($route["function"]);
                }

                //Get the annotations for the route
                $doc = $reflection->getDocComment();
                $annotations = (new Annotation())->parseAnnotations($doc, "");

                $params = $this->getParams($response, $route["inlineParamsToRequest"], $customRequest);

                //Check for middle ware and pass params to the middle ware for processing
                if (isset($annotations["middleware"])) {
                    $route["middleware"] = explode(",", $annotations["middleware"][0]);
                }

                //Check for no-cache
                if (isset($annotations["no-cache"])) {
                    $route["cached"] = false;
                }

                //Check for cache annotation
                if (isset($annotations["cache"])) {
                    $route["cached"] = true;
                }

                //Determine the content type
                if (isset($annotations["content-type"])) {
                    $route["content-type"] = $annotations["content-type"][0];
                } else {
                    $route["content-type"] = TEXT_HTML;
                }

                if (!isset($route["cached"])) {
                    if (defined("TINA4_CACHE_ON") && TINA4_CACHE_ON ) {
                        $route["cached"] = true;
                    } else {
                        $route["cached"] = false;
                    }
                }


                if (isset($annotations["secure"]) || isset($annotations["security"])) {
                    $params[sizeof($params)-1]->security = isset($annotations["secure"]) ? explode(",", $annotations["secure"][0]) : explode(",", $annotations["security"][0]);
                    if (empty($params[sizeof($params)-1]->security[0])) {
                        $params[sizeof($params)-1]->security[0] = "user";
                    }
                }

                if (isset($route["middleware"])) {
                    global $arrMiddleware;
                    foreach ($route["middleware"] as $middleware) {
                        if (isset($arrMiddleware[Middleware::getName($middleware)])) {
                            \Tina4\Debug::message("Executing " . $middleware);

                            $middleWareFunction = new \ReflectionFunction($arrMiddleware[Middleware::getName($middleware)]["function"]);
                            $middlewareParams = $middleWareFunction->getParameters();

                            //Everything should work based on order of input parameters
                            if (count($params) === count($middlewareParams)) {
                                $response = $arrMiddleware[Middleware::getName($middleware)]["function"](...$params);
                            } else {
                                $refFunction = new \ReflectionFunction($route["function"]);
                                $routeParams = $refFunction->getParameters();

                                $iterateParams = $middlewareParams;
                                $checkParams = $routeParams;

                                $newParams = [];
                                $counterIterateParams = 0;
                                while ($counterIterateParams < count($iterateParams)) {
                                    $found = false;
                                    foreach ($checkParams as $id => $param) {
                                        if ($iterateParams[$counterIterateParams]->getName() === $param->getName()) {
                                            $newParams[] = $params[$id];
                                            $found = true;
                                            break;
                                        }
                                    }

                                    if (!$found) {
                                        $newParams[] = null;
                                    }

                                    $counterIterateParams++;
                                }

                                $response = $arrMiddleware[Middleware::getName($middleware)]["function"](...$newParams);
                            }

                            if (!empty($response)) {
                                $result = $response;
                                break;
                            }
                        }
                    }
                }

                if (function_exists("getallheaders")) {
                    $requestHeaders = getallheaders();
                }  else {
                    $requestHeaders = $customHeaders;
                }

                //Look to see if we are a secure route
                if (empty($result) && isset($annotations["secure"]) || empty($result) && (isset($requestHeaders["Authorization"]) && stripos($requestHeaders["Authorization"], "bearer ") !== false)) {
                    if (isset($requestHeaders["Authorization"]) && $this->config->getAuthentication()->validToken(urldecode($requestHeaders["Authorization"]))) {
                        //call closure with & without params
                        $this->config->setAuthentication(null); //clear the auth
                        $result = $this->getRouteResult($route["class"], $route["function"], $params);
                    } else {
                        //Fail over to formToken, but payload must match the route
                        //CRUD fix for built-in values of form & {id}

                        //Ensure the replaced '/form' is at the end of the route path when removing
                        if(substr($route["routePath"], -5,5) == '/form') {
                            $route["routePath"] = substr_replace($route["routePath"], '', strrpos($route["routePath"], '/form'), 5);
                        }

                        $route["routePath"] = str_replace("/{id}", "", $route["routePath"]);

                        if (isset($_REQUEST["formToken"]) && $route["method"] === TINA4_GET && $this->config->getAuthentication()->validToken($_REQUEST["formToken"]))
                            // && $this->config->getAuthentication()->getPayLoad($_REQUEST["formToken"])["payload"] === $route["routePath"]) @todo fix this
                        {
                            //\Tina4\Debug::message("$this->GUID Matching secure ".$this->config->getAuthentication()->getPayLoad($_REQUEST["formToken"])["payload"]." ".$route["routePath"], TINA4_LOG_DEBUG);
                            $this->config->setAuthentication(null); //clear the auth

                            $result = $this->getRouteResult($route["class"], $route["function"], $params);
                        } else {
                            if ($route["method"] === TINA4_GET) {
                                if ($route["content-type"] === APPLICATION_JSON || $route["content-type"] === APPLICATION_XML) {
                                    return new RouterResponse([], HTTP_FORBIDDEN, $headers, false, $route["content-type"]);
                                } else {
                                    return new RouterResponse("", HTTP_FORBIDDEN, $headers, false, $route["content-type"]);
                                }
                            }
                        }
                    }
                }
                  else
                if ($route["cached"] && empty($result)) {
                    $cacheResult = $this->getCacheResponse($url.$method);
                    if ($cacheResult !== null && $url !== "/cache/clear" && $url !== "/migrate" && $url !== "/migrate/create") {
                        Debug::message("$this->GUID Got cached result for $url", TINA4_LOG_DEBUG);
                        return new RouterResponse(
                            $cacheResult["content"],
                            $cacheResult["httpCode"],
                            array_merge($cacheResult["headers"], $headers),
                            false,
                            $cacheResult["contentType"]
                        );
                    }
                }

                if ($result === null) {
                    if (isset($_REQUEST["formToken"]) && in_array($route["method"], [\TINA4_POST, \TINA4_PUT, \TINA4_PATCH, \TINA4_DELETE], true)) {
                        if ($this->config->getAuthentication() === null) {
                            $auth = new Auth();
                            $this->config->setAuthentication($auth);
                        }
                        //Check for the formToken request variable
                        if (!$this->config->getAuthentication()->validToken($_REQUEST["formToken"])) {
                            return new RouterResponse("", HTTP_FORBIDDEN, $headers);
                        } else {
                            if ($route["cached"]) {
                                $cacheResult = $this->getCacheResponse($url.$method);
                                if ($cacheResult !== null && $url !== "/cache/clear" && $url !== "/migrate" && $url !== "/migrate/create") {
                                    Debug::message("$this->GUID Got cached result for $url", TINA4_LOG_DEBUG);
                                    return new RouterResponse(
                                        $cacheResult["content"],
                                        $cacheResult["httpCode"],
                                        array_merge($cacheResult["headers"], $headers),
                                        false,
                                        $cacheResult["contentType"]
                                    );
                                }
                            }

                            $this->config->setAuthentication(null); //clear the auth
                            $result = $this->getRouteResult($route["class"], $route["function"], $params);
                        }
                    } elseif (!in_array($route["method"], [\TINA4_POST, \TINA4_PUT, \TINA4_PATCH, \TINA4_DELETE], true)) {
                        $this->config->setAuthentication(null); //clear the auth
                        $result = $this->getRouteResult($route["class"], $route["function"], $params);
                    } elseif (!empty($this->config->getAuthentication())) {
                        if ($url === "/git/deploy" || $this->config->getAuthentication()->validToken(json_encode($_REQUEST))) {
                            $result = $this->getRouteResult($route["class"], $route["function"], $params);
                        } else {
                            if ($route["content-type"] === APPLICATION_JSON || $route["content-type"] === APPLICATION_XML) {
                                return new RouterResponse([], HTTP_FORBIDDEN, $headers, false, $route["content-type"]);
                            } else {
                                return new RouterResponse("", HTTP_FORBIDDEN, $headers, false, $route["content-type"]);
                            }
                        }
                    }
                    else {
                        return new RouterResponse("", HTTP_FORBIDDEN, $headers);
                    }
                }

                //check for an empty result
                if ($result === null && !is_array($result) && !is_object($result)) {
                    return new RouterResponse("", HTTP_OK, [], false, $route["content-type"]);
                } elseif (!is_string($result)) {
                    $content = $result["content"];
                    $httpCode = $result["httpCode"];

                    //Find X Headers and add to the $headers variable TINA4_RETURN_X_HEADERS=true which is default
                    if (defined("TINA4_RETURN_X_HEADERS") && TINA4_RETURN_X_HEADERS) {
                        $headers = $this->getXHeaders($headers);
                    }

                    if (!empty($result["customHeaders"]) && is_array($result["customHeaders"])) {
                        $headers = array_merge($headers, $result["customHeaders"]);
                    }

                    return new RouterResponse($content, $httpCode, $headers, $route["cached"], $result["contentType"]);
                }

                break;
            }
        }
        return null;
    }

    /**
     * Match path
     * @param $url
     * @param $routePath
     * @return bool
     */
    public function matchPath($url, $routePath): bool
    {
        $url .= "/";
        $routePath .= "/";

        Debug::message("$this->GUID Matching {$url} -> {$routePath}", TINA4_LOG_DEBUG);
        preg_match_all($this->pathMatchExpression, $url, $matchesPath);
        preg_match_all($this->pathMatchExpression, $routePath, $matchesRoute);
        $matching = true;
        $variables = [];
        $this->params = [];

        if ($url !== $routePath && count($matchesPath[1]) === count($matchesRoute[1]) && count($matchesPath[1]) === 2) {
            return false;
        }

        if (count($matchesPath[1]) === count($matchesRoute[1])) {
            foreach ($matchesPath[1] as $rid => $matchPath) {
                if ($matchPath !== "" && !empty($matchesRoute[1][$rid]) && strpos($matchesRoute[1][$rid], "{") !== false) {
                    $variables[] = urldecode($matchPath);
                } elseif (!empty($matchesRoute[1][$rid])) {
                    if ($matchPath !== $matchesRoute[1][$rid]) {
                        $matching = false;
                        break;
                    }
                } elseif ($matchesRoute[1][$rid] === "" && $rid > 1) {
                    $matching = false;
                    break;
                }
            }
        } else {
            $matching = false; //The path was totally different from the route
        }

        if ($matching) {
            Debug::message("$this->GUID Matching {$url} with {$routePath}", TINA4_LOG_DEBUG);
            $this->params = $variables;
        } else {
            $matching = false;
        }

        return $matching;
    }

    /**
     * Get the params
     * @param $response
     * @param $inlineToRequest
     * @param $customRequest
     * @return array
     */
    public function getParams($response, $inlineToRequest = null, $customRequest=null): array
    {
        $request = new Request(file_get_contents("php://input"), $customRequest);

        if ($inlineToRequest) { //Pull the inlineParams into the request by resetting the params
            $request->inlineParams = $this->params;
            $this->params = [];
        }

        $this->params[] = $response;
        $this->params[] = $request; //Check if header is JSON

        return $this->params;
    }

    /**
     * @param $class
     * @param $method
     * @param $params
     * @return false|mixed
     * @throws \ReflectionException
     */
    public function getRouteResult($class, $method, $params): ?array
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

        return call_user_func_array($method, $params);
    }
}
