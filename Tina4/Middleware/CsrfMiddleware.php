<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Middleware;

use Tina4\Auth;
use Tina4\DotEnv;
use Tina4\Request;
use Tina4\Response;

/**
 * CSRF middleware — validates form tokens on state-changing requests.
 *
 * Off by default — only active when TINA4_CSRF=true in .env or when
 * registered explicitly via Router::use(CsrfMiddleware::class).
 *
 * Behaviour:
 *   - Skips GET, HEAD, OPTIONS requests.
 *   - Skips routes marked with ->noAuth() / @noauth().
 *   - Skips requests with a valid Authorization: Bearer header (API clients).
 *   - Checks request->body["formToken"] then request->headers["X-Form-Token"].
 *   - Rejects if token found in request->query["formToken"] (log warning, 403).
 *   - Validates token with Auth::validToken using SECRET env var.
 *   - If token payload has session_id, verifies it matches current session.
 *   - Returns 403 with response->error() on failure.
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

        // Skip requests with valid Bearer token (API clients)
        $authHeader = $request->headers['Authorization']
            ?? $request->headers['authorization']
            ?? '';
        if (str_starts_with($authHeader, 'Bearer ')) {
            $bearerToken = trim(substr($authHeader, 7));
            if ($bearerToken !== '') {
                $secret = DotEnv::getEnv('SECRET') ?? $_ENV['SECRET'] ?? 'tina4-default-secret';
                $payload = Auth::validToken($bearerToken, $secret);
                if ($payload !== null) {
                    return [$request, $response];
                }
            }
        }

        // Reject if token is in query string (security risk)
        $query = $request->params ?? $request->query ?? [];
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

        // Validate the token
        $secret = DotEnv::getEnv('SECRET') ?? $_ENV['SECRET'] ?? 'tina4-default-secret';
        $payload = Auth::validToken($token, $secret);

        if ($payload === null) {
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
}
