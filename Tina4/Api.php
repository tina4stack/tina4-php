<?php

namespace Tina4;

/**
 * HTTP client using the file_get_contents stream wrapper — zero external
 * dependencies.
 *
 *     $api = new Api("https://api.example.com");
 *     $result = $api->get("/users");
 *     $result = $api->post("/users", ["name" => "Alice"]);
 *
 * Multipart upload (from disk or in-memory bytes), streaming download, an
 * injectable transport seam (for USERS to unit-test their own code), and an
 * opt-in per-client cookie jar are all built on the same zero-dependency
 * stream-wrapper core. Redirects are followed with a manual loop that strips
 * the Authorization and Cookie headers on a cross-origin hop.
 *
 * HTTPS needs ext-openssl — it is what registers PHP's `https` stream wrapper.
 * The extension is suggested, not required, so an https:// call on a build
 * without it fails with {@see Api::HTTPS_UNAVAILABLE} rather than PHP's
 * misleading "No such file or directory". Plain http:// needs nothing.
 */
class Api
{
    /**
     * Statuses that warrant an automatic retry when $maxRetries > 0: rate-limit
     * (429) plus the transient server-side 5xx family. 4xx client errors (401,
     * 404, …) are NOT retried — a repeat won't succeed.
     */
    private const RETRY_STATUSES = [429, 500, 502, 503, 504];

    /**
     * Streaming download reads/writes this many bytes per chunk so a
     * multi-megabyte body never lands in memory in one piece.
     */
    private const DOWNLOAD_CHUNK_SIZE = 65536;

    /** Redirects to follow before giving up (matches Python's urllib default). */
    private const MAX_REDIRECTS = 10;

    /**
     * Headers dropped when a redirect crosses to a different origin — a bearer
     * token or a session cookie must never be handed to a host you didn't
     * authenticate to. Compared case-insensitively.
     */
    private const STRIP_ON_CROSS_ORIGIN = ['authorization', 'cookie'];

    /**
     * Message returned (and logged at boot) when PHP cannot open https:// URLs.
     *
     * THE IMPLICIT DEPENDENCY: this client is built on the stream wrapper, and
     * PHP's `https` (and `ftps`) wrapper is registered BY ext-openssl. That
     * extension is suggested, not required — so on a build without it every
     * outbound HTTPS call fails while plain http keeps working. Nothing in this
     * file calls an openssl_* function, so grepping for one does not find the
     * dependency: it is carried by the URL scheme.
     *
     * It fails misleadingly, too. PHP reports the missing wrapper as
     * "Failed to open stream: No such file or directory", which reads like a bad
     * path and sends you hunting in the wrong place. This message names the real
     * cause instead.
     */
    public const HTTPS_UNAVAILABLE = 'Outbound HTTPS is unavailable: PHP has registered no "https" stream '
        . 'wrapper, which means ext-openssl is not loaded — that extension is what registers it. '
        . 'Every https:// request through Tina4\Api will fail until it is installed '
        . '(Debian/Ubuntu: `apt install php-openssl`; Alpine: `apk add php-openssl`; '
        . 'Docker: `docker-php-ext-install openssl`). Plain http:// requests are unaffected. '
        . 'Check `php -m | grep openssl`.';

    private string $baseUrl;
    private string $authHeader;
    private int $timeout;
    private bool $ignoreSSL;
    private array $headers = [];
    private int $maxRetries;
    private float $retryBackoff;

    /**
     * Injectable transport seam (see the constructor doc). Null = the real
     * stream-wrapper network path. Untyped property because PHP does not allow
     * `callable` as a property type.
     */
    private $transport;

    /** Opt-in per-client cookie jar (in-memory, not persisted). */
    private bool $cookiesEnabled;
    private array $cookies = [];

    /**
     * Buffer size used by the streaming primitives when reading from a
     * plain (non-chunked) response body. 8 KB matches PHP's default
     * fread() sweet spot without holding a full megabyte in memory.
     */
    private const STREAM_READ_CHUNK = 8192;

