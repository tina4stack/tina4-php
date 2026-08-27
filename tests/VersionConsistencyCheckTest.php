<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Real-subprocess tests for scripts/check-version-consistency.php — the pre-tag
 * precheck a release worker runs BEFORE `git tag` to catch a partial version
 * bump (a version-bearing file left behind).
 *
 * NO MOCKS: every test invokes the REAL script as a `php` child process via
 * proc_open and asserts on its real stdout + real exit code.
 *   - the pass case runs against the REAL repo at HEAD, proving the script is
 *     green on the version this checkout actually ships;
 *   - the drift cases build a real fixture tree on disk (real copies of every
 *     checked file), corrupt exactly ONE real file, and prove the script exits
 *     non-zero AND names the drifted file with its wrong value.
 *
 * The subprocess extracts the version literals with the SAME regexes the script
 * uses; the fixture setup asserts each corruption actually landed (count === 1)
 * so a no-op rewrite can never turn a drift test into a ghost that passes green.
 */

use PHPUnit\Framework\TestCase;

class VersionConsistencyCheckTest extends TestCase
{
    private static string $script;
    private static string $repoRoot;
    private static string $currentVersion;
    private string $fixtureRoot;

    public static function setUpBeforeClass(): void
    {
        $script = realpath(__DIR__ . '/../scripts/check-version-consistency.php');
        self::assertNotFalse($script, 'scripts/check-version-consistency.php not found');
        self::$script   = $script;
        self::$repoRoot = dirname(__DIR__);

        // Pin the pass test to whatever HEAD actually declares, read straight
        // from the single source of truth.
        $appPhp = file_get_contents(self::$repoRoot . '/Tina4/App.php');
        self::assertIsString($appPhp, 'could not read Tina4/App.php');
        self::assertSame(1, preg_match("~\\\$VERSION\\s*=\\s*'([^']*)'~", $appPhp, $match),
            'could not extract $VERSION from Tina4/App.php');
        self::$currentVersion = $match[1];
    }

    protected function tearDown(): void
    {
        if (isset($this->fixtureRoot) && is_dir($this->fixtureRoot)) {
            self::removeTree($this->fixtureRoot);
        }
    }

    /**
     * Run the precheck as a real subprocess. Returns [stdout, stderr, exitCode].
     * A null $root exercises the script's DEFAULT root (the real repo).
     */
    private function runCheck(string $expected, ?string $root = null): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::$script)
             . ' ' . escapeshellarg($expected);
        if ($root !== null) {
            $cmd .= ' ' . escapeshellarg($root);
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, self::$repoRoot);
        self::assertIsResource($process, 'failed to start the precheck subprocess');

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$out, $err, $exit];
    }

    /** Build a fixture repo holding real copies of every checked file. */
    private function makeFixture(): string
    {
        $root = sys_get_temp_dir() . '/tina4-versioncheck-' . getmypid() . '-' . uniqid();
        mkdir($root . '/Tina4', 0755, true);
        copy(self::$repoRoot . '/Tina4/App.php', $root . '/Tina4/App.php');
        copy(self::$repoRoot . '/CLAUDE.md', $root . '/CLAUDE.md');
        copy(self::$repoRoot . '/composer.json', $root . '/composer.json');
        return $root;
    }

    private static function removeTree(string $dir): void
    {
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // ── positive: the shipped tree is consistent at HEAD ────────────────────

    public function testAllVersionBearingFilesAgreeAtHead(): void
    {
        [$out, , $exit] = $this->runCheck(self::$currentVersion);

        self::assertSame(0, $exit,
            'precheck must pass at HEAD for ' . self::$currentVersion . ":\n" . $out);
        self::assertStringContainsString('PASS  Tina4/App.php  ' . self::$currentVersion, $out);
        self::assertStringContainsString('PASS  CLAUDE.md  ' . self::$currentVersion, $out);
        self::assertStringContainsString('agree on ' . self::$currentVersion, $out);
        self::assertStringNotContainsString('FAIL', $out);
        self::assertStringNotContainsString('DRIFT', $out);
    }

    // ── negative: a partial bump left Tina4/App.php behind ──────────────────

    public function testDriftInAppPhpFailsAndNamesThatFile(): void
    {
        $this->fixtureRoot = $this->makeFixture();

        // Corrupt ONLY App.php's $VERSION — the classic "bumped everything but
        // this one" miss. CLAUDE.md keeps the real, matching version.
        $appPath = $this->fixtureRoot . '/Tina4/App.php';
        $rewritten = preg_replace(
            "~(\\\$VERSION\\s*=\\s*')[^']*(')~",
            '${1}9.9.9${2}',
            (string) file_get_contents($appPath),
            1,
            $count
        );
        self::assertSame(1, $count, 'fixture setup failed to rewrite $VERSION');
        file_put_contents($appPath, $rewritten);

        [$out, , $exit] = $this->runCheck(self::$currentVersion, $this->fixtureRoot);

        self::assertNotSame(0, $exit, "drift must exit non-zero:\n" . $out);
        self::assertStringContainsString('Tina4/App.php', $out); // names the drifted file
        self::assertStringContainsString('9.9.9', $out);         // reports the wrong value
        self::assertStringContainsString('DRIFT', $out);
        // The untouched file is still PASS — the check pinpoints, not blanket-fails.
        self::assertStringContainsString('PASS  CLAUDE.md', $out);
    }

    // ── negative: drift in the OTHER file is named too (not hardcoded) ──────

    public function testDriftInClaudeMdFailsAndNamesThatFile(): void
    {
        $this->fixtureRoot = $this->makeFixture();

        $claudePath = $this->fixtureRoot . '/CLAUDE.md';
        $rewritten = preg_replace(
            '/^Version\s+\d+\.\d+\.\d+/m',
            'Version 9.9.9',
            (string) file_get_contents($claudePath),
            1,
            $count
        );
        self::assertSame(1, $count, 'fixture setup failed to rewrite the CLAUDE.md footer');
        file_put_contents($claudePath, $rewritten);

        [$out, , $exit] = $this->runCheck(self::$currentVersion, $this->fixtureRoot);

        self::assertNotSame(0, $exit, "drift must exit non-zero:\n" . $out);
        self::assertStringContainsString('CLAUDE.md', $out); // names the drifted file
        self::assertStringContainsString('9.9.9', $out);
        self::assertStringContainsString('DRIFT', $out);
        self::assertStringContainsString('PASS  Tina4/App.php', $out);
    }

    // ── negative: a malformed expected version is a usage error ─────────────

    public function testMalformedExpectedVersionIsUsageError(): void
    {
        [, $err, $exit] = $this->runCheck('not-a-version');
        self::assertSame(2, $exit, 'a bad version argument must exit 2');
        self::assertStringContainsString('usage', $err);
    }
}
