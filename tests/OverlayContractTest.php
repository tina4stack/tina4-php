<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Error-overlay conformance — dead-code removal, redaction, frame cap, self-throw guard.
 *
 * Feature 126. See OVERLAY-DEC-01..04 and
 * tina4-documentation/plan/v3/fixtures/overlay_contract.json.
 *
 * Four rules, driven through the REAL dispatcher (Router::dispatch) with real
 * Request objects / a real thrown 500. NO MOCKS.
 *
 * 1. WIRED PRODUCTION NO-LEAK (OVERLAY-DEC-01). renderProductionError is deleted;
 *    the real production 500 renders errors/500.twig with an empty error_message
 *    (CWE-209). A real production 500 leaks neither the message nor a traceback.
 * 2. REDACTION (OVERLAY-DEC-02). The dev overlay masks Authorization/Cookie headers
 *    and password-like body keys. PHP now RENDERS headers (a CaseInsensitiveArray)
 *    deliberately and redacts the sensitive ones — no longer relying on the accidental
 *    array-only skip that hid all headers.
 * 3. FRAME CAP (OVERLAY-DEC-03). A deep recursive stack renders a bounded page.
 * 4. SELF-THROW GUARD (OVERLAY-DEC-03). If the overlay render throws (a body value
 *    whose json_encode raises), the dispatch still returns a safe 500.
 *
 * Mutation-proved: make redact() return the value and case 2 goes RED; drop the
 * MAX_FRAMES break and case 3 goes RED; drop the try/catch around the overlay in
 * Router::dispatch and case 4 goes RED (the overlay throw escapes dispatch).
 *
 * Same case names in all four:
 *   tina4-python/tests/test_overlay_contract.py
 *   tina4-ruby/spec/overlay_contract_spec.rb
 *   tina4-nodejs/test/overlayContract.test.ts
 */

use PHPUnit\Framework\TestCase;
use Tina4\ErrorOverlay;
use Tina4\Middleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

/** A real request-body value whose json_encode raises — the "malformed edge" the
 *  overlay guard exists for. NOT a mock: the real overlay really fails on it. */
class Tina4OverlayPoison implements \JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        throw new \RuntimeException('poison jsonSerialize exploded');
    }
}

class OverlayContractTest extends TestCase
{
    private const SECRET_MARKER = 'SECRET-MARKER-do-not-leak-9f3a';
    // Secret VALUES as constants, referenced by name below, so the literal never sits
    // in a stack frame's rendered source window (the overlay shows a source window per
    // frame and this test file is on the stack). The redaction is about the REQUEST
    // table, not this test's own source.
    private const AUTH_SECRET = 'sekret-auth-71c2';
    private const COOKIE_SECRET = 'sekret-cookie-4d8e';
    private const PASSWORD_SECRET = 'hunter2-9a1f';

    protected function setUp(): void
    {
        Router::clear();
        Middleware::reset();
        putenv('TINA4_DEBUG');
        unset($_ENV['TINA4_DEBUG'], $_SERVER['TINA4_DEBUG']);
    }

    protected function tearDown(): void
    {
        Router::clear();
        Middleware::reset();
        putenv('TINA4_DEBUG');
        unset($_ENV['TINA4_DEBUG'], $_SERVER['TINA4_DEBUG']);
    }

    private function setDebug(bool $on): void
    {
        if ($on) {
            putenv('TINA4_DEBUG=true');
            $_ENV['TINA4_DEBUG'] = 'true';
        } else {
            putenv('TINA4_DEBUG');
            unset($_ENV['TINA4_DEBUG'], $_SERVER['TINA4_DEBUG']);
        }
    }

    private function dispatch(Request $request): Response
    {
        return Router::dispatch($request, new Response(testing: true));
    }

    // ---------------------------------------------- 1. wired production no-leak

    public function testAWiredProduction500DoesNotLeakTheException(): void
    {
        $this->setDebug(false);
        Router::get('/overlay-boom', function ($request, $response) {
            throw new \RuntimeException(self::SECRET_MARKER);
        });

        $result = $this->dispatch(Request::create(method: 'GET', path: '/overlay-boom'));
        $body = $result->getBody();

        $this->assertSame(500, $result->getStatusCode());
        foreach ([self::SECRET_MARKER, '#0 ', '.php:', 'Stack Trace', 'RuntimeException'] as $marker) {
            $this->assertStringNotContainsString(
                $marker,
                $body,
                "CWE-209 regression: production 500 body leaked '$marker'"
            );
        }
    }

    // ------------------------------------------------------- 2. redaction (dev)

    public function testTheDevOverlayRedactsAuthorizationAndSecretBodyFields(): void
    {
        $this->setDebug(true);
        Router::get('/overlay-secret', function ($request, $response) {
            throw new \RuntimeException('handler exploded');
        });

        $request = Request::create(
            method: 'GET',
            path: '/overlay-secret',
            body: ['password' => self::PASSWORD_SECRET, 'username' => 'alice'],
            headers: [
                'Authorization' => 'Bearer ' . self::AUTH_SECRET,
                'Cookie' => 'session=' . self::COOKIE_SECRET,
            ],
        );
        $html = $this->dispatch($request)->getBody();

        // The overlay DID render the request section (proves redaction is masking, not
        // merely hiding the whole section):
        $this->assertStringContainsString('Request Details', $html);
        $this->assertStringContainsString('alice', $html);
        $this->assertStringContainsString('[redacted]', $html);
        // ...but every secret is masked:
        foreach ([self::AUTH_SECRET, self::COOKIE_SECRET, self::PASSWORD_SECRET] as $secret) {
            $this->assertStringNotContainsString($secret, $html, "dev overlay leaked a secret: $secret");
        }
    }

    // -------------------------------------------------------------- 3. frame cap

    private static function deepRecurse(int $n): void
    {
        if ($n <= 0) {
            throw new \RuntimeException('deep stack marker');
        }
        self::deepRecurse($n - 1);
    }

    public function testADeepRecursiveStackRendersAFrameCappedPage(): void
    {
        @ini_set('xdebug.max_nesting_level', '100000');
        try {
            self::deepRecurse(5000);
            $this->fail('expected the deep recursion to throw');
        } catch (\Throwable $e) {
            $html = ErrorOverlay::renderErrorOverlay($e);
            $frameBlocks = substr_count($html, '<div style="margin-bottom:16px;">');
            $this->assertLessThanOrEqual(
                50,
                $frameBlocks,
                "frame count $frameBlocks exceeds the cap 50 — unbounded render"
            );
            $this->assertStringContainsString('more stack frames hidden', $html);
        }
    }

    // --------------------------------------------------------- 4. self-throw guard

    public function testAThrowingOverlayRenderStillReturnsASafe500(): void
    {
        $this->setDebug(true);
        Router::get('/overlay-poison', function ($request, $response) {
            throw new \RuntimeException('handler boom marker');
        });

        // The request carries an unrenderable body value; the overlay's json_encode of
        // it raises while building the request table, so the render throws.
        $request = Request::create(
            method: 'GET',
            path: '/overlay-poison',
            body: ['note' => new Tina4OverlayPoison()],
        );
        $result = $this->dispatch($request);
        $body = $result->getBody();

        $this->assertSame(500, $result->getStatusCode(), 'dispatch must still serve a 500 when the overlay throws');
        $this->assertStringNotContainsString('poison jsonSerialize exploded', $body);
        $this->assertStringNotContainsString('handler boom marker', $body);
        $this->assertStringNotContainsString('Stack Trace', $body);
    }
}
