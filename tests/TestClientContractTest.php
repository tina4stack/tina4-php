<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Shared cross-framework conformance for feature 131 (TestClient fidelity).
 *
 * Plan: tina4-documentation/plan/v3/features/131-test-client.md
 * Fixture: tina4-documentation/plan/v3/fixtures/test_client_contract.json
 *
 * PHP's TestClient has always dispatched through the REAL front controller
 * (Router::dispatch) — this suite is the shared conformance fixture proven
 * against all four languages, so a regression that made PHP's TestClient skip
 * a stage would be caught the same way Node's re-implemented dispatch is
 * (TC-DEC-01).
 *
 * TC-DEC-02: TestResponse used to read ONLY Response::getHeaders() (a plain
 * name=>value array — a header set twice already overwrote itself at the
 * SOURCE, Response::header()), and never included cookies at all (they live
 * in a SEPARATE store, Response::$cookies, keyed by cookie name, precisely so
 * more than one survives — folded into real wire "Set-Cookie:" lines only at
 * send() time). So TestResponse could not see a Set-Cookie AT ALL before this
 * fix — worse than a collapse, a total gap. Fixed by having TestResponse ALSO
 * read Response::cookieHeaderLines() (the same builder Server.php's raw-socket
 * writer uses) into a multi-map; getList() is the new multi accessor,
 * headers[name] stays the back-compat single (last) value.
 *
 * Four cases, identical names in all four frameworks' own idiom:
 *   - testClientResponseEqualsARealSocketRequest — THE ORACLE. Boots a REAL
 *     `php -S` server (TestServer, the established real-process helper this
 *     repo already uses for exactly this) and asserts the in-process
 *     TestClient response for an identically-defined route equals what the
 *     real socket gave back (status, body, content-type, a custom marker
 *     header).
 *   - testASecuredRouteReturns401WithoutRunningItsRouteMiddleware — locks
 *     gate-BEFORE-middleware (ADR-0012): a visible marker proves the route's
 *     own middleware never ran on a request the gate already rejected.
 *   - testASessionLoginThenAuthenticatedRequestSucceeds — locks the session
 *     stage: a login route sets $request->session->set('token', ...), the
 *     Set-Cookie is threaded BY HAND (no cookie jar — TC-NO-COOKIE-JAR is
 *     deliberately out of scope) into a second request to a ->secure() route.
 *   - testDuplicateResponseHeadersAreAllExposed — two $response->cookie()
 *     calls on one route; getList('set-cookie') returns BOTH, headers['set-cookie']
 *     still collapses to the last (back-compat).
 *
 * NO MOCKS: the oracle is a real `php -S` child process on a real socket;
 * every other case drives the real in-process dispatch (Router::dispatch)
 * through TestClient. Positive AND negative assertions throughout.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;
use Tina4\Router;
use Tina4\TestClient;

/** Visible marker a route-attached closure middleware flips when it runs. */
final class Tc131Marker
{
    public static bool $ran = false;
}

class TestClientContractTest extends TestCase
{
    private const SECRET = 'tc131-contract-secret';

    protected function setUp(): void
    {
        Router::clear();
        putenv('TINA4_SECRET=' . self::SECRET);
        Tc131Marker::$ran = false;

        Router::post('/tc131-secured-write', function ($request, $response) {
            return $response->json(['created' => true], 201);
        })->middleware([
            function ($req, $res) {
                Tc131Marker::$ran = true;
                return true;
            },
        ]);

        Router::post('/tc131-login', function ($request, $response) {
            $token = Auth::getToken(['sub' => 'tc131-user'], self::SECRET);
            $request->session->set('token', $token);
            return $response->json(['logged_in' => true]);
        })->noAuth();

        Router::get('/tc131-protected', function ($request, $response) {
            return $response->json(['ok' => true]);
        })->secure();

        Router::get('/tc131-cookies', function ($request, $response) {
            $response->cookie('tc131_a', '1');
            $response->cookie('tc131_b', '2');
            return $response->json(['ok' => true]);
        });
    }

    protected function tearDown(): void
    {
        Router::clear();
        putenv('TINA4_SECRET');
    }

    // ── the oracle ──────────────────────────────────────────────────────

