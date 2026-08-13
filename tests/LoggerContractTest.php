<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * The settled logger contract (owner decision 2026-08-09/10), superseding
 * the 2026-08-01 pass this file used to pin.
 *
 *  L1  FORMAT IS DEBUG-DERIVED (Decision 3, supersedes the 2026-08-01 "text
 *      always" rule): explicit TINA4_LOG_FORMAT wins; otherwise truthy
 *      TINA4_DEBUG selects text and a false/absent TINA4_DEBUG selects JSON.
 *
 *  L2  TINA4_LOG_* is resolved on FIRST USE (no configure() call needed).
 *
 *  L3  TINA4_LOG_STRICT exists -- a log-write failure RAISES a
 *      LogWriteError instead of being swallowed.
 *
 *  L4  Explicit argument beats environment, which beats default (ADR-0041),
 *      for every configure() field.
 *
 *  L5  REMOVED SETTINGS NOW HARD-FAIL CONFIGURATION (Decision 19; STRICTER
 *      than the 2026-08-01 "the old names have no effect" pass):
 *      TINA4_LOG_MAX_SIZE / TINA4_LOG_KEEP / TINA4_LOG_APPEND /
 *      TINA4_DEBUG_LEVEL / TINA4_LOG_CRITICAL now RAISE a
 *      LogConfigurationError rather than being silently ignored.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\Log;
use Tina4\LogConfigurationError;
use Tina4\LogWriteError;

class LoggerContractTest extends TestCase
{
    private string $tempDir;
    private string $cwd;

    private const LOG_ENV = [
        'TINA4_LOG_FILE', 'TINA4_LOG_DIR', 'TINA4_LOG_FORMAT', 'TINA4_LOG_OUTPUT',
        'TINA4_LOG_LEVEL', 'TINA4_LOG_FILE_LEVEL', 'TINA4_LOG_ROTATE_SIZE',
        'TINA4_LOG_ROTATE_KEEP', 'TINA4_LOG_STRICT', 'TINA4_LOG_FUNC',
        'TINA4_LOG_MAX_SIZE', 'TINA4_LOG_KEEP', 'TINA4_LOG_APPEND',
        'TINA4_DEBUG_LEVEL', 'TINA4_LOG_CRITICAL', 'TINA4_DEBUG',
    ];

    protected function setUp(): void
    {
        $this->cwd = getcwd();
        $this->tempDir = sys_get_temp_dir() . '/tina4_loggercontract_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        chdir($this->tempDir);
        foreach (self::LOG_ENV as $n) { putenv($n); unset($_ENV[$n]); }
        Log::reset();
    }

    protected function tearDown(): void
    {
        Log::reset();
        chdir($this->cwd);
        foreach (self::LOG_ENV as $n) { putenv($n); unset($_ENV[$n]); }
    }

    private function lines(): array
    {
        $path = $this->tempDir . '/tina4.log';
        if (!is_file($path)) {
            return [];
        }
        return array_values(array_filter(explode("\n", file_get_contents($path))));
    }

    // ── L1: format is debug-derived ──────────────────────────────────

    public function testNoDebugSelectsJsonByDefault(): void
    {
        putenv('TINA4_DEBUG');
        putenv('TINA4_LOG_OUTPUT=file');
        putenv('TINA4_LOG_DIR=' . $this->tempDir);
        Log::configure(level: 'info');
        Log::info('prod default is json');
        $entry = json_decode($this->lines()[0], true);
        $this->assertSame('prod default is json', $entry['message']);
    }

    public function testTruthyDebugSelectsTextByDefault(): void
    {
        putenv('TINA4_DEBUG=true');
        putenv('TINA4_LOG_OUTPUT=file');
        putenv('TINA4_LOG_DIR=' . $this->tempDir);
        Log::configure(level: 'info');
        Log::info('dev default is text');
        $line = $this->lines()[0];
        $this->assertStringContainsString('[INFO', $line);
        $this->assertNull(json_decode($line));
    }

    public function testExplicitTextWinsEvenWithoutDebug(): void
    {
        putenv('TINA4_DEBUG');
        putenv('TINA4_LOG_FORMAT=text');
        putenv('TINA4_LOG_OUTPUT=file');
        putenv('TINA4_LOG_DIR=' . $this->tempDir);
        Log::configure(level: 'info');
        Log::info('still text');
        $this->assertStringContainsString('[INFO', $this->lines()[0]);
    }

    public function testExplicitJsonWinsEvenWithDebug(): void
    {
        putenv('TINA4_DEBUG=true');
        putenv('TINA4_LOG_FORMAT=json');
        putenv('TINA4_LOG_OUTPUT=file');
        putenv('TINA4_LOG_DIR=' . $this->tempDir);
        Log::configure(level: 'info');
        Log::error('boom', ['code' => 500]);
        $entry = json_decode($this->lines()[0], true);
        $this->assertSame('ERROR', $entry['level']);
        $this->assertSame(500, $entry['context']['code']);
    }

    // ── L2: env resolved lazily on first use ─────────────────────────

    public function testLogFormatHonouredWithoutConfigure(): void
    {
        putenv('TINA4_LOG_FORMAT=json');
        putenv('TINA4_LOG_OUTPUT=file');
        putenv('TINA4_LOG_DIR=' . $this->tempDir);
        Log::info('from a script that never called configure');
        $entry = json_decode($this->lines()[0], true);
        $this->assertSame('from a script that never called configure', $entry['message']);
    }

    public function testLogLevelHonouredWithoutConfigure(): void
    {
        putenv('TINA4_LOG_LEVEL=error');
        putenv('TINA4_LOG_OUTPUT=both');
        $this->assertFalse(Log::isEnabled('info'));
        $this->assertTrue(Log::isEnabled('error'));
    }

    public function testConfigureLevelBeatsTheEnvLevel(): void
    {
        putenv('TINA4_LOG_LEVEL=error');
        Log::configure(level: 'debug', output: 'both');
        $this->assertTrue(Log::isEnabled('debug'));
    }

    // ── L4: explicit argument beats environment (ADR-0041) ───────────

    public function testExplicitLogDirBeatsConflictingEnvLogDir(): void
    {
        $envDir = $this->tempDir . '/from_env';
        $argDir = $this->tempDir . '/from_argument';
        mkdir($envDir);
        mkdir($argDir);
        putenv('TINA4_LOG_OUTPUT=file');
        putenv('TINA4_LOG_DIR=' . $envDir);

        Log::configure(logDir: $argDir, level: 'info');
        Log::info('which directory won?');

        $this->assertFileExists($argDir . '/tina4.log');
        $this->assertFileDoesNotExist($envDir . '/tina4.log');
    }

    public function testEnvLogDirStillAppliesWhenNoArgumentGiven(): void
    {
        $envDir = $this->tempDir . '/from_env';
        mkdir($envDir);
        putenv('TINA4_LOG_OUTPUT=file');
        putenv('TINA4_LOG_DIR=' . $envDir);

        Log::configure(level: 'info');
        Log::info('the env should win here');

        $this->assertFileExists($envDir . '/tina4.log');
    }

    // ── L3: TINA4_LOG_STRICT ──────────────────────────────────────────

    private function wedgeAfterConfigure(): void
    {
        $target = $this->tempDir . '/tina4.log';
        @unlink($target);
        mkdir($target);
    }

    public function testStrictTrueRaises(): void
    {
        putenv('TINA4_LOG_STRICT=true');
        Log::configure(logDir: $this->tempDir, level: 'info', output: 'file');
        $this->wedgeAfterConfigure();
        $this->expectException(LogWriteError::class);
        Log::info('this cannot be written');
    }

    public function testStrictUnsetSwallowsAndTheCallerSurvives(): void
    {
        Log::configure(logDir: $this->tempDir, level: 'info', output: 'file');
        $this->wedgeAfterConfigure();
        Log::info('this cannot be written either');
        $this->assertTrue(true, 'must not raise');
    }

    // ── L5: removed settings now hard-fail configuration (BREAKING) ──

    public static function removedSettingsProvider(): array
    {
        return [
            ['TINA4_LOG_MAX_SIZE'], ['TINA4_LOG_KEEP'], ['TINA4_LOG_APPEND'],
            ['TINA4_DEBUG_LEVEL'], ['TINA4_LOG_CRITICAL'],
        ];
    }

    #[DataProvider('removedSettingsProvider')]
    public function testRemovedSettingRaises(string $name): void
    {
        putenv("{$name}=1");
        $this->expectException(LogConfigurationError::class);
        Log::configure();
    }

    public function testRemovedSettingDoesNotMutateTheFilesystem(): void
    {
        putenv('TINA4_LOG_MAX_SIZE=1');
        putenv('TINA4_LOG_OUTPUT=file');
        $logsDir = $this->tempDir . '/logs';
        putenv('TINA4_LOG_DIR=' . $logsDir);
        try {
            Log::configure();
            $this->fail('expected LogConfigurationError');
        } catch (LogConfigurationError $e) {
            // expected
        }
        $this->assertDirectoryDoesNotExist($logsDir);
    }

    // ── canonical rotation names still work (negative half of L5) ────

    public function testRotateSizeInBytesStillRotates(): void
    {
        putenv('TINA4_LOG_OUTPUT=file');
        putenv('TINA4_LOG_DIR=' . $this->tempDir);
        putenv('TINA4_LOG_FILE=canonical.log');
        putenv('TINA4_LOG_ROTATE_SIZE=1024');
        Log::configure(level: 'info');
        for ($i = 0; $i < 120; $i++) {
            Log::info("canonical-line-{$i}-padding-padding-padding");
        }
        $this->assertNotEmpty(glob($this->tempDir . '/canonical.log.*'));
    }

    public function testRotateKeepStillCapsTheNumberOfBackups(): void
    {
        putenv('TINA4_LOG_OUTPUT=file');
        putenv('TINA4_LOG_DIR=' . $this->tempDir);
        putenv('TINA4_LOG_FILE=canonkeep.log');
        putenv('TINA4_LOG_ROTATE_SIZE=1024');
        putenv('TINA4_LOG_ROTATE_KEEP=2');
        Log::configure(level: 'info');
        for ($i = 0; $i < 200; $i++) {
            Log::info("canon-line-{$i}-padding-padding-padding-padding");
        }
        $this->assertCount(2, glob($this->tempDir . '/canonkeep.log.*'));
    }
}
