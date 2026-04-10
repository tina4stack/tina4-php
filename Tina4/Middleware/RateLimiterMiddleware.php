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
 * Static rate limiter middleware — tracks requests per IP using a sliding window.
 * Config via env: TINA4_RATE_LIMIT (default 100), TINA4_RATE_WINDOW (default 60s).
 *
 * Usage:
 *   Router::get("/api/data", ...)->middleware([RateLimiterMiddleware::class]);
 */
class RateLimiterMiddleware
{
    /** @var array<string, array<float>> IP => [timestamps] */
    private static array $store = [];

    /**
     * Middleware hook — enforces rate limiting before the route handler.
     *
     * @param Request $request
     * @param Response $response
     * @return array{0: Request, 1: Response}
     */
    public static function beforeRateLimit(Request $request, Response $response): array
    {
        $ip = $request->ip ?? 'unknown';
        $maxRequests = (int)(DotEnv::getEnv('TINA4_RATE_LIMIT', '100'));
        $windowSeconds = (int)(DotEnv::getEnv('TINA4_RATE_WINDOW', '60'));
        $now = microtime(true);
        $windowStart = $now - $windowSeconds;

        // Initialize or prune expired entries
        if (!isset(self::$store[$ip])) {
            self::$store[$ip] = [];
        }

        self::$store[$ip] = array_values(
            array_filter(self::$store[$ip], fn(float $ts): bool => $ts > $windowStart)
        );

        $currentCount = count(self::$store[$ip]);
        $remaining = max(0, $maxRequests - $currentCount);

        $response->header('X-RateLimit-Limit', (string)$maxRequests);
        $response->header('X-RateLimit-Remaining', (string)$remaining);

        if ($currentCount >= $maxRequests) {
            $oldestInWindow = self::$store[$ip][0] ?? $now;
            $retryAfter = (int)ceil(($oldestInWindow + $windowSeconds) - $now);
            $retryAfter = max(1, $retryAfter);

            $response->header('Retry-After', (string)$retryAfter);
            $response->header('X-RateLimit-Remaining', '0');

            return [$request, $response->status(429)->json(['error' => 'Too Many Requests'])];
        }

        // Record this request
        self::$store[$ip][] = $now;
        $response->header('X-RateLimit-Remaining', (string)max(0, $maxRequests - count(self::$store[$ip])));

        return [$request, $response];
    }

    /**
     * Check if an IP is within rate limits without recording a request.
     *
     * @param string $ip
     * @return array{0: bool, 1: array{limit: int, remaining: int, reset: int, window: int}}
     */
    public static function check(string $ip): array
    {
        $maxRequests = (int)(DotEnv::getEnv('TINA4_RATE_LIMIT', '100'));
        $windowSeconds = (int)(DotEnv::getEnv('TINA4_RATE_WINDOW', '60'));
        $now = microtime(true);
        $windowStart = $now - $windowSeconds;

        if (!isset(self::$store[$ip])) {
            self::$store[$ip] = [];
        }

        self::$store[$ip] = array_values(
            array_filter(self::$store[$ip], fn(float $ts): bool => $ts > $windowStart)
        );

        $entries = self::$store[$ip];
        $remaining = max(0, $maxRequests - count($entries));
        $reset = !empty($entries)
            ? (int)ceil(($entries[0] + $windowSeconds) - $now)
            : $windowSeconds;

        if (count($entries) >= $maxRequests) {
            return [false, ['limit' => $maxRequests, 'remaining' => 0, 'reset' => $reset, 'window' => $windowSeconds]];
        }

        return [true, ['limit' => $maxRequests, 'remaining' => $remaining - 1, 'reset' => $windowSeconds, 'window' => $windowSeconds]];
    }

    /**
     * Reset tracking (for testing).
     */
    public static function reset(): void
    {
        self::$store = [];
    }
}
