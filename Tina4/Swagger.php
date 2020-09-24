<?php

namespace Tina4;
/**
 * @todo This is a work in progress
 * Class Swagger
 * @package Tina4
 */
class Swagger implements \JsonSerializable
{
    public $root;
    public $swagger = [];

    function __construct($root = null, $title = "Open API", $description = "API Documentation", $version = "1.0.0")
    {
        global $arrRoutes;
        if (empty($this->root)) {
            $this->root = $_SERVER["DOCUMENT_ROOT"];
        }

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
            $security = [];
            $example = (object)[];
            foreach ($annotations[0] as $aid => $annotation) {
                preg_match_all('/^(@[a-zA-Z]*)([\w\s,]*)$/m', $annotation, $matches, PREG_SET_ORDER, 0);

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
                                if ($matches[1] === "@queryParams") {
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
                        "get" => (object)[
                            "tags" => $tags,
                            "summary" => $summary,
                            "description" => $description,
                            "produces" => ["application/json", "html/text"],
                            "parameters" => $params,
                            "security" => $security,
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
                            "security" => $security,
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
                            "security" => $security,
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
                            "security" => $security,
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
                            "security" => $security,
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
                                "security" => $security,
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

        $this->swagger = [
            "openapi" => "3.0.0",
            "host" => $_SERVER["HTTP_HOST"],
            "info" => [
                "title" => $title,
                "description" => $description,
                "version" => $version
            ],
            "components" => ["securitySchemes" => ["bearerAuth" => ["type" => "http", "scheme" => "bearer", "bearerFormat" => "JWT"]]],
            "basePath" => '/',
            "paths" => $paths

        ];

    }

    function __toString()
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