<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Service;
use Tina4\ServiceRunner;

/**
 * Concrete Service subclass used as the regression fixture. Its run()
 * matches the exact shape the docs (chapter 27) teach — a cooperative
 * loop guarded by shouldStop() — so if ServiceRunner::stop() ever fails
 * to reach the instance, the shouldStop() flag will not flip.
 */
final class _StopClassInstanceFixture extends Service
{
    /** @var int Number of loop iterations completed. */
    public int $runCalls = 0;

    public function run(): void
    {
        while (!$this->shouldStop()) {
            $this->runCalls++;
            usleep(1000); // 1ms
            if ($this->runCalls > 10000) {
                break; // fixture safety net — the test never invokes run()
            }
        }
    }
}

/**
 * Regression test — parity with tina4-python #118 (7a3608b9).
 *
 * Before the fix, ServiceRunner::stop($name) only signalled the
 * SIGTERM / pid-file / stop-file path used by the forked-child and
 * plain-callable modes; it never touched
 * self::$services[$name]['instance'], so a class-based Service subclass
 * whose run() loops on shouldStop() would never see the stop request
 * and its in-process (no-fork) loop would run forever.
 *
 * These tests exercise the REAL Service subclass, the REAL
 * registerService() public API, and the REAL ServiceRunner::stop()
 * — no mocks, no doubles.
 */
final class ServiceRunnerStopClassInstanceTest extends TestCase
{
    protected function setUp(): void
    {
        // A fresh registry per test — otherwise leftover services from
        // an earlier test could match by name and mask a real regression.
        ServiceRunner::reset();
    }

    protected function tearDown(): void
    {
        ServiceRunner::reset();
    }

    public function testRunnerStopFlipsRegisteredInstanceShouldStop(): void
    {
        $name = 'parity-stop-' . bin2hex(random_bytes(3));
        $svc  = new _StopClassInstanceFixture();

        // Real registration through the public API.
        ServiceRunner::registerService($name, $svc);

        // Sanity: a fresh Service is not stopped.
        $this->assertFalse(
            $svc->shouldStop(),
            'sanity: a freshly-constructed Service must not report as stopped'
        );

        // Trigger cooperative stop through the runner.
        ServiceRunner::stop($name);

        // The fix: the runner must have called $svc->stop() on the
        // registered instance, flipping its internal $running flag.
        $this->assertTrue(
            $svc->shouldStop(),
            'ServiceRunner::stop() must call the registered Service instance\'s stop() '
            . '(parity with tina4-python #118 / 7a3608b9); without the fix the '
            . 'class-based service\'s run() loop never sees the stop request.'
        );
    }

    public function testRunnerStopWithNoRegisteredInstanceDoesNotThrow(): void
    {
        // Legacy plain-callable path or a name that was never registered
        // must remain a safe no-op — the fix is additive, not a hard
        // require. stop() on an unknown name must not throw.
        $missing = 'never-registered-' . bin2hex(random_bytes(3));

        ServiceRunner::stop($missing);   // must not throw

        $this->assertTrue(true, 'stop() on an unknown name is a no-op');
    }

    public function testRunnerStopAllRoutesToEveryRegisteredInstance(): void
    {
        // The `stop(null)` branch iterates every registered service; the
        // fix must reach each instance too, not just the named single-target
        // path. This regression pins the loop's per-service invocation.
        $svcA = new _StopClassInstanceFixture();
        $svcB = new _StopClassInstanceFixture();
        $nameA = 'parity-stop-all-a-' . bin2hex(random_bytes(3));
        $nameB = 'parity-stop-all-b-' . bin2hex(random_bytes(3));

        ServiceRunner::registerService($nameA, $svcA);
        ServiceRunner::registerService($nameB, $svcB);

        $this->assertFalse($svcA->shouldStop());
        $this->assertFalse($svcB->shouldStop());

        ServiceRunner::stop();   // no name = stop all

        $this->assertTrue(
            $svcA->shouldStop(),
            'ServiceRunner::stop() (all) must reach every registered instance'
        );
        $this->assertTrue(
            $svcB->shouldStop(),
            'ServiceRunner::stop() (all) must reach every registered instance'
        );
    }
}
