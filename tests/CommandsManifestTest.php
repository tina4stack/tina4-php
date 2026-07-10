<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Real tests for the self-describing `commands` CLI subcommand (Phase 1 of the
 * self-describing-client epic — the PHP mirror of the Python master).
 *
 * NO mocks. These exercise the ACTUAL CLI by shelling out to the real
 * `bin/tina4php` entrypoint (exactly how the tina4 Rust client drives it) and
 * parsing what it prints:
 *
 *   * content + shape of `commands --json` (framework/version/commands, args,
 *     generate.subcommands);
 *   * anti-drift lock-in — the manifest lists EXACTLY the commands the top-level
 *     dispatch switch handles (the manifest is derived from the same
 *     $TINA4_COMMANDS registry that gates dispatch, so they cannot diverge);
 *   * app/DB-free — `commands --json` runs in an empty temp dir with NO database
 *     configured, exits 0 with valid JSON, and creates no files / no database.
 *     This is the contract the Rust client relies on when it calls the command
 *     on every `tina4 --help`, in any directory.
 */

use PHPUnit\Framework\TestCase;

class CommandsManifestTest extends TestCase
{
    /** The command set the tina4 client must be able to discover truthfully. */
    private const KNOWN_COMMANDS = [
        'migrate', 'migrate:create', 'seed', 'test', 'routes', 'generate', 'commands',
    ];

    private static string $bin;

    public static function setUpBeforeClass(): void
    {
        self::$bin = realpath(__DIR__ . '/../bin/tina4php');
        self::assertNotFalse(self::$bin, 'bin/tina4php not found');
    }