    public function testClientResponseEqualsARealSocketRequest(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $root = \TempPath::dir('tina4_tc131_oracle_');
        $index = <<<PHP
        <?php
        require_once '{$autoload}';
        \\Tina4\\Router::get('/tc131-oracle', function (\\Tina4\\Request \$request, \\Tina4\\Response \$response) {
            \$response->header('X-Tc131-Marker', 'oracle');
            return \$response(['pipeline' => 'ok']);
        });
        \$result = \\Tina4\\Router::dispatch(new \\Tina4\\Request(), new \\Tina4\\Response());
        \$result->send();
        PHP;
        file_put_contents($root . '/index.php', $index);

        $server = \TestServer::start($root . '/index.php');
        try {
            $opts = ['method' => 'GET', 'ignore_errors' => true];
            $ctx = stream_context_create(['http' => $opts]);
            $liveBody = @file_get_contents($server->base() . '/tc131-oracle', false, $ctx);
            $this->assertNotFalse($liveBody, 'the real socket server must respond');

            $liveHeaders = [];
            foreach ($http_response_header ?? [] as $line) {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $liveHeaders[strtolower(trim($name))] = trim($value);
                }
            }
            // The live server is the oracle: prove IT answered before trusting
            // the comparison (a shared failure could vacuously "match").
            $this->assertSame('oracle', $liveHeaders['x-tc131-marker'] ?? null,
                'live server did not answer /tc131-oracle: ' . $server->log());

            // The IDENTICAL route, registered in THIS process, for TestClient.
            Router::get('/tc131-oracle', function ($request, $response) {
                $response->header('X-Tc131-Marker', 'oracle');
                return $response(['pipeline' => 'ok']);
            });
            $testResult = (new TestClient())->get('/tc131-oracle');

            $this->assertSame(200, $testResult->status);
            $this->assertSame($liveBody, $testResult->body);
            $this->assertSame($liveHeaders['content-type'] ?? null, $testResult->headers['content-type'] ?? null);
            $this->assertSame($liveHeaders['x-tc131-marker'] ?? null, $testResult->headers['x-tc131-marker'] ?? null);
        } finally {
            $server->stop();
            @unlink($root . '/index.php');
            @rmdir($root);
        }
    }

    // ── gate BEFORE route middleware (ADR-0012) ─────────────────────────

    public function testASecuredRouteReturns401WithoutRunningItsRouteMiddleware(): void
    {
        $this->assertFalse(Tc131Marker::$ran, 'marker must start unset');

        $response = (new TestClient())->post('/tc131-secured-write', json: ['name' => 'Mallory']);

        $this->assertSame(401, $response->status, 'a tokenless write to a secured route must 401');
        $this->assertFalse(
            Tc131Marker::$ran,
            'the route\'s own middleware ran on a request the auth gate should have '
            . 'rejected first — gate-before-middleware order (ADR-0012) is broken'
        );

        // Positive control: a VALID token lets the request through, and only
        // THEN does the route's own middleware run — proving the marker
        // mechanism itself works (a permanently-false marker would pass the
        // negative assertion above for the wrong reason).
        $token = Auth::getToken(['sub' => 'tc131-user'], self::SECRET);
        $ok = (new TestClient())->post('/tc131-secured-write', json: ['name' => 'Alice'], headers: [
            'Authorization' => "Bearer {$token}",
        ]);
        $this->assertSame(201, $ok->status);
        $this->assertTrue(Tc131Marker::$ran, 'middleware must run for an authorised request');
    }

    // ── the session stage runs ───────────────────────────────────────────

    public function testASessionLoginThenAuthenticatedRequestSucceeds(): void
    {
        $client = new TestClient();

        // Negative first: the protected route is genuinely gated.
        $bare = $client->get('/tc131-protected');
        $this->assertSame(401, $bare->status, 'the session-guarded route must reject an unauthenticated request');

        $loginResponse = $client->post('/tc131-login');
        $this->assertSame(200, $loginResponse->status);
        $setCookie = $loginResponse->headers['set-cookie'] ?? null;
        $this->assertNotNull($setCookie, 'login must set a session cookie for the session stage to have run');
        $cookiePair = explode(';', $setCookie, 2)[0];

        $protectedResponse = $client->get('/tc131-protected', headers: ['Cookie' => $cookiePair]);
        $this->assertSame(
            200,
            $protectedResponse->status,
            'replaying the session cookie must authenticate the request via the '
            . 'session-token path — this is structurally unreachable if the session '
            . 'stage never attaches $request->session'
        );
        $this->assertSame(['ok' => true], $protectedResponse->json());
    }

    // ── duplicate response headers are all exposed (TC-DEC-02) ─────────

    public function testDuplicateResponseHeadersAreAllExposed(): void
    {
        $response = (new TestClient())->get('/tc131-cookies');

        $this->assertSame(200, $response->status);
        $allCookies = $response->getList('set-cookie');
        // The framework auto-starts a session on every request (independent
        // of this route), so a THIRD, incidental Set-Cookie (tina4_session) is
        // real and expected here too — filter to the two THIS route set on
        // purpose rather than asserting a total count sensitive to that.
        $tc131Cookies = array_values(array_filter(
            $allCookies,
            fn($c) => str_starts_with($c, 'tc131_a=') || str_starts_with($c, 'tc131_b=')
        ));
        $this->assertCount(2, $tc131Cookies, 'expected 2 Set-Cookie values, got: ' . implode(' | ', $allCookies));
        $this->assertTrue(
            (bool)array_filter($tc131Cookies, fn($c) => str_starts_with($c, 'tc131_a=1')),
            'missing tc131_a cookie in: ' . implode(' | ', $allCookies)
        );
        $this->assertTrue(
            (bool)array_filter($tc131Cookies, fn($c) => str_starts_with($c, 'tc131_b=2')),
            'missing tc131_b cookie in: ' . implode(' | ', $allCookies)
        );

        // Back-compat: the single accessor still collapses to ONE value (the
        // last one sent), never a list — existing callers are unaffected.
        $this->assertIsString($response->headers['set-cookie']);
        $this->assertContains($response->headers['set-cookie'], $allCookies);

        // Negative: a header that was only ever sent once returns a one-item
        // list, not an empty one, and a header never sent returns [].
        $this->assertSame([$response->headers['content-type']], $response->getList('content-type'));
        $this->assertSame([], $response->getList('x-tc131-never-sent'));
    }
}
