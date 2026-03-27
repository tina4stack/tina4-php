<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Tests for CsrfMiddleware::beforeCsrf() — validates CSRF token
 * enforcement on state-changing requests.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;
use Tina4\Middleware\CsrfMiddleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Session;

class CsrfMiddlewareTest extends TestCase
{
    private string $secret = 'test-csrf-secret-key';

    protected function setUp(): void
    {
        $_ENV['SECRET'] = $this->secret;
    }

    protected function tearDown(): void
    {
        unset($_ENV['SECRET']);
    }

    // ── Safe methods are skipped ─────────────────────────────────

    public function testCsrfSkipsGetRequest(): void
    {
        $request = $this->makeRequest('GET', '/api/items');
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertInstanceOf(Response::class, $resOut);
        $this->assertEquals(200, $resOut->getStatusCode());
    }

    public function testCsrfSkipsHeadRequest(): void
    {
        $request = $this->makeRequest('HEAD', '/api/items');
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertInstanceOf(Response::class, $resOut);
        $this->assertEquals(200, $resOut->getStatusCode());
    }

    public function testCsrfSkipsOptionsRequest(): void
    {
        $request = $this->makeRequest('OPTIONS', '/api/items');
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertInstanceOf(Response::class, $resOut);
        $this->assertEquals(200, $resOut->getStatusCode());
    }

    // ── POST without token is blocked ────────────────────────────

    public function testCsrfBlocksPostWithoutToken(): void
    {
        $request = $this->makeRequest('POST', '/api/items');
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertCsrfRejected($resOut);
    }

    // ── Valid formToken in POST body passes ───────────────────────

    public function testCsrfAcceptsFormTokenInBody(): void
    {
        $token = Auth::getToken(['type' => 'form'], $this->secret, 3600);
        $request = $this->makeRequest('POST', '/api/items', body: ['formToken' => $token]);
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertInstanceOf(Response::class, $resOut);
        $this->assertEquals(200, $resOut->getStatusCode());
    }

    // ── Valid token in X-Form-Token header passes ────────────────

    public function testCsrfAcceptsXFormTokenHeader(): void
    {
        $token = Auth::getToken(['type' => 'form'], $this->secret, 3600);
        $request = $this->makeRequest('POST', '/api/items', headers: [
            'X-Form-Token' => $token,
        ]);
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertInstanceOf(Response::class, $resOut);
        $this->assertEquals(200, $resOut->getStatusCode());
    }

    // ── Token in query params is rejected ────────────────────────

    public function testCsrfRejectsFormTokenInQueryParams(): void
    {
        $token = Auth::getToken(['type' => 'form'], $this->secret, 3600);
        $request = $this->makeRequest('POST', '/api/items', query: ['formToken' => $token]);
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertCsrfRejected($resOut);
    }

    // ── Expired/malformed token is rejected ──────────────────────

    public function testCsrfRejectsInvalidToken(): void
    {
        // Expired token
        $expired = Auth::getToken(['type' => 'form', 'exp' => time() - 60], $this->secret, 0);
        $request = $this->makeRequest('POST', '/api/items', body: ['formToken' => $expired]);
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);
        $this->assertCsrfRejected($resOut);

        // Malformed token
        $request2 = $this->makeRequest('POST', '/api/items', body: ['formToken' => 'not.a.valid.jwt']);
        $response2 = new Response(true);

