<?php

// Global namespace, like PgTestEnv, AppTestSupport, FreePort and TestServer:
// this repo maps PSR-4 Tina4\ -> Tina4/, so a Tina4\Tests\... class here would
// never autoload. Required from tests/bootstrap.php.

/**
 * Run a REAL php subprocess with a chosen set of extensions genuinely absent.
 *
 * This is the one mechanism in the suite for "what happens when extension X is
 * not installed". Nothing is faked: PHP_INI_SCAN_DIR is pointed at a COPY of
 * this host's own conf.d with the .ini files that load the named extensions
 * left out, so those shared objects are never dlopen'd and extension_loaded()
 * really answers false in the child. There is no monkeypatch, no stub function
 * and no redefined constant anywhere in the path.
 *
 * WHY IT EXISTS
 *
 * A negative test written as "if the extension IS here, skip" can never run on
 * a fully provisioned box - the better the machine, the less it tests. Three of
 * them sat in tests/DatabaseDriversTest.php (ext-pgsql, ext-mysqli,
 * ext-interbase) and one in tests/QueueBackendTest.php (ext-mongodb): every one
 * of them skipped green on CI and on the lab, so the error message a user with
 * a bare PHP actually hits was asserted by nobody. Inverting the gate is not
 * enough either - it just moves the green skip to the other kind of host. The
 * only honest answer is to CREATE the absence, which is what this does.
 *
 * ASSERT THE INSTRUMENT FIRST
 *
 * Every child reports what it could see before it reports what it measured, and
 * the caller checks that report FIRST via assertExtensionsReallyAbsent(). The
 * total extension count is checked EXACTLY and is DERIVED from this process's
 * own count (never hardcoded), so a child that quietly inherited the full
 * conf.d fails loudly instead of passing while measuring nothing. Without that
 * check every assertion in the child would be vacuous on a host where the
 * extension happens to be missing anyway.
 */
final class PhpChild
{
    /** The single line a child prints to hand its findings back. */
    public const REPORT_PREFIX = 'TINA4_REPORT ';

    /** @var string[] Temp files and directories to remove when the process ends. */
    private static array $temporaryPaths = [];

    private static bool $cleanupRegistered = false;

    /**
     * Run $source in a php subprocess holding this host's whole extension set
     * MINUS exactly $withoutExtensions.
     *
     * Everything the framework legitimately uses stays present, which keeps a
     * real failure legible instead of turning it into a missing-function fatal.
     *
     * @param string[]              $withoutExtensions Extension names, e.g. ['pgsql'].
     * @param string                $source            PHP source for the child (no leading tag).
     * @param array<string, string> $environment       The child's COMPLETE environment.
     * @return array{report: array<string, mixed>|null, stdout: string, stderr: string, exit: int}
     */
    public static function runWithoutExtensions(array $withoutExtensions, string $source, array $environment = []): array
    {
        $environment['PHP_INI_SCAN_DIR'] = self::scanDirectoryWithout($withoutExtensions);

        return self::run([PHP_BINARY, '-d', 'display_startup_errors=0'], $source, $environment);
    }

    /**
     * Run $source in a php subprocess with NO shared extension at all.
     *
     * `-n` makes PHP ignore php.ini and every conf.d file, so nothing is
     * dlopen'd: no optional client, and no SQL driver either.
     *
     * @param string                $source      PHP source for the child (no leading tag).
     * @param array<string, string> $environment The child's COMPLETE environment.
     * @return array{report: array<string, mixed>|null, stdout: string, stderr: string, exit: int}
     */
    public static function runWithNoSharedExtensions(string $source, array $environment = []): array
    {
        return self::run([PHP_BINARY, '-n', '-d', 'display_startup_errors=0'], $source, $environment);
    }

    /**
     * A copy of this host's conf.d holding every .ini EXCEPT the ones that load
     * one of $extensions.
     *
     * Each file is inspected for its own `extension=` / `zend_extension=` line,
     * so the decision is made on what the file actually loads rather than on its
     * name. That matters: 20-pdo_pgsql.ini and 20-pgsql.ini load DIFFERENT
     * extensions, and dropping pgsql must not drop pdo_pgsql with it.
     *
     * @param string[] $extensions
     */
    public static function scanDirectoryWithout(array $extensions): string
    {
        $extensions = array_map('strtolower', $extensions);
        $target = self::temporaryDirectory('conf.d');
        $source = PHP_CONFIG_FILE_SCAN_DIR;

        foreach ((array)glob(rtrim($source, '/') . '/*.ini') as $file) {
            $file = (string)$file;
            $body = (string)@file_get_contents($file);

            $loaded = [];
            if (preg_match_all('/^\s*(?:zend_)?extension\s*=\s*"?([^"\s;]+)/mi', $body, $matches)) {
                foreach ($matches[1] as $name) {
                    $loaded[] = strtolower((string)preg_replace('/\.so$/i', '', basename($name)));
                }
            }

            if (array_intersect($loaded, $extensions) !== []) {
                continue;   // this file loads one of the targets - leave it out
            }
            copy($file, $target . '/' . basename($file));
        }

        return $target;
    }

