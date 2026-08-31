<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Real tests for the `lint` CLI command (PHP mirror of the Python master's
 * tests/test_cli_lint.py).
 *
 * The framework ships NO linter; `tina4php lint` runs the project's own phpcs and
 * INSTALLS it as a DEV dependency on demand, with a zero-dependency `php -l` syntax
 * baseline as the fallback. NO mocks — every case drives the REAL `bin/tina4php` as
 * a subprocess over a real temp project:
 *
 *   * the baseline cases run the real `php -l` parse over real files;
 *   * the install cases run a REAL `composer require --dev squizlabs/php_codesniffer`
 *     in a throwaway project and read the mutated composer.json back — real composer,
 *     real phpcs, real manifest mutation (needs composer + network);
 *   * the registration case reads the real `commands --json` manifest (the same
 *     $TINA4_COMMANDS registry that drives dispatch).
 */

use PHPUnit\Framework\TestCase;

class CliLintTest extends TestCase
{
    /** Valid PHP — the baseline `php -l` passes it. */
    private const CLEAN = "<?php\n\nfunction add(\$a, \$b)\n{\n    return \$a + \$b;\n}\n";
    /** Missing semicolon + unclosed brace — a real parse error. */
    private const BROKEN = "<?php\nfunction add(\$a, \$b) { return \$a + \$b\n";

    private static string $bin;
    private static string $emptyIni;
    private string $dir;

