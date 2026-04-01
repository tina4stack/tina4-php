<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;
use Tina4\Migration;

class MigrationV3Test extends TestCase
{
    private SQLite3Adapter $db;
    private string $migrationsDir;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        $this->migrationsDir = sys_get_temp_dir() . '/tina4_migrations_test_' . uniqid();
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
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // --- Setup ---

    public function testMigrationsTableCreated(): void
    {
        new Migration($this->db, $this->migrationsDir);
        $this->assertTrue($this->db->tableExists('tina4_migration'));
    }

    // --- Migrate ---

    public function testMigrateNoPendingFiles(): void
    {
        $migration = new Migration($this->db, $this->migrationsDir);
        $result = $migration->migrate();

        $this->assertSame([], $result['applied']);
        $this->assertSame([], $result['errors']);
    }

    public function testMigrateSingleFile(): void
    {
        file_put_contents(
            $this->migrationsDir . '/20240101000000_create_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT)'
        );

        $migration = new Migration($this->db, $this->migrationsDir);
        $result = $migration->migrate();

        $this->assertCount(1, $result['applied']);
        $this->assertSame('20240101000000_create_users.sql', $result['applied'][0]);
        $this->assertTrue($this->db->tableExists('users'));
    }

    public function testMigrateMultipleFiles(): void
    {
        file_put_contents(
            $this->migrationsDir . '/20240101000000_create_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)'
        );
        file_put_contents(
            $this->migrationsDir . '/20240102000000_create_posts.sql',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT NOT NULL, user_id INTEGER)'
        );

        $migration = new Migration($this->db, $this->migrationsDir);
        $result = $migration->migrate();

