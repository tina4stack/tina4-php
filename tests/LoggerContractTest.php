<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * LoggerContractTest — the settled logger contract (owner decision 2026-08-01),
 * measured against REAL log files on disk and REAL environment variables. No
 * doubles: every assertion reads what the logger actually wrote.
 *
 *  L1  TEXT is the format by default. ONLY TINA4_LOG_FORMAT=json selects JSON.
 *      The implicit production->JSON switch is DELETED. It meant four different
 *      things across the four frameworks (node: TINA4_DEBUG unset; ruby:
 *      TINA4_ENV/RACK_ENV/RUBY_ENV; python: configure(production=True); php: no
 *      switch at all, JSON was simply the shipped default) — one machine, one
 *      .env, four formats. An object passed as the MESSAGE is still JSON-encoded
 *      inline inside the text line; that is the only implicit JSON left.
 *
 *  L2  TINA4_LOG_* is resolved on FIRST USE. Ruby and Node already did; Python
 *      and PHP read it only inside configure(), which only the server calls — so
 *      a worker, CLI tool, cron script or test that logged without booting a
 *      server silently ignored the operator's .env, and the fallback defaults
 *      were OPPOSITE per framework (python: stdout + text + no file; php: NO
 *      stdout + files in ./logs + json). configure() stays the explicit override.
 *
 *  L3  TINA4_LOG_STRICT exists. It was documented on ALL FOUR env-var pages and
 *      implemented ONLY in Ruby — a documented no-op in three frameworks. When
 *      truthy, a log-write failure RAISES instead of being swallowed.
 *
 *  L4  TINA4_LOG_KEEP / TINA4_LOG_MAX_SIZE are DELETED (breaking). They were
 *      legacy aliases documented for four frameworks and implemented in two, and
 *      the size alias took MEGABYTES while the name it aliased takes BYTES.
 *      Canonical: TINA4_LOG_ROTATE_KEEP / TINA4_LOG_ROTATE_SIZE.
 *
 *  L5 is Ruby-only (a stdlib ::Logger header line) and has no PHP counterpart.
 */

use PHPUnit\Framework\TestCase;
use Tina4\DotEnv;
use Tina4\Log;

class LoggerContractTest extends TestCase
{
    private string $tempDir;

