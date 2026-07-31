<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Middleware;
use Tina4\Middleware\CorsMiddleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

/**
 * Every successful OPTIONS response carries Allow (RFC 9110 s9.3.7).
 *
 * There are TWO OPTIONS paths and they used to answer different questions:
 *
 *   bare OPTIONS  (no Origin)  - protocol introspection. Link checkers,
 *                                monitoring probes, `curl -X OPTIONS`.
 *   CORS preflight (Origin)    - a browser asking "may I send this?".
 *
 * A preflight IS an OPTIONS response, so it should carry Allow too. Measured
 * 2026-07-31: Ruby, Python and Node all dropped it on a preflight, and PHP
 * dropped it on BOTH as soon as CorsMiddleware was registered, because its
 * short-circuit fired on any OPTIONS with no Origin check.
 *
 * Allow and Access-Control-Allow-Methods are NOT interchangeable and this
 * suite asserts both: Allow is what the RESOURCE supports (derived from the
 * router), ACAM is what the CORS POLICY permits cross-origin (a configured
 * static list, as in every mainstream CORS library). A policy naming DELETE on
 * a GET-only route is still a 405, so a client that reads only ACAM is misled.
 *
 * NO MOCKS: real routes through the real dispatcher.
 *
 * Same case names in all four frameworks:
 *   tina4-ruby/spec/options_allow_conformance_spec.rb
 *   tina4-python/tests/test_options_allow_conformance.py
 *   tina4-nodejs/test/optionsAllowConformance.test.ts
 */
class OptionsAllowConformanceTest extends TestCase
{
    protected function setUp(): void
    {
        // ADR-0018 made the CORS default deny. This suite is about CORS POLICY
        // headers, so it now declares the policy it used to inherit from the old
        // permissive default. No assertion below was changed.
        putenv('TINA4_CORS_ORIGINS=*');
        $_ENV['TINA4_CORS_ORIGINS'] = '*';
        \Tina4\Middleware\CorsMiddleware::resetWarnings();
        Router::clear();
        Middleware::reset();
        Middleware::use(CorsMiddleware::class);
        Router::get('/only-get', fn($q, $s) => $s->json(['ok' => true]));
        Router::post('/only-get', fn($q, $s) => $s->json(['ok' => true]));
    }

    protected function tearDown(): void
    {
        putenv('TINA4_CORS_ORIGINS');
        unset($_ENV['TINA4_CORS_ORIGINS']);
        Router::clear();
        Middleware::reset();
    }

    private function options(array $headers = []): Response
    {
        return Router::dispatch(
            Request::create(method: 'OPTIONS', path: '/only-get', headers: $headers),
            new Response(testing: true)
        );
    }

    private function header(Response $r, string $name): ?string
    {
        foreach ($r->getHeaders() as $k => $v) {
            if (strtolower($k) === strtolower($name)) {
                return $v;
            }
        }
        return null;
    }

    /** A bare OPTIONS must reach the RFC 9110 handler, not be eaten by CORS. */
    public function testABareOptionsCarriesAllow(): void
    {
        $r = $this->options();
        $this->assertSame(204, $r->getStatusCode());
        $this->assertSame(
            'GET, POST, HEAD, OPTIONS',
            $this->header($r, 'Allow'),
            'a bare OPTIONS lost Allow - CorsMiddleware short-circuited without an Origin'
        );
    }

    /** The gap this suite was written for. */
    public function testACorsPreflightAlsoCarriesAllow(): void
    {
        $r = $this->options([
            'Origin' => 'https://example.com',
            'Access-Control-Request-Method' => 'POST',
        ]);
        $this->assertSame(204, $r->getStatusCode());
        $this->assertSame(
            'GET, POST, HEAD, OPTIONS',
            $this->header($r, 'Allow'),
            'a CORS preflight returned 204 without Allow'
        );
    }

    /** NEGATIVE: the fix must not break CORS itself. */
    public function testARealPreflightIsStillAnsweredByCors(): void
    {
        $r = $this->options([
            'Origin' => 'https://example.com',
            'Access-Control-Request-Method' => 'POST',
        ]);
        $this->assertNotNull(
            $this->header($r, 'Access-Control-Allow-Origin'),
            'the preflight lost its CORS headers'
        );
        $this->assertNotNull($this->header($r, 'Access-Control-Allow-Methods'));
    }

    /**
     * Allow describes the RESOURCE; ACAM describes the POLICY. They are
     * different values on purpose, and conflating them is the bug this pins:
     * the policy names methods the route does not implement.
     */
    public function testAllowDescribesTheResourceNotThePolicy(): void
    {
        $r = $this->options([
            'Origin' => 'https://example.com',
            'Access-Control-Request-Method' => 'POST',
        ]);
        $allow = $this->header($r, 'Allow');
        $acam = $this->header($r, 'Access-Control-Allow-Methods');

        $this->assertStringNotContainsString('DELETE', (string) $allow,
            'Allow named a method the route does not implement');
        $this->assertStringContainsString('DELETE', (string) $acam,
            'the policy list is expected to be broader than the resource');
        $this->assertNotSame($allow, $acam);
    }
}