        $this->assertCount(2, $result['applied']);
        $this->assertTrue($this->db->tableExists('users'));
        $this->assertTrue($this->db->tableExists('posts'));
    }

    public function testMigrateMultiStatementFile(): void
    {
        file_put_contents(
            $this->migrationsDir . '/20240101000000_setup.sql',
            "CREATE TABLE a (id INTEGER PRIMARY KEY);\nCREATE TABLE b (id INTEGER PRIMARY KEY)"
        );

        $migration = new Migration($this->db, $this->migrationsDir);
        $result = $migration->migrate();

        $this->assertCount(1, $result['applied']);
        $this->assertTrue($this->db->tableExists('a'));
        $this->assertTrue($this->db->tableExists('b'));
    }

    public function testMigrateSkipsAlreadyApplied(): void
    {
        file_put_contents(
            $this->migrationsDir . '/20240101000000_create_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)'
        );

        $migration = new Migration($this->db, $this->migrationsDir);
        $result1 = $migration->migrate();
        $this->assertCount(1, $result1['applied']);

        // Run again — should skip
        $migration2 = new Migration($this->db, $this->migrationsDir);
        $result2 = $migration2->migrate();
        $this->assertCount(0, $result2['applied']);
    }

    public function testMigrateSkipsEmptyFiles(): void
    {
        file_put_contents($this->migrationsDir . '/20240101000000_empty.sql', '');

        $migration = new Migration($this->db, $this->migrationsDir);
        $result = $migration->migrate();

        $this->assertCount(0, $result['applied']);
        $this->assertCount(1, $result['skipped']);
    }

    public function testMigrateRecordsError(): void
    {
        file_put_contents(
            $this->migrationsDir . '/20240101000000_bad.sql',
            'THIS IS NOT VALID SQL'
        );

        $migration = new Migration($this->db, $this->migrationsDir);
        $result = $migration->migrate();

        $this->assertCount(0, $result['applied']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testMigrateErrorStopsExecution(): void
    {
        file_put_contents(
            $this->migrationsDir . '/20240101000000_create_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)'
        );
        file_put_contents(
            $this->migrationsDir . '/20240102000000_bad.sql',
            'INVALID SQL STATEMENT'
        );
        file_put_contents(
            $this->migrationsDir . '/20240103000000_create_posts.sql',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY)'
        );

        $migration = new Migration($this->db, $this->migrationsDir);
        $result = $migration->migrate();

        $this->assertCount(1, $result['applied']);
        $this->assertNotEmpty($result['errors']);
        $this->assertFalse($this->db->tableExists('posts')); // Third migration should not run
    }

    // --- Rollback ---

    public function testRollback(): void
    {
        file_put_contents(
            $this->migrationsDir . '/20240101000000_create_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)'
        );
        file_put_contents(
            $this->migrationsDir . '/20240102000000_create_posts.sql',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY)'
        );

        $migration = new Migration($this->db, $this->migrationsDir);
        $migration->migrate();

        $result = $migration->rollback();

        $this->assertCount(2, $result['rolledBack']);
        $this->assertEmpty($result['errors']);
    }

    public function testRollbackRemovesMigrationRecords(): void
    {
        file_put_contents(
            $this->migrationsDir . '/20240101000000_create_users.sql',
            'CREATE TABLE users (id INTEGER PRIMARY KEY)'
        );

        $migration = new Migration($this->db, $this->migrationsDir);
        $migration->migrate();

        $this->assertCount(1, $migration->getAppliedMigrations());

        $migration->rollback();

        $this->assertCount(0, $migration->getAppliedMigrations());
    }

    public function testRollbackEmptyDatabase(): void
    {
        $migration = new Migration($this->db, $this->migrationsDir);
        $result = $migration->rollback();

        $this->assertSame([], $result['rolledBack']);
        $this->assertSame([], $result['errors']);
    }

    // --- Batch Numbers ---

    public function testBatchNumbers(): void
    {
        file_put_contents(
            $this->migrationsDir . '/20240101000000_a.sql',
            'CREATE TABLE a (id INTEGER PRIMARY KEY)'
        );

        $migration = new Migration($this->db, $this->migrationsDir);
        $migration->migrate();

        // Add another migration file
        file_put_contents(
            $this->migrationsDir . '/20240102000000_b.sql',
            'CREATE TABLE b (id INTEGER PRIMARY KEY)'
        );

        $migration2 = new Migration($this->db, $this->migrationsDir);
        $migration2->migrate();

        $applied = $migration2->getAppliedMigrations();
        $this->assertCount(2, $applied);
        $this->assertSame(1, $applied[0]['batch']);
        $this->assertSame(2, $applied[1]['batch']);
    }

    public function testRollbackOnlyLastBatch(): void
    {
        file_put_contents(
            $this->migrationsDir . '/20240101000000_a.sql',
            'CREATE TABLE a (id INTEGER PRIMARY KEY)'
        );

        $migration = new Migration($this->db, $this->migrationsDir);
        $migration->migrate();

        file_put_contents(
            $this->migrationsDir . '/20240102000000_b.sql',
            'CREATE TABLE b (id INTEGER PRIMARY KEY)'
        );

        $migration2 = new Migration($this->db, $this->migrationsDir);
        $migration2->migrate();

        // Rollback should only undo the second batch
        $result = $migration2->rollback();
        $this->assertCount(1, $result['rolledBack']);
        $this->assertSame('20240102000000_b.sql', $result['rolledBack'][0]);

        // First migration should still be applied
        $applied = $migration2->getAppliedMigrations();
        $this->assertCount(1, $applied);
        $this->assertSame('20240101000000_a.sql', $applied[0]['migration']);
    }

    // --- Listing ---

    public function testGetPendingMigrations(): void
    {
        file_put_contents(
            $this->migrationsDir . '/20240101000000_a.sql',
            'CREATE TABLE a (id INTEGER PRIMARY KEY)'
        );
        file_put_contents(
            $this->migrationsDir . '/20240102000000_b.sql',
            'CREATE TABLE b (id INTEGER PRIMARY KEY)'
        );

        $migration = new Migration($this->db, $this->migrationsDir);
        $this->assertCount(2, $migration->getPendingMigrations());

        $migration->migrate();
        $this->assertCount(0, $migration->getPendingMigrations());
    }

    public function testGetMigrationFiles(): void
    {
        file_put_contents($this->migrationsDir . '/20240101000000_a.sql', 'SELECT 1');
        file_put_contents($this->migrationsDir . '/20240102000000_b.sql', 'SELECT 1');
        file_put_contents($this->migrationsDir . '/not_a_migration.txt', 'nope');

        $migration = new Migration($this->db, $this->migrationsDir);
        $files = $migration->getMigrationFiles();

        $this->assertCount(2, $files);
    }

    public function testMigrationsRunInOrder(): void
    {
        file_put_contents(
            $this->migrationsDir . '/20240102000000_second.sql',
            'CREATE TABLE second (id INTEGER PRIMARY KEY)'
        );
        file_put_contents(
            $this->migrationsDir . '/20240101000000_first.sql',
            'CREATE TABLE first (id INTEGER PRIMARY KEY)'
        );

        $migration = new Migration($this->db, $this->migrationsDir);
        $result = $migration->migrate();

        $this->assertSame('20240101000000_first.sql', $result['applied'][0]);
        $this->assertSame('20240102000000_second.sql', $result['applied'][1]);
    }

    // --- Pending status ---

    public function testGetPendingAndAppliedStatus(): void
    {
        file_put_contents(
            $this->migrationsDir . '/20240101000000_create_orders.sql',
            'CREATE TABLE orders (id INTEGER PRIMARY KEY)'
        );

        $migration = new Migration($this->db, $this->migrationsDir);
        $pending = $migration->getPendingMigrations();
        $this->assertCount(1, $pending);

        $migration->migrate();
        $pending2 = $migration->getPendingMigrations();
        $this->assertCount(0, $pending2);
        $applied = $migration->getAppliedMigrations();
        $this->assertCount(1, $applied);
    }

    // --- Multi-statement with rollback on failure ---

    public function testMultiStatementWithSemicolons(): void
    {
        file_put_contents(
            $this->migrationsDir . '/20240101000000_multi.sql',
            "CREATE TABLE t1 (id INTEGER PRIMARY KEY);\n" .
            "INSERT INTO t1 (id) VALUES (1);\n" .
            "CREATE TABLE t2 (id INTEGER PRIMARY KEY);"
        );

        $migration = new Migration($this->db, $this->migrationsDir);
        $result = $migration->migrate();

        $this->assertCount(1, $result['applied']);
        $this->assertTrue($this->db->tableExists('t1'));
        $this->assertTrue($this->db->tableExists('t2'));
    }

    // --- SQL with comments ---

    public function testSqlWithLineComments(): void
    {
        file_put_contents(
            $this->migrationsDir . '/20240101000000_comments.sql',
            "-- This is a comment\n" .
            "CREATE TABLE commented (id INTEGER PRIMARY KEY);\n" .
            "-- Another comment\n"
        );

        $migration = new Migration($this->db, $this->migrationsDir);
        $result = $migration->migrate();

        $this->assertCount(1, $result['applied']);
        $this->assertTrue($this->db->tableExists('commented'));
    }

    // --- Description sanitization in migration filenames ---

    public function testMigrationFileFiltersSqlOnly(): void
    {
        file_put_contents($this->migrationsDir . '/20240101000000_a.sql', 'SELECT 1');
        file_put_contents($this->migrationsDir . '/not_a_migration.txt', 'nope');
        file_put_contents($this->migrationsDir . '/readme.md', '# readme');

        $migration = new Migration($this->db, $this->migrationsDir);
        $files = $migration->getMigrationFiles();

        $this->assertCount(1, $files);
    }

    // --- Rollback removes migration records (verify via count) ---

    public function testRollbackClearsAppliedRecords(): void
    {
        file_put_contents(
            $this->migrationsDir . '/20240101000000_create_rb.sql',
            'CREATE TABLE rb (id INTEGER PRIMARY KEY)'
        );

        $migration = new Migration($this->db, $this->migrationsDir);
        $migration->migrate();
        $this->assertCount(1, $migration->getAppliedMigrations());

        $migration->rollback();
        $this->assertCount(0, $migration->getAppliedMigrations());
    }

    // --- Error stops further migrations ---

    public function testErrorInMiddleStopsFurtherMigrations(): void
    {
        file_put_contents(
            $this->migrationsDir . '/20240101000000_good.sql',
            'CREATE TABLE good_table (id INTEGER PRIMARY KEY)'
        );
        file_put_contents(
            $this->migrationsDir . '/20240102000000_bad_err.sql',
            'THIS IS INVALID SQL'
        );
        file_put_contents(
            $this->migrationsDir . '/20240103000000_after.sql',
            'CREATE TABLE after_table (id INTEGER PRIMARY KEY)'
        );

        $migration = new Migration($this->db, $this->migrationsDir);
        $result = $migration->migrate();

        $this->assertCount(1, $result['applied']);
        $this->assertNotEmpty($result['errors']);
        $this->assertFalse($this->db->tableExists('after_table'));
    }

    // --- Applied migrations have data ---

    public function testAppliedMigrationsHaveData(): void
    {
        file_put_contents(
            $this->migrationsDir . '/20240101000000_ts_test.sql',
            'CREATE TABLE ts_test (id INTEGER PRIMARY KEY)'
        );

        $migration = new Migration($this->db, $this->migrationsDir);
        $migration->migrate();

        $applied = $migration->getAppliedMigrations();
        $this->assertCount(1, $applied);
        $this->assertArrayHasKey('migration', $applied[0]);
        $this->assertArrayHasKey('batch', $applied[0]);
    }
}
