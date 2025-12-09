<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Consume REST API interfaces with little code
 * @package Tina4
 */
class Api
{
    /**
     * @var string|null Base url for the Api
     */
    public ?string $baseURL;

    /**
     * @var string Auth header , normally basic auth or token auth
     */
    public string $authHeader;

    /**
     * @var bool Set to true to ignore SSL validation
     */
    public bool $ignoreSSLValidation;

    /**
     * @var array An array of custom headers to make things happen!
     */
    private array $customHeaders;

    /**
     * @var string Username for basic auth
     */
    private string $username;

    /**
     * @var string Password for basic auth
     */
    private string $password;

    /**
     * API constructor.
     * @param string|null $baseURL
     * @param string $authHeader Example - Authorization: Bearer AFD-22323-FD
     * @tests tina4
     *   assert ("https://the-one-api.dev/v2", "Authorization: Bearer 123456") === null,"Could not initialize API"
     */
    public function __construct(?string $baseURL="", string $authHeader = "")
    {
        if (!empty($baseURL)) {
            $this->baseURL = $baseURL;
        }

        if (!empty($authHeader)) {
            $this->authHeader = $authHeader;
        }

        $this->ignoreSSLValidation = false;
    }

    /**
     * Adds custom headers for use in the request
     * @param $headers
     * @return void
     */
    public function addCustomHeaders($headers) : void
    {
        $this->customHeaders = $headers;
    }

    /**
     * Sets the username and password for basic auth
     * @param $username
     * @param $password
     * @return void
     */
    public function setUsernamePassword($username, $password) : void
    {
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Sends a request to the specified API
     * @param string $restService
     * @param string $requestType
     * @param null $body
     * @param string $contentType
     * @param array $customHeaders
     * @param array $curlOptions
     * @return array tests tina4
     * tests tina4
     *   assert ("/book")['docs'][0]['name'] === "The Fellowship Of The Ring", "API Get request"
     *   assert ("/book")['docs'][1]['name'] !== "The Fellowship Of The Ring", "API Get request"
     *   assert is_array("/book") === true, "This is not an array"
     * @documentation api#tips
     * @tips
     * @code-example
     */
    final public function sendRequest(string $restService = "", string $requestType = "GET", $body = null, string $contentType = "application/json", array $customHeaders=[], array $curlOptions=[]): array
    {
        try {
            $headers = [];
            if (!in_array("Accept:", $customHeaders)) {
                $headers[] = "Accept: " . $contentType;
            }

            if (!in_array("Accept-Charset:", $customHeaders)) {
                $headers[] = "Accept-Charset: utf-8, *;q=0.8";
            }

            if (!empty($this->authHeader)) {
                $headers = [...$headers, ...explode(",", $this->authHeader)];
            }

            if (!empty($this->username) && !empty($this->password)) {
                $headers[] = "Authorization: Basic " . base64_encode("{$this->username}:{$this->password}");
            }

            if (!empty($body) && !empty($contentType)) {
                $headers[] = "Content-Type: " . $contentType;
            }

            if (!empty($this->customHeaders)) {
                $headers = [...$headers, ...$this->customHeaders];
            }

            if (!empty($customHeaders)) {
                $headers = [...$headers, ...$customHeaders];
            }

            $curlRequest = curl_init($this->baseURL . $restService);

            if ($this->ignoreSSLValidation) {
                curl_setopt($curlRequest, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($curlRequest, CURLOPT_SSL_VERIFYHOST, false);
            }
            else {
                curl_setopt($curlRequest, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($curlRequest, CURLOPT_SSL_VERIFYHOST, 2);
            }

            curl_setopt($curlRequest, CURLOPT_CUSTOMREQUEST, $requestType);
            curl_setopt($curlRequest, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curlRequest, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curlRequest, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($curlRequest, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

            if (!empty($curlOptions)) {
                foreach ($curlOptions as $option => $optionValue) {
                    curl_setopt($curlRequest, $option, $optionValue);
                }
            }

            if (!empty($body)) {
                if ((is_object($body) || is_array($body)) && $contentType === "application/json" ) {
                    $body = json_encode($body);
                }
                $headers[] = "Content-Length: " . strlen($body);
                curl_setopt($curlRequest, CURLOPT_POSTFIELDS, $body);
            }

            $curlResult = curl_exec($curlRequest); //execute the Curl request
            $curlInfo = curl_getinfo($curlRequest); //Assign the response to a variable
            $curlError = curl_error($curlRequest);
            curl_close($curlRequest);

            if ($response = json_decode($curlResult, true)) {
                return  ["error" => $curlError, "info" => $curlInfo, "body" => $response, "httpCode" => $curlInfo['http_code'], "headers" => $headers];
            } else {
                return ["error" => $curlError, "info" => $curlInfo, "body" => $curlResult, "httpCode" => $curlInfo['http_code'], "headers" => $headers];
            }
        } catch (\Exception $error) {
            return ["error" => $error->getMessage(), "info" => null, "body" => null, "httpCode" => null, "headers" => $headers];
        }
    }
}
