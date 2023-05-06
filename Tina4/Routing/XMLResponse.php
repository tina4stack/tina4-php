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
     * @param string|object|array $content
     * @param string $nodeName
     * @return string
     */
    public static function generateValidXmlFromArray($content, $nodeName = 'value'): string
    {
        if ((is_object($content) || is_array($content))) {
            $xml  = self::generateXmlFromArray($content, $nodeName, is_object($content) ? get_class($content): "Array");

            //Nodes are the xml wrappers for unknown objects or arrays
            //$xml  = str_replace('<'.$nodeName.'>', '', $xml);
            //$xml  = str_replace('</'.$nodeName.'>', '', $xml);
        } else {
            if (self::isValidXml($content)) {
                $xml = $content;
            }
            else {
                $xml = "<errors>";
                libxml_use_internal_errors(true);
                simplexml_load_string($content);
                $errors = libxml_get_errors();
                foreach ($errors as $error) {
                    $xml .= "<error>{$error->message}</error>";
                }
                $xml .= "</errors>";
            }
        }

        return $xml;
    }

    /**
     * Creates XML from an array
     * @param $array
     * @param $nodeName
     * @param null $className
     * @return string
     */
    public static function generateXmlFromArray($array, $nodeName, $className=null): string
    {
        $xml = '';
        if (is_array($array) || is_object($array)) {
            foreach ($array as $key => $value) {
                if (is_numeric($key)) {
                    $key = $nodeName." id=\"{$key}\"";
                }
                //Allows for xml attributes, strips out for the closing key
                $keyName = explode(" ", $key, 2);
                $xml .= '<' . $key . '>' . self::generateXmlFromArray($value, $nodeName) . '</' . $keyName[0] . '>';
            }
        } else {
            if (!empty($array)) {
                $xml = htmlspecialchars($array, ENT_QUOTES);
            }
        }

        if (!empty($className))
        {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'."<$className>$xml</$className>";
        }
        return $xml;
    }


}