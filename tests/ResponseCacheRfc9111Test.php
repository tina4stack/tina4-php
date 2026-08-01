<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * RFC 9111 conformance for the SHARED response cache.
 *
 * The cache key is method + URL only, with no header input. Two normative
 * rules stop that from replaying one caller's response to another:
 *
 *   s3   — "if the cache is shared: the Authorization header field is not
 *          present in the request ... or a response directive is present that
 *          explicitly allows shared caching"
 *   s4.1 — "the cache MUST NOT use that stored response without revalidation
 *          unless all the presented request header fields nominated by that
 *          Vary field value match those fields in the original request"
 *
 * Test names match tina4-python, tina4-ruby and tina4-nodejs one-for-one.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Middleware\ResponseCache;
use Tina4\Request;
use Tina4\Response;

class ResponseCacheRfc9111Test extends TestCase
{
    protected function setUp(): void
    {
        ResponseCache::clearCache();
    }

    /** A handler that always returns the given body. */
    private function handlerReturning(string $body): callable
    {
        return static fn(Request $req, Response $res): Response => $res->json(['v' => $body]);
    }

    public function testResponseCacheDoesNotStoreAResponseToAnAuthorizedRequest(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);

        $alice = Request::create(method: 'GET', path: '/api/me', headers: ['authorization' => 'Bearer alice']);
        $cache->handle($alice, new Response(testing: true), $this->handlerReturning('alice-private'));

        // A different bearer on the same URL must NOT get alice's body.
        $bob = Request::create(method: 'GET', path: '/api/me', headers: ['authorization' => 'Bearer bob']);
        $bobRes = $cache->handle($bob, new Response(testing: true), $this->handlerReturning('bob-private'));
        $this->assertStringNotContainsString('alice-private', $bobRes->getBody());

        // Nor may an unauthenticated caller, where the cache sits ahead of the gate.
        $anon = Request::create(method: 'GET', path: '/api/me');
        $anonRes = $cache->handle($anon, new Response(testing: true), $this->handlerReturning('anon'));
        $this->assertStringNotContainsString('alice-private', $anonRes->getBody());
    }

    public function testResponseCacheStoresAnAuthorizedResponseWhenCacheControlPublic(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $publicHandler = static function (Request $req, Response $res): Response {
            $res->header('Cache-Control', 'public, max-age=60');
            return $res->json(['v' => 'shared-rates']);
        };

        $first = Request::create(method: 'GET', path: '/api/rates', headers: ['authorization' => 'Bearer any']);
        $cache->handle($first, new Response(testing: true), $publicHandler);

        $ran = false;
        $second = Request::create(method: 'GET', path: '/api/rates', headers: ['authorization' => 'Bearer other']);
        $res = $cache->handle($second, new Response(testing: true), function (Request $q, Response $r) use (&$ran): Response {
            $ran = true;
            return $r->json(['v' => 'recomputed']);
        });
        $this->assertFalse($ran, 'public opts an authenticated response back into the shared cache');
        $this->assertStringContainsString('shared-rates', $res->getBody());
    }

    public function testResponseCacheServesAnUnauthenticatedGet(): void
    {
        // Negative control: the fix must not simply disable caching everywhere.
        $cache = new ResponseCache(['ttl' => 60]);
        $first = Request::create(method: 'GET', path: '/api/public');
        $cache->handle($first, new Response(testing: true), $this->handlerReturning('public-body'));

        $ran = false;
        $second = Request::create(method: 'GET', path: '/api/public');
        $res = $cache->handle($second, new Response(testing: true), function (Request $q, Response $r) use (&$ran): Response {
            $ran = true;
            return $r->json(['v' => 'recomputed']);
        });
        $this->assertFalse($ran, 'an ordinary public GET must still be served from cache');
        $this->assertSame('HIT', $res->getHeader('X-Cache'));
        $this->assertStringContainsString('public-body', $res->getBody());
    }

    public function testResponseCacheHonoursVaryOnANominatedRequestHeader(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $varying = static function (Request $req, Response $res): Response {
            $res->header('Vary', 'Accept-Language');
            return $res->json(['v' => 'english']);
        };

        $en = Request::create(method: 'GET', path: '/api/greeting', headers: ['accept-language' => 'en']);
        $cache->handle($en, new Response(testing: true), $varying);

        // Same nominated value → HIT.
        $sameRan = false;
        $same = Request::create(method: 'GET', path: '/api/greeting', headers: ['accept-language' => 'en']);
        $cache->handle($same, new Response(testing: true), function (Request $q, Response $r) use (&$sameRan): Response {
            $sameRan = true;
            return $r->json(['v' => 'recomputed']);
        });
        $this->assertFalse($sameRan, 'a matching Vary header must still hit');

        // Different value → MISS, the handler runs again.
        $frRan = false;
        $fr = Request::create(method: 'GET', path: '/api/greeting', headers: ['accept-language' => 'fr']);
        $frRes = $cache->handle($fr, new Response(testing: true), function (Request $q, Response $r) use (&$frRan): Response {
            $frRan = true;
            return $r->json(['v' => 'french']);
        });
        $this->assertTrue($frRan, 'a different Vary header must not be served the stored variant');
        $this->assertStringContainsString('french', $frRes->getBody());
    }

    public function testResponseCacheNeverStoresVaryAsterisk(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $starVary = static function (Request $req, Response $res): Response {
            $res->header('Vary', '*');
            return $res->json(['v' => 'never-reusable']);
        };

        $first = Request::create(method: 'GET', path: '/api/anything');
        $cache->handle($first, new Response(testing: true), $starVary);

        $ran = false;
        $second = Request::create(method: 'GET', path: '/api/anything');
        $res = $cache->handle($second, new Response(testing: true), function (Request $q, Response $r) use (&$ran): Response {
            $ran = true;
            return $r->json(['v' => 'recomputed']);
        });
        $this->assertTrue($ran, 'Vary:* always fails to match, so it must never be stored');
        $this->assertStringContainsString('recomputed', $res->getBody());
    }
}
