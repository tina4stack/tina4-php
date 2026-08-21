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

    // -- Session-cookie isolation (#117, port of Python) ---------------------

    public function testResponseSettingSetCookieIsNotReplayedToAnotherSession(): void
    {
        // Core security regression: a GET whose response installs a Set-Cookie
        // is built for one caller, so it must NOT be replayed from cache.
        $cache = new ResponseCache(['ttl' => 60]);
        $installsSession = static function (Request $req, Response $res): Response {
            $res->header('Set-Cookie', 'session=ALICE; Path=/; HttpOnly');
            return $res->json(['v' => 'alice-secret']);
        };

        $alice = Request::create(method: 'GET', path: '/api/me');
        $cache->handle($alice, new Response(testing: true), $installsSession);

        // A second request must MISS — the handler runs, alice's body is not served.
        $ran = false;
        $bob = Request::create(method: 'GET', path: '/api/me');
        $bobRes = $cache->handle($bob, new Response(testing: true), function (Request $q, Response $r) use (&$ran): Response {
            $ran = true;
            return $r->json(['v' => 'bob']);
        });
        $this->assertTrue($ran, 'a Set-Cookie response must not be served from the shared cache');
        $this->assertStringNotContainsString('alice-secret', $bobRes->getBody());
        $this->assertSame('MISS', $bobRes->getHeader('X-Cache'));
    }

    public function testCookieRequestWithoutSharedDirectiveIsNotCached(): void
    {
        // A request carrying a Cookie is as specific as an authenticated one.
        // With no shared-cache directive on the response it must not be stored.
        $cache = new ResponseCache(['ttl' => 60]);

        $alice = Request::create(method: 'GET', path: '/api/dashboard', headers: ['cookie' => 'session=ALICE']);
        $cache->handle($alice, new Response(testing: true), $this->handlerReturning('alice-dashboard'));

        $ran = false;
        $bob = Request::create(method: 'GET', path: '/api/dashboard', headers: ['cookie' => 'session=BOB']);
        $bobRes = $cache->handle($bob, new Response(testing: true), function (Request $q, Response $r) use (&$ran): Response {
            $ran = true;
            return $r->json(['v' => 'bob-dashboard']);
        });
        $this->assertTrue($ran, 'a cookie-bearing request without a shared directive must miss');
        $this->assertStringNotContainsString('alice-dashboard', $bobRes->getBody());
        $this->assertSame('MISS', $bobRes->getHeader('X-Cache'));
    }

    public function testPrivateNoStoreNoCacheResponsesAreNotCached(): void
    {
        // Cache-Control: private / no-store / no-cache must all keep a response
        // out of the shared cache, even for cookieless traffic.
        foreach (['private', 'no-store', 'no-cache', 'no-cache="Set-Cookie"'] as $directive) {
            $cache = new ResponseCache(['ttl' => 60]);
            $refusing = static function (Request $req, Response $res) use ($directive): Response {
                $res->header('Cache-Control', $directive);
                return $res->json(['v' => 'do-not-store']);
            };

            $first = Request::create(method: 'GET', path: '/api/secret');
            $cache->handle($first, new Response(testing: true), $refusing);

            $ran = false;
            $second = Request::create(method: 'GET', path: '/api/secret');
            $res = $cache->handle($second, new Response(testing: true), function (Request $q, Response $r) use (&$ran): Response {
                $ran = true;
                return $r->json(['v' => 'recomputed']);
            });
            $this->assertTrue($ran, "Cache-Control: {$directive} must keep the response out of the cache");
            $this->assertStringContainsString('recomputed', $res->getBody());
        }
    }

    public function testCookieRequestMarkedPublicStillHits(): void
    {
        // Control: a cookie-bearing request whose response is explicitly public
        // must still be served from cache (the fix is not "disable caching").
        $cache = new ResponseCache(['ttl' => 60]);
        $publicHandler = static function (Request $req, Response $res): Response {
            $res->header('Cache-Control', 'public, max-age=60');
            return $res->json(['v' => 'shared-products']);
        };

        $first = Request::create(method: 'GET', path: '/api/products', headers: ['cookie' => 'session=ALICE']);
        $cache->handle($first, new Response(testing: true), $publicHandler);

        $ran = false;
        $second = Request::create(method: 'GET', path: '/api/products', headers: ['cookie' => 'session=BOB']);
        $res = $cache->handle($second, new Response(testing: true), function (Request $q, Response $r) use (&$ran): Response {
            $ran = true;
            return $r->json(['v' => 'recomputed']);
        });
        $this->assertFalse($ran, 'a public response stays cacheable for cookie-bearing browsers');
        $this->assertSame('HIT', $res->getHeader('X-Cache'));
        $this->assertStringContainsString('shared-products', $res->getBody());
    }

    public function testCookielessPublicTrafficStillHits(): void
    {
        // Control: ordinary cookieless traffic must still HIT.
        $cache = new ResponseCache(['ttl' => 60]);
        $first = Request::create(method: 'GET', path: '/api/anon');
        $cache->handle($first, new Response(testing: true), $this->handlerReturning('anon-body'));

        $ran = false;
        $second = Request::create(method: 'GET', path: '/api/anon');
        $res = $cache->handle($second, new Response(testing: true), function (Request $q, Response $r) use (&$ran): Response {
            $ran = true;
            return $r->json(['v' => 'recomputed']);
        });
        $this->assertFalse($ran, 'cookieless public traffic must still be served from cache');
        $this->assertSame('HIT', $res->getHeader('X-Cache'));
        $this->assertStringContainsString('anon-body', $res->getBody());
    }
}