        [$reqOut2, $resOut2] = CsrfMiddleware::beforeCsrf($request2, $response2);
        $this->assertCsrfRejected($resOut2);
    }

    // ── Routes with noAuth=true skip CSRF ────────────────────────

    public function testCsrfSkipsNoAuthRoutes(): void
    {
        $request = $this->makeRequest('POST', '/api/webhook');
        // Use reflection to set handler metadata with noAuth flag
        // The CsrfMiddleware checks $request->handler ?? null
        $this->setDynamicProperty($request, 'handler', ['noAuth' => true]);
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertInstanceOf(Response::class, $resOut);
        $this->assertEquals(200, $resOut->getStatusCode());
    }

    // ── Valid Bearer auth skips CSRF ─────────────────────────────

    public function testCsrfSkipsBearerAuth(): void
    {
        $bearerToken = Auth::getToken(['sub' => 'api-client'], $this->secret, 3600);
        $request = $this->makeRequest('POST', '/api/items', headers: [
            'Authorization' => "Bearer $bearerToken",
        ]);
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertInstanceOf(Response::class, $resOut);
        $this->assertEquals(200, $resOut->getStatusCode());
    }

    // ── Session binding: wrong session_id is rejected ────────────

    public function testCsrfSessionBindingInvalid(): void
    {
        $token = Auth::getToken([
            'type' => 'form',
            'session_id' => 'session-abc',
        ], $this->secret, 3600);

        $request = $this->makeRequest('POST', '/api/items', body: ['formToken' => $token]);
        // Create a mock session with a different session_id
        $request->session = $this->createMockSession('session-xyz');
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertCsrfRejected($resOut);
    }

    // ── Session binding: matching session_id passes ──────────────

    public function testCsrfSessionBindingValid(): void
    {
        $sessionId = 'session-match-123';
        $token = Auth::getToken([
            'type' => 'form',
            'session_id' => $sessionId,
        ], $this->secret, 3600);

        $request = $this->makeRequest('POST', '/api/items', body: ['formToken' => $token]);
        $request->session = $this->createMockSession($sessionId);
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertInstanceOf(Response::class, $resOut);
        $this->assertEquals(200, $resOut->getStatusCode());
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Build a Request object for testing the CSRF middleware.
     */
    private function makeRequest(
        string $method,
        string $path,
        ?array $body = null,
        ?array $headers = null,
        ?array $query = null,
    ): Request {
        $defaultHeaders = ['content-type' => 'application/json'];
        $mergedHeaders = array_merge($defaultHeaders, $headers ?? []);

        return new Request(
            method: $method,
            path: $path,
            query: $query ?? [],
            body: $body ?? [],
            headers: $mergedHeaders,
            ip: '127.0.0.1',
        );
    }

    /**
     * Create a mock Session object with a get() method that returns
     * the given session_id. The CsrfMiddleware checks for method_exists
     * on 'get' and calls $session->get('session_id').
     */
    private function createMockSession(string $sessionId): Session
    {
        $session = new Session('file', [
            'path' => sys_get_temp_dir() . '/tina4-csrf-test-sessions',
        ]);
        $session->set('session_id', $sessionId);
        return $session;
    }

    /**
     * Set a dynamic property on an object via an anonymous wrapper.
     * The middleware uses null coalescing ($request->handler ?? null)
     * which safely handles undefined properties.
     */
    private function setDynamicProperty(object $obj, string $name, mixed $value): void
    {
        // Use Closure::bind to add the property in a way that avoids deprecation
        $setter = \Closure::bind(function () use ($name, $value) {
            $this->$name = $value;
        }, $obj, get_class($obj));
        $setter();
    }

    /**
     * Assert that the CSRF middleware rejected the request.
     *
     * The middleware calls Response::error() which is a static method
     * returning an array with error details. On rejection the second
     * element of the return is an array, not a Response object.
     */
    private function assertCsrfRejected(mixed $resOut): void
    {
        if ($resOut instanceof Response) {
            // If implementation uses sendError() (chainable instance method)
            $this->assertGreaterThanOrEqual(403, $resOut->getStatusCode());
        } else {
            // Response::error() returns an array
            $this->assertIsArray($resOut);
            $this->assertTrue($resOut['error'] ?? false, 'Expected error flag in response');
            $this->assertEquals('CSRF_INVALID', $resOut['code'] ?? '');
            $this->assertEquals(403, $resOut['status'] ?? 0);
        }
    }
}
