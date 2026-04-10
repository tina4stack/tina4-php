<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * HTTP Response builder — fluent interface for constructing responses.
 * Zero dependencies — uses only PHP built-in functions.
 */
class Response
{
    /** @var Frond|null Singleton Frond instance for user templates */
    private static ?Frond $frond = null;

    /** @var Frond|null Singleton Frond instance for framework templates */
    private static ?Frond $frameworkFrond = null;

    /**
     * Get the singleton Frond instance for user templates.
     * Lazily creates one pointing at 'src/templates' on first call.
     *
     * @return Frond
     */
    public static function getFrond(): Frond
    {
        if (self::$frond === null) {
            self::$frond = new Frond('src/templates');
        }
        return self::$frond;
    }

    /**
     * Register a pre-configured Frond engine as the singleton.
     * Call this at startup to set custom filters/globals before any template renders.
     *
     * @param Frond $frond
     */
    public static function setFrond(Frond $frond): void
    {
        self::$frond = $frond;
    }

    /**
     * Get the singleton Frond instance for framework templates (Tina4/templates).
     * Lazily creates one and syncs custom filters/globals from the user Frond engine.
     *
     * @return Frond
     */
    public static function getFrameworkFrond(): Frond
    {
        if (self::$frameworkFrond === null) {
            self::$frameworkFrond = new Frond(__DIR__ . '/templates');

            // Sync custom filters and globals from the user engine
            $userFrond = self::getFrond();
            foreach ($userFrond->getFilters() as $name => $fn) {
                self::$frameworkFrond->addFilter($name, $fn);
            }
            foreach ($userFrond->getGlobals() as $name => $value) {
                self::$frameworkFrond->addGlobal($name, $value);
            }
        }
        return self::$frameworkFrond;
    }

    /** @var int HTTP status code */
    private int $statusCode = 200;

    /** @var array<string, string> Response headers */
    private array $headers = [];

    /** @var string Response body content */
    private string $body = '';

    /** @var array<string, array> Cookies to set */
    private array $cookies = [];

    /** @var bool Whether the response has been sent */
    private bool $sent = false;

    /** @var bool Whether to suppress actual output (for testing) */
    private bool $testing = false;

    /**
     * Create a new Response instance.
     *
     * @param bool $testing If true, suppresses actual header/output calls
     */
    public function __construct(bool $testing = false)
    {
        $this->testing = $testing;
    }

    /**
     * Smart callable — auto-detects content type from data.
     *
     * Usage:
     *   return $response(["key" => "value"]);                  // JSON
     *   return $response(["ok" => true], HTTP_CREATED);        // JSON with status
     *   return $response("<h1>Hello</h1>");                    // HTML
     *   return $response("plain text", HTTP_OK);               // Plain text
     *   return $response($data, HTTP_OK, APPLICATION_JSON);    // Explicit
     */
    public function __invoke(mixed $data = null, int $statusCode = 200, ?string $contentType = null): self
    {
        $this->statusCode = $statusCode;

        if ($contentType !== null) {
            $this->headers['Content-Type'] = $contentType;
            if (is_array($data)) {
                $this->body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } elseif ($data === null) {
                $this->body = '';
            } else {
                $this->body = (string)$data;
            }
        } elseif (is_array($data)) {
            $this->headers['Content-Type'] = 'application/json';
            $this->body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif (is_string($data)) {
            $trimmed = trim($data);
            if (str_starts_with($trimmed, '<') && str_ends_with($trimmed, '>')) {
                $this->headers['Content-Type'] = 'text/html; charset=UTF-8';
            } else {
                $this->headers['Content-Type'] = 'text/plain; charset=UTF-8';
            }
            $this->body = $data;
        } elseif ($data === null) {
            $this->body = '';
        } else {
            $this->headers['Content-Type'] = 'text/plain; charset=UTF-8';
            $this->body = (string)$data;
        }

        return $this;
    }

    /**
     * Set the HTTP status code.
     *
     * @return $this
     */
    public function status(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Set a response header.
     *
     * @return $this
     */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Set multiple headers at once.
     *
     * @param array<string, string> $headers
     * @return $this
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }
        return $this;
    }

    /**
     * Send a JSON response.
     *
     * @return $this
     */
    public function json(mixed $data, int $status = 200): self
    {
        $this->statusCode = $status;
        $this->headers['Content-Type'] = 'application/json';
        $this->body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return $this;
    }

    /**
     * Send an HTML response.
     *
     * @return $this
     */
    public function html(string $content, int $status = 200): self
    {
        $this->statusCode = $status;
        $this->headers['Content-Type'] = 'text/html; charset=UTF-8';
        $this->body = $content;
        return $this;
    }

