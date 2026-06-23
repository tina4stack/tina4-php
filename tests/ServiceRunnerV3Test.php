<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\ServiceRunner;
use Tina4\Log;

class ServiceRunnerV3Test extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        ServiceRunner::reset();
        Log::configure(sys_get_temp_dir() . '/tina4-test-logs');

        $this->tmpDir = sys_get_temp_dir() . '/tina4-service-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        ServiceRunner::setPidDir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        ServiceRunner::reset();

        // Clean up temp directory
        if (is_dir($this->tmpDir)) {
            $files = glob($this->tmpDir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
    }

    // ---------------------------------------------------------------
    // Registration tests
    // ---------------------------------------------------------------

    public function testRegisterService(): void
    {
        ServiceRunner::register('test_service', function ($ctx) {});

        $list = ServiceRunner::list();
        $this->assertArrayHasKey('test_service', $list);
        $this->assertEquals('test_service', $list['test_service']['name']);
    }

    public function testRegisterMultipleServices(): void
    {
        ServiceRunner::register('svc_a', function ($ctx) {});
        ServiceRunner::register('svc_b', function ($ctx) {});
        ServiceRunner::register('svc_c', function ($ctx) {});

        $list = ServiceRunner::list();
        $this->assertCount(3, $list);
        $this->assertArrayHasKey('svc_a', $list);
        $this->assertArrayHasKey('svc_b', $list);
        $this->assertArrayHasKey('svc_c', $list);
    }

    public function testRegisterWithDefaultOptions(): void
    {
        ServiceRunner::register('defaults', function ($ctx) {});

        $list = ServiceRunner::list();
        $options = $list['defaults']['options'];

        $this->assertEquals('', $options['timing']);
        $this->assertFalse($options['daemon']);
        $this->assertEquals(3, $options['maxRetries']);
        $this->assertEquals(0, $options['timeout']);
    }

    public function testRegisterWithCustomOptions(): void
    {
        ServiceRunner::register('custom', function ($ctx) {}, [
            'timing' => '*/5 * * * *',
            'daemon' => true,
            'maxRetries' => 5,
            'timeout' => 60,
        ]);

        $list = ServiceRunner::list();
        $options = $list['custom']['options'];

        $this->assertEquals('*/5 * * * *', $options['timing']);
        $this->assertTrue($options['daemon']);
        $this->assertEquals(5, $options['maxRetries']);
        $this->assertEquals(60, $options['timeout']);
    }

    public function testRegisterOverwritesPreviousService(): void
    {
        ServiceRunner::register('overwrite', function ($ctx) {}, ['timeout' => 10]);
        ServiceRunner::register('overwrite', function ($ctx) {}, ['timeout' => 99]);

        $list = ServiceRunner::list();
        $this->assertCount(1, $list);
        $this->assertEquals(99, $list['overwrite']['options']['timeout']);
    }

    // ---------------------------------------------------------------
    // Cron pattern matching tests
    // ---------------------------------------------------------------

    public function testCronWildcardMatchesAll(): void
    {
        // "* * * * *" matches any time
        $this->assertTrue(ServiceRunner::matchCron('* * * * *'));
    }

    public function testCronExactMinute(): void
    {
        $now = new \DateTime('2026-03-20 10:30:00');

        $this->assertTrue(ServiceRunner::matchCron('30 10 * * *', $now));
        $this->assertFalse(ServiceRunner::matchCron('31 10 * * *', $now));
    }

    public function testCronIntervalEvery5Minutes(): void
    {
        $at00 = new \DateTime('2026-03-20 10:00:00');
        $at05 = new \DateTime('2026-03-20 10:05:00');
        $at10 = new \DateTime('2026-03-20 10:10:00');
        $at03 = new \DateTime('2026-03-20 10:03:00');

        $this->assertTrue(ServiceRunner::matchCron('*/5 * * * *', $at00));
        $this->assertTrue(ServiceRunner::matchCron('*/5 * * * *', $at05));
        $this->assertTrue(ServiceRunner::matchCron('*/5 * * * *', $at10));
        $this->assertFalse(ServiceRunner::matchCron('*/5 * * * *', $at03));
    }

    public function testCronListOfValues(): void
    {
        $at10 = new \DateTime('2026-03-20 10:00:00');
        $at14 = new \DateTime('2026-03-20 14:00:00');
        $at11 = new \DateTime('2026-03-20 11:00:00');

        $this->assertTrue(ServiceRunner::matchCron('0 10,14,18 * * *', $at10));
        $this->assertTrue(ServiceRunner::matchCron('0 10,14,18 * * *', $at14));
        $this->assertFalse(ServiceRunner::matchCron('0 10,14,18 * * *', $at11));
    }

    public function testCronRange(): void
    {
        // Monday=1 through Friday=5
        $monday = new \DateTime('2026-03-16 09:00:00'); // Monday
        $wednesday = new \DateTime('2026-03-18 09:00:00'); // Wednesday
        $sunday = new \DateTime('2026-03-15 09:00:00'); // Sunday

        $this->assertTrue(ServiceRunner::matchCron('0 9 * * 1-5', $monday));
        $this->assertTrue(ServiceRunner::matchCron('0 9 * * 1-5', $wednesday));
        $this->assertFalse(ServiceRunner::matchCron('0 9 * * 1-5', $sunday));
    }

    public function testCronDailyMidnight(): void
    {
        $midnight = new \DateTime('2026-03-20 00:00:00');
        $noon = new \DateTime('2026-03-20 12:00:00');

        $this->assertTrue(ServiceRunner::matchCron('0 0 * * *', $midnight));
        $this->assertFalse(ServiceRunner::matchCron('0 0 * * *', $noon));
    }

    public function testCronSpecificDayOfMonth(): void
    {
        $first = new \DateTime('2026-03-01 06:00:00');
        $second = new \DateTime('2026-03-02 06:00:00');

        $this->assertTrue(ServiceRunner::matchCron('0 6 1 * *', $first));
        $this->assertFalse(ServiceRunner::matchCron('0 6 1 * *', $second));
    }

    public function testCronSpecificMonth(): void
    {
        $march = new \DateTime('2026-03-01 00:00:00');
        $april = new \DateTime('2026-04-01 00:00:00');

        $this->assertTrue(ServiceRunner::matchCron('0 0 1 3 *', $march));
        $this->assertFalse(ServiceRunner::matchCron('0 0 1 3 *', $april));
    }

    public function testCronInvalidPatternReturnsFalse(): void
    {
        // Too few fields
        $this->assertFalse(ServiceRunner::matchCron('* *'));
        // Too many fields
        $this->assertFalse(ServiceRunner::matchCron('* * * * * *'));
    }

    // ---------------------------------------------------------------
    // Context object tests
    // ---------------------------------------------------------------

    public function testContextObjectProperties(): void
    {
        $context = ServiceRunner::createContext('my_service', 1000);

        $this->assertTrue($context->running);
        $this->assertEquals(1000, $context->lastRun);
        $this->assertEquals('my_service', $context->name);
        $this->assertNotNull($context->logger);
    }

    /**
     * Consolidated interface-contract guard for the context logger.
     *
     * Replaces four per-method `method_exists` smoke tests. This is a
     * single parity rename guard: if any of the four level methods is
     * renamed or dropped, the contract breaks here. The REAL logging
     * behaviour (that each method actually emits through Tina4\Log) is
     * proven by testContextLoggerEmitsThroughLog() below — this test
     * only locks the public method set.
     */
    public function testContextLoggerContract(): void
    {
        $logger = ServiceRunner::createContext('logger_test')->logger;

        foreach (['debug', 'info', 'warning', 'error'] as $level) {
            $this->assertTrue(
                method_exists($logger, $level),
                "Context logger is missing the '{$level}' method"
            );
            $this->assertTrue(
                is_callable([$logger, $level]),
                "Context logger '{$level}' is not callable"
            );
        }
    }

    /**
     * Real behaviour: the context logger is not a stub — each level
     * method routes through Tina4\Log and the message lands in the log
     * file. Drive every level with a unique marker and assert all four
     * markers were actually written by the real Log backend.
     */
    public function testContextLoggerEmitsThroughLog(): void
    {
        $logDir = $this->tmpDir . '/ctx-logs';
        $prevDebug = getenv('TINA4_DEBUG');
        // Dev-gate the log FILE so we can read back what was emitted.
        $_ENV['TINA4_DEBUG'] = 'true';
        @putenv('TINA4_DEBUG=true');

        try {
            Log::reset();
            // INFO floor would drop debug(); set DEBUG so every level writes.
            Log::configure(logDir: $logDir, development: true, minLevel: 'DEBUG');

            $logger = ServiceRunner::createContext('emit_test')->logger;
            $marker = 'ctxlog_' . uniqid();

            $logger->debug("D-{$marker}");
            $logger->info("I-{$marker}");
            $logger->warning("W-{$marker}");
            $logger->error("E-{$marker}");

            $content = (string) @file_get_contents($logDir . '/tina4.log');
            $this->assertStringContainsString("D-{$marker}", $content, 'debug() did not reach Log');
            $this->assertStringContainsString("I-{$marker}", $content, 'info() did not reach Log');
            $this->assertStringContainsString("W-{$marker}", $content, 'warning() did not reach Log');
            $this->assertStringContainsString("E-{$marker}", $content, 'error() did not reach Log');
        } finally {
            Log::reset();
            if ($prevDebug === false) {
                unset($_ENV['TINA4_DEBUG']);
                @putenv('TINA4_DEBUG');
            } else {
                $_ENV['TINA4_DEBUG'] = $prevDebug;
                @putenv('TINA4_DEBUG=' . $prevDebug);
            }
        }
    }

    // ---------------------------------------------------------------
    // Service execution: real register -> start (run) -> list behaviour
    // ---------------------------------------------------------------

    /**
     * The crux behavioural test the smoke suite was missing: register a
     * REAL daemon service, actually run it via start($name) (which runs
     * the loop in-process — start($name) never forks), and assert the
     * handler genuinely executed with a correctly-populated context, the
     * PID file existed during the run, and the loop cleaned it up on exit.
     */
    public function testRegisterAndRunDaemonServiceExecutesHandler(): void
    {
        $observed = (object)['ran' => 0, 'name' => null, 'running' => null, 'pidDuringRun' => false];
        $pidFile = ServiceRunner::pidFilePath('runner_daemon');

        ServiceRunner::register('runner_daemon', function ($ctx) use ($observed, $pidFile) {
            $observed->ran++;
            $observed->name = $ctx->name;
            $observed->running = $ctx->running;
            // The runLoop writes the PID file before invoking us.
            $observed->pidDuringRun = file_exists($pidFile);
        }, ['daemon' => true]);

        // start($name) runs in the foreground; a daemon handler is called
        // exactly once and the loop then breaks — fully deterministic.
        ServiceRunner::start('runner_daemon');

        $this->assertSame(1, $observed->ran, 'Daemon handler should run exactly once');
        $this->assertSame('runner_daemon', $observed->name, 'Context carried the wrong service name');
        $this->assertTrue($observed->running, 'Context->running should be true while the handler runs');
        $this->assertTrue($observed->pidDuringRun, 'PID file should exist while the handler runs');

        // The loop cleans up the PID file on a clean exit.
        $this->assertFileDoesNotExist($pidFile, 'PID file should be removed after the daemon exits');
        $this->assertFalse(ServiceRunner::isRunning('runner_daemon'), 'Service should not report running after exit');
    }

    /**
     * Real cron-mode run: a non-daemon service with empty timing runs the
     * handler every loop iteration. Drive a single real iteration by
     * having the handler signal shutdown after the first call, then assert
     * the handler actually executed and received the prior lastRun via the
     * context (0 on the first pass).
     */
    public function testRegisterAndRunCronServiceExecutesHandler(): void
    {
        @putenv('TINA4_SERVICE_SLEEP=0'); // no real sleeping in the loop
        $observed = (object)['ran' => 0, 'lastRunSeen' => null];

        ServiceRunner::register('runner_cron', function ($ctx) use ($observed) {
            $observed->ran++;
            $observed->lastRunSeen = $ctx->lastRun;
            // Empty-timing cron mode loops forever; stop after one real run.
            ServiceRunner::shutdown();
        }); // default options: timing='', daemon=false

        ServiceRunner::start('runner_cron');

        $this->assertSame(1, $observed->ran, 'Cron handler should have executed for real');
        $this->assertSame(0, $observed->lastRunSeen, 'First-pass context lastRun should be 0');
        $this->assertFileDoesNotExist(
            ServiceRunner::pidFilePath('runner_cron'),
            'PID file should be cleaned up after the run'
        );
        @putenv('TINA4_SERVICE_SLEEP');
    }

    /**
     * Real retry behaviour: a handler that always throws is retried up to
     * maxRetries and then the loop gives up. Assert the handler was
     * actually invoked the expected number of times (initial try + retries)
     * rather than asserting a property merely exists.
     */
    public function testRunRetriesHandlerUpToMaxRetriesThenStops(): void
    {
        @putenv('TINA4_SERVICE_SLEEP=0');
        $calls = (object)['count' => 0];

        ServiceRunner::register('runner_flaky', function ($ctx) use ($calls) {
            $calls->count++;
            throw new \RuntimeException('boom');
        }, ['maxRetries' => 2]);

        ServiceRunner::start('runner_flaky');

        // Loop condition is `retries <= maxRetries`; each throw increments
        // retries, so a maxRetries=2 service runs the handler 3 times
        // (retries 0,1,2 all enter the body) then exits once retries > 2.
        $this->assertSame(3, $calls->count, 'Handler should be tried initial + maxRetries times');
        $this->assertFileDoesNotExist(
            ServiceRunner::pidFilePath('runner_flaky'),
            'PID file should be cleaned up after retries are exhausted'
        );
        @putenv('TINA4_SERVICE_SLEEP');
    }

    /**
     * Regression: TINA4_SERVICE_SLEEP=0 must be honoured as a real zero
     * sleep. Previously start() read it as `getenv(...) ?: 5`, and PHP
     * treats the string "0" as falsy, so the runner slept 5 seconds even
     * when the operator asked for none. Drive a retry loop (which sleeps
     * between iterations) with sleep=0 and assert the whole run completes
     * far faster than the 5-second fallback would allow.
     */
    public function testZeroServiceSleepIsHonoured(): void
    {
        @putenv('TINA4_SERVICE_SLEEP=0');
        $calls = (object)['count' => 0];

        // maxRetries=2 => handler runs 3 times with a sleep between each.
        // At the buggy 5s fallback this run takes >= 10s; honoured-zero is
        // effectively instant.
        ServiceRunner::register('runner_zero_sleep', function ($ctx) use ($calls) {
            $calls->count++;
            throw new \RuntimeException('boom');
        }, ['maxRetries' => 2]);

        $start = microtime(true);
        ServiceRunner::start('runner_zero_sleep');
        $elapsed = microtime(true) - $start;

        $this->assertSame(3, $calls->count, 'Handler should still run initial + maxRetries times');
        $this->assertLessThan(
            2.0,
            $elapsed,
            'TINA4_SERVICE_SLEEP=0 was not honoured — run took the 5s fallback sleep'
        );
        @putenv('TINA4_SERVICE_SLEEP');
    }

    /**
     * Real run from a discovered service file: discover() loads a service
     * config from disk, then start($name) actually executes its handler.
     * This ties discovery and execution together end-to-end against the
     * real filesystem.
     */
    public function testDiscoveredServiceRunsItsHandler(): void
    {
        $serviceDir = $this->tmpDir . '/run_services';
        mkdir($serviceDir, 0755, true);
        $flag = $this->tmpDir . '/discovered_ran.flag';

        $flagEscaped = var_export($flag, true);
        file_put_contents($serviceDir . '/worker.php', "<?php return [
            'name' => 'disk_worker',
            'daemon' => true,
            'handler' => function (\$ctx) { file_put_contents({$flagEscaped}, 'ran'); },
        ];");

        $discovered = ServiceRunner::discover($serviceDir);
        $this->assertContains('disk_worker', $discovered);

        ServiceRunner::start('disk_worker');

        $this->assertFileExists($flag, 'Discovered service handler should have actually run');
        $this->assertSame('ran', file_get_contents($flag));
    }

    // ---------------------------------------------------------------
    // PID file management tests
    // ---------------------------------------------------------------

    public function testWriteAndReadPidFile(): void
    {
        ServiceRunner::writePidFile('test_pid', 12345);

        $pidFile = ServiceRunner::pidFilePath('test_pid');
        $this->assertFileExists($pidFile);
        $this->assertEquals('12345', file_get_contents($pidFile));
    }

    public function testRemovePidFile(): void
    {
        ServiceRunner::writePidFile('remove_me', 99999);
        $pidFile = ServiceRunner::pidFilePath('remove_me');
        $this->assertFileExists($pidFile);

        ServiceRunner::removePidFile('remove_me');
        $this->assertFileDoesNotExist($pidFile);
    }

    public function testRemoveNonExistentPidFileIsANoOp(): void
    {
        $pidFile = ServiceRunner::pidFilePath('nonexistent');
        // Precondition: the file genuinely does not exist.
        $this->assertFileDoesNotExist($pidFile);

        // Removing a missing PID file must neither throw nor create anything.
        ServiceRunner::removePidFile('nonexistent');

        $this->assertFileDoesNotExist($pidFile, 'removePidFile must not create the file');
        $this->assertFalse(ServiceRunner::isRunning('nonexistent'));
    }

    public function testPidFilePathFormat(): void
    {
        $path = ServiceRunner::pidFilePath('my_service');
        $this->assertStringEndsWith('my_service.pid', $path);
    }

    // ---------------------------------------------------------------
    // Service listing and status tests
    // ---------------------------------------------------------------

    public function testListReturnsEmptyWhenNoServices(): void
    {
        $list = ServiceRunner::list();
        $this->assertEmpty($list);
    }

    public function testServiceNotRunningByDefault(): void
    {
        ServiceRunner::register('idle', function ($ctx) {});

        $list = ServiceRunner::list();
        $this->assertFalse($list['idle']['running']);
        $this->assertNull($list['idle']['pid']);
    }

    public function testIsRunningReturnsFalseForUnknownService(): void
    {
        $this->assertFalse(ServiceRunner::isRunning('does_not_exist'));
    }

    // ---------------------------------------------------------------
    // Service discovery tests
    // ---------------------------------------------------------------

    public function testDiscoverFromDirectory(): void
    {
        // Create a temp service file
        $serviceDir = $this->tmpDir . '/services';
        mkdir($serviceDir, 0755, true);

        file_put_contents($serviceDir . '/test_svc.php', '<?php return [
            "name" => "discovered_service",
            "timing" => "*/10 * * * *",
            "handler" => function($ctx) { /* work */ },
        ];');

        $discovered = ServiceRunner::discover($serviceDir);

        $this->assertContains('discovered_service', $discovered);

        $list = ServiceRunner::list();
        $this->assertArrayHasKey('discovered_service', $list);
        $this->assertEquals('*/10 * * * *', $list['discovered_service']['options']['timing']);
    }

    public function testDiscoverFromNonExistentDirectoryReturnsEmpty(): void
    {
        $discovered = ServiceRunner::discover('/nonexistent/path/services');
        $this->assertEmpty($discovered);
    }

    public function testDiscoverSkipsInvalidFiles(): void
    {
        $serviceDir = $this->tmpDir . '/invalid_services';
        mkdir($serviceDir, 0755, true);

        // File missing 'handler' key
        file_put_contents($serviceDir . '/bad.php', '<?php return ["name" => "bad"];');

        $discovered = ServiceRunner::discover($serviceDir);
        $this->assertEmpty($discovered);
    }

    // ---------------------------------------------------------------
    // Reset test
    // ---------------------------------------------------------------

    public function testResetClearsAllState(): void
    {
        ServiceRunner::register('temp', function ($ctx) {});
        $this->assertNotEmpty(ServiceRunner::list());

        ServiceRunner::reset();
        $this->assertEmpty(ServiceRunner::list());
    }

    // ---------------------------------------------------------------
    // Cron parsing: step on wildcard additional tests
    // ---------------------------------------------------------------

    public function testCronStepOnWildcardMinutes(): void
    {
        $at00 = new \DateTime('2026-03-20 10:00:00');
        $at15 = new \DateTime('2026-03-20 10:15:00');
        $at30 = new \DateTime('2026-03-20 10:30:00');
        $at07 = new \DateTime('2026-03-20 10:07:00');

        $this->assertTrue(ServiceRunner::matchCron('*/15 * * * *', $at00));
        $this->assertTrue(ServiceRunner::matchCron('*/15 * * * *', $at15));
        $this->assertTrue(ServiceRunner::matchCron('*/15 * * * *', $at30));
        $this->assertFalse(ServiceRunner::matchCron('*/15 * * * *', $at07));
    }

    // ---------------------------------------------------------------
    // Cron: day of week matching
    // ---------------------------------------------------------------

    public function testCronDayOfWeek(): void
    {
        // 2026-03-16 is a Monday (1), 2026-03-22 is a Sunday (0)
        $monday = new \DateTime('2026-03-16 10:00:00');
        $sunday = new \DateTime('2026-03-22 10:00:00');

        $this->assertTrue(ServiceRunner::matchCron('0 10 * * 1', $monday));
        $this->assertFalse(ServiceRunner::matchCron('0 10 * * 1', $sunday));
    }

    // ---------------------------------------------------------------
    // Service lifecycle: max retries
    // ---------------------------------------------------------------

    public function testMaxRetriesDefault(): void
    {
        ServiceRunner::register('retries_default', function ($ctx) {});
        $list = ServiceRunner::list();
        $this->assertEquals(3, $list['retries_default']['options']['maxRetries']);
    }

    public function testMaxRetriesCustom(): void
    {
        ServiceRunner::register('retries_custom', function ($ctx) {}, [
            'maxRetries' => 10,
        ]);
        $list = ServiceRunner::list();
        $this->assertEquals(10, $list['retries_custom']['options']['maxRetries']);
    }

    // ---------------------------------------------------------------
    // Service lifecycle: timeout
    // ---------------------------------------------------------------

    public function testTimeoutDefault(): void
    {
        ServiceRunner::register('timeout_default', function ($ctx) {});
        $list = ServiceRunner::list();
        $this->assertEquals(0, $list['timeout_default']['options']['timeout']);
    }

    public function testTimeoutCustom(): void
    {
        ServiceRunner::register('timeout_custom', function ($ctx) {}, [
            'timeout' => 120,
        ]);
        $list = ServiceRunner::list();
        $this->assertEquals(120, $list['timeout_custom']['options']['timeout']);
    }

    // ---------------------------------------------------------------
    // Service: daemon option
    // ---------------------------------------------------------------

    public function testDaemonDefaultFalse(): void
    {
        ServiceRunner::register('not_daemon', function ($ctx) {});
        $list = ServiceRunner::list();
        $this->assertFalse($list['not_daemon']['options']['daemon']);
    }

    public function testDaemonTrue(): void
    {
        ServiceRunner::register('is_daemon', function ($ctx) {}, [
            'daemon' => true,
        ]);
        $list = ServiceRunner::list();
        $this->assertTrue($list['is_daemon']['options']['daemon']);
    }

    // ---------------------------------------------------------------
    // Service listing
    // ---------------------------------------------------------------

    public function testListContainsServiceName(): void
    {
        ServiceRunner::register('my_service', function ($ctx) {});
        $list = ServiceRunner::list();
        $this->assertArrayHasKey('my_service', $list);
        $this->assertEquals('my_service', $list['my_service']['name']);
    }

    public function testListContainsAllFields(): void
    {
        ServiceRunner::register('full_service', function ($ctx) {}, [
            'timing' => '* * * * *',
        ]);
        $list = ServiceRunner::list();
        $svc = $list['full_service'];
        $this->assertArrayHasKey('name', $svc);
        $this->assertArrayHasKey('running', $svc);
        $this->assertArrayHasKey('pid', $svc);
        $this->assertArrayHasKey('options', $svc);
    }

    // ---------------------------------------------------------------
    // Error handling: discover with invalid service files
    // ---------------------------------------------------------------

    public function testDiscoverSkipsNonPhpFiles(): void
    {
        $serviceDir = $this->tmpDir . '/svc_txt';
        mkdir($serviceDir, 0755, true);

        file_put_contents($serviceDir . '/readme.txt', 'Not a PHP file');

        $discovered = ServiceRunner::discover($serviceDir);
        $this->assertEmpty($discovered);
    }

    // ---------------------------------------------------------------
    // PID file: overwrite
    // ---------------------------------------------------------------

    public function testPidFileOverwrite(): void
    {
        ServiceRunner::writePidFile('overwrite_pid', 111);
        ServiceRunner::writePidFile('overwrite_pid', 222);

        $pidFile = ServiceRunner::pidFilePath('overwrite_pid');
        $this->assertEquals('222', file_get_contents($pidFile));
    }
}
