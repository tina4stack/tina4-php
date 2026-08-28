<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Secure route hands the validated principal + session + cookies to the handler.
 *
 * Regresses tina4-nodejs#57 (reported on 3.13.103): a login route stores a signed
 * token in the session, a later SECURE GET is router-authenticated via the returned
 * session cookie (a request WITHOUT the cookie gets 401), but the report says the
 * handler saw $request->user, $request->session and $request->cookies as
 * UNAVAILABLE. Expected: the secure handler receives the validated principal AND
 * the session.
 *
 * Driven through the REAL dispatcher (Router::dispatch) with a real session-cookie
 * round trip. NO MOCKS.
 *
 * Flow (exactly the reporter's):
 *   1. POST /api/login (public) stores a signed token in the session -> Set-Cookie.
 *   2. GET /api/secure (secured) WITHOUT the cookie -> 401 (the router gate works).
 *   3. GET /api/secure WITH the session cookie -> 200, and the handler sees the
 *      validated principal (user_id === 1), the token a prior request stored, and
 *      the cookies.
 *
 * Same case names in all four:
 *   tina4-python/tests/test_secure_route_session_handoff.py
 *   tina4-ruby/spec/secure_route_session_handoff_spec.rb
 *   tina4-nodejs/test/secureRouteSessionHandoff.test.ts
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class SecureRouteSessionHandoffTest extends TestCase
{
    private string $sessionPath;

    protected function setUp(): void
    {
        Router::clear();
        putenv('TINA4_SECRET=secure-handoff-secret');
        $_ENV['TINA4_SECRET'] = 'secure-handoff-secret';
        putenv('TINA4_SESSION_BACKEND=file');
        $this->sessionPath = sys_get_temp_dir() . '/tina4_handoff_' . uniqid();
        mkdir($this->sessionPath, 0755, true);
        putenv('TINA4_SESSION_PATH=' . $this->sessionPath);

        Router::post('/api/login', function (Request $request, Response $response) {
            // Store the token the TEST minted; writing it server-side mints the cookie.
            $request->session->set('token', $request->body['token'] ?? null);
            return $response(['ok' => true]);
        })->noAuth();

        Router::get('/api/secure', function (Request $request, Response $response) {
            return $response([
                'user' => $request->user,
                'session_token' => $request->session !== null ? $request->session->get('token') : null,
                'cookie_keys' => array_keys($request->cookies ?? []),
            ]);
        })->secure();
    }

    protected function tearDown(): void
    {
        Router::clear();
        foreach (['TINA4_SECRET', 'TINA4_SESSION_BACKEND', 'TINA4_SESSION_PATH'] as $k) {
            putenv($k);
            unset($_ENV[$k]);
        }
    }

    /** Build a Cookie request header from a response's Set-Cookie header(s). */
    private function cookieHeader(Response $response): string
    {
        $headers = $response->getHeaders();
        $setCookie = $headers['Set-Cookie'] ?? ($headers['set-cookie'] ?? []);
        if (is_string($setCookie)) {
            $setCookie = [$setCookie];
        }
        return implode('; ', array_map(fn($c) => explode(';', $c)[0], $setCookie));
    }

    public function testSecureRouteSessionHandoff(): void
    {
        $token = Auth::getToken(['user_id' => 1, 'role' => 'admin']);

        // 1. Log in: the token lands in the session; the response mints the cookie.
        $login = Router::dispatch(
            Request::create(method: 'POST', path: '/api/login', body: ['token' => $token]),
            new Response(testing: true)
        );
        $this->assertSame(200, $login->getStatusCode(), 'login should succeed: ' . $login->getBody());
        $cookie = $this->cookieHeader($login);
        $this->assertNotSame('', $cookie, 'login must return a session cookie');

        // 2. The router gate really gates: no cookie -> 401, handler never runs.
        $denied = Router::dispatch(
            Request::create(method: 'GET', path: '/api/secure'),
            new Response(testing: true)
        );
        $this->assertSame(401, $denied->getStatusCode(), 'secure GET without the cookie must be 401');

        // 3. THE #57 assertion: with the cookie, the handler gets principal + session + cookies.
        $ok = Router::dispatch(
            Request::create(method: 'GET', path: '/api/secure', headers: ['Cookie' => $cookie]),
            new Response(testing: true)
        );
        $this->assertSame(200, $ok->getStatusCode(), 'secure GET with the cookie must be 200: ' . $ok->getBody());
        $data = json_decode($ok->getBody(), true);
        $this->assertIsArray($data['user'], 'request.user must be the validated principal array');
        $this->assertSame(1, $data['user']['user_id'] ?? null, 'request.user must carry the claims');
        $this->assertSame($token, $data['session_token'], 'request.session must round-trip the stored token');
        $this->assertNotEmpty($data['cookie_keys'], 'request.cookies must be populated in the secured handler');
    }
}
