<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

/**
 * Lock-in tests for E1 (event listener isolation) + M1 (middleware
 * ordering) + M2 (middleware-throw 500 / after-on-4xx rule).
 *
 * These are the regression guards for the "visible-but-resilient"
 * philosophy: an event bus never lets one listener break the others, and
 * the middleware chain is deterministic (registration/definition order),
 * never silently skips a middleware, and a throwing middleware yields a
 * logged clean 500 instead of crashing the worker.
 *
 * Same contract is mirrored in tina4-python, tina4-ruby, tina4-nodejs.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Events;
use Tina4\Log;
use Tina4\Middleware;
use Tina4\Request;
use Tina4\Response;

// ── Middleware fixtures — DEFINITION order ≠ alphabetical ──────────────
// Names are chosen so alphabetical order (before_alpha, before_mike,
// before_zulu) differs from definition order.

class MwDefinitionOrder
{
    public static function before_zulu(Request $req, Response $resp): array
    {
        return [$req, $resp];
    }

    public static function before_mike(Request $req, Response $resp): array
    {
        return [$req, $resp];
    }

    public static function before_alpha(Request $req, Response $resp): array
    {
        return [$req, $resp];
    }
}

class MwAfterDefinitionOrder
{
    public static function after_zebra(Request $req, Response $resp): array
    {
        return [$req, $resp];
    }

    public static function after_apple(Request $req, Response $resp): array
    {
        return [$req, $resp];
    }
}

// Trace fixtures — module-level statics so the orchestrator (which calls
// $class::$method statically) can record execution order.
class MwTraceZuluAlpha
{
    /** @var array<int, string> */
    public static array $trace = [];

    public static function before_zulu(Request $req, Response $resp): array
    {
        self::$trace[] = 'zulu';
        return [$req, $resp];
    }

    public static function before_alpha(Request $req, Response $resp): array
    {
        self::$trace[] = 'alpha';
        return [$req, $resp];
    }
}

// Five registration-order fixtures. Each runs exactly one before_tag, so the
// orchestrator's cross-class iteration order is what gets traced — the
// parity guard for the Node index-skip bug.
class MwOne
{
    public static function before_tag(Request $req, Response $resp): array
    {
        MwRegOrderTrace::$trace[] = 'one';
        return [$req, $resp];
    }
}
class MwTwo
{
    public static function before_tag(Request $req, Response $resp): array
    {
        MwRegOrderTrace::$trace[] = 'two';
        return [$req, $resp];
    }
}
class MwThree
{
    public static function before_tag(Request $req, Response $resp): array
    {
        MwRegOrderTrace::$trace[] = 'three';
        return [$req, $resp];
    }
}
class MwFour
{
    public static function before_tag(Request $req, Response $resp): array
    {
        MwRegOrderTrace::$trace[] = 'four';
        return [$req, $resp];
    }
}
class MwFive
{
    public static function before_tag(Request $req, Response $resp): array
    {
        MwRegOrderTrace::$trace[] = 'five';
        return [$req, $resp];
    }
}
class MwRegOrderTrace
{
    /** @var array<int, string> */
    public static array $trace = [];
}

// Throwing-before fixture.
class MwBeforeBoom
{
    public static function before_boom(Request $req, Response $resp): void
    {
        throw new \RuntimeException('middleware exploded');
    }
}

// Throwing-after fixture (after_boom throws, after_log still runs).
class MwAfterBoomThenLog
{
    /** @var array<int, string> */
    public static array $ran = [];

    public static function after_boom(Request $req, Response $resp): array
    {
        self::$ran[] = 'boom';
        throw new \RuntimeException('after exploded');
    }

    public static function after_log(Request $req, Response $resp): array
    {
        self::$ran[] = 'log';
        return [$req, $resp];
    }
}

// before_gate short-circuits with 403; after_headers must still run.
class MwGateThenAudit
{
    /** @var array<int, string> */
    public static array $ran = [];

    public static function before_gate(Request $req, Response $resp): array
    {
        $resp->status(403);
        return [$req, $resp];
    }

    public static function after_headers(Request $req, Response $resp): array
    {
        self::$ran[] = 'after';
        $resp->header('x-audited', 'yes');
        return [$req, $resp];
    }
}

class MiddlewareEventsTest extends TestCase
{
    private string $tempLogDir;

