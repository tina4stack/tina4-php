<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Shared contract suite for feature 47 — background tasks.
 *
 * Fixture: tina4-documentation/plan/v3/fixtures/backgroundtasks_contract.json
 * Decisions: BG-DEC-01 (run under the production runtime, not just the dev loop)
 * + BG-DEC-02 (ONE surface: a stop-handle + a count).
 *
 * NO MOCKS. Every case exercises the REAL runtime with a REAL side effect:
 *
 *  - "runs under the production runtime" boots the REAL persistent socket server
 *    (`App::run()` under the `cli` SAPI — what `tina4 serve` runs) in a child
 *    process, with a task scheduled via `background()` that appends to a REAL
 *    file, and asserts the file grew. That is the persistent runtime the PHP
 *    background loop actually lives in (FPM/Swoole are per-request — see below).
 *  - "guarded, not a silent drop" asserts the FPM guard: under a non-persistent
 *    web SAPI, `background()` warns LOUDLY with the remedy (never a silent drop);
 *    under `cli` it does not. Tested through the pure decision function so it runs
 *    without a live php-fpm.
 *  - the stop-handle case drives the REAL private `Server::runTickCallbacks()`
 *    sweep against a real App wired to a real Server, exactly as `run()` wires it.
 */

use PHPUnit\Framework\TestCase;
use Tina4\App;
use Tina4\BackgroundTask;
use Tina4\Server;

class BackgroundContractTest extends TestCase
{
    /** @var resource|null */
    private $proc = null;
    private string $appDir = '';
    private int $port = 0;

    private function makeApp(): App
    {
        $previous = set_error_handler(static fn() => false);
        restore_error_handler();
        return new App();
    }

