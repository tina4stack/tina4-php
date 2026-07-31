<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Middleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

/**
 * A global middleware's `after*` hooks MUST run.
 *
 * REGRESSION. The pre/post middleware split (538cf99f) replaced the block that
 * assigned `$globalMiddleware`, but the READ at the end of dispatchInner
 * survived. Nothing set the variable, and PHP treats an undefined variable in
 * empty() as empty - so `!empty($globalMiddleware)` was permanently false and
 * NO global after hook ran at all. Silently, for every request.
 *
 * Nothing caught it because no test asserted that a global after hook runs:
 * every middleware test covered the BEFORE pass. This is that missing test.
 *
 * The AFTER pass runs for the WHOLE global set, both the pre-match and
 * post-match groups. Splitting the BEFORE pass by dependency (ADR-0012) says
 * nothing about the after pass - an after hook adds headers or logging and
 * needs no route metadata either way.
 *
 * NO MOCKS: real routes through the real dispatcher.
 */
class GlobalAfterMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
        Middleware::reset();
        AfterStampMw::$ran = 0;
        PreMatchAfterStampMw::$ran = 0;
        PreMatchAfterStampMw::$inFlight = 0;
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

    /** POSITIVE: the case that was broken. */
    public function testAGlobalAfterHookRunsOnAMatchedRoute(): void
    {
        Middleware::use(AfterStampMw::class);
        Router::get('/hello', fn($q, $s) => $s->json(['ok' => true]));

        $response = $this->dispatch('GET', '/hello');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, AfterStampMw::$ran, 'the global after hook did not run');
    }

    /** The after pass covers the PRE-match group too, not only post-match. */
    public function testAPreMatchMiddlewaresAfterHookAlsoRuns(): void
    {
        Middleware::use(PreMatchAfterStampMw::class);
        Router::get('/hello', fn($q, $s) => $s->json(['ok' => true]));

        $this->dispatch('GET', '/hello');

        $this->assertSame(
            1,
            PreMatchAfterStampMw::$ran,
            'a pre-match middleware was excluded from the after pass - the split '
            . 'applies to the BEFORE pass only'
        );
    }

    /**
     * The implication, asserted directly: a before/after pair must not leak.
     *
     * This is what made the bug serious rather than cosmetic in Node and
     * Python - the imbalance grew by one per request, without bound, and
     * nothing errored. Counting hook invocations alone would have missed it.
     */
    public function testAnAcquireReleasePairStaysBalanced(): void
    {
        Middleware::use(PreMatchAfterStampMw::class);
        Router::get('/hello', fn($q, $s) => $s->json(['ok' => true]));

        for ($i = 0; $i < 5; $i++) {
            $this->dispatch('GET', '/hello');
        }

        $this->assertSame(5, PreMatchAfterStampMw::$ran);
        $this->assertSame(
            0,
            PreMatchAfterStampMw::$inFlight,
            'acquire/release leaked ' . PreMatchAfterStampMw::$inFlight . ' slots over 5 requests'
        );
    }

    /** NEGATIVE: it must not fire when no route matched. */
    public function testAGlobalAfterHookDoesNotRunOnAnUnmatchedPath(): void
    {
        Middleware::use(AfterStampMw::class);

        $response = $this->dispatch('GET', '/no/such/route');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            0,
            AfterStampMw::$ran,
            'the after pass ran with no matched route - it belongs to the '
            . 'matched-route path, which is where the response is finalised'
        );
    }
}

class AfterStampMw
{
    public static int $ran = 0;

    public static function afterStamp($request, $response): array
    {
        self::$ran++;
        return [$request, $response];
    }
}

class PreMatchAfterStampMw
{
    public static bool $preMatch = true;
    public static int $ran = 0;
    /** Acquired in before, released in after - the pair that leaked elsewhere. */
    public static int $inFlight = 0;

    public static function beforeAcquire($request, $response): array
    {
        self::$inFlight++;
        return [$request, $response];
    }

    public static function afterStamp($request, $response): array
    {
        self::$inFlight--;
        self::$ran++;
        return [$request, $response];
    }
}
