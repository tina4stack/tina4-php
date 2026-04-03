<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Middleware;

use Tina4\Log;
use Tina4\Request;
use Tina4\Response;

/**
 * Request logging middleware — records timing for every request.
 *
 * Uses the standardized middleware convention:
 *   - `beforeLog` stamps the start time
 *   - `afterLog` calculates elapsed time and writes an info log entry
 *
 * Register globally:
 *   Middleware::use(RequestLogger::class);
 */
class RequestLogger
{
    /** @var array<string, float> Start times keyed by request path+method for concurrent safety */
    private static array $startTimes = [];

    /**
     * Record the start time before the route handler runs.
     *
     * @param Request $request
     * @param Response $response
     * @return array{0: Request, 1: Response}
     */
    public static function beforeLog(Request $request, Response $response): array
    {
        $key = $request->method . ':' . $request->path;
        self::$startTimes[$key] = microtime(true);
        return [$request, $response];
    }

    /**
     * Log the request after the route handler has completed.
     *
     * Outputs a single info-level line:
     *   GET /api/users 12.34ms
     *
     * @param Request $request
     * @param Response $response
     * @return array{0: Request, 1: Response}
     */
    public static function afterLog(Request $request, Response $response): array
    {
        $key = $request->method . ':' . $request->path;
        $startTime = self::$startTimes[$key] ?? microtime(true);
        unset(self::$startTimes[$key]);

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);
        Log::info("{$request->method} {$request->path} {$elapsed}ms");
        return [$request, $response];
    }

    /**
     * Reset tracked start times (for testing).
     */
    public static function reset(): void
    {
        self::$startTimes = [];
    }
}