    /**
     * Send a plain text response.
     *
     * @return $this
     */
    public function text(string $content, int $status = 200): self
    {
        $this->statusCode = $status;
        $this->headers['Content-Type'] = 'text/plain; charset=UTF-8';
        $this->body = $content;
        return $this;
    }

    /**
     * Return an XML response.
     */
    public function xml(string $content, int $status = 200): self
    {
        $this->statusCode = $status;
        $this->headers['Content-Type'] = 'application/xml; charset=UTF-8';
        $this->body = $content;
        return $this;
    }

    /**
     * Send a redirect response.
     *
     * @return $this
     */
    public function redirect(string $url, int $status = 302): self
    {
        $this->statusCode = $status;
        $this->headers['Location'] = $url;
        return $this;
    }

    /**
     * Set a cookie on the response.
     *
     * @param array<string, mixed> $options Cookie options: path, domain, secure, httponly, samesite, expires
     * @return $this
     */
    public function cookie(string $name, string $value, array $options = []): self
    {
        $this->cookies[$name] = [
            'value' => $value,
            'expires' => $options['expires'] ?? 0,
            'path' => $options['path'] ?? '/',
            'domain' => $options['domain'] ?? '',
            'secure' => $options['secure'] ?? false,
            'httponly' => $options['httponly'] ?? true,
            'samesite' => $options['samesite'] ?? (getenv('TINA4_SESSION_SAMESITE') ?: 'Lax'),
        ];
        return $this;
    }

    /**
     * Build a standard error response envelope array.
     *
     * Usage:
     *   $data = Response::errorResponse("VALIDATION_FAILED", "Email is required", 400);
     *
     * @param string $code    Machine-readable error code (e.g. "VALIDATION_FAILED")
     * @param string $message Human-readable error message
     * @param int    $status  HTTP status code (default 400)
     * @return array
     */
    public static function errorResponse(string $code, string $message, int $status = 400): array
    {
        return [
            'error' => true,
            'code' => $code,
            'message' => $message,
            'status' => $status,
        ];
    }

    /**
     * Send a standard JSON error response (instance method, chainable).
     *
     * Usage:
     *   return $response->error("VALIDATION_FAILED", "Email is required", 400);
     *
     * @return $this
     */
    public function error(string $code, string $message, int $status = 400): self
    {
        return $this->json(self::errorResponse($code, $message, $status), $status);
    }

    /**
     * Flush the response to the client.
     */
    public function send(mixed $data = null, ?int $statusCode = null, ?string $contentType = null): self
    {
        // If data is provided, set it on the response and return (fluent API)
        if ($data !== null) {
            if (is_array($data) || is_object($data)) {
                return $this->json($data, $statusCode ?? 200);
            }
            if ($contentType !== null) {
                $this->headers['content-type'] = $contentType;
            }
            $this->body = (string) $data;
            if ($statusCode !== null) {
                $this->statusCode = $statusCode;
            }
            return $this;
        }

        // No data — flush the response to the client
        if ($this->sent) {
            return $this;
        }

        $this->sent = true;

        if ($this->testing) {
            return $this;
        }

        // Set status code
        http_response_code($this->statusCode);

        // Set headers
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        // Set cookies
        foreach ($this->cookies as $name => $opts) {
            setcookie($name, $opts['value'], [
                'expires' => $opts['expires'],
                'path' => $opts['path'],
                'domain' => $opts['domain'],
                'secure' => $opts['secure'],
                'httponly' => $opts['httponly'],
                'samesite' => $opts['samesite'],
            ]);
        }

        // Output body
        echo $this->body;
        return $this;
    }

    /**
     * Stream response from a generator or callable for Server-Sent Events (SSE).
     *
     * Usage:
     *   Router::get("/events", function ($request, $response) {
     *       return $response->stream(function () {
     *           for ($i = 0; $i < 10; $i++) {
     *               yield "data: message {$i}\n\n";
     *               sleep(1);
     *           }
     *       });
     *   });
     *
     * @param callable $source Generator function that yields string chunks
     * @param string $contentType Content type (default: text/event-stream)
     * @return self
     */
    public function stream(callable $source, string $contentType = 'text/event-stream'): self
    {
        if ($this->testing) {
            // In testing mode, collect all chunks into body
            $gen = $source();
            $this->body = '';
            foreach ($gen as $chunk) {
                $this->body .= $chunk;
            }
            $this->headers['Content-Type'] = $contentType;
            $this->sent = true;
            return $this;
        }

        $this->sent = true;

        // Disable output buffering
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        // Set time limit to 0 for long-running streams
        set_time_limit(0);

        // Send headers
        http_response_code($this->statusCode);
        header("Content-Type: {$contentType}");
        header("Cache-Control: no-cache");
        header("Connection: keep-alive");
        header("X-Accel-Buffering: no");

        // Send cookies
        foreach ($this->cookies as $name => $opts) {
            setcookie($name, $opts['value'], [
                'expires' => $opts['expires'],
                'path' => $opts['path'],
                'domain' => $opts['domain'],
                'secure' => $opts['secure'],
                'httponly' => $opts['httponly'],
                'samesite' => $opts['samesite'],
            ]);
        }

        // Send any custom headers
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        // Stream chunks from generator
        $gen = $source();
        foreach ($gen as $chunk) {
            echo $chunk;
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();

            // Check if client disconnected
            if (connection_aborted()) {
                break;
            }
        }

        return $this;
    }