    /**
     * @param string        $baseUrl      Base URL for all requests
     * @param string        $authHeader   Authorization header value (e.g. "Bearer token")
     * @param int           $timeout      Request timeout in seconds
     * @param bool          $ignoreSSL    Skip SSL certificate verification (legacy flag)
     * @param string|null   $bearerToken  Optional bearer token — sugar for setBearerToken() at construction
     * @param string|null   $username     Optional basic-auth username (paired with $password)
     * @param string|null   $password     Optional basic-auth password
     * @param array|null    $headers      Optional headers to addHeaders() at construction
     * @param bool|null     $verifySSL    Positive form of $ignoreSSL — when explicitly false, disables SSL verification
     * @param int           $maxRetries   Automatic retry count (default 0 = off — non-breaking)
     * @param float         $retryBackoff Base backoff in seconds, doubled each attempt (default 0.5)
     * @param callable|null $transport    Injectable transport seam (default null = real network)
     * @param bool          $cookies      Enable the in-memory cookie jar (default false = off)
     *
     * The ergonomic kwargs (since 3.13.1) match the cross-framework Api
     * constructor surface so callers no longer need three follow-up setter
     * calls. Pass via named arguments:
     *
     *     new Api("https://api.example.com", bearerToken: "sk-abc");
     *     new Api("https://api.example.com", username: "u", password: "p", headers: ["X-Tenant" => "acme"]);
     *
     * Bearer wins over basic-auth when both are passed. $verifySSL=false
     * is equivalent to $ignoreSSL=true; legacy $ignoreSSL wins when both
     * supplied for backward compatibility.
     *
     * $maxRetries (default 0 = off) enables automatic retry with exponential
     * backoff ($retryBackoff seconds base, doubling each attempt) on a transport
     * error or a retryable status (429/5xx). A retried non-idempotent request
     * (POST/…) may be re-sent — retries are opt-in for that reason.
     *
     * $transport (default null = the real stream-wrapper network path) is an
     * injectable seam so USERS can unit-test their own code without a live
     * server. When supplied it must be a callable with the signature
     * `transport(string $method, string $url, array $headers, ?string $body,
     * int $timeout): array` returning the same result array every verb returns:
     * `["http_code" => ?int, "body" => mixed, "headers" => array, "error" =>
     * ?string]`. It fully REPLACES the network call.
     *
     * NOTE: Tina4's own test suite must NEVER inject a fake/canned transport —
     * the no-mock rule stands, so framework tests always exercise the real
     * network path (or inject a transport that itself performs REAL socket I/O).
     * The seam exists purely so *application* developers can test code that
     * calls an Api instance.
     *
     * $cookies (default false = off, zero behaviour change) turns on a
     * per-client, in-memory cookie jar: `Set-Cookie` headers on responses are
     * parsed and the accumulated `Cookie` header is sent on subsequent
     * requests. The jar is not persisted and is scoped to this instance.
     */
    public function __construct(
        string $baseUrl = '',
        string $authHeader = '',
        int $timeout = 30,
        bool $ignoreSSL = false,
        ?string $bearerToken = null,
        ?string $username = null,
        ?string $password = null,
        ?array $headers = null,
        ?bool $verifySSL = null,
        int $maxRetries = 0,
        float $retryBackoff = 0.5,
        ?callable $transport = null,
        bool $cookies = false
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->authHeader = $authHeader;
        $this->timeout = $timeout;
        $this->maxRetries = max(0, $maxRetries);
        $this->retryBackoff = $retryBackoff;
        $this->transport = $transport;
        $this->cookiesEnabled = $cookies;

        // ── kwarg sugar ────────────────────────────────────────────────
        // Bearer takes precedence over basic-auth when both passed.
        if ($bearerToken !== null) {
            $this->setBearerToken($bearerToken);
        } elseif ($username !== null && $password !== null) {
            $this->setBasicAuth($username, $password);
        }

        if ($headers !== null && $headers !== []) {
            $this->addHeaders($headers);
        }

        // verifySSL=false is the positive-form of ignoreSSL=true.
        // ignoreSSL wins when both are explicitly supplied.
        $this->ignoreSSL = $ignoreSSL || ($verifySSL === false);
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
     * POST a multipart/form-data body — a file plus optional text fields.
     *
     * Two ways to supply the file, so a caller never needs a temp file:
     *
     *  - $filePath — a file on disk. $filename defaults to its basename.
     *  - $fileBytes + $filename — an in-memory payload (raw byte string).
     *
     * $fieldName is the form field the file is sent under (default "file").
     * $extraFields become additional text parts. $headers are extra per-call
     * headers merged onto the request (they override, applied last). The part's
     * Content-Type is guessed from the filename (falling back to
     * application/octet-stream).
     *
     * Returns the standard result array `["http_code", "body", "headers",
     * "error"]`. A missing file or no source given returns a clean error array
     * (http_code null, error set) — it does NOT throw.
     *
     *     $api->upload("/avatars", filePath: "/tmp/me.png");
     *     $api->upload("/avatars", fileBytes: $raw, filename: "me.png",
     *                  extraFields: ["user_id" => "42"]);
     *
     * @return array{http_code: ?int, body: mixed, headers: array, error: ?string}
     */
    public function upload(
        string $path = '',
        ?string $filePath = null,
        string $fieldName = 'file',
        array $extraFields = [],
        array $headers = [],
        ?string $fileBytes = null,
        ?string $filename = null
    ): array {
        if ($fileBytes !== null) {
            $content = $fileBytes;
            $uploadName = $filename ?? 'upload.bin';
        } elseif ($filePath !== null && $filePath !== '') {
            if (!is_file($filePath)) {
                return ['http_code' => null, 'body' => null, 'headers' => [], 'error' => "file not found: {$filePath}"];
            }
            $content = @file_get_contents($filePath);
            if ($content === false) {
                return ['http_code' => null, 'body' => null, 'headers' => [], 'error' => "unable to read file: {$filePath}"];
            }
            $uploadName = $filename ?? basename($filePath);
        } else {
            return ['http_code' => null, 'body' => null, 'headers' => [], 'error' => 'upload requires filePath or fileBytes'];
        }

        $partContentType = $this->guessMimeType($uploadName);
        $boundary = '----Tina4Boundary' . bin2hex(random_bytes(16));
        $bodyStr = $this->buildMultipartBody($boundary, $fieldName, $uploadName, $content, $partContentType, $extraFields);

        $url = str_starts_with($path, 'http') ? $path : $this->buildUrl($path);
        $reqHeaders = $this->baseHeaders();
        $reqHeaders['Content-Type'] = "multipart/form-data; boundary={$boundary}";
        if (!empty($headers)) {
            $reqHeaders = array_merge($reqHeaders, $headers);
        }

        return $this->withRetry(fn () => $this->dispatch('POST', $url, $reqHeaders, $bodyStr));
    }

    /**
     * Stream a GET response body to $destPath in chunks.
     *
     * The body is written to disk DOWNLOAD_CHUNK_SIZE bytes at a time instead of
     * being buffered whole in memory — safe for large payloads. Uses the same
     * request path as every other verb (redirect following, the cross-origin
     * auth strip, and the SSL toggle all apply).
     *
     * Returns `["http_code", "headers", "error", "path"]` — there is NO "body"
     * key (it went to disk). $path is $destPath on success and null on any error
     * (missing dest, HTTP error status, or a transport failure), and the
     * destination file is not written on error.
     *
     * @return array{http_code: ?int, headers: array, error: ?string, path: ?string}
     */
    public function download(string $path = '', ?string $destPath = null, array $params = []): array
    {
        if ($destPath === null || $destPath === '') {
            return ['http_code' => null, 'headers' => [], 'error' => 'download requires destPath', 'path' => null];
        }

        $url = str_starts_with($path, 'http') ? $path : $this->buildUrl($path);
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        $headers = $this->baseHeaders();

        // An injected transport can't stream (it returns a buffered result), so
        // write its body out; only the real path streams chunk-by-chunk.
        if ($this->transport !== null) {
            $result = $this->callTransport('GET', $url, $headers, null);
            $code = $result['http_code'];
            if ($result['error'] === null && $code !== null && $code >= 200 && $code < 300) {
                $data = $result['body'];
                if (is_array($data) || is_object($data)) {
                    $data = json_encode($data);
                } elseif ($data === null) {
                    $data = '';
                } else {
                    $data = (string)$data;
                }
                if (@file_put_contents($destPath, $data) === false) {
                    return ['http_code' => $code, 'headers' => $result['headers'], 'error' => "unable to write {$destPath}", 'path' => null];
                }
                return ['http_code' => $code, 'headers' => $result['headers'], 'error' => null, 'path' => $destPath];
            }
            return [
                'http_code' => $code,
                'headers' => $result['headers'],
                'error' => $result['error'] ?? "download failed (HTTP {$code})",
                'path' => null,
            ];
        }

        // Real streaming path.
        $open = $this->openStream('GET', $url, $headers, null);
        if ($open['handle'] === false) {
            return ['http_code' => null, 'headers' => [], 'error' => $open['error'], 'path' => null];
        }

        $status = $open['status'];
        $this->storeCookies($open['rawHeaders']);

        if ($status === null || $status < 200 || $status >= 300) {
            fclose($open['handle']);
            return [
                'http_code' => $status,
                'headers' => $open['headers'],
                'error' => $status !== null ? "HTTP {$status}" : ($open['error'] ?? 'download failed'),
                'path' => null,
            ];
        }

        $out = @fopen($destPath, 'wb');
        if ($out === false) {
            fclose($open['handle']);
            return ['http_code' => $status, 'headers' => $open['headers'], 'error' => "unable to open {$destPath} for writing", 'path' => null];
        }

        while (!feof($open['handle'])) {
            $chunk = fread($open['handle'], self::DOWNLOAD_CHUNK_SIZE);
            if ($chunk === false) {
                break;
            }
            fwrite($out, $chunk);
        }
        fclose($out);
        fclose($open['handle']);

        return ['http_code' => $status, 'headers' => $open['headers'], 'error' => null, 'path' => $destPath];
    }

    /**
     * Execute an HTTP request with opt-in retry/backoff. Returns a standardized
     * result array.
     *
     * With $maxRetries > 0, a transport failure (http_code null) or a retryable
     * status (429/5xx) is retried up to $maxRetries times with exponential
     * backoff; any other outcome (2xx, 4xx, 3xx) returns at once. A retried
     * non-idempotent request (POST/…) may be re-sent — retries are opt-in for
     * that reason.
     *
     * @param string $method      HTTP method (GET, POST, PUT, PATCH, DELETE)
     * @param string $path        Full URL or path (appended to baseUrl if not absolute)
     * @param mixed  $body        Request body
     * @param string $contentType Content-Type header for the request body
     * @return array{http_code: ?int, body: mixed, headers: array, error: ?string}
     */
    public function sendRequest(string $method = 'GET', string $path = '', mixed $body = null, string $contentType = 'application/json'): array
    {
        return $this->withRetry(fn () => $this->attempt($method, $path, $body, $contentType));
    }

    /**
     * Run a single-attempt closure with the opt-in retry/backoff policy.
     *
     * Factored out so upload() (which cannot flow through the 4-arg attempt()
     * seam without a per-call header) shares the exact retry semantics as the
     * verb methods.
     *
     * @param callable():array $doAttempt Produces one standardized result array
     * @return array{http_code: ?int, body: mixed, headers: array, error: ?string}
     */
    private function withRetry(callable $doAttempt): array
    {
        $attempts = $this->maxRetries + 1;
        $result = [];
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $result = $doAttempt();
            $code = $result['http_code'];
            $retryable = $code === null || in_array($code, self::RETRY_STATUSES, true);
            if (!$retryable || $attempt === $attempts - 1) {
                return $result;
            }
            // Exponential backoff: retryBackoff * 2^attempt (attempt index 0-based).
            $seconds = $this->retryBackoff * (2 ** $attempt);
            usleep((int)($seconds * 1_000_000));
        }
        return $result;
    }

