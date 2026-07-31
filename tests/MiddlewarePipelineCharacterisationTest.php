<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Middleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

/**
 * The middleware pipeline CONTRACT (feature 7).
 *
 * Seventeen cases, worded identically in all four frameworks, so the four
 * suites can be grepped side by side and a gap in one shows up as a missing
 * sentence rather than as a subtly different test.
 *
 * Every case is asserted at BOTH public entry points, because they are two
 * different pieces of code that must agree:
 *
 *   - {@see Middleware::runBefore()} / {@see Middleware::runAfter()} — the
 *     orchestrator, which an application may call directly;
 *   - {@see Router::dispatch()} — the real dispatcher every request goes
 *     through.
 *
 * The return-value table both must obey:
 *
 *   | a Response object              | SHORT-CIRCUIT — that object IS the response, at ANY status |
 *   | the [$request, $response] pair | rebind both, continue                                      |
 *   | false                          | SHORT-CIRCUIT — response as set, 403 when still default    |
 *   | null                           | continue                                                   |
 *
 * NO MOCKS: real middleware classes, real Tina4\Request / Tina4\Response, the
 * real Router::dispatch.
 */
final class MiddlewarePipelineCharacterisationTest extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
        Middleware::reset();
        PipelineTrace::reset();
    }

    protected function tearDown(): void
    {
        Router::clear();
        Middleware::reset();
        PipelineTrace::reset();
    }

    /** Register a route whose handler stamps the trace, then dispatch to it. */
    private function dispatchTo(string $path, array $routeMiddleware = []): Response
    {
        $route = Router::get($path, static function (Request $request, Response $response) {
            PipelineTrace::$steps[] = 'handler';
            return $response->json(['handler' => true]);
        });
        if ($routeMiddleware !== []) {
            $route->middleware($routeMiddleware);
        }

        return Router::dispatch(
            Request::create(method: 'GET', path: $path),
            new Response(testing: true)
        );
    }

    private function request(): Request
    {
        return Request::create(method: 'GET', path: '/pipeline');
    }

    // ── 1 ────────────────────────────────────────────────────────

    /** global class middleware runs its before hook */
    public function testGlobalClassMiddlewareRunsItsBeforeHook(): void
    {
        [, $response] = Middleware::runBefore([PipelineStampMw::class], $this->request(), new Response(testing: true));
        $this->assertSame(['before'], PipelineTrace::$steps, 'orchestrator did not run the before hook');
        $this->assertSame(200, $response->getStatusCode());

        PipelineTrace::reset();
        Middleware::use(PipelineStampMw::class);
        $this->dispatchTo('/pipeline');

        $this->assertContains('before', PipelineTrace::$steps, 'dispatcher did not run the global before hook');
    }

    // ── 2 ────────────────────────────────────────────────────────

    /** global class middleware runs its after hook */
    public function testGlobalClassMiddlewareRunsItsAfterHook(): void
    {
        Middleware::runAfter([PipelineStampMw::class], $this->request(), new Response(testing: true));
        $this->assertSame(['after'], PipelineTrace::$steps, 'orchestrator did not run the after hook');

        PipelineTrace::reset();
        Middleware::use(PipelineStampMw::class);
        $this->dispatchTo('/pipeline');

        $this->assertContains('after', PipelineTrace::$steps, 'dispatcher did not run the global after hook');
    }

    // ── 3 ────────────────────────────────────────────────────────

    /** hooks within one class run in definition order */
    public function testHooksWithinOneClassRunInDefinitionOrder(): void
    {
        // beforeZulu is written FIRST and beforeAlpha second, so an
        // alphabetical discovery would invert them.
        Middleware::runBefore([PipelineDefinitionOrderMw::class], $this->request(), new Response(testing: true));
        $this->assertSame(['zulu', 'alpha'], PipelineTrace::$steps, 'orchestrator ran hooks out of definition order');

        PipelineTrace::reset();
        Middleware::use(PipelineDefinitionOrderMw::class);
        $this->dispatchTo('/pipeline');

        $this->assertSame(
            ['zulu', 'alpha', 'handler'],
            PipelineTrace::$steps,
            'dispatcher ran hooks out of definition order'
        );
    }

    // ── 4 ────────────────────────────────────────────────────────

    /** classes run in registration order */
    public function testClassesRunInRegistrationOrder(): void
    {
        Middleware::runBefore(
            [PipelineSecondMw::class, PipelineFirstMw::class],
            $this->request(),
            new Response(testing: true)
        );
        $this->assertSame(
            ['second', 'first'],
            PipelineTrace::$steps,
            'orchestrator ignored the order the classes were given in'
        );

        PipelineTrace::reset();
        Middleware::use(PipelineFirstMw::class);
        Middleware::use(PipelineSecondMw::class);
        $this->dispatchTo('/pipeline');

        $this->assertSame(
            ['first', 'second', 'handler'],
            PipelineTrace::$steps,
            'dispatcher ignored registration order'
        );
    }

    // ── 5 ────────────────────────────────────────────────────────

    /** a before hook that returns a 4xx pair skips the handler */
    public function testABeforeHookThatReturnsA4xxPairSkipsTheHandler(): void
    {
        [, $response] = Middleware::runBefore([PipelinePair4xxMw::class], $this->request(), new Response(testing: true));
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['deny'], PipelineTrace::$steps, 'a later hook ran after a 4xx pair');

        PipelineTrace::reset();
        Middleware::use(PipelinePair4xxMw::class);
        $response = $this->dispatchTo('/pipeline');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotContains('handler', PipelineTrace::$steps, 'the handler ran after a 4xx pair');
    }

    // ── 6 ────────────────────────────────────────────────────────

    /** a before hook that sets 4xx and returns nothing skips the handler */
    public function testABeforeHookThatSets4xxAndReturnsNothingSkipsTheHandler(): void
    {
        [, $response] = Middleware::runBefore([PipelineSilent4xxMw::class], $this->request(), new Response(testing: true));
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['deny'], PipelineTrace::$steps, 'a later hook ran after a silent 4xx');

        PipelineTrace::reset();
        Middleware::use(PipelineSilent4xxMw::class);
        $response = $this->dispatchTo('/pipeline');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertNotContains('handler', PipelineTrace::$steps, 'the handler ran after a silent 4xx');
    }

    // ── 7 ────────────────────────────────────────────────────────

    /** after hooks still run when a before hook short circuits */
    public function testAfterHooksStillRunWhenABeforeHookShortCircuits(): void
    {
        $request = $this->request();
        [$request, $response] = Middleware::runBefore([PipelineGateAuditMw::class], $request, new Response(testing: true));
        Middleware::runAfter([PipelineGateAuditMw::class], $request, $response);
        $this->assertSame(['gate', 'audit'], PipelineTrace::$steps, 'the after hook was skipped by the orchestrator');

        PipelineTrace::reset();
        Middleware::use(PipelineGateAuditMw::class);
        $response = $this->dispatchTo('/pipeline');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            ['gate', 'audit'],
            PipelineTrace::$steps,
            'the dispatcher returned the short-circuit without running the after pass'
        );
    }

    // ── 8 ────────────────────────────────────────────────────────

    /** a throwing before hook becomes a clean 500 */
    public function testAThrowingBeforeHookBecomesAClean500(): void
    {
        [, $response] = Middleware::runBefore([PipelineThrowingBeforeMw::class], $this->request(), new Response(testing: true));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Internal Server Error', $response->getJsonBody()['error'] ?? null);

        PipelineTrace::reset();
        Middleware::use(PipelineThrowingBeforeMw::class);
        $response = $this->dispatchTo('/pipeline');

        $this->assertSame(500, $response->getStatusCode());
        $this->assertNotContains('handler', PipelineTrace::$steps, 'the handler ran after a throwing before hook');
    }

    // ── 9 ────────────────────────────────────────────────────────

    /** a throwing after hook does not stop the remaining after hooks */
    public function testAThrowingAfterHookDoesNotStopTheRemainingAfterHooks(): void
    {
        [, $response] = Middleware::runAfter([PipelineThrowingAfterMw::class], $this->request(), new Response(testing: true));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['boom', 'log'], PipelineTrace::$steps, 'the orchestrator stopped at the throwing after hook');

        PipelineTrace::reset();
        Middleware::use(PipelineThrowingAfterMw::class);
        $this->dispatchTo('/pipeline');

        $this->assertSame(
            ['handler', 'boom', 'log'],
            PipelineTrace::$steps,
            'the dispatcher stopped at the throwing after hook'
        );
    }

    // ── 10 ───────────────────────────────────────────────────────

    /** hook discovery includes hooks inherited from a base class */
    public function testHookDiscoveryIncludesHooksInheritedFromABaseClass(): void
    {
        $before = Middleware::discoverMethods(PipelineSubMw::class, 'before');
        $after = Middleware::discoverMethods(PipelineSubMw::class, 'after');

        $this->assertContains('beforeBase', $before, 'an inherited before hook was not discovered');
        $this->assertContains('beforeSub', $before);
        $this->assertContains('afterBase', $after, 'an inherited after hook was not discovered');
        $this->assertContains('afterSub', $after);

        Middleware::use(PipelineSubMw::class);
        $this->dispatchTo('/pipeline');

        $this->assertContains('base:before', PipelineTrace::$steps, 'the dispatcher skipped the inherited before hook');
        $this->assertContains('base:after', PipelineTrace::$steps, 'the dispatcher skipped the inherited after hook');
    }

    // ── 11 ───────────────────────────────────────────────────────

    /** inherited before hooks run before the subclass own hooks */
    public function testInheritedBeforeHooksRunBeforeTheSubclassOwnHooks(): void
    {
        $this->assertSame(
            ['beforeBase', 'beforeSub'],
            Middleware::discoverMethods(PipelineSubMw::class, 'before'),
            'discovery put the subclass hook ahead of the base it inherits'
        );

        Middleware::runBefore([PipelineSubMw::class], $this->request(), new Response(testing: true));
        $this->assertSame(
            ['base:before', 'sub:before'],
            PipelineTrace::$steps,
            'the orchestrator ran the subclass hook before the base hook'
        );

        PipelineTrace::reset();
        Middleware::use(PipelineSubMw::class);
        $this->dispatchTo('/pipeline');

        $this->assertSame(
            'base:before',
            PipelineTrace::$steps[0] ?? null,
            'the dispatcher ran the subclass hook before the base hook'
        );
    }

    // ── 12 ───────────────────────────────────────────────────────

    /** route class middleware runs its before hook */
    public function testRouteClassMiddlewareRunsItsBeforeHook(): void
    {
        Middleware::runBefore([PipelineRouteScopedMw::class], $this->request(), new Response(testing: true));
        $this->assertSame(['route:before'], PipelineTrace::$steps);

        PipelineTrace::reset();
        $this->dispatchTo('/pipeline', [PipelineRouteScopedMw::class]);

        $this->assertSame(
            'route:before',
            PipelineTrace::$steps[0] ?? null,
            'a class attached per route did not run its before hook'
        );
    }

    // ── 13 ───────────────────────────────────────────────────────

    /** route class middleware runs its after hook */
    public function testRouteClassMiddlewareRunsItsAfterHook(): void
    {
        Middleware::runAfter([PipelineRouteScopedMw::class], $this->request(), new Response(testing: true));
        $this->assertSame(['route:after'], PipelineTrace::$steps);

        PipelineTrace::reset();
        $this->dispatchTo('/pipeline', [PipelineRouteScopedMw::class]);

        $this->assertSame(
            ['route:before', 'handler', 'route:after'],
            PipelineTrace::$steps,
            'a class attached per route never runs its after hooks'
        );
    }

    // ── 14 ───────────────────────────────────────────────────────

    /** a before hook that returns a response object short circuits */
    public function testABeforeHookThatReturnsAResponseObjectShortCircuits(): void
    {
        $answer = PipelineResponseObjectMw::$answer = (new Response(testing: true))->json(['answered' => true], 200);

        [, $response] = Middleware::runBefore([PipelineResponseObjectMw::class], $this->request(), new Response(testing: true));
        $this->assertSame($answer, $response, 'the orchestrator did not adopt the returned Response');
        $this->assertSame(['answer'], PipelineTrace::$steps, 'a later hook ran after a Response was returned');

        PipelineTrace::reset();
        Middleware::use(PipelineResponseObjectMw::class);
        $response = $this->dispatchTo('/pipeline');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['answered' => true], $response->getJsonBody());
        $this->assertSame(['answer'], PipelineTrace::$steps, 'the handler ran after a Response was returned');
    }

    // ── 15 ───────────────────────────────────────────────────────

    /** a before hook that returns a redirect response short circuits */
    public function testABeforeHookThatReturnsARedirectResponseShortCircuits(): void
    {
        // The load-bearing case: 302 is not >= 400, so the legacy status rule
        // cannot express it. Only the Response rule can.
        [, $response] = Middleware::runBefore([PipelineRedirectMw::class], $this->request(), new Response(testing: true));
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeader('Location'));
        $this->assertSame(['redirect'], PipelineTrace::$steps, 'a later hook ran after a redirect was returned');

        PipelineTrace::reset();
        Middleware::use(PipelineRedirectMw::class);
        $response = $this->dispatchTo('/pipeline');

        $this->assertSame(302, $response->getStatusCode(), 'the dispatcher ignored a returned redirect');
        $this->assertSame('/login', $response->getHeader('Location'));
        $this->assertSame(['redirect'], PipelineTrace::$steps, 'the handler ran after a redirect was returned');
    }

    // ── 16 ───────────────────────────────────────────────────────

    /** a before hook that returns false short circuits with 403 */
    public function testABeforeHookThatReturnsFalseShortCircuitsWith403(): void
    {
        [, $response] = Middleware::runBefore([PipelineFalseMw::class], $this->request(), new Response(testing: true));
        $this->assertSame(403, $response->getStatusCode(), 'the orchestrator ignored a false return');
        $this->assertSame(['deny'], PipelineTrace::$steps, 'a later hook ran after false was returned');

        PipelineTrace::reset();
        Middleware::use(PipelineFalseMw::class);
        $response = $this->dispatchTo('/pipeline');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['deny'], PipelineTrace::$steps, 'the handler ran after false was returned');
    }

    // ── 17 ───────────────────────────────────────────────────────

    /** a before hook that returns nothing continues to the handler */
    public function testABeforeHookThatReturnsNothingContinuesToTheHandler(): void
    {
        [, $response] = Middleware::runBefore([PipelineNullMw::class], $this->request(), new Response(testing: true));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['pass', 'pass-again'], PipelineTrace::$steps, 'a null return stopped the chain');

        PipelineTrace::reset();
        Middleware::use(PipelineNullMw::class);
        $response = $this->dispatchTo('/pipeline');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['pass', 'pass-again', 'handler'], PipelineTrace::$steps, 'a null return stopped the chain');
    }

    // ── PHP-specific, beyond the shared seventeen ────────────────

    /**
     * `false` sends the response the hook already set; 403 is only the fallback.
     *
     * Not one of the seventeen shared cases — it pins the PHP-specific half of
     * the `false` rule, which used to render 403 unconditionally and threw away
     * a status the hook had deliberately set.
     */
    public function testFalseKeepsAStatusTheHookAlreadySet(): void
    {
        [, $response] = Middleware::runBefore([PipelineFalseWithStatusMw::class], $this->request(), new Response(testing: true));
        $this->assertSame(402, $response->getStatusCode(), 'false overwrote a status the hook had set');

        PipelineTrace::reset();
        $response = $this->dispatchTo('/pipeline', [PipelineFalseWithStatusMw::class]);

        $this->assertSame(402, $response->getStatusCode(), 'false overwrote a status the hook had set');
        $this->assertNotContains('handler', PipelineTrace::$steps);
    }

    /**
     * Discovery keeps trait hooks, and an override keeps its base's position.
     *
     * Not one of the seventeen shared cases — traits are a PHP language
     * feature, and walking the class hierarchy by hand (rather than trusting
     * get_class_methods) is exactly the kind of rewrite that can drop a hook
     * composed in from a trait without anything noticing.
     */
    public function testDiscoveryKeepsTraitHooksAndOverridesKeepTheirBasePosition(): void
    {
        $this->assertSame(
            ['beforeOwn', 'beforeTrait'],
            Middleware::discoverMethods(PipelineTraitUserMw::class, 'before'),
            'a hook composed in from a trait was dropped'
        );

        $this->assertSame(
            ['beforeOne', 'beforeTwo', 'beforeThree'],
            Middleware::discoverMethods(PipelineOverrideSubMw::class, 'before'),
            'an overridden hook moved out of the position its base declared it in'
        );

        $this->assertSame(
            ['beforeReal'],
            Middleware::discoverMethods(PipelineNonHookMw::class, 'before'),
            'a non-static or non-public method was treated as a hook'
        );
    }

    /**
     * The orchestrator reports the short-circuit as a third return element.
     *
     * PHP-specific: a caller destructuring `[$request, $response]` is
     * unaffected (list assignment ignores extra elements), but the dispatcher
     * needs to know a 302 or a 200 Response ENDED the chain — a status check
     * alone cannot tell that apart from a hook that merely set a status.
     */
    public function testRunBeforeReportsTheShortCircuitAsAThirdElement(): void
    {
        $passed = Middleware::runBefore([PipelineNullMw::class], $this->request(), new Response(testing: true));
        $this->assertArrayHasKey(2, $passed, 'runBefore must always report the short-circuit slot');
        $this->assertNull($passed[2], 'a chain that ran to the end must report no short-circuit');

        PipelineTrace::reset();
        $stopped = Middleware::runBefore([PipelineRedirectMw::class], $this->request(), new Response(testing: true));
        $this->assertInstanceOf(
            Response::class,
            $stopped[2] ?? null,
            'a short-circuited chain must report the response that ended it'
        );
        $this->assertSame($stopped[1], $stopped[2]);
    }
}

