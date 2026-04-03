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

    public function testContextLoggerHasMethods(): void
    {
        $context = ServiceRunner::createContext('logger_test');

        $this->assertTrue(method_exists($context->logger, 'debug'));
        $this->assertTrue(method_exists($context->logger, 'info'));
        $this->assertTrue(method_exists($context->logger, 'warning'));
        $this->assertTrue(method_exists($context->logger, 'error'));
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

    public function testRemoveNonExistentPidFileDoesNotError(): void
    {
        // Should not throw
        ServiceRunner::removePidFile('nonexistent');
        $this->assertTrue(true);
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
