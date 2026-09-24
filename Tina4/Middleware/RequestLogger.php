<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
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
     * Outputs a single info-level line (v3.13.14 — now includes the
     * status code for parity with Python/Ruby/Node):
     *   GET /api/users -> 200 (12.34ms)
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

        $elapsed = round((microtime(true) - $startTime) * 1000, 3);
        $status = $response->getStatusCode();
        Log::info("{$request->method} {$request->path} -> {$status} ({$elapsed}ms)");
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
