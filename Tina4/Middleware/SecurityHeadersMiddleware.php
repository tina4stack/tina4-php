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
 * Security headers middleware — auto-injects security headers on every response.
 *
 * Secure-by-default (SECHDR-DEC-01): the framework registers this in the default
 * middleware chain at boot via SecurityHeadersMiddleware::attach() (called
 * unconditionally from App::start), so a default Tina4 app ships the security
 * headers with no opt-in. HSTS is HTTPS-only.
 *
 * Configuration via environment variables:
 *   TINA4_FRAME_OPTIONS       — X-Frame-Options (default: "SAMEORIGIN")
 *   TINA4_HSTS                — Strict-Transport-Security max-age value
 *                                (default: "" = off; set to "31536000" to enable — HTTPS only)
 *   TINA4_CSP                 — Content-Security-Policy (default: "default-src 'self'")
 *   TINA4_REFERRER_POLICY     — Referrer-Policy (default: "strict-origin-when-cross-origin")
 *   TINA4_PERMISSIONS_POLICY  — Permissions-Policy (default: "camera=(), microphone=(), geolocation=()")
 */
class SecurityHeadersMiddleware
{
    /**
     * Register this middleware in the default chain (secure-by-default).
     *
     * Unlike CSRF (opt-in via TINA4_CSRF) this is UNCONDITIONAL: a default app
     * ships the security headers with no opt-in — the SECHDR-DEC-01 posture that
     * closes the SECHDR-OFF-BY-DEFAULT gap. Idempotent (Middleware::use de-dupes).
     * Called once from App::start at boot.
     *
     * @return void
     */
    public static function attach(): void
    {
        \Tina4\Middleware::use(self::class);
    }

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

        // HSTS is HTTPS-only (SECHDR-DEC-02): a downgrade-protection header on a
        // plain-HTTP response is inert at best and ships a bad max-age on an
        // unencrypted scheme at worst. Emit it ONLY when TINA4_HSTS is set AND the
        // request is HTTPS — Request::isSecureScheme honours x-forwarded-proto
        // (first hop) then $_SERVER['HTTPS'], the same source of truth the session
        // cookie's Secure flag uses.
        $hsts = DotEnv::getEnv('TINA4_HSTS', '');
        $isHttps = Request::isSecureScheme((string)($request->headers['x-forwarded-proto'] ?? ''));
        if ($hsts !== '' && $isHttps) {
            $response->header(
                'Strict-Transport-Security',
                "max-age={$hsts}; includeSubDomains"
            );
        }

        if (DotEnv::getEnv('TINA4_CSP', null) === null) {
            self::warnCspDefaultOnce();
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

    /**
     * Warn once per process that the default CSP is in force (TINA4_CSP unset).
     *
     * Secure-by-default keeps `default-src 'self'` (SECHDR-DEC-01), but that
     * default is invisible: it blocks runtime-injected inline styles, cross-origin
     * fonts/scripts/CDNs, `data:` URIs, and cross-origin WebSocket/XHR (a separate
     * API or LiveKit host) — and the failure surfaces only in the browser at
     * runtime, long after a deploy has gone green. So the framework says so once,
     * naming the escape hatch. It NEVER fails the boot or a request — logging a
     * heads-up must not be the reason the server or a request dies. Fires only when
     * TINA4_CSP is ABSENT; setting it (even to empty) is an explicit opt-in.
     *
     * @return void
     */
    private static function warnCspDefaultOnce(): void
    {
        if (self::$cspDefaultWarned) {
            return;
        }
        self::$cspDefaultWarned = true;
        $message = "TINA4_CSP is not set, so Tina4 is serving the default Content-Security-Policy "
            . "\"default-src 'self'\" on every response. That default blocks runtime-injected "
            . "inline styles, cross-origin fonts/scripts/CDNs, data: URIs, and cross-origin "
            . "WebSocket/XHR (e.g. a separate API or LiveKit host). If your app uses any of "
            . "these, set TINA4_CSP to a policy that allows them (see https://tina4.com); to "
            . "silence this notice without changing behaviour, set TINA4_CSP=\"default-src 'self'\".";
        try {
            \Tina4\Log::warning($message);
        } catch (\Throwable $e) {
            // Logging must never break a request.
            error_log($message);
        }
    }

    /** @var bool Warn-once ledger for the default-CSP heads-up (per process). */
    private static bool $cspDefaultWarned = false;
}
