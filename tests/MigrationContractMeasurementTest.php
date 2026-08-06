<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * M1 measurement, PHP half (authority:
 * tina4-documentation/plan/v3/parity-3.13.96-decisions.md). Measured against a
 * REAL SQLite database (no mocks) so the four frameworks can be compared, and
 * locked in so the measured shapes cannot drift:
 *
 *   - tina4_migration tracking-table schema
 *   - migrate() return shape on success AND on a failed file
 *   - a failed file STOPS the run (no row written, later files untouched)
 *   - status() output shape
 *   - rollback() semantics (default 1 batch) for BOTH sql and code kinds
 *   - createMigration() scaffolding for sql and code kinds
 */

namespace Tina4;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

class MigrationContractMeasurementTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/m1-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/migrations', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->base);
    }

    private function rmrf(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = "$dir/$e";
            is_dir($p) ? $this->rmrf($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function db(string $name = 'app')
    {
        return Database::create('sqlite:///' . $this->base . '/' . $name . '.db');
    }

    private function migDir(): string
    {
        return $this->base . '/migrations';
    }

    // ── createMigration scaffolding (sql + code) ──

    public function testCreateMigrationScaffoldsSqlAndCodeKinds(): void
    {
        $sql = Migration::createMigration('create widgets', $this->migDir(), 'sql');
        $this->assertStringEndsWith('.sql', $sql);
        $this->assertFileExists($sql);
        $this->assertFileExists(preg_replace('/\.sql$/', '.down.sql', $sql), 'sql kind creates a paired .down.sql');
        $this->assertMatchesRegularExpression('/\d{14}_create_widgets\.sql$/', basename($sql));

        $code = Migration::createMigration('create gadgets', $this->migDir(), 'code');
        $this->assertStringEndsWith('.php', $code, 'code kind creates a single .php file');
        $this->assertStringContainsString('extends MigrationBase', file_get_contents($code));
    }

    // ── tina4_migration tracking-table schema ──

    public function testTrackingTableSchemaIsTheCanonicalSixColumns(): void
    {
        $db = $this->db();
        new Migration($db, $this->migDir()); // constructor ensures the table

        $cols = [];
        foreach ($db->getColumns('tina4_migration') as $c) {
            $cols[$c['name']] = $c;
        }
        $this->assertSame(
            ['id', 'migration_name', 'description', 'batch', 'executed_at', 'passed'],
            array_keys($cols),
            'the canonical column set, in order',
        );
        $this->assertFalse($cols['migration_name']['nullable'], 'migration_name is NOT NULL');
        $this->assertFalse($cols['executed_at']['nullable'], 'executed_at is NOT NULL');
        $this->assertFalse($cols['passed']['nullable'], 'passed is NOT NULL');
        $this->assertFalse($cols['batch']['nullable'], 'batch is NOT NULL');
        $this->assertSame('1', (string)$cols['batch']['default'], 'batch defaults to 1');
        $this->assertSame('1', (string)$cols['passed']['default'], 'passed defaults to 1');
    }

    // ── migrate() success return shape + recorded row ──

    public function testMigrateSuccessReturnShapeAndRecordedRow(): void
    {
        $up = Migration::createMigration('create widgets', $this->migDir(), 'sql');
        file_put_contents($up, 'CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT);');
        file_put_contents(preg_replace('/\.sql$/', '.down.sql', $up), 'DROP TABLE widgets;');

        $db = $this->db();
        $res = (new Migration($db, $this->migDir()))->migrate();

        $this->assertSame(['applied', 'skipped', 'errors'], array_keys($res), 'migrate() returns applied/skipped/errors');
        $this->assertSame([basename($up)], $res['applied']);
        $this->assertSame([], $res['skipped']);
        $this->assertSame([], $res['errors']);
        $this->assertTrue($db->tableExists('widgets'));

        // The recorded row carries the canonical values; executed_at is ISO-8601.
        $row = $db->fetch('SELECT * FROM tina4_migration')->records[0];
        $this->assertSame(basename($up), $row['migration_name']);
        $this->assertSame(1, (int)$row['batch']);
        $this->assertSame(1, (int)$row['passed']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $row['executed_at']);
    }

    // ── a failed file STOPS the run ──

    public function testFailedFileStopsTheRunAndWritesNoRow(): void
    {
        $dir = $this->migDir();
        file_put_contents($dir . '/001_ok.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY);');
        file_put_contents($dir . '/002_bad.sql', 'CREATE TABLE b (id INTEGER PRIMARY KEY; -- broken');
        file_put_contents($dir . '/003_after.sql', 'CREATE TABLE c (id INTEGER PRIMARY KEY);');

        $db = $this->db();
        $m = new Migration($db, $dir);
        $res = $m->migrate();

        $this->assertSame(['001_ok.sql'], $res['applied'], 'the file before the failure applied');
        $this->assertArrayHasKey('002_bad.sql', $res['errors'], 'the failing file is reported in errors (a map)');
        $this->assertTrue($db->tableExists('a'));
        $this->assertFalse($db->tableExists('c'), 'the run STOPS at the first error - the later file is never run');

        // No row is written for a failed file (rolled back, not recorded passed=0).
        $failedRows = (int)$db->fetch(
            "SELECT COUNT(*) c FROM tina4_migration WHERE migration_name = '002_bad.sql'"
        )->records[0]['c'];
        $this->assertSame(0, $failedRows);

        // Both the failed and the un-attempted file are still pending.
        $this->assertSame(['002_bad.sql', '003_after.sql'], $m->status()['pending']);
    }

    // ── status() output shape ──

    public function testStatusOutputShape(): void
    {
        $up = Migration::createMigration('create things', $this->migDir(), 'sql');
        file_put_contents($up, 'CREATE TABLE things (id INTEGER PRIMARY KEY);');

        $db = $this->db();
        $m = new Migration($db, $this->migDir());
        $m->migrate();
        $st = $m->status();

        $this->assertSame(['completed', 'pending'], array_keys($st));
        $this->assertSame(
            ['migration_name', 'description', 'batch', 'executed_at'],
            array_keys($st['completed'][0]),
            'completed rows carry migration_name/description/batch/executed_at',
        );
        $this->assertSame([], $st['pending']);
    }

    // ── rollback() default (1 batch) for both sql and code kinds ──

    public function testRollbackDefaultReversesSqlAndCodeMigrations(): void
    {
        $dir = $this->migDir();

        // sql kind with a paired .down.sql
        file_put_contents($dir . '/001_widgets.sql', 'CREATE TABLE widgets (id INTEGER PRIMARY KEY);');
        file_put_contents($dir . '/001_widgets.down.sql', 'DROP TABLE widgets;');

        // code kind with up()/down()
        file_put_contents($dir . '/002_gadgets.php', "<?php\nuse Tina4\\MigrationBase;\n"
            . "class M1MeasureGadgets extends MigrationBase {\n"
            . "  public function up(\$db): void { \$db->execute('CREATE TABLE gadgets (id INTEGER PRIMARY KEY)'); }\n"
            . "  public function down(\$db): void { \$db->execute('DROP TABLE gadgets'); }\n}\n");

        $db = $this->db();
        (new Migration($db, $dir))->migrate();
        $this->assertTrue($db->tableExists('widgets'));
        $this->assertTrue($db->tableExists('gadgets'));

        $res = (new Migration($db, $dir))->rollback();

        $this->assertSame(['rolledBack', 'errors'], array_keys($res), 'rollback() returns rolledBack/errors');
        $this->assertSame([], $res['errors']);
        // Both were batch 1; default rollback (1 batch) reverses both, newest first.
        $this->assertSame(['002_gadgets.php', '001_widgets.sql'], $res['rolledBack']);
        $this->assertFalse($db->tableExists('widgets'), 'sql .down.sql ran');
        $this->assertFalse($db->tableExists('gadgets'), 'code down() ran');
        $this->assertSame(0, (int)$db->fetch('SELECT COUNT(*) c FROM tina4_migration')->records[0]['c']);
    }
}
