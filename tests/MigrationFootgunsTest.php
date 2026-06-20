<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Lock-in tests for the migration footgun fixes (v3.13.39) — mirrors
 * tina4-python tests/test_migration_footguns.py.
 *
 * [10] `//` delimiter no longer swallows a URL (`https://…`) as a stored-proc block.
 * [8]  discovery sort is numeric-aware (`9_` before `10_`).
 * [9]  CREATE TABLE is idempotent on engines lacking IF NOT EXISTS (Firebird/MSSQL),
 *      NOT on SQLite, and NOT when the table is absent.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;
use Tina4\Migration;

/**
 * A fake MSSQL adapter that does NOT connect (the real ctor requires ext-sqlsrv).
 * It is `instanceof \Tina4\Database\MSSQLAdapter`, which is all the CREATE-TABLE
 * idempotency guard checks, and lets us control tableExists().
 */
class FakeMSSQLAdapter extends \Tina4\Database\MSSQLAdapter
{
    public function __construct(private bool $exists)
    {
        // Intentionally do NOT call parent::__construct() — no real connection.
    }

    // The Migration ctor checks for the tracking table; report it present so
    // ensureMigrationsTable() takes the no-op path (no real connection needed).
    public function tableExists(string $tableName): bool
    {
        if ($tableName === 'tina4_migration') {
            return true;
        }
        return $this->exists;
    }

    public function getColumns(string $tableName): array
    {
        return [['name' => 'migration'], ['name' => 'batch'], ['name' => 'applied_at']];
    }
}

/** Fake Firebird adapter — same trick. */
class FakeFirebirdAdapter extends \Tina4\Database\FirebirdAdapter
{
    public function __construct(private bool $exists)
    {
    }

    public function tableExists(string $tableName): bool
    {
        if ($tableName === 'tina4_migration') {
            return true;
        }
        return $this->exists;
    }

    public function getColumns(string $tableName): array
    {
        return [['name' => 'migration'], ['name' => 'batch'], ['name' => 'applied_at']];
    }
}

class MigrationFootgunsTest extends TestCase
{
    private string $migrationsDir;

    protected function setUp(): void
    {
        $this->migrationsDir = sys_get_temp_dir() . '/tina4_mig_footguns_' . uniqid();
        mkdir($this->migrationsDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->migrationsDir);
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

    private function newMigration(\Tina4\Database\DatabaseAdapter $db): Migration
    {
        return new Migration($db, $this->migrationsDir);
    }

    private function invoke(Migration $m, string $method, array $args)
    {
        $ref = new \ReflectionMethod(Migration::class, $method);
        return $ref->invokeArgs($m, $args);
    }

    private static function staticInvoke(string $method, array $args)
    {
        $ref = new \ReflectionMethod(Migration::class, $method);
        return $ref->invokeArgs(null, $args);
    }

    // ── [10] `//` delimiter must not swallow a URL ──────────────────────

    public function testSplitDoesNotSwallowUrlScheme(): void
    {
        $m = $this->newMigration(new SQLite3Adapter(':memory:'));
        $sql = "INSERT INTO cfg (k, v) VALUES ('home', 'https://a.example.com');\n"
            . "INSERT INTO cfg (k, v) VALUES ('cb', 'https://b.example.com');";

        $stmts = $this->invoke($m, 'splitStatements', [$sql]);

        $this->assertCount(2, $stmts, 'URL `//` was captured as a block, breaking split');
        $this->assertStringContainsString('https://a.example.com', $stmts[0]);
        $this->assertStringContainsString('https://b.example.com', $stmts[1]);
    }

    public function testSplitStillHandlesRealStoredProcBlock(): void
    {
        $m = $this->newMigration(new SQLite3Adapter(':memory:'));
        // A genuine `// ... //` stored-proc block (delimiters not preceded by `:`)
        // is still kept intact as one statement.
        $sql = "CREATE PROCEDURE foo() // BEGIN SELECT 1; SELECT 2; END //;";

        $stmts = $this->invoke($m, 'splitStatements', [$sql]);

        $found = false;
        foreach ($stmts as $s) {
            if (str_contains($s, 'BEGIN SELECT 1; SELECT 2; END')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'real // stored-proc block was split incorrectly');
    }

    // ── [8] numeric-aware discovery order ───────────────────────────────

    public function testGetMigrationFilesIsNumericAware(): void
    {
        foreach (['10_b.sql', '9_a.sql', '2_x.sql', 'alpha.sql'] as $name) {
            file_put_contents($this->migrationsDir . '/' . $name, '-- ' . $name);
        }
        $m = $this->newMigration(new SQLite3Adapter(':memory:'));

        $names = array_map('basename', $m->getMigrationFiles());

        $this->assertSame(['2_x.sql', '9_a.sql', '10_b.sql', 'alpha.sql'], $names);
    }

    public function testSortKeyIsNumericAware(): void
    {
        $names = ['10_b.sql', '9_a.sql', '2_x.sql', 'alpha.sql'];
        usort($names, function (string $a, string $b): int {
            return self::staticInvoke('migrationSortKey', [$a])
                <=> self::staticInvoke('migrationSortKey', [$b]);
        });
        $this->assertSame(['2_x.sql', '9_a.sql', '10_b.sql', 'alpha.sql'], $names);
    }

    // ── [9] CREATE TABLE idempotency on Firebird/MSSQL ──────────────────

    public function testCreateTableSkippedOnMssqlWhenTableExists(): void
    {
        $m = $this->newMigration(new FakeMSSQLAdapter(true));
        $reason = $this->invoke($m, 'shouldSkipCreateTable', ['CREATE TABLE users (id INT)']);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('users', $reason);
    }

    public function testCreateTableSkippedOnFirebirdQuotedWhenExists(): void
    {
        $m = $this->newMigration(new FakeFirebirdAdapter(true));
        $reason = $this->invoke($m, 'shouldSkipCreateTable', ['CREATE TABLE "Orders" (id INT)']);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('Orders', $reason);
    }

    public function testCreateTableSkippedOnMssqlBracketedWhenExists(): void
    {
        $m = $this->newMigration(new FakeMSSQLAdapter(true));
        $reason = $this->invoke($m, 'shouldSkipCreateTable', ['CREATE TABLE [Things] (id INT)']);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('Things', $reason);
    }

    public function testCreateTableNotSkippedWhenAbsent(): void
    {
        $m = $this->newMigration(new FakeFirebirdAdapter(false));
        $reason = $this->invoke($m, 'shouldSkipCreateTable', ['CREATE TABLE users (id INT)']);
        $this->assertNull($reason);
    }

    public function testCreateTableNotSkippedOnSqliteLeftToIfNotExists(): void
    {
        // SQLite supports IF NOT EXISTS → never skipped by this guard, even when
        // the table really exists.
        $db = new SQLite3Adapter(':memory:');
        $db->exec('CREATE TABLE users (id INTEGER)');
        $m = $this->newMigration($db);
        $reason = $this->invoke($m, 'shouldSkipCreateTable', ['CREATE TABLE users (id INT)']);
        $this->assertNull($reason);
    }

    public function testNonCreateStatementIgnored(): void
    {
        $m = $this->newMigration(new FakeMSSQLAdapter(true));
        $reason = $this->invoke($m, 'shouldSkipCreateTable', ['INSERT INTO users VALUES (1)']);
        $this->assertNull($reason);
    }
}
