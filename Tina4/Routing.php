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

class Routing
{
    private $params;
    private $content;
    private $debug = false;
    private $method;
    private $pathMatchExpression = "/([a-zA-Z0-9\\ \\! \\-\\}\\{\\.]*)\\//";

    function __construct($root = "", $urlToParse = "", $method = "")
    {
        $this->debug = TINA4_DEBUG;

        if ($this->debug) {
            error_log("TINA4: URL to parse " . $urlToParse);
        }
        global $arrRoutes;

        if (in_array("*", TINA4_ALLOW_ORIGINS) || in_array($_SERVER["HTTP_ORIGIN"], TINA4_ALLOW_ORIGINS) ) {
            if (key_exists("HTTP_ORIGIN", $_SERVER) ) {
                header('Access-Control-Allow-Origin: ' . $_SERVER["HTTP_ORIGIN"]);
            }
            header('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
            header('Access-Control-Allow-Credentials: true');
        }

        if ($method === "OPTIONS") {
            http_response_code(200);
            $this->content = "";
            return true;
        }

        //Gives back things in an orderly fashion for API responses
        $response = function ($content, $code = 200, $contentType = null) {
            http_response_code($code);

            if (!empty($content) && (is_array($content) || is_object($content))) {
                header("Content-Type: application/json");
                $content = json_encode($content);
            } else
                if (!empty($content)) {
                    header("Content-Type: {$contentType}");
                }

            return $content;
        };

        //Initialize debugging
        if ($this->debug) {
            echo "<PRE>";
        }

        $urlToParse = $this->cleanURL($urlToParse);

        //Generate a filename just in case the routing doesn't find anything
        if ($urlToParse === "/") {
            $fileName = "index";
        } else {
            $fileName = $urlToParse;
        }

        //Clean up twig extensions
        $fileName = str_replace (".twig", "", $fileName);



        // if requested file is'nt a php file
        if (file_exists($root . $urlToParse) && $urlToParse !== "/") {
            $ext = pathinfo($urlToParse, PATHINFO_EXTENSION);
            $mimeType = mime_content_type($root . $urlToParse);

            if ($ext === "svg") {
                $mimeType = "image/svg+xml";
            }
            else
                if ($ext === "css") {
                    $mimeType = "text/css";
                }
            header('Content-Type: ' . $mimeType);
            header('Cache-Control: max-age='.(60 * 60).', public');
            header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (60 * 60))); //1 hour expiry time

            $fh = fopen($root . $urlToParse, 'r');
            fpassthru($fh);
            fclose($fh);
            exit; //we are done here, file will be delivered
        }


        if ($urlToParse !== "/") {
            $urlToParse .= "/";
            $urlToParse = str_replace("//", "/", $urlToParse);
        }

        $this->content = "";
        $this->method = $method;
        $this->debug("Root: {$root}");
        $this->debug("URL: {$urlToParse}");
        $this->debug("Method: {$method}");

        //include routes in routes folder
        foreach (TINA4_ROUTE_LOCATIONS as $rid => $route) {
            if (file_exists(getcwd() . "/" . $route)) {

                $d = dir(getcwd() . "/" . $route);


                while (($file = $d->read()) !== false) {
                    if ($file != "." && $file != "..") {
                        $fileNameRoute = realpath(getcwd() . "/" . $route) . "/" . $file;
                        require_once $fileNameRoute;
                    }
                }
                $d->close();
            } else {
                if ($this->debug) {
                    error_log("TINA4: " . getcwd() . "/" . $route . " not found!");
                }
            }
        }

        //determine what should be outputted and if the route is found
        $matched = false;

        if ($this->debug) {
            print_r($arrRoutes);
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

                if (in_array("secure", $annotations[1])) {
                    $headers = getallheaders();

                    if (isset($headers["Authorization"]) && Auth::validToken($headers["Authorization"])) {
                        //call closure with & without params
                        $result = call_user_func_array($route["function"], $this->getParams($response));
                    } else {
                        $result = $response("Not authorized", 401);
                    }

                    $matched = true;
                    break;
                } else {
                    //call closure with & without params
                    $result = call_user_func_array($route["function"], $this->getParams($response));
                }

                //check for an empty result
                if (empty($result)) {
                    $result = "";
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
            }

            if ($this->debug) {
                error_log("TINA4: Variables\n" . print_r(get_defined_vars(), 1));
            }
            $this->content .= new ParseTemplate($root, $fileName, get_defined_vars());
        } else {
            $this->content = $result;
        }


