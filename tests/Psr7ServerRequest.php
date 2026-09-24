<?php

declare(strict_types=1);

// Global namespace, like FreePort and TestServer. Test support: required
// directly by the tests that use it.

require_once __DIR__ . '/Psr7Uri.php';
require_once __DIR__ . '/Psr7Stream.php';

/**
 * A server request with PSR-7 ServerRequestInterface semantics for every method
 * App::__invoke() reads.
 *
 * An input value object, not a test double. Tina4 declares no PSR dependency,
 * so App::__invoke() recognises PSR-7 structurally (getUri() + getMethod()) and
 * then runs for real on whatever it is handed - exactly as it does on the
 * request a RoadRunner or FrankenPHP bridge passes in. Nothing here stands in
 * for a collaborator; it is the input.
 */
final class Psr7ServerRequest
{
    private Psr7Uri $uri;

    /** @var array<string, list<string>> header name as given => values */
    private array $headers = [];

    private Psr7Stream $body;

    /** @var array<string, mixed> */
    private array $queryParams;

    /**
     * @param string $method HTTP method
     * @param string $uri Absolute or origin-form request target
     * @param array<string, string|list<string>> $headers Header name => value(s)
     * @param string $body Raw request body
     * @param array<string, mixed> $serverParams What $_SERVER would hold
     * @param array<string, string> $cookieParams What $_COOKIE would hold
     * @param array<string, mixed>|object|null $parsedBody The decoded body, if any
     */
    public function __construct(
        private readonly string $method,
        string $uri,
        array $headers = [],
        string $body = '',
        private readonly array $serverParams = [],
        private readonly array $cookieParams = [],
        private readonly array|object|null $parsedBody = null,
    ) {
        $this->uri = new Psr7Uri($uri);
        foreach ($headers as $name => $value) {
            $this->headers[$name] = array_values(array_map('strval', (array) $value));
        }
        $this->body = new Psr7Stream($body);
        parse_str($this->uri->getQuery(), $query);
        $this->queryParams = $query;
    }

    /** @return string The HTTP method, case preserved */
    public function getMethod(): string
    {
        return $this->method;
    }

    /** @return Psr7Uri The request URI */
    public function getUri(): Psr7Uri
    {
        return $this->uri;
    }

    /** @return array<string, list<string>> Every header, name as given => values */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @param string $name Header name, matched case-insensitively
     * @return bool Whether the header is present
     */
    public function hasHeader(string $name): bool
    {
        return $this->getHeader($name) !== [];
    }

    /**
     * @param string $name Header name, matched case-insensitively
     * @return list<string> Its values, or [] when absent
     */
    public function getHeader(string $name): array
    {
        foreach ($this->headers as $headerName => $values) {
            if (strcasecmp($headerName, $name) === 0) {
                return $values;
            }
        }
        return [];
    }

    /**
     * @param string $name Header name, matched case-insensitively
     * @return string Its values joined with ", ", or "" when absent
     */
    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    /** @return Psr7Stream The body */
    public function getBody(): Psr7Stream
    {
        return $this->body;
    }

    /** @return array<string, mixed> The query string, decoded */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /** @return array<string, mixed> The server parameters */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /** @return array<string, string> The cookies */
    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    /** @return array<string, mixed>|object|null The decoded body, or null */
    public function getParsedBody(): array|object|null
    {
        return $this->parsedBody;
    }
}