    /**
     * A single HTTP attempt. Returns the standardized result array.
     *
     * This is the network-call seam — protected so an APPLICATION subclass can
     * wrap or instrument one attempt (the same audience as the $transport
     * constructor argument). Tina4's own suite never overrides it with canned
     * responses: ApiTest drives the retry policy against a real scripted HTTP
     * server over real sockets. Keep this signature at four parameters — a
     * subclass override must stay signature-compatible.
     *
     * @param string $method      HTTP method (GET, POST, PUT, PATCH, DELETE)
     * @param string $path        Full URL or path (appended to baseUrl if not absolute)
     * @param mixed  $body        Request body
     * @param string $contentType Content-Type header for the request body
     * @return array{http_code: ?int, body: mixed, headers: array, error: ?string}
     */
    protected function attempt(string $method = 'GET', string $path = '', mixed $body = null, string $contentType = 'application/json'): array
    {
        $url = str_starts_with($path, 'http') ? $path : $this->buildUrl($path);
        $headers = $this->baseHeaders();
        $content = $this->prepareBody($body, $contentType, $headers);
        return $this->dispatch(strtoupper($method), $url, $headers, $content);
    }

    /**
     * Send a fully-built request through the transport seam OR the real network.
     * Stores any Set-Cookie into the jar. Returns the standardized result array.
     *
     * @param array<string,string> $headers Full request headers (auth/cookie/content-type included)
     */
    private function dispatch(string $method, string $url, array $headers, ?string $content): array
    {
        if ($this->transport !== null) {
            return $this->callTransport($method, $url, $headers, $content);
        }

        try {
            $open = $this->openStream($method, $url, $headers, $content);
        } catch (\Throwable $e) {
            return ['http_code' => null, 'body' => null, 'headers' => [], 'error' => $e->getMessage()];
        }

        if ($open['handle'] === false) {
            return ['http_code' => null, 'body' => null, 'headers' => [], 'error' => $open['error']];
        }

        $raw = stream_get_contents($open['handle']);
        fclose($open['handle']);
        $this->storeCookies($open['rawHeaders']);

        $status = $open['status'];
        $parsed = json_decode((string)$raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $parsed = $raw;
        }

        $error = ($status !== null && $status >= 400) ? "HTTP {$status}" : null;

        return [
            'http_code' => $status,
            'body' => $parsed,
            'headers' => $open['headers'],
            'error' => $error,
        ];
    }

