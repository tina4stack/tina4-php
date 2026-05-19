<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Regression test for tina4-php#115 — Migration tracker schema changed
 * between v2 and v3 without a backwards-compatible upgrade path.
 *
 * v2 shape:
 *   migration_id varchar(14) NOT NULL PRIMARY KEY,
 *   description  varchar(1000) DEFAULT '',
 *   content      blob,
 *   passed       integer DEFAULT 0
 *
 * v3 shape:
 *   id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *   migration  VARCHAR(255) NOT NULL UNIQUE,
 *   batch      INTEGER NOT NULL,
 *   applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
 *
 * The bug: v3's ensureMigrationsTable() called CREATE TABLE IF NOT EXISTS,
 * so an existing v2-shaped tina4_migration table was left untouched. Every
 * SELECT against the v3 columns then errored or returned empty, and the
 * runner re-ran already-applied migrations, eventually failing with errors
 * like "duplicate column name" when an ALTER TABLE ADD couldn't be repeated.
 *
 * The fix: on construction, detect a v2-shaped table and upgrade it in
 * place — ALTER TABLE ADD the v3 columns, backfill from v2 rows. Try to
 * match each v2 migration_id (timestamp prefix) to a file on disk and use
 * the file's basename as the v3 migration value. Fall back to migration_id
 * if no matching file is found.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;
use Tina4\Migration;

class Issue115Test extends TestCase
{
    private SQLite3Adapter $db;
    private string $migrationsDir;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        $this->migrationsDir = sys_get_temp_dir() . '/tina4_v2up_' . uniqid();
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

    private function createV2Table(): void
    {
        $this->db->exec(
            "CREATE TABLE tina4_migration ("
            . " migration_id VARCHAR(14) NOT NULL PRIMARY KEY,"
            . " description VARCHAR(1000) DEFAULT '',"
            . " content BLOB,"
            . " passed INTEGER DEFAULT 0"
            . ")"
        );
    }

    private function insertV2Row(string $migrationId, string $description, int $passed = 1): void
    {
        $this->db->exec(
            "INSERT INTO tina4_migration (migration_id, description, content, passed)"
            . " VALUES (:id, :d, :c, :p)",
            [':id' => $migrationId, ':d' => $description, ':c' => '', ':p' => $passed]
        );
    }

    /** Read the list of column names on the tina4_migration table. */
    private function columnNames(): array
    {
        return array_map(
            fn($c) => strtolower((string)($c['name'] ?? '')),
            $this->db->getColumns('tina4_migration')
        );
    }

    // ── 1. v2 schema detected and upgraded — v3 columns present ─────────

    public function testV2SchemaDetectedAndUpgraded(): void
    {
        $this->createV2Table();
        $this->insertV2Row('20240101000000', 'create_users');

        new Migration($this->db, $this->migrationsDir);

        $names = $this->columnNames();
        $this->assertContains('migration', $names, 'v3 migration column must be added');
        $this->assertContains('batch', $names, 'v3 batch column must be added');
        $this->assertContains('applied_at', $names, 'v3 applied_at column must be added');
    }

    // ── 2. Legacy v2 row gets its v3 migration value from a matching file ─

    public function testV2RowMatchedToFileOnDiskByTimestampPrefix(): void
    {
        $this->createV2Table();
        $this->insertV2Row('20240301120000', 'whatever');

        // Real file uses a different description than the v2 description column —
        // upgrade must key off the 14-char timestamp prefix only.
        file_put_contents(
            $this->migrationsDir . '/20240301120000_completely_different_name.sql',
            'SELECT 1'
        );

        new Migration($this->db, $this->migrationsDir);

        $rows = $this->db->query("SELECT migration FROM tina4_migration");
        $applied = array_column($rows, 'migration');
        $this->assertContains('20240301120000_completely_different_name.sql', $applied);
    }

    // ── 3. No file match → fallback keeps a reference to migration_id ───

    public function testV2RowWithNoMatchingFileFallsBackToMigrationId(): void
    {
        $this->createV2Table();
        $this->insertV2Row('99990101000000', 'orphan_no_file');

        new Migration($this->db, $this->migrationsDir);

        $rows = $this->db->query("SELECT migration FROM tina4_migration");
        $applied = array_column($rows, 'migration');
        $this->assertNotEmpty($applied);
        $this->assertNotNull($applied[0], 'Fallback migration value must be set');
        $this->assertStringContainsString(
            '99990101000000',
            (string)$applied[0],
            'Fallback must keep the legacy migration_id as a reference'
        );
    }

    // ── 4. Already-applied v2 migration must NOT re-run after upgrade ──

    public function testAppliedV2MigrationDoesNotRerunAfterUpgrade(): void
    {
        $this->createV2Table();
        $this->insertV2Row('20240101000000', 'create_users');

        // Real file exists and matches the prefix
        file_put_contents(
            $this->migrationsDir . '/20240101000000_create_users.sql',
            'CREATE TABLE users (id INTEGER)'
        );

        // Simulate v2 having already applied it — the table exists in the DB
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');

        $migration = new Migration($this->db, $this->migrationsDir);
        $result = $migration->migrate();

        $this->assertSame([], $result['errors'], 'Upgrade must not produce migration errors');
        $this->assertSame(
            [],
            $result['applied'],
            'Already-applied v2 migration must NOT re-run after the v2→v3 upgrade'
        );
    }

