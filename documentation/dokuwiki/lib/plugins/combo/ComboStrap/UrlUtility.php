<?php


namespace ComboStrap;



class UrlUtility
{

    /**
     * Extract the value of a property
     * @param $URL
     * @param $propertyName
     * @return string - the value of the property
     */
    public static function getPropertyValue($URL, $propertyName)
    {
        $parsedQuery = parse_url($URL,PHP_URL_QUERY);
        $parsedQueryArray = [];
        parse_str($parsedQuery,$parsedQueryArray);
        return $parsedQueryArray[$propertyName];
    }

    /**
     * Validate URL
     * Allows for port, path and query string validations
     * @param string $url string containing url user input
     * @return   boolean     Returns TRUE/FALSE
     */
    public static function isValidURL($url)
    {
        // of preg_match('/^https?:\/\//',$url) ? from redirect plugin
        return preg_match('|^http(s)?://[a-z0-9-]+(.[a-z0-9-]+)*(:[0-9]+)?(/.*)?$|i', $url);
    }

    /**
     * PHP is blocking and fsockopen also.
     * Don't use it in a page rendering flow
     * https://segment.com/blog/how-to-make-async-requests-in-php/
     * @param $url
     */
    function sendGetRequest($url)
    {
        $parts=parse_url($url);
        $fp = fsockopen($parts['host'],isset($parts['port'])?$parts['port']:80,$errno, $errstr, 30);
        $out = "GET ".$parts['path'] . "?" . $parts['query'] . " HTTP/1.1\r\n";
        $out.= "Host: ".$parts['host']."\r\n";
        $out.= "Content-Length: 0"."\r\n";
        $out.= "Connection: Close\r\n\r\n";

        fwrite($fp, $out);
        fclose($fp);
    }

}
