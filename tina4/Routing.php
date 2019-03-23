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

class Routing
{
    private $session;
    private $request;
    private $parser;
    private $params;
    private $content;
    private $debug = false;
    private $method;
    private $pathMatchExpression = "/([a-zA-Z0-9\\ \\! \\-\\}\\{\\.]*)\\//";

    function debug($msg)
    {
        if ($this->debug) {
            echo date("\nY-m-d h:i:s - ") . $msg . "\n";
        }
    }

    function matchPath($path, $routePath)
    {
        $this->debug("Matching {$path} with {$routePath}");
        if ($routePath !== "/") $routePath .= "/";
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

    function cleanURL($url)
    {
        $url = explode("?", $url, 2);
        return $url[0];
    }

    function __construct($root = "", $urlToParse = "", $method = "")
    {
        global $arrRoutes;

        //Gives back things in an orderly fashion for API responses
        $response = function ($content, $code = 200, $contentType = null) {
            http_response_code($code);

            if (!empty($content) && (is_array($content) || is_object($content))) {
                header( "Content-Type: application/json" );
                $content = json_encode($content);
            }
                else
            if (!empty($content)) {
                header("Content-Type: {$contentType}");
            }

            return $content;
        };

        //Initialize debugging
        if ($this->debug) {
            echo "<PRE>";
        }

        //Generate a filename just in case the routing doesn't find anything
        if ($urlToParse === "/") {
            $fileName = "index.html";
        } else {
            $ext = pathinfo($urlToParse, PATHINFO_EXTENSION);
            if (empty($ext)) {
                $fileName = $urlToParse . ".html";
                $fileName = str_replace("/.html", "/index.html", $fileName);
            } else {
                $fileName = $urlToParse;
            }
        }

        $urlToParse = $this->cleanURL($urlToParse);

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
            $d = dir(getcwd() . "/" . $route);

            while (($file = $d->read()) !== false) {
                if ($file != "." && $file != "..") {
                    $fileNameRoute = realpath(getcwd() . "/" . $route) . "/" . $file;
                    require_once $fileNameRoute;
                }
            }
            $d->close();
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
                $reflection = new ReflectionFunction($route["function"]);
                $doc = $reflection->getDocComment();
                preg_match_all('#@(.*?)\n#s', $doc, $annotations);
                
                if (in_array("secure", $annotations[1])) {
                    $headers = getallheaders();
                    if (isset($headers["Authorization"]) && Auth::validToken($headers["Authorization"]) ) {
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
                $fileName = "index.html";
            }

            $this->content .= new ParseTemplate($root, $fileName);
        } else {
            $this->content = $result;
        }



        //end debugging
        if ($this->debug) {
            echo "</PRE>";
        }
    }

    //convert the output to a string value
    function __toString()
    {
        return $this->content;
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

            $reflection = new ReflectionFunction($route["function"]);
            $doc = $reflection->getDocComment();
            preg_match_all('#@(.*?)\n#s', $doc, $annotations);

            //print_r ($annotations);

            $summary = "None";
            $description = "None";
            $tags = [];
            foreach ($annotations[0] as $aid => $annotation) {

                preg_match_all('/^(@[a-z]*) ([\w\s]*)$/m', $annotation, $matches, PREG_SET_ORDER, 0);


                if (count($matches) > 0) {
                    $matches = $matches[0];
                } else {
                    $matches = null;
                }

                $example = (object)[];

                if (!empty($matches)) {
                    if ($matches[1] === "@summary") {
                        $summary = $matches[2];
                    } else
                        if ($matches[1] === "@description") {
                            $description = $matches[2];
                        } else
                    if ($matches[1] === "@tags") {
                        $tags = explode(",", $matches[2]);
                    }  else
                    if ($matches[1] === "@example") {
                        eval(' if (class_exists("'.trim(str_replace("\n", "", $matches[2])).'")) { $example = (object)(new '.trim(str_replace("\n", "", $matches[2])).'())->getTableData();} else {$example = (object)[];} ');
                    }

                }
            }


            $arguments = $reflection->getParameters();

            $params = json_decode(json_encode($arguments));
            $propertyIn = "in";
            $propertyType = "type";
            foreach ($params as $pid => $param) {
                $params[$pid]->{$propertyIn} = "path";
                $params[$pid]->{$propertyType} = "string";

                if ($params[$pid]->name === "response" || $params[$pid]->name === "request") {
                    unset($params[$pid]);
                }
            }

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
                        $paths->{$route["routePath"]}->{$method} =(object)[
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