    /**
     * Serve a file as the response.
     *
     * Reads the file, sets the appropriate Content-Type (auto-detected from
     * extension if not provided), and optionally forces a download via
     * Content-Disposition: attachment.
     *
     * @param string      $path        Path to the file
     * @param string|null $contentType MIME type (auto-detected if null)
     * @param bool        $download    If true, sets Content-Disposition: attachment
     * @return $this
     */
    public function file(string $path, ?string $contentType = null, bool $download = false): self
    {
        if (!file_exists($path) || !is_readable($path)) {
            $this->statusCode = 404;
            $this->headers['Content-Type'] = 'text/plain; charset=UTF-8';
            $this->body = "File not found: {$path}";
            return $this;
        }

        // Auto-detect content type from extension
        if ($contentType === null) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mimeMap = [
                'html' => 'text/html', 'htm' => 'text/html',
                'css' => 'text/css', 'js' => 'application/javascript',
                'json' => 'application/json', 'xml' => 'application/xml',
                'txt' => 'text/plain', 'csv' => 'text/csv',
                'pdf' => 'application/pdf',
                'zip' => 'application/zip', 'gz' => 'application/gzip',
                'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
                'ico' => 'image/x-icon',
                'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
                'mp4' => 'video/mp4', 'webm' => 'video/webm',
                'woff' => 'font/woff', 'woff2' => 'font/woff2',
                'ttf' => 'font/ttf', 'otf' => 'font/otf',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
            $contentType = $mimeMap[$ext] ?? 'application/octet-stream';
        }

        $this->headers['Content-Type'] = $contentType;
        $this->headers['Content-Length'] = (string)filesize($path);

        if ($download) {
            $filename = basename($path);
            $this->headers['Content-Disposition'] = "attachment; filename=\"{$filename}\"";
        }

        $this->body = file_get_contents($path);
        return $this;
    }

    /**
     * Get the current status code.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get all response headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get a specific response header value.
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /**
     * Get the response body.
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Get all cookies that will be sent.
     *
     * @return array<string, array>
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * Check if the response has been sent.
     */
    public function isSent(): bool
    {
        return $this->sent;
    }

    /**
     * Get the Content-Type header value.
     */
    public function getContentType(): ?string
    {
        return $this->headers['Content-Type'] ?? null;
    }

    /**
     * Set the response body directly.
     */
    public function setBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    /**
     * Allow the response to be cast to a string (e.g. echo $app()).
     * Note: prefer $app->handle() for proper header sending.
     */
    public function __toString(): string
    {
        return $this->body;
    }

    /**
     * Get the decoded JSON body (for testing).
     */
    public function getJsonBody(): mixed
    {
        return json_decode($this->body, true);
    }

    /**
     * Render a Twig template via the Frond engine and return as HTML response.
     *
     * Usage:
     *   return $res->template("dashboard.twig", ["title" => "Dashboard"]);
     *
     * @param string $templateName Template file relative to the template directory
     * @param array  $data         Variables to pass into the template
     * @param int    $status       HTTP status code (default 200)
     * @param string $templateDir  Template directory (default 'src/templates')
     * @return $this
     */
    public function template(string $templateName, array $data = [], int $status = 200, string $templateDir = 'src/templates'): self
    {
        $frond = ($templateDir !== 'src/templates') ? new Frond($templateDir) : self::getFrond();
        $html = $frond->render($templateName, $data);

        $this->statusCode = $status;
        $this->headers['Content-Type'] = 'text/html; charset=UTF-8';
        $this->body = $html;

        return $this;
    }

    /**
     * Render a template. Alias for template() — provides cross-framework parity.
     *
     * @param string $templateName Template file name (e.g. 'dashboard.twig')
     * @param array  $data         Variables to pass to the template
     * @param int    $status       HTTP status code (default 200)
     * @param string $templateDir  Template directory (default 'src/templates')
     * @return $this
     */
    public function render(string $templateName, array $data = [], int $status = 200, string $templateDir = 'src/templates'): self
    {
        return $this->template($templateName, $data, $status, $templateDir);
    }
}