    /**
     * Run the REAL CLI entrypoint as a subprocess and return [stdout, stderr, exit].
     * $cwd/$envOverride let a test prove the app/DB-free contract in a clean dir.
     */
    private function runCli(array $args, ?string $cwd = null, array $envOverride = []): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::$bin);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $env = $envOverride['__reset__'] ?? false ? [] : getenv();
        unset($env['__reset__']);
        foreach ($envOverride as $key => $value) {
            if ($key === '__reset__') {
                continue;
            }
            if ($value === null) {
                unset($env[$key]);
            } else {
                $env[$key] = $value;
            }
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, $cwd, $env);
        self::assertIsResource($process, 'failed to start bin/tina4php');

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$stdout, $stderr, $exit];
    }

    /** The manifest emitted by the real `commands --json` subprocess. */
    private function manifest(): array
    {
        [$stdout, $stderr, $exit] = $this->runCli(['commands', '--json']);
        $this->assertSame(0, $exit, "commands --json exited non-zero; stderr:\n{$stderr}");
        $manifest = json_decode($stdout, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), "commands --json is not valid JSON:\n{$stdout}");
        return $manifest;
    }

    // ── Content ──────────────────────────────────────────────────────────

    public function testFrameworkIsPhp(): void
    {
        $this->assertSame('php', $this->manifest()['framework']);
    }

    public function testVersionIsNonEmptyAndVersionish(): void
    {
        $version = $this->manifest()['version'];
        $this->assertIsString($version);
        $this->assertNotSame('', trim($version));
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $version, "not version-looking: {$version}");
    }

    public function testCommandsAreNonEmptyAndWellFormed(): void
    {
        $commands = $this->manifest()['commands'];
        $this->assertIsArray($commands);
        $this->assertNotEmpty($commands);
        foreach ($commands as $entry) {
            $this->assertArrayHasKey('name', $entry);
            $this->assertNotSame('', (string)$entry['name'], 'entry missing name: ' . json_encode($entry));
            $this->assertArrayHasKey('summary', $entry);
            $this->assertNotSame('', (string)$entry['summary'], 'entry missing summary: ' . json_encode($entry));
        }
    }

    public function testKnownCommandsAllPresent(): void
    {
        $names = array_column($this->manifest()['commands'], 'name');
        $missing = array_diff(self::KNOWN_COMMANDS, $names);
        $this->assertSame([], array_values($missing), 'manifest missing commands: ' . implode(', ', $missing));
    }

    public function testGenerateHasSubcommandsWithModelAndCrud(): void
    {
        $commands = $this->manifest()['commands'];
        $generate = null;
        foreach ($commands as $entry) {
            if ($entry['name'] === 'generate') {
                $generate = $entry;
                break;
            }
        }
        $this->assertNotNull($generate, 'no generate command in manifest');
        $this->assertArrayHasKey('subcommands', $generate);
        $this->assertIsArray($generate['subcommands']);
        $this->assertNotEmpty($generate['subcommands']);
        $this->assertContains('model', $generate['subcommands']);
        $this->assertContains('crud', $generate['subcommands']);
    }

    public function testMigrateCreateDeclaresItsPositionalArg(): void
    {
        $commands = $this->manifest()['commands'];
        $entry = null;
        foreach ($commands as $c) {
            if ($c['name'] === 'migrate:create') {
                $entry = $c;
                break;
            }
        }
        $this->assertNotNull($entry);
        $this->assertSame(['description'], $entry['args'] ?? null);
    }

    public function testNoInventedTopLevelQueueCommand(): void
    {
        // `queue` exists only as a `generate` subcommand today — it must NOT
        // masquerade as a top-level command (parity with the Python master).
        $manifest = $this->manifest();
        $names = array_column($manifest['commands'], 'name');
        $this->assertNotContains('queue', $names);

        $generate = null;
        foreach ($manifest['commands'] as $c) {
            if ($c['name'] === 'generate') {
                $generate = $c;
                break;
            }
        }
        $this->assertContains('queue', $generate['subcommands'] ?? []);
    }

    // ── Anti-drift lock-in ───────────────────────────────────────────────

    public function testManifestListsExactlyTheDispatchableCommands(): void
    {
        // The manifest is derived from the $TINA4_COMMANDS registry, which also
        // GATES dispatch. This lock-in proves the top-level dispatch switch
        // handles EXACTLY the commands the manifest advertises — add a `case`
        // without registering it (or vice versa) and this test fails.
        $source = file_get_contents(self::$bin);

        // Top-level switch cases are indented exactly 4 spaces; the nested
        // `generate` switch cases are indented 12, so they are excluded here.
        preg_match_all("/^    case '([^']+)':/m", $source, $matches);
        $dispatchable = $matches[1];

        // Flag-style aliases are normalised to a real command before dispatch.
        $dispatchable = array_values(array_diff($dispatchable, ['--help', '-h']));

        $manifestNames = array_column($this->manifest()['commands'], 'name');

        sort($dispatchable);
        sort($manifestNames);
        $this->assertSame(
            $manifestNames,
            $dispatchable,
            'manifest command names drifted from the dispatch switch cases'
        );
    }

    public function testUnknownCommandIsRejectedViaTheRegistry(): void
    {
        // A command absent from the registry is unknown — proves the registry
        // (not a parallel list) is the gate for what the CLI will dispatch.
        [$stdout, , $exit] = $this->runCli(['definitely-not-a-real-command']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Unknown command: definitely-not-a-real-command', $stdout);
    }

    // ── App/DB-free contract ─────────────────────────────────────────────

    public function testCommandsJsonIsAppAndDbFree(): void
    {
        $tempDir = sys_get_temp_dir() . '/tina4-cmdmanifest-' . getmypid() . '-' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            $this->assertDirectoryDoesNotExist($tempDir . '/migrations');
            $this->assertFileDoesNotExist($tempDir . '/app.php');

            // Genuinely fresh: run in the empty dir with NO database configured.
            [$stdout, $stderr, $exit] = $this->runCli(
                ['commands', '--json'],
                $tempDir,
                ['TINA4_DATABASE_URL' => null, 'TINA4_AUTO_MIGRATE' => null]
            );

            $this->assertSame(0, $exit, "non-zero exit; stderr:\n{$stderr}");

            $manifest = json_decode($stdout, true);
            $this->assertSame(JSON_ERROR_NONE, json_last_error(), "not valid JSON:\n{$stdout}");
            $this->assertSame('php', $manifest['framework']);
            $this->assertNotSame('', trim((string)$manifest['version']));
            $names = array_column($manifest['commands'], 'name');
            $this->assertContains('commands', $names);
            $this->assertContains('generate', $names);

            // Nothing bootstrapped: no database file, nothing written to the dir.
            $dbFiles = glob($tempDir . '/**/*.db') ?: [];
            $dbFiles = array_merge($dbFiles, glob($tempDir . '/*.db') ?: []);
            $this->assertSame([], $dbFiles, 'commands opened/created a database: ' . implode(', ', $dbFiles));

            $entries = array_values(array_diff(scandir($tempDir), ['.', '..']));
            $this->assertSame([], $entries, 'commands created files in the project dir: ' . implode(', ', $entries));
        } finally {
            @rmdir($tempDir);
        }
    }
}
