<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Behavioural tests for CsrfMiddleware::beforeCsrf() — CSRF token enforcement
 * on state-changing requests.
 *
 * These tests drive the REAL before-middleware dispatcher
 * (Middleware::runBefore), with a real Request, a real Response, real
 * Auth::getToken tokens, and a real file-backed Session. No request/response/
 * session/token doubles — the middleware sees exactly what it sees when serving
 * traffic, so a regression in the real wiring (e.g. $request->handler never
 * being populated, which made the @noauth bypass dead code) is caught here.
 *
 * "passed" means the middleware let the request continue to the handler — the
 * response status is still the un-touched default (200) and no CSRF error
 * envelope was written. "rejected" means a 403 CSRF_INVALID envelope.
 *
 * Tina4 CSRF convention:
 *   - GET/HEAD/OPTIONS are skipped (safe methods)
 *   - POST/PUT/PATCH/DELETE require a valid formToken
 *   - Token accepted in request->body["formToken"] or X-Form-Token header
 *   - Token rejected if sent in query params (security risk)
 *   - Routes marked ->noAuth() / @noauth skip CSRF
 *   - Requests with a valid Authorization: Bearer skip CSRF
 *   - Session binding: token session_id must match the request session
 *   - TINA4_CSRF=false (or 0/no) disables all checks
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\Auth;
use Tina4\Frond;
use Tina4\Middleware;
use Tina4\Middleware\CsrfMiddleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;
use Tina4\Session;

class CsrfMiddlewareTest extends TestCase
{
    private string $secret = 'test-csrf-secret-key';

    protected function setUp(): void
    {
        // Set the secret through every channel the middleware/Auth reads:
        // resolveSecret() reads getenv() first, then $_ENV.
        putenv("TINA4_SECRET={$this->secret}");
        $_ENV['TINA4_SECRET'] = $this->secret;
        // CSRF enabled by default for every test (kill-switch tests flip it).
        putenv('TINA4_CSRF=true');
    }

    protected function tearDown(): void
    {
        putenv('TINA4_SECRET');
        unset($_ENV['TINA4_SECRET']);
        putenv('TINA4_CSRF');
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Build a real Request for the CSRF middleware.
     *
     * @param mixed                     $handler  Route-handler metadata exposed
     *                                            on $request->handler, exactly as
     *                                            Router::dispatch sets it (an array
     *                                            carrying the `noAuth` flag).
     */
    private function makeRequest(
        string $method,
        string $path = '/api/items',
        ?array $body = null,
        ?array $headers = null,
        ?array $query = null,
        mixed $handler = null,
        ?Session $session = null,
    ): Request {
        $mergedHeaders = array_merge(['content-type' => 'application/json'], $headers ?? []);
        $request = new Request(
            method: $method,
            path: $path,
            query: $query ?? [],
            body: $body ?? [],
            headers: $mergedHeaders,
            ip: '127.0.0.1',
        );
        if ($handler !== null) {
            $request->handler = $handler;
        }
        if ($session !== null) {
            $request->session = $session;
        }
        return $request;
    }

    /**
     * Run a request through the REAL before-middleware dispatcher and return
     * the (request, response, passed) outcome.
     *
     * `passed` is true when the middleware let the request continue (status
     * untouched, < 400); false when it short-circuited with an error.
     *
     * @return array{0: Request, 1: Response, 2: bool}
     */
    private function dispatchCsrf(Request $request): array
    {
        $response = new Response(true);
        [$req, $res] = Middleware::runBefore([CsrfMiddleware::class], $request, $response);
        $passed = $res->getStatusCode() < 400;
        return [$req, $res, $passed];
    }

    /**
     * Assert the middleware let the request through (no CSRF short-circuit):
     * status is the untouched default and no error envelope was written.
     */
    private function assertPassed(Response $response): void
    {
        $this->assertSame(
            200,
            $response->getStatusCode(),
            'Expected CSRF middleware to pass the request through (status untouched)'
        );
        // A pass writes nothing to the body — the handler (run later) would.
        $this->assertSame('', $response->getBody(), 'A passed CSRF check must not write a body');
    }

    /**
     * Assert the middleware rejected the request with the real 403 envelope.
     */
    private function assertRejected(Response $response): void
    {
        $this->assertSame(403, $response->getStatusCode(), 'Expected a 403 CSRF rejection');
        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body, 'Rejection must carry a JSON error envelope');
        $this->assertTrue($body['error'] ?? false, 'envelope.error must be true');
        $this->assertSame('CSRF_INVALID', $body['code'] ?? null);
        $this->assertSame(403, $body['status'] ?? null);
        $this->assertArrayHasKey('message', $body);
    }