    protected function setUp(): void
    {
        Events::clear();
        MwTraceZuluAlpha::$trace = [];
        MwRegOrderTrace::$trace = [];
        MwAfterBoomThenLog::$ran = [];
        MwGateThenAudit::$ran = [];

        // Point Log at a temp dir so we can assert "logged, never silent"
        // by reading the log file. Pin dev (TINA4_DEBUG=true) so the log FILE
        // is written — since v3.13.39 the file is dev-gated by default
        // (prod/containers are stdout-only) — and explicitly request DEBUG
        // level + both sinks (logger_contract rewrite, 2026-08-13: configure()
        // takes `level` as a string, not the retired `minLevel`/`LEVEL_DEBUG`)
        // so every emitted level lands in the file.
        $_ENV['TINA4_DEBUG'] = 'true';
        @putenv('TINA4_DEBUG=true');
        $this->tempLogDir = sys_get_temp_dir() . '/tina4_mwevt_' . uniqid();
        mkdir($this->tempLogDir, 0755, true);
        Log::reset();
        Log::configure(logDir: $this->tempLogDir, level: 'DEBUG', output: 'both');
    }

    protected function tearDown(): void
    {
        Events::clear();
        Log::reset();
        unset($_ENV['TINA4_DEBUG']);
        @putenv('TINA4_DEBUG');
        $files = glob($this->tempLogDir . '/*');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        if (is_dir($this->tempLogDir)) {
            @rmdir($this->tempLogDir);
        }
    }

    private function logContents(): string
    {
        $main = $this->tempLogDir . '/tina4.log';
        return is_file($main) ? (string) file_get_contents($main) : '';
    }

    private function makeRequest(): Request
    {
        return new Request('GET', '/test');
    }

    // ──────────────────────────────────────────────────────────────────
    // E1 — a throwing listener must NOT silently abort the rest of emit()
    // ──────────────────────────────────────────────────────────────────

    public function testMiddleListenerThrowsOthersStillRun(): void
    {
        $ran = [];

        Events::on('evt', function ($x) use (&$ran) {
            $ran[] = 'first';
            return 'r1';
        }, 30);

        Events::on('evt', function ($x) use (&$ran) {
            $ran[] = 'boom';
            throw new \RuntimeException('listener exploded');
        }, 20);

        Events::on('evt', function ($x) use (&$ran) {
            $ran[] = 'third';
            return 'r3';
        }, 10);

        $results = Events::emit('evt', 'payload'); // must NOT raise

        $this->assertSame(['first', 'boom', 'third'], $ran, 'a throwing listener aborted the rest of emit()');
        // N listeners → N result slots, in priority order, failed = null
        $this->assertSame(['r1', null, 'r3'], $results);
    }

    public function testErrorIsLoggedNeverSilent(): void
    {
        Events::on('evt.logged', function () {
            throw new \RuntimeException('kaboom');
        });

        Events::emit('evt.logged');

        $log = $this->logContents();
        $this->assertStringContainsString('evt.logged', $log, 'listener error must be logged with the event name');
        $this->assertTrue(
            str_contains($log, 'kaboom') || str_contains($log, 'RuntimeException'),
            'listener error must be logged with the error type/message',
        );
    }

    public function testStrictReraisesOnFirstError(): void
    {
        $ran = [];

        Events::on('evt.strict', function () use (&$ran) {
            $ran[] = 'boom';
            throw new \RuntimeException('strict boom');
        }, 20);

        Events::on('evt.strict', function () use (&$ran) {
            $ran[] = 'after';
        }, 10);

        try {
            Events::emitStrict('evt.strict');
            $this->fail('emitStrict must re-raise on the first listener error');
        } catch (\RuntimeException $e) {
            $this->assertSame('strict boom', $e->getMessage());
        }

        $this->assertSame(['boom'], $ran, 'strict mode must stop at the first error');
    }

    public function testNonThrowingListenersUnchanged(): void
    {
        Events::on('evt.ok', fn() => 1, 10);
        Events::on('evt.ok', fn() => 2, 5);

        $this->assertSame([1, 2], Events::emit('evt.ok'));
    }

    public function testEmitAsyncIsolatesEachListener(): void
    {
        $ran = [];

        Events::on('evt.async', function () use (&$ran) {
            $ran[] = 'first';
            return 'a1';
        }, 30);

        Events::on('evt.async', function () use (&$ran) {
            $ran[] = 'boom';
            throw new \RuntimeException('async boom');
        }, 20);

        Events::on('evt.async', function () use (&$ran) {
            $ran[] = 'third';
            return 'a3';
        }, 10);

        $results = Events::emitAsync('evt.async');

        $this->assertSame(['first', 'boom', 'third'], $ran);
        $this->assertSame(['a1', null, 'a3'], $results);
        // never silent — even the async path logs
        $this->assertStringContainsString('evt.async', $this->logContents());
    }