    /**
     * Invoke a user-injected transport and normalize its result array.
     *
     * The transport fully replaces the network call; it is called as
     * `(string $method, string $url, array $headers, ?string $body, int $timeout)`.
     */
    private function callTransport(string $method, string $url, array $headers, ?string $content): array
    {
        try {
            $result = ($this->transport)($method, $url, $headers, $content, $this->timeout);
        } catch (\Throwable $e) {
            return ['http_code' => null, 'body' => null, 'headers' => [], 'error' => $e->getMessage()];
        }
        if (!is_array($result)) {
            $result = [];
        }
        $headersOut = $result['headers'] ?? [];
        $this->storeCookies($headersOut);
        return [
            'http_code' => $result['http_code'] ?? null,
            'body' => $result['body'] ?? null,
            'headers' => is_array($headersOut) ? $headersOut : [],
            'error' => $result['error'] ?? null,
        ];
    }

    /**
     * Perform the request with a manual redirect loop, returning the open
     * response stream (positioned at the body) for the final hop.
     *
     * Redirects are followed with follow_location DISABLED so we control each
     * hop: the Authorization and Cookie headers are stripped whenever the
     * target origin (scheme/host/port) differs from the current one — plain
     * file_get_contents forwards them cross-origin, which leaks a bearer token
     * or session cookie to a host you never authenticated to.
     *
     * @param array<string,string> $headers
     * @return array{handle: resource|false, status: ?int, headers: array, rawHeaders: array, error: ?string}
     */
    private function openStream(string $method, string $url, array $headers, ?string $content): array
    {
        $currentUrl = $url;
        $currentMethod = strtoupper($method);
        $currentContent = $content;

        for ($hop = 0; ; $hop++) {
            // Checked per hop, not once up front: a plain http:// request is
            // allowed to redirect to https://, so the hop that actually needs
            // TLS may not be the one the caller asked for.
            if (self::isHttpsUrl($currentUrl) && !self::httpsAvailable()) {
                return [
                    'handle' => false,
                    'status' => null,
                    'headers' => [],
                    'rawHeaders' => [],
                    'error' => self::HTTPS_UNAVAILABLE . " (requested {$currentUrl})",
                ];
            }

            $httpOptions = [
                'method' => $currentMethod,
                'header' => $this->serializeHeaders($headers),
                'timeout' => $this->timeout,
                'follow_location' => 0,   // we follow manually to strip auth cross-origin
                'ignore_errors' => true,  // read 4xx/5xx bodies instead of failing open()
            ];
            if ($currentContent !== null) {
                $httpOptions['content'] = $currentContent;
            }

            $contextOptions = ['http' => $httpOptions];
            if ($this->ignoreSSL) {
                $contextOptions['ssl'] = [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ];
            }
            $context = stream_context_create($contextOptions);

            $handle = @fopen($currentUrl, 'rb', false, $context);
            if ($handle === false) {
                return [
                    'handle' => false,
                    'status' => null,
                    'headers' => [],
                    'rawHeaders' => [],
                    'error' => "Request failed: unable to connect to {$currentUrl}",
                ];
            }

            $meta = stream_get_meta_data($handle);
            $rawHeaders = $meta['wrapper_data'] ?? [];
            [$status, $responseHeaders, $location] = $this->parseResponseHeaders($rawHeaders);

            $isRedirect = $status !== null && $status >= 300 && $status < 400 && $location !== null;
            if ($isRedirect && $hop < self::MAX_REDIRECTS) {
                fclose($handle);
                $newUrl = $this->resolveLocation($currentUrl, $location);
                if (!$this->sameOrigin($currentUrl, $newUrl)) {
                    $headers = $this->stripHeaders($headers, self::STRIP_ON_CROSS_ORIGIN);
                }
                // 303, and 301/302 on a POST, downgrade to a bodyless GET (per HTTP
                // semantics / urllib); 307/308 preserve method and body.
                if ($status === 303 || (($status === 301 || $status === 302) && $currentMethod === 'POST')) {
                    $currentMethod = 'GET';
                    $currentContent = null;
                }
                $currentUrl = $newUrl;
                continue;
            }

            return [
                'handle' => $handle,
                'status' => $status,
                'headers' => $responseHeaders,
                'rawHeaders' => $rawHeaders,
                'error' => null,
            ];
        }
    }

    /**
     * Report whether PHP can open an https:// stream at all.
     *
     * Asks the runtime what wrappers are registered rather than asking whether
     * ext-openssl is loaded — the wrapper registry is the thing fopen() consults,
     * so this cannot disagree with what actually happens on the next request.
     *
     * @return bool True when the "https" stream wrapper is registered
     */
    public static function httpsAvailable(): bool
    {
        return in_array('https', stream_get_wrappers(), true);
    }

    /**
     * True when a URL uses the https scheme (case-insensitive, per RFC 3986 §3.1).
     *
     * @param string $url The absolute URL to inspect
     * @return bool True when the scheme is https
     */
    private static function isHttpsUrl(string $url): bool
    {
        return str_starts_with(strtolower($url), 'https://');
    }

