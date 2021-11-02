<?php


namespace Tina4;


class XMLResponse
{
    //XML Serialize taken from Stack Overflow
    //https://stackoverflow.com/questions/137021/php-object-as-xml-document


    /**
     * Initializes the XML header
     * @param array $array
     * @param string $node_name
     * @return string
     */
    public static function generateValidXmlFromArray($array, $nodeName = 'node'): string
    {
        $xml  = self::generateXmlFromArray($array, $nodeName);
        $xml  = str_replace('<node>', '', $xml);
        $xml  = str_replace('</node>', '', $xml);

        return '<?xml version="1.0" encoding="UTF-8" ?>'.$xml;
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