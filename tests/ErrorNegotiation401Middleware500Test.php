<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * #342 — the auth-gate 401 and a throwing middleware's 500 go through the SAME
 * negotiated error renderer as 404/403/500 (ERR-DEC-02).
 *
 * The defect: a browser that hit a protected route without a valid token, or a
 * route whose middleware hook threw, got a raw JSON body instead of the HTML
 * error PAGE every other framework error already negotiated. Fixed: a browser
 * gets errors/{401,500}.twig; an API client gets the JSON envelope
 * {error, code, message, status, request_id}. The 500 keeps CWE-209.
 *
 * Driven through the REAL front controller via TestClient — no mocks. A real
 * secure-by-default route for the 401; a real throwing class middleware for the
 * 500. Same case names in all four:
 *   tina4-python/tests/test_error_negotiation_401_500.py
 *   tina4-ruby/spec/error_negotiation_401_middleware500_spec.rb
 *   tina4-nodejs/test/errorNegotiation401Middleware500.test.ts
 */

use PHPUnit\Framework\TestCase;
use Tina4\Frond;
use Tina4\Middleware;
use Tina4\Response;
use Tina4\Router;
use Tina4\TestClient;
use Tina4\TestResponse;

/** A class middleware whose before* hook throws — the #342 500 path. */
class ErrNeg342ThrowingMiddleware
{
    public const SECRET = 'ERRNEG342-secret-stack-detail-do-not-leak';

    public static function beforeExplode($request, $response)
    {
        throw new \RuntimeException('middleware exploded: ' . self::SECRET);
    }
}

class ErrorNegotiation401Middleware500Test extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
        Middleware::reset();
        putenv('TINA4_SECRET=err-neg-342-secret');
        $_ENV['TINA4_SECRET'] = 'err-neg-342-secret-0123456789ab';
        putenv('TINA4_API_KEY');
        unset($_ENV['TINA4_API_KEY'], $_SERVER['TINA4_API_KEY']);
        putenv('TINA4_DEBUG');
        unset($_ENV['TINA4_DEBUG'], $_SERVER['TINA4_DEBUG']);
        Response::setFrond(new Frond('src/templates'));
    }

    protected function tearDown(): void
    {
        Router::clear();
        Middleware::reset();
        Response::setFrond(new Frond('src/templates'));
    }

    private function header(TestResponse $res, string $name): ?string
    {
        foreach ($res->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    // ----------------------------------------------------- 401 auth gate

    public function testBrowserWithoutTokenGetsTheHtml401Page(): void
    {
        Router::get('/errneg342-secure', fn($req, $res) => $res->json(['secret' => true]))->secure();
        $r = (new TestClient())->get('/errneg342-secure', headers: ['Accept' => 'text/html']);
        $this->assertSame(401, $r->status);
        $this->assertStringContainsString('text/html', (string) $this->header($r, 'Content-Type'));
        $this->assertStringContainsString('error-code', $r->body);
        $this->assertStringContainsString('>401<', $r->body);
    }

    public function testApiClientWithoutTokenGetsTheJsonEnvelope(): void
    {
        Router::get('/errneg342-secure', fn($req, $res) => $res->json(['secret' => true]))->secure();
        $r = (new TestClient())->get('/errneg342-secure', headers: ['Accept' => 'application/json']);
        $this->assertSame(401, $r->status);
        $this->assertStringContainsString('application/json', (string) $this->header($r, 'Content-Type'));
        $body = $r->json();
        $this->assertTrue($body['error']);
        $this->assertSame('UNAUTHORIZED', $body['code']);
        $this->assertSame(401, $body['status']);
        $this->assertNotEmpty($body['request_id']);
    }

    // --------------------------------------------- middleware-throw 500

    public function testBrowserGetsTheHtml500PageWithoutExceptionText(): void
    {
        Router::get('/errneg342-boom', fn($req, $res) => $res->json(['never' => true]))
            ->middleware([ErrNeg342ThrowingMiddleware::class]);
        $r = (new TestClient())->get('/errneg342-boom', headers: ['Accept' => 'text/html']);
        $this->assertSame(500, $r->status);
        $this->assertStringContainsString('text/html', (string) $this->header($r, 'Content-Type'));
        $this->assertStringContainsString('"error-code">500<', $r->body);
        $this->assertStringNotContainsString(ErrNeg342ThrowingMiddleware::SECRET, $r->body); // CWE-209
    }

    public function testApiClientGetsTheJsonEnvelopeWithoutExceptionText(): void
    {
        Router::get('/errneg342-boom', fn($req, $res) => $res->json(['never' => true]))
            ->middleware([ErrNeg342ThrowingMiddleware::class]);
        $r = (new TestClient())->get('/errneg342-boom', headers: ['Accept' => 'application/json']);
        $this->assertSame(500, $r->status);
        $this->assertStringContainsString('application/json', (string) $this->header($r, 'Content-Type'));
        $body = $r->json();
        $this->assertTrue($body['error']);
        $this->assertSame('INTERNAL_SERVER_ERROR', $body['code']);
        $this->assertSame(500, $body['status']);
        $this->assertNotEmpty($body['request_id']);
        $this->assertStringNotContainsString(ErrNeg342ThrowingMiddleware::SECRET, json_encode($body)); // CWE-209
    }
}
