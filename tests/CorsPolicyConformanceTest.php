<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * CORS policy conformance — deny by default, no wildcard+credentials, Vary: Origin.
 *
 * Feature 10 (CORS middleware) conformance suite. See ADR-0018.
 *
 * Three rules, each pinned positive AND negative, driven through the REAL
 * dispatcher (Router::dispatch) with real Request objects. NO MOCKS.
 *
 * 1. DENY BY DEFAULT. With TINA4_CORS_ORIGINS unset, no
 *    Access-Control-Allow-Origin is emitted at all and the browser's own CORS
 *    check blocks the request. "*" still works, but must be asked for.
 *
 * 2. NEVER ACAO: * WITH CREDENTIALS. The Fetch Standard's CORS check treats
 *    "*" as a literal once the request's credentials mode is "include", so the
 *    pair is rejected by every browser and a server emitting it is broken.
 *    Measured 2026-07-31: Ruby emitted exactly this pair.
 *
 * 3. VARY: ORIGIN WHENEVER ACAO IS COMPUTED FROM THE REQUEST. RFC 9110
 *    s12.5.5: a Vary field name list tells cache recipients they "MUST NOT use
 *    this response to satisfy a later request unless the later request has the
 *    same values for the listed header fields as the original request".
 *    Emitted on an allow-list MISS as well as a match. NOT emitted for a
 *    constant "*", which genuinely does not vary.
 *
 * Access-Control-Allow-Methods / -Allow-Headers are static configured lists
 * here, never derived from the request's Access-Control-Request-* headers, so
 * those field names deliberately do NOT appear in Vary.
 *
 * Same case names in all four:
 *   tina4-python/tests/test_cors_policy_conformance.py
 *   tina4-ruby/spec/cors_policy_conformance_spec.rb
 *   tina4-nodejs/test/corsPolicyConformance.test.ts
 */

