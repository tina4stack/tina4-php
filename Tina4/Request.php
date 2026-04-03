<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * HTTP Request wrapper — provides clean access to superglobals.
 * Zero dependencies — uses only PHP built-in functions.
 */
class Request
{
    /** @var string HTTP method (GET, POST, PUT, PATCH, DELETE) */
    public readonly string $method;

    /** @var string Request path without query string */
    public readonly string $path;

    /** @var string Full URL including query string */
    public readonly string $url;

    /** @var array<string, mixed> Route dynamic parameters (e.g. {id}) */
    public array $params = [];

    /** @var array<string, string> Parsed query string parameters */
    public readonly array $query;

    /** @var mixed Parsed request body (JSON decoded or form data) */
    public readonly mixed $body;

    /** @var string Raw request body */
    public readonly string $rawBody;

    /** @var array<string, string> Request headers (normalised to lowercase keys) */
    public readonly array $headers;

    /** @var string Client IP address (supports X-Forwarded-For) */
    public readonly string $ip;

    /** @var array<string, array> Uploaded files */
    public readonly array $files;

    /** @var string Content type of the request */
    public readonly string $contentType;

    /** @var Session|null Lazy-loaded session instance */
    public ?Session $session = null;

    /**
     * Create a Request from PHP superglobals (convenience factory).
     */
    public static function fromGlobals(): self
    {
        return new self();
    }

    /**
     * Create a Request from PHP superglobals or supplied data.
     *
     * @param string|null $method HTTP method override
     * @param string|null $path Path override
     * @param array|null $query Query params override
     * @param mixed $body Body override
     * @param array|null $headers Headers override
     * @param string|null $ip IP override
     * @param array|null $files Files override
     */
    public function __construct(
        ?string $method = null,
        ?string $path = null,
        ?array $query = null,
        mixed $body = null,
        ?array $headers = null,
        ?string $ip = null,
        ?array $files = null,
    ) {
        $this->method = strtoupper($method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $this->headers = $headers ?? self::parseHeaders();
        $this->contentType = $this->headers['content-type'] ?? '';

        // Check upload size limit (default 10 MB)
        $maxUploadSize = (int)($_ENV['TINA4_MAX_UPLOAD_SIZE'] ?? getenv('TINA4_MAX_UPLOAD_SIZE') ?: 10485760);
        $contentLength = (int)($this->headers['content-length'] ?? 0);
        if ($contentLength > $maxUploadSize) {
            http_response_code(413);
            throw new \RuntimeException(
                "Request body ({$contentLength} bytes) exceeds TINA4_MAX_UPLOAD_SIZE ({$maxUploadSize} bytes)"
            );
        }

        // Parse path
        $uri = $path ?? ($_SERVER['REQUEST_URI'] ?? '/');
        $questionMark = strpos($uri, '?');
        $this->path = $questionMark !== false ? substr($uri, 0, $questionMark) : $uri;
        $this->url = $uri;

        // Parse query
        if ($query !== null) {
            $this->query = $query;
        } else {
            $queryString = $questionMark !== false ? substr($uri, $questionMark + 1) : ($_SERVER['QUERY_STRING'] ?? '');
            parse_str($queryString, $parsed);
            $this->query = $parsed;
        }

        // Parse body
        $this->rawBody = ($body !== null && is_string($body)) ? $body : (string)(file_get_contents('php://input') ?: '');

        if ($body !== null && !is_string($body)) {
            $this->body = $body;
        } else {
            $this->body = $this->parseBody();
        }

        // IP address
        $this->ip = $ip ?? $this->resolveIp();

        // Files — normalise PHP's $_FILES to standard format: filename, type, content, size
        $this->files = self::normaliseFiles($files ?? ($_FILES ?? []));
    }

    /**
     * Create a Request for testing with explicit parameters.
     */
    public static function create(
        string $method = 'GET',
        string $path = '/',
        ?array $query = null,
        mixed $body = null,
        array $headers = [],
        string $ip = '127.0.0.1',
        array $files = [],
    ): self {
        return new self(
            method: $method,
            path: $path,
            query: $query,
            body: $body,
            headers: $headers,
            ip: $ip,
            files: $files,
        );
    }

    /**
     * Normalise PHP's $_FILES array into a consistent format matching all Tina4 frameworks.
     * Each file becomes: ['filename' => string, 'type' => string, 'content' => string (base64), 'size' => int]
     */
    private static function normaliseFiles(array $files): array
    {
        $result = [];
        foreach ($files as $field => $file) {
            if (!isset($file['tmp_name'])) {
                // Already normalised or custom format — keep as-is
                $result[$field] = $file;
                continue;
            }

            if (is_array($file['tmp_name'])) {
                // Multiple files under one field name
                $result[$field] = [];
                foreach ($file['tmp_name'] as $i => $tmpName) {
                    if ($file['error'][$i] !== UPLOAD_ERR_OK || empty($tmpName)) {
                        continue;
                    }
                    $result[$field][] = [
                        'filename' => $file['name'][$i] ?? '',
                        'type'     => $file['type'][$i] ?? '',
                        'content'  => file_get_contents($tmpName),
                        'size'     => $file['size'][$i] ?? 0,
                    ];
                }
            } else {
                // Single file
                if (($file['error'] ?? 0) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
                    continue;
                }
                $result[$field] = [
                    'filename' => $file['name'] ?? '',
                    'type'     => $file['type'] ?? '',
                    'content'  => file_get_contents($file['tmp_name']),
                    'size'     => $file['size'] ?? 0,
                ];
            }
        }
        return $result;
    }

    /**
     * Check if the request method matches.
     */
    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /**
     * Check if the request expects JSON response.
     */
    public function wantsJson(): bool
    {
        $accept = $this->headers['accept'] ?? '';
        return str_contains($accept, 'application/json');
    }

    /**
     * Check if the request body is JSON.
     */
    public function isJson(): bool
    {
        return str_contains($this->contentType, 'application/json');
    }

    /**
     * Get a specific header value.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Get a specific query parameter.
     */
    public function queryParam(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Get a value from the parsed body.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        if (is_array($this->body)) {
            return $this->body[$key] ?? $default;
        }
        if (is_object($this->body)) {
            return $this->body->$key ?? $default;
        }
        return $default;
    }

    /**
     * Get the bearer token from the Authorization header.
     */
    public function bearerToken(): ?string
    {
        $auth = $this->headers['authorization'] ?? '';
        if (str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return null;
    }

    /**
     * Parse request headers from $_SERVER.
     *
     * @return array<string, string>
     */
    private static function parseHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[strtolower($name)] = $value;
            }
            return $headers;
        }

        // Fallback: parse from $_SERVER
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }

    /**
     * Parse the request body based on content type.
     */
    private function parseBody(): mixed
    {
        if ($this->rawBody === '') {
            // Fall back to $_POST for form submissions
            if (!empty($_POST)) {
                return $_POST;
            }
            return null;
        }

        // JSON body
        if (str_contains($this->contentType, 'application/json')) {
            $decoded = json_decode($this->rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // Form-encoded body
        if (str_contains($this->contentType, 'application/x-www-form-urlencoded')) {
            parse_str($this->rawBody, $parsed);
            return $parsed;
        }

        // Return raw body for other content types
        return $this->rawBody;
    }

    /**
     * Resolve the client IP address, supporting proxy headers.
     */
    private function resolveIp(): string
    {
        // Check X-Forwarded-For
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }

        // Check X-Real-IP
        if (isset($_SERVER['HTTP_X_REAL_IP'])) {
            return trim($_SERVER['HTTP_X_REAL_IP']);
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
