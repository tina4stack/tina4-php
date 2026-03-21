<?php

/**
 * Tina4 - This is not a 4ramework.
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
            'samesite' => $options['samesite'] ?? 'Lax',
        ];
        return $this;
    }

    /**
     * Flush the response to the client.
     */
    public function send(): void
    {
        if ($this->sent) {
            return;
        }

        $this->sent = true;

        if ($this->testing) {
            return;
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
     * Get the decoded JSON body (for testing).
     */
    public function getJsonBody(): mixed
    {
        return json_decode($this->body, true);
    }
}
