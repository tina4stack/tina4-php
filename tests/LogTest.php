<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Log;

class LogTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tina4_log_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        Log::reset();
        Log::configure(logDir: $this->tempDir);
    }

    protected function tearDown(): void
    {
        Log::reset();

        // Clean up log files
        $files = glob($this->tempDir . '/*');
        if ($files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testInfoWritesToLogFile(): void
    {
        Log::info('Test info message');

        $logFile = $this->tempDir . '/tina4.log';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $this->assertStringContainsString('"level":"INFO"', $content);
        $this->assertStringContainsString('"message":"Test info message"', $content);
    }

    public function testDebugWritesToLogFile(): void
    {
        Log::debug('Debug message');

        $logFile = $this->tempDir . '/tina4.log';
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('"level":"DEBUG"', $content);
    }

    public function testWarningWritesToLogFile(): void
    {
        Log::warning('Warning message');

        $logFile = $this->tempDir . '/tina4.log';
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('"level":"WARNING"', $content);
    }

    public function testErrorWritesToLogFile(): void
    {
        Log::error('Error message');

        $logFile = $this->tempDir . '/tina4.log';
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('"level":"ERROR"', $content);
    }

    public function testLogEntryIsValidJson(): void
    {
        Log::info('JSON test');

        $logFile = $this->tempDir . '/tina4.log';
        $content = trim(file_get_contents($logFile));

        $decoded = json_decode($content, true);
        $this->assertNotNull($decoded);
        $this->assertArrayHasKey('timestamp', $decoded);
        $this->assertArrayHasKey('level', $decoded);
        $this->assertArrayHasKey('message', $decoded);
    }

    public function testContextIncludedInLog(): void
    {
        Log::info('With context', ['user_id' => 42, 'action' => 'login']);

        $logFile = $this->tempDir . '/tina4.log';
        $content = trim(file_get_contents($logFile));

        $decoded = json_decode($content, true);
        $this->assertArrayHasKey('context', $decoded);
        $this->assertEquals(42, $decoded['context']['user_id']);
        $this->assertEquals('login', $decoded['context']['action']);
    }

    public function testRequestIdIncludedWhenSet(): void
    {
        Log::setRequestId('req-abc-123');
        Log::info('With request ID');

        $logFile = $this->tempDir . '/tina4.log';
        $content = trim(file_get_contents($logFile));

        $decoded = json_decode($content, true);
        $this->assertArrayHasKey('request_id', $decoded);
        $this->assertEquals('req-abc-123', $decoded['request_id']);
    }

    public function testRequestIdNotIncludedWhenNull(): void
    {
        Log::setRequestId(null);
        Log::info('No request ID');

        $logFile = $this->tempDir . '/tina4.log';
        $content = trim(file_get_contents($logFile));

        $decoded = json_decode($content, true);
        $this->assertArrayNotHasKey('request_id', $decoded);
    }

    public function testMinLevelFiltering(): void
    {
        Log::configure(logDir: $this->tempDir, minLevel: Log::LEVEL_WARNING);
        Log::debug('Should not appear');
        Log::info('Should not appear either');
        Log::warning('Should appear');
        Log::error('Should also appear');

        $logFile = $this->tempDir . '/tina4.log';
        $content = file_get_contents($logFile);

        $this->assertStringNotContainsString('Should not appear', $content);
        $this->assertStringContainsString('Should appear', $content);
        $this->assertStringContainsString('Should also appear', $content);
    }

    public function testHumanReadableFormat(): void
    {
        Log::configure(logDir: $this->tempDir, development: true);
        Log::info('Human readable test');

        $logFile = $this->tempDir . '/tina4.log';
        $content = file_get_contents($logFile);

        // Human-readable format should contain [INFO   ] padded
        $this->assertStringContainsString('[INFO   ]', $content);
        $this->assertStringContainsString('Human readable test', $content);
    }

    public function testGetRequestId(): void
    {
        Log::setRequestId('test-id-456');
        $this->assertEquals('test-id-456', Log::getRequestId());
    }

    public function testMultipleLogLines(): void
    {
        Log::info('Line 1');
        Log::info('Line 2');
        Log::info('Line 3');

        $logFile = $this->tempDir . '/tina4.log';
        $lines = array_filter(explode("\n", file_get_contents($logFile)));

        $this->assertCount(3, $lines);
    }

    public function testTimestampFormat(): void
    {
        Log::info('Timestamp test');

        $logFile = $this->tempDir . '/tina4.log';
        $decoded = json_decode(trim(file_get_contents($logFile)), true);

        // Should match ISO 8601 format with milliseconds
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $decoded['timestamp']
        );
    }

    public function testLogCreatesDirectoryIfMissing(): void
    {
        $nestedDir = $this->tempDir . '/nested/logs';
        Log::configure(logDir: $nestedDir);
        Log::info('Test nested dir');

        $this->assertFileExists($nestedDir . '/tina4.log');

        // Clean up
        unlink($nestedDir . '/tina4.log');
        rmdir($nestedDir);
        rmdir($this->tempDir . '/nested');
    }
}