    private function formToken(array $extraClaims = [], ?string $secret = null): string
    {
        return Auth::getToken(array_merge(['type' => 'form'], $extraClaims), $secret ?? $this->secret, 60);
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Forge a genuinely-signed JWT whose exp is in the past — so the HMAC
     * verification passes but the expiry check rejects it. Mirrors the
     * framework's HS256 signing (Auth::sign) exactly. Auth::getToken cannot
     * emit a past exp (it only sets exp when expiresIn > 0), so we sign by hand.
     */
    private function forgeExpiredToken(): string
    {
        $now = time();
        $header = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $payload = self::b64url(json_encode([
            'type' => 'form',
            'iat' => $now - 7200,
            'exp' => $now - 3600,
        ], JSON_UNESCAPED_SLASHES));
        $sig = self::b64url(hash_hmac('sha256', "{$header}.{$payload}", $this->secret, true));
        return "{$header}.{$payload}.{$sig}";
    }

    /** A REAL file-backed Session started with a known id. */
    private function startedSession(string $sessionId): Session
    {
        $session = new Session('file', [
            'path' => sys_get_temp_dir() . '/tina4-csrf-test-sessions',
        ]);
        $session->set('session_id', $sessionId);
        return $session;
    }

    // ── Interface contract (rename guard) ─────────────────────────
    // One consolidated existence guard for the middleware surface. Cheap
    // rename guard; the real behaviour is covered by the classes below.

    public function testMiddlewareExposesBeforeHook(): void
    {
        foreach (['beforeCsrf'] as $method) {
            $this->assertTrue(
                method_exists(CsrfMiddleware::class, $method),
                "CsrfMiddleware is missing {$method}()"
            );
            $this->assertTrue(is_callable([CsrfMiddleware::class, $method]));
        }
    }

    // ── Safe methods are skipped ──────────────────────────────────

    #[DataProvider('safeMethods')]
    public function testSafeMethodPassesWithoutToken(string $method): void
    {
        [, $res, $passed] = $this->dispatchCsrf($this->makeRequest($method));
        $this->assertTrue($passed);
        $this->assertPassed($res);
    }

    public static function safeMethods(): array
    {
        return [['GET'], ['HEAD'], ['OPTIONS']];
    }

    // ── State-changing methods without a token are blocked ────────

    #[DataProvider('writeMethods')]
    public function testWriteWithoutTokenIsRejected403(string $method): void
    {
        [, $res, $passed] = $this->dispatchCsrf($this->makeRequest($method));
        $this->assertFalse($passed);
        $this->assertRejected($res);
    }

    public static function writeMethods(): array
    {
        return [['POST'], ['PUT'], ['PATCH'], ['DELETE']];
    }

    // ── Valid formToken in body passes (real token, real validation) ──

    #[DataProvider('writeMethods')]
    public function testValidBodyTokenPasses(string $method): void
    {
        [, $res] = $this->dispatchCsrf(
            $this->makeRequest($method, body: ['formToken' => $this->formToken()])
        );
        $this->assertPassed($res);
    }

    // ── Valid token in X-Form-Token header passes ─────────────────

    public function testValidHeaderTokenPasses(): void
    {
        [, $res] = $this->dispatchCsrf(
            $this->makeRequest('POST', headers: ['X-Form-Token' => $this->formToken()])
        );
        $this->assertPassed($res);
    }

    public function testHeaderTokenUsedWhenBodyHasNoToken(): void
    {
        // Body present but with no formToken key — the header is the fallback.
        [, $res] = $this->dispatchCsrf(
            $this->makeRequest('POST', body: ['name' => 'Alice'], headers: ['X-Form-Token' => $this->formToken()])
        );
        $this->assertPassed($res);
    }

    public function testBodyTokenTakesPrecedenceOverHeader(): void
    {
        // A valid body token wins even when the header carries garbage —
        // the middleware checks the body first.
        [, $res] = $this->dispatchCsrf($this->makeRequest(
            'POST',
            body: ['formToken' => $this->formToken()],
            headers: ['X-Form-Token' => 'not.a.token'],
        ));
        $this->assertPassed($res);
    }

    // ── Token rejected when sent in the query string ──────────────

    public function testQueryParamTokenIsRejected403(): void
    {
        // A real, otherwise-valid token in the URL is STILL rejected because
        // the URL leaks into logs/referrers/history.
        [, $res, $passed] = $this->dispatchCsrf(
            $this->makeRequest('POST', query: ['formToken' => $this->formToken()])
        );
        $this->assertFalse($passed);
        $this->assertRejected($res);
    }

    public function testQueryParamRejectionExplainsWhy(): void
    {
        [, $res] = $this->dispatchCsrf(
            $this->makeRequest('POST', query: ['formToken' => $this->formToken()])
        );
        $body = json_decode($res->getBody(), true);
        $this->assertSame('CSRF_INVALID', $body['code']);
        $this->assertStringContainsStringIgnoringCase('query string', $body['message']);
    }

    // ── Invalid / expired / forged tokens are rejected ────────────

    public function testMalformedTokenIsRejected403(): void
    {
        [, $res] = $this->dispatchCsrf(
            $this->makeRequest('POST', body: ['formToken' => 'not.a.valid.jwt'])
        );
        $this->assertRejected($res);
    }

    public function testExpiredTokenIsRejected403(): void
    {
        // A genuinely-signed token whose exp is in the past must fail real
        // validation (signature OK, expiry rejected).
        $expired = $this->forgeExpiredToken();
        // Sanity: the token is correctly signed but expired.
        $this->assertNull(Auth::validToken($expired, $this->secret), 'forged token must be expired-invalid');

        [, $res] = $this->dispatchCsrf($this->makeRequest('POST', body: ['formToken' => $expired]));
        $this->assertRejected($res);
    }

    public function testTokenSignedWithWrongSecretIsRejected403(): void
    {
        // Genuine JWT shape, signed with a different secret — the HMAC check
        // (against TINA4_SECRET) must fail.
        $forged = Auth::getToken(['type' => 'form'], 'a-different-secret', 60);
        [, $res] = $this->dispatchCsrf($this->makeRequest('POST', body: ['formToken' => $forged]));
        $this->assertRejected($res);
    }

    public function testTamperedTokenPayloadIsRejected403(): void
    {
        // Take a genuine token, mutate the payload (privilege-escalation style),
        // keep the original signature — the HMAC check must fail.
        $good = $this->formToken();
        [$header, , $sig] = explode('.', $good);
        $claims = Auth::getPayload($good);
        $claims['admin'] = true;
        $newPayload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
        $tampered = "{$header}.{$newPayload}.{$sig}";

        [, $res] = $this->dispatchCsrf($this->makeRequest('POST', body: ['formToken' => $tampered]));
        $this->assertRejected($res);
    }

    // ── ->noAuth() / @noauth routes skip CSRF (real handler metadata) ──
    // The handler metadata is exactly what Router::dispatch sets on the
    // request (an array with a `noAuth` key). Before $request->handler was
    // wired in dispatch, this bypass was DEAD CODE and a @noauth POST guarded
    // by CsrfMiddleware was wrongly blocked.

    public function testNoAuthWriteRouteSkipsCsrf(): void
    {
        // No token at all, but the route is marked noAuth → must pass.
        [, $res, $passed] = $this->dispatchCsrf(
            $this->makeRequest('POST', handler: ['noAuth' => true])
        );
        $this->assertTrue($passed);
        $this->assertPassed($res);
    }

    public function testNonNoAuthWriteRouteStillRequiresCsrf(): void
    {
        // A plain handler (noAuth=false) with no token is blocked — proving the
        // skip is specific to noAuth, not a blanket pass for any handler.
        [, $res, $passed] = $this->dispatchCsrf(
            $this->makeRequest('POST', handler: ['noAuth' => false])
        );
        $this->assertFalse($passed);
        $this->assertRejected($res);
    }

    // ── Valid Bearer auth skips CSRF (API clients) ────────────────

    public function testValidBearerSkipsCsrf(): void
    {
        $bearer = Auth::getToken(['sub' => 'api-client'], $this->secret, 60);
        [, $res, $passed] = $this->dispatchCsrf(
            $this->makeRequest('POST', headers: ['Authorization' => "Bearer {$bearer}"])
        );
        $this->assertTrue($passed);
        $this->assertPassed($res);
    }

    public function testInvalidBearerDoesNotSkipCsrf(): void
    {
        // A bogus bearer falls through to the form-token check, which then
        // fails for the missing token — 403, not a silent pass.
        [, $res, $passed] = $this->dispatchCsrf(
            $this->makeRequest('POST', headers: ['Authorization' => 'Bearer not-a-real-token'])
        );
        $this->assertFalse($passed);
        $this->assertRejected($res);
    }

    public function testBearerSignedWithWrongSecretDoesNotSkipCsrf(): void
    {
        $forgedBearer = Auth::getToken(['sub' => 'api-client'], 'wrong-secret', 60);
        [, $res, $passed] = $this->dispatchCsrf(
            $this->makeRequest('POST', headers: ['Authorization' => "Bearer {$forgedBearer}"])
        );
        $this->assertFalse($passed);
        $this->assertRejected($res);
    }

    // ── Session binding (real Session, real session_id claim) ─────

    public function testTokenSessionIdMismatchIsRejected403(): void
    {
        $token = $this->formToken(['session_id' => 'session-abc']);
        [, $res, $passed] = $this->dispatchCsrf($this->makeRequest(
            'POST',
            body: ['formToken' => $token],
            session: $this->startedSession('session-xyz'),
        ));
        $this->assertFalse($passed);
        $this->assertRejected($res);
    }

    public function testTokenSessionIdMatchPasses(): void
    {
        $token = $this->formToken(['session_id' => 'session-match-123']);
        [, $res] = $this->dispatchCsrf($this->makeRequest(
            'POST',
            body: ['formToken' => $token],
            session: $this->startedSession('session-match-123'),
        ));
        $this->assertPassed($res);
    }

    public function testTokenWithoutSessionIdSkipsBindingCheck(): void
    {
        // No session_id claim → binding check does not run, so a mismatched
        // request session is irrelevant and the request passes.
        $token = $this->formToken();
        [, $res] = $this->dispatchCsrf($this->makeRequest(
            'POST',
            body: ['formToken' => $token],
            session: $this->startedSession('some-other-session'),
        ));
        $this->assertPassed($res);
    }

    // ── Token rotation / refresh ───────────────────────────────────

    public function testRefreshedTokenStillPassesCsrf(): void
    {
        $original = $this->formToken();
        $rotated = Auth::refreshToken($original);
        $this->assertNotNull($rotated, 'refreshToken must re-issue a valid token');
        $this->assertNotNull(Auth::validToken($rotated, $this->secret));

        [, $res] = $this->dispatchCsrf($this->makeRequest('POST', body: ['formToken' => $rotated]));
        $this->assertPassed($res);
    }

    public function testRefreshedTokenPreservesSessionBinding(): void
    {
        $original = $this->formToken(['session_id' => 'sess-1']);
        $rotated = Auth::refreshToken($original);
        $this->assertNotNull($rotated);

        // Same session still matches.
        [, $okRes] = $this->dispatchCsrf($this->makeRequest(
            'POST',
            body: ['formToken' => $rotated],
            session: $this->startedSession('sess-1'),
        ));
        $this->assertPassed($okRes);

        // ...and a different session is still rejected.
        [, $badRes, $passed] = $this->dispatchCsrf($this->makeRequest(
            'POST',
            body: ['formToken' => $rotated],
            session: $this->startedSession('sess-2'),
        ));
        $this->assertFalse($passed);
        $this->assertRejected($badRes);
    }

    // ── TINA4_CSRF kill switch (real middleware behaviour) ────────
    // TINA4_CSRF=false (or 0/no, case-insensitive) disables all CSRF
    // enforcement; any other value (or unset) keeps it on. Driven through the
    // real middleware, not by re-asserting the parse expression.

    #[DataProvider('disabledValues')]
    public function testDisabledValueLetsTokenlessWriteThrough(string $value): void
    {
        putenv("TINA4_CSRF={$value}");
        [, $res, $passed] = $this->dispatchCsrf($this->makeRequest('POST')); // no token at all
        $this->assertTrue($passed, "TINA4_CSRF={$value} must disable enforcement");
        $this->assertPassed($res);
    }

    public static function disabledValues(): array
    {
        return [['false'], ['0'], ['no'], ['FALSE'], ['No']];
    }

    #[DataProvider('enabledValues')]
    public function testEnabledOrUnknownValueKeepsEnforcementOn(string $value): void
    {
        putenv("TINA4_CSRF={$value}");
        [, $res, $passed] = $this->dispatchCsrf($this->makeRequest('POST')); // no token
        $this->assertFalse($passed, "TINA4_CSRF={$value} must keep enforcement on");
        $this->assertRejected($res);
    }

    public static function enabledValues(): array
    {
        return [['true'], ['1'], ['yes'], ['anything-else']];
    }

    public function testUnsetDefaultsToEnforced(): void
    {
        putenv('TINA4_CSRF');
        [, $res, $passed] = $this->dispatchCsrf($this->makeRequest('POST'));
        $this->assertFalse($passed);
        $this->assertRejected($res);
    }

    // ── Error response structure ──────────────────────────────────

    public function test403ResponseHasFullErrorEnvelope(): void
    {
        [, $res] = $this->dispatchCsrf($this->makeRequest('POST'));
        $body = json_decode($res->getBody(), true);
        $this->assertTrue($body['error']);
        $this->assertSame('CSRF_INVALID', $body['code']);
        $this->assertSame(403, $body['status']);
        $this->assertArrayHasKey('message', $body);
        $this->assertSame(403, $res->getStatusCode());
    }

    // ── End-to-end wiring guard ───────────────────────────────────
    // Locks in the source fix: Router::dispatch must populate
    // $request->handler with the matched route's metadata (carrying noAuth)
    // BEFORE per-route middleware runs, so CsrfMiddleware's noAuth bypass is
    // live in production — not dead code. Drives the real dispatch path.

    public function testDispatchPopulatesHandlerMetadataForMiddleware(): void
    {
        $captured = null;
        $handler = function (Request $request, Response $response) use (&$captured) {
            $captured = $request->handler;
            return $response(['ok' => true]);
        };
        Router::post('/__csrf_e2e_noauth', $handler, [CsrfMiddleware::class])->noAuth();

        // POST with NO token. The framework's write-auth gate is opted out by
        // noAuth, and CsrfMiddleware must ALSO skip (via $request->handler), so
        // the handler runs and returns 200 — not a 403 from a dead bypass.
        $request = new Request(
            method: 'POST',
            path: '/__csrf_e2e_noauth',
            body: [],
            headers: ['content-type' => 'application/json'],
            ip: '127.0.0.1',
        );
        $response = Router::dispatch($request, new Response(true));

        $this->assertSame(200, $response->getStatusCode(), 'noAuth POST guarded by CsrfMiddleware must reach the handler');
        $this->assertIsArray($captured, '$request->handler must be populated before middleware runs');
        $this->assertTrue($captured['noAuth'] ?? false, 'route metadata must carry the noAuth flag');
        $this->assertSame('{"ok":true}', $response->getBody());
    }

    // ── Cross-framework conformance: the csrf_contract.json invariants ──────
    //
    // Each method name below normalises (strip "test", lowercase, drop
    // non-alphanumerics) to a `case` in
    // tina4-documentation/plan/v3/fixtures/csrf_contract.json, so the shared
    // auditor (scripts/audit-contract-fixtures.py) credits this suite. The SAME
    // case names appear in the Python (reference), Ruby and Node CSRF suites.
    // Real HS256, real Request pipeline, real Frond generation, real Session;
    // no mocks.

    // csrf-no-default-secret (SEC-01)
    public function testForgedDefaultSecretTokenIsRejected(): void
    {
        // TINA4_SECRET unset: a formToken forged with the retired public
        // 'tina4-default-secret' must be rejected (fail closed — no default).
        putenv('TINA4_SECRET');
        unset($_ENV['TINA4_SECRET']);
        $forged = Auth::getToken(['type' => 'form'], 'tina4-default-secret', 60);
        [, $res] = $this->dispatchCsrf($this->makeRequest('POST', body: ['formToken' => $forged]));
        $this->assertRejected($res);
    }

    public function testForgedBlankSecretTokenIsRejected(): void
    {
        // TINA4_SECRET unset: the resolved key is BLANK, and a blank HMAC key is
        // publicly reproducible — a token forged with '' must NOT validate. The
        // middleware fails closed: no secret means no trusted token.
        putenv('TINA4_SECRET');
        unset($_ENV['TINA4_SECRET']);
        $forged = Auth::getToken(['type' => 'form'], '', 60);
        [, $res] = $this->dispatchCsrf($this->makeRequest('POST', body: ['formToken' => $forged]));
        $this->assertRejected($res);
    }

    // csrf-frond-token-validates
    public function testAFrondEmittedFormTokenPassesCsrf(): void
    {
        // TINA4_SECRET is set by setUp(); generate a token via the REAL Frond
        // generator and confirm the middleware passes it. The generator and the
        // middleware both resolve the SAME fail-closed secret, so a legitimately
        // rendered token is never self-rejected. No active session → no binding.
        Frond::$formTokenSessionId = '';
        $frond = new Frond();
        $globals = $frond->getGlobals();
        $this->assertArrayHasKey('form_token_value', $globals, 'Frond must expose the form_token_value generator');
        $generate = $globals['form_token_value'];   // returns the raw JWT string
        $token = $generate('');
        $this->assertNotSame('', $token, 'Frond generator must emit a token');

        [, $res] = $this->dispatchCsrf($this->makeRequest('POST', body: ['formToken' => $token]));
        $this->assertPassed($res);
    }

    // csrf-type-must-be-form
    public function testANonFormJwtInTheFormTokenSlotIsRejected(): void
    {
        // A validly-signed but non-form JWT (e.g. an auth token) must not be
        // accepted in the formToken slot even though its signature verifies.
        $authJwt = Auth::getToken(['user_id' => 1], $this->secret, 60);
        [, $res] = $this->dispatchCsrf($this->makeRequest('POST', body: ['formToken' => $authJwt]));
        $this->assertRejected($res);
    }

    // csrf-session-binding
    public function testATokenBoundToAForeignSessionIsRejected(): void
    {
        // A token minted for session-A cannot be replayed against session-B.
        $token = $this->formToken(['session_id' => 'session-A']);
        [, $res] = $this->dispatchCsrf($this->makeRequest(
            'POST',
            body: ['formToken' => $token],
            session: $this->startedSession('session-B'),
        ));
        $this->assertRejected($res);
    }

    // csrf-safe-methods-skipped
    public function testASafeGetRequestSkipsCsrf(): void
    {
        [, $res, $passed] = $this->dispatchCsrf($this->makeRequest('GET'));
        $this->assertTrue($passed);
        $this->assertPassed($res);
    }

    // csrf-write-methods-gated
    public function testAPostWithoutATokenIsRejected(): void
    {
        [, $res] = $this->dispatchCsrf($this->makeRequest('POST'));
        $this->assertRejected($res);
    }

    // csrf-noauth-exempt
    public function testANoauthWriteRouteSkipsCsrf(): void
    {
        [, $res, $passed] = $this->dispatchCsrf(
            $this->makeRequest('POST', handler: ['noAuth' => true])
        );
        $this->assertTrue($passed);
        $this->assertPassed($res);
    }

    // csrf-bearer-exempt
    public function testAValidBearerTokenSkipsCsrf(): void
    {
        $bearer = Auth::getToken(['user_id' => 1], $this->secret, 60);
        [, $res, $passed] = $this->dispatchCsrf(
            $this->makeRequest('POST', headers: ['Authorization' => "Bearer {$bearer}"])
        );
        $this->assertTrue($passed);
        $this->assertPassed($res);
    }

    // csrf-query-token-rejected
    public function testAFormTokenInTheQueryStringIsRejected(): void
    {
        $token = $this->formToken();
        [, $res] = $this->dispatchCsrf($this->makeRequest('POST', query: ['formToken' => $token]));
        $this->assertRejected($res);
    }

    // csrf-env-attach
    public function testCsrfIsOffByDefault(): void
    {
        // TINA4_CSRF unset → the attach function returns false AND CsrfMiddleware
        // is NOT in the global middleware list. Save/restore the global list so
        // no state leaks to other tests.
        putenv('TINA4_CSRF');
        $saved = Middleware::getGlobal();
        try {
            Middleware::reset();
            foreach ($saved as $mw) {
                if ($mw !== CsrfMiddleware::class) {
                    Middleware::use($mw);
                }
            }
            $this->assertFalse(CsrfMiddleware::attachFromEnv(), 'unset TINA4_CSRF must not attach');
            $this->assertNotContains(CsrfMiddleware::class, Middleware::getGlobal());
        } finally {
            Middleware::reset();
            foreach ($saved as $mw) {
                Middleware::use($mw);
            }
        }
    }

    public function testCsrfTrueAttachesTheMiddleware(): void
    {
        // TINA4_CSRF=true → the attach function returns true AND CsrfMiddleware
        // IS in the global list. Save/restore the global list.
        putenv('TINA4_CSRF=true');
        $saved = Middleware::getGlobal();
        try {
            Middleware::reset();
            foreach ($saved as $mw) {
                if ($mw !== CsrfMiddleware::class) {
                    Middleware::use($mw);
                }
            }
            $this->assertTrue(CsrfMiddleware::attachFromEnv(), 'TINA4_CSRF=true must attach');
            $this->assertContains(CsrfMiddleware::class, Middleware::getGlobal());
            // Idempotent — a second attach does not duplicate the registration.
            CsrfMiddleware::attachFromEnv();
            $this->assertSame(
                1,
                count(array_filter(Middleware::getGlobal(), static fn($c) => $c === CsrfMiddleware::class)),
                'attachFromEnv must be idempotent (Middleware::use de-dupes)'
            );
        } finally {
            Middleware::reset();
            foreach ($saved as $mw) {
                Middleware::use($mw);
            }
        }
    }

    // csrf-403-envelope
    public function testTheRejectionBodyIsTheCsrfInvalidEnvelope(): void
    {
        [, $res] = $this->dispatchCsrf($this->makeRequest('POST'));
        $this->assertSame(403, $res->getStatusCode());
        $body = json_decode($res->getBody(), true);
        $this->assertTrue($body['error']);
        $this->assertSame('CSRF_INVALID', $body['code']);
        $this->assertSame(403, $body['status']);
    }
}