    /**
     * The extension baseline as a CHILD sees conf.d RIGHT NOW.
     *
     * WHY NOT count(get_loaded_extensions()) IN THE PARENT
     *
     * That is what this used to compare against, and it is a real trap. The
     * PHPUnit parent fixes its extension set once, at startup; every child
     * re-reads conf.d at the moment it is spawned. Anything that changes conf.d
     * mid-run therefore breaks the arithmetic while nothing is actually wrong.
     * It happened for real on 2026-08-05: ext-interbase was installed WHILE a
     * suite was in flight, the parent had 57 extensions and the child found 58,
     * and SessionZeroDependencyFallbackTest failed with "57 extensions where
     * exactly 56 were expected". The instrument was right to check the count
     * exactly - that exactness is what stops a vacuous pass - but it was
     * comparing two different moments.
     *
     * So the baseline is measured by a child too, spawned seconds before the
     * one under test and through the SAME copy-the-conf.d path (with nothing
     * filtered out). Both numbers then come from the same conf.d as it exists
     * now, and the expectation stays EXACT rather than being loosened to a
     * range. Copying with an empty filter also proves the copy step itself
     * drops nothing.
     *
     * @param string[] $extensions Extensions whose presence to report.
     * @return array{count: int, present: array<string, bool>}
     */
    public static function childExtensionBaseline(array $extensions): array
    {
        $source = self::instrumentSource($extensions) . self::reportSource("['instrument' => \$instrument]");
        $result = self::runWithoutExtensions([], $source);

        if (!is_array($result['report'])) {
            throw new RuntimeException(
                "could not measure the child extension baseline.\nexit: {$result['exit']}\n"
                . "stdout:\n{$result['stdout']}\nstderr:\n{$result['stderr']}"
            );
        }

        $instrument = $result['report']['instrument'];
        $present = [];
        foreach ($extensions as $name) {
            $present[$name] = (bool)$instrument["extension_loaded:{$name}"];
        }

        return ['count' => (int)$instrument['loaded_extension_count'], 'present' => $present];
    }

    /**
     * The exact number of extensions a child WITHOUT $extensions must report,
     * derived from a baseline child measured moments earlier.
     *
     * @param array{count: int, present: array<string, bool>} $baseline
     * @param string[]                                        $extensions
     */
    public static function expectedCountWithout(array $baseline, array $extensions): int
    {
        $removed = 0;
        foreach ($extensions as $name) {
            $removed += ($baseline['present'][$name] ?? false) ? 1 : 0;
        }

        return $baseline['count'] - $removed;
    }

    /**
     * Which of $extensions THIS process actually has loaded.
     *
     * @param string[] $extensions
     * @return string[]
     */
    public static function loadedAmong(array $extensions): array
    {
        return array_values(array_filter($extensions, static fn (string $name): bool => extension_loaded($name)));
    }

    /**
     * The preamble a child runs before anything else: prove the extensions
     * really are gone, and say so, BEFORE the case measures a thing.
     *
     * Produces `$instrument`, which the child must include in its report under
     * the 'instrument' key for assertExtensionsReallyAbsent() to check.
     *
     * @param string[] $extensions
     */
    public static function instrumentSource(array $extensions): string
    {
        $names = var_export(array_values($extensions), true);

        return <<<CHILD
        \$instrument = ['loaded_extension_count' => count(get_loaded_extensions())];
        foreach ({$names} as \$tina4Extension) {
            \$instrument["extension_loaded:{\$tina4Extension}"] = extension_loaded(\$tina4Extension);
        }

        CHILD;
    }

    /**
     * The single line a child prints to hand $payload back to the test.
     *
     * @param string $payloadExpression PHP expression evaluating to the array.
     */
    public static function reportSource(string $payloadExpression): string
    {
        return 'echo "' . self::REPORT_PREFIX . '" . json_encode(' . $payloadExpression . ') . "\n";';
    }

    /**
     * Start a real php subprocess, run it to completion, return its report.
     *
     * @param string[]              $command     The php binary plus its flags.
     * @param string                $source      PHP source for the child (no leading tag).
     * @param array<string, string> $environment The child's COMPLETE environment.
     * @return array{report: array<string, mixed>|null, stdout: string, stderr: string, exit: int}
     */
    public static function run(array $command, string $source, array $environment = []): array
    {
        $script = self::temporaryDirectory('child') . '/case.php';
        file_put_contents($script, "<?php\n" . $source);
        self::$temporaryPaths[] = $script;
        $command[] = $script;

        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            sys_get_temp_dir(),   // never the repo, so no stray .env is read
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('could not start the php subprocess');
        }

        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $report = null;
        foreach (explode("\n", $stdout) as $line) {
            if (str_starts_with($line, self::REPORT_PREFIX)) {
                $decoded = json_decode(substr($line, strlen(self::REPORT_PREFIX)), true);
                if (is_array($decoded)) {
                    $report = $decoded;
                }
            }
        }

        return ['report' => $report, 'stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exitCode];
    }

    private static function temporaryDirectory(string $label): string
    {
        self::registerCleanup();
        $path = sys_get_temp_dir() . "/tina4-php-child-{$label}-" . bin2hex(random_bytes(4));
        mkdir($path, 0777, true);
        self::$temporaryPaths[] = $path;

        return $path;
    }

    private static function registerCleanup(): void
    {
        if (self::$cleanupRegistered) {
            return;
        }
        self::$cleanupRegistered = true;
        register_shutdown_function(static function (): void {
            foreach (array_reverse(self::$temporaryPaths) as $path) {
                if (is_dir($path)) {
                    foreach ((array)glob($path . '/*') as $file) {
                        @unlink((string)$file);
                    }
                    @rmdir($path);
                    continue;
                }
                @unlink($path);
            }
            self::$temporaryPaths = [];
        });
    }
}
