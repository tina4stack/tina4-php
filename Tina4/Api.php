<?php

namespace Tina4;

/**
 * HTTP client using file_get_contents — zero external dependencies.
 *
 *     $api = new Api("https://api.example.com");
 *     $result = $api->get("/users");
 *     $result = $api->post("/users", ["name" => "Alice"]);
 */
class Api
{
    private string $baseUrl;
    private string $authHeader;
    private int $timeout;
    private bool $ignoreSSL;
    private array $headers = [];

    /**
     * @param string $baseUrl    Base URL for all requests
     * @param string $authHeader Authorization header value (e.g. "Bearer token")
     * @param int    $timeout    Request timeout in seconds
     * @param bool   $ignoreSSL  Skip SSL certificate verification
     */
    public function __construct(string $baseUrl = '', string $authHeader = '', int $timeout = 30, bool $ignoreSSL = false)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->authHeader = $authHeader;
        $this->timeout = $timeout;
        $this->ignoreSSL = $ignoreSSL;
    }

    /**
     * Add custom headers to all subsequent requests.
     *
     * @param array $headers Associative array of header name => value
     */
    public function addHeaders(array $headers): void
    {
        $this->headers = array_merge($this->headers, $headers);
    }

    /**
     * Set Bearer token authentication.
     */
    public function setBearerToken(string $token): void
    {
        $this->authHeader = "Bearer {$token}";
    }

    /**
     * Set Basic authentication.
     */
    public function setBasicAuth(string $username, string $password): void
    {
        $this->authHeader = "Basic " . base64_encode("{$username}:{$password}");
    }

    /**
     * HTTP GET request.
     *
     * @param string $path   URL path (appended to baseUrl)
     * @param array  $params Query string parameters
     * @return array Standardized result with http_code, body, headers, error
     */
    public function get(string $path = '', array $params = []): array
    {
        $url = $this->buildUrl($path);
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        return $this->sendRequest('GET', $url);
    }

    /**
     * HTTP POST request.
     *
     * @param string $path        URL path
     * @param mixed  $body        Request body (array/object auto-serialized to JSON)
     * @param string $contentType Content-Type header
     * @return array
     */
    public function post(string $path = '', mixed $body = null, string $contentType = 'application/json'): array
    {
        return $this->sendRequest('POST', $this->buildUrl($path), $body, $contentType);
    }

    /**
     * HTTP PUT request.
     *
     * @param string $path        URL path
     * @param mixed  $body        Request body
     * @param string $contentType Content-Type header
     * @return array
     */
    public function put(string $path = '', mixed $body = null, string $contentType = 'application/json'): array
    {
        return $this->sendRequest('PUT', $this->buildUrl($path), $body, $contentType);
    }

    /**
     * HTTP PATCH request.
     *
     * @param string $path        URL path
     * @param mixed  $body        Request body
     * @param string $contentType Content-Type header
     * @return array
     */
    public function patch(string $path = '', mixed $body = null, string $contentType = 'application/json'): array
    {
        return $this->sendRequest('PATCH', $this->buildUrl($path), $body, $contentType);
    }

    /**
     * HTTP DELETE request.
     *
     * @param string $path URL path
     * @param mixed  $body Request body
     * @return array
     */
    public function delete(string $path = '', mixed $body = null): array
    {
        return $this->sendRequest('DELETE', $this->buildUrl($path), $body);
    }

    /**
     * Execute an HTTP request. Returns a standardized result array.
     *
     * @param string $method      HTTP method (GET, POST, PUT, PATCH, DELETE)
     * @param string $path        Full URL or path (appended to baseUrl if not absolute)
     * @param mixed  $body        Request body
     * @param string $contentType Content-Type header for the request body
     * @return array{http_code: ?int, body: mixed, headers: array, error: ?string}
     */
    public function sendRequest(string $method = 'GET', string $path = '', mixed $body = null, string $contentType = 'application/json'): array
    {
        $url = str_starts_with($path, 'http') ? $path : $this->buildUrl($path);

        // Build headers
        $headerLines = [];
        foreach ($this->headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }
        if (!empty($this->authHeader)) {
            $headerLines[] = "Authorization: {$this->authHeader}";
        }

        // Prepare body
        $content = null;
        if ($body !== null) {
            if ($contentType === 'application/json' && (is_array($body) || is_object($body))) {
                $content = json_encode($body);
                $headerLines[] = "Content-Type: application/json";
            } elseif (is_string($body)) {
                $content = $body;
                $headerLines[] = "Content-Type: {$contentType}";
            }
        }

        // Build stream context options
        $httpOptions = [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headerLines),
            'timeout' => $this->timeout,
            'ignore_errors' => true, // Don't throw on 4xx/5xx
        ];

        if ($content !== null) {
            $httpOptions['content'] = $content;
        }

        $contextOptions = ['http' => $httpOptions];

        // SSL verification toggle
        if ($this->ignoreSSL) {
            $contextOptions['ssl'] = [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ];
        }

        $context = stream_context_create($contextOptions);

        try {
            $raw = @file_get_contents($url, false, $context);

            if ($raw === false) {
                return [
                    'http_code' => null,
                    'body' => null,
                    'headers' => [],
                    'error' => "Request failed: unable to connect to {$url}",
                ];
            }

            // Parse response headers from $http_response_header (magic variable)
            $httpCode = null;
            $responseHeaders = [];
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $header) {
                    if (preg_match('/^HTTP\/[\d.]+ (\d{3})/', $header, $matches)) {
                        $httpCode = (int)$matches[1];
                    } else {
                        $parts = explode(':', $header, 2);
                        if (count($parts) === 2) {
                            $responseHeaders[trim($parts[0])] = trim($parts[1]);
                        }
                    }
                }
            }

            // Auto-parse JSON response
            $parsed = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $parsed = $raw;
            }

            $error = null;
            if ($httpCode !== null && $httpCode >= 400) {
                $error = "HTTP {$httpCode}";
            }

            return [
                'http_code' => $httpCode,
                'body' => $parsed,
                'headers' => $responseHeaders,
                'error' => $error,
            ];
        } catch (\Exception $e) {
            return [
                'http_code' => null,
                'body' => null,
                'headers' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build a full URL from a path segment.
     */
    private function buildUrl(string $path): string
    {
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        if (empty($path)) {
            return $this->baseUrl;
        }
        return $this->baseUrl . '/' . ltrim($path, '/');
    }
}
