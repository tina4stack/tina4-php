<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Middleware;
use Tina4\Middleware\CorsMiddleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

/**
 * Global middleware runs in TWO passes, split by what it depends on.
 *
 *   PRE-match   must survive a short-circuit, needs no route metadata.
 *               CORS lives here: a browser shown a 401 with no CORS headers
 *               reports a CORS error, so the real status never reaches the
 *               developer debugging it.
 *   POST-match  reads the matched route's metadata. CSRF lives here, because
 *               it must honour a route marked ->noAuth() by reading
 *               $request->handler['noAuth'].
 *
 * PHP used to run the WHOLE global set before matching, with CORS singled out
 * by a hardcoded is_a() check. That only worked because PHP's CsrfMiddleware is
 * attached per-route rather than globally - the other three register it
 * globally, so the same ordering would break them. The flag replaces the class
 * check and says what each middleware actually depends on.
 *
 * NO MOCKS: real routes through the real dispatcher.
 *
 * Same case names in all four frameworks.
 */
class GlobalMiddlewareSplitTest extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
        Middleware::reset();
    }

    protected function tearDown(): void
    {
        Router::clear();
        Middleware::reset();
    }

    private function dispatch(string $method, string $path): Response
    {
        return Router::dispatch(
            Request::create(method: $method, path: $path),
            new Response(testing: true)
        );
    }

    public function testPreMatchMiddlewareIsSelectedByTheFlag(): void
    {
        Middleware::use(PreMatchStampMw::class);
        Middleware::use(PlainStampMw::class);

        $this->assertSame([PreMatchStampMw::class], Middleware::getPreMatch());
    }

    /** NEGATIVE: the default must be unchanged. */
    public function testMiddlewareWithoutTheFlagStillRunsAfterMatching(): void
    {
        Middleware::use(PreMatchStampMw::class);
        Middleware::use(PlainStampMw::class);

        $this->assertSame([PlainStampMw::class], Middleware::getPostMatch());
    }

    /** The shipped CORS middleware must be in the pre-match group. */
    public function testCorsMiddlewareIsPreMatch(): void
    {
        Middleware::use(CorsMiddleware::class);

        $this->assertSame([CorsMiddleware::class], Middleware::getPreMatch());
    }

    /** POSITIVE: it runs even though nothing matched. */
    public function testPreMatchMiddlewareRunsOnAPathWithNoRouteAtAll(): void
    {
        Middleware::use(PreMatchStampMw::class);

        $resp = $this->dispatch('GET', '/no/such/route');
        $this->assertSame(404, $resp->getStatusCode());
        $this->assertSame('yes', $resp->getHeaders()['X-Ran-Before-Match'] ?? null);
    }

    /**
     * POSITIVE: a pre-match middleware's output survives a short-circuited 401.
     *
     * This pins the response THREADING - the pre-match pass mutates the same
     * response the gate later short-circuits with. It does not by itself
     * distinguish the two groups (post-match survives a 401 too, by design);
     * testPostMatchMiddlewareDoesNotRunWhenNoRouteMatched is what does.
     */
    public function testPreMatchMiddlewareOutputSurvivesA401(): void
    {
        Middleware::use(PreMatchStampMw::class);
        Router::post('/secured', fn($q, $s) => $s->json(['ok' => true]));

        $resp = $this->dispatch('POST', '/secured');
        $this->assertSame(401, $resp->getStatusCode(), 'expected the write route to be secured by default');
        $this->assertSame(
            'yes',
            $resp->getHeaders()['X-Ran-Before-Match'] ?? null,
            "a pre-match middleware's header was lost on the 401"
        );
    }

    /** NEGATIVE: middleware before a route must not weaken the auth gate. */
    public function testPreMatchMiddlewareDoesNotOpenASecuredRoute(): void
    {
        Middleware::use(PreMatchStampMw::class);
        Router::post('/still-secured', fn($q, $s) => $s->json(['ok' => true]));

        $this->assertSame(401, $this->dispatch('POST', '/still-secured')->getStatusCode());
    }

    /**
     * NEGATIVE, and the case that actually discriminates the two groups.
     *
     * A post-match middleware CANNOT run when nothing matched - the dispatch
     * returns 404 before that pass is reached. That is the real difference
     * between the groups.
     *
     * It is NOT the 401: both groups run on a 401 by design, because the auth
     * gate is deliberately late (Django enforces in a view decorator after all
     * MIDDLEWARE; Laravel's `auth` is route middleware after the global and
     * group passes). A global rate limiter or access log has to see rejected
     * requests, so an early gate would be the bug.
     */
    public function testPostMatchMiddlewareDoesNotRunWhenNoRouteMatched(): void
    {
        Middleware::use(PlainStampMw::class);

        $resp = $this->dispatch('GET', '/no/such/route');
        $this->assertSame(404, $resp->getStatusCode());
        $this->assertArrayNotHasKey(
            'X-Ran-After-Match',
            $resp->getHeaders(),
            'a post-match middleware ran with no matched route - it must sit after matching'
        );
    }

    /**
     * POSITIVE counterpart: the same middleware DOES run once a route matches.
     * Without this, the test above would also pass if the middleware never ran.
     */
    public function testPostMatchMiddlewareRunsOnAMatchedRoute(): void
    {
        Middleware::use(PlainStampMw::class);
        Router::get('/matched', fn($q, $s) => $s->json(['ok' => true]));

        $resp = $this->dispatch('GET', '/matched');
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('yes', $resp->getHeaders()['X-Ran-After-Match'] ?? null);
    }

    /** The happy path must not change. */
    public function testANormalRequestIsUnaffected(): void
    {
        Middleware::use(PreMatchStampMw::class);
        Router::get('/hello', fn($q, $s) => $s->json(['ok' => true]));

        $this->assertSame(200, $this->dispatch('GET', '/hello')->getStatusCode());
    }
}

class PreMatchStampMw
{
    public static bool $preMatch = true;

    public static function beforeStamp($request, $response): array
    {
        $response->header('X-Ran-Before-Match', 'yes');
        return [$request, $response];
    }
}

class PlainStampMw
{
    public static function beforePlain($request, $response): array
    {
        // It MUST stamp: a no-op here would make the negative test vacuous.
        $response->header('X-Ran-After-Match', 'yes');
        return [$request, $response];
    }
}
