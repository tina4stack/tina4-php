<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Lock-in suite for stopping and DEREGISTERING a background task.
 *
 * The bug this locks in was found in Ruby: `Tina4::Background.stop_task` killed
 * the thread but left the descriptor in the `tasks` array, so the registry grew
 * for the whole process lifetime for any subsystem that starts and stops a task
 * repeatedly, and introspection reported stopped tasks as still registered.
 *
 * PHP had no per-task stop at all, so the leak could not occur here — the parity
 * gap was the missing capability. `App::background()` returns a
 * `Tina4\BackgroundTask` handle (3.13.99 — the ONE shared surface; it used to
 * return `$this`), and the stop is either `$handle->stop()` or the separate
 * `App::stopBackground($callback)` / `Server::stopTick($callback)`, which removes
 * the registration by callable identity.
 *
 * NO MOCKS. Real `App` and `Server` objects, real closures, and the REAL private
 * `Server::runTickCallbacks()` sweep driven directly — nothing here stands in
 * for a collaborator, and the scheduler exercised is the one that ships.
 */

use PHPUnit\Framework\TestCase;
use Tina4\App;
use Tina4\Server;

class BackgroundStopTest extends TestCase
{
    /**
     * Build a real App with its error handler released inside this test.
     *
     * @return App A real App instance
     */
    private function makeApp(): App
    {
        $prev = set_error_handler(static fn() => false);
        restore_error_handler();

        return new App();
    }

    /* ═══════════ App::stopBackground() ═══════════ */

    /**
     * POSITIVE: a registered task is counted; after stopBackground it is gone.
     */
    public function testStopBackgroundDeregistersTheTask(): void
    {
        $app = $this->makeApp();
        $callback = static function () {
            // real callback; never invoked in this test
        };

        $app->background($callback, 5.0);
        $this->assertSame(1, $app->backgroundTaskCount());

        $this->assertTrue($app->stopBackground($callback));

        $this->assertSame(0, $app->backgroundTaskCount());

        AppTestSupport::releaseHandlers($app);
    }

    /**
     * background() returns a BackgroundTask HANDLE (the ONE shared surface,
     * 3.13.99 — it used to return $this). Two registrations count as two, and the
     * handle's stop() deregisters just its own task.
     */
    public function testBackgroundReturnsAStopHandle(): void
    {
        $app = $this->makeApp();
        $first = static fn() => null;
        $second = static fn() => null;

        $firstHandle = $app->background($first, 5.0);
        $secondHandle = $app->background($second, 5.0);

        $this->assertInstanceOf(\Tina4\BackgroundTask::class, $firstHandle);
        $this->assertInstanceOf(\Tina4\BackgroundTask::class, $secondHandle);
        $this->assertSame(2, $app->backgroundTaskCount());

        $this->assertTrue($firstHandle->stop());
        $this->assertSame(1, $app->backgroundTaskCount());
        $this->assertTrue($secondHandle->stop());
        $this->assertSame(0, $app->backgroundTaskCount());

        AppTestSupport::releaseHandlers($app);
    }

    /**
     * Idempotent: a second stop is a safe no-op that reports it removed nothing.
     */
    public function testStopBackgroundIsIdempotent(): void
    {
        $app = $this->makeApp();
        $callback = static fn() => null;
        $app->background($callback, 5.0);

        $this->assertTrue($app->stopBackground($callback));
        $this->assertFalse($app->stopBackground($callback));
        $this->assertFalse($app->stopBackground($callback));
        $this->assertSame(0, $app->backgroundTaskCount());

        AppTestSupport::releaseHandlers($app);
    }

    /**
     * NEGATIVE: an unregistered callable removes nothing and disturbs nothing.
     */
    public function testStopBackgroundWithAnUnknownCallbackRemovesNothing(): void
    {
        $app = $this->makeApp();
        $registered = static fn() => null;
        $neverRegistered = static fn() => null;
        $app->background($registered, 5.0);

        $this->assertFalse($app->stopBackground($neverRegistered));
        $this->assertSame(1, $app->backgroundTaskCount());

        AppTestSupport::releaseHandlers($app);
    }

    /**
     * NEGATIVE: stopping one task must leave its siblings registered.
     */
    public function testStopBackgroundRemovesOnlyTheMatchingTask(): void
    {
        $app = $this->makeApp();
        $doomed = static fn() => null;
        $survivor = static fn() => null;
        $app->background($doomed, 5.0);
        $app->background($survivor, 5.0);
        $this->assertSame(2, $app->backgroundTaskCount());

        $app->stopBackground($doomed);

        $this->assertSame(1, $app->backgroundTaskCount());
        // The survivor is still there — stopping it now must succeed.
        $this->assertTrue($app->stopBackground($survivor));
        $this->assertSame(0, $app->backgroundTaskCount());

        AppTestSupport::releaseHandlers($app);
    }

    /**
     * Registering ONE callable twice needs two stops — each removes one.
     */
    public function testStopBackgroundRemovesOneRegistrationPerCall(): void
    {
        $app = $this->makeApp();
        $shared = static fn() => null;
        $app->background($shared, 5.0);
        $app->background($shared, 5.0);
        $this->assertSame(2, $app->backgroundTaskCount());

        $this->assertTrue($app->stopBackground($shared));
        $this->assertSame(1, $app->backgroundTaskCount(), 'only ONE registration may go');

        $this->assertTrue($app->stopBackground($shared));
        $this->assertSame(0, $app->backgroundTaskCount());

        AppTestSupport::releaseHandlers($app);
    }