    /**
     * Build the base request headers: a default `Tina4/<version>` User-Agent
     * first (VERSION-DEC-03), then user headers (which override it if a
     * caller set their own 'User-Agent'), then Authorization, then the
     * accumulated Cookie header when the jar is enabled. Content-Type is added
     * by the body-serialization step, not here.
     *
     * @return array<string,string>
     */
    private function baseHeaders(): array
    {
        $headers = array_merge(['User-Agent' => 'Tina4/' . App::$VERSION], $this->headers);
        if ($this->authHeader !== '') {
            $headers['Authorization'] = $this->authHeader;
        }
        if ($this->cookiesEnabled) {
            $cookieHeader = $this->cookieHeaderValue();
            if ($cookieHeader !== null) {
                $headers['Cookie'] = $cookieHeader;
            }
        }
        return $headers;
    }

    /**
     * Serialize a request body onto $headers (by reference) and return the raw
     * content string, mirroring the legacy behaviour: array/object bodies with a
     * JSON content-type are json_encoded; string bodies pass through with the
     * given content-type; anything else sends no body.
     *
     * @param array<string,string> $headers
     */
    private function prepareBody(mixed $body, string $contentType, array &$headers): ?string
    {
        if ($body === null) {
            return null;
        }
        if ($contentType === 'application/json' && (is_array($body) || is_object($body))) {
            $headers['Content-Type'] = 'application/json';
            return json_encode($body);
        }
        if (is_string($body)) {
            $headers['Content-Type'] = $contentType;
            return $body;
        }
        return null;
    }

    /**
     * Assemble a multipart/form-data body as a raw string.
     *
     * Text fields come first, then the file part, then the closing delimiter —
     * matching the Python/Ruby master shape so every framework produces a
     * byte-identical layout.
     */
    private function buildMultipartBody(
        string $boundary,
        string $fieldName,
        string $filename,
        string $fileContent,
        string $contentType,
        array $extraFields
    ): string {
        $crlf = "\r\n";
        $delimiter = '--' . $boundary;
        $body = '';
        foreach ($extraFields as $key => $value) {
            $body .= $delimiter . $crlf;
            $body .= "Content-Disposition: form-data; name=\"{$key}\"" . $crlf . $crlf;
            $body .= (string)$value . $crlf;
        }
        $body .= $delimiter . $crlf;
        $body .= "Content-Disposition: form-data; name=\"{$fieldName}\"; filename=\"{$filename}\"" . $crlf;
        $body .= "Content-Type: {$contentType}" . $crlf . $crlf;
        $body .= $fileContent . $crlf;
        $body .= $delimiter . '--' . $crlf;
        return $body;
    }

    /**
     * Guess a part Content-Type from a filename via a small extension map
     * (zero-dependency; no ext-fileinfo needed, and it works for in-memory bytes
     * where there is no file on disk to sniff). Falls back to
     * application/octet-stream.
     */
    private function guessMimeType(string $filename): string
    {
        static $map = [
            'txt' => 'text/plain', 'csv' => 'text/csv', 'html' => 'text/html', 'htm' => 'text/html',
            'css' => 'text/css', 'js' => 'text/javascript', 'json' => 'application/json', 'xml' => 'application/xml',
            'pdf' => 'application/pdf', 'zip' => 'application/zip', 'gz' => 'application/gzip', 'tar' => 'application/x-tar',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
            'svg' => 'image/svg+xml', 'webp' => 'image/webp', 'ico' => 'image/x-icon', 'bmp' => 'image/bmp',
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
            'mp4' => 'video/mp4', 'webm' => 'video/webm',
            'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel',
            'bin' => 'application/octet-stream',
        ];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return $map[$ext] ?? 'application/octet-stream';
    }

