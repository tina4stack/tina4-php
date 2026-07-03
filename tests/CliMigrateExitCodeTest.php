<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;

/**
 * Pins the exit-code contract of `tina4php migrate`.
 *
 * Without these tests, the CLI would happily print "Errors:" then exit 0,
 * letting CI/CD pipelines deploy code whose migrations didn't actually
 * apply. The earlier (3.11.x — 3.12.13) handler did exactly that.
 *
 * The Migration class itself is covered in MigrationV3Test; this file
 * specifically guards the CLI wrapper's exit-code mapping.
 */
class CliMigrateExitCodeTest extends TestCase
{
    private string $tmpDir;
    private string $dbPath;
    private string $binPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/tina4_cli_migrate_test_' . uniqid();
        mkdir($this->tmpDir . '/migrations', 0755, true);
        $this->dbPath = $this->tmpDir . '/test.db';
        $this->binPath = realpath(__DIR__ . '/../bin/tina4php');
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
     * Run the CLI in the temp dir with DATABASE_URL pointed at our SQLite
     * file, and return [exitCode, stdout]. Captures stderr too so failing
     * runs surface useful detail.
     */
    private function runCli(): array
    {
        $env = ['DATABASE_URL' => 'sqlite:///' . $this->dbPath];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = [PHP_BINARY, $this->binPath, 'migrate'];
        $proc = proc_open($cmd, $descriptors, $pipes, $this->tmpDir, $env);
        $this->assertIsResource($proc, 'failed to spawn CLI');

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        return [$exitCode, $stdout . $stderr];
    }

    public function testCliExitsNonZeroWhenAMigrationErrors(): void
    {
        file_put_contents(
            $this->tmpDir . '/migrations/20240101000000_bad.sql',
            'THIS IS NOT VALID SQL'
        );

        [$exitCode, $output] = $this->runCli();

        $this->assertNotSame(0, $exitCode, 'CLI must exit non-zero on migration error; output was: ' . $output);
        $this->assertStringContainsString('20240101000000_bad.sql', $output);
    }

    public function testCliExitsZeroWhenMigrationsSucceed(): void
    {
        file_put_contents(
            $this->tmpDir . '/migrations/20240101000000_create_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)'
        );

        [$exitCode, $output] = $this->runCli();

        $this->assertSame(0, $exitCode, 'CLI must exit 0 on success; output was: ' . $output);
        $this->assertStringContainsString('Applied 1 migration', $output);
    }

    public function testCliExitsZeroWhenNoPendingMigrations(): void
    {
        // No migration files in the directory.
        [$exitCode, $output] = $this->runCli();

        $this->assertSame(0, $exitCode, 'CLI must exit 0 when nothing is pending; output was: ' . $output);
        $this->assertStringContainsString('No pending migrations', $output);
    }

    public function testCliPartialFailureExitsNonZero(): void
    {
        // First migration OK; second is broken. The earlier handler would
        // print "Applied 1 migration(s):" and "Errors:" then still exit 0.
        file_put_contents(
            $this->tmpDir . '/migrations/20240101000000_create_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)'
        );
        file_put_contents(
            $this->tmpDir . '/migrations/20240102000000_bad.sql',
            'INVALID SQL STATEMENT'
        );

        [$exitCode, $output] = $this->runCli();

        $this->assertNotSame(0, $exitCode, 'CLI must exit non-zero when ANY migration errors, even if some succeeded; output was: ' . $output);
        $this->assertStringContainsString('Applied 1 migration', $output);
        $this->assertStringContainsString('20240102000000_bad.sql', $output);
    }
}
