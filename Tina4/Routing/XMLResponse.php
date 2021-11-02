<?php


namespace Tina4;


class XMLResponse
{
    //XML Serialize taken from Stack Overflow
    //https://stackoverflow.com/questions/137021/php-object-as-xml-document

    /**
     * Taken from stack over flow
     * https://stackoverflow.com/questions/4554233/how-check-if-a-string-is-a-valid-xml-with-out-displaying-a-warning-in-php
     * @param $content
     * @return bool
     */
    public static function isValidXml($content): bool
    {
        $content = trim($content);
        if (empty($content)) {
            return false;
        }
        //html go to hell!
        if (stripos($content, '<!DOCTYPE html>') !== false) {
            return false;
        }

        libxml_use_internal_errors(true);
        simplexml_load_string($content);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        return empty($errors);
    }


    /**
     * Initializes the XML header
     * @param array $array
     * @param string $node_name
     * @return string
     */
    public static function generateValidXmlFromArray($array, $nodeName = 'node'): string
    {
        if (!self::isValidXml($array) && (is_object($array) || is_array($array))) {
            $xml  = self::generateXmlFromArray($array, $nodeName);
        } else {
            $xml = $array;
        }

        //Nodes are the xml wrappers for unknown objects or arrays
        $xml  = str_replace('<node>', '', $xml);
        $xml  = str_replace('</node>', '', $xml);

        return $xml;
    }

    /**
     * Creates XML from an array
     * @param $array
     * @param $node_name
     * @return string
     */
    public static function generateXmlFromArray($array, $nodeName): string
    {
        $xml = '';
        if (is_array($array) || is_object($array)) {
            foreach ($array as $key => $value) {
                if (is_numeric($key)) {
                    $key = $nodeName;
                }
                $xml .= '<' . $key . '>' . self::generateXmlFromArray($value, $nodeName) . '</' . $key . '>';
            }
        } else {
            $xml = htmlspecialchars($array, ENT_QUOTES);
        }
        return $xml;
    }


}