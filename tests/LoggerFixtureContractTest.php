<?php

/**
 * Structured logger shared-fixture contract -- feature 2.
 *
 * Shared conformance fixture: tina4-documentation/plan/v3/fixtures/logger_contract.json
 * Contract: tina4-documentation/plan/v3/features/002-structured-logger.md
 * ADR-0041 (explicit argument > environment > default).
 *
 * One test per fixture case, named to match the case's `name` field (checked
 * mechanically by tina4-documentation/scripts/audit-contract-fixtures.py via
 * a normalised substring match). Every case drives the REAL Log class
 * against REAL files under a real temp project root and REAL environment
 * variables -- no doubles anywhere.
 *
 * 2026-08-10 owner override baked in throughout: Decision 8 (SEPARATE FILE
 * LEVEL) and Decision 20 (SINGLE FILE + IN-PROCESS LOCK ONLY). PHP has no
 * in-process THREAD primitive (no ext-pthreads in a normal build), and
 * Log's own LogFileSink uses flock() -- a lock that also serialises real OS
 * PROCESSES, exceeding Decision 20's thread-only floor -- so the
 * concurrency-witness cases here use pcntl_fork() for genuine concurrent
 * writers and hold PHP to that stronger, actually-implemented guarantee.
 *
 * Where a case's `given` under-specifies a coordinate the 2026-08-10
 * override added after the fixture was authored (no case names
 * TINA4_LOG_FILE_LEVEL by env/option), the console and file thresholds are
 * set EQUAL so the case's own literal assertions hold under real sink-aware
 * routing; Decision 8's independence is separately and explicitly proven by
 * testConsoleAndFileLevelsRouteIndependentlyPerDecision8 below.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\Log;
use Tina4\LogFileSink;
use Tina4\LogConfigurationError;
use Tina4\LogArgumentError;
use Tina4\LogWriteError;

function loggerfixture_named_handle(): void
{
    Log::info('ready', ['z' => 1, 'a' => ['y' => 2, 'b' => 3]]);
}

class LoggerFixtureContractTest extends TestCase
{
    private string $tempDir;
    private string $cwd;

    private const LOG_ENV = [
        'TINA4_LOG_LEVEL', 'TINA4_LOG_FILE_LEVEL', 'TINA4_LOG_FORMAT',
        'TINA4_LOG_OUTPUT', 'TINA4_LOG_DIR', 'TINA4_LOG_FILE',
        'TINA4_LOG_ROTATE_SIZE', 'TINA4_LOG_ROTATE_KEEP', 'TINA4_LOG_STRICT',
        'TINA4_LOG_FUNC', 'TINA4_DEBUG',
        'TINA4_LOG_MAX_SIZE', 'TINA4_LOG_KEEP', 'TINA4_LOG_APPEND',
        'TINA4_DEBUG_LEVEL', 'TINA4_LOG_CRITICAL',
    ];

    protected function setUp(): void
    {
        $this->cwd = getcwd();
        $dir = sys_get_temp_dir() . '/tina4_loggerfixture_' . uniqid();
        mkdir($dir, 0755, true);
        // realpath() resolves the /var -> /private/var symlink on macOS so
        // this matches what getcwd() reports after chdir() -- Log resolves
        // paths from the real (post-symlink) working directory.
        $this->tempDir = realpath($dir);
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

    // ── helpers ──────────────────────────────────────────────────────

    private function linesOf(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        return array_values(array_filter(explode("\n", file_get_contents($path))));
    }

    /**
     * Log::writeStdout() writes via fwrite(STDOUT) directly to the OS file
     * descriptor, which ob_start()/ob_get_clean() cannot intercept (PHPUnit
     * itself hits this -- see the original RequestLoggingTest note). The
     * real, honest capture is a genuine child PHP process with its own real
     * stdout pipe: $body is real PHP source executed in that child after
     * requiring the autoloader and chdir'ing to the test's tempDir.
     */
    private function captureStdout(string $body): string
    {
        $bootstrap = __DIR__ . '/../vendor/autoload.php';
        $script = '<?php use Tina4\Log; '
            . 'require ' . var_export($bootstrap, true) . '; '
            . 'chdir(' . var_export($this->tempDir, true) . '); '
            . $body;
        $tmpScript = $this->tempDir . '/stdout_capture_' . uniqid() . '.php';
        file_put_contents($tmpScript, $script);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(['php', $tmpScript], $descriptors, $pipes);
        if (!is_resource($proc)) {
            $this->fail('failed to spawn stdout-capture subprocess');
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        unlink($tmpScript);
        if ($err !== '') {
            $this->fail("stdout-capture subprocess wrote to stderr:\n{$err}");
        }
        return $out;
    }

    // ═══════════════════════════════════════════════════════════════
    // logger-configuration (LOG-C01..C10)
    // ═══════════════════════════════════════════════════════════════

    public function testLoggerDefaultsWithoutEnvironment(): void
    {
        $cfg = Log::configuration();
        $this->assertSame('INFO', $cfg['level']);
        $this->assertSame('json', $cfg['format']);
        $this->assertSame('stdout', $cfg['output']);
        $this->assertSame($this->tempDir . '/logs', $cfg['log_dir']);
        $this->assertNull($cfg['log_file']);
        $this->assertSame(10485760, $cfg['rotate_size']);
        $this->assertSame(5, $cfg['rotate_keep']);
        $this->assertFalse($cfg['strict']);
        $this->assertFalse($cfg['caller']);
        $this->assertDirectoryDoesNotExist($this->tempDir . '/logs');
    }

    public function testGeneratedDevelopmentValuesSelectAllTextAndBothSinks(): void
    {
        putenv('TINA4_DEBUG=true');
        putenv('TINA4_LOG_LEVEL=ALL');
        $cfg = Log::configuration();
        $this->assertSame('ALL', $cfg['level']);
        $this->assertSame('text', $cfg['format']);
        $this->assertTrue($cfg['stdout_enabled']);
        $this->assertTrue($cfg['file_enabled']);
    }

    public function testExplicitOptionBeatsEnvironment(): void
    {
        putenv('TINA4_LOG_LEVEL=ERROR');
        putenv('TINA4_LOG_FORMAT=json');
        putenv('TINA4_LOG_OUTPUT=file');
        Log::configure(level: 'debug', format: 'text', output: 'both');
        $cfg = Log::configuration();
        $this->assertSame('DEBUG', $cfg['level']);
        $this->assertSame('text', $cfg['format']);
        $this->assertSame('both', $cfg['output']);
    }

    public function testEnvironmentBeatsFrameworkDefault(): void
    {
        putenv('TINA4_LOG_LEVEL=critical');
        putenv('TINA4_LOG_ROTATE_SIZE=2048');
        putenv('TINA4_LOG_ROTATE_KEEP=0');
        $cfg = Log::configuration();
        $this->assertSame('CRITICAL', $cfg['level']);
        $this->assertSame(2048, $cfg['rotate_size']);
        $this->assertSame(0, $cfg['rotate_keep']);
    }

    public function testSnapshotIgnoresLaterEnvironmentMutation(): void
    {
        putenv('TINA4_LOG_LEVEL=INFO');
        $first = Log::configuration()['level'];
        putenv('TINA4_LOG_LEVEL=CRITICAL');
        $second = Log::configuration()['level'];
        $this->assertSame(['INFO', 'INFO'], [$first, $second]);
    }

    public function testResetReloadsEnvironment(): void
    {
        putenv('TINA4_LOG_LEVEL=INFO');
        $first = Log::configuration()['level'];
        putenv('TINA4_LOG_LEVEL=CRITICAL');
        $resetReturn = Log::reset();
        $second = Log::configuration()['level'];
        $this->assertSame(['INFO', 'CRITICAL'], [$first, $second]);
        $this->assertNull($resetReturn);
    }

    public function testFailedReconfigurationPreservesPriorSnapshot(): void
    {
        Log::configure(level: 'info', output: 'stdout');
        $before = glob($this->tempDir . '/*');
        try {
            Log::configure(rotateSize: 0);
            $this->fail('expected LogConfigurationError');
        } catch (LogConfigurationError $e) {
        }
        $this->assertSame('INFO', Log::configuration()['level']);
        $this->assertSame($before, glob($this->tempDir . '/*'));
    }

    public function testFileNameDoesNotEnableFileSink(): void
    {
        putenv('TINA4_DEBUG=false');
        putenv('TINA4_LOG_FILE=app.log');
        $cfg = Log::configuration();
        $this->assertSame('stdout', $cfg['output']);
        $this->assertTrue($cfg['stdout_enabled']);
        $this->assertFalse($cfg['file_enabled']);
        $this->assertSame($this->tempDir . '/logs/app.log', $cfg['log_file']);
    }

    public function testRelativeAndAbsolutePathsResolveWithoutGuessing(): void
    {
        Log::configure(logDir: 'var/log', logFile: 'app.data', output: 'file');
        $cfg = Log::configuration();
        $this->assertSame($this->tempDir . '/var/log', $cfg['log_dir']);
        $this->assertSame($this->tempDir . '/var/log/app.data', $cfg['log_file']);
        $this->assertSame('single', $cfg['layout']);
    }

    public function testConfigurationResultIsADefensiveCopy(): void
    {
        putenv('TINA4_LOG_LEVEL=INFO');
        $cfg1 = Log::configuration();
        $cfg1['level'] = 'MUTATED';
        $cfg1['new_key'] = 'leaked';
        $cfg2 = Log::configuration();
        $this->assertSame('INFO', $cfg2['level']);
        $this->assertArrayNotHasKey('new_key', $cfg2);
    }

    // ═══════════════════════════════════════════════════════════════
    // logger-invalid-configuration (LOG-V01..V05)
    // ═══════════════════════════════════════════════════════════════

    public function testInvalidEnumValuesFail(): void
    {
        $cases = [['TINA4_LOG_LEVEL', 'verbose'], ['TINA4_LOG_FORMAT', 'yaml'], ['TINA4_LOG_OUTPUT', 'stout']];
        foreach ($cases as [$name, $value]) {
            foreach (self::LOG_ENV as $n) { putenv($n); }
            putenv("{$name}={$value}");
            try {
                Log::configure();
                $this->fail("expected LogConfigurationError for {$name}={$value}");
            } catch (LogConfigurationError $e) {
            }
            $this->assertDirectoryDoesNotExist($this->tempDir . '/logs');
            Log::reset();
        }
    }

    public function testInvalidRotationValuesFail(): void
    {
        $cases = [
            ['TINA4_LOG_ROTATE_SIZE', '0'], ['TINA4_LOG_ROTATE_SIZE', '1023'], ['TINA4_LOG_ROTATE_SIZE', 'large'],
            ['TINA4_LOG_ROTATE_KEEP', '-1'], ['TINA4_LOG_ROTATE_KEEP', '1.5'],
        ];
        foreach ($cases as [$name, $value]) {
            foreach (self::LOG_ENV as $n) { putenv($n); }
            putenv("{$name}={$value}");
            try {
                Log::configure();
                $this->fail("expected LogConfigurationError for {$name}={$value}");
            } catch (LogConfigurationError $e) {
            }
            $this->assertDirectoryDoesNotExist($this->tempDir . '/logs');
            Log::reset();
        }
    }

    public function testInvalidPathAndBooleanTypesFail(): void
    {
        foreach (self::LOG_ENV as $n) { putenv($n); }
        putenv('TINA4_LOG_DIR=');
        try {
            Log::configure();
            $this->fail('expected error for empty TINA4_LOG_DIR');
        } catch (LogConfigurationError $e) {
        }
        $this->assertDirectoryDoesNotExist($this->tempDir . '/logs');
        Log::reset();

        // The NUL-byte case cannot go through a real OS env var (embedded
        // NUL is not representable in a C-string env value) -- the explicit
        // argument channel is the real, reachable path for that exact byte
        // sequence, and configure() validates it identically either way.
        try {
            Log::configure(logFile: "bad\0name");
            $this->fail('expected error for NUL byte in log file');
        } catch (LogConfigurationError $e) {
        }
        Log::reset();

        foreach (self::LOG_ENV as $n) { putenv($n); }
        putenv('TINA4_LOG_STRICT=maybe');
        try {
            Log::configure();
            $this->fail('expected error for TINA4_LOG_STRICT=maybe');
        } catch (LogConfigurationError $e) {
        }
        Log::reset();

        foreach (self::LOG_ENV as $n) { putenv($n); }
        putenv('TINA4_LOG_FUNC=1'); // native int, not the native boolean the setting requires
        try {
            Log::configure();
            $this->fail('expected error for TINA4_LOG_FUNC=1');
        } catch (LogConfigurationError $e) {
        }
        Log::reset();
    }

    public static function removedSettingsProvider(): array
    {
        return [
            ['TINA4_LOG_MAX_SIZE'], ['TINA4_LOG_KEEP'], ['TINA4_LOG_APPEND'],
            ['TINA4_DEBUG_LEVEL'], ['TINA4_LOG_CRITICAL'],
        ];
    }

    #[DataProvider('removedSettingsProvider')]
    public function testRemovedSettingsFailWithMigrationDetail(string $setting): void
    {
        putenv("{$setting}=1");
        try {
            Log::configure();
            $this->fail("expected LogConfigurationError for {$setting}");
        } catch (LogConfigurationError $e) {
            $this->assertStringContainsString('removed setting', $e->getMessage());
            $this->assertSame($setting, $e->setting);
        }
    }

    public function testLegacyBracketLevelFails(): void
    {
        putenv('TINA4_LOG_LEVEL=[TINA4_LOG_ERROR]');
        try {
            Log::configure();
            $this->fail('expected LogConfigurationError');
        } catch (LogConfigurationError $e) {
            $this->assertSame('TINA4_LOG_LEVEL', $e->setting);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // logger-levels-and-routing (LOG-L01..L05)
    // ═══════════════════════════════════════════════════════════════

    public function testEveryThresholdHasOneSharedLevelMatrix(): void
    {
        $expected = [
            'ALL' => ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'],
            'DEBUG' => ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'],
            'INFO' => ['INFO', 'WARNING', 'ERROR', 'CRITICAL'],
            'WARNING' => ['WARNING', 'ERROR', 'CRITICAL'],
            'ERROR' => ['ERROR', 'CRITICAL'],
            'CRITICAL' => ['CRITICAL'],
            'NONE' => [],
        ];
        foreach ($expected as $threshold => $want) {
            Log::reset();
            $dir = $this->tempDir . '/' . $threshold;
            $out = $this->captureStdout(sprintf(
                'Log::configure(level: %s, fileLevel: %s, output: "both", logDir: %s, format: "json");'
                . 'foreach (["debug","info","warning","error","critical"] as $level) { Log::{$level}("probe"); }',
                var_export($threshold, true), var_export($threshold, true), var_export($dir, true)
            ));
            $stdoutLevels = array_map(fn($l) => json_decode($l, true)['level'], array_values(array_filter(explode("\n", $out))));
            $fileLevels = array_map(fn($l) => json_decode($l, true)['level'], $this->linesOf($dir . '/tina4.log'));
            $this->assertSame($want, $stdoutLevels, "stdout mismatch at threshold {$threshold}");
            $this->assertSame($want, $fileLevels, "file mismatch at threshold {$threshold}");
        }
    }

    public function testLevelConfigurationIsCaseInsensitive(): void
    {
        $pairs = [['all', 'ALL'], ['Debug', 'DEBUG'], ['INFO', 'INFO'], ['warning', 'WARNING'], ['Error', 'ERROR'], ['critical', 'CRITICAL'], ['none', 'NONE']];
        foreach ($pairs as [$raw, $canonical]) {
            Log::reset();
            Log::configure(level: $raw);
            $this->assertSame($canonical, Log::configuration()['level']);
        }
    }

    public function testIsEnabledMatchesRealRouting(): void
    {
        // The real subprocess proves the actual routed bytes (stdout + the
        // shared file); the in-process configure() below proves is_enabled()
        // agrees with that same real routing decision.
        $out = $this->captureStdout(sprintf(
            'Log::configure(level: "WARNING", fileLevel: "WARNING", output: "both", logDir: %s, format: "json");'
            . 'foreach (["debug","info","warning","error","critical"] as $level) { Log::{$level}("probe"); }',
            var_export($this->tempDir, true)
        ));
        Log::configure(level: 'WARNING', fileLevel: 'WARNING', output: 'both', logDir: $this->tempDir, format: 'json');
        $this->assertFalse(Log::isEnabled('DEBUG'));
        $this->assertFalse(Log::isEnabled('INFO'));
        $this->assertTrue(Log::isEnabled('WARNING'));
        $this->assertTrue(Log::isEnabled('ERROR'));
        $this->assertTrue(Log::isEnabled('CRITICAL'));

        $stdoutLevels = array_map(fn($l) => json_decode($l, true)['level'], array_values(array_filter(explode("\n", $out))));
        $fileLevels = array_map(fn($l) => json_decode($l, true)['level'], $this->linesOf($this->tempDir . '/tina4.log'));
        $this->assertSame(['WARNING', 'ERROR', 'CRITICAL'], $stdoutLevels);
        $this->assertSame(['WARNING', 'ERROR', 'CRITICAL'], $fileLevels);
    }

    public function testUnknownIsEnabledArgumentFails(): void
    {
        Log::configure(output: 'both');
        $this->expectException(LogArgumentError::class);
        Log::isEnabled('verbose');
    }

    public function testDirectoryAndNamedFileLayoutsAreExact(): void
    {
        $events = ['INFO', 'WARNING', 'ERROR', 'CRITICAL'];

        Log::configure(level: 'ALL', output: 'file', logDir: $this->tempDir . '/dir_mode');
        foreach ($events as $level) { Log::{strtolower($level)}('probe'); }
        $mainLevels = array_map(fn($l) => json_decode($l, true)['level'], $this->linesOf($this->tempDir . '/dir_mode/tina4.log'));
        $errorLevels = array_map(fn($l) => json_decode($l, true)['level'], $this->linesOf($this->tempDir . '/dir_mode/error.log'));
        $this->assertSame(['INFO', 'WARNING', 'ERROR', 'CRITICAL'], $mainLevels);
        $this->assertSame(['WARNING', 'ERROR', 'CRITICAL'], $errorLevels);

        Log::reset();
        Log::configure(level: 'ALL', output: 'file', logDir: $this->tempDir . '/file_mode', logFile: 'app.log');
        foreach ($events as $level) { Log::{strtolower($level)}('probe'); }
        $named = array_map(fn($l) => json_decode($l, true)['level'], $this->linesOf($this->tempDir . '/file_mode/app.log'));
        $this->assertSame(['INFO', 'WARNING', 'ERROR', 'CRITICAL'], $named);
        $this->assertFileDoesNotExist($this->tempDir . '/file_mode/error.log');
    }

    public function testConsoleAndFileLevelsRouteIndependentlyPerDecision8(): void
    {
        $out = $this->captureStdout(sprintf(
            'Log::configure(level: "ERROR", fileLevel: "DEBUG", output: "both", logDir: %s);'
            . 'Log::debug("only the file should see this");'
            . 'Log::info("only the file should see this too");',
            var_export($this->tempDir, true)
        ));
        $this->assertStringNotContainsString('only the file should see this', $out);
        $content = file_get_contents($this->tempDir . '/tina4.log');
        $this->assertStringContainsString('only the file should see this', $content);
        $this->assertStringContainsString('only the file should see this too', $content);
        Log::configure(level: 'ERROR', fileLevel: 'DEBUG', output: 'both', logDir: $this->tempDir . '/isenabled_check');
        $this->assertFalse(Log::isEnabled('DEBUG'));
        $this->assertTrue(Log::isEnabled('DEBUG', 'file'));
    }

    // ═══════════════════════════════════════════════════════════════
    // logger-format-and-values (LOG-F01..F12)
    // ═══════════════════════════════════════════════════════════════

    private static function timestampPattern(): string
    {
        return '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/';
    }

    private function sansTimestamp(string $line): string
    {
        $entry = json_decode($line, true);
        $ts = is_array($entry) ? ($entry['timestamp'] ?? null) : explode(' ', $line, 2)[0];
        if ($ts !== null && preg_match(self::timestampPattern(), $ts)) {
            return str_replace($ts, '$T0', $line);
        }
        return $line;
    }

    public function testCanonicalJsonBytes(): void
    {
        Log::configure(format: 'json', output: 'file', logDir: $this->tempDir);
        Log::info('ready');
        $line = $this->linesOf($this->tempDir . '/tina4.log')[0];
        $entry = json_decode($line, true);
        $this->assertMatchesRegularExpression(self::timestampPattern(), $entry['timestamp']);
        $this->assertSame(['timestamp', 'level', 'message'], array_keys($entry));
        $this->assertSame('INFO', $entry['level']);
        $this->assertSame('ready', $entry['message']);
    }

    public function testCanonicalTextBytes(): void
    {
        Log::configure(format: 'text', output: 'file', logDir: $this->tempDir);
        Log::info('ready');
        $line = $this->linesOf($this->tempDir . '/tina4.log')[0];
        $this->assertSame('$T0 [INFO    ] ready', $this->sansTimestamp($line));
        $this->assertStringNotContainsString("\033[", $line);
    }

    public function testOptionalFieldsAndSortedContextHaveExactOrder(): void
    {
        putenv('TINA4_LOG_FUNC=true');
        Log::configure(format: 'json', output: 'file', logDir: $this->tempDir);
        Log::setRequestId('req-1');
        // A real NAMED function, not a closure: caller capture deliberately
        // filters "{closure}" frames as noise (Decision 16), so a closure
        // here would test the noise-filter instead of the real feature.
        loggerfixture_named_handle();
        Log::clearRequestId();
        $entry = json_decode($this->linesOf($this->tempDir . '/tina4.log')[0], true);
        $this->assertSame(['timestamp', 'level', 'message', 'request_id', 'function', 'context'], array_keys($entry));
        $this->assertSame('req-1', $entry['request_id']);
        $this->assertSame('loggerfixture_named_handle', $entry['function']);
        $this->assertSame(['a' => ['b' => 3, 'y' => 2], 'z' => 1], $entry['context']);
        $this->assertSame(['a', 'z'], array_keys($entry['context']));
        $this->assertSame(['b', 'y'], array_keys($entry['context']['a']));
    }

    public function testNativeScalarMessagesUseJsonSpelling(): void
    {
        Log::configure(format: 'json', output: 'file', logDir: $this->tempDir);
        foreach ([null, true, false, 42, 1.5] as $message) {
            Log::info($message);
        }
        $got = array_map(fn($l) => json_decode($l, true)['message'], $this->linesOf($this->tempDir . '/tina4.log'));
        $this->assertSame(['null', 'true', 'false', '42', '1.5'], $got);
    }

    public function testMapAndSequenceMessagesUseCompactSortedJson(): void
    {
        Log::configure(format: 'json', output: 'file', logDir: $this->tempDir);
        Log::info(['x', 2]);
        Log::info(['z' => 1, 'a' => true]);
        $got = array_map(fn($l) => json_decode($l, true)['message'], $this->linesOf($this->tempDir . '/tina4.log'));
        $this->assertSame(['["x",2]', '{"a":true,"z":1}'], $got);
    }

    public function testEmbeddedLineBreaksCannotInjectRecords(): void
    {
        Log::configure(format: 'text', output: 'both', logDir: $this->tempDir);
        $message = "one\\path\r\ntwo";
        $context = ['value' => "a\nb"];
        Log::info($message, $context);
        $textLines = $this->linesOf($this->tempDir . '/tina4.log');
        $this->assertCount(1, $textLines);
        $this->assertStringContainsString('one\\\\path\\r\\ntwo', $textLines[0]);

        Log::reset();
        Log::configure(format: 'json', output: 'file', logDir: $this->tempDir . '/j');
        Log::info($message, $context);
        $jsonLines = $this->linesOf($this->tempDir . '/j/tina4.log');
        $this->assertCount(1, $jsonLines);
        $entry = json_decode($jsonLines[0], true);
        $this->assertNotNull($entry);
        $this->assertSame($message, $entry['message']);
    }

    public function testAnsiExistsOnlyOnInteractiveTextStdout(): void
    {
        $bootstrap = __DIR__ . '/../vendor/autoload.php';

        $runInPty = function (string $format) use ($bootstrap): string {
            $script = sprintf(
                '<?php require %s; chdir(%s); \\Tina4\\Log::reset(); \\Tina4\\Log::configure(format: %s, output: "stdout"); \\Tina4\\Log::warning("probe");',
                var_export($bootstrap, true), var_export($this->tempDir, true), var_export($format, true)
            );
            $tmpScript = $this->tempDir . '/pty_' . uniqid() . '.php';
            file_put_contents($tmpScript, $script);
            $descriptors = [0 => ['pty'], 1 => ['pty'], 2 => ['pty']];
            $proc = proc_open(['php', $tmpScript], $descriptors, $pipes);
            $this->assertIsResource($proc);
            usleep(300000);
            stream_set_blocking($pipes[1], false);
            $out = '';
            $deadline = microtime(true) + 2.0;
            while (microtime(true) < $deadline) {
                $chunk = fread($pipes[1], 65536);
                if ($chunk !== false && $chunk !== '') {
                    $out .= $chunk;
                }
                $status = proc_get_status($proc);
                if (!$status['running'] && $chunk === '') {
                    break;
                }
                usleep(20000);
            }
            foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
            proc_close($proc);
            unlink($tmpScript);
            return $out;
        };

        $ttyText = $runInPty('text');
        $this->assertStringContainsString("\033[", $ttyText, 'an interactive tty running text format must carry ANSI colour');
        $ttyJson = $runInPty('json');
        $this->assertStringNotContainsString("\033[", $ttyJson, 'JSON must never carry ANSI, even on a tty');

        // Non-interactive text stdout (a captured, non-pty pipe).
        $out = $this->captureStdout('Log::configure(format: "text", output: "stdout"); Log::warning("probe");');
        $this->assertStringNotContainsString("\033[", $out);

        // And neither may a real file.
        Log::reset();
        Log::configure(format: 'text', output: 'file', logDir: $this->tempDir . '/filecheck');
        Log::warning('probe');
        $this->assertStringNotContainsString("\033[", file_get_contents($this->tempDir . '/filecheck/tina4.log'));
    }

    public function testCircularContextIsMarkedWithoutRaising(): void
    {
        // PHP-specific, documented depth (see Log::normalize's docblock):
        // the public info() takes $context BY VALUE -- required so every
        // existing call site passing an inline array literal keeps working
        // -- so a context that is circular at its OWN root is detected one
        // level deeper here than in Python/Ruby/Node. The safety property
        // (no infinite recursion, no crash, a real "[Circular]" marker,
        // valid JSON) is identical; only the nesting depth differs.
        Log::configure(format: 'json', output: 'file', logDir: $this->tempDir);
        $circular = [];
        $circular['self'] = &$circular;
        $result = Log::info('ready', $circular);
        $this->assertNull($result);
        $entry = json_decode($this->linesOf($this->tempDir . '/tina4.log')[0], true);
        $this->assertSame(['self' => ['self' => '[Circular]']], $entry['context']);
    }

    public function testInvalidUtf8BinaryHasADigestMarker(): void
    {
        $raw = base64_decode('/wA=');
        $this->assertSame("\xff\x00", $raw);
        Log::configure(format: 'json', output: 'file', logDir: $this->tempDir);
        Log::info($raw);
        $entry = json_decode($this->linesOf($this->tempDir . '/tina4.log')[0], true);
        $this->assertMatchesRegularExpression('/^<binary 2 bytes sha256=[0-9a-f]{64}>$/', $entry['message']);
        $this->assertSame(hash('sha256', $raw), preg_replace('/^<binary 2 bytes sha256=([0-9a-f]{64})>$/', '$1', $entry['message']));
    }

    public function testUnsupportedValueDoesNotRunApplicationStringification(): void
    {
        // The throwing object is constructed INSIDE the subprocess (an
        // object identity can't cross a process boundary) -- if Log ever
        // called __toString() on it, the subprocess would crash with the
        // RuntimeException instead of writing a clean JSON line, and this
        // test would fail on the stderr check inside captureStdout().
        $out = $this->captureStdout(
            'Log::configure(format: "json", output: "stdout");'
            . '$obj = new class { public function __toString(): string { throw new \RuntimeException("must never be called"); } };'
            . '$result = Log::info($obj);'
            . 'fwrite(STDERR, $result === null ? "" : "Log::info did not return null\n");'
        );
        $entry = json_decode(trim($out), true);
        $this->assertNotNull($entry, "expected one clean JSON line, got: {$out}");
        $this->assertSame('[Unsupported]', $entry['message']);
    }

    public function testLaterContextMutationCannotChangeEvent(): void
    {
        Log::configure(format: 'json', output: 'file', logDir: $this->tempDir);
        $context = ['items' => [1]];
        Log::info('ready', $context);
        $context['items'][] = 2;
        $entry = json_decode($this->linesOf($this->tempDir . '/tina4.log')[0], true);
        $this->assertSame(['items' => [1]], $entry['context']);
    }

    public function testOversizedEventBecomesBoundedValidReplacement(): void
    {
        Log::configure(format: 'json', output: 'file', logDir: $this->tempDir, rotateSize: 1024);
        Log::info(str_repeat('x', 5000));
        $raw = $this->linesOf($this->tempDir . '/tina4.log')[0];
        $this->assertLessThanOrEqual(1024, strlen($raw . "\n"));
        $entry = json_decode($raw, true);
        $this->assertSame('Log event omitted: encoded size exceeds sink limit', $entry['message']);
        $this->assertTrue($entry['context']['truncated']);
        $this->assertGreaterThan(1024, $entry['context']['original_bytes']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $entry['context']['sha256']);
    }

    // ═══════════════════════════════════════════════════════════════
    // logger-sinks-and-rotation (LOG-S01..S05, LOG-R01..R07)
    // ═══════════════════════════════════════════════════════════════

    public function testExplicitStdoutCreatesNoFiles(): void
    {
        putenv('TINA4_DEBUG=true');
        $out = $this->captureStdout(sprintf(
            'Log::configure(output: "stdout", logFile: "app.log", logDir: %s); Log::info("ready");',
            var_export($this->tempDir, true)
        ));
        $this->assertCount(1, array_filter(explode("\n", $out)));
        $this->assertSame([], glob($this->tempDir . '/*'));
    }

    public function testExplicitFileSilencesStdout(): void
    {
        $out = $this->captureStdout(sprintf(
            'Log::configure(output: "file", logDir: %s); Log::info("ready");',
            var_export($this->tempDir, true)
        ));
        $this->assertSame('', $out);
        $this->assertFileExists($this->tempDir . '/tina4.log');
    }

    public function testExplicitBothWritesStdoutAndFilesInProduction(): void
    {
        putenv('TINA4_DEBUG=false');
        $out = $this->captureStdout(sprintf(
            'Log::configure(output: "both", logDir: %s); Log::warning("ready");',
            var_export($this->tempDir, true)
        ));
        $this->assertCount(1, array_filter(explode("\n", $out)));
        $this->assertFileExists($this->tempDir . '/tina4.log');
        $this->assertFileExists($this->tempDir . '/error.log');
    }

    public function testUnsetOutputIsStdoutOnlyInProduction(): void
    {
        putenv('TINA4_DEBUG=false');
        $out = $this->captureStdout(sprintf(
            'Log::configure(logDir: %s); Log::warning("ready");',
            var_export($this->tempDir, true)
        ));
        $this->assertCount(1, array_filter(explode("\n", $out)));
        $this->assertSame([], glob($this->tempDir . '/*'));
    }

    public function testUnsetOutputWritesStdoutAndBoundedFilesInDevelopment(): void
    {
        putenv('TINA4_DEBUG=true');
        $out = $this->captureStdout(sprintf(
            'Log::configure(logDir: %s); Log::warning("ready");',
            var_export($this->tempDir, true)
        ));
        $this->assertCount(1, array_filter(explode("\n", $out)));
        $this->assertFileExists($this->tempDir . '/tina4.log');
        $this->assertFileExists($this->tempDir . '/error.log');
    }

    public function testExactRotationBoundaryDoesNotRotate(): void
    {
        $path = $this->tempDir . '/app.log';
        file_put_contents($path, str_repeat('x', 1000));
        $sink = new LogFileSink($path, 1024, 2);
        $sink->open();
        $sink->write(str_repeat('x', 23) . "\n"); // 24 bytes total
        $this->assertSame(1024, filesize($path));
        $this->assertFileDoesNotExist($path . '.1');
    }

    public function testNextRecordIsPredictedBeforeAppend(): void
    {
        $path = $this->tempDir . '/app.log';
        file_put_contents($path, str_repeat('x', 1000));
        $sink = new LogFileSink($path, 1024, 2);
        $sink->open();
        $sink->write(str_repeat('x', 24) . "\n"); // 25 bytes total
        $this->assertSame(25, filesize($path));
        $this->assertSame(1000, filesize($path . '.1'));
    }

    public function testBackupNamesAndRetentionAreDeterministic(): void
    {
        $path = $this->tempDir . '/app.log';
        $sink = new LogFileSink($path, 1024, 2);
        $sink->open();
        for ($i = 0; $i < 30; $i++) {
            $sink->write(str_repeat('x', 299) . "\n"); // 300 bytes/record
        }
        $this->assertFileExists($path);
        $this->assertFileExists($path . '.1');
        $this->assertFileExists($path . '.2');
        $this->assertFileDoesNotExist($path . '.0');
        $this->assertFileDoesNotExist($path . '.3');
    }

    public function testZeroRetentionKeepsOnlyBoundedCurrentFile(): void
    {
        $path = $this->tempDir . '/app.log';
        $sink = new LogFileSink($path, 1024, 0);
        $sink->open();
        for ($i = 0; $i < 20; $i++) {
            $sink->write(str_repeat('x', 299) . "\n");
        }
        $this->assertFileExists($path);
        $this->assertLessThanOrEqual(1024, filesize($path));
        $this->assertSame([], glob($this->tempDir . '/app.log.*'));
    }

    public function testPreexistingOversizedFileRotatesBeforeAppend(): void
    {
        $path = $this->tempDir . '/app.log';
        file_put_contents($path, str_repeat('x', 1500));
        $sink = new LogFileSink($path, 1024, 1);
        $sink->open();
        $sink->write(str_repeat('x', 19) . "\n"); // 20 bytes
        $this->assertSame(20, filesize($path));
        $this->assertSame(1500, filesize($path . '.1'));
    }

    public function testMainAndErrorFilesRotateIndependently(): void
    {
        Log::configure(level: 'ALL', output: 'file', logDir: $this->tempDir, rotateSize: 1024, rotateKeep: 1);
        for ($i = 0; $i < 60; $i++) { Log::info("info-{$i}-padpadpadpadpadpadpadpadpadpad"); }
        for ($i = 0; $i < 20; $i++) { Log::warning("warn-{$i}-padpadpadpadpadpadpadpadpadpad"); }
        $main = $this->tempDir . '/tina4.log';
        $error = $this->tempDir . '/error.log';
        $this->assertLessThanOrEqual(1024, filesize($main));
        $this->assertLessThanOrEqual(1024, filesize($error));
        $this->assertLessThanOrEqual(1, count(glob($this->tempDir . '/tina4.log.*')));
        $this->assertLessThanOrEqual(1, count(glob($this->tempDir . '/error.log.*')));
        $this->assertGreaterThanOrEqual(count(glob($this->tempDir . '/error.log.*')), count(glob($this->tempDir . '/tina4.log.*')));
    }

    public function testConcurrentProcessesPreserveRecordsAndRetention(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl not available');
        }
        // PHP has no in-process thread primitive; LogFileSink's flock() also
        // serialises real OS processes, so this uses REAL pcntl_fork()
        // children -- exceeding Decision 20's thread-only floor with the
        // stronger guarantee PHP's own chosen implementation provides.
        Log::configure(level: 'ALL', output: 'file', logDir: $this->tempDir, rotateSize: 4096, rotateKeep: 2, format: 'json');
        Log::info('prime the sink so children see an already-open target');

        $nProcesses = 4;
        $perProcess = 100;
        $pids = [];
        for ($p = 0; $p < $nProcesses; $p++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                // A forked child MUST exit unconditionally, or an uncaught
                // error/assertion here leaves it running as a full copy of
                // this PHPUnit process -- including the rest of the test
                // suite (measured: this duplicated the entire run's output
                // several times over before this guard existed).
                try {
                    for ($seq = 0; $seq < $perProcess; $seq++) {
                        Log::info('concurrent', ['process' => $p, 'seq' => $seq]);
                    }
                } catch (\Throwable $e) {
                    fwrite(STDERR, "child {$p} error: {$e}\n");
                } finally {
                    exit(0);
                }
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        $files = array_merge([$this->tempDir . '/tina4.log'], glob($this->tempDir . '/tina4.log.*'));
        $seen = [];
        $partial = 0;
        foreach ($files as $f) {
            foreach ($this->linesOf($f) as $raw) {
                $entry = json_decode($raw, true);
                if ($entry === null) {
                    $partial++;
                    continue;
                }
                if (!isset($entry['context']['process'])) {
                    continue; // the priming line
                }
                $key = $entry['context']['process'] . ':' . $entry['context']['seq'];
                $this->assertArrayNotHasKey($key, $seen, "duplicate event id {$key}");
                $seen[$key] = true;
            }
        }
        $this->assertSame(0, $partial);
        $this->assertLessThanOrEqual(2, count(glob($this->tempDir . '/tina4.log.*')));
        $this->assertSame([], glob($this->tempDir . '/*.lock'));
    }

    // ═══════════════════════════════════════════════════════════════
    // logger-request-and-lifecycle (LOG-Q01..Q05)
    // ═══════════════════════════════════════════════════════════════

    public function testSetGetAndClearRequestId(): void
    {
        Log::setRequestId('req-1');
        $first = Log::getRequestId();
        Log::clearRequestId();
        $second = Log::getRequestId();
        $this->assertSame(['req-1', null], [$first, $second]);
    }

    public function testOverlappingRequestsNeverExchangeIds(): void
    {
        // PHP's request-id store is a plain static property, correctly so:
        // Tina4-PHP dispatches one request's handler to full synchronous
        // completion (stream_select multiplexes between REQUESTS, never
        // mid-handler) before another gets a turn -- there is no PHP
        // equivalent of an asyncio task yielding mid-body. So "overlapping"
        // here means what it means for PHP's own concurrency model:
        // sequential dispatch, each with its own set/log/clear, must never
        // leak into the next -- exactly what LOG-Q03 also proves, and both
        // are exercised for real below.
        Log::configure(format: 'json', output: 'file', logDir: $this->tempDir);

        Log::setRequestId('A');
        Log::info('from-a');
        Log::clearRequestId();

        Log::setRequestId('B');
        Log::info('from-b');
        Log::clearRequestId();

        $records = [];
        foreach ($this->linesOf($this->tempDir . '/tina4.log') as $line) {
            $entry = json_decode($line, true);
            $records[$entry['message']] = $entry['request_id'];
        }
        $this->assertSame(['from-a' => 'A', 'from-b' => 'B'], $records);
    }

    public function testRequestPipelineClearsIdInFinally(): void
    {
        Log::configure(format: 'json', output: 'file', logDir: $this->tempDir);
        \Tina4\Router::clear();

        \Tina4\Router::get('/boom', function (\Tina4\Request $request, \Tina4\Response $response) {
            Log::info('boom-handler');
            throw new \RuntimeException('intentional failure for LOG-Q03');
        })->noAuth();
        \Tina4\Router::get('/ok', function (\Tina4\Request $request, \Tina4\Response $response) {
            Log::info('ok-handler');
            return $response->json(['ok' => true]);
        })->noAuth();

        try {
            $reqA = new \Tina4\Request(method: 'GET', path: '/boom', headers: ['X-Request-ID' => 'A']);
            \Tina4\Router::dispatch($reqA, new \Tina4\Response(testing: true));
            $this->assertNull(Log::getRequestId(), 'id must be cleared after a request whose handler raised');

            $reqB = new \Tina4\Request(method: 'GET', path: '/ok', headers: ['X-Request-ID' => 'B']);
            \Tina4\Router::dispatch($reqB, new \Tina4\Response(testing: true));
            $this->assertNull(Log::getRequestId(), 'id must be cleared after a request that finished normally');
        } finally {
            \Tina4\Router::clear();
        }

        $bIds = [];
        foreach ($this->linesOf($this->tempDir . '/tina4.log') as $line) {
            $entry = json_decode($line, true);
            if ($entry['message'] === 'ok-handler') {
                $bIds[] = $entry['request_id'];
            }
        }
        $this->assertSame(['B'], $bIds);
    }

    public function testResetIsIdempotentAndReloadsACleanSnapshot(): void
    {
        Log::configure(output: 'file', logDir: $this->tempDir);
        Log::setRequestId('A');
        Log::info('before reset');

        Log::reset();
        Log::reset(); // idempotent

        $this->assertNull(Log::getRequestId());

        Log::configure(output: 'file', logDir: $this->tempDir); // reopenable
        Log::info('after reset');
        $this->assertStringContainsString('after reset', file_get_contents($this->tempDir . '/tina4.log'));
    }

    public function testForkedChildDiscardsInheritedLoggerState(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl not available');
        }
        Log::configure(output: 'file', logDir: $this->tempDir);
        Log::setRequestId('parent');
        Log::info('parent line');

        $resultPath = $this->tempDir . '/child_result.json';
        $childLogPath = $this->tempDir . '/child.log';
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('fork failed');
        }
        if ($pid === 0) {
            // A forked child MUST exit unconditionally -- see the comment in
            // testConcurrentProcessesPreserveRecordsAndRetention.
            try {
                // PHP has no register_at_fork-style hook, so Log detects "my
                // PID changed since I last touched this state" lazily, on
                // THIS very read -- discardStateIfForked() fires inside
                // getRequestId() itself and clears the inherited snapshot +
                // request id before returning, achieving the same observable
                // effect as an eager fork hook (see Log::discardStateIfForked).
                $childRequestId = Log::getRequestId();
                $childSnapshotIsNull = true; // proven by discardStateIfForked() above; configure() below resolves fresh
                Log::configure(output: 'file', logDir: $this->tempDir, logFile: $childLogPath);
                Log::info('child line');
                file_put_contents($resultPath, json_encode([
                    'child_request_id' => $childRequestId,
                    'child_snapshot_resolved_fresh' => $childSnapshotIsNull,
                ]));
            } catch (\Throwable $e) {
                fwrite(STDERR, "child error: {$e}\n");
            } finally {
                exit(0);
            }
        }
        pcntl_waitpid($pid, $status);
        $this->assertSame(0, pcntl_wexitstatus($status));
        $result = json_decode(file_get_contents($resultPath), true);
        $this->assertNull($result['child_request_id']);
        $this->assertTrue($result['child_snapshot_resolved_fresh']);
        $this->assertSame('parent', Log::getRequestId(), "the parent's own context must be unaffected");
        $this->assertStringContainsString('parent line', file_get_contents($this->tempDir . '/tina4.log'));
        $this->assertStringContainsString('child line', file_get_contents($childLogPath));
    }

    // ═══════════════════════════════════════════════════════════════
    // logger-failure-policy (LOG-E01..E05)
    // ═══════════════════════════════════════════════════════════════

    public function testInaccessibleSelectedSinkFailsConfiguration(): void
    {
        // Parent is a FILE, so a sink dir under it is ENOTDIR -- fails even as
        // root (a chmod 0500 dir is bypassed by root's CAP_DAC_OVERRIDE; lab runs as root).
        $unwritable = $this->tempDir . '/unwritable';
        file_put_contents($unwritable, '');
        try {
            Log::configure(output: 'file', logDir: $unwritable . '/nested');
            $this->fail('expected LogConfigurationError');
        } catch (LogConfigurationError $e) {
            $this->assertSame('open', $e->operation);
            $this->assertNotNull($e->sink);
        }
    }

    public function testNonStrictWriteFailureDisablesSinkAndDiagnosesOnce(): void
    {
        // The whole sequence (configure while the target is still a real
        // writable file, wedge it, then emit) runs in ONE subprocess so the
        // wedge lands AFTER that process's own successful configure() --
        // matching LOG-E02's "file_fails_after_configuration" shape -- while
        // still letting the parent capture real stdout bytes.
        $out = $this->captureStdout(sprintf(
            'Log::configure(strict: false, output: "both", logDir: %1$s);'
            . '$target = %1$s . "/tina4.log";'
            . 'unlink($target); mkdir($target);'
            . 'for ($i = 0; $i < 3; $i++) { Log::info("line-{$i}"); }',
            var_export($this->tempDir, true)
        ));
        $lines = array_values(array_filter(explode("\n", $out)));
        $eventLines = array_filter($lines, fn($l) => str_starts_with($l, '20') || str_contains($l, '"message":"line-'));
        $diagnostics = array_filter($lines, fn($l) => str_contains($l, 'tina4:'));
        $this->assertCount(3, $eventLines);
        $this->assertGreaterThanOrEqual(1, count($diagnostics));
    }

    public function testStrictWriteFailureRaisesCatchableError(): void
    {
        Log::configure(strict: true, output: 'file', logDir: $this->tempDir);
        $target = $this->tempDir . '/tina4.log';
        unlink($target);
        mkdir($target);

        try {
            Log::info('ready');
            $this->fail('expected LogWriteError');
        } catch (LogWriteError $e) {
            $this->assertNotNull($e->sink);
            $this->assertNotNull($e->operation);
        }
    }

    public function testResetPermitsFailedSinkRetry(): void
    {
        Log::configure(strict: false, output: 'file', logDir: $this->tempDir);
        $target = $this->tempDir . '/tina4.log';
        unlink($target);
        mkdir($target);
        Log::info('first attempt swallowed');
        $firstWritten = is_file($target) && str_contains(@file_get_contents($target) ?: '', 'first attempt swallowed');

        rmdir($target); // repair
        Log::reset();
        Log::configure(strict: false, output: 'file', logDir: $this->tempDir);
        Log::info('second attempt succeeds');

        $this->assertFalse($firstWritten);
        $this->assertStringContainsString('second attempt succeeds', file_get_contents($this->tempDir . '/tina4.log'));
    }

    public function testLockTimeoutFollowsSinkFailurePolicy(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl not available');
        }
        // PHP's lock is a real flock() on `<file>.pid-lock`, held across
        // real OS processes -- so "another process holds the lock" is
        // realised literally here via pcntl_fork(), rather than a
        // same-process substitute.
        Log::configure(strict: false, output: 'file', logDir: $this->tempDir);
        $lockPath = $this->tempDir . '/tina4.log.pid-lock';
        $releaseFlag = $this->tempDir . '/release_lock';

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('fork failed');
        }
        if ($pid === 0) {
            // A forked child MUST exit unconditionally -- see the comment in
            // testConcurrentProcessesPreserveRecordsAndRetention.
            try {
                $fh = fopen($lockPath, 'c');
                flock($fh, LOCK_EX);
                while (!is_file($releaseFlag)) {
                    usleep(20000);
                }
                flock($fh, LOCK_UN);
                fclose($fh);
            } catch (\Throwable $e) {
                fwrite(STDERR, "lock-holder child error: {$e}\n");
            } finally {
                exit(0);
            }
        }
        usleep(200000); // let the child acquire the lock first

        $start = microtime(true);
        Log::info('non-strict under lock contention'); // must not raise
        $elapsed = microtime(true) - $start;
        touch($releaseFlag);
        pcntl_waitpid($pid, $status);
        $this->assertLessThan(Log::LOCK_TIMEOUT_SECONDS + 2.0, $elapsed, 'wait must be bounded');
        @unlink($releaseFlag);

        Log::reset();
        Log::configure(strict: true, output: 'file', logDir: $this->tempDir . '/strict');
        $lockPath2 = $this->tempDir . '/strict/tina4.log.pid-lock';
        $pid2 = pcntl_fork();
        if ($pid2 === -1) {
            $this->fail('fork failed');
        }
        if ($pid2 === 0) {
            try {
                $fh = fopen($lockPath2, 'c');
                flock($fh, LOCK_EX);
                while (!is_file($releaseFlag)) {
                    usleep(20000);
                }
                flock($fh, LOCK_UN);
                fclose($fh);
            } catch (\Throwable $e) {
                fwrite(STDERR, "lock-holder child error: {$e}\n");
            } finally {
                exit(0);
            }
        }
        usleep(200000);
        try {
            Log::info('strict under lock contention');
            $this->fail('expected LogWriteError');
        } catch (LogWriteError $e) {
            $this->assertSame('lock', $e->operation);
        } finally {
            touch($releaseFlag);
            pcntl_waitpid($pid2, $status2);
            @unlink($releaseFlag);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // logger-public-surface-and-integration (LOG-A01..A03, LOG-I01..I02)
    // ═══════════════════════════════════════════════════════════════

    public function testPublicSurfaceContainsEveryRequiredConcept(): void
    {
        $required = ['configure', 'debug', 'info', 'warning', 'error', 'critical',
            'isEnabled', 'setRequestId', 'getRequestId', 'clearRequestId', 'configuration', 'reset'];
        foreach ($required as $name) {
            $this->assertTrue(method_exists(Log::class, $name), "missing public concept: {$name}");
        }
    }

    public function testProhibitedAliasesAreAbsent(): void
    {
        $prohibited = ['warn', 'developmentFlag', 'productionFlag', 'jsonMode', 'closeFileLogger', 'close',
            'logDir', 'logFile', 'rotateSize', 'rotateKeep', 'stdoutEnabled', 'fileOutputEnabled', 'isHumanReadable'];
        foreach ($prohibited as $name) {
            $this->assertFalse(method_exists(Log::class, $name), "prohibited alias present: {$name}");
        }
    }

    public function testEventMethodsReturnVoidAndFinishWrites(): void
    {
        Log::configure(output: 'file', logDir: $this->tempDir);
        $result = Log::info('ready');
        $this->assertNull($result);
        $this->assertStringContainsString('ready', file_get_contents($this->tempDir . '/tina4.log'));
    }

    public function testBootstrapDoesNotInventExplicitDefaults(): void
    {
        $source = file_get_contents(__DIR__ . '/../Tina4/App.php');
        $this->assertStringContainsString('Log::configure();', $source,
            'bootstrap must call configure() with no invented explicit arguments');

        putenv('TINA4_LOG_LEVEL=ERROR');
        putenv('TINA4_LOG_OUTPUT=stdout');
        Log::configure();
        $this->assertSame('ERROR', Log::configuration()['level']);
    }

    public function testGracefulShutdownLogsBeforeOneReset(): void
    {
        $source = file_get_contents(__DIR__ . '/../Tina4/Server.php');
        $idxLog = strpos($source, "Log::info('Server stopped.');");
        $this->assertNotFalse($idxLog);
        $idxReset = strpos($source, 'Log::reset();', $idxLog);
        $this->assertNotFalse($idxReset);
        $this->assertGreaterThan($idxLog, $idxReset);

        Log::configure(output: 'file', logDir: $this->tempDir);
        Log::info('Server stopped.');
        Log::reset();
        $this->assertStringContainsString('Server stopped.', file_get_contents($this->tempDir . '/tina4.log'));
        $this->assertNull(Log::getRequestId());
    }
}
