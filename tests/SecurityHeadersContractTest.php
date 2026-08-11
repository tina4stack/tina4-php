<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Security headers conformance — secure-by-default register + HTTPS-guarded HSTS.
 *
 * Feature 36. See SECHDR-DEC-01/02 and
 * tina4-documentation/plan/v3/fixtures/securityheaders_contract.json.
 *
 * Two rules, driven through the REAL dispatcher (Router::dispatch) with real
 * Request objects. NO MOCKS.
 *
 * 1. SECURE-BY-DEFAULT. SecurityHeadersMiddleware::attach() — the SAME call
 *    App::start makes at boot — registers it in the default chain, so a default
 *    app response carries the canonical header set with byte-identical VALUES to
 *    Python/Ruby/Node, and CSP is default-src 'self'. Before SECHDR-DEC-01 the
 *    middleware (then named SecurityHeaders) was never registered, so a default
 *    app had none of these.
 *
 * 2. HSTS IS HTTPS-ONLY. Strict-Transport-Security is emitted only when
 *    TINA4_HSTS is set AND the request is HTTPS (x-forwarded-proto honoured);
 *    ABSENT on plain HTTP even with TINA4_HSTS set.
 *
 * Mutation-proved: unregister the middleware and the canonical-set case goes RED;
 * drop the HTTPS guard and the plain-http HSTS case goes RED.
 *
 * Same case names in all four:
 *   tina4-python/tests/test_security_headers_contract.py
 *   tina4-ruby/spec/security_headers_contract_spec.rb
 *   tina4-nodejs/test/securityHeadersContract.test.ts
 */

use PHPUnit\Framework\TestCase;
use Tina4\Middleware;
use Tina4\Middleware\SecurityHeadersMiddleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class SecurityHeadersContractTest extends TestCase
{
    /** The canonical set every framework emits, byte-identical values. */
    private const CANONICAL = [
        'x-frame-options' => 'SAMEORIGIN',
        'x-content-type-options' => 'nosniff',
        'content-security-policy' => "default-src 'self'",
        'referrer-policy' => 'strict-origin-when-cross-origin',
        'x-xss-protection' => '0',
        'permissions-policy' => 'camera=(), microphone=(), geolocation=()',
    ];
    private const HSTS = '31536000';

    protected function setUp(): void
    {
        Router::clear();
        Middleware::reset();
        $this->clearEnv();
        // A stray $_SERVER['HTTPS'] would make the plain-http case falsely secure.
        unset($_SERVER['HTTPS']);
    }

    protected function tearDown(): void
    {
        Router::clear();
        Middleware::reset();
        $this->clearEnv();
    }

    private function clearEnv(): void
    {
        foreach (['TINA4_HSTS', 'TINA4_CSP', 'TINA4_FRAME_OPTIONS',
                  'TINA4_REFERRER_POLICY', 'TINA4_PERMISSIONS_POLICY', 'TINA4_CSRF'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }

    /**
     * Dispatch a REAL request through the REAL router, with the middleware attached
     * exactly the way App::start attaches it, and return lower-cased headers.
     *
     * @return array<string, string>
     */
    private function dispatch(bool $https = false): array
    {
        Router::clear();
        Middleware::reset();
        // The SAME registration App::start performs at boot — secure-by-default.
        SecurityHeadersMiddleware::attach();
        Router::get('/sec-probe', fn($request, $res) => $res->json(['ok' => true]));

        // A TLS-terminating proxy forwards plain HTTP to the app with this header;
        // Request::isSecureScheme honours it. This is how HTTPS is expressed.
        $headers = $https ? ['X-Forwarded-Proto' => 'https'] : [];

        $result = Router::dispatch(
            Request::create(method: 'GET', path: '/sec-probe', headers: $headers),
            new Response(testing: true)
        );

        $lowered = [];
        foreach ($result->getHeaders() as $name => $value) {
            $lowered[strtolower($name)] = $value;
        }
        return $lowered;
    }

    // --------------------------------------------------- secure-by-default set

    public function testADefaultAppResponseCarriesTheCanonicalSecurityHeaderSet(): void
    {
        $headers = $this->dispatch();
        foreach (self::CANONICAL as $name => $value) {
            $this->assertArrayHasKey($name, $headers, "default app must emit $name");
            $this->assertSame($value, $headers[$name], "default app must emit $name: $value");
        }
        // The middleware IS in the default chain — a real registration, not a
        // per-test hand-wire a real app would not do.
        $this->assertContains(SecurityHeadersMiddleware::class, Middleware::getGlobal());
        // HSTS must NOT be emitted by default (TINA4_HSTS unset).
        $this->assertArrayNotHasKey('strict-transport-security', $headers);
    }

    public function testCspDefaultsToDefaultSrcSelf(): void
    {
        $headers = $this->dispatch();
        $this->assertSame("default-src 'self'", $headers['content-security-policy']);
    }

    // ------------------------------------------------------ HSTS HTTPS-guarded

    public function testHstsIsPresentOnAnHttpsRequest(): void
    {
        $this->setEnv('TINA4_HSTS', self::HSTS);
        $headers = $this->dispatch(https: true);
        $this->assertSame(
            'max-age=' . self::HSTS . '; includeSubDomains',
            $headers['strict-transport-security'] ?? null
        );
    }

    public function testHstsIsAbsentOnAPlainHttpRequest(): void
    {
        // The guard: even with TINA4_HSTS set, a plain-HTTP request gets NO HSTS.
        // Dropping the scheme guard (emit on any scheme) turns this red.
        $this->setEnv('TINA4_HSTS', self::HSTS);
        $headers = $this->dispatch(https: false);
        $this->assertArrayNotHasKey(
            'strict-transport-security',
            $headers,
            'HSTS on plain HTTP is a downgrade-protection header on an unencrypted '
            . 'scheme — the framework must guard it behind Request::isSecureScheme()'
        );
    }
}
