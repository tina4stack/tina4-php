<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;
use Tina4\Migration;

/**
 * Lock-in tests for the `passed` column in the canonical `tina4_migration`
 * bookkeeping table (tina4-php#129 / #172).
 *
 * The canonical v3 shape is identical across all four Tina4 frameworks:
 *   id, migration_name VARCHAR(500) NOT NULL UNIQUE, description VARCHAR(500),
 *   batch INTEGER NOT NULL DEFAULT 1, executed_at VARCHAR(50) NOT NULL,
 *   passed INTEGER NOT NULL DEFAULT 1
 *
 * The contract these tests pin down:
 *   - "applied" == a row with passed = 1 (getAppliedMigrations reads WHERE passed = 1).
 *   - migrate() writes ONLY passed = 1 rows; a failed file is rolled back and NO
 *     row is written, and the run stops.
 *   - recordMigration($name, $batch, $passed) CAN write a passed = 0 row; any
 *     passed = 0 row is treated as NOT applied, so its migration is re-selected
 *     to run rather than skipped.
 *
 * All tests use a REAL in-memory SQLite database (no mocks). Real SQLite is the
 * live dependency exercised here, matching MigrationV3Test.php's SQLite tests.
 */
class MigrationPassedColumnTest extends TestCase
{
    private SQLite3Adapter $db;
    private string $migrationsDir;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        $this->migrationsDir = sys_get_temp_dir() . '/tina4_passed_col_test_' . uniqid();
        mkdir($this->migrationsDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->db->close();
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

    /** Column names of the tracking table, lower-cased. */
    private function trackingColumns(): array
    {
        return array_map(
            static fn($c) => strtolower((string)($c['name'] ?? '')),
            $this->db->getColumns('tina4_migration')
        );
    }

    /** The passed value stored for a migration_name, or null when no row exists. */
    private function passedFor(string $migrationName): ?int
    {
        $rows = $this->db->query(
            "SELECT passed FROM tina4_migration WHERE migration_name = :n",
            [':n' => $migrationName]
        );
        return isset($rows[0]) ? (int)$rows[0]['passed'] : null;
    }

    // -----------------------------------------------------------------
    // 1. A fresh v3 table carries the canonical `passed` column.
    // -----------------------------------------------------------------

    public function testFreshV3TableHasPassedColumn(): void
    {
        new Migration($this->db, $this->migrationsDir);
        $this->assertTrue($this->db->tableExists('tina4_migration'));

        $cols = $this->trackingColumns();

        // The column at the heart of #129 / #172.
        $this->assertContains('passed', $cols, '`passed` column missing from a fresh v3 table');

        // The full canonical column set, unified across all four frameworks.
        $canonical = ['id', 'migration_name', 'description', 'batch', 'executed_at', 'passed'];
        foreach ($canonical as $expected) {
            $this->assertContains($expected, $cols, "canonical column `{$expected}` missing from a fresh v3 table");
        }
        // Nothing outside the canonical set (no legacy v2/older-v3 columns).
        $expected = $canonical;
        sort($expected);
        $actual = array_values(array_unique($cols));
        sort($actual);
        $this->assertSame(
            $expected,
            $actual,
            'fresh v3 table columns must be exactly the canonical set'
        );
    }

    // -----------------------------------------------------------------
    // 2. A successfully applied migration is recorded passed = 1 and is
    //    not re-run on a second migrate().
    // -----------------------------------------------------------------

    public function testAppliedMigrationRecordedPassed1AndNotRerun(): void
    {
        $fileName = '20240101000000_create_widgets.sql';
        file_put_contents(
            $this->migrationsDir . '/' . $fileName,
            'CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)'
        );

        $migration = new Migration($this->db, $this->migrationsDir);
        $result = $migration->migrate();

        // The file applied cleanly.
        $this->assertContains($fileName, $result['applied']);
        $this->assertSame([], $result['errors']);
        $this->assertTrue($this->db->tableExists('widgets'));

        // Its bookkeeping row is passed = 1 (a migration is "applied" iff passed = 1).
        $this->assertSame(1, $this->passedFor($fileName), 'applied migration must be recorded passed = 1');

        // A second run (fresh runner on the SAME real DB) must NOT re-run it.
        $migration2 = new Migration($this->db, $this->migrationsDir);
        $result2 = $migration2->migrate();
        $this->assertSame([], $result2['applied'], 'an already-applied (passed = 1) migration must not re-run');
        $this->assertSame([], $result2['errors']);

        // The table it created still exists after the no-op second run.
        $this->assertTrue($this->db->tableExists('widgets'));

        // Still exactly one bookkeeping row for it — no duplicate.
        $rows = $this->db->query(
            "SELECT COUNT(*) AS c FROM tina4_migration WHERE migration_name = :n",
            [':n' => $fileName]
        );
        $this->assertSame(1, (int)$rows[0]['c']);
    }

    // -----------------------------------------------------------------
    // 3. A leftover passed = 0 row re-applies cleanly on the next migrate().
    //
    // A passed = 0 row is NOT treated as applied, so its migration is
    // re-selected to run. When the fresh passed = 1 row is written on
    // completion, recordMigration() first DELETEs any existing row for that
    // migration_name (delete-before-insert), so the leftover passed = 0 marker
    // (a prior failure, or one carried over by the v2 -> v3 upgrade) is
    // superseded instead of colliding on the UNIQUE migration_name. The
    // migration applies cleanly, its effect table is created, and exactly ONE
    // row remains for it - passed = 1. This is IDENTICAL to the Python master
    // (same DELETE-then-INSERT before the fresh passed = 1 row). Invariant: the
    // bookkeeping table holds AT MOST ONE row per migration_name, latest wins.
    // -----------------------------------------------------------------

    public function testPassedZeroRowReAppliesCleanlyOnMigrate(): void
    {
        new Migration($this->db, $this->migrationsDir); // fresh v3 table
        $this->assertContains('passed', $this->trackingColumns());

        $fileName = '20240101000000_create_gadgets.sql';
        file_put_contents(
            $this->migrationsDir . '/' . $fileName,
            'CREATE TABLE gadgets (id INTEGER PRIMARY KEY)'
        );

        // A fresh runner over the existing (empty) table, then write a passed = 0
        // row explicitly - the ONLY way a passed = 0 row is produced, since
        // migrate() never records failures.
        $migration = new Migration($this->db, $this->migrationsDir);
        $migration->recordMigration($fileName, 1, 0);
        $this->assertSame(0, $this->passedFor($fileName), 'precondition: a passed = 0 row exists');

        // --- POSITIVE (#172 lock): passed = 0 != applied ---
        // NOT applied: getAppliedMigrations reads WHERE passed = 1.
        $this->assertSame([], $migration->getAppliedMigrations(), 'a passed = 0 row must NOT count as applied');
        // IS pending: re-selected to run, not skipped like a passed = 1 row.
        $this->assertContains($fileName, $migration->getPending(), 'a passed = 0 migration must be listed as pending');
        $this->assertContains($fileName, $migration->status()['pending'], 'status() must list a passed = 0 migration as pending');
        // The effect table does not exist yet.
        $this->assertFalse($this->db->tableExists('gadgets'));

        // --- POSITIVE (b) lock: the leftover passed = 0 row re-applies cleanly ---
        $result = $migration->migrate();

        // migrate() applied the file cleanly: it is in ['applied'] and there are
        // no errors. The delete-before-insert superseded the leftover passed = 0
        // row rather than colliding on the UNIQUE migration_name.
        $this->assertContains($fileName, $result['applied'], 'a leftover passed = 0 marker must re-apply cleanly');
        $this->assertSame([], $result['errors'], 'a clean re-apply must report no errors');

        // The migration's table effect WAS created.
        $this->assertTrue($this->db->tableExists('gadgets'), 'the clean re-apply must have created its DDL');

        // Exactly ONE row now remains for this migration_name, and it is
        // passed = 1 - the stale passed = 0 row was superseded (no duplicate).
        $rows = $this->db->query(
            "SELECT passed FROM tina4_migration WHERE migration_name = :n",
            [':n' => $fileName]
        );
        $this->assertCount(1, $rows, 'exactly one bookkeeping row must remain - the stale passed = 0 row was superseded');
        $this->assertSame(1, (int)$rows[0]['passed'], 'the surviving row must be passed = 1');

        // It now counts as applied and is no longer pending.
        $appliedNames = array_column($migration->getAppliedMigrations(), 'migration_name');
        $this->assertContains($fileName, $appliedNames, 'the re-applied migration must now count as applied');
        $this->assertNotContains($fileName, $migration->getPending(), 'the re-applied migration must no longer be pending');
    }

    // -----------------------------------------------------------------
    // 4. A v2 table (migration_id-keyed) with an already-applied row
    //    upgrades in place to the v3 shape and does not re-run the
    //    already-applied migration.
    // -----------------------------------------------------------------

    public function testV2TableWithPassed0RowUpgradesAndAppliedNotRerun(): void
    {
        // The PHP v2 shape exactly as tina4-php ^2.x created it.
        $this->db->execute(
            "CREATE TABLE tina4_migration (
                migration_id VARCHAR(14) NOT NULL PRIMARY KEY,
                description VARCHAR(1000),
                content BLOB,
                passed INTEGER
            )"
        );

        // An APPLIED (passed = 1) v2 row whose matching file exists on disk AND
        // whose effect table already exists (it ran under v2).
        $appliedFile = '20240101000000_create_applied.sql';
        $this->db->execute(
            "INSERT INTO tina4_migration (migration_id, description, passed) VALUES ('20240101000000', 'create applied', 1)"
        );
        $this->db->execute('CREATE TABLE v2_applied_effect (id INTEGER PRIMARY KEY)');
        file_put_contents(
            $this->migrationsDir . '/' . $appliedFile,
            'CREATE TABLE v2_applied_effect (id INTEGER PRIMARY KEY)'
        );

        // A second, passed = 0 legacy row. Its file is deliberately ABSENT so it
        // is never listed as pending and nothing errors on it (per #129/#172).
        $this->db->execute(
            "INSERT INTO tina4_migration (migration_id, description, passed) VALUES ('20240102000000', 'half done', 0)"
        );

        // Constructing the runner performs the in-place v2 -> v3 upgrade.
        $migration = new Migration($this->db, $this->migrationsDir);

        // The table now carries the canonical v3 columns.
        $cols = $this->trackingColumns();
        foreach (['migration_name', 'batch', 'executed_at'] as $added) {
            $this->assertContains($added, $cols, "v2 -> v3 upgrade did not add `{$added}`");
        }
        // passed and the legacy migration_id PK are retained.
        $this->assertContains('passed', $cols);
        $this->assertContains('migration_id', $cols);

        // The applied migration's migration_name was backfilled from its file.
        $appliedNames = array_column($migration->getAppliedMigrations(), 'migration_name');
        $this->assertContains($appliedFile, $appliedNames, 'the applied v2 row must be recognised as applied after upgrade');

        // It must NOT be re-listed as pending.
        $pending = array_map('basename', $migration->getPendingMigrations());
        $this->assertNotContains($appliedFile, $pending, 'an already-applied v2 migration was re-listed as pending');

        // A real migrate() does NOT re-run the already-applied migration and
        // throws NO exception (pending is empty).
        $result = $migration->migrate();
        $this->assertNotContains($appliedFile, $result['applied'], 'the already-applied migration was wrongly re-run');
        $this->assertSame([], $result['errors']);

        // Its effect table is untouched (not dropped / not recreated).
        $this->assertTrue($this->db->tableExists('v2_applied_effect'));
    }
}
