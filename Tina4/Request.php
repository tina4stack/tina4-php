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

    /** @var string Full absolute URL (scheme://host[:port]/path[?query]) */
    public readonly string $url;

    /** @var string Raw query string (no leading "?") */
    public readonly string $queryString;

    /** @var array<string, mixed> Route dynamic parameters (e.g. {id}) */
    public array $params = [];

    /** @var array<string, string> Parsed query string parameters */
    public readonly array $query;

    /** @var mixed Parsed request body (JSON decoded or form data) */
    public readonly mixed $body;

    /** @var string Raw request body */
    public readonly string $rawBody;

    /**
     * Request headers — case-insensitive associative access.
     *
     * Behaves like an array for ArrayAccess / iteration / count, but
     * lookups are case-insensitive: ``$request->headers['Content-Type']``,
     * ``$request->headers['content-type']`` and
     * ``$request->headers['CONTENT-TYPE']`` all return the same value
     * (tina4-book#141 PY-10-03 — parity with Python/Ruby/Node).
     *
     * @var CaseInsensitiveArray
     */
    public readonly CaseInsensitiveArray $headers;

    /** @var string Client IP address (supports X-Forwarded-For) */
    public readonly string $ip;

    /**
     * Raw socket peer address — the immediate TCP client. Unlike {@see $ip}
     * this NEVER honours X-Forwarded-For (which any caller can spoof), so it
     * can be trusted for security decisions such as the MCP loopback/remote
     * authorisation gate. Empty for in-process / synthetic requests.
     *
     * @var string
     */
    public readonly string $remoteIp;

    /** @var array<string, array> Uploaded files */
    public readonly array $files;

    /**
     * Files extracted by parseBody() during multipart parsing. Populated
     * before $this->files is finalised so the constructor can merge them
     * into the readonly $files property in one assignment. Internal —
     * never read by user code.
     * @internal
     */
    private array $multipartFiles = [];

    /** @var string Content type of the request */
    public readonly string $contentType;

    /** @var array<string, string> Parsed cookies from Cookie header */
    public readonly array $cookies;

    /** @var Session|null Lazy-loaded session instance */
    public ?Session $session = null;

    /** @var mixed|null Authenticated user payload — set by auth middleware after token validation */
    public mixed $user = null;

    /** @var mixed|null Route handler reference — set by the router during dispatch */
    public mixed $handler = null;

    /**
     * Create a Request from PHP superglobals (convenience factory).
     */
    /**
     * Is this request HTTPS from the CLIENT's point of view?
     *
     * TLS is normally terminated at a proxy (nginx, HAProxy, ALB, Cloudflare,
     * most container deploys) which then forwards plain HTTP to PHP. So
     * $_SERVER['HTTPS'] is unset on exactly the deployments that ARE encrypted,
     * and it cannot be the only signal: x-forwarded-proto carries the scheme
     * the client actually used.
     *
     * This is the single source of truth for the URL scheme AND for the Secure
     * flag on both session cookies. They used to decide it independently and
     * disagreed, so $request->url said https:// while the cookies concluded
     * plain HTTP and dropped Secure (tina4-php#175).
     *
     * @param string|null $forwardedProto Forwarded proto when the caller already
     *                                    has parsed headers; pass '' for "absent".
     *                                    Null reads $_SERVER (a real request).
     * @return bool True when the client's scheme is https.
     */
    public static function isSecureScheme(?string $forwardedProto = null): bool
    {
        $forwarded = $forwardedProto ?? (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        if ($forwarded !== '') {
            // A chain of proxies appends each hop: "https, http". The FIRST is
            // the client-facing one, which is the scheme the browser used.
            return strcasecmp(trim(explode(',', $forwarded)[0]), 'https') === 0;
        }
        $https = (string)($_SERVER['HTTPS'] ?? '');
        return $https !== '' && strcasecmp($https, 'off') !== 0;
    }

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
        ?string $remoteIp = null,
    ) {
        $this->method = strtoupper($method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // Normalise caller-provided header keys to lowercase. parseHeaders()
        // already lowercases when it builds the array itself, but a number
        // of upstream entry points (Apache+PHP-FPM with custom mappings,
        // some proxies, hand-written test fixtures) hand headers in with
        // the original case — `Content-Type`, `Authorization`, etc. The
        // constructor only ever looks them up by lowercase key, so without
        // this normalisation the content-type detection silently misses
        // and `multipart/form-data` bodies fall through as raw bytes
        // (issue tina4-book#135 follow-up — Kerneels' May-7 repro).
        // HTTP header field-names are case-insensitive (RFC 7230 §3.2).
        // Wrap the parsed/passed-in array in CaseInsensitiveArray so user
        // code can write `$request->headers['Content-Type']` or
        // `$request->headers['content-type']` interchangeably — matches
        // chapter 10 documented examples and Python/Ruby/Node parity
        // (tina4-book#141 PY-10-03). Caller-provided original-case keys
        // are preserved internally for round-tripping; lookups are
        // case-insensitive.
        $source = $headers === null ? self::parseHeaders() : $headers;
        $this->headers = new CaseInsensitiveArray($source);
        $this->contentType = $this->headers['content-type'] ?? '';

        // Parse cookies
        $cookieStr = $this->headers['cookie'] ?? '';
        $parsedCookies = [];
        if ($cookieStr) {
            foreach (explode(';', $cookieStr) as $pair) {
                $parts = explode('=', trim($pair), 2);
                if (count($parts) === 2) {
                    $parsedCookies[trim($parts[0])] = trim($parts[1]);
                }
            }
        }
        $this->cookies = $parsedCookies;

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

        // Resolve the raw query string before $this->query is parsed —
        // both the parsed dict and the original string are useful to apps.
        $this->queryString = $questionMark !== false
            ? substr($uri, $questionMark + 1)
            : ($_SERVER['QUERY_STRING'] ?? '');

        // Reconstruct the full absolute URL (scheme://host[:port]/path[?query]).
        // Honours x-forwarded-proto / x-forwarded-host so apps behind a proxy
        // still see the URL the client actually used. Matches Python/Ruby/Node parity.
        $scheme = self::isSecureScheme((string)($this->headers['x-forwarded-proto'] ?? ''))
            ? 'https'
            : 'http';
        $host = $this->headers['x-forwarded-host']
            ?? ($this->headers['host'] ?? ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost')));
        $url = "{$scheme}://{$host}{$this->path}";
        if ($this->queryString !== '') {
            $url .= '?' . $this->queryString;
        }
        $this->url = $url;

        // Parse query
        if ($query !== null) {
            $this->query = $query;
        } else {
            parse_str($this->queryString, $parsed);
            $this->query = $parsed;
        }

        // Parse body
        $this->rawBody = ($body !== null && is_string($body)) ? $body : (string)(file_get_contents('php://input') ?: '');

        if ($body !== null && !is_string($body)) {
            $this->body = $body;
        } else {
            // parseBody() may stash multipart files into
            // $this->multipartFiles (a private mutable). Merge those
            // into the final readonly $this->files below.
            $this->body = $this->parseBody();
        }

        // Files — normalise PHP's $_FILES + the explicit $files arg + any
        // multipart files parsed by parseBody(). Single assignment so the
        // readonly property contract holds. Issue tina4-book#135: an
        // earlier version mutated $this->files inside parseBody() and
        // crashed with `Cannot modify readonly property`, which the error
        // handler swallowed and the route received the raw multipart bytes
        // as $request->body.
        $explicit = $files ?? ($_FILES ?? []);
        $this->files = self::normaliseFiles(array_merge($explicit, $this->multipartFiles));

        // IP address
        $this->ip = $ip ?? $this->resolveIp();

        // Raw socket peer — explicit arg first (the built-in socket server
        // and PSR-7/Swoole transports pass the real peer), else REMOTE_ADDR
        // from the SAPI (php -S / FPM / Apache set it to the real client).
        // NEVER X-Forwarded-For: a trust decision must not read a spoofable
        // header. Empty string for in-process / synthetic requests.
        $this->remoteIp = $remoteIp ?? ($_SERVER['REMOTE_ADDR'] ?? '');
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
        ?string $remoteIp = null,
    ): self {
        return new self(
            method: $method,
            path: $path,
            query: $query,
            body: $body,
            headers: $headers,
            ip: $ip,
            files: $files,
            remoteIp: $remoteIp,
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
     * Get a parameter by key from merged params (query + body + route params).
     */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $this->query[$key] ?? $default;
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
    public function parseBody(): mixed
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

        // Multipart form data (XHR FormData or standard form POST).
        // Files are stashed on the private $multipartFiles property so
        // the constructor can fold them into the readonly $files
        // property in a single assignment. Trying to write $this->files
        // here used to throw `Cannot modify readonly property` and the
        // body fell back to the raw multipart bytes (#135).
        if (str_contains($this->contentType, 'multipart/form-data')) {
            $parsed = Server::parseMultipartBody($this->rawBody, $this->contentType);
            if (!empty($parsed['files'])) {
                $this->multipartFiles = $parsed['files'];
            }
            return $parsed['fields'];
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
