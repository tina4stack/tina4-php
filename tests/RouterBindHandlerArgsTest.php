<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Router::bindHandlerArgs() untyped-parameter misbinding (3.13.99 hygiene).
 *
 * Router's own docblock (bindHandlerArgs()) documents an untyped `$req`
 * handler param as valid: a route path parameter of that name wins,
 * otherwise a `Request` type hint OR the literal name `request` gets the
 * Request, and "everything else gets the Response". That last rule meant an
 * untyped, unrecognised-name param like `$req` in
 * `fn($req, $res) => $res->json(['id' => $req->params['id']])` fell straight
 * to the Response default — so BOTH $req and $res bound to the SAME Response
 * instance, and `$req->params` silently read null ("Undefined property:
 * Tina4\Response::$params") instead of the real path params.
 *
 * Fix: a positional fallback for the documented two-param arity
 * `($request, $response)` — when exactly two params are not resolved by a
 * path-parameter name and none of them already unambiguously claims the
 * request (by type hint or literal name), the FIRST one positionally is the
 * request. Matches the Python/Ruby/Node masters' handler-binding contract.
 * An explicit type hint or literal name always wins over the fallback, so
 * the existing name/type order-independence (`fn($response, $request)` with
 * either param typed or literally named) is untouched.
 *
 * Driven through the REAL Router::dispatch() pipeline (path matching,
 * reflection-based arg binding, handler invocation). NO mocks.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class RouterBindHandlerArgsTest extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
    }

    protected function tearDown(): void
    {
        Router::clear();
    }

    private function dispatch(string $path, string $routePath, callable $handler): Response
    {
        Router::get($routePath, $handler);
        $request = Request::create(method: 'GET', path: $path);
        $response = new Response(testing: true);
        return Router::dispatch($request, $response);
    }

    // ── Positive: untyped $req binds to the REQUEST, not the Response ───

    public function testUntypedReqParamReceivesTheRequestInstance(): void
    {
        $response = $this->dispatch('/widgets/42', '/widgets/{id}', function ($req, $res) {
            // Pre-fix, $req was bound to the Response: ->params does not
            // exist there, so this read null instead of the real path param.
            return $res->json([
                'reqClass' => get_class($req),
                'resClass' => get_class($res),
                'id'       => $req->params['id'] ?? null,
                'method'   => $req->method,
            ]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame(Request::class, $body['reqClass'], '$req must be the Request instance');
        $this->assertSame(Response::class, $body['resClass'], '$res must be the Response instance');
        $this->assertSame('42', (string)$body['id'], '$req->params must carry the real path param, not null');
        $this->assertSame('GET', $body['method']);
    }

    // ── Negative: the fix must not over-correct (both -> request) ───────

    public function testUntypedResParamStillReceivesTheResponseInstance(): void
    {
        $response = $this->dispatch('/widgets2/7', '/widgets2/{id}', function ($req, $res) {
            // If $res were bound to the Request (an over-correction),
            // ->json() does not exist there and this call fatals to a 500.
            return $res->json(['ok' => true]);
        });

        $this->assertSame(200, $response->getStatusCode(), '$res must be the Response (json() must not fatal)');
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['ok']);
    }

    // ── Order-independence for explicit type/name is untouched ──────────

    public function testExplicitlyTypedRequestResponseOrderStillWorks(): void
    {
        $response = $this->dispatch('/typed/5', '/typed/{id}', function (Request $request, Response $response) {
            return $response->json(['id' => $request->params['id'] ?? null]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('5', (string)$body['id']);
    }

    public function testSwappedNamedResponseRequestOrderStillWorks(): void
    {
        // $response declared FIRST, $request SECOND - the swapped, explicitly
        // named order the docblock's "order independence" promise covers.
        // The literal-name match must keep winning over the new positional
        // fallback (which would otherwise wrongly promote the first param).
        $response = $this->dispatch('/swapped/9', '/swapped/{id}', function ($response, $request) {
            return $response->json(['id' => $request->params['id'] ?? null]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('9', (string)$body['id']);
    }

    // ── Single untyped param still defaults to the response (unchanged) ─

    public function testSingleUntypedParamStillDefaultsToResponse(): void
    {
        $response = $this->dispatch('/single', '/single', function ($x) {
            // Unchanged single-param convention: the one param is the
            // response (matches the Python master's single-remaining rule).
            return $x->json(['single' => true]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['single']);
    }

    // ── A real path param interleaved between $req and $res still binds ─

    public function testPathParamInterleavedWithUntypedReqResStillBinds(): void
    {
        $response = $this->dispatch('/mix/99', '/mix/{id}', function ($id, $req, $res) {
            return $res->json([
                'idParam'  => $id,
                'reqClass' => get_class($req),
                'resClass' => get_class($res),
            ]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('99', (string)$body['idParam']);
        $this->assertSame(Request::class, $body['reqClass']);
        $this->assertSame(Response::class, $body['resClass']);
    }
}
