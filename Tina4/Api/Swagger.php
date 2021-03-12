<?php

namespace Tina4;
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * @todo This is a work in progress and may contain many gaps
 * Class Swagger
 * Attempts to document in OpenAPI format and return a JSON file
 * @package Tina4
 */
class Swagger implements \JsonSerializable
{
    public $root;
    public $subFolder;
    public $swagger = [];

    /**
     * Swagger constructor.
     * @param null $root
     * @param string $title
     * @param string $apiDescription
     * @param string $version
     * @param string $subFolder - for when the swagger is running under a sub folder
     * @throws \ReflectionException
     */
    public function __construct($root = null, $title = "Open API", $apiDescription = "API Documentation", $version = "1.0.0", $subFolder="/")
    {

        global $arrRoutes;


        if (empty($this->root)) {
            $this->root = $_SERVER["DOCUMENT_ROOT"];
        }

        $this->subFolder = $subFolder;

        $paths = (object)[];



        foreach ($arrRoutes as $arId => $route) {

            $route["routePath"] = str_replace("//", "/", $this->subFolder.$route["routePath"]);

            $method = strtolower($route["method"]);
            //echo $method;

            if (!empty($route["class"])) {
                $reflectionClass =  new \ReflectionClass($route["class"]);
                $reflection = $reflectionClass->getMethod($route["function"]);
            } else {
                $reflection = new \ReflectionFunction($route["function"]);
            }

            $doc = $reflection->getDocComment();
            preg_match_all('#@(.*?)(\r\n|\n)#s', $doc, $annotations);




            $summary = "None";
            $description = "None";
            $tags = [];
            $queryParams = [];


            $addParams = [];
            $security = [];
            $example = (object)[];
            foreach ($annotations[0] as $aid => $annotation) {
                preg_match_all('/^(@[a-zA-Z]*)([\w\s,\W]*)$/m', $annotation, $matches, PREG_SET_ORDER, 0);
                if (count($matches) > 0) {
                    $matches = $matches[0];
                } else {
                    $matches = null;
                }

                if (!empty($matches[2])) {
                    $matches[2] = trim($matches[2]);
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
                                if ($matches[1] === "@queryParams" || $matches[1] === "@params") {
                                    $queryParams = explode(",", $matches[2]);
                                } else
                                    if ($matches[1] === "@example") {
                                        eval('  if ( class_exists("' . trim(str_replace("\n", "", "\\" . $matches[2])) . '")) { $example = (new ' . trim(str_replace("\n", "", $matches[2])) . '()); if (method_exists($example, "asArray")) { $example = (object)$example->asArray(); } else { $example = json_decode (json_encode($example)); }  } else { $example = (object)[];} ');
                                    } else
                                        if ($matches[1] === "@secure") {
                                            $security[] = (object)["bearerAuth" => []];
                                        }

                }
            }

            if ($summary === "None" && $description !== "None") {
                $summary = $description;
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
                $newParam = (object)[$propertyName => $param, $propertyIn => "query", $propertyType => "string"];
                array_push($params, $newParam);
            }

            $params = json_decode(json_encode(array_values($params)));

            if ($description !== "None") {
                if ($method === "any") {
                    $paths->{$route["routePath"]} = (object)[
                        "get" => $this->getSwaggerEntry($tags, $summary, $description, ["application/json", "html/text"], $security, $params, null, (object)[
                            "200" => (object)["description" => "Success"],
                            "400" => (object)["description" => "Failed"]

                        ]),
                        "delete" => $this->getSwaggerEntry($tags, $summary, $description, ["application/json", "html/text"], $security, $params, null, (object)[
                            "200" => (object)["description" => "Success"],
                            "400" => (object)["description" => "Failed"]

                        ]),
                        "put" => $this->getSwaggerEntry($tags, $summary, $description, ["application/json", "html/text"], $security, $params, $example, (object)[
                            "200" => (object)["description" => "Success"],
                            "400" => (object)["description" => "Failed"]

                        ]),
                        "post" => $this->getSwaggerEntry($tags, $summary, $description, ["application/json", "html/text"], $security, $params, $example, (object)[
                            "200" => (object)["description" => "Success"],
                            "400" => (object)["description" => "Failed"]

                        ])
                    ];
                } else {
                    if (!empty($paths->{$route["routePath"]})) {
                        $paths->{$route["routePath"]}->{$method} = $this->getSwaggerEntry($tags, $summary, $description, ["application/json", "html/text"], $security, $params, $example, (object)[
                            "200" => (object)["description" => "Success"],
                            "400" => (object)["description" => "Failed"]

                        ]);
                    } else {
                        $paths->{$route["routePath"]} = (object)[
                            "{$method}" => $this->getSwaggerEntry($tags, $summary, $description, ["application/json", "html/text"], $security, $params, $example, (object)[
                                "200" => (object)["description" => "Success"],
                                "400" => (object)["description" => "Failed"]

                            ])];
                    }
                }

            }
        }

        $this->swagger = [
            "openapi" => "3.0.0",
            "host" => $_SERVER["HTTP_HOST"],
            "info" => [
                "title" => $title,
                "description" => $apiDescription,
                "version" => $version
            ],
            "components" => ["securitySchemes" => ["bearerAuth" => ["type" => "http", "scheme" => "bearer", "bearerFormat" => "JWT"]]],
            "basePath" => $this->subFolder,
            "paths" => $paths

        ];



    }

    public function getSwaggerEntry($tags, $summary, $description, $produces, $security, $params, $example, $responses)
    {
        $entry = (object)[
            "tags" => $tags,
            "summary" => $summary,
            "description" => $description,
            "produces" => $produces,
            "parameters" => $params,
            "requestBody" => (object)[
                "description" => "Example Object",
                "required" => true,
                "content" => [
                    "application/json" => [
                        "schema" => ["type" => "object", "example" => $example]
                    ]
                ]
            ],
            "security" => $security,
            "responses" => $responses
        ];

        //Get rid of request body if the example is empty
        if (empty((array)$example)) {
            unset($entry->requestBody);
        }

        return $entry;
    }

    public function __toString(): string
    {
        header("Content-Type: application/json");
        return json_encode($this->swagger, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Specify data which should be serialized to JSON
     * @link https://php.net/manual/en/jsonserializable.jsonserialize.php
     * @return mixed data which can be serialized by <b>json_encode</b>,
     * which is a value of any type other than a resource.
     * @since 5.4.0
     */
    public function jsonSerialize()
    {
        return $this->swagger;
    }
}