    /** Drive the REAL private tick sweep the event loop calls each iteration. */
    private function sweep(Server $server): void
    {
        (new \ReflectionMethod(Server::class, 'runTickCallbacks'))->invoke($server);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->proc)) {
            $status = proc_get_status($this->proc);
            if (($status['running'] ?? false) && function_exists('posix_kill')) {
                @posix_kill($status['pid'], SIGTERM);
            }
            proc_terminate($this->proc, SIGTERM);
            @proc_close($this->proc);
            $this->proc = null;
        }
    }

    /**
     * A task scheduled via background() RUNS under the real persistent socket
     * server (`App::run()`), proven by a REAL file it wrote. This is the PHP half
     * of BG-DEC-01: the tick loop lives in the persistent server, not FPM.
     */
    public function testAScheduledTaskRunsUnderTheProductionRuntime(): void
    {
        $this->appDir = \TempPath::dir('tina4_bgtask_');
        $this->port = \FreePort::get();
        mkdir($this->appDir . '/src/routes', 0755, true);

        $counter = $this->appDir . '/ticks.txt';
        $counterLiteral = var_export($counter, true);
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $port = $this->port;

        // The child registers a background task that appends one byte per tick to
        // a real file, then runs the real persistent socket server.
        file_put_contents($this->appDir . '/index.php', <<<PHP
<?php
require '{$autoload}';
\$app = new \\Tina4\\App(__DIR__);
\$app->background(function () {
    file_put_contents({$counterLiteral}, 'x', FILE_APPEND);
}, 0.1);
\$app->run('127.0.0.1', {$port});
PHP);
        file_put_contents(
            $this->appDir . '/.env',
            "TINA4_OVERRIDE_CLIENT=true\nTINA4_NO_BROWSER=true\nTINA4_SUPPRESS=true\nTINA4_DEBUG=false\n"
        );

        $this->proc = proc_open(
            [PHP_BINARY, 'index.php'],
            [
                1 => ['file', $this->appDir . '/server.log', 'w'],
                2 => ['file', $this->appDir . '/server.log', 'a'],
            ],
            $pipes,
            $this->appDir
        );
        $this->assertIsResource($this->proc, 'could not start the test server');

        $deadline = microtime(true) + 20;
        $accepting = false;
        while (microtime(true) < $deadline) {
            $sock = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.3);
            if ($sock) {
                fclose($sock);
                $accepting = true;
                break;
            }
            usleep(150000);
        }
        $this->assertTrue(
            $accepting,
            'the socket server never accepted - log: ' . @file_get_contents($this->appDir . '/server.log')
        );

        $deadline = microtime(true) + 15;
        $ticks = 0;
        while (microtime(true) < $deadline) {
            if (is_file($counter)) {
                $ticks = strlen((string) @file_get_contents($counter));
                if ($ticks >= 2) {
                    break;
                }
            }
            usleep(100000);
        }
        $this->assertGreaterThanOrEqual(
            2,
            $ticks,
            "background() never ran under the persistent socket server (ticks={$ticks}); "
            . 'the production runtime is a silent no-op'
        );
    }

    /**
     * BG-PHP-FPM-SWOOLE-NOOP: under a non-persistent SAPI there is no long-lived
     * worker to run the tick, so background() must warn LOUDLY with the remedy —
     * never silently drop the task. `cli` (the persistent server) warns nothing.
     */
    public function testANonPersistentRuntimeIsGuardedNotASilentDrop(): void
    {
        // The persistent socket server runs ticks -> no warning, no noise.
        $this->assertNull(App::backgroundSapiWarning('cli'));

        // php-fpm has no long-lived loop -> a LOUD remedy, not a silent drop.
        $fpmWarning = App::backgroundSapiWarning('fpm-fcgi');
        $this->assertIsString($fpmWarning);
        $this->assertNotSame('', $fpmWarning);
        $this->assertStringContainsString('fpm-fcgi', $fpmWarning);
        $this->assertStringContainsString('tina4 serve', $fpmWarning);

        // apache and the built-in php -S are non-persistent too.
        $this->assertIsString(App::backgroundSapiWarning('apache2handler'));
        $this->assertIsString(App::backgroundSapiWarning('cli-server'));
    }

    /** count() climbs by one per registration (BG-DEC-02 count surface). */
    public function testCountReflectsPendingAndRunningTasks(): void
    {
        $app = $this->makeApp();
        $this->assertSame(0, $app->backgroundTaskCount());

        $first = $app->background(static fn() => null, 5.0);
        $this->assertSame(1, $app->backgroundTaskCount());
        $second = $app->background(static fn() => null, 5.0);
        $this->assertSame(2, $app->backgroundTaskCount());

        $first->stop();
        $second->stop();
        \AppTestSupport::releaseHandlers($app);
    }

    /** A stopped task leaves the registry, so count() returns to 0. */
    public function testCountReturnsToZeroWhenATaskIsStopped(): void
    {
        $app = $this->makeApp();
        $handle = $app->background(static fn() => null, 5.0);
        $this->assertSame(1, $app->backgroundTaskCount());

        $this->assertTrue($handle->stop());
        $this->assertSame(0, $app->backgroundTaskCount());
        \AppTestSupport::releaseHandlers($app);
    }

    /**
     * The handle's stop() cancels a task that is actually ticking. Driven through
     * the REAL private sweep against a real App wired to a real Server, exactly as
     * run() wires it (BG-DEC-02 handle).
     */
    public function testTheStopHandleCancelsARunningTask(): void
    {
        $app = $this->makeApp();
        $runs = 0;
        $callback = function () use (&$runs) {
            $runs++;
        };
        $handle = $app->background($callback, 0.0); // interval 0.0 => due every sweep

        // Wire a real Server the way App::run() does, so the sweep AND the handle
        // stop both hit the live loop.
        $server = new Server('127.0.0.1', 0);
        $serverProperty = new \ReflectionProperty(App::class, 'server');
        $serverProperty->setAccessible(true);
        $serverProperty->setValue($app, $server);
        $server->onTick($callback, 0.0);

        $this->sweep($server);
        $this->sweep($server);
        $this->assertGreaterThanOrEqual(2, $runs, 'the task must tick before we stop it');
        $ranBeforeStop = $runs;

        $this->assertTrue($handle->stop());
        $this->assertSame(0, $server->tickCallbackCount(), 'the live tick must be cancelled');
        $this->assertSame(0, $app->backgroundTaskCount(), 'the task must be deregistered');

        $this->sweep($server);
        $this->sweep($server);
        $this->assertSame($ranBeforeStop, $runs, 'stop() must cancel the running task');
        \AppTestSupport::releaseHandlers($app);
    }

    /** stop() is idempotent: true the first time, false thereafter — never raises. */
    public function testASecondStopIsASafeNoOp(): void
    {
        $app = $this->makeApp();
        $handle = $app->background(static fn() => null, 5.0);

        $this->assertTrue($handle->stop());
        $this->assertFalse($handle->stop());
        $this->assertFalse($handle->stop());
        $this->assertSame(0, $app->backgroundTaskCount());
        \AppTestSupport::releaseHandlers($app);
    }
}
