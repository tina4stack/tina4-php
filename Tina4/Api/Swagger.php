<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Document methods in OpenAPI format using annotations and return a JSON file
 * @package Tina4
 * @todo This is a work in progress and may contain many gaps
 */
class Swagger implements \JsonSerializable
{
    public $root;
    public $annotation;
    public $subFolder;
    public $swagger = [];
    public $ormObjects = []; //define which objects should be returned in the schema

    /**
     * Swagger constructor.
     * @param null $root
     * @param string $title
     * @param string $apiDescription
     * @param string $version
     * @param string $subFolder - for when the swagger is running under a sub folder
     * @throws \ReflectionException
     */
    public function __construct($root = null, $title = "Open API", $apiDescription = "API Documentation", $version = "1.0.0", $subFolder = "/")
    {

        global $arrRoutes;

        if (empty($this->root)) {
            $this->root = $_SERVER["DOCUMENT_ROOT"];
        }

        $this->subFolder = $subFolder;
        $this->annotation = new Annotation();

        $paths = (object)[];

        foreach ($arrRoutes as $arId => $route) {
            $route["routePath"] = str_replace("//", "/", $this->subFolder . $route["routePath"]);

            $method = strtolower($route["method"]);
            if (!empty($route["class"])) {
                $reflectionClass = new \ReflectionClass($route["class"]);
                $reflection = $reflectionClass->getMethod($route["function"]);
            } else {
                $reflection = new \ReflectionFunction($route["function"]);
            }

            $doc = $reflection->getDocComment();
            $annotations = $this->annotation->parseAnnotations($doc);

            if (!empty($reflection) && !empty($reflection->getClosureScopeClass()) && $reflection->getClosureScopeClass()->name === "Tina4\Crud") {
                $reflectionCaller = new \ReflectionFunction($route["caller"]["args"][2]);
                $crudDocumentation = $reflectionCaller->getDocComment();
                $crudAnnotations = $this->annotation->parseAnnotations($crudDocumentation, "");

                foreach ($annotations as $annotation => $annotationValue) {

                    if (!empty($crudAnnotations[$annotation])) {
                        $annotations[$annotation][0] = str_replace("{" . $annotation . "}", $crudAnnotations[$annotation][0], $annotationValue[0]);
                        $annotations[$annotation][0] = str_replace("{path}", $route["caller"]["args"][0], $annotations[$annotation][0]);
                    } else {
                        if ($annotation === "example") {
                            $annotations[$annotation][0] = (new \ReflectionClass($route["caller"]["args"][1]))->getShortName();
                        } else {
                            unset($annotations[$annotation]);
                        }
                    }

                }
            }

            $summary = "None";
            $description = "None";
            $tags = [];
            $queryParams = [];
            $security = [];
            $example = null;
            foreach ($annotations as $annotationName => $annotationValue) {
                $annotationValue = $annotationValue[0];

                if ($annotationName === "summary") {
                    $summary = $annotationValue;
                }
                    else
                if ($annotationName === "description")
                {
                    $description = str_replace("\n", "", $annotationValue);
                }
                    else
                if ($annotationName === "tags")
                {
                    $tags = explode(",", $annotationValue);
                    foreach ($tags as $tid => $tag) {
                        $tags[$tid] = trim(str_replace("\n", "", $tag));
                    }
                }
                    else
                if ($annotationName === "params" || $annotationName === "queryParams")
                {
                    $queryParams = explode(",", $annotationValue);
                }
                    else
                if ($annotationName === "example") {
                    $this->ormObjects[] = trim(str_replace("\n", "", "\\" . $annotationValue));
                    $example = [];
                    $className = trim(str_replace("\n", "", "\\" . $annotationValue));

                    if (class_exists($className)) {
                        $exampleObject = (new $className);

                        if (method_exists($exampleObject, "asArray")) {
                            $example["data"] = (object)$exampleObject->asArray();
                            $fields = $exampleObject->getFieldDefinitions();
                            $properties = (object)[];
                            if ($fields !== null) {
                                foreach ($fields as $field) {
                                    $properties->{$field->fieldName} = (object)["type" => $field->dataType];
                                }
                            }
                            $example["properties"] = $properties;
                        } else {
                            $example["data"] = (object)json_decode(json_encode($exampleObject));
                            $example["properties"] = (object)[];
                        }
                    } else {
                        $className = substr($className, 1);
                        $example["data"] = json_decode($className);
                        $example["properties"] = (object)[];
                    }
                }

                if ($annotationName === "@secure" || $method != "GET") {
                    $security = [(object)["bearerAuth" => []]];
                }

            }

            if ($summary === "None" && $description !== "None") {
                $summary = $description;
            }

            $regEx = '/\{(.*)\}/mU';
            preg_match_all($regEx, $route["routePath"], $matches, PREG_SET_ORDER, 0);
            $params = [];

            $propertyIn = "in";
            $propertyType = "type";
            $propertyName = "name";

            foreach ($matches as $match) {
                $params[] = (object)[ $propertyName => $match[1]];
            }

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
                        "get" => $this->getSwaggerEntry("get", $tags, $summary, $description, ["application/json", "html/text"], $security, $params, null, (object)[
                            "200" => (object)["description" => "Success"],
                            "400" => (object)["description" => "Failed"]

                        ]),
                        "delete" => $this->getSwaggerEntry("delete", $tags, $summary, $description, ["application/json", "html/text"], $security, $params, null, (object)[
                            "200" => (object)["description" => "Success"],
                            "400" => (object)["description" => "Failed"]

                        ]),
                        "put" => $this->getSwaggerEntry("put", $tags, $summary, $description, ["application/json", "html/text"], $security, $params, $example, (object)[
                            "200" => (object)["description" => "Success"],
                            "400" => (object)["description" => "Failed"]

                        ]),
                        "post" => $this->getSwaggerEntry("post", $tags, $summary, $description, ["application/json", "html/text"], $security, $params, $example, (object)[
                            "200" => (object)["description" => "Success"],
                            "400" => (object)["description" => "Failed"]

                        ])
                    ];
                } else {
                    if ($method === "get" && !empty($example)) {
                        if (is_array($example)) {
                            $schema = (object)["type" => "object", "properties" => $example["properties"], "example" => $example["data"]];
                        } else {
                            $schema = (object)["type" => "object", "example" => $example];
                        }

                        $responseContent = (object)["application/json" => $schema];
                        $response = (object)[
                            "200" => (object)["description" => "Success", "content" => $responseContent],
                            "400" => (object)["description" => "Failed"]
                        ];

                    } else {
                        $response = (object)[
                            "200" => (object)["description" => "Success"],
                            "400" => (object)["description" => "Failed"]
                        ];
                    }

                    if (!empty($paths->{$route["routePath"]})) {
                        $paths->{$route["routePath"]}->{$method} = $this->getSwaggerEntry($method, $tags, $summary, $description, ["application/json", "html/text"], $security, $params, $example, $response);
                    } else {
                        $paths->{$route["routePath"]} = (object)[(string)($method) => $this->getSwaggerEntry($method, $tags, $summary, $description, ["application/json", "html/text"], $security, $params, $example, $response)];
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

    /**
     * Get Swagger Entry
     * @param $method
     * @param $tags
     * @param $summary
     * @param $description
     * @param $produces
     * @param $security
     * @param $params
     * @param $example
     * @param $responses
     * @return object
     */
    public function getSwaggerEntry($method, $tags, $summary, $description, $produces, $security, $params, $example, $responses): object
    {
        if (is_array($example) && !empty($example)) {
            $schema = (object)["type" => "object", "properties" => $example["properties"], "example" => $example["data"]];
        } else {
            $schema = (object)["type" => "object", "example" => $example];
        }

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
                        "schema" => $schema
                    ]
                ]
            ],
            "security" => $security,
            "responses" => $responses
        ];

        //Get rid of request body if the example is empty
        if (empty((array)$example) || $method === "get") {
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
    public function jsonSerialize(): array
    {
        return $this->swagger;
    }
}
