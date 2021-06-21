<?php


namespace Tina4;


class XMLResponse
{
    //XML Serialize taken from Stack Overflow
    //https://stackoverflow.com/questions/137021/php-object-as-xml-document


    /**
     * Initializes the XML header
     * @param array $array
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