    // ── 5. v3 table is left untouched — second construction is a no-op ──

    public function testV3SchemaUntouched(): void
    {
        // Initialize as v3 from scratch
        new Migration($this->db, $this->migrationsDir);
        $this->db->exec("INSERT INTO tina4_migration (migration, batch) VALUES ('x.sql', 1)");

        // Second construction must not corrupt the existing v3 data
        new Migration($this->db, $this->migrationsDir);
        $rows = $this->db->query("SELECT migration FROM tina4_migration");

        $this->assertCount(1, $rows);
        $this->assertSame('x.sql', $rows[0]['migration']);
    }

    // ── 6. Empty v2 table still upgrades cleanly (no rows to backfill) ──

    public function testEmptyV2TableUpgradesCleanly(): void
    {
        $this->createV2Table();
        // No rows inserted

        new Migration($this->db, $this->migrationsDir);

        $names = $this->columnNames();
        $this->assertContains('migration', $names);
        $this->assertContains('batch', $names);
        $this->assertContains('applied_at', $names);

        $rows = $this->db->query("SELECT * FROM tina4_migration");
        $this->assertSame([], $rows);
    }

    // ── 7. Multiple v2 rows — all get backfilled, all get batch=1 ───────

    public function testMultipleV2RowsBackfilledWithBatchOne(): void
    {
        $this->createV2Table();
        $this->insertV2Row('20240101000000', 'create_users');
        $this->insertV2Row('20240201000000', 'create_orders');
        $this->insertV2Row('20240301000000', 'add_index');

        // Matching files for the first two — third has no file
        file_put_contents($this->migrationsDir . '/20240101000000_create_users.sql', 'SELECT 1');
        file_put_contents($this->migrationsDir . '/20240201000000_create_orders.sql', 'SELECT 1');

        new Migration($this->db, $this->migrationsDir);

        $rows = $this->db->query(
            "SELECT migration, batch FROM tina4_migration ORDER BY migration_id ASC"
        );
        $this->assertCount(3, $rows, 'All v2 rows must be backfilled');

        foreach ($rows as $row) {
            $this->assertSame(1, (int)$row['batch'], 'All legacy rows get batch=1');
            $this->assertNotEmpty($row['migration'], 'migration column must be backfilled');
        }
    }

    // ── 8. v2 row with passed=0 also gets backfilled (with warning logged) ─

    public function testFailedV2EntriesAlsoBackfilled(): void
    {
        $this->createV2Table();
        $this->insertV2Row('20240101000000', 'create_users', passed: 1);
        $this->insertV2Row('20240201000000', 'create_orders', passed: 0);

        new Migration($this->db, $this->migrationsDir);

        $rows = $this->db->query("SELECT migration FROM tina4_migration");
        $this->assertCount(
            2,
            $rows,
            'Both passed=1 and passed=0 v2 entries must be backfilled — leaving '
            . 'passed=0 entries without a v3 migration value would cause them to '
            . 're-run and cascade duplicate-column errors'
        );
        foreach ($rows as $row) {
            $this->assertNotEmpty($row['migration']);
        }
    }

    // ── 9. Status reporting reflects backfilled entries ─────────────────

    public function testStatusListsBackfilledLegacyMigrations(): void
    {
        $this->createV2Table();
        $this->insertV2Row('20240101000000', 'create_users');
        file_put_contents($this->migrationsDir . '/20240101000000_create_users.sql', 'SELECT 1');

        $migration = new Migration($this->db, $this->migrationsDir);
        $status = $migration->status();

        $completed = array_column($status['completed'], 'migration');
        $this->assertContains(
            '20240101000000_create_users.sql',
            $completed,
            'status()->completed must include backfilled legacy migrations'
        );
        $this->assertEmpty(
            $status['pending'],
            'No pending migrations expected — legacy row was matched to a file'
        );
    }

    // ── 10. Migration files added AFTER the upgrade still run normally ──

    public function testNewMigrationsAfterUpgradeRunNormally(): void
    {
        $this->createV2Table();
        $this->insertV2Row('20240101000000', 'create_users');
        file_put_contents(
            $this->migrationsDir . '/20240101000000_create_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)'
        );
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');

        $migration = new Migration($this->db, $this->migrationsDir);

        // A new migration is added after the upgrade
        file_put_contents(
            $this->migrationsDir . '/20260101000000_create_orders.sql',
            'CREATE TABLE orders (id INTEGER PRIMARY KEY)'
        );

        $result = $migration->migrate();

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['applied'], 'Only the new migration runs');
        $this->assertSame('20260101000000_create_orders.sql', $result['applied'][0]);
        $this->assertTrue($this->db->tableExists('orders'));
    }
}
