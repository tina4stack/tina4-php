<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
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
    private function runCli(string $command = 'migrate'): array
    {
        $env = ['DATABASE_URL' => 'sqlite:///' . $this->dbPath];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = [PHP_BINARY, $this->binPath, $command];
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

    /**
     * Run the CLI inside a project whose index.php boots the App, the shape
     * `tina4php init` writes, with TINA4_DATABASE_URL in the environment.
     * Booting installs App's uncaught-exception handler, so this is the path
     * a failed connection takes in a real project. Returns [exitCode, output].
     */
    private function runCliInBootedProject(string $databaseUrl, array $extraEnv = []): array
    {
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        file_put_contents(
            $this->tmpDir . '/index.php',
            "<?php\nrequire_once " . var_export($autoload, true) . ";\n"
            . "\$app = new \\Tina4\\App(basePath: __DIR__);\n\$app->handle();\n"
        );
        $env = array_merge([
            'TINA4_DATABASE_URL' => $databaseUrl,
            'TINA4_DEBUG' => 'false',
            'TINA4_SECRET' => str_repeat('s', 40),
            'TINA4_LOG_LEVEL' => 'ERROR',
        ], $extraEnv);
        foreach (['SystemRoot', 'PATH', 'TEMP', 'TMP'] as $key) {
            if (getenv($key) !== false && !isset($env[$key])) {
                $env[$key] = getenv($key);
            }
        }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([PHP_BINARY, $this->binPath, 'migrate'], $descriptors, $pipes, $this->tmpDir, $env);
        $this->assertIsResource($proc, 'failed to spawn CLI');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $output];
    }

    /** A database file that cannot be created: its directory does not exist. */
    private function unopenableSqliteUrl(): string
    {
        return 'sqlite:///' . $this->tmpDir . '/no-such-dir/app.db';
    }

    public function testBootedProjectExitsNonZeroWhenTheDatabaseCannotBeOpened(): void
    {
        file_put_contents(
            $this->tmpDir . '/migrations/20240101000000_create_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)'
        );

        [$exitCode, $output] = $this->runCliInBootedProject($this->unopenableSqliteUrl());

        $this->assertNotSame(0, $exitCode, 'migrate must fail when the database cannot be opened; output was: ' . $output);
        $this->assertStringNotContainsString('Applied', $output);
    }

    public function testBootedProjectExitsNonZeroWhenTheUrlIsUnusableAndAutoMigrateIsOff(): void
    {
        // With the startup auto-migrator off, the failure comes from the
        // CLI's own App::getDatabase() call instead of from boot -- the same
        // handler swallowed it there too.
        [$exitCode, $output] = $this->runCliInBootedProject(
            'nosuchengine://user:pass@127.0.0.1/app',
            ['TINA4_AUTO_MIGRATE' => 'false']
        );

        $this->assertNotSame(0, $exitCode, 'migrate must fail on an unusable database URL; output was: ' . $output);
    }

    public function testBootedProjectExitsNonZeroInDevelopmentMode(): void
    {
        // Development mode chains the dev-admin error tracker in front of
        // App's handler; the exit status must survive that chain too.
        [$exitCode, $output] = $this->runCliInBootedProject(
            $this->unopenableSqliteUrl(),
            ['TINA4_DEBUG' => 'true']
        );

        $this->assertNotSame(0, $exitCode, 'migrate must fail when the database cannot be opened; output was: ' . $output);
    }

    public function testBootedProjectExitsZeroWhenTheDatabaseWorks(): void
    {
        file_put_contents(
            $this->tmpDir . '/migrations/20240101000000_create_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)'
        );

        [$exitCode, $output] = $this->runCliInBootedProject('sqlite:///' . $this->dbPath);

        $this->assertSame(0, $exitCode, 'migrate must exit 0 when the database works; output was: ' . $output);
        $this->assertFileExists($this->dbPath);
    }

    public function testRollbackExitsNonZeroWhenADownMigrationErrors(): void
    {
        file_put_contents(
            $this->tmpDir . '/migrations/20240101000000_create_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)'
        );
        file_put_contents(
            $this->tmpDir . '/migrations/20240101000000_create_users.down.sql',
            'DROP TABLE no_such_table'
        );
        [$applied, $output] = $this->runCli();
        $this->assertSame(0, $applied, 'setup: migrate must succeed; output was: ' . $output);

        [$exitCode, $output] = $this->runCli('migrate:rollback');

        $this->assertNotSame(0, $exitCode, 'migrate:rollback must exit non-zero when a rollback fails; output was: ' . $output);
        $this->assertStringContainsString('Error rolling back', $output);
    }

    public function testRollbackExitsZeroWhenTheRollbackSucceeds(): void
    {
        file_put_contents(
            $this->tmpDir . '/migrations/20240101000000_create_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)'
        );
        file_put_contents(
            $this->tmpDir . '/migrations/20240101000000_create_users.down.sql',
            'DROP TABLE users'
        );
        [$applied, $output] = $this->runCli();
        $this->assertSame(0, $applied, 'setup: migrate must succeed; output was: ' . $output);

        [$exitCode, $output] = $this->runCli('migrate:rollback');

        $this->assertSame(0, $exitCode, 'migrate:rollback must exit 0 on success; output was: ' . $output);
        $this->assertStringContainsString('Rolled back', $output);
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
