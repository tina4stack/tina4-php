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
    public $baseURL;
    /**
     * @var string Auth header , normally basic auth or token auth
     */
    public $authHeader;

    /**
     * API constructor.
     * @param ?string $baseURL
     * @param string $authHeader Example - Authorization: Bearer AFD-22323-FD
     * @tests tina4
     *   assert ("https://the-one-api.dev/v2", "Authorization: Bearer 123456") === null,"Could not initialize API"
     */
    public function __construct(?string $baseURL, string $authHeader = "")
    {
        if (!empty($baseURL)) {
            $this->baseURL = $baseURL;
        }
        $this->authHeader = $authHeader;
    }

    /**
     * Sends a request to the specified API
     * @param string $restService
     * @param string $requestType
     * @param string|null $body
     * @param string $contentType
     * @param array $customHeaders
     * @param array $curlOptions
     * @return array|mixed
     * tests tina4
     *   assert ("/book")['docs'][0]['name'] === "The Fellowship Of The Ring", "API Get request"
     *   assert ("/book")['docs'][1]['name'] !== "The Fellowship Of The Ring", "API Get request"
     *   assert is_array("/book") === true, "This is not an array"
     */
    final public function sendRequest(string $restService = "", string $requestType = "GET", ?string $body = null, string $contentType = "*/*", $customHeaders=[], $curlOptions=[]): array
    {
        try {
            $headers = [];
            $headers[] = "Accept: " . $contentType;
            $headers[] = "Accept-Charset: utf-8, *;q=0.8";

            if (!empty($this->authHeader)) {
                $headers[] = $this->authHeader;
            }

            if (!empty($body)) {
                $headers[] = "Content-Type: " . $contentType;
            }

            if (!empty($customHeaders)) {
                $headers = array_merge($headers, $customHeaders);
            }

            $curlRequest = curl_init($this->baseURL . $restService);

            curl_setopt($curlRequest, CURLOPT_CUSTOMREQUEST, $requestType);
            curl_setopt($curlRequest, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curlRequest, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curlRequest, CURLOPT_FOLLOWLOCATION, 1);

            if (!empty($curlOptions)) {
                foreach ($curlOptions as $option => $optionValue) {
                    curl_setopt($curlRequest, $option, $optionValue);
                }
            }

            if (!empty($body)) {
                curl_setopt($curlRequest, CURLOPT_POSTFIELDS, $body);
            }

            $curlResult = curl_exec($curlRequest); //execute the Curl request
            $curlInfo = curl_getinfo($curlRequest); //Assign the response to a variable
            $curlError = curl_error($curlRequest);
            curl_close($curlRequest);

            if ($response = json_decode($curlResult, true)) {
                return  ["error" => $curlError, "info" => $curlInfo, "body" => $response, "httpCode" => $curlInfo['http_code']];
            } else {
                return ["error" => $curlError, "info" => $curlInfo, "body" => $curlResult, "httpCode" => $curlInfo['http_code']];
            }
        } catch (\Exception $error) {
            return ["error" => $error->getMessage(), "info" => null, "body" => null, "httpCode" => null];
        }
    }
}