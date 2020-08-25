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
            if (empty($contentType)) {
                $contentType = TEXT_HTML;
            }
        }
        http_response_code($httpCode);

        if (!empty($content) && (is_array($content) || is_object($content))) {

            switch ($contentType) {
                case APPLICATION_JSON:
                    $content = json_encode($content);
                    break;
                case APPLICATION_XML:
                    $content = self::generateValidXmlFromArray($content);
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

    //XML Serialize taken from Stack Overflow
    //https://stackoverflow.com/questions/137021/php-object-as-xml-document


    /**
     * Initializes the XML header
     * @param $array
     * @param string $node_block
     * @param string $node_name
     * @return string
     */
    public static function generateValidXmlFromArray($array, $node_block='nodes', $node_name='node') {
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
    private static function generateXmlFromArray($array, $node_name) {
        $xml = '';

        if (is_array($array) || is_object($array)) {
            foreach ($array as $key=>$value) {
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