// ── Fixtures — real middleware classes, no doubles ───────────────

/** Shared ordered trace every fixture writes into. */
final class PipelineTrace
{
    /** @var array<int, string> */
    public static array $steps = [];

    public static function reset(): void
    {
        self::$steps = [];
    }
}

class PipelineStampMw
{
    public static function beforeStamp(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'before';
        return [$request, $response];
    }

    public static function afterStamp(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'after';
        return [$request, $response];
    }
}

/** Deliberately anti-alphabetical: Zulu is declared before Alpha. */
class PipelineDefinitionOrderMw
{
    public static function beforeZulu(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'zulu';
        return [$request, $response];
    }

    public static function beforeAlpha(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'alpha';
        return [$request, $response];
    }
}

class PipelineFirstMw
{
    public static function beforeFirst(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'first';
        return [$request, $response];
    }
}

class PipelineSecondMw
{
    public static function beforeSecond(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'second';
        return [$request, $response];
    }
}

class PipelinePair4xxMw
{
    public static function beforeDeny(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'deny';
        return [$request, $response->json(['error' => 'nope'], 422)];
    }

    public static function beforeNever(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'never';
        return [$request, $response];
    }
}

class PipelineSilent4xxMw
{
    public static function beforeDeny(Request $request, Response $response): void
    {
        PipelineTrace::$steps[] = 'deny';
        $response->status(401);
    }

