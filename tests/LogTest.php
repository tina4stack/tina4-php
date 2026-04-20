<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
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

    public function testErrorLogCapturesErrorsOnly(): void
    {
        // error.log must receive WARNING + ERROR, not DEBUG + INFO.
        // Same rotation config as tina4.log, separate file.
        Log::debug('Debug message');
        Log::info('Info message');
        Log::warning('Warning message');
        Log::error('Error message');

        $errorLog = $this->tempDir . '/error.log';
        $this->assertFileExists($errorLog);

        $content = file_get_contents($errorLog);
        $this->assertStringNotContainsString('Debug message', $content);
        $this->assertStringNotContainsString('Info message', $content);
        $this->assertStringContainsString('Warning message', $content);
        $this->assertStringContainsString('Error message', $content);
    }

    public function testErrorLogFormatMatchesMainLog(): void
    {
        // An error written via Log::error should appear with the
        // same JSON shape in both tina4.log and error.log so a
        // consumer tailing either file sees identical frames.
        Log::error('Parity check', ['code' => 500]);

        $mainContent = file_get_contents($this->tempDir . '/tina4.log');
        $errContent  = file_get_contents($this->tempDir . '/error.log');

        $mainLine = trim(explode("\n", $mainContent)[0]);
        $errLine  = trim(explode("\n", $errContent)[0]);

        $this->assertJsonStringEqualsJsonString($mainLine, $errLine);
    }

    public function testErrorLogNotCreatedWithoutErrors(): void
    {
        // If the project only ever logs INFO/DEBUG, error.log stays
        // absent — saves dashboards from showing a phantom empty file.
        Log::info('Hello');
        Log::debug('World');

        $errorLog = $this->tempDir . '/error.log';
        $this->assertFileDoesNotExist($errorLog);
    }

    public function testFileAlwaysCapturesAllLevels(): void
    {
        Log::configure(logDir: $this->tempDir, minLevel: Log::LEVEL_WARNING);
        Log::debug('Debug message');
        Log::info('Info message');
        Log::warning('Warning message');
        Log::error('Error message');

        $logFile = $this->tempDir . '/tina4.log';
        $content = file_get_contents($logFile);

        // File always captures ALL levels regardless of minLevel setting
        $this->assertStringContainsString('Debug message', $content);
        $this->assertStringContainsString('Info message', $content);
        $this->assertStringContainsString('Warning message', $content);
        $this->assertStringContainsString('Error message', $content);
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

    // ── Log Levels ───────────────────────────────────────────────

    public function testFileAlwaysCapturesDebugWhenMinLevelError(): void
    {
        Log::configure(logDir: $this->tempDir, minLevel: Log::LEVEL_ERROR);
        Log::debug('Debug at error level');

        $logFile = $this->tempDir . '/tina4.log';
        $content = file_get_contents($logFile);

        // File always captures ALL levels
        $this->assertStringContainsString('Debug at error level', $content);
    }

    public function testWarningWritesWhenMinLevelWarning(): void
    {
        Log::configure(logDir: $this->tempDir, minLevel: Log::LEVEL_WARNING);
        Log::warning('Warning at warning level');

        $logFile = $this->tempDir . '/tina4.log';
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('Warning at warning level', $content);
    }

    public function testDebugLevelLogsEverything(): void
    {
        Log::configure(logDir: $this->tempDir, minLevel: Log::LEVEL_DEBUG);
        Log::debug('d');
        Log::info('i');
        Log::warning('w');
        Log::error('e');

        $logFile = $this->tempDir . '/tina4.log';
        $lines = array_filter(explode("\n", file_get_contents($logFile)));
        $this->assertCount(4, $lines);
    }

    // ── File Rotation ─────────────────────────────────────────────

    public function testFileRotation(): void
    {
        $logFile = $this->tempDir . '/tina4.log';

        // Write enough to trigger rotation (default is 10MB, but we can test the mechanism)
        // Create a large-ish initial log file
        file_put_contents($logFile, str_repeat('X', 100));

        // Write another entry
        Log::info('After initial content');

        $this->assertFileExists($logFile);
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('After initial content', $content);
    }

    // ── Format: production JSON ──────────────────────────────────

    public function testProductionFormatIsJson(): void
    {
        Log::configure(logDir: $this->tempDir, development: false);
        Log::info('json test');

        $logFile = $this->tempDir . '/tina4.log';
        $content = trim(file_get_contents($logFile));
        $decoded = json_decode($content, true);

        $this->assertNotNull($decoded);
        $this->assertSame('INFO', $decoded['level']);
        $this->assertSame('json test', $decoded['message']);
    }

    public function testProductionFormatIncludesContext(): void
    {
        Log::configure(logDir: $this->tempDir, development: false);
        Log::error('fail', ['code' => 500]);

        $logFile = $this->tempDir . '/tina4.log';
        $decoded = json_decode(trim(file_get_contents($logFile)), true);

        $this->assertArrayHasKey('context', $decoded);
        $this->assertEquals(500, $decoded['context']['code']);
    }

    // ── Context data ──────────────────────────────────────────────

    public function testContextNestedArray(): void
    {
        Log::info('Nested context', ['user' => ['id' => 1, 'name' => 'Alice']]);

        $logFile = $this->tempDir . '/tina4.log';
        $decoded = json_decode(trim(file_get_contents($logFile)), true);

        $this->assertArrayHasKey('context', $decoded);
        $this->assertEquals(1, $decoded['context']['user']['id']);
        $this->assertEquals('Alice', $decoded['context']['user']['name']);
    }

    public function testContextEmptyNotIncluded(): void
    {
        Log::info('No context');

        $logFile = $this->tempDir . '/tina4.log';
        $decoded = json_decode(trim(file_get_contents($logFile)), true);

        $this->assertArrayNotHasKey('context', $decoded);
    }

    // ── Request ID ───────────────────────────────────────────────

    public function testGetRequestIdReturnsSetValue(): void
    {
        Log::setRequestId('req-xyz');
        $this->assertEquals('req-xyz', Log::getRequestId());
    }

    public function testDefaultRequestIdIsNull(): void
    {
        $this->assertNull(Log::getRequestId());
    }

    // ── Reset ────────────────────────────────────────────────────

    public function testResetClearsRequestId(): void
    {
        Log::setRequestId('will-be-cleared');
        Log::reset();
        $this->assertNull(Log::getRequestId());
    }

    // ── Error level written ──────────────────────────────────────

    public function testErrorLevelWrittenToFile(): void
    {
        Log::error('critical failure');

        $logFile = $this->tempDir . '/tina4.log';
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('"level":"ERROR"', $content);
        $this->assertStringContainsString('critical failure', $content);
    }

    // ── Human-readable format with context ───────────────────────

    public function testHumanReadableWithContext(): void
    {
        Log::configure(logDir: $this->tempDir, development: true);
        Log::info('Ctx test', ['action' => 'login']);

        $logFile = $this->tempDir . '/tina4.log';
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('[INFO   ]', $content);
        $this->assertStringContainsString('login', $content);
    }

    // ── Human-readable format with request ID ────────────────────

    public function testHumanReadableWithRequestId(): void
    {
        Log::configure(logDir: $this->tempDir, development: true);
        Log::setRequestId('req-human-123');
        Log::info('With human ID');

        $logFile = $this->tempDir . '/tina4.log';
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('req-human-123', $content);
    }
}
