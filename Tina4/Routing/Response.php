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
     * @param array $customHeaders
     * @return array|null
     * @throws \JsonException
     * @example examples\exampleRouteGetAdd.php For simple response
     */
    public function __invoke($content, int $httpCode = 200, $contentType = null, array $customHeaders = []): ?array
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
        return ["contentType" => $contentType, "content" => $content, "httpCode" => $httpCode, "customHeaders" => $customHeaders];
    }

    /**
     * Renders a template using \Tina4\renderTemplate and returns a response array.
     *
     * @param string $template The template file to render (e.g., "index.twig").
     * @param array $data Data to pass to the template.
     * @param int $httpCode HTTP response code (default: 200).
     * @param array $customHeaders Custom headers to include in the response.
     * @return array|null The response array suitable for Tina4 routing.
     * @throws \Exception
     */
    public function render(string $template, array $data = [], int $httpCode = 200, array $customHeaders = []): ?array
    {
        $content = \Tina4\renderTemplate($template, $data);
        return $this->__invoke($content, $httpCode, TEXT_HTML, $customHeaders);
    }

    /**
     * Streams a file from a specified location, defaulting to 'src/public'.
     *
     * @param string $file The relative path to the file.
     * @param string $location The base directory for relative paths (default: 'src/public').
     * @return array|null The response array suitable for Tina4 routing, or null on error.
     * @throws \Exception
     */
    public function file(
        string $file,
        string $location = 'src/public'
    ): ?array {
        $fullPath = $file;
        if (!file_exists($fullPath) && strpos($file, DIRECTORY_SEPARATOR) !== 0) {
            $fullPath = rtrim($location, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file;
        }
        $fullPath = realpath($fullPath);

        if (!$fullPath || !file_exists($fullPath)) {
            return $this->__invoke('File not found', 404, TEXT_HTML);
        }

        $contentType = mime_content_type($fullPath) ?: 'application/octet-stream';

        $content = file_get_contents($fullPath);

        return $this->__invoke($content, 200, $contentType);
    }

    /**
     * Returns a redirect response to the specified URL.
     *
     * @param string $url The URL to redirect to.
     * @param int $httpCode HTTP response code (default: 302 for temporary redirect)
     * @return array The response array suitable for Tina4 routing.
     * @throws \JsonException
     */
    public function redirect(string $url, int $httpCode = 302): array
    {
        $customHeaders['Location'] = $url;
        return $this->__invoke('', $httpCode, TEXT_HTML, $customHeaders);
    }

}