    /**
     * THE LEAK ITSELF: repeated register/stop cycles must not grow the registry.
     */
    public function testRepeatedRegisterStopCyclesDoNotGrowTheRegistry(): void
    {
        $app = $this->makeApp();

        for ($i = 0; $i < 10; $i++) {
            $callback = static fn() => null;
            $app->background($callback, 5.0);
            $app->stopBackground($callback);
            $this->assertSame(0, $app->backgroundTaskCount(), "cycle {$i} left a task behind");
        }

        $this->assertSame(0, $app->backgroundTaskCount());

        AppTestSupport::releaseHandlers($app);
    }

    /* ═══════════ Server::stopTick() ═══════════ */

    /**
     * POSITIVE + idempotency on the server's own registry.
     */
    public function testServerStopTickDeregistersTheCallback(): void
    {
        $server = new Server('127.0.0.1', 0);
        $callback = static fn() => null;

        $server->onTick($callback, 5.0);
        $this->assertSame(1, $server->tickCallbackCount());

        $this->assertTrue($server->stopTick($callback));
        $this->assertSame(0, $server->tickCallbackCount());

        // Idempotent.
        $this->assertFalse($server->stopTick($callback));
        $this->assertSame(0, $server->tickCallbackCount());
    }

    /**
     * NEGATIVE: stopping one server tick must leave the others registered.
     */
    public function testServerStopTickRemovesOnlyTheMatchingCallback(): void
    {
        $server = new Server('127.0.0.1', 0);
        $doomed = static fn() => null;
        $survivor = static fn() => null;
        $server->onTick($doomed, 5.0);
        $server->onTick($survivor, 5.0);

        $server->stopTick($doomed);

        $this->assertSame(1, $server->tickCallbackCount());
        $this->assertTrue($server->stopTick($survivor));
    }

    /* ═══════════ The sweep is safe against a mid-sweep stop ═══════════ */

    /**
     * Drive the REAL private tick sweep the event loop calls.
     *
     * @param  Server $server The server whose callbacks should be swept
     * @return void
     */
    private function sweep(Server $server): void
    {
        $method = new \ReflectionMethod(Server::class, 'runTickCallbacks');
        $method->invoke($server);
    }

    /**
     * A callback that stops ITSELF mid-sweep must not run again, and must not
     * cause a neighbour to be skipped or run twice.
     *
     * This is the hazard behind not reindexing in stopTick(): the sweep iterates
     * the array by reference, so renumbering it mid-sweep would shift every
     * later entry onto a different key. Leaving a hole is safe — PHP skips an
     * element unset before the loop reaches it.
     */
    public function testACallbackMayStopItselfWithoutDisturbingTheSweep(): void
    {
        $server = new Server('127.0.0.1', 0);
        $selfStopRuns = 0;
        $neighbourRuns = 0;

        // Interval 0.0 so every callback is due on every sweep.
        $selfStopping = null;
        $selfStopping = static function () use ($server, &$selfStopping, &$selfStopRuns) {
            $selfStopRuns++;
            $server->stopTick($selfStopping);
        };
        $neighbour = static function () use (&$neighbourRuns) {
            $neighbourRuns++;
        };

        $server->onTick($selfStopping, 0.0);
        $server->onTick($neighbour, 0.0);

        $this->sweep($server);

        $this->assertSame(1, $selfStopRuns, 'the self-stopping task must run exactly once');
        $this->assertSame(
            1,
            $neighbourRuns,
            'the neighbour must run exactly once — not skipped by the mid-sweep removal'
        );
        $this->assertSame(1, $server->tickCallbackCount(), 'only the self-stopped task may go');

        // Second sweep: the stopped one stays stopped, the neighbour keeps running.
        $this->sweep($server);

        $this->assertSame(1, $selfStopRuns, 'a stopped task must never run again');
        $this->assertSame(2, $neighbourRuns, 'the surviving task must keep ticking');
    }

    /**
     * A callback that stops a LATER neighbour mid-sweep must prevent that
     * neighbour from running in the same sweep.
     */
    public function testStoppingALaterTaskMidSweepPreventsItRunningThatSweep(): void
    {
        $server = new Server('127.0.0.1', 0);
        $victimRuns = 0;

        $victim = static function () use (&$victimRuns) {
            $victimRuns++;
        };
        $assassin = static function () use ($server, $victim) {
            $server->stopTick($victim);
        };

        $server->onTick($assassin, 0.0);   // runs first
        $server->onTick($victim, 0.0);     // stopped before its turn

        $this->sweep($server);

        $this->assertSame(0, $victimRuns, 'a task stopped earlier in the sweep must not run');
        $this->assertSame(1, $server->tickCallbackCount());
    }

    /**
     * A still-registered task keeps running across sweeps — the control that
     * proves the sweep itself was not broken by the key-snapshot change.
     */
    public function testUnstoppedTasksKeepRunningEverySweep(): void
    {
        $server = new Server('127.0.0.1', 0);
        $runs = 0;
        $server->onTick(static function () use (&$runs) {
            $runs++;
        }, 0.0);

        $this->sweep($server);
        $this->sweep($server);
        $this->sweep($server);

        $this->assertSame(3, $runs);
        $this->assertSame(1, $server->tickCallbackCount());
    }
}