    public function testEmitAsyncStrictReraises(): void
    {
        Events::on('evt.async.strict', function () {
            throw new \RuntimeException('async strict');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('async strict');
        Events::emitAsyncStrict('evt.async.strict');
    }

    // ──────────────────────────────────────────────────────────────────
    // M1 — middleware ordering: definition order within a class, NOT
    //      alphabetical; registration order across classes; none skipped.
    // ──────────────────────────────────────────────────────────────────

    public function testWithinClassIsDefinitionOrderNotAlphabetical(): void
    {
        $names = Middleware::discoverMethods(MwDefinitionOrder::class, 'before');

        $this->assertSame(
            ['before_zulu', 'before_mike', 'before_alpha'],
            $names,
            'middleware methods must run in definition order',
        );

        // Prove it is NOT the old alphabetical behaviour.
        $sorted = $names;
        sort($sorted);
        $this->assertNotSame($sorted, $names);
    }

    public function testAfterMethodsAlsoDefinitionOrder(): void
    {
        $this->assertSame(
            ['after_zebra', 'after_apple'],
            Middleware::discoverMethods(MwAfterDefinitionOrder::class, 'after'),
        );
    }

    public function testDispatchRunsBeforeInDefinitionOrder(): void
    {
        Middleware::runBefore([MwTraceZuluAlpha::class], $this->makeRequest(), new Response());
        $this->assertSame(['zulu', 'alpha'], MwTraceZuluAlpha::$trace);
    }

    public function testNMiddlewareAllRunExactlyOnceInOrder(): void
    {
        // N=5 classes attached in a known order must all run exactly once, in
        // that order, none skipped. Parity guard for the Node double-advance
        // index-skip bug.
        $order = ['one', 'two', 'three', 'four', 'five'];
        $classes = [
            MwOne::class,
            MwTwo::class,
            MwThree::class,
            MwFour::class,
            MwFive::class,
        ];

        Middleware::runBefore($classes, $this->makeRequest(), new Response());

        $this->assertSame($order, MwRegOrderTrace::$trace, 'middleware skipped or reordered');
        $this->assertCount(count($order), MwRegOrderTrace::$trace);
        $this->assertSame(MwRegOrderTrace::$trace, array_values(array_unique(MwRegOrderTrace::$trace)));
    }

    // ──────────────────────────────────────────────────────────────────
    // M2 — a throwing middleware → logged clean 500 (no unhandled crash);
    //      after_* still run on a 4xx short-circuit.
    // ──────────────────────────────────────────────────────────────────

    public function testBeforeThrowYieldsClean500AndSkipsHandler(): void
    {
        [$request, $response] = Middleware::runBefore(
            [MwBeforeBoom::class],
            $this->makeRequest(),
            new Response(),
        );

        $this->assertSame(500, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('Internal Server Error', $body['error']);
        $this->assertSame(500, $body['status']);

        $log = $this->logContents();
        $this->assertStringContainsString('MwBeforeBoom', $log);
        $this->assertTrue(
            str_contains($log, 'RuntimeException') || str_contains($log, 'exploded'),
            'middleware throw must be logged',
        );
    }

    public function testAfterThrowYieldsClean500AndContinues(): void
    {
        [$request, $response] = Middleware::runAfter(
            [MwAfterBoomThenLog::class],
            $this->makeRequest(),
            new Response(),
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['boom', 'log'], MwAfterBoomThenLog::$ran, 'later after_* must still run after a throw');
    }

    public function testAfterRunsEvenWhenBeforeShortCircuits(): void
    {
        // Documented rule: after_* ALWAYS run, even when a before_* set status
        // >= 400 and the handler was skipped — so after-middleware can still
        // add headers / logging on error responses.
        [$request, $response] = Middleware::runBefore(
            [MwGateThenAudit::class],
            $this->makeRequest(),
            new Response(),
        );
        $this->assertSame(403, $response->getStatusCode());

        // The dispatcher runs the after pass on every request that MATCHED a
        // route, short-circuited or not - proven end to end by
        // MiddlewarePipelineCharacterisationTest ("after hooks still run when a
        // before hook short circuits"). This is the orchestrator half.
        [$request, $response] = Middleware::runAfter([MwGateThenAudit::class], $request, $response);

        $this->assertSame(['after'], MwGateThenAudit::$ran, 'after_* must run on a 4xx short-circuit');
        $this->assertSame('yes', $response->getHeader('x-audited'));
    }
}