    public static function beforeNever(Request $request, Response $response): void
    {
        PipelineTrace::$steps[] = 'never';
    }
}

class PipelineGateAuditMw
{
    public static function beforeGate(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'gate';
        $response->status(403);
        return [$request, $response];
    }

    public static function afterAudit(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'audit';
        return [$request, $response->header('x-audited', 'yes')];
    }
}

class PipelineThrowingBeforeMw
{
    public static function beforeBoom(Request $request, Response $response): void
    {
        throw new \RuntimeException('before exploded');
    }
}

class PipelineThrowingAfterMw
{
    public static function afterBoom(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'boom';
        throw new \RuntimeException('after exploded');
    }

    public static function afterLog(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'log';
        return [$request, $response];
    }
}

class PipelineBaseMw
{
    public static function beforeBase(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'base:before';
        return [$request, $response];
    }

    public static function afterBase(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'base:after';
        return [$request, $response];
    }
}

class PipelineSubMw extends PipelineBaseMw
{
    public static function beforeSub(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'sub:before';
        return [$request, $response];
    }

    public static function afterSub(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'sub:after';
        return [$request, $response];
    }
}

class PipelineRouteScopedMw
{
    public static function beforeTouch(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'route:before';
        return [$request, $response];
    }

    public static function afterTouch(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'route:after';
        return [$request, $response];
    }
}