    /**
     * Serialize an associative header array to the CRLF-joined block the http
     * stream context expects.
     *
     * @param array<string,string> $headers
     */
    private function serializeHeaders(array $headers): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        return implode("\r\n", $lines);
    }

    /**
     * Parse raw response header lines (from stream wrapper_data) into
     * [status, assocHeaders, location]. The last status line wins; header names
     * keep their case with later duplicates overwriting earlier ones.
     *
     * @param array<int,string> $lines
     * @return array{0: ?int, 1: array<string,string>, 2: ?string}
     */
    private function parseResponseHeaders(array $lines): array
    {
        $status = null;
        $headers = [];
        $location = null;
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/[\d.]+\s+(\d{3})#', $line, $matches)) {
                $status = (int)$matches[1];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $name = trim($parts[0]);
                $value = trim($parts[1]);
                $headers[$name] = $value;
                if (strtolower($name) === 'location') {
                    $location = $value;
                }
            }
        }
        return [$status, $headers, $location];
    }

    /**
     * Drop headers whose (lower-cased) name is in $dropLower.
     *
     * @param array<string,string> $headers
     * @param array<int,string>    $dropLower
     * @return array<string,string>
     */
    private function stripHeaders(array $headers, array $dropLower): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (!in_array(strtolower((string)$name), $dropLower, true)) {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    /**
     * True when two URLs share scheme + host + (effective) port.
     */
    private function sameOrigin(string $a, string $b): bool
    {
        $pa = parse_url($a);
        $pb = parse_url($b);
        $defaults = ['http' => 80, 'https' => 443];
        $schemeA = strtolower($pa['scheme'] ?? '');
        $schemeB = strtolower($pb['scheme'] ?? '');
        $hostA = strtolower($pa['host'] ?? '');
        $hostB = strtolower($pb['host'] ?? '');
        $portA = $pa['port'] ?? ($defaults[$schemeA] ?? null);
        $portB = $pb['port'] ?? ($defaults[$schemeB] ?? null);
        return $schemeA === $schemeB && $hostA === $hostB && $portA === $portB;
    }

    /**
     * Resolve a (possibly relative) Location header against the current URL.
     */
    private function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ":{$parts['port']}" : '';
        if (str_starts_with($location, '/')) {
            return "{$scheme}://{$host}{$port}{$location}";
        }
        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int)strrpos($path, '/') + 1);
        return "{$scheme}://{$host}{$port}{$dir}{$location}";
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

    // ── streaming primitives (ADR-0060) ───────────────────────────────────

    /**
     * Yield raw response body chunks in the order the transport delivered
     * them. The primitive that streamLines and streamSse build on. No
     * decoding, no framing, no line-splitting - a caller downloading a
     * large file or a binary event feed gets the same bytes the server
     * wrote in the same order it wrote them.
     *
     * $opts recognises:
     *   method          HTTP method (default GET)
     *   body            request body (array/object auto-serialised when
     *                   content_type is application/json)
     *   headers         additional request headers (merged onto baseHeaders)
     *   content_type    body content-type (default application/json)
     *   timeout         total streaming deadline in seconds
     *                   (default TINA4_API_TIMEOUT, then $this->timeout)
     *   connect_timeout connection establishment deadline in seconds
     *                   (default TINA4_API_CONNECT_TIMEOUT, then 10)
     *
     * Iteration ends cleanly on EOF. A connection failure, HTTP timeout,
     * mid-stream drop, or non-2xx response raises {@see ApiStreamError}
     * (or one of its subclasses); the underlying socket is closed by the
     * finally block so an early break out of the loop cannot leak.
     *
     * @return \Generator<string>
     */
    public function streamBytes(string $path = '', array $opts = []): \Generator
    {
        $method = strtoupper($opts['method'] ?? 'GET');
        $body = $opts['body'] ?? null;
        $extraHeaders = $opts['headers'] ?? [];
        $contentType = $opts['content_type'] ?? 'application/json';
        $totalTimeout = (float)($opts['timeout'] ?? self::envFloat('TINA4_API_TIMEOUT', (float)$this->timeout));
        $connectTimeout = (float)($opts['connect_timeout'] ?? self::envFloat('TINA4_API_CONNECT_TIMEOUT', 10.0));
        if ($totalTimeout <= 0) {
            throw new ApiStreamError('Api stream timeout must be greater than zero');
        }

        $url = str_starts_with($path, 'http') ? $path : $this->buildUrl($path);
        $requestHeaders = $this->baseHeaders();
        foreach ($extraHeaders as $name => $value) {
            $requestHeaders[$name] = $value;
        }
        $content = $this->prepareBody($body, $contentType, $requestHeaders);

        $deadline = microtime(true) + $totalTimeout;
        [$stream, $status, $responseHeaders] = $this->openStreamSocket($url, $method, $requestHeaders, $content, $deadline, $connectTimeout);

        try {
            if ($status < 200 || $status >= 300) {
                foreach ($this->readStreamBodyChunks($stream, $responseHeaders, $deadline) as $ignored) {
                    // drain body so the server can close cleanly
                }
                throw new ApiStreamHttpError("Api stream received HTTP {$status}", $status);
            }
            foreach ($this->readStreamBodyChunks($stream, $responseHeaders, $deadline) as $chunk) {
                yield $chunk;
            }
        } finally {
            if (is_resource($stream)) {
                @fclose($stream);
            }
        }
    }

    /**
     * Yield one string per complete line (LF or CRLF delimited),
     * buffered across chunk boundaries. A trailing line without a
     * terminating newline is yielded on EOF. UTF-8 is preserved
     * verbatim because bytes are only ever concatenated, never
     * decoded per chunk.
     *
     * NDJSON feeds, log tails, and line-delimited protocols read the
     * same way regardless of whatever byte chunking the transport
     * happened to pick.
     *
     * @return \Generator<string>
     */
    public function streamLines(string $path = '', array $opts = []): \Generator
    {
        $buffer = '';
        foreach ($this->streamBytes($path, $opts) as $chunk) {
            $buffer .= $chunk;
            while (($lfPos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $lfPos);
                $buffer = substr($buffer, $lfPos + 1);
                if ($line !== '' && $line[strlen($line) - 1] === "\r") {
                    $line = substr($line, 0, -1);
                }
                yield $line;
            }
        }
        if ($buffer !== '') {
            if ($buffer[strlen($buffer) - 1] === "\r") {
                $buffer = substr($buffer, 0, -1);
            }
            yield $buffer;
        }
    }

    /**
     * Yield Server-Sent-Event records built on streamLines. Each yielded
     * value is an associative array with keys:
     *   data   concatenated data lines joined with "\n"
     *   event  ?string named event (event: field) or null
     *   id     ?string last-event-id (id: field) or null
     *   retry  ?int    reconnection delay in ms (retry: field) or null
     *
     * SSE framing rules (per WHATWG):
     *   - Blank line = event boundary; buffered fields dispatch here.
     *   - `:` prefix = comment (ignored, per spec).
     *   - `data:` field values are concatenated with "\n" per event.
     *   - `event:` / `id:` fields are captured (last-write-wins per event).
     *   - `retry:` field is captured only when its value is all digits.
     *   - A leading space after the colon is stripped ("data: foo" -> "foo").
     *   - The OpenAI `data: [DONE]` sentinel is delivered as an ORDINARY
     *     SseEvent (data === "[DONE]"); the iterator does NOT stop on it,
     *     it stops on transport EOF. The caller decides how to treat the
     *     sentinel (AI::stream terminates its typed event stream on it;
     *     a raw consumer may ignore it or keep reading trailing bytes).
     *   - A trailing event on EOF (no final blank line) is dispatched.
     *
     * @return \Generator<array{data:string, event:?string, id:?string, retry:?int}>
     */
    public function streamSse(string $path = '', array $opts = []): \Generator
    {
        $headers = $opts['headers'] ?? [];
        if (!self::hasHeader($headers, 'Accept')) {
            $headers['Accept'] = 'text/event-stream';
        }
        $opts['headers'] = $headers;

        $dataLines = [];
        $eventName = null;
        $eventId = null;
        $retry = null;
        $sawFieldForCurrentEvent = false;

        foreach ($this->streamLines($path, $opts) as $line) {
            if ($line === '') {
                if ($sawFieldForCurrentEvent) {
                    $data = implode("\n", $dataLines);
                    yield ['data' => $data, 'event' => $eventName, 'id' => $eventId, 'retry' => $retry];
                    $dataLines = [];
                    $eventName = null;
                    $retry = null;
                    $sawFieldForCurrentEvent = false;
                }
                continue;
            }
            if ($line[0] === ':') {
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                $field = $line;
                $value = '';
            } else {
                $field = substr($line, 0, $colon);
                $value = substr($line, $colon + 1);
                if ($value !== '' && $value[0] === ' ') {
                    $value = substr($value, 1);
                }
            }
            if ($field === 'data') {
                $dataLines[] = $value;
                $sawFieldForCurrentEvent = true;
            } elseif ($field === 'event') {
                $eventName = $value;
                $sawFieldForCurrentEvent = true;
            } elseif ($field === 'id') {
                $eventId = $value;
                $sawFieldForCurrentEvent = true;
            } elseif ($field === 'retry') {
                if ($value !== '' && ctype_digit($value)) {
                    $retry = (int)$value;
                }
                $sawFieldForCurrentEvent = true;
            }
        }
        if ($sawFieldForCurrentEvent) {
            $data = implode("\n", $dataLines);
            yield ['data' => $data, 'event' => $eventName, 'id' => $eventId, 'retry' => $retry];
        }
    }

    /**
     * Open a raw TCP/TLS socket, write an HTTP/1.1 request, read status +
     * response headers, and return the still-open socket positioned at the
     * body. This is the streaming primitive's transport - it uses
     * stream_socket_client rather than the fopen("http://") wrapper so the
     * caller sees bytes in the chunking the server produced (the wrapper
     * pre-reads the whole response, defeating streaming).
     *
     * @param array<string,string> $headers
     * @return array{0: resource, 1: int, 2: array<string,string>}
     * @throws ApiStreamError|ApiStreamTimeoutError
     */
    private function openStreamSocket(string $url, string $method, array $headers, ?string $content, float $deadline, float $connectTimeout): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            throw new ApiStreamError('Api stream: invalid URL');
        }
        $scheme = strtolower($parts['scheme'] ?? 'http');
        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if ($scheme === 'https' && !self::httpsAvailable()) {
            throw new ApiStreamError(self::HTTPS_UNAVAILABLE . " (requested {$url})");
        }

        $contextOptions = [];
        if ($scheme === 'https') {
            $contextOptions['ssl'] = [
                'verify_peer' => !$this->ignoreSSL,
                'verify_peer_name' => !$this->ignoreSSL,
                'peer_name' => $host,
                'allow_self_signed' => $this->ignoreSSL,
            ];
        }
        $context = stream_context_create($contextOptions);
        $address = ($scheme === 'https' ? 'tls' : 'tcp') . "://{$host}:{$port}";

        $connectStarted = microtime(true);
        $remaining = $deadline - $connectStarted;
        if ($remaining <= 0) {
            throw new ApiStreamTimeoutError('Api stream total timeout expired');
        }
        $connectLimit = min($connectTimeout, $remaining);
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $connectLimit,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($stream === false) {
            $elapsed = microtime(true) - $connectStarted;
            if (microtime(true) >= $deadline
                || $elapsed >= $connectLimit * 0.8
                || stripos($errstr, 'timed out') !== false) {
                throw new ApiStreamTimeoutError('Api stream connection timeout expired');
            }
            throw new ApiStreamError("Api stream connect failed: {$errstr}");
        }
        $this->applyStreamSocketTimeout($stream, $deadline);

        $requestPath = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $hostHeader = $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $lines = [
            "{$method} {$requestPath} HTTP/1.1",
            "Host: {$hostHeader}",
            'Connection: close',
            'Accept-Encoding: identity',
        ];
        if ($content !== null && $content !== '') {
            $lines[] = 'Content-Length: ' . strlen($content);
        }
        $skip = ['host' => true, 'connection' => true, 'content-length' => true, 'accept-encoding' => true];
        foreach ($headers as $name => $value) {
            if (isset($skip[strtolower((string)$name)])) {
                continue;
            }
            $lines[] = "{$name}: {$value}";
        }
        $request = implode("\r\n", $lines) . "\r\n\r\n";
        if ($content !== null && $content !== '') {
            $request .= $content;
        }

        $offset = 0;
        $length = strlen($request);
        while ($offset < $length) {
            $this->applyStreamSocketTimeout($stream, $deadline);
            $written = @fwrite($stream, substr($request, $offset));
            if ($written === false || $written === 0) {
                @fclose($stream);
                throw new ApiStreamError('Api stream: request write failed');
            }
            $offset += $written;
        }

        $statusLine = $this->readStreamSocketLine($stream, $deadline);
        if (!preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $match)) {
            @fclose($stream);
            throw new ApiStreamError('Api stream: invalid HTTP response line');
        }
        $status = (int)$match[1];
        $responseHeaders = [];
        while (true) {
            $line = $this->readStreamSocketLine($stream, $deadline);
            $trimmed = rtrim($line, "\r\n");
            if ($trimmed === '') {
                break;
            }
            if (str_contains($trimmed, ':')) {
                [$name, $value] = explode(':', $trimmed, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
        }
        return [$stream, $status, $responseHeaders];
    }

    /**
     * Apply the remaining-deadline as the stream_set_timeout on the socket
     * so any subsequent read fails fast when the total streaming budget is
     * exhausted rather than blocking the request forever.
     */
    private function applyStreamSocketTimeout($stream, float $deadline): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new ApiStreamTimeoutError('Api stream total timeout expired');
        }
        $seconds = (int)floor($remaining);
        $micros = (int)(($remaining - $seconds) * 1_000_000);
        stream_set_timeout($stream, $seconds, max(1, $micros));
    }

    /**
     * Read a single "\n"-terminated line off the streaming socket. On a
     * transport timeout raises ApiStreamTimeoutError; on EOF returns "".
     */
    private function readStreamSocketLine($stream, float $deadline): string
    {
        $this->applyStreamSocketTimeout($stream, $deadline);
        $line = @fgets($stream);
        if ($line === false) {
            $meta = stream_get_meta_data($stream);
            if ($meta['timed_out'] ?? false || microtime(true) >= $deadline) {
                throw new ApiStreamTimeoutError('Api stream total timeout expired');
            }
            return '';
        }
        return $line;
    }

    /**
     * Yield successive response body chunks off the streaming socket,
     * honouring the response's Transfer-Encoding: chunked framing when
     * present and otherwise reading Content-Length bytes (or until EOF
     * when neither is set).
     *
     * @param array<string,string> $headers response headers (lower-cased names)
     * @return \Generator<string>
     */
    private function readStreamBodyChunks($stream, array $headers, float $deadline): \Generator
    {
        $transferEncoding = strtolower($headers['transfer-encoding'] ?? '');
        if (str_contains($transferEncoding, 'chunked')) {
            while (true) {
                $sizeLine = trim($this->readStreamSocketLine($stream, $deadline));
                if ($sizeLine === '') {
                    continue;
                }
                $size = hexdec(explode(';', $sizeLine, 2)[0]);
                if ($size === 0) {
                    // Trailer line (empty)
                    $this->readStreamSocketLine($stream, $deadline);
                    return;
                }
                $chunk = '';
                while (strlen($chunk) < $size) {
                    $this->applyStreamSocketTimeout($stream, $deadline);
                    $part = @fread($stream, $size - strlen($chunk));
                    if ($part === false || $part === '') {
                        $meta = stream_get_meta_data($stream);
                        if ($meta['timed_out'] ?? false) {
                            throw new ApiStreamTimeoutError('Api stream total timeout expired');
                        }
                        throw new ApiStreamError('Api stream ended unexpectedly');
                    }
                    $chunk .= $part;
                }
                $this->readStreamSocketLine($stream, $deadline);
                yield $chunk;
            }
        }

        $remaining = isset($headers['content-length']) ? (int)$headers['content-length'] : null;
        while (!feof($stream) && ($remaining === null || $remaining > 0)) {
            $this->applyStreamSocketTimeout($stream, $deadline);
            $length = $remaining === null ? self::STREAM_READ_CHUNK : min(self::STREAM_READ_CHUNK, $remaining);
            $chunk = @fread($stream, $length);
            if ($chunk === false) {
                throw new ApiStreamError('Api stream read failed');
            }
            if ($chunk === '') {
                $meta = stream_get_meta_data($stream);
                if ($meta['timed_out'] ?? false) {
                    throw new ApiStreamTimeoutError('Api stream total timeout expired');
                }
                if (feof($stream)) {
                    break;
                }
                continue;
            }
            if ($remaining !== null) {
                $remaining -= strlen($chunk);
                if ($remaining > 0 && feof($stream)) {
                    throw new ApiStreamError('Api stream ended before Content-Length bytes were received');
                }
            }
            yield $chunk;
        }
        if ($remaining !== null && $remaining > 0) {
            throw new ApiStreamError('Api stream ended before Content-Length bytes were received');
        }
    }

    /** Env-driven float with a default; supports an unset/blank value. */
    private static function envFloat(string $name, float $default): float
    {
        $raw = getenv($name);
        if ($raw === false || $raw === '') {
            return $default;
        }
        $value = filter_var($raw, FILTER_VALIDATE_FLOAT);
        return $value === false ? $default : (float)$value;
    }

    /** Case-insensitive header presence check for the streaming opts. */
    private static function hasHeader(array $headers, string $name): bool
    {
        $lower = strtolower($name);
        foreach ($headers as $key => $_) {
            if (strtolower((string)$key) === $lower) {
                return true;
            }
        }
        return false;
    }

    // ── cookie jar (opt-in, in-memory, per-client) ─────────────────────────

    /**
     * The accumulated Cookie request-header value, or null when the jar is empty.
     */
    private function cookieHeaderValue(): ?string
    {
        if (empty($this->cookies)) {
            return null;
        }
        $pairs = [];
        foreach ($this->cookies as $name => $value) {
            $pairs[] = "{$name}={$value}";
        }
        return implode('; ', $pairs);
    }

    /**
     * Parse Set-Cookie response headers into the jar (when enabled).
     *
     * Only the leading name=value pair of each Set-Cookie is kept (attributes
     * like Path/HttpOnly/Expires are ignored); a later value for the same name
     * overwrites an earlier one. $headers may be a list of raw header LINES
     * (real path — multiple Set-Cookie lines) or an associative array with a
     * "Set-Cookie" key (transport seam).
     *
     * @param mixed $headers
     */
    private function storeCookies($headers): void
    {
        if (!$this->cookiesEnabled || !is_array($headers)) {
            return;
        }
        foreach ($headers as $key => $value) {
            $raw = null;
            if (is_int($key)) {
                // A full header line, e.g. "Set-Cookie: name=value; Path=/".
                if (is_string($value) && stripos($value, 'set-cookie:') === 0) {
                    $raw = trim(substr($value, strlen('set-cookie:')));
                }
            } elseif (is_string($key) && strtolower($key) === 'set-cookie') {
                $raw = (string)$value;
            }
            if ($raw === null) {
                continue;
            }
            $firstPair = trim(explode(';', $raw, 2)[0]);
            if (str_contains($firstPair, '=')) {
                [$name, $val] = explode('=', $firstPair, 2);
                $name = trim($name);
                if ($name !== '') {
                    $this->cookies[$name] = trim($val);
                }
            }
        }
    }
}
