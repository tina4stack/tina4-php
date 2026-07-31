<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Lock-in suite for `App::background(fn, interval)` scheduling — the PHP half
 * of tina4-python#94 (mirrors tina4-python/tests/test_background_tasks.py).
 *
 * The bug elsewhere (#94): `background(fn, interval=N)` ran a callback
 * CONCURRENTLY WITH ITSELF whenever a run outlasted the loop's timeout. Python
 * abandoned an un-cancellable ThreadPoolExecutor future and the next tick
 * started a SECOND copy alongside it; Node's `setInterval` never awaited an
 * async callback. In a real app that double-sent customer emails from an
 * outbox drainer, in ONE process.
 *
 * PHP's tick loop (Tina4\Server::runTickCallbacks, Server.php:1655) invokes the
 * callback synchronously from the idle branch of the single-threaded
 * stream_select accept loop (Server.php:344-346), so control cannot return to
 * the loop until the callback returns. These tests prove that empirically
 * rather than by reading the code.
 *
 * TIMING CONSTANTS IN THE PHP PATH (checked before choosing the numbers below):
 *   - There is NO timeout and NO floor/clamp. PHP's ONLY threshold is the raw
 *     interval: `if ($elapsed > $tick['interval'])` (Server.php:1668) — a plain
 *     Log::warning, not a cancellation. Python's `max(interval * 2, 5.0)`
 *     5-SECOND FLOOR (server.py:66) has NO PHP equivalent, so a callback does
 *     not need to be >5s here to cross the threshold.
 *   - The only other constant is the 1 ms idle timeout on stream_select
 *     (Server.php:331, `stream_select($read, $write, $except, 0, 1000)`), which
 *     sets tick granularity — it is not a floor on the callback.
 *   A 0.6s callback against a 0.05s interval is therefore 12x the interval and
 *   exceeds every threshold in the PHP path.
 *
 * NO MOCKS. Each test spawns a REAL php process running a REAL Tina4 App
 * (tests/fixtures/background_overlap_server.php) whose REAL server event loop
 * drives a REAL callback on wall-clock timing. Concurrency is measured by a
 * probe file under a real flock() — chosen so that a tick loop which ran the
 * callback in another process (i.e. did not wait for it) would still be caught.
 */

use PHPUnit\Framework\TestCase;

class BackgroundOverlapTest extends TestCase
{
    /** Slow-callback duration: 12x the interval, and past every PHP threshold. */
    private const SLOW_WORK_SECONDS = 0.6;

    /** Interval for the slow tests — the callback deliberately outlasts it. */
    private const SLOW_INTERVAL = 0.05;

    /** Wall-clock ticking window for the slow tests. */
    private const SLOW_WINDOW_SECONDS = 3.0;

    private string $probeFile = '';

    protected function setUp(): void
    {
        $this->probeFile = sys_get_temp_dir()
            . '/tina4_bg_probe_' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        if ($this->probeFile !== '' && file_exists($this->probeFile)) {
            @unlink($this->probeFile);
        }
    }

    /** Reserve a free localhost TCP port. */
    private static function freePort(): int
    {
        return \FreePort::get();
    }

    /**
     * Boot the real Tina4 server with one real background task, let it tick for
     * $windowSeconds of wall-clock time, then stop it and read the probe.
     *
     * @param float  $workSeconds How long each callback run really blocks.
     * @param float  $interval    The registered background() interval.
     * @param float  $windowSeconds How long to let the loop tick once it is up.
     * @param string $mode        'normal' or 'throw'.
     * @return array{in_flight:int, peak:int, runs:int}
     */
    private function drive(
        float $workSeconds,
        float $interval,
        float $windowSeconds,
        string $mode = 'normal'
    ): array {
        $port = self::freePort();
        $fixture = __DIR__ . '/fixtures/background_overlap_server.php';
        // ARRAY form on purpose: it execs php directly with no `sh -c` layer, so
        // proc_get_status()['pid'] IS the php server and proc_terminate() really
        // kills it. With the string form, sh is the child and terminating it can
        // ORPHAN the server, leaving it ticking after the test ends.
        $command = [
            PHP_BINARY,
            $fixture,
            $this->probeFile,
            (string)$workSeconds,
            (string)$interval,
            (string)$port,
            $mode,
        ];

        $environment = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
            // Boot the server directly (no tina4 CLI in a test), keep it quiet,
            // and keep migrations out of the measured window. All real config.
            'TINA4_OVERRIDE_CLIENT' => 'true',
            'TINA4_SUPPRESS' => 'true',
            'TINA4_AUTO_MIGRATE' => 'false',
            'TINA4_DEBUG' => 'false',
        ];

        $process = proc_open(
            $command,
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            dirname(__DIR__),
            $environment
        );
        $this->assertIsResource($process, 'background fixture process must start');

        try {
            // Wait for the loop to be genuinely ticking before starting the
            // clock, so boot time never eats the measured window.
            $booted = false;
            for ($i = 0; $i < 400; $i++) {
                if ($this->readProbe()['runs'] >= 1) {
                    $booted = true;
                    break;
                }
                usleep(25000);
            }
            $this->assertTrue($booted, 'the real server must reach its first background tick');

            usleep((int)round($windowSeconds * 1_000_000));

            return $this->readProbe();
        } finally {
            // SIGKILL the server itself. Never kill the process GROUP here: the
            // array form above puts no shell in between, so the group is the
            // TEST RUNNER's own group — a negative-pid kill would take phpunit
            // (and the shell that started it) down with it.
            proc_terminate($process, SIGKILL);
            proc_close($process);
        }
    }

    /** @return array{in_flight:int, peak:int, runs:int} */
    private function readProbe(): array
    {
        if (!file_exists($this->probeFile)) {
            return ['in_flight' => 0, 'peak' => 0, 'runs' => 0];
        }
        $handle = fopen($this->probeFile, 'r');
        flock($handle, LOCK_SH);
        $raw = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        $state = ($raw !== '' && $raw !== false) ? (json_decode($raw, true) ?: []) : [];
        return $state + ['in_flight' => 0, 'peak' => 0, 'runs' => 0];
    }

    // ── THE regression test ────────────────────────────────────────────────

    /**
     * THE #94 lock-in: a run longer than its interval must never overlap itself.
     *
     * A 0.6s callback on a 0.05s interval crosses the only threshold PHP has
     * (the raw interval — there is no 5s floor as in Python). Measured against a
     * tick loop patched not to wait for the callback this reports PEAK >= 2; the
     * invariant is PEAK == 1.
     */
    public function testSlowTaskNeverRunsConcurrentlyWithItself(): void
    {
        $probe = $this->drive(self::SLOW_WORK_SECONDS, self::SLOW_INTERVAL, self::SLOW_WINDOW_SECONDS);

        $this->assertGreaterThanOrEqual(
            2,
            $probe['runs'],
            "the loop must have ticked more than once; got {$probe['runs']}"
        );
        $this->assertSame(
            1,
            $probe['peak'],
            "background() must never run a task concurrently with itself "
            . "(peak concurrency was {$probe['peak']} — a run outlasting its interval "
            . "was not waited for and the next tick started alongside it)"
        );
    }

    /**
     * Runs are serialised, so the count is bounded by wall-clock / run-time.
     *
     * ~3s of ticking with a 0.6s callback completes at most ~5 serialised runs.
     * Overlapping execution (a tick every 0.05s) inflates this by an order of
     * magnitude.
     */
    public function testSlowTaskDefersRatherThanPilingUp(): void
    {
        $probe = $this->drive(self::SLOW_WORK_SECONDS, self::SLOW_INTERVAL, self::SLOW_WINDOW_SECONDS);

        $this->assertLessThanOrEqual(
            8,
            $probe['runs'],
            "serialised runs are bounded by wall-clock/run-time; got {$probe['runs']} "
            . "(overlap would inflate this)"
        );
    }

    // ── Controls ───────────────────────────────────────────────────────────

    /** The no-overlap rule must not stall a well-behaved fast task. */
    public function testFastTaskRunsEveryInterval(): void
    {
        $probe = $this->drive(0.0, 0.05, 0.6);

        $this->assertGreaterThanOrEqual(
            3,
            $probe['runs'],
            "a fast task must keep ticking; got {$probe['runs']}"
        );
        $this->assertSame(1, $probe['peak'], 'a fast task must not overlap either');
    }

    /** A raising callback is logged and the next interval still fires. */
    public function testCallbackErrorDoesNotKillTheLoop(): void
    {
        $probe = $this->drive(0.0, 0.05, 0.5, 'throw');

        $this->assertGreaterThanOrEqual(
            2,
            $probe['runs'],
            "the loop must survive a callback error; got {$probe['runs']} calls"
        );
    }
}
