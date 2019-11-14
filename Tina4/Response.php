<?php

namespace Tina4;

define("HTTP_OK", 200);
define("HTTP_CREATED", 201);
define("HTTP_NO_CONTENT", 204);
define("HTTP_BAD_REQUEST", 400);
define("HTTP_UNAUTHORIZED", 401);
define("HTTP_FORBIDDEN", 403);
define("HTTP_NOT_FOUND", 404);
define("HTTP_METHOD_NOT_ALLOWED", 405);
define("HTTP_RESOURCE_CONFLICT", 409);
define("HTTP_MOVED_PERMANENTLY", 301);
define("HTTP_INTERNAL_SERVER_ERROR", 500);
define("HTTP_NOT_IMPLEMENTED", 501);


define("TEXT_HTML", "text/html");
define("TEXT_PLAIN", "text/plain");
define("APPLICATION_JSON", "application/json");
define("APPLICATION_XML", "application/xml");

/**
 * Class Response Used in apis to return a response
 * @package Tina4
 */
class Response
{
    /**
     * Performs task when invoked
     * @param mixed $content Content of which may be a simple string to show on screen or even a parsed twig template using \Tina4\renderTemplate()
     * @param int $httpCode Response code
     * @param null $contentType Type of content to be responded, default is "text/html"
     * @return false|string
     * @example examples\exampleRouteGetAdd.php For simple response
     */
    function __invoke($content, $httpCode = 200, $contentType = null)
    {
        if (empty($contentType) && !empty($_SERVER) && isset($_SERVER["CONTENT_TYPE"])) {
            $contentType = $_SERVER["CONTENT_TYPE"];
        } else {
            $contentType = TEXT_HTML;
        }
        http_response_code($httpCode);
        if (!empty($content) && (is_array($content) || is_object($content))) {

            switch ($contentType) {
                case APPLICATION_JSON:
                    $content = json_encode($content);
                    break;
                case APPLICATION_XML:

                    break;
                default:
                    $contentType = APPLICATION_JSON;
                    $content = json_encode($content);
                    break;
            }

            header("Content-Type: {$contentType}");

        } else
            if (!empty($content)) {
                header("Content-Type: {$contentType}");
            }
        return $content;
    }
}
