<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

/**
 * The ORM layer must honour a COMPOSITE primary key (feature 4, open item).
 *
 * Feature 4 fixed the raw write path: update()/delete() put EVERY primary-key
 * column in the WHERE, because keying on one column of a composite key matches
 * every row sharing that value. The ORM layer ABOVE it was never fixed, so the
 * data-loss shape lived one level up. Three defects:
 *
 *   1. SAVING A NEW ROW OVERWROTE AN EXISTING ONE. recordExists() tested only
 *      the FIRST key column, which is true for ANY row sharing it, so a
 *      genuinely new row was decided to be an UPDATE. Saving (acme, a2) rewrote
 *      (acme, a1). The worst of the three - it destroys data on an ordinary
 *      insert, with no error.
 *   2. UPDATE and DELETE keyed on one column, hitting every row sharing it.
 *   3. createTable() emitted an inline PRIMARY KEY on EACH key column, which is
 *      invalid DDL - SQLite, PostgreSQL and MySQL all reject two of them.
 *
 * PHP needed a public-API change the other three did not: `$primaryKey` was a
 * declared `string`, not derived from field definitions, so a SEPARATE
 * `$primaryKeys` array property was added. Widening `$primaryKey` itself was
 * tried and is NOT backward compatible: PHP requires a subclass to redeclare an
 * inherited typed property with the SAME type, so every existing model fataled.
 *
 * Real SQLite, no mocks: the DDL defect only shows against an engine that
 * actually parses the statement.
 */
class OrmCompositeKeyTest extends TestCase
{
    private Database $db;
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \TempPath::dir('tina4-ck-', 0777);
        $this->db = Database::create('sqlite:///' . $this->dir . '/composite.db');
        \Tina4\ORM::bindDatabase($this->db);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function membership(): \Tina4\ORM
    {
        return new class extends \Tina4\ORM {
            public string $tableName = 'membership';
            public array $primaryKeys = ['tenant', 'code'];
            public ?string $tenant = null;
            public ?string $code = null;
            public ?string $label = null;
            public ?int $seats = null;
        };
    }

    private function widget(): \Tina4\ORM
    {
        return new class extends \Tina4\ORM {
            public string $tableName = 'widget';
            public string $primaryKey = 'id';
            public ?int $id = null;
            public ?string $name = null;
        };
    }

    public function testTheModelReportsEveryPrimaryKeyColumn(): void
    {
        $this->assertSame(['tenant', 'code'], $this->membership()->getPrimaryKeys());
    }

    public function testAStringPrimaryKeyStillResolvesToOneColumn(): void
    {
        $this->assertSame(['id'], $this->widget()->getPrimaryKeys());
    }

    public function testPkWhereNamesEveryKeyColumn(): void
    {
        $m = $this->membership();
        $m->tenant = 'acme';
        $m->code = 'a1';
        [$sql, $params] = $m->pkWhere();
        $this->assertStringContainsString('tenant = ', $sql);
        $this->assertStringContainsString('code = ', $sql);
        $this->assertStringContainsString(' AND ', $sql);
        $this->assertCount(2, $params);
        $this->assertContains('acme', array_values($params));
        $this->assertContains('a1', array_values($params));
    }

    public function testCreateTableEmitsOneTableLevelPrimaryKey(): void
    {
        // Two inline PRIMARY KEY clauses is invalid DDL on every engine.
        $this->membership()->createTable();
        $ddl = strtoupper(
            $this->db->fetch(
                "SELECT sql FROM sqlite_master WHERE type='table' AND name='membership'",
                [],
                1
            )->records[0]['sql'] ?? ''
        );
        $this->assertSame(1, substr_count($ddl, 'PRIMARY KEY'), $ddl);
        $clause = explode('PRIMARY KEY', $ddl)[1] ?? '';
        $this->assertStringContainsString('TENANT', $clause, $ddl);
        $this->assertStringContainsString('CODE', $clause, $ddl);
    }

    public function testSavingASecondRowSharingItsFirstKeyColumnInsertsIt(): void
    {
        // The worst defect: this used to be decided as an UPDATE and overwrite a1.
        $this->membership()->createTable();

        $a = $this->membership();
        $a->tenant = 'acme'; $a->code = 'a1'; $a->label = 'first'; $a->seats = 1;
        $a->save();

        $b = $this->membership();
        $b->tenant = 'acme'; $b->code = 'a2'; $b->label = 'second'; $b->seats = 2;
        $b->save();

        $rows = $this->db->fetch('SELECT * FROM membership ORDER BY code', [], 100)->records;
        $this->assertCount(2, $rows, 'a new row overwrote an existing one');
    }

    public function testSaveUpdatesOnlyTheRowMatchingTheWholeKey(): void
    {
        $this->membership()->createTable();

        $a = $this->membership();
        $a->tenant = 'acme'; $a->code = 'a1'; $a->label = 'first'; $a->seats = 1;
        $a->save();
        $b = $this->membership();
        $b->tenant = 'acme'; $b->code = 'a2'; $b->label = 'second'; $b->seats = 2;
        $b->save();

        $c = $this->membership();
        $c->tenant = 'acme'; $c->code = 'a1'; $c->label = 'CHANGED'; $c->seats = 99;
        $c->save();

        $rows = $this->db->fetch('SELECT * FROM membership ORDER BY code', [], 100)->records;
        $byCode = [];
        foreach ($rows as $r) {
            $byCode[$r['code']] = $r;
        }
        $this->assertSame('CHANGED', $byCode['a1']['label']);
        $this->assertSame('second', $byCode['a2']['label'], 'saving a1 rewrote a2 - the key is truncated');
    }

    public function testNegativeDeleteRemovesOnlyTheRowMatchingTheWholeKey(): void
    {
        $this->membership()->createTable();

        $a = $this->membership();
        $a->tenant = 'acme'; $a->code = 'a1'; $a->label = 'first'; $a->seats = 1;
        $a->save();
        $b = $this->membership();
        $b->tenant = 'acme'; $b->code = 'a2'; $b->label = 'second'; $b->seats = 2;
        $b->save();

        $d = $this->membership();
        $d->tenant = 'acme'; $d->code = 'a1';
        $d->delete();

        $rows = $this->db->fetch('SELECT code FROM membership', [], 100)->records;
        $this->assertCount(1, $rows, 'delete removed more than the addressed row');
        $this->assertSame('a2', $rows[0]['code']);
    }

    /** The common case must not regress: one key column keeps working. */
    public function testNegativeASingleKeyModelIsUnaffected(): void
    {
        $w = $this->widget();
        $w->createTable();
        $ddl = strtoupper(
            $this->db->fetch(
                "SELECT sql FROM sqlite_master WHERE type='table' AND name='widget'",
                [],
                1
            )->records[0]['sql'] ?? ''
        );
        $this->assertSame(1, substr_count($ddl, 'PRIMARY KEY'), $ddl);
        // A single-key model keeps the INLINE form, not a table-level clause.
        $this->assertStringNotContainsString('PRIMARY KEY (', $ddl, $ddl);

        $w->name = 'one';
        $this->assertNotFalse($w->save());
        $this->assertNotNull($w->id);
    }
}