    /** Every env var this file touches — cleared before and after each test. */
    private const TRACKED = [
        'TINA4_LOG_DIR',
        'TINA4_LOG_FILE',
        'TINA4_LOG_FORMAT',
        'TINA4_LOG_OUTPUT',
        'TINA4_LOG_LEVEL',
        'TINA4_LOG_STRICT',
        'TINA4_LOG_APPEND',
        'TINA4_LOG_ROTATE_SIZE',
        'TINA4_LOG_ROTATE_KEEP',
        'TINA4_LOG_MAX_SIZE',
        'TINA4_LOG_KEEP',
        'TINA4_DEBUG',
        'TINA4_ENV',
        'RACK_ENV',
    ];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tina4_logger_contract_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->clearEnv();
        DotEnv::resetEnv();
        Log::reset();
    }

    protected function tearDown(): void
    {
        Log::reset();
        $this->clearEnv();
        DotEnv::resetEnv();
        $this->removeDir($this->tempDir);
    }

    private function clearEnv(): void
    {
        foreach (self::TRACKED as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            @putenv($key);
        }
    }

    /** @param array<string,string> $vars */
    private function setEnv(array $vars): void
    {
        foreach ($vars as $key => $value) {
            $_ENV[$key] = $value;
            @putenv("{$key}={$value}");
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function logLines(?string $dir = null, string $file = 'tina4.log'): array
    {
        $path = ($dir ?? $this->tempDir) . '/' . $file;
        if (!is_file($path)) {
            return [];
        }
        return array_values(array_filter(explode("\n", (string) file_get_contents($path)), 'strlen'));
    }

    // ── L1 — TEXT BY DEFAULT ────────────────────────────────────────────────

    public function testTextIsTheDefaultFormat(): void
    {
        // Nothing set but the sink: no TINA4_LOG_FORMAT, no TINA4_DEBUG.
        $this->setEnv(['TINA4_LOG_OUTPUT' => 'file', 'TINA4_LOG_DIR' => $this->tempDir]);
        Log::configure();
        Log::info('plain text please');

        $line = $this->logLines()[0] ?? '';
        $this->assertNull(json_decode($line, true), 'the default line must NOT be JSON');
        $this->assertStringContainsString('[INFO    ]', $line, 'text layout pads the level to 8');
        $this->assertStringContainsString('plain text please', $line);
        $this->assertTrue(Log::isHumanReadable());
    }

    public function testProductionDoesNotSelectJson(): void
    {
        // The DELETED switch, spelled every way the four frameworks spelled it:
        // TINA4_DEBUG absent (node), TINA4_ENV/RACK_ENV=production (ruby),
        // configure(development: false) (python's production=True, php's old
        // default). None of them may change the format.
        $this->setEnv([
            'TINA4_LOG_OUTPUT' => 'file',
            'TINA4_LOG_DIR' => $this->tempDir,
            'TINA4_ENV' => 'production',
            'RACK_ENV' => 'production',
        ]);
        Log::configure(development: false);
        Log::info('still text in production');

        $line = $this->logLines()[0] ?? '';
        $this->assertNull(json_decode($line, true), 'production must not imply JSON');
        $this->assertStringContainsString('still text in production', $line);
    }

    public function testJsonIsSelectedOnlyByTheFormatEnvVar(): void
    {
        // The negative half of L1: JSON must still be reachable, and only this
        // way. Without it, "text by default" could be implemented by deleting
        // the JSON writer.
        $this->setEnv([
            'TINA4_LOG_OUTPUT' => 'file',
            'TINA4_LOG_DIR' => $this->tempDir,
            'TINA4_LOG_FORMAT' => 'json',
        ]);
        Log::configure();
        Log::error('machine readable', ['code' => 500]);

        $decoded = json_decode($this->logLines()[0] ?? '', true);
        $this->assertIsArray($decoded, 'TINA4_LOG_FORMAT=json must produce JSON');
        $this->assertSame('ERROR', $decoded['level']);
        $this->assertSame('machine readable', $decoded['message']);
        $this->assertSame(500, $decoded['context']['code']);
        $this->assertFalse(Log::isHumanReadable());
    }

    public function testObjectMessageIsJsonEncodedInlineInsideTheTextLine(): void
    {
        // The one implicit JSON that stays: an array/object handed to the
        // logger as the MESSAGE is encoded inline in the text line, because an
        // array rendered as "Array" is the whole reason the caller logged it.
        $this->setEnv(['TINA4_LOG_OUTPUT' => 'file', 'TINA4_LOG_DIR' => $this->tempDir]);
        Log::configure();
        Log::info(['user' => ['id' => 7], 'action' => 'login']);

        $line = $this->logLines()[0] ?? '';
        $this->assertNull(json_decode($line, true), 'the LINE is text, not JSON');
        $this->assertStringContainsString('[INFO    ]', $line);
        $this->assertStringContainsString('{"user":{"id":7},"action":"login"}', $line);
    }

    // ── L2 — LAZY ENV RESOLUTION ────────────────────────────────────────────

    public function testEnvIsResolvedOnFirstUseWithoutConfigure(): void
    {
        // The worker / CLI / cron case: nobody calls configure(). Pre-fix the
        // logger ignored every TINA4_LOG_* here and wrote JSON into ./logs.
        $this->setEnv([
            'TINA4_LOG_OUTPUT' => 'file',
            'TINA4_LOG_DIR' => $this->tempDir,
            'TINA4_LOG_FILE' => 'worker.log',
        ]);

        Log::warning('no configure() in this process');

        $lines = $this->logLines(file: 'worker.log');
        $this->assertCount(1, $lines, 'the operator .env must be honoured without configure()');
        $this->assertStringContainsString('no configure() in this process', $lines[0]);
        $this->assertSame($this->tempDir, Log::logDir());
        $this->assertSame('worker.log', Log::logFile());
    }

    public function testIsEnabledResolvesEnvOnFirstUse(): void
    {
        // isEnabled() can be the very first logger call in a process — it must
        // answer against the operator's TINA4_LOG_LEVEL, not a class default.
        $this->setEnv(['TINA4_LOG_LEVEL' => 'ERROR']);

        $this->assertFalse(Log::isEnabled('debug'), 'TINA4_LOG_LEVEL=ERROR hides debug');
        $this->assertTrue(Log::isEnabled('error'));
    }

    public function testExplicitConfigureIsNotReResolvedLater(): void
    {
        // configure() stays the explicit override: once it has run, a later env
        // change does NOT quietly re-point the sink mid-process.
        $chosen = $this->tempDir . '/chosen';
        $ignored = $this->tempDir . '/ignored';
        $this->setEnv(['TINA4_LOG_OUTPUT' => 'file']);
        Log::configure(logDir: $chosen);
        Log::info('first');

        $this->setEnv(['TINA4_LOG_DIR' => $ignored]);
        Log::info('second');

        $this->assertCount(2, $this->logLines($chosen), 'configure() wins for the whole process');
        $this->assertDirectoryDoesNotExist($ignored);
    }

    // ── L3 — TINA4_LOG_STRICT ───────────────────────────────────────────────

    /**
     * A REAL unwritable log target: a regular FILE sits where the log DIRECTORY
     * would have to be, so mkdir() genuinely fails — for root too, unlike a
     * chmod trick.
     */
    private function blockedLogDir(): string
    {
        $blocker = $this->tempDir . '/blocker';
        file_put_contents($blocker, 'not a directory');
        return $blocker . '/logs';
    }

    public function testStrictModeRaisesOnARealLogWriteFailure(): void
    {
        $this->setEnv([
            'TINA4_LOG_OUTPUT' => 'file',
            'TINA4_LOG_DIR' => $this->blockedLogDir(),
            'TINA4_LOG_STRICT' => 'true',
        ]);
        Log::configure();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/TINA4_LOG_STRICT/');
        Log::error('this write cannot land');
    }

    public function testWithoutStrictALogWriteFailureIsSwallowed(): void
    {
        // The negative half: the default must stay log-and-degrade — a failing
        // log sink may never be the reason a request dies.
        $blocked = $this->blockedLogDir();
        $this->setEnv(['TINA4_LOG_OUTPUT' => 'file', 'TINA4_LOG_DIR' => $blocked]);
        Log::configure();

        Log::error('this write cannot land either');

        $this->assertFileDoesNotExist($blocked . '/tina4.log');
        $this->assertDirectoryDoesNotExist($blocked);
        $this->addToAssertionCount(1); // reaching here == no exception escaped
    }

    public function testStrictModeIsSilentWhenTheWriteSucceeds(): void
    {
        // Negative control for strict itself: it must raise on FAILURE only,
        // not on every write.
        $this->setEnv([
            'TINA4_LOG_OUTPUT' => 'file',
            'TINA4_LOG_DIR' => $this->tempDir,
            'TINA4_LOG_STRICT' => '1',
        ]);
        Log::configure();

        Log::error('this write lands');

        $this->assertCount(1, $this->logLines());
    }

    // ── L4 — the deleted rotation aliases ───────────────────────────────────

    public function testLegacyRotationAliasesAreIgnored(): void
    {
        // BREAKING: TINA4_LOG_MAX_SIZE (megabytes) and TINA4_LOG_KEEP no longer
        // do anything. Migration: TINA4_LOG_MAX_SIZE=10 ->
        // TINA4_LOG_ROTATE_SIZE=10485760, TINA4_LOG_KEEP=n ->
        // TINA4_LOG_ROTATE_KEEP=n.
        $this->setEnv(['TINA4_LOG_MAX_SIZE' => '1', 'TINA4_LOG_KEEP' => '9']);
        Log::configure(logDir: $this->tempDir);

        $this->assertSame(Log::DEFAULT_ROTATE_SIZE, Log::rotateSize(), 'TINA4_LOG_MAX_SIZE is gone');
        $this->assertSame(Log::DEFAULT_ROTATE_KEEP, Log::rotateKeep(), 'TINA4_LOG_KEEP is gone');
    }

    public function testCanonicalRotationVarsStillApply(): void
    {
        // The negative half: deleting the aliases must not break the names they
        // aliased.
        $this->setEnv(['TINA4_LOG_ROTATE_SIZE' => '2048', 'TINA4_LOG_ROTATE_KEEP' => '3']);
        Log::configure(logDir: $this->tempDir);

        $this->assertSame(2048, Log::rotateSize());
        $this->assertSame(3, Log::rotateKeep());
    }

    public function testCanonicalRotationSizeActuallyRotates(): void
    {
        // End to end on a real file: the canonical byte threshold rotates.
        $this->setEnv([
            'TINA4_LOG_OUTPUT' => 'file',
            'TINA4_LOG_DIR' => $this->tempDir,
            'TINA4_LOG_ROTATE_SIZE' => '200',
            'TINA4_LOG_ROTATE_KEEP' => '2',
        ]);
        Log::configure();

        for ($i = 0; $i < 6; $i++) {
            Log::info(str_repeat('x', 80) . " line {$i}");
        }

        $this->assertFileExists($this->tempDir . '/tina4.log');
        $this->assertFileExists($this->tempDir . '/tina4.log.1');
    }
}
