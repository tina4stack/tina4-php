<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Middleware;

use Tina4\DotEnv;
use Tina4\Request;
use Tina4\Response;

/**
 * In-memory sliding-window rate limiter.
 * Configuration via environment variables:
 *   TINA4_RATE_LIMIT   — max requests per window (default: 60)
 *   TINA4_RATE_WINDOW  — window duration in seconds (default: 60)
 */
class RateLimiter
{
    /** @var array<string, array<float>> IP => [timestamps] */
    private array $requests = [];

    private readonly int $limit;
    private readonly int $window;

    public function __construct(?int $limit = null, ?int $window = null)
    {
        $this->limit = $limit ?? (int)DotEnv::getEnv('TINA4_RATE_LIMIT', '60');
        $this->window = $window ?? (int)DotEnv::getEnv('TINA4_RATE_WINDOW', '60');
    }

    /**
     * Check if a request from an IP is allowed.
     *
     * @param string $ip The client IP address
     * @return array{allowed: bool, headers: array<string, string>, status: int}
     */
    public function check(string $ip): array
    {
        $now = microtime(true);
        $windowStart = $now - $this->window;

        // Initialize or clean up old entries
        if (!isset($this->requests[$ip])) {
            $this->requests[$ip] = [];
        }

        // Remove timestamps outside the sliding window
        $this->requests[$ip] = array_values(
            array_filter($this->requests[$ip], fn(float $ts): bool => $ts > $windowStart)
        );

        $currentCount = count($this->requests[$ip]);
        $remaining = max(0, $this->limit - $currentCount);

        $headers = [
            'X-RateLimit-Limit' => (string)$this->limit,
            'X-RateLimit-Remaining' => (string)$remaining,
        ];

        if ($currentCount >= $this->limit) {
            // Calculate retry-after from oldest request in window
            $oldestInWindow = $this->requests[$ip][0] ?? $now;
            $retryAfter = (int)ceil(($oldestInWindow + $this->window) - $now);
            $retryAfter = max(1, $retryAfter);

            $headers['Retry-After'] = (string)$retryAfter;
            $headers['X-RateLimit-Remaining'] = '0';

            return [
                'allowed' => false,
                'headers' => $headers,
                'status' => 429,
            ];
        }

        // Record this request
        $this->requests[$ip][] = $now;
        $headers['X-RateLimit-Remaining'] = (string)max(0, $this->limit - count($this->requests[$ip]));

        return [
            'allowed' => true,
            'headers' => $headers,
            'status' => 200,
        ];
    }

    /**
     * Get the configured rate limit.
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * Get the configured window duration in seconds.
     */
    public function getWindow(): int
    {
        return $this->window;
    }

    /**
     * Get the current request count for an IP.
     */
    public function getRequestCount(string $ip): int
    {
        if (!isset($this->requests[$ip])) {
            return 0;
        }

        $windowStart = microtime(true) - $this->window;
        return count(array_filter($this->requests[$ip], fn(float $ts): bool => $ts > $windowStart));
    }

    /**
     * Reset all tracking data (useful for testing).
     */
    public function reset(): void
    {
        $this->requests = [];
    }

    /**
     * Clean up expired entries across all IPs to prevent memory leaks.
     */
    public function cleanup(): void
    {
        $windowStart = microtime(true) - $this->window;

        foreach ($this->requests as $ip => $timestamps) {
            $this->requests[$ip] = array_values(
                array_filter($timestamps, fn(float $ts): bool => $ts > $windowStart)
            );

            if (empty($this->requests[$ip])) {
                unset($this->requests[$ip]);
            }
        }
    }

    /** @var array<string, array<float>> Static request tracking for the middleware hook */
    private static array $staticRequests = [];

    /**
     * Standardized middleware hook — enforces per-IP rate limiting before the route handler.
     *
     * Reads configuration from environment variables:
     *   TINA4_RATE_LIMIT  — max requests per window (default: 100)
     *   TINA4_RATE_WINDOW — window duration in seconds (default: 60)
     *
     * Returns a 429 response if the limit is exceeded, causing a short-circuit.
     *
     * @param Request $request
     * @param Response $response
     * @return array{0: Request, 1: Response}
     */
    public static function beforeRateLimit(Request $request, Response $response): array
    {
        $ip = $request->ip;
        $maxRequests = (int)(DotEnv::getEnv('TINA4_RATE_LIMIT', '100'));
        $windowSeconds = (int)(DotEnv::getEnv('TINA4_RATE_WINDOW', '60'));
        $now = microtime(true);
        $windowStart = $now - $windowSeconds;

        // Initialize or prune expired entries
        if (!isset(self::$staticRequests[$ip])) {
            self::$staticRequests[$ip] = [];
        }

        self::$staticRequests[$ip] = array_values(
            array_filter(self::$staticRequests[$ip], fn(float $ts): bool => $ts > $windowStart)
        );

        $currentCount = count(self::$staticRequests[$ip]);
        $remaining = max(0, $maxRequests - $currentCount);

        $response->header('X-RateLimit-Limit', (string)$maxRequests);
        $response->header('X-RateLimit-Remaining', (string)$remaining);

        if ($currentCount >= $maxRequests) {
            // Calculate retry-after from the oldest request still in the window
            $oldestInWindow = self::$staticRequests[$ip][0] ?? $now;
            $retryAfter = (int)ceil(($oldestInWindow + $windowSeconds) - $now);
            $retryAfter = max(1, $retryAfter);

            $response->header('Retry-After', (string)$retryAfter);
            $response->header('X-RateLimit-Remaining', '0');

            return [$request, $response->status(429)->json(['error' => 'Too Many Requests'])];
        }

        // Record this request
        self::$staticRequests[$ip][] = $now;
        $response->header('X-RateLimit-Remaining', (string)max(0, $maxRequests - count(self::$staticRequests[$ip])));

        return [$request, $response];
    }

    /**
     * Reset the static request tracking (for testing).
     */
    public static function resetStatic(): void
    {
        self::$staticRequests = [];
    }
}
