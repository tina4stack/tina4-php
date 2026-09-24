<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Middleware;

use Tina4\DevAdmin;
use Tina4\Request;
use Tina4\Response;

/**
 * Dev-admin security middleware (feature 127, DEVADMIN-DEC-01/02).
 *
 * A single fail-closed choke point in front of every state-changing /__dev
 * route. The dashboard can write files, run SQL and install packages, so it
 * must assume the developer ALSO browses the web:
 *
 *   - DEC-01 same-origin: a cross-site Sec-Fetch-Site, or a cross-origin Origin,
 *     is refused 403 (closes drive-by CSRF from any page the developer visits).
 *   - DEC-02 loopback: a non-loopback socket peer is refused 403 (raw peer,
 *     XFF-proof), except on the MCP surface which carries its own 404 gate.
 *
 * ADR-0078: every /__dev request, reads included, also passes a Host
 * allow-list (loopback names + TINA4_HOST) against DNS rebinding. The path is
 * normalised first, so `//__dev/...` cannot slip past the prefix match.
 * The deliberately cross-origin /__feedback widget and the /ai proxy are untouched.
 * Registered only on the dev path (DevAdmin::register), so production never
 * carries the middleware at all.
 */
class DevAdminSecurityMiddleware
{
    /**
     * Standardized middleware hook — gates a /__dev mutation before the handler.
     *
     * @param Request  $request
     * @param Response $response
     * @return Response|array{0: Request, 1: Response} A 403 Response short-circuits;
     *         a [$request, $response] pair continues to the handler.
     */
    public static function beforeDevAdmin(Request $request, Response $response): Response|array
    {
        $path = (string) preg_replace('#/+#', '/', (string) ($request->path ?? ''));
        if (!str_starts_with($path, '/__dev')) {
            return [$request, $response];
        }
        $denial = DevAdmin::guardRequest($request);
        if ($denial !== null) {
            return $response->json(['ok' => false, 'error' => $denial[1]], $denial[0]);
        }
        return [$request, $response];
    }
}
