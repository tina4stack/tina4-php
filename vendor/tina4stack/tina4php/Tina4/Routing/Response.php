<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Used in apis and routes to return a response
 * @package Tina4
 */
class Response
{
    /**
     * Performs task when invoked
     * @param mixed $content Content of which may be a simple string to show on screen or even a parsed twig template using renderTemplate()
     * @param int $httpCode Response code
     * @param null $contentType Type of content to be responded, default is "text/html"
     * @return array
     * @throws \JsonException
     * @example examples\exampleRouteGetAdd.php For simple response
     */
    public function __invoke($content, int $httpCode = 200, $contentType = null): ?array
    {
        if (empty($contentType) && !empty($_SERVER) && isset($_SERVER["CONTENT_TYPE"])) {
            $contentType = $_SERVER["CONTENT_TYPE"];
        }

        if (empty($contentType)) {
            $contentType = TEXT_HTML;
        }

        if (!empty($content) || (is_array($content) || is_object($content))) {
            switch ($contentType) {
                case APPLICATION_XML:
                    $content = XMLResponse::generateValidXmlFromArray($content);
                break;
                case APPLICATION_JSON:
                default:
                    if ($content instanceof HTMLElement) {
                        $content .= "";
                    }

                    //Try to determine the  content type
                    if (!is_string($content) && (is_object($content) || is_array($content))) {
                        $contentType = APPLICATION_JSON;
                        $content = json_encode($content, JSON_THROW_ON_ERROR);
                    }

                break;
            }
        }

        return ["contentType" => "Content-Type: {$contentType}", "content" => $content, "httpCode" => $httpCode];
    }

}
