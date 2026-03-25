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
 * Security headers middleware — auto-injects security headers on every response.
 *
 * Configuration via environment variables:
 *   TINA4_FRAME_OPTIONS       — X-Frame-Options (default: "SAMEORIGIN")
 *   TINA4_HSTS                — Strict-Transport-Security max-age value
 *                                (default: "" = off; set to "31536000" to enable)
 *   TINA4_CSP                 — Content-Security-Policy (default: "default-src 'self'")
 *   TINA4_REFERRER_POLICY     — Referrer-Policy (default: "strict-origin-when-cross-origin")
 *   TINA4_PERMISSIONS_POLICY  — Permissions-Policy (default: "camera=(), microphone=(), geolocation=()")
 */
class SecurityHeaders
{
    /**
     * Standardized middleware hook — sets security headers before the route handler.
     *
     * @param Request $request
     * @param Response $response
     * @return array{0: Request, 1: Response}
     */
    public static function beforeSecurity(Request $request, Response $response): array
    {
        $response->header(
            'X-Frame-Options',
            DotEnv::getEnv('TINA4_FRAME_OPTIONS', 'SAMEORIGIN')
        );

        $response->header('X-Content-Type-Options', 'nosniff');

        $hsts = DotEnv::getEnv('TINA4_HSTS', '');
        if ($hsts !== '') {
            $response->header(
                'Strict-Transport-Security',
                "max-age={$hsts}; includeSubDomains"
            );
        }

        $response->header(
            'Content-Security-Policy',
            DotEnv::getEnv('TINA4_CSP', "default-src 'self'")
        );

        $response->header(
            'Referrer-Policy',
            DotEnv::getEnv('TINA4_REFERRER_POLICY', 'strict-origin-when-cross-origin')
        );

        $response->header('X-XSS-Protection', '0');

        $response->header(
            'Permissions-Policy',
            DotEnv::getEnv('TINA4_PERMISSIONS_POLICY', 'camera=(), microphone=(), geolocation=()')
        );

        return [$request, $response];
    }
}
