<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;

/**
 * Pins the exit-code contract of `tina4php test` (PHP parity of python#96).
 *
 * Before the fix, the `test` case called passthru() WITHOUT capturing its
 * result code and then `break`ed, so `tina4php test` always exited 0 — a red
 * suite sailed past a `tina4php test || exit 1` CI gate. These tests spawn the
 * REAL bin/tina4php as a child process (no doubles) with cwd pointed at a temp
 * project, and assert the runner's exit code is propagated verbatim.
 *
 * The test_v3_smoke.php branch is used deliberately: it exercises the exact
 * passthru -> exit path with no phpunit bootstrap needed. The temp project has
 * no vendor/, so the CLI autoloads against THIS framework's vendor (its
 * autoload search falls through to the binary-relative path) — real boot, real
 * child spawn.
 */
class CliTestExitCodeTest extends TestCase
{
    private string $tmpDir;
    private string $binPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/tina4_cli_test_exit_' . uniqid();
        mkdir($this->tmpDir . '/tests', 0755, true);
        $this->binPath = realpath(__DIR__ . '/../bin/tina4php');
        $this->assertNotFalse($this->binPath, 'bin/tina4php not found');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
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
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Write the temp project's tests/test_v3_smoke.php with the given body.
     */
    private function writeSmokeTest(string $body): void
    {
        file_put_contents($this->tmpDir . '/tests/test_v3_smoke.php', "<?php\n" . $body . "\n");
    }

    /**
     * Spawn the real `bin/tina4php test` with cwd = the temp project and
     * return [exitCode, combinedOutput]. Uses proc_open so we read the true
     * child exit code (proc_close's return value).
     */
    private function runCli(): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = [PHP_BINARY, $this->binPath, 'test'];
        $proc = proc_open($cmd, $descriptors, $pipes, $this->tmpDir);
        $this->assertIsResource($proc, 'failed to spawn CLI');

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        return [$exitCode, $stdout . $stderr];
    }

    /**
     * NEGATIVE case — a failing smoke test must propagate a non-zero code.
     * This is the case that fails against the pre-fix CLI (which exited 0).
     * We use exit(3) to prove the ACTUAL code is propagated, not just "some
     * non-zero".
     */
    public function testCliPropagatesNonZeroWhenTestsFail(): void
    {
        $this->writeSmokeTest('echo "smoke: FAIL\n"; exit(3);');

        [$exitCode, $output] = $this->runCli();

        $this->assertSame(3, $exitCode, 'CLI must propagate the runner exit code on failure; output was: ' . $output);
    }

    /**
     * POSITIVE case — a passing smoke test must exit 0.
     */
    public function testCliExitsZeroWhenTestsPass(): void
    {
        $this->writeSmokeTest('echo "smoke: OK\n"; exit(0);');

        [$exitCode, $output] = $this->runCli();

        $this->assertSame(0, $exitCode, 'CLI must exit 0 when the runner passes; output was: ' . $output);
    }

    /**
     * A missing test runner is itself a failure: a CI gate must exit non-zero
     * when it cannot run the tests, never silently pass.
     */
    public function testCliExitsNonZeroWhenNoRunnerFound(): void
    {
        // Temp project has no tests/test_v3_smoke.php and no vendor/bin/phpunit.
        [$exitCode, $output] = $this->runCli();

        $this->assertNotSame(0, $exitCode, 'CLI must exit non-zero when no test runner is found; output was: ' . $output);
        $this->assertStringContainsString('No test runner found', $output);
    }
}
