<?php

namespace Tina4;



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
                    if (is_object($content) && get_class($content) === "Tina4\HTMLElement") {
                        $content .= "";
                    }
                    //Try determine the  content type
                    if (!is_string($content) && (is_object($content) || is_array($content))) {
                        $contentType = APPLICATION_JSON;
                        $content = json_encode($content);
                    } else {
                        $contentType = TEXT_HTML;
                    }
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