    public static function setUpBeforeClass(): void
    {
        $bin = realpath(__DIR__ . '/../bin/tina4php');
        self::assertNotFalse($bin, 'bin/tina4php not found');
        self::$bin = $bin;

        // An empty php.ini so the child CLI's STDOUT is not polluted by this host's
        // PECL startup warnings (e.g. a missing grpc.so loaded from the main ini).
        // Used ONLY for invocations that spawn no composer subprocess — composer
        // needs the real ini (openssl/phar) to fetch phpcs over HTTPS.
        self::$emptyIni = sys_get_temp_dir() . '/tina4-lint-empty-' . getmypid() . '.ini';
        file_put_contents(self::$emptyIni, '');
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$emptyIni);
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tina4-clilint-' . getmypid() . '-' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) && !is_link($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Run the REAL `bin/tina4php <args...>` in the temp project dir.
     *
     * $cleanIni points the child at an empty PHPRC so this host's PECL startup
     * warnings never land on the CLI's stdout — safe ONLY when the command spawns
     * no composer (composer needs the real ini). The full parent env is inherited
     * (PATH/HOME/COMPOSER_* etc.) so composer and phpcs resolve exactly as a user's
     * would.
     *
     * @param list<string> $args
     * @return array{0: string, 1: string, 2: int} [stdout, stderr, exitCode]
     */
    private function runCli(array $args, bool $cleanIni = false): array
    {
        $cmd = array_merge([PHP_BINARY, self::$bin], $args);

        $env = getenv();
        // Default to PHP's real ini discovery (the composer/phpcs path needs
        // openssl+phar), regardless of a PHPRC the parent phpunit may have set to
        // silence its own startup noise — otherwise an empty PHPRC would leak in
        // and break the HTTPS install. cleanIni then opts a composer-free run into
        // the empty ini for pristine stdout.
        unset($env['PHPRC'], $env['PHP_INI_SCAN_DIR']);
        $env['TINA4_NO_BROWSER'] = 'true';
        if ($cleanIni) {
            $env['PHPRC'] = self::$emptyIni;
            $env['PHP_INI_SCAN_DIR'] = '';
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, $this->dir, $env);
        self::assertIsResource($process, 'failed to spawn bin/tina4php');

        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$out, $err, $exit];
    }

    /** Syntax-check a file with `php -l` (ini-independent via -n). Returns its exit code. */
    private function phpLint(string $file): int
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' -n -l ' . escapeshellarg($file);
        @exec($cmd . ' 2>/dev/null', $_, $code);
        return $code;
    }

    /** Skip loudly (with a machine-readable reason) if composer is genuinely absent. */
    private function requireComposer(): void
    {
        $which = trim((string)@shell_exec('command -v composer 2>/dev/null'));
        if ($which === '') {
            $this->markTestSkipped('[needs:composer] composer not on PATH — required for the on-demand phpcs install');
        }
    }

    private function writeProbeComposerJson(string $name): void
    {
        file_put_contents(
            $this->dir . '/composer.json',
            json_encode(['name' => $name, 'require' => new stdClass()], JSON_PRETTY_PRINT) . "\n"
        );
    }

    // ── Zero-dependency baseline (php -l) ─────────────────────────────────

    public function testCleanSrcFilePassesBaseline(): void
    {
        mkdir($this->dir . '/src');
        file_put_contents($this->dir . '/src/Ok.php', self::CLEAN);

        [$out, $err, $exit] = $this->runCli(['lint', '--no-install'], cleanIni: true);

        $this->assertSame(0, $exit, "clean src must pass; stdout:\n{$out}\nstderr:\n{$err}");
        $this->assertStringContainsString('lint clean', $out);
        $this->assertStringContainsString('[php -l]', $out); // the baseline ran, not phpcs
    }

    public function testSyntaxErrorInSrcFailsBaseline(): void
    {
        mkdir($this->dir . '/src');
        file_put_contents($this->dir . '/src/Bad.php', self::BROKEN);

        [$out, $err, $exit] = $this->runCli(['lint', '--no-install'], cleanIni: true);

        $this->assertSame(1, $exit, "syntax error must fail; stdout:\n{$out}\nstderr:\n{$err}");
        $this->assertStringContainsString('lint failed', $out);
        $this->assertStringContainsString('src/Bad.php', $out);
    }

    public function testIndexPhpIsInScope(): void
    {
        // No src/ at all — only the entrypoint. A broken index.php must still fail,
        // proving index.php is part of the lint scope (parity with app.py in Python).
        file_put_contents($this->dir . '/index.php', "<?php\n\$x = ;\n");

        [$out, $err, $exit] = $this->runCli(['lint', '--no-install'], cleanIni: true);

        $this->assertSame(1, $exit, "broken index.php must fail; stdout:\n{$out}\nstderr:\n{$err}");
        $this->assertStringContainsString('index.php', $out);
    }

    public function testNothingToLintIsClean(): void
    {
        // Empty project: no src/, no index.php.
        [$out, $err, $exit] = $this->runCli(['lint', '--no-install'], cleanIni: true);

        $this->assertSame(0, $exit, "nothing to lint must exit 0; stdout:\n{$out}\nstderr:\n{$err}");
        $this->assertStringContainsString('nothing to lint', $out);
    }

    // ── Registration ─────────────────────────────────────────────────────

    public function testLintIsARegisteredCommand(): void
    {
        // The `commands --json` manifest is driven by the same $TINA4_COMMANDS
        // registry that dispatches commands — the PHP analog of Python's COMMANDS.
        [$out, $err, $exit] = $this->runCli(['commands', '--json'], cleanIni: true);
        $this->assertSame(0, $exit, "commands --json must exit 0; stderr:\n{$err}");

        $manifest = json_decode($out, true);
        $this->assertIsArray($manifest, "commands --json must be valid JSON; got:\n{$out}");
        $this->assertArrayHasKey('commands', $manifest);

        $names = array_map(
            static fn($cmd): string => is_array($cmd) ? (string)($cmd['name'] ?? '') : (string)$cmd,
            $manifest['commands']
        );
        $this->assertContains('lint', $names, 'lint must be a registered command');
    }

    // ── On-demand install (REAL — no mock: composer + network) ────────────

    public function testLintInstallsPhpcsAsDevDependencyOnDemand(): void
    {
        $this->requireComposer();

        $this->writeProbeComposerJson('tina4/lintprobe');
        mkdir($this->dir . '/src');
        file_put_contents($this->dir . '/src/Probe.php', "<?php\n\n\$value = 1;\n");

        // Precondition: phpcs is genuinely absent before the run.
        $this->assertFileDoesNotExist($this->dir . '/vendor/bin/phpcs');

        // No --no-install: running the command is the consent to add phpcs. NO
        // cleanIni here — the composer subprocess needs the real ini for HTTPS.
        [$out, $err, $exit] = $this->runCli(['lint']);

        // The manifest was really mutated: phpcs is now a DEV dependency.
        $manifest = json_decode((string)file_get_contents($this->dir . '/composer.json'), true);
        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('require-dev', $manifest, "require-dev missing; stdout:\n{$out}\nstderr:\n{$err}");
        $this->assertArrayHasKey(
            'squizlabs/php_codesniffer',
            $manifest['require-dev'],
            "phpcs was not added to require-dev; stdout:\n{$out}\nstderr:\n{$err}"
        );

        // phpcs was really installed and RAN (not the php -l baseline).
        $this->assertFileExists($this->dir . '/vendor/bin/phpcs');
        $this->assertStringContainsString('[phpcs]', $out, "phpcs (not php -l) must have run; stdout:\n{$out}");
    }

    public function testInstalledPhpcsCatchesAViolationTheBaselineWouldPass(): void
    {
        $this->requireComposer();

        $this->writeProbeComposerJson('tina4/lintprobe2');
        mkdir($this->dir . '/src');
        // Valid PHP syntax, but a PSR12 ERROR: the class/method opening braces are
        // on the same line as the declaration. `php -l` PASSES this (so the
        // zero-dependency baseline would report it clean); phpcs FAILS it — so a
        // non-zero exit here proves phpcs, not the baseline, produced the verdict.
        $probe = $this->dir . '/src/StyleViolation.php';
        file_put_contents(
            $probe,
            "<?php\n\nclass StyleViolation {\n    public function value() {\n        return 1;\n    }\n}\n"
        );
        $this->assertSame(0, $this->phpLint($probe), 'probe must be valid PHP syntax (baseline would pass it)');

        [$out, $err, $exit] = $this->runCli(['lint']); // installs phpcs, then runs it

        $this->assertFileExists($this->dir . '/vendor/bin/phpcs'); // the real install happened
        $this->assertStringContainsString('[phpcs]', $out, "phpcs must have run; stdout:\n{$out}\nstderr:\n{$err}");
        $this->assertSame(1, $exit, "phpcs must flag the PSR12 violation php -l would pass; stdout:\n{$out}\nstderr:\n{$err}");
    }
}