use PHPUnit\Framework\TestCase;
use Tina4\Middleware;
use Tina4\Middleware\CorsMiddleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class CorsPolicyConformanceTest extends TestCase
{
    private const GOOD = 'https://good.example';
    private const EVIL = 'https://evil.example';

    protected function setUp(): void
    {
        Router::clear();
        Middleware::reset();
        $this->configure(null, null);
    }

    protected function tearDown(): void
    {
        Router::clear();
        Middleware::reset();
        $this->configure(null, null);
    }

    /**
     * Set the CORS env exactly, then register the middleware and a real route.
     */
    private function configure(?string $origins, ?string $credentials): void
    {
        foreach (['TINA4_CORS_ORIGINS' => $origins, 'TINA4_CORS_CREDENTIALS' => $credentials] as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
        // Tolerate the method's absence so this suite still RUNS against
        // pre-fix code — the red must be a real assertion failure, not a fatal
        // "undefined method", or it proves nothing about behaviour.
        if (method_exists(CorsMiddleware::class, 'resetWarnings')) {
            CorsMiddleware::resetWarnings();
        }
    }

    /**
     * Dispatch a REAL request through the REAL router and return its headers.
     *
     * @return array<string, string> Lower-cased header names to values.
     */
    private function dispatch(string $method, ?string $origin, ?Response $response = null): array
    {
        Router::clear();
        Middleware::reset();
        Middleware::use(CorsMiddleware::class);
        Router::get('/api/thing', fn($request, $res) => $res->json(['ok' => true]));

        $headers = [];
        if ($origin !== null) {
            $headers['Origin'] = $origin;
        }
        if ($method === 'OPTIONS') {
            $headers['Access-Control-Request-Method'] = 'GET';
        }

        $result = Router::dispatch(
            Request::create(method: $method, path: '/api/thing', headers: $headers),
            $response ?? new Response(testing: true)
        );

        $this->lastStatus = $result->getStatusCode();
        $lowered = [];
        foreach ($result->getHeaders() as $name => $value) {
            $lowered[strtolower($name)] = $value;
        }
        return $lowered;
    }

    private int $lastStatus = 0;

    // ------------------------------------------------------------ deny by default

    public function testDenyByDefaultEmitsNoAllowOrigin(): void
    {
        $this->configure(null, null);
        $headers = $this->dispatch('GET', self::EVIL);
        $this->assertArrayNotHasKey(
            'access-control-allow-origin',
            $headers,
            'an unconfigured app must not hand out Access-Control-Allow-Origin'
        );
    }

    public function testDenyByDefaultStillServesNonBrowserClients(): void
    {
        // CORS is a BROWSER mechanism. curl and server-to-server are unaffected.
        $this->configure(null, null);
        $headers = $this->dispatch('GET', null);
        $this->assertSame(200, $this->lastStatus);
        $this->assertArrayNotHasKey('access-control-allow-origin', $headers);
    }

    public function testExplicitWildcardStillAllowsAnyOrigin(): void
    {
        // The default changed; the capability did not.
        $this->configure('*', null);
        $headers = $this->dispatch('GET', self::EVIL);
        $this->assertSame('*', $headers['access-control-allow-origin']);
    }

    public function testPreflightStatusIsUnchangedWhenDenied(): void
    {
        // Denying must not change the status code — the browser does the blocking.
        $this->configure(null, null);
        $denied = $this->dispatch('OPTIONS', self::EVIL);
        $deniedStatus = $this->lastStatus;

        $this->configure(self::GOOD, null);
        $this->dispatch('OPTIONS', self::GOOD);

        $this->assertSame(204, $deniedStatus);
        $this->assertSame($deniedStatus, $this->lastStatus);
        $this->assertArrayNotHasKey('access-control-allow-origin', $denied);
    }

    // -------------------------------------------------- wildcard never + credentials

    public function testWildcardNeverPairsWithCredentials(): void
    {
        $this->configure('*', 'true');
        $headers = $this->dispatch('GET', self::EVIL);
        $this->assertSame('*', $headers['access-control-allow-origin']);
        $this->assertArrayNotHasKey(
            'access-control-allow-credentials',
            $headers,
            'Access-Control-Allow-Origin: * with Access-Control-Allow-Credentials: true is '
            . 'rejected by every browser — the framework must never emit the pair'
        );
    }

    public function testAllowListMatchReflectsOriginAndCredentials(): void
    {
        // The positive case: a named origin DOES get credentials.
        $this->configure(self::GOOD . ',https://other.example', 'true');
        $headers = $this->dispatch('GET', self::GOOD);
        $this->assertSame(self::GOOD, $headers['access-control-allow-origin']);
        $this->assertSame('true', $headers['access-control-allow-credentials']);
    }

    public function testAllowListMissEmitsNoAllowOrigin(): void
    {
        $this->configure(self::GOOD, 'true');
        $headers = $this->dispatch('GET', self::EVIL);
        $this->assertArrayNotHasKey('access-control-allow-origin', $headers);
        $this->assertArrayNotHasKey('access-control-allow-credentials', $headers);
    }

    // ------------------------------------------------------------------------ Vary

    public function testAllowListAlwaysVariesOnOrigin(): void
    {
        $this->configure(self::GOOD, null);
        $this->assertSame('Origin', $this->dispatch('GET', self::GOOD)['vary'], 'match must vary');

        $this->configure(self::GOOD, null);
        $this->assertSame(
            'Origin',
            $this->dispatch('GET', self::EVIL)['vary'] ?? null,
            'a MISS must vary too — otherwise a shared cache can serve this no-ACAO '
            . 'response to an origin that should have been allowed'
        );
    }

    public function testConstantWildcardDoesNotVaryOnOrigin(): void
    {
        // A constant '*' is identical for everyone; Vary would only fragment caches.
        $this->configure('*', null);
        $this->assertArrayNotHasKey('vary', $this->dispatch('GET', self::EVIL));
    }

    public function testVaryOriginDoesNotClobberAnExistingVary(): void
    {
        // Appending, not overwriting — a pre-existing Vary must survive.
        $this->configure(self::GOOD, null);
        $response = new Response(testing: true);
        $response->header('Vary', 'Accept-Encoding');
        $headers = $this->dispatch('GET', self::GOOD, $response);
        $this->assertSame('Accept-Encoding, Origin', $headers['vary']);
    }
}
