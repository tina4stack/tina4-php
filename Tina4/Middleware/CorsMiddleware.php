<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Middleware;

use Tina4\DotEnv;
use Tina4\Request;
use Tina4\Response;

/**
 * CORS middleware — handles Cross-Origin Resource Sharing headers.
 * Configuration via environment variables:
 *   TINA4_CORS_ORIGINS  — comma-separated allowed origins (default: "*")
 *   TINA4_CORS_METHODS  — comma-separated allowed methods (default: "GET,POST,PUT,PATCH,DELETE,OPTIONS")
 *   TINA4_CORS_HEADERS  — comma-separated allowed headers (default: "Content-Type,Authorization,X-Request-ID")
 *   TINA4_CORS_MAX_AGE  — preflight cache duration in seconds (default: "86400")
 *   TINA4_CORS_CREDENTIALS — allow credentials (default: "true"); only sent when origin is not "*"
 */
class CorsMiddleware
{
    private readonly string $allowedOrigins;
    private readonly string $allowedMethods;
    private readonly string $allowedHeaders;
    private readonly int $maxAge;
    private readonly bool $credentials;

    public function __construct(
        ?string $origins = null,
        ?string $methods = null,
        ?string $headers = null,
        ?int $maxAge = null,
        ?bool $credentials = null,
    ) {
        $this->allowedOrigins = $origins ?? DotEnv::getEnv('TINA4_CORS_ORIGINS', '*');
        $this->allowedMethods = $methods ?? DotEnv::getEnv('TINA4_CORS_METHODS', 'GET,POST,PUT,PATCH,DELETE,OPTIONS');
        $this->allowedHeaders = $headers ?? DotEnv::getEnv('TINA4_CORS_HEADERS', 'Content-Type,Authorization,X-Request-ID');
        $this->maxAge = $maxAge ?? (int)DotEnv::getEnv('TINA4_CORS_MAX_AGE', '86400');
        $credentialsEnv = DotEnv::getEnv('TINA4_CORS_CREDENTIALS', 'true');
        $this->credentials = $credentials ?? in_array(strtolower($credentialsEnv), ['true', '1', 'yes'], true);
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

        // If origin is not wildcard, add Vary header and credentials
        if ($origin !== '*') {
            $headers['Vary'] = 'Origin';
            if ($this->credentials) {
                $headers['Access-Control-Allow-Credentials'] = 'true';
            }
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

    /**
     * Standardized middleware hook — sets CORS headers before the route handler.
     *
     * Reads configuration from environment variables:
     *   TINA4_CORS_ORIGINS  — allowed origins (default: "*")
     *   TINA4_CORS_METHODS  — allowed methods (default: "GET,POST,PUT,PATCH,DELETE,OPTIONS")
     *   TINA4_CORS_HEADERS  — allowed headers (default: "Content-Type,Authorization,X-Request-ID")
     *   TINA4_CORS_MAX_AGE  — preflight cache in seconds (default: "86400")
     *   TINA4_CORS_CREDENTIALS — allow credentials (default: "true"); only sent when origin is not "*"
     *
     * For OPTIONS preflight requests, returns a 204 response to short-circuit the handler.
     *
     * @param Request $request
     * @param Response $response
     * @return array{0: Request, 1: Response}
     */
    public static function beforeCors(Request $request, Response $response): array
    {
        $origins = DotEnv::getEnv('TINA4_CORS_ORIGINS', '*');
        $methods = DotEnv::getEnv('TINA4_CORS_METHODS', 'GET,POST,PUT,PATCH,DELETE,OPTIONS');
        $headers = DotEnv::getEnv('TINA4_CORS_HEADERS', 'Content-Type,Authorization,X-Request-ID');
        $maxAge = DotEnv::getEnv('TINA4_CORS_MAX_AGE', '86400');
        $credentialsEnv = DotEnv::getEnv('TINA4_CORS_CREDENTIALS', 'true');
        $credentials = in_array(strtolower($credentialsEnv), ['true', '1', 'yes'], true);

        // Resolve a single allowed origin from the request's Origin header.
        // The CORS spec forbids a comma-separated list in Access-Control-Allow-Origin.
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $allowedList = array_map('trim', explode(',', $origins));
        $isWildcard = in_array('*', $allowedList, true);

        if ($isWildcard) {
            $resolvedOrigin = '*';
        } elseif ($requestOrigin !== null && in_array($requestOrigin, $allowedList, true)) {
            $resolvedOrigin = $requestOrigin;
        } else {
            $resolvedOrigin = null;
        }

        if ($resolvedOrigin !== null) {
            $response->header('Access-Control-Allow-Origin', $resolvedOrigin);
        }

        $response->header('Access-Control-Allow-Methods', $methods);
        $response->header('Access-Control-Allow-Headers', $headers);
        $response->header('Access-Control-Max-Age', $maxAge);

        // Add Vary and credentials headers when origin is not wildcard
        if ($resolvedOrigin !== '*') {
            $response->header('Vary', 'Origin');
            if ($resolvedOrigin !== null && $credentials) {
                $response->header('Access-Control-Allow-Credentials', 'true');
            }
        }

        // Handle OPTIONS preflight — return 204 No Content to short-circuit
        if (strtoupper($request->method) === 'OPTIONS') {
            $response->status(204);
        }

        return [$request, $response];
    }
}
