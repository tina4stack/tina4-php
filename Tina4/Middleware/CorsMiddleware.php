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
 *   TINA4_CORS_CREDENTIALS — allow credentials (default: "false"); only sent when origin is not "*"
 */
class CorsMiddleware
{
    /**
     * CORS runs BEFORE route matching so its headers survive a short-circuited
     * 401/403. A browser shown a 401 without them reports a CORS error, and the
     * real status never reaches the developer debugging it.
     */
    public static bool $preMatch = true;

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
        $credentialsEnv = DotEnv::getEnv('TINA4_CORS_CREDENTIALS', 'false');
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
     *   TINA4_CORS_CREDENTIALS — allow credentials (default: "false"); only sent when origin is not "*"
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
        $credentialsEnv = DotEnv::getEnv('TINA4_CORS_CREDENTIALS', 'false');
        $credentials = in_array(strtolower($credentialsEnv), ['true', '1', 'yes'], true);

        // Resolve a single allowed origin from the request's Origin header.
        // The CORS spec forbids a comma-separated list in Access-Control-Allow-Origin.
        //
        // Read it off the REQUEST first and fall back to $_SERVER. Reading only
        // $_SERVER meant the header was invisible to anything not running under
        // a web SAPI - the in-process TestClient, the CLI, and any Request
        // built by hand - so the origin silently resolved to null there.
        $requestOrigin = $request->header('Origin') ?? ($_SERVER['HTTP_ORIGIN'] ?? null);
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

        // Handle OPTIONS preflight — return 204 No Content to short-circuit.
        //
        // Only a REAL preflight short-circuits. A preflight carries an Origin
        // (browsers always send one); a bare OPTIONS does not, and belongs to
        // the RFC 9110 s9.3.7 handler in dispatch, which answers 204 WITH an
        // Allow header listing the path's method set.
        //
        // Without the Origin check this fired on EVERY OPTIONS request,
        // swallowing the RFC 9110 path whenever CorsMiddleware was registered:
        // a plain OPTIONS from a link checker or monitoring probe got a 204
        // that told it nothing. Node had the identical bug and was fixed the
        // same way; Ruby and Python already required the Origin.
        if (strtoupper($request->method) === 'OPTIONS' && $requestOrigin !== null && $requestOrigin !== '') {
            // Carry the resource's REAL method set as Allow (RFC 9110 s9.3.7)
            // so a preflight answers the same question a bare OPTIONS does, on
            // top of the CORS policy headers. Allow and
            // Access-Control-Allow-Methods are NOT interchangeable: Allow is
            // what the resource supports, ACAM is what the policy permits
            // cross-origin. A policy allowing DELETE on a GET-only route still
            // 405s. An unknown path yields "" - the same shape the
            // bare-OPTIONS branch uses. This is CONFORMANCE, not a deviation:
            // the frameworks' own OPTIONS handlers already emit Allow
            // (Django's View.options(), Express's router); only the add-on
            // CORS libraries lose it, by short-circuiting first. ADR-0013.
            $response->header('Allow', implode(', ', \Tina4\Router::methodsAllowedForPath($request->path)));
            $response->status(204);
        }

        return [$request, $response];
    }
}
