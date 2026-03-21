<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Middleware;

use Tina4\DotEnv;

/**
 * CORS middleware — handles Cross-Origin Resource Sharing headers.
 * Configuration via environment variables:
 *   TINA4_CORS_ORIGINS  — comma-separated allowed origins (default: "*")
 *   TINA4_CORS_METHODS  — comma-separated allowed methods (default: "GET,POST,PUT,PATCH,DELETE,OPTIONS")
 *   TINA4_CORS_HEADERS  — comma-separated allowed headers (default: "Content-Type,Authorization,X-Requested-With")
 *   TINA4_CORS_MAX_AGE  — preflight cache duration in seconds (default: "86400")
 */
class CorsMiddleware
{
    private readonly string $allowedOrigins;
    private readonly string $allowedMethods;
    private readonly string $allowedHeaders;
    private readonly int $maxAge;

    public function __construct(
        ?string $origins = null,
        ?string $methods = null,
        ?string $headers = null,
        ?int $maxAge = null,
    ) {
        $this->allowedOrigins = $origins ?? DotEnv::getEnv('TINA4_CORS_ORIGINS', '*');
        $this->allowedMethods = $methods ?? DotEnv::getEnv('TINA4_CORS_METHODS', 'GET,POST,PUT,PATCH,DELETE,OPTIONS');
        $this->allowedHeaders = $headers ?? DotEnv::getEnv('TINA4_CORS_HEADERS', 'Content-Type,Authorization,X-Requested-With');
        $this->maxAge = $maxAge ?? (int)DotEnv::getEnv('TINA4_CORS_MAX_AGE', '86400');
    }

    /**
     * Get the CORS headers to add to a response.
     *
     * @param string|null $requestOrigin The Origin header from the request
     * @return array<string, string> Headers to set on the response
     */
    public function getHeaders(?string $requestOrigin = null): array
    {
        $headers = [];

        // Determine which origin to allow
        $origin = $this->resolveOrigin($requestOrigin);
        if ($origin !== null) {
            $headers['Access-Control-Allow-Origin'] = $origin;
        }

        $headers['Access-Control-Allow-Methods'] = $this->allowedMethods;
        $headers['Access-Control-Allow-Headers'] = $this->allowedHeaders;
        $headers['Access-Control-Max-Age'] = (string)$this->maxAge;

        // If origin is not wildcard, add Vary header
        if ($origin !== '*') {
            $headers['Vary'] = 'Origin';
        }

        return $headers;
    }

    /**
     * Check if the request is an OPTIONS preflight request.
     *
     * @param string $method The HTTP method
     * @return bool
     */
    public function isPreflight(string $method): bool
    {
        return strtoupper($method) === 'OPTIONS';
    }

    /**
     * Handle the middleware invocation.
     * Returns the headers array and whether this is a preflight that should short-circuit.
     *
     * @param string $method The HTTP method
     * @param string|null $requestOrigin The Origin header from the request
     * @return array{headers: array<string, string>, preflight: bool}
     */
    public function handle(string $method, ?string $requestOrigin = null): array
    {
        $headers = $this->getHeaders($requestOrigin);
        $preflight = $this->isPreflight($method);

        return [
            'headers' => $headers,
            'preflight' => $preflight,
        ];
    }

    /**
     * Resolve which origin to include in the response.
     */
    private function resolveOrigin(?string $requestOrigin): ?string
    {
        if ($this->allowedOrigins === '*') {
            return '*';
        }

        if ($requestOrigin === null) {
            return null;
        }

        $allowed = array_map('trim', explode(',', $this->allowedOrigins));

        if (in_array($requestOrigin, $allowed, true)) {
            return $requestOrigin;
        }

        return null;
    }

    /**
     * Get the configured allowed origins string.
     */
    public function getAllowedOrigins(): string
    {
        return $this->allowedOrigins;
    }

    /**
     * Get the configured allowed methods string.
     */
    public function getAllowedMethods(): string
    {
        return $this->allowedMethods;
    }

    /**
     * Get the configured allowed headers string.
     */
    public function getAllowedHeaders(): string
    {
        return $this->allowedHeaders;
    }
}
