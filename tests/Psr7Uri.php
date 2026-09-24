<?php

declare(strict_types=1);

// Global namespace, like FreePort and TestServer. Test support: required
// directly by the tests that use it.

/**
 * The URI of a {@see Psr7ServerRequest}, with PSR-7 UriInterface semantics for
 * the parts App::__invoke() reads.
 *
 * An input value object, not a test double: Tina4 declares no PSR dependency
 * and probes PSR-7 structurally, so this is exactly the shape a RoadRunner or
 * FrankenPHP bridge hands App::__invoke().
 */
final class Psr7Uri
{
    private string $scheme;
    private string $host;
    private ?int $port;
    private string $path;
    private string $query;

    /**
     * Parse an absolute or origin-form URI.
     *
     * @param string $uri e.g. "http://localhost/visit?x=1" or "/visit"
     */
    public function __construct(string $uri)
    {
        $parts = parse_url($uri) ?: [];
        $this->scheme = strtolower($parts['scheme'] ?? '');
        $this->host = strtolower($parts['host'] ?? '');
        $this->port = $parts['port'] ?? null;
        $this->path = $parts['path'] ?? '';
        $this->query = $parts['query'] ?? '';
    }

    /** @return string The scheme, lowercased, or "" */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /** @return string The host, lowercased, or "" */
    public function getHost(): string
    {
        return $this->host;
    }

    /** @return int|null The port, or null when none was given */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /** @return string The path, or "" */
    public function getPath(): string
    {
        return $this->path;
    }

    /** @return string The query string without the leading "?", or "" */
    public function getQuery(): string
    {
        return $this->query;
    }

    /** @return string The URI reassembled per RFC 3986 */
    public function __toString(): string
    {
        $authority = $this->host . ($this->port !== null ? ':' . $this->port : '');
        return ($this->scheme !== '' ? $this->scheme . ':' : '')
            . ($authority !== '' ? '//' . $authority : '')
            . $this->path
            . ($this->query !== '' ? '?' . $this->query : '');
    }
}
