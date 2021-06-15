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
    public function __invoke($content, $httpCode = 200, $contentType = null): ?array
    {
        if (empty($contentType) && !empty($_SERVER) && isset($_SERVER["CONTENT_TYPE"])) {
            $contentType = $_SERVER["CONTENT_TYPE"];
        } else if (empty($contentType)) {
            $contentType = TEXT_HTML;
        }

        if (!empty($content) || (is_array($content) || is_object($content))) {
            switch ($contentType) {
                case APPLICATION_JSON:
                    $content = json_encode($content, JSON_THROW_ON_ERROR);
                    break;
                case APPLICATION_XML:
                    $content = self::generateValidXmlFromArray($content);
                    break;
                default:
                    if (is_object($content) && $content instanceof HTMLElement) {
                        $content .= "";
                    }

                    //Try determine the  content type
                    if (!is_string($content) && (is_object($content) || is_array($content))) {
                        $contentType = APPLICATION_JSON;
                        $content = json_encode($content, JSON_THROW_ON_ERROR);
                    } else {
                        $contentType = TEXT_HTML;
                    }
                    break;
            }
        }
        return ["contentType" => "Content-Type: {$contentType}", "content" => $content, "httpCode" => $httpCode];
    }

    //XML Serialize taken from Stack Overflow
    //https://stackoverflow.com/questions/137021/php-object-as-xml-document


    /**
     * Initializes the XML header
     * @param $array
     * @param string $node_block
     * @param string $node_name
     * @return string
     */
    public static function generateValidXmlFromArray($array, $node_block = 'nodes', $node_name = 'node'): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" ?>';

        $xml .= '<' . $node_block . '>';
        $xml .= self::generateXmlFromArray($array, $node_name);
        $xml .= '</' . $node_block . '>';

        return $xml;
    }

    /**
     * Creates XML from an array
     * @param $array
     * @param $node_name
     * @return string
     */
    private static function generateXmlFromArray($array, $node_name): string
    {
        $xml = '';
        if (is_array($array) || is_object($array)) {
            foreach ($array as $key => $value) {
                if (is_numeric($key)) {
                    $key = $node_name;
                }
                $xml .= '<' . $key . '>' . self::generateXmlFromArray($value, $node_name) . '</' . $key . '>';
            }
        } else {
            $xml = htmlspecialchars($array, ENT_QUOTES);
        }
        return $xml;
    }
}
