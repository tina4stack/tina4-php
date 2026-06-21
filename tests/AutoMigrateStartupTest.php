<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\App;
use Tina4\DotEnv;
use Tina4\Database\SQLite3Adapter;

/**
 * Startup auto-migration (TINA4_AUTO_MIGRATE) — mirrors the Python master
 * (_auto_migrate_on_startup) wired into App::start() via autoMigrateOnStartup().
 *
 * Covers: applies-on-startup, TINA4_AUTO_MIGRATE=false skips, no-folder no-op,
 * and failure-is-non-breaking (a bad migration does not throw out of the helper).
 */
class AutoMigrateStartupTest extends TestCase
{
    private string $tempDir;
    private SQLite3Adapter $db;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tina4_automigrate_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->db = new SQLite3Adapter(':memory:');

        // Reset App static state so each case starts clean.
        $this->setAppStatic('autoMigrated', false);
        $this->setAppStatic('database', null);
        DotEnv::resetEnv();
        putenv('TINA4_AUTO_MIGRATE');
        unset($_ENV['TINA4_AUTO_MIGRATE']);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        $this->removeDir($this->tempDir);
        $this->setAppStatic('autoMigrated', false);
        $this->setAppStatic('database', null);
        DotEnv::resetEnv();
        putenv('TINA4_AUTO_MIGRATE');
        unset($_ENV['TINA4_AUTO_MIGRATE']);
    }

    // --- helpers --------------------------------------------------------

    private function setAppStatic(string $name, mixed $value): void
    {
        // setAccessible() is a no-op on PHP 8.1+ (deprecated on 8.5).
        $prop = new \ReflectionProperty(App::class, $name);
        $prop->setValue(null, $value);
    }

    /** Invoke the private autoMigrateOnStartup() on a freshly-constructed App. */
    private function invokeAutoMigrate(): void
    {
        $app = new App(basePath: $this->tempDir);
        $method = new \ReflectionMethod(App::class, 'autoMigrateOnStartup');
        $method->invoke($app);
        // App construction may install error handlers via Log setup — restore so
        // PHPUnit doesn't flag the test as risky for leaking handlers.
        @restore_error_handler();
        @restore_exception_handler();
    }

    private function writeMigration(string $name, string $sql): void
    {
        $dir = $this->tempDir . '/migrations';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/' . $name, $sql);
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

    // --- tests ----------------------------------------------------------

    public function testAppliesPendingMigrationOnStartup(): void
    {
        $this->writeMigration(
            '000001_create_widgets.sql',
            'CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT);'
        );

        App::setDatabase($this->db);
        // After setDatabase the guard may have been touched by nothing yet —
        // ensure it's unset so the helper actually runs.
        $this->setAppStatic('autoMigrated', false);

        $this->invokeAutoMigrate();

        $this->assertTrue(
            $this->db->tableExists('widgets'),
            'Startup auto-migration should have created the widgets table'
        );
    }

    public function testDisabledWhenAutoMigrateFalse(): void
    {
        $this->writeMigration(
            '000001_create_widgets.sql',
            'CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT);'
        );

        App::setDatabase($this->db);
        $this->setAppStatic('autoMigrated', false);
        putenv('TINA4_AUTO_MIGRATE=false');
        $_ENV['TINA4_AUTO_MIGRATE'] = 'false';

        $this->invokeAutoMigrate();

        $this->assertFalse(
            $this->db->tableExists('widgets'),
            'TINA4_AUTO_MIGRATE=false must skip startup migrations'
        );
    }

    public function testDisabledWhenAutoMigrateOff(): void
    {
        $this->writeMigration(
            '000001_create_widgets.sql',
            'CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT);'
        );

        App::setDatabase($this->db);
        $this->setAppStatic('autoMigrated', false);
        putenv('TINA4_AUTO_MIGRATE=off');
        $_ENV['TINA4_AUTO_MIGRATE'] = 'off';

        $this->invokeAutoMigrate();

        $this->assertFalse(
            $this->db->tableExists('widgets'),
            'TINA4_AUTO_MIGRATE=off must skip startup migrations'
        );
    }

    public function testNoMigrationsFolderIsNoOp(): void
    {
        // No migrations/ folder created at all.
        App::setDatabase($this->db);
        $this->setAppStatic('autoMigrated', false);

        // Must not throw.
        $this->invokeAutoMigrate();

        $this->assertFalse($this->db->tableExists('widgets'));
        $this->addToAssertionCount(1);
    }

    public function testEmptyMigrationsFolderIsNoOp(): void
    {
        // Folder exists but has no .sql files.
        mkdir($this->tempDir . '/migrations', 0755, true);
        file_put_contents($this->tempDir . '/migrations/README.md', '# not a migration');

        App::setDatabase($this->db);
        $this->setAppStatic('autoMigrated', false);

        $this->invokeAutoMigrate();

        $this->assertFalse($this->db->tableExists('widgets'));
        $this->addToAssertionCount(1);
    }

    public function testFailureIsNonBreaking(): void
    {
        // A syntactically broken migration must NOT throw out of the helper —
        // the service still boots.
        $this->writeMigration(
            '000001_broken.sql',
            'CREATE TABLE ( this is not valid sql ;'
        );

        App::setDatabase($this->db);
        $this->setAppStatic('autoMigrated', false);

        try {
            $this->invokeAutoMigrate();
        } catch (\Throwable $e) {
            $this->fail(
                'Startup auto-migration must swallow failures (non-breaking), '
                . 'but it threw: ' . $e->getMessage()
            );
        }

        $this->assertTrue(true, 'A failing startup migration did not abort boot');
    }

    public function testRunsAtMostOncePerProcess(): void
    {
        $this->writeMigration(
            '000001_create_widgets.sql',
            'CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT);'
        );

        App::setDatabase($this->db);
        $this->setAppStatic('autoMigrated', false);

        $this->invokeAutoMigrate();
        $this->assertTrue($this->db->tableExists('widgets'));

        // Second invocation is guarded — it must be a no-op (no re-run, no throw
        // from re-creating the table).
        $this->invokeAutoMigrate();
        $this->assertTrue($this->db->tableExists('widgets'));
    }
}
