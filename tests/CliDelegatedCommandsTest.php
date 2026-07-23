<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;

/**
 * Pins the contract of the client-owned commands the CLI reaches by DELEGATION.
 *
 * `doctor`, `setup` and `deploy` are owned by the Rust `tina4` client. This CLI
 * recognises them (the closed $TINA4_DELEGATED registry) and runs the client with
 * the same argv, propagating its exit code — so `tina4php doctor` behaves exactly
 * like `tina4 doctor` without cloning the client into four languages.
 *
 * NO MOCKS. Every test spawns the REAL bin/tina4php as a child process. The
 * positive tests put a REAL executable named `tina4` on a real temp PATH and
 * assert the CLI actually ran it with the exact argv and propagated its exit
 * status — real process, real PATH resolution, real exit status. The negative
 * tests use a real PATH with no `tina4` on it at all.
 */
class CliDelegatedCommandsTest extends TestCase
{
    private string $tmpDir;
    private string $binPath;
    private string $phpBinary;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/tina4_cli_delegated_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->binPath = (string)realpath(__DIR__ . '/../bin/tina4php');
        $this->assertNotEmpty($this->binPath, 'bin/tina4php not found');
        $this->phpBinary = PHP_BINARY;
    }

    protected function tearDown(): void
    {
        foreach (['clientbin/tina4', 'argv.txt', 'guard.txt'] as $file) {
            @unlink($this->tmpDir . '/' . $file);
        }
        @rmdir($this->tmpDir . '/clientbin');
        @rmdir($this->tmpDir . '/nobin');
        @rmdir($this->tmpDir);
    }

    /**
     * Run the REAL bin/tina4php as a child with a controlled PATH.
     *
     * @param array<int, string> $argv
     * @param array<string, string> $extraEnv
     * @return array{0: int, 1: string} [exit code, combined output]
     */
    private function runCli(array $argv, string $path, array $extraEnv = []): array
    {
        $env = getenv();
        unset($env['TINA4_CLI_DELEGATED']);
        $env['PATH'] = $path;
        $env = array_merge($env, $extraEnv);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            array_merge([$this->phpBinary, $this->binPath], $argv),
            $descriptors,
            $pipes,
            $this->tmpDir,
            $env
        );
        $this->assertIsResource($process, 'could not spawn bin/tina4php');
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }

    /** A real PATH directory that genuinely has NO `tina4` executable on it. */
    private function pathWithoutClient(): string
    {
        $dir = $this->tmpDir . '/nobin';
        @mkdir($dir, 0755, true);
        $this->assertFileDoesNotExist($dir . '/tina4');
        return $dir;
    }

    /**
     * Install a REAL executable named `tina4` on a fresh temp PATH.
     *
     * It is a genuine program (not a test double standing in for one): a small
     * shell script that records the argv and guard variable it was invoked with,
     * then exits with $exitCode. That is exactly the collaborator the delegation
     * code has — "whatever executable named tina4 is first on PATH" — so the test
     * exercises the real PATH lookup, real spawn and real exit-status
     * propagation, with no in-process substitution anywhere.
     */
    private function pathWithRealClient(int $exitCode = 0): string
    {
        $dir = $this->tmpDir . '/clientbin';
        @mkdir($dir, 0755, true);
        $client = $dir . '/tina4';
        $argvFile = $this->tmpDir . '/argv.txt';
        $guardFile = $this->tmpDir . '/guard.txt';
        file_put_contents($client, <<<SH
        #!/bin/sh
        for arg in "\$@"; do printf "%s\\n" "\$arg" >> "{$argvFile}"; done
        printf "%s\\n" "\$TINA4_CLI_DELEGATED" > "{$guardFile}"
        echo "REAL-CLIENT-RAN \$*"
        exit {$exitCode}
        SH);
        chmod($client, 0755);
        return $dir;
    }

    /** @return array<int, string> */
    private function recordedArgv(): array
    {
        $raw = (string)@file_get_contents($this->tmpDir . '/argv.txt');
        return $raw === '' ? [] : explode("\n", rtrim($raw, "\n"));
    }

    private function recordedGuard(): string
    {
        return trim((string)@file_get_contents($this->tmpDir . '/guard.txt'));
    }

    private function clientWasInvoked(): bool
    {
        return file_exists($this->tmpDir . '/guard.txt');
    }

    // ── The registry itself ──────────────────────────────────────────────

    public function testTheThreeClientCommandsAreAdvertisedInHelp(): void
    {
        [$code, $output] = $this->runCli(['help'], $this->pathWithoutClient());

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Delegated to the tina4 client', $output);
        foreach (['doctor', 'setup', 'deploy'] as $name) {
            $this->assertStringContainsString($name, $output, "help omits {$name}");
        }
    }

    public function testManifestListsDelegatedCommandsFlagged(): void
    {
        [$code, $output] = $this->runCli(['commands', '--json'], $this->pathWithoutClient());

        $this->assertSame(0, $code, $output);
        $manifest = json_decode($output, true);
        $this->assertIsArray($manifest, "commands --json was not valid JSON:\n{$output}");
        $byName = [];
        foreach ($manifest['commands'] as $command) {
            $byName[$command['name']] = $command;
        }
        foreach (['doctor', 'setup', 'deploy'] as $name) {
            $this->assertArrayHasKey($name, $byName, "manifest omits {$name}");
            $this->assertTrue($byName[$name]['delegated'] ?? false, "{$name} not flagged delegated");
        }
        // A native command must never claim to be delegated.
        $this->assertArrayNotHasKey('delegated', $byName['migrate']);
        $this->assertArrayNotHasKey('delegated', $byName['generate']);
    }

    // ── Positive: delegation really reaches the client ───────────────────

    public function testDoctorRunsTheClientWithTheSameArgv(): void
    {
        [$code, $output] = $this->runCli(['doctor'], $this->pathWithRealClient(0));

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('REAL-CLIENT-RAN doctor', $output);
        $this->assertSame(['doctor'], $this->recordedArgv());
    }

    public function testDeployPassesItsArgumentsAndFlagsThrough(): void
    {
        [$code] = $this->runCli(['deploy', 'docker', '--force'], $this->pathWithRealClient(0));

        $this->assertSame(0, $code);
        $this->assertSame(['deploy', 'docker', '--force'], $this->recordedArgv());
    }

    public function testClientExitCodeIsPropagatedNotSwallowed(): void
    {
        [$code] = $this->runCli(['doctor'], $this->pathWithRealClient(3));

        $this->assertSame(3, $code, 'the client exit code must be propagated verbatim');
    }

    public function testLoopGuardIsSetOnTheChild(): void
    {
        $this->runCli(['setup'], $this->pathWithRealClient(0));

        $this->assertSame('setup', $this->recordedGuard());
    }

    // ── Negative: every failure path is loud, actionable and non-zero ────

    public function testMissingClientNamesTheCommandAndHowToInstall(): void
    {
        [$code, $output] = $this->runCli(['doctor'], $this->pathWithoutClient());

        $this->assertSame(127, $code);
        $this->assertStringContainsString('doctor', $output);
        $this->assertStringContainsString('tina4 client', $output);
        $this->assertStringContainsString('install.sh', $output, 'no actionable install hint');
        $this->assertStringNotContainsString('Fatal error', $output);
    }

    public function testLoopGuardRefusesToRespawn(): void
    {
        [$code, $output] = $this->runCli(
            ['doctor'],
            $this->pathWithRealClient(0),
            ['TINA4_CLI_DELEGATED' => 'doctor']
        );

        $this->assertSame(127, $code);
        $this->assertStringContainsString('Refusing to delegate', $output);
        $this->assertFalse($this->clientWasInvoked(), 'it spawned the client anyway');
    }

    public function testUnknownCommandExitsNonZero(): void
    {
        [$code, $output] = $this->runCli(['definitely-not-a-command'], $this->pathWithoutClient());

        $this->assertSame(1, $code, 'an unknown command used to exit 0, hiding typos in CI');
        $this->assertStringContainsString('Unknown command: definitely-not-a-command', $output);
    }

    public function testUnknownCommandIsNotDelegated(): void
    {
        [$code] = $this->runCli(['not-a-real-command'], $this->pathWithRealClient(0));

        $this->assertSame(1, $code);
        $this->assertFalse($this->clientWasInvoked(), 'forwarded an unknown command to the client');
    }
}