class PipelineResponseObjectMw
{
    public static ?Response $answer = null;

    public static function beforeAnswer(Request $request, Response $response): Response
    {
        PipelineTrace::$steps[] = 'answer';
        return self::$answer ??= (new Response(testing: true))->json(['answered' => true], 200);
    }

    public static function beforeNever(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'never';
        return [$request, $response];
    }
}

class PipelineRedirectMw
{
    public static function beforeRedirect(Request $request, Response $response): Response
    {
        PipelineTrace::$steps[] = 'redirect';
        return (new Response(testing: true))->redirect('/login', 302);
    }

    public static function beforeNever(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'never';
        return [$request, $response];
    }
}

class PipelineFalseMw
{
    public static function beforeDeny(Request $request, Response $response): bool
    {
        PipelineTrace::$steps[] = 'deny';
        return false;
    }

    public static function beforeNever(Request $request, Response $response): array
    {
        PipelineTrace::$steps[] = 'never';
        return [$request, $response];
    }
}

class PipelineFalseWithStatusMw
{
    public static function beforeDeny(Request $request, Response $response): bool
    {
        PipelineTrace::$steps[] = 'deny';
        $response->json(['error' => 'Payment Required'], 402);
        return false;
    }
}

trait PipelineHookTrait
{
    public static function beforeTrait(Request $request, Response $response): void
    {
        PipelineTrace::$steps[] = 'trait';
    }
}

class PipelineTraitUserMw
{
    use PipelineHookTrait;

    public static function beforeOwn(Request $request, Response $response): void
    {
        PipelineTrace::$steps[] = 'own';
    }
}

class PipelineOverrideBaseMw
{
    public static function beforeOne(Request $request, Response $response): void {}

    public static function beforeTwo(Request $request, Response $response): void {}
}

class PipelineOverrideSubMw extends PipelineOverrideBaseMw
{
    public static function beforeOne(Request $request, Response $response): void {}

    public static function beforeThree(Request $request, Response $response): void {}
}

class PipelineNonHookMw
{
    public function beforeInstance(Request $request, Response $response): void {}

    private static function beforePrivate(Request $request, Response $response): void {}

    public static function beforeReal(Request $request, Response $response): void {}
}

class PipelineNullMw
{
    public static function beforePass(Request $request, Response $response): void
    {
        PipelineTrace::$steps[] = 'pass';
    }

    public static function beforePassAgain(Request $request, Response $response): void
    {
        PipelineTrace::$steps[] = 'pass-again';
    }
}
