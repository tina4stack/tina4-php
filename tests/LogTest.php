<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Tests for Tina4\Log (3.14 contract, black-box against the public surface
 * only). Rewritten 2026-08-13 alongside the shared logger_contract.json
 * conformance runner (tests/LoggerFixtureContractTest.php): the old version
 * reflected private internals and used the now-retired `development:`
 * parameter. Real files, real env vars, no doubles.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Log;
use Tina4\LogConfigurationError;
use Tina4\LogArgumentError;
use Tina4\LogWriteError;

function logtest_caller_helper_php(): void
{
    Log::info('hi');
}

class LogTest extends TestCase
{
    private string $tempDir;
    private string $cwd;

    private const LOG_ENV = [
        'TINA4_LOG_LEVEL', 'TINA4_LOG_FILE_LEVEL', 'TINA4_LOG_FORMAT',
        'TINA4_LOG_OUTPUT', 'TINA4_LOG_DIR', 'TINA4_LOG_FILE',
        'TINA4_LOG_ROTATE_SIZE', 'TINA4_LOG_ROTATE_KEEP', 'TINA4_LOG_STRICT',
        'TINA4_LOG_FUNC', 'TINA4_DEBUG',
    ];

    protected function setUp(): void
    {
        $this->cwd = getcwd();
        $this->tempDir = sys_get_temp_dir() . '/tina4_logtest_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        chdir($this->tempDir);
        foreach (self::LOG_ENV as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
        Log::reset();
    }

    protected function tearDown(): void
    {
        Log::reset();
        chdir($this->cwd);
        foreach (self::LOG_ENV as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
    }

    private function lines(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        return array_values(array_filter(explode("\n", file_get_contents($path))));
    }

    // ── levels ───────────────────────────────────────────────────────

    public function testShouldLogInfoAtInfoLevel(): void
    {
        Log::configure(level: 'info', output: 'both');
        $this->assertTrue(Log::isEnabled('info'));
    }

    public function testShouldNotLogDebugAtInfoLevel(): void
    {
        Log::configure(level: 'info', fileLevel: 'info', output: 'both');
        $this->assertFalse(Log::isEnabled('debug'));
    }

    public function testShouldLogErrorAtInfoLevel(): void
    {
        Log::configure(level: 'info', output: 'both');
        $this->assertTrue(Log::isEnabled('error'));
    }

    public function testDebugLevelLogsEverything(): void
    {
        Log::configure(level: 'debug', output: 'both');
        foreach (['debug', 'info', 'warning', 'error'] as $level) {
            $this->assertTrue(Log::isEnabled($level));
        }
    }

    public function testIsEnabledIsCaseInsensitive(): void
    {
        Log::configure(level: 'info', output: 'both');
        $this->assertTrue(Log::isEnabled('INFO'));
        $this->assertFalse(Log::isEnabled('Debug'));
    }

    public function testIsEnabledCriticalIsTopLevel(): void
    {
        Log::configure(level: 'info', output: 'both');
        $this->assertTrue(Log::isEnabled('critical'));
        Log::configure(level: 'error', output: 'both');
        $this->assertTrue(Log::isEnabled('critical'));
    }

    public function testIsEnabledIsSinkAware(): void
    {
        Log::configure(level: 'error', fileLevel: 'debug', output: 'both');
        $this->assertFalse(Log::isEnabled('info'));
        $this->assertTrue(Log::isEnabled('info', 'file'));
    }

    public function testIsEnabledUnknownLevelThrows(): void
    {
        Log::configure(output: 'both');
        $this->expectException(LogArgumentError::class);
        Log::isEnabled('verbose');
    }

    // ── format ───────────────────────────────────────────────────────

    public function testJsonFormatIsJson(): void
    {
        Log::configure(format: 'json', output: 'file', logDir: $this->tempDir);
        Log::info('test message');
        $entry = json_decode($this->lines($this->tempDir . '/tina4.log')[0], true);
        $this->assertSame('INFO', $entry['level']);
        $this->assertSame('test message', $entry['message']);
    }

    public function testJsonFormatIncludesContext(): void
    {
        Log::configure(format: 'json', output: 'file', logDir: $this->tempDir);
        Log::error('fail', ['code' => 500]);
        $entry = json_decode($this->lines($this->tempDir . '/tina4.log')[0], true);
        $this->assertSame(500, $entry['context']['code']);
    }

    public function testFormatWithRequestId(): void
    {
        Log::configure(format: 'json', output: 'file', logDir: $this->tempDir);
        Log::setRequestId('req-123');
        Log::info('test');
        Log::clearRequestId();
        $entry = json_decode($this->lines($this->tempDir . '/tina4.log')[0], true);
        $this->assertSame('req-123', $entry['request_id']);
    }

    public function testTextFormatContainsLevelAndMessage(): void
    {
        Log::configure(format: 'text', output: 'file', logDir: $this->tempDir);
        Log::info('hello world');
        $content = file_get_contents($this->tempDir . '/tina4.log');
        $this->assertStringContainsString('INFO', $content);
        $this->assertStringContainsString('hello world', $content);
    }

    public function testDebugDerivedFormatIsJsonWithoutDebug(): void
    {
        putenv('TINA4_DEBUG');
        Log::configure(output: 'file', logDir: $this->tempDir);
        Log::info('prod line');
        json_decode($this->lines($this->tempDir . '/tina4.log')[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue(true);
    }

    public function testDebugDerivedFormatIsTextWithDebug(): void
    {
        putenv('TINA4_DEBUG=true');
        Log::configure(output: 'file', logDir: $this->tempDir);
        Log::info('dev line');
        $line = $this->lines($this->tempDir . '/tina4.log')[0];
        $this->assertNull(json_decode($line));
    }

    // ── output ───────────────────────────────────────────────────────

    public function testInfoWritesToFile(): void
    {
        Log::configure(output: 'file', logDir: $this->tempDir);
        Log::info('file write test');
        $this->assertStringContainsString('file write test', file_get_contents($this->tempDir . '/tina4.log'));
    }

    public function testErrorWritesToErrorLog(): void
    {
        Log::configure(output: 'file', logDir: $this->tempDir);
        Log::error('error write test');
        $this->assertStringContainsString('error write test', file_get_contents($this->tempDir . '/error.log'));
    }

    public function testWarningAlsoWritesToErrorLog(): void
    {
        Log::configure(output: 'file', logDir: $this->tempDir);
        Log::warning('warn into errors');
        $this->assertStringContainsString('warn into errors', file_get_contents($this->tempDir . '/error.log'));
    }

    public function testCriticalAlwaysLogsAtCriticalSeverity(): void
    {
        Log::configure(level: 'error', output: 'file', logDir: $this->tempDir);
        Log::critical('meltdown');
        $content = file_get_contents($this->tempDir . '/tina4.log');
        $this->assertStringContainsString('meltdown', $content);
        $this->assertStringContainsString('CRITICAL', $content);
    }

    public function testCriticalWritesToErrorLog(): void
    {
        Log::configure(output: 'file', logDir: $this->tempDir);
        Log::critical('page the oncall');
        $this->assertStringContainsString('page the oncall', file_get_contents($this->tempDir . '/error.log'));
    }

    public function testDebugAlwaysLoggedToFileWhenFileLevelAll(): void
    {
        Log::configure(level: 'info', output: 'file', logDir: $this->tempDir);
        Log::debug('should still appear in file');
        $this->assertStringContainsString('should still appear in file', file_get_contents($this->tempDir . '/tina4.log'));
    }

    // ── request id ───────────────────────────────────────────────────

    public function testSetAndGetRequestId(): void
    {
        Log::setRequestId('abc-123');
        $this->assertSame('abc-123', Log::getRequestId());
        Log::clearRequestId();
    }

    public function testDefaultRequestIdIsNull(): void
    {
        Log::clearRequestId();
        $this->assertNull(Log::getRequestId());
    }

    public function testClearRequestId(): void
    {
        Log::setRequestId('abc-123');
        Log::clearRequestId();
        $this->assertNull(Log::getRequestId());
    }

    // ── caller capture (feature #41) ─────────────────────────────────

    public function testCallerNameNotInjectedByDefault(): void
    {
        Log::configure(output: 'file', logDir: $this->tempDir, format: 'json');
        $superTrooper = function () { Log::info('hello'); };
        $superTrooper();
        $entry = json_decode($this->lines($this->tempDir . '/tina4.log')[0], true);
        $this->assertArrayNotHasKey('function', $entry);
    }

    public function testCallerNameInjectedWhenEnabled(): void
    {
        Log::configure(output: 'file', logDir: $this->tempDir, format: 'json', caller: true);
        logtest_caller_helper_php();
        $entry = json_decode($this->lines($this->tempDir . '/tina4.log')[0], true);
        $this->assertSame('logtest_caller_helper_php', $entry['function']);
    }

    public function testCallerNameEnvTrueEnablesInjection(): void
    {
        putenv('TINA4_LOG_FUNC=true');
        Log::configure(output: 'file', logDir: $this->tempDir, format: 'json');
        logtest_caller_helper_php();
        $entry = json_decode($this->lines($this->tempDir . '/tina4.log')[0], true);
        $this->assertSame('logtest_caller_helper_php', $entry['function']);
    }

    public function testCallerNameRejectsNonBooleanToken(): void
    {
        putenv('TINA4_LOG_FUNC=1');
        $this->expectException(LogConfigurationError::class);
        Log::configure(output: 'stdout');
    }
}
