<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Middleware;

use Tina4\Auth;
use Tina4\Middleware;
use Tina4\Request;
use Tina4\Response;

/**
 * CSRF middleware — validates form tokens on state-changing requests.
 *
 * OFF by default: with TINA4_CSRF unset the middleware is NOT attached at boot
 * (see attachFromEnv()), so a default app has no CSRF gate. Set TINA4_CSRF=true
 * (or 1/yes/on) and the framework auto-attaches it at boot so every write is
 * gated; or register it explicitly via Router::use(CsrfMiddleware::class). Once
 * attached, TINA4_CSRF=false (or 0/no) is the kill switch that disables
 * enforcement again.
 *
 * Behaviour:
 *   - Skips GET, HEAD, OPTIONS requests.
 *   - Skips routes marked with ->noAuth() / @noauth().
 *   - Fails CLOSED: with TINA4_SECRET unset the signing secret resolves to
 *     blank (there is NO built-in default), and a blank HMAC key is publicly
 *     reproducible — so no token can be trusted and every write is rejected
 *     (403). This is the SEC-01 no-default-secret guarantee.
 *   - Skips requests with a valid Authorization: Bearer header (API clients).
 *   - Checks request->body["formToken"] then request->headers["X-Form-Token"].
 *   - Rejects if token found in request->query["formToken"] (log warning, 403).
 *   - Validates token with Auth::validToken using the resolved SECRET, and
 *     enforces that the token's `type` claim is `form` — a non-form JWT
 *     presented in the formToken slot is rejected.
 *   - If token payload has session_id, verifies it matches current session.
 *   - Returns 403 with response->error("CSRF_INVALID", ...) on failure.
 */
class CsrfMiddleware
{
    /**
     * Standardized middleware hook — validates CSRF token before the route handler.
     *
     * @param Request $request
     * @param Response $response
     * @return array{0: Request, 1: Response}
     */
    public static function beforeCsrf(Request $request, Response $response): array
    {
        // Check if CSRF is explicitly disabled via env
        $csrfEnv = getenv('TINA4_CSRF');
        if ($csrfEnv !== false && in_array(strtolower($csrfEnv), ['false', '0', 'no'], true)) {
            return [$request, $response];
        }

        // Skip safe HTTP methods
        $method = strtoupper($request->method ?? 'GET');
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return [$request, $response];
        }

        // Skip routes marked @noauth()
        $handler = $request->handler ?? null;
        if ($handler !== null) {
            $noAuth = false;
            if (is_array($handler) && isset($handler['noAuth'])) {
                $noAuth = (bool)$handler['noAuth'];
            } elseif (is_object($handler) && property_exists($handler, 'noAuth')) {
                $noAuth = (bool)$handler->noAuth;
            }
            if ($noAuth) {
                return [$request, $response];
            }
        }

        // Resolve the signing secret ONCE, fail-closed (blank when TINA4_SECRET
        // is unset — there is NO built-in default). A blank HMAC key is publicly
        // reproducible, so a token signed with it (or with the retired public
        // 'tina4-default-secret') is a forgery: reject every write rather than
        // validate against a guessable key. SEC-01, fail closed hard.
        $secret = Auth::resolveSecret();
        if ($secret === '') {
            return [$request, $response->error(
                'CSRF_INVALID',
                'CSRF token cannot be validated: TINA4_SECRET is not set',
                403
            )];
        }

        // Skip requests with a valid Bearer token (API clients). The secret is
        // non-blank here, so a valid bearer is genuinely trusted.
        $authHeader = $request->headers['Authorization']
            ?? $request->headers['authorization']
            ?? '';
        if (str_starts_with($authHeader, 'Bearer ')) {
            $bearerToken = trim(substr($authHeader, 7));
            if ($bearerToken !== '' && Auth::validToken($bearerToken, $secret)) {
                return [$request, $response];
            }
        }

        // Reject if token is in query string (security risk)
        $query = $request->query ?? $request->params ?? [];
        if (is_array($query) && !empty($query['formToken'])) {
            error_log('[Tina4 CSRF] Token found in query string — rejected for security');
            return [$request, $response->error(
                'CSRF_INVALID',
                'Form token must not be sent in the URL query string',
                403
            )];
        }

        // Extract token: body first, then header
        $token = null;
        $body = $request->body ?? [];
        if (is_array($body) && !empty($body['formToken'])) {
            $token = $body['formToken'];
        } elseif (is_object($body) && !empty($body->formToken)) {
            $token = $body->formToken;
        }

        if (empty($token)) {
            $token = $request->headers['X-Form-Token']
                ?? $request->headers['x-form-token']
                ?? '';
        }

        if (empty($token)) {
            return [$request, $response->error(
                'CSRF_INVALID',
                'Invalid or missing form token',
                403
            )];
        }

        // Validate the token signature / expiry against the resolved secret.
        if (!Auth::validToken($token, $secret)) {
            return [$request, $response->error(
                'CSRF_INVALID',
                'Invalid or missing form token',
                403
            )];
        }

        $payload = Auth::getPayload($token) ?? [];

        // Enforce the form-token TYPE — a valid signature is not enough: a
        // non-form JWT (e.g. an auth/session token) must never be accepted in
        // the formToken slot.
        if (($payload['type'] ?? null) !== 'form') {
            return [$request, $response->error(
                'CSRF_INVALID',
                'Invalid or missing form token',
                403
            )];
        }

        // Session binding — if token has session_id, verify it matches
        $tokenSessionId = $payload['session_id'] ?? null;
        if ($tokenSessionId !== null) {
            $currentSessionId = null;
            $session = $request->session ?? null;
            if ($session !== null) {
                if (is_object($session) && method_exists($session, 'get')) {
                    $currentSessionId = $session->get('session_id')
                        ?? (property_exists($session, 'session_id') ? $session->session_id : null);
                } elseif (is_array($session)) {
                    $currentSessionId = $session['session_id'] ?? null;
                }
            }
            // Fall back to PHP's native session ID
            if ($currentSessionId === null && session_status() === PHP_SESSION_ACTIVE) {
                $currentSessionId = session_id();
            }

            if ($currentSessionId !== null && $tokenSessionId !== $currentSessionId) {
                return [$request, $response->error(
                    'CSRF_INVALID',
                    'Invalid or missing form token',
                    403
                )];
            }
        }

        return [$request, $response];
    }

    /**
     * Auto-attach this middleware when TINA4_CSRF is enabled in the env.
     *
     * CSRF is OFF by default: with TINA4_CSRF unset the middleware is never
     * attached, so a default app has no CSRF gate. Setting TINA4_CSRF to a
     * truthy value (true/1/yes/on, case-insensitive, whitespace-trimmed)
     * attaches it globally so every state-changing route is gated — the env
     * flag is the switch, no code change needed. Idempotent (Middleware::use
     * de-dupes). Mirrors tina4_python's attach_csrf_from_env().
     *
     * The framework calls this once during App::start() (after routes are
     * discovered, before serving); a false/0/no value still lets an explicit
     * Router::use opt-in be disabled at runtime by the kill switch in
     * beforeCsrf().
     *
     * @return bool True when the middleware is now attached, false otherwise.
     */
    public static function attachFromEnv(): bool
    {
        $raw = getenv('TINA4_CSRF');
        $value = $raw === false ? '' : strtolower(trim($raw));
        if (in_array($value, ['true', '1', 'yes', 'on'], true)) {
            Middleware::use(self::class);
            return true;
        }
        return false;
    }
}
