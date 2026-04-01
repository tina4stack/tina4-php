<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Tests for CsrfMiddleware::beforeCsrf() — validates CSRF token
 * enforcement on state-changing requests.
 *
 * Tina4 CSRF convention:
 *   - GET/HEAD/OPTIONS are skipped (safe methods)
 *   - POST/PUT/PATCH/DELETE require a valid formToken
 *   - Token accepted in request->body["formToken"] or X-Form-Token header
 *   - Token rejected if sent in query params (security risk)
 *   - Routes marked with noAuth skip CSRF
 *   - Requests with valid Authorization: Bearer skip CSRF
 *   - Session binding: token session_id must match request session
 *   - TINA4_CSRF=false disables all checks
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
        // Ensure CSRF is enabled by default
        putenv('TINA4_CSRF=true');
    }

    protected function tearDown(): void
    {
        unset($_ENV['SECRET']);
        putenv('TINA4_CSRF');
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

    // ── POST/PUT/DELETE without token is blocked ────────────────

    public function testCsrfBlocksPostWithoutToken(): void
    {
        $request = $this->makeRequest('POST', '/api/items');
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertCsrfRejected($resOut);
    }

    public function testCsrfBlocksPutWithoutToken(): void
    {
        $request = $this->makeRequest('PUT', '/api/items');
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertCsrfRejected($resOut);
    }

    public function testCsrfBlocksDeleteWithoutToken(): void
    {
        $request = $this->makeRequest('DELETE', '/api/items');
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertCsrfRejected($resOut);
    }

    // ── Valid formToken in POST/PUT body passes ──────────────────

    public function testCsrfAcceptsFormTokenInBody(): void
    {
        $token = Auth::getToken(['type' => 'form'], $this->secret, 3600);
        $request = $this->makeRequest('POST', '/api/items', body: ['formToken' => $token]);
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertInstanceOf(Response::class, $resOut);
        $this->assertEquals(200, $resOut->getStatusCode());
    }

    public function testCsrfAcceptsPutWithValidBodyToken(): void
    {
        $token = Auth::getToken(['type' => 'form'], $this->secret, 3600);
        $request = $this->makeRequest('PUT', '/api/items', body: ['formToken' => $token]);
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

    public function testCsrfHeaderTakesPrecedenceWhenBodyEmpty(): void
    {
        $token = Auth::getToken(['type' => 'form'], $this->secret, 3600);
        $request = $this->makeRequest('POST', '/api/items', body: [], headers: [
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

    public function testCsrfQueryParamRejectionMessage(): void
    {
        $token = Auth::getToken(['type' => 'form'], $this->secret, 3600);
        $request = $this->makeRequest('POST', '/api/items', query: ['formToken' => $token]);
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        // Response::error() returns an array with a 'message' key
        $this->assertIsArray($resOut);
        $this->assertStringContainsStringIgnoringCase('query string', $resOut['message']);
    }

    // ── Invalid / expired / wrong-secret token is rejected ───────

    public function testCsrfRejectsMalformedToken(): void
    {
        $request = $this->makeRequest('POST', '/api/items', body: ['formToken' => 'not.a.valid.jwt']);
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertCsrfRejected($resOut);
    }

    public function testCsrfRejectsExpiredToken(): void
    {
        $expired = Auth::getToken([
            'type' => 'form',
            'exp' => time() - 60,
        ], $this->secret, 0);
        $request = $this->makeRequest('POST', '/api/items', body: ['formToken' => $expired]);
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertCsrfRejected($resOut);
    }

    public function testCsrfRejectsWrongSecretToken(): void
    {
        $wrongToken = Auth::getToken(['type' => 'form'], 'wrong-secret-key', 3600);
        $request = $this->makeRequest('POST', '/api/items', body: ['formToken' => $wrongToken]);
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertCsrfRejected($resOut);
    }

    // ── Routes with noAuth=true skip CSRF ────────────────────────

    public function testCsrfSkipsNoAuthRoutes(): void
    {
        $request = $this->makeRequest('POST', '/api/webhook');
        $this->setDynamicProperty($request, 'handler', ['noAuth' => true]);
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertInstanceOf(Response::class, $resOut);
        $this->assertEquals(200, $resOut->getStatusCode());
    }

    public function testCsrfHandlerWithoutNoAuthRequiresCsrf(): void
    {
        $request = $this->makeRequest('POST', '/api/items');
        $this->setDynamicProperty($request, 'handler', ['noAuth' => false]);
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertCsrfRejected($resOut);
    }

    // ── Valid Bearer auth skips CSRF ──────────────────────────────

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

    public function testCsrfInvalidBearerDoesNotSkip(): void
    {
        $request = $this->makeRequest('POST', '/api/items', headers: [
            'Authorization' => 'Bearer invalid-token',
        ]);
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        // Invalid bearer means CSRF check runs and fails (no formToken)
        $this->assertCsrfRejected($resOut);
    }

    // ── Session binding ──────────────────────────────────────────

    public function testCsrfSessionBindingInvalid(): void
    {
        $token = Auth::getToken([
            'type' => 'form',
            'session_id' => 'session-abc',
        ], $this->secret, 3600);

        $request = $this->makeRequest('POST', '/api/items', body: ['formToken' => $token]);
        $request->session = $this->createMockSession('session-xyz');
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertCsrfRejected($resOut);
    }

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

    public function testCsrfTokenWithoutSessionIdPasses(): void
    {
        // Token with no session_id claim skips session binding check
        $token = Auth::getToken(['type' => 'form'], $this->secret, 3600);
        $request = $this->makeRequest('POST', '/api/items', body: ['formToken' => $token]);
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertInstanceOf(Response::class, $resOut);
        $this->assertEquals(200, $resOut->getStatusCode());
    }

    // ── TINA4_CSRF env toggle ────────────────────────────────────

    public function testCsrfDisabledViaEnvFalse(): void
    {
        putenv('TINA4_CSRF=false');
        $csrfEnabled = !in_array(strtolower(getenv('TINA4_CSRF')), ['false', '0', 'no'], true);
        $this->assertFalse($csrfEnabled);
    }

    public function testCsrfDefaultOnWithoutEnv(): void
    {
        putenv('TINA4_CSRF');
        $csrfEnv = getenv('TINA4_CSRF');
        // When not set, getenv returns false — CSRF defaults to active
        $csrfEnabled = ($csrfEnv === false) || !in_array(strtolower($csrfEnv), ['false', '0', 'no'], true);
        $this->assertTrue($csrfEnabled);
    }

    public function testCsrfEnabledViaEnvTrue(): void
    {
        putenv('TINA4_CSRF=true');
        $csrfEnv = getenv('TINA4_CSRF');
        $csrfEnabled = ($csrfEnv === false) || !in_array(strtolower($csrfEnv), ['false', '0', 'no'], true);
        $this->assertTrue($csrfEnabled);
    }

    public function testCsrfDisabledViaEnvZero(): void
    {
        putenv('TINA4_CSRF=0');
        $csrfEnabled = !in_array(strtolower(getenv('TINA4_CSRF')), ['false', '0', 'no'], true);
        $this->assertFalse($csrfEnabled);
    }

    public function testCsrfDisabledViaEnvNo(): void
    {
        putenv('TINA4_CSRF=no');
        $csrfEnabled = !in_array(strtolower(getenv('TINA4_CSRF')), ['false', '0', 'no'], true);
        $this->assertFalse($csrfEnabled);
    }

    // ── CSRF disabled via env skips middleware ────────────────────

    public function testCsrfDisabledEnvSkipsMiddleware(): void
    {
        putenv('TINA4_CSRF=false');
        // POST without token should pass when CSRF is disabled
        $request = $this->makeRequest('POST', '/api/items');
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertInstanceOf(Response::class, $resOut);
        $this->assertEquals(200, $resOut->getStatusCode());
    }

    // ── Error response structure ─────────────────────────────────

    public function testCsrf403ResponseHasErrorEnvelope(): void
    {
        $request = $this->makeRequest('POST', '/api/items');
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        // Response::error() returns an array
        $this->assertIsArray($resOut);
        $this->assertTrue($resOut['error']);
        $this->assertEquals('CSRF_INVALID', $resOut['code']);
        $this->assertArrayHasKey('message', $resOut);
    }

    public function testCsrf403ResponseHasStatusField(): void
    {
        $request = $this->makeRequest('POST', '/api/items');
        $response = new Response(true);

        [$reqOut, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        $this->assertIsArray($resOut);
        $this->assertEquals(403, $resOut['status']);
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