        //end debugging
        if ($this->debug) {
            echo "</PRE>";
        }
    }

    function cleanURL($url)
    {
        $url = explode("?", $url, 2);
        return $url[0];
    }

    function debug($msg)
    {
        if ($this->debug) {
            echo date("\nY-m-d h:i:s - ") . $msg . "\n";
        }
    }

    function matchPath($path, $routePath)
    {
        $this->debug("Matching {$path} with {$routePath}");
        if ($routePath !== "/") {
            $routePath .= "/";
        }
        preg_match_all($this->pathMatchExpression, $path, $matchesPath);
        preg_match_all($this->pathMatchExpression, $routePath, $matchesRoute);

        if (count($matchesPath[1]) == count($matchesRoute[1])) {
            $matching = true;
            $variables = [];

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
            $this->debug("Found match {$path} with {$routePath}");
        } else {
            $this->debug("No match for {$path} with {$routePath}");
        }
        return $matching;
    }

    function getParams($response)
    {
        $this->params[] = $response;
        $this->params[] = json_decode(file_get_contents("php://input")); //TODO: check if header is JSON
        return $this->params;
    }

    static function recurseCopy($src, $dst)
    {
        $dir = opendir($src);
        @mkdir($dst);
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

    //convert the output to a string value

    function __toString()
    {
        return $this->content;
    }

    /**
     * Convert a multi-dimensional array into a single-dimensional array.
     * @author Sean Cannon, LitmusBox.com | seanc@litmusbox.com
     * @param  array $array The multi-dimensional array.
     * @return array
     */
    function array_flatten($array) {
        if (!is_array($array)) {
            return false;
        }
        $result = array();
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result = array_merge($result, array_flatten($value));
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }


    function getClassAnnotations($class)
    {
        $r = new ReflectionClass($class);
        $doc = $r->getDocComment();
        preg_match_all('#@(.*?)\n#s', $doc, $annotations);
        return $annotations[1];
    }

    //swagger
    function getSwagger($title = "Tina4", $description = "Swagger Documentation", $version = "1.0.0")
    {
        global $arrRoutes;

        $paths = (object)[];

        foreach ($arrRoutes as $arId => $route) {
            $method = strtolower($route["method"]);
            //echo $method;

            $reflection = new \ReflectionFunction($route["function"]);

            $doc = $reflection->getDocComment();
            preg_match_all('#@(.*?)(\r\n|\n)#s', $doc, $annotations);



            $summary = "None";
            $description = "None";
            $tags = [];
            $queryParams = [];



            $addParams = [];
            $example = (object)[];
            foreach ($annotations[0] as $aid => $annotation) {
                preg_match_all('/^(@[a-zA-Z]*)([\w\s,]*)$/m', $annotation, $matches, PREG_SET_ORDER, 0);

                if (count($matches) > 0) {
                    $matches = $matches[0];
                } else {
                    $matches = null;
                }

                if (!empty($matches[2])) {
                    $matches[2] = trim ($matches[2]);
                }

                if (!empty($matches)) {
                    if ($matches[1] === "@summary") {
                        $summary = $matches[2];
                    } else
                        if ($matches[1] === "@description") {
                            $description = str_replace("\n", "", $matches[2]);
                        } else
                            if ($matches[1] === "@tags") {
                                $tags = explode(",", $matches[2]);
                                foreach ($tags as $tid => $tag) {
                                    $tags[$tid] = str_replace("\n", "", $tag);
                                }
                            } else
                                if ($matches[1] === "@queryParams") {
                                    $queryParams = explode(",", $matches[2]);
                                } else
                                    if ($matches[1] === "@example") {
                                        eval(' if (class_exists("' . trim(str_replace("\n", "", $matches[2])) . '")) { $example = (new ' . trim(str_replace("\n", "", $matches[2])) . '()); if (method_exists($example, "getTableData")) { $example = (object)$example->getTableData(); } else {  $example = json_decode (json_encode($example)); }  } else {$example = (object)[];} ');
                                    }
                                    else
                                        if ($matches[1] === "@secure") {
                                            $addParams[] = (object)["name" => "Authorization", "in" => "header", "required" => false];
                                        }

                }
            }

            $arguments = $reflection->getParameters();

            $params = json_decode(json_encode($arguments));
            $params = array_merge($params, $addParams);

            $propertyIn = "in";
            $propertyType = "type";
            $propertyName = "name";

            foreach ($params as $pid => $param) {

                if (!isset($params[$pid]->{$propertyIn})) {
                    $params[$pid]->{$propertyIn} = "path";
                }

                if (!isset($params[$pid]->{$propertyType})) {
                    $params[$pid]->{$propertyType} = "string";
                }

                if ($params[$pid]->name === "response" || $params[$pid]->name === "request") {
                    unset($params[$pid]);
                }
            }

            foreach ($queryParams as $pid => $param) {
                $newParam = (object)[$propertyName => $param, $propertyIn => "query", $propertyType => "string" ];
                array_push($params, $newParam);
            }

            $params = json_decode(json_encode(array_values($params)));

            if ($description !== "None") {
                if ($method === "any") {
                    $paths->{$route["routePath"]} = (object)[
                        "get" => (object)[
                            "tags" => $tags,
                            "summary" => $summary,
                            "description" => $description,
                            "produces" => ["application/json", "html/text"],
                            "parameters" => $params,
                            "responses" => (object)[
                                "200" => (object)["description" => "Success"],
                                "400" => (object)["description" => "Failed"]

                            ]

                        ],
                        "delete" => (object)[
                            "tags" => $tags,
                            "summary" => $summary,
                            "description" => $description,
                            "produces" => ["application/json", "html/text"],
                            "parameters" => $params,
                            "responses" => (object)[
                                "200" => (object)["description" => "Success"],
                                "400" => (object)["description" => "Failed"]

                            ]

                        ],
                        "put" => (object)[
                            "tags" => $tags,
                            "summary" => $summary,
                            "description" => $description,
                            "consumes" => "application/json",
                            "produces" => ["application/json", "html/text"],
                            "parameters" => array_merge($params, [(object)["name" => "request", "in" => "body", "schema" => (object)["type" => "object", "example" => $example]]]),
                            "responses" => (object)[
                                "200" => (object)["description" => "Success"],
                                "400" => (object)["description" => "Failed"]

                            ]

                        ],
                        "post" => (object)[
                            "tags" => $tags,
                            "summary" => $summary,
                            "description" => $description,
                            "consumes" => "application/json",
                            "produces" => ["application/json", "html/text"],
                            "parameters" => array_merge($params, [(object)["name" => "request", "in" => "body", "schema" => (object)["type" => "object", "example" => $example]]]),
                            "responses" => (object)[
                                "200" => (object)["description" => "Success"],
                                "400" => (object)["description" => "Failed"]

                            ]

                        ]

                    ];
                } else {


                    if ($method === "post" || $method === "patch") {
                        $params = array_merge($params, [(object)["name" => "request", "in" => "body", "schema" => (object)["type" => "object", "example" => $example]]]);
                    }
                    if (!empty($paths->{$route["routePath"]})) {
                        $paths->{$route["routePath"]}->{$method} = (object)[
                            "tags" => $tags,
                            "summary" => $summary,
                            "description" => $description,
                            "produces" => ["application/json", "html/text"],
                            "parameters" => $params,
                            "responses" => (object)[
                                "200" => (object)["description" => "Success"],
                                "400" => (object)["description" => "Failed"]

                            ]];
                    } else {
                        $paths->{$route["routePath"]} = (object)[
                            "{$method}" => (object)[
                                "tags" => $tags,
                                "summary" => $summary,
                                "description" => $description,
                                "produces" => ["application/json", "html/text"],
                                "parameters" => $params,
                                "responses" => (object)[
                                    "200" => (object)["description" => "Success"],
                                    "400" => (object)["description" => "Failed"]

                                ]

                            ]
                        ];
                    }
                }

            }
        }


        $swagger = [
            "swagger" => "2.0",
            "host" => $_SERVER["HTTP_HOST"],
            "info" => [
                "title" => $title,
                "description" => $description,
                "version" => $version
            ],
            "security" => ["ApiKeyAuth" => "", "Oauth2" => ["read", "write"]],
            "basePath" => '/',
            "paths" => $paths

        ];

        header("Content-Type: application/json");
        return json_encode($swagger, JSON_UNESCAPED_SLASHES);
    }

}
