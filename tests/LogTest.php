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

    // ── Caller-name injection — feature #41 ──────────────────────────
    // When TINA4_LOG_FUNC=true, log lines include the calling function
    // name so a tail -f gives "super_trooper - message" context for free.
    // Default behaviour is unchanged when the env var is absent or false.

    /** Helper — clears the env var so each test starts neutral. */
    private function clearLogFunc(): void
    {
        unset($_ENV['TINA4_LOG_FUNC']);
        @putenv('TINA4_LOG_FUNC');
    }

    /** Helper — sets TINA4_LOG_FUNC to the given value (both $_ENV + getenv). */
    private function setLogFunc(string $value): void
    {
        $_ENV['TINA4_LOG_FUNC'] = $value;
        @putenv("TINA4_LOG_FUNC={$value}");
    }

    public function testCallerNameNotInjectedByDefault(): void
    {
        $this->clearLogFunc();
        Log::info('hello');

        $logFile = $this->tempDir . '/tina4.log';
        $content = trim(file_get_contents($logFile));
        $decoded = json_decode($content, true);

        $this->assertArrayNotHasKey('function', $decoded);
        $this->assertSame('hello', $decoded['message']);
    }

    public function testCallerNameInjectedWhenEnabled(): void
    {
        $this->setLogFunc('true');
        try {
            Log::info('hello');
        } finally {
            $this->clearLogFunc();
        }

        $logFile = $this->tempDir . '/tina4.log';
        $content = trim(file_get_contents($logFile));
        $decoded = json_decode($content, true);

        $this->assertArrayHasKey('function', $decoded);
        $this->assertSame(__FUNCTION__, $decoded['function']);
    }

    /**
     * @dataProvider logFuncTruthyValues
     */
    public function testCallerNameAcceptsTruthyVariants(string $value): void
    {
        $this->setLogFunc($value);
        try {
            $caller = Log::callerName();
        } finally {
            $this->clearLogFunc();
        }
        $this->assertSame(__FUNCTION__, $caller, "TINA4_LOG_FUNC={$value} should enable injection");
    }

    public static function logFuncTruthyValues(): array
    {
        return array_map(fn($v) => [$v], ['1', 'true', 'TRUE', 'on', 'yes', 'y', 't']);
    }

    /**
     * @dataProvider logFuncFalsyValues
     */
    public function testCallerNameRejectsFalsyVariants(string $value): void
    {
        $this->setLogFunc($value);
        try {
            $caller = Log::callerName();
        } finally {
            $this->clearLogFunc();
        }
        $this->assertNull($caller, "TINA4_LOG_FUNC={$value} should NOT enable injection");
    }

    public static function logFuncFalsyValues(): array
    {
        return array_map(fn($v) => [$v], ['0', 'false', 'off', 'no', 'n', 'f', '']);
    }

    public function testCallerNameInJsonMode(): void
    {
        // JSON is the default (production) format — assert the key lands
        // alongside "message" and "level" so jq pipelines can group on it.
        $this->setLogFunc('true');
        try {
            Log::info('json msg');
        } finally {
            $this->clearLogFunc();
        }

        $logFile = $this->tempDir . '/tina4.log';
        $decoded = json_decode(trim(file_get_contents($logFile)), true);

        $this->assertArrayHasKey('function', $decoded);
        $this->assertSame(__FUNCTION__, $decoded['function']);
        $this->assertSame('json msg', $decoded['message']);
    }

    public function testCallerNameInHumanReadableMode(): void
    {
        // Human format puts [caller] between [request-id] and the message.
        Log::configure(logDir: $this->tempDir, development: true);
        Log::setRequestId('req-human');
        $this->setLogFunc('true');
        try {
            Log::info('text msg');
        } finally {
            $this->clearLogFunc();
        }

        $logFile = $this->tempDir . '/tina4.log';
        $content = file_get_contents($logFile);

        $this->assertStringContainsString('[' . __FUNCTION__ . ']', $content);
        $this->assertStringContainsString('[req-human]', $content);
        // Order: [request-id] before [function] before message.
        $reqPos = strpos($content, '[req-human]');
        $funcPos = strpos($content, '[' . __FUNCTION__ . ']');
        $msgPos = strpos($content, 'text msg');
        $this->assertNotFalse($reqPos);
        $this->assertNotFalse($funcPos);
        $this->assertNotFalse($msgPos);
        $this->assertLessThan($funcPos, $reqPos, 'request-id should appear before caller-name');
        $this->assertLessThan($msgPos, $funcPos, 'caller-name should appear before message');
    }

    public function testCallerNameFiltersClosure(): void
    {
        // PHP renders closures as "{closure}" in debug_backtrace. Those
        // are noise (no useful symbol), so callerName() must return null
        // rather than emit a meaningless "[{closure}]" segment.
        $this->setLogFunc('true');
        try {
            $closure = function () {
                return Log::callerName();
            };
            $caller = $closure();
        } finally {
            $this->clearLogFunc();
        }
        $this->assertNull($caller, '{closure} frames should be filtered, not leaked');
    }

    public function testCallerNameEndToEndViaPublicApi(): void
    {
        // End-to-end via Log::info — proves the frame walk handles the
        // full call chain (info → log → buildEntry → callerName).
        $this->setLogFunc('true');
        try {
            Log::info('end to end');
        } finally {
            $this->clearLogFunc();
        }

        $logFile = $this->tempDir . '/tina4.log';
        $decoded = json_decode(trim(file_get_contents($logFile)), true);

        $this->assertArrayHasKey('function', $decoded);
        $this->assertSame(__FUNCTION__, $decoded['function']);
        $this->assertSame('end to end', $decoded['message']);
    }

    // ── v3.13.14: stdout-on-by-default + INFO default (Docker logs) ──────
    // The built-in server runs as PID 1 in a container; docker logs / k8s
    // read PID 1 stdout. Pre-v3.13.14 production set $stdout=false (file
    // only) and there was no TINA4_LOG_LEVEL env read, so deployed apps
    // "weren't getting logs". fwrite(STDOUT) bypasses PHPUnit's output
    // buffer, so we assert the behavioural flags via reflection.

    private function logProp(string $name): mixed
    {
        $ref = new ReflectionProperty(Log::class, $name);
        $ref->setAccessible(true);
        return $ref->getValue();
    }

    public function testStdoutEnabledInProduction(): void
    {
        Log::reset();
        Log::configure(logDir: $this->tempDir, development: false);
        $this->assertTrue(
            $this->logProp('stdout'),
            'production must log to stdout (docker logs reads PID 1 stdout)'
        );
    }

    public function testDefaultMinLevelIsInfo(): void
    {
        Log::reset();
        Log::configure(logDir: $this->tempDir);
        $this->assertSame(
            Log::LEVEL_INFO,
            $this->logProp('minLevel'),
            'default level is INFO (parity with Python/Ruby/Node)'
        );
    }

    public function testLogLevelEnvOverridesDefault(): void
    {
        unset($_ENV['TINA4_LOG_LEVEL']);
        @putenv('TINA4_LOG_LEVEL=ERROR');
        $_ENV['TINA4_LOG_LEVEL'] = 'ERROR';
        try {
            Log::reset();
            Log::configure(logDir: $this->tempDir);
            $this->assertSame(Log::LEVEL_ERROR, $this->logProp('minLevel'));
        } finally {
            unset($_ENV['TINA4_LOG_LEVEL']);
            @putenv('TINA4_LOG_LEVEL');
        }
    }

    public function testOutputFileKeepsStdoutSilent(): void
    {
        unset($_ENV['TINA4_LOG_OUTPUT']);
        @putenv('TINA4_LOG_OUTPUT=file');
        $_ENV['TINA4_LOG_OUTPUT'] = 'file';
        try {
            Log::reset();
            Log::configure(logDir: $this->tempDir, development: false);
            $this->assertFalse(
                $this->logProp('stdout'),
                'TINA4_LOG_OUTPUT=file must opt out of stdout'
            );
        } finally {
            unset($_ENV['TINA4_LOG_OUTPUT']);
            @putenv('TINA4_LOG_OUTPUT');
        }
    }

    // ----------------------------------------------------------------------
    // Log::isEnabled — console-threshold level predicate (parity with
    // Python's Log.is_enabled). Reflects CONSOLE (stdout) visibility only —
    // the log file always records every level regardless. The predicate
    // delegates to the SAME private threshold gate the console write uses,
    // so it can never disagree with what the logger actually prints.
    // ----------------------------------------------------------------------

    /** Invoke the private console-threshold gate the logger uses for stdout. */
    private function callShouldLog(string $level): bool
    {
        $ref = new ReflectionMethod(Log::class, 'shouldLog');
        $ref->setAccessible(true);
        return $ref->invoke(null, $level);
    }

    public function testIsEnabledAtInfoLevel(): void
    {
        Log::reset();
        Log::configure(logDir: $this->tempDir, minLevel: Log::LEVEL_INFO);

        $this->assertFalse(Log::isEnabled('debug'), 'debug is below the INFO threshold');
        $this->assertTrue(Log::isEnabled('info'), 'info is at the INFO threshold');
        $this->assertTrue(Log::isEnabled('warning'), 'warning is above the INFO threshold');
        $this->assertTrue(Log::isEnabled('error'), 'error is above the INFO threshold');
    }

    public function testIsEnabledAtErrorLevel(): void
    {
        Log::reset();
        Log::configure(logDir: $this->tempDir, minLevel: Log::LEVEL_ERROR);

        $this->assertFalse(Log::isEnabled('info'), 'info is below the ERROR threshold');
        $this->assertFalse(Log::isEnabled('warning'), 'warning is below the ERROR threshold');
        $this->assertTrue(Log::isEnabled('error'), 'error is at the ERROR threshold');
    }

    public function testIsEnabledIsCaseInsensitive(): void
    {
        Log::reset();
        Log::configure(logDir: $this->tempDir, minLevel: Log::LEVEL_INFO);

        $this->assertTrue(Log::isEnabled('INFO'), 'upper-case INFO must pass the INFO threshold');
        $this->assertTrue(Log::isEnabled('Warning'), 'mixed-case Warning must pass');
        $this->assertFalse(Log::isEnabled('Debug'), 'mixed-case Debug must not pass');
    }

    public function testIsEnabledMatchesInternalThresholdForAllLevels(): void
    {
        // The public predicate must equal the private console gate for every
        // level — including critical, which is now first-class and flows
        // through the SAME ordinary threshold (no special toggle branch).
        foreach ([Log::LEVEL_DEBUG, Log::LEVEL_INFO, Log::LEVEL_WARNING, Log::LEVEL_ERROR, Log::LEVEL_CRITICAL] as $minLevel) {
            Log::reset();
            Log::configure(logDir: $this->tempDir, minLevel: $minLevel);

            foreach (['debug', 'info', 'warning', 'error', 'critical'] as $level) {
                $this->assertSame(
                    $this->callShouldLog($level),
                    Log::isEnabled($level),
                    "isEnabled('$level') must equal shouldLog('$level') at minLevel=$minLevel"
                );
            }
        }
    }

    public function testIsEnabledFileAlwaysRecordsRegardlessOfThreshold(): void
    {
        // The predicate reflects CONSOLE visibility only — even when a level is
        // NOT console-enabled, the log file still records it.
        Log::reset();
        Log::configure(logDir: $this->tempDir, minLevel: Log::LEVEL_ERROR);

        $this->assertFalse(Log::isEnabled('debug'), 'debug is not console-visible at ERROR');

        Log::debug('below-threshold but still filed');
        $content = file_get_contents($this->tempDir . '/tina4.log');
        $this->assertStringContainsString('"level":"DEBUG"', $content);
        $this->assertStringContainsString('below-threshold but still filed', $content);
    }

    public function testIsEnabledCriticalIsOrdinaryThreshold(): void
    {
        // v3.13.39: critical is first-class (the highest severity) and flows
        // through the SAME ordinary threshold as every other level — there is
        // no TINA4_LOG_CRITICAL toggle and no special branch. So critical is
        // console-visible at any normal min level and only suppressed when the
        // configured min level is ABOVE critical (which is impossible — it is
        // the top), i.e. critical always passes.

        // At the lowest threshold critical passes.
        Log::reset();
        Log::configure(logDir: $this->tempDir, minLevel: Log::LEVEL_DEBUG);
        $this->assertTrue(Log::isEnabled('critical'), 'critical passes at DEBUG threshold');

        // Even at the ERROR threshold critical passes (CRITICAL 4 >= ERROR 3).
        Log::reset();
        Log::configure(logDir: $this->tempDir, minLevel: Log::LEVEL_ERROR);
        $this->assertTrue(Log::isEnabled('error'), 'error passes at ERROR threshold');
        $this->assertTrue(Log::isEnabled('critical'), 'critical outranks error, so it passes at ERROR threshold');

        // At the CRITICAL threshold every lower level is suppressed but
        // critical itself still passes.
        Log::reset();
        Log::configure(logDir: $this->tempDir, minLevel: Log::LEVEL_CRITICAL);
        $this->assertFalse(Log::isEnabled('error'), 'error is below the CRITICAL threshold');
        $this->assertTrue(Log::isEnabled('critical'), 'critical is at the CRITICAL threshold');
        // Case-insensitive like every other level.
        $this->assertTrue(Log::isEnabled('CRITICAL'), 'upper-case CRITICAL also passes');
    }

    // ── Critical: first-class top-level severity (v3.13.39) ─────────────
    // critical is the HIGHEST severity (debug<info<warning<error<critical).
    // It ALWAYS emits like every other level — no opt-in toggle — and lands
    // in error.log (4 >= warning 2). No TINA4_LOG_CRITICAL env var is read.

    public function testCriticalAlwaysWritesToLogFile(): void
    {
        // No env var set, default config — critical must STILL emit. The old
        // TINA4_LOG_CRITICAL "enable critical()" gate is gone: a critical log
        // must never be a silent no-op.
        Log::critical('Disk full');

        $logFile = $this->tempDir . '/tina4.log';
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('"level":"CRITICAL"', $content);
        $this->assertStringContainsString('Disk full', $content);
    }

    public function testCriticalWritesToErrorLog(): void
    {
        // critical 4 >= warning 2, so it mirrors into error.log alongside
        // WARNING/ERROR.
        Log::critical('System failure');

        $errorLog = $this->tempDir . '/error.log';
        $this->assertFileExists($errorLog);
        $content = file_get_contents($errorLog);
        $this->assertStringContainsString('"level":"CRITICAL"', $content);
        $this->assertStringContainsString('System failure', $content);
    }

    public function testCriticalIgnoresRetiredEnvToggle(): void
    {
        // The TINA4_LOG_CRITICAL env var is RETIRED — it is no longer read.
        // Setting it false must NOT suppress a critical log (proving the
        // toggle is truly gone, not merely defaulted on).
        unset($_ENV['TINA4_LOG_CRITICAL']);
        @putenv('TINA4_LOG_CRITICAL=false');
        $_ENV['TINA4_LOG_CRITICAL'] = 'false';
        try {
            Log::reset();
            Log::configure(logDir: $this->tempDir);
            Log::critical('still logs');

            $content = file_get_contents($this->tempDir . '/tina4.log');
            $this->assertStringContainsString('"level":"CRITICAL"', $content);
            $this->assertStringContainsString('still logs', $content);
        } finally {
            unset($_ENV['TINA4_LOG_CRITICAL']);
            @putenv('TINA4_LOG_CRITICAL');
        }
    }

    public function testCriticalIsTheHighestPriority(): void
    {
        // Lock in the ordering: critical outranks error, which outranks every
        // lower level. With minLevel=ERROR the console admits error+critical
        // but not warning; with minLevel=CRITICAL it admits ONLY critical.
        Log::reset();
        Log::configure(logDir: $this->tempDir, minLevel: Log::LEVEL_ERROR);
        $this->assertFalse(Log::isEnabled('warning'));
        $this->assertTrue(Log::isEnabled('error'));
        $this->assertTrue(Log::isEnabled('critical'), 'critical >= error');

        Log::reset();
        Log::configure(logDir: $this->tempDir, minLevel: Log::LEVEL_CRITICAL);
        $this->assertFalse(Log::isEnabled('warning'));
        $this->assertFalse(Log::isEnabled('error'), 'error is below critical');
        $this->assertTrue(Log::isEnabled('critical'));
    }
}
