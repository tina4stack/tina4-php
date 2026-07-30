<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

/**
 * Write-path contract: a write with no filter is an error, not a full-table operation.
 *
 * Audit feature 4 (plan/v3/features/004-sqlite-adapter.md), P1.
 *
 * The bug these lock in: update($table, $data) with no explicit filter builds
 * "UPDATE table SET ..." with NO WHERE clause, so it overwrites EVERY row and
 * reports success. Verified in PHP, Python and Ruby; Node silently changed
 * nothing instead.
 *
 * PHP already gets two things right and those are locked in here too: a failed
 * write RAISES (it never returns false), and lastId is null on update/delete.
 *
 * Real SQLite files in a temp dir. No mocks.
 */
class WritePathContractTest extends TestCase
{
    private string $dir;
    private Database $db;

    /**
     * The engine under test.
     *
     * TINA4_TEST_WRITE_PATH_URL points this whole class at a real engine, with
     * TINA4_TEST_WRITE_PATH_USERNAME / _PASSWORD for its login; unset, it runs
     * on SQLite as before. CI runs the class once per engine.
     *
     * This suite pinned the write path for feature 4 and has only ever run on
     * SQLite - which is the one engine that shares nothing with the others on
     * the axes that matter here: placeholder style, RETURNING support,
     * identifier quoting and sequence behaviour. It could not gate the feature-3
     * refactor (moving insert/update/delete out of ten adapters into one
     * builder) while that was true.
     */
    private static function engineUrl(): ?string
    {
        $url = trim((string) (getenv('TINA4_TEST_WRITE_PATH_URL') ?: ''));
        return $url === '' ? null : $url;
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tina4-writepath-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);

        $url = self::engineUrl();
        $this->db = $url === null
            ? Database::create('sqlite:///' . $this->dir . '/contract.db')
            : Database::create(
                $url,
                username: (string) (getenv('TINA4_TEST_WRITE_PATH_USERNAME') ?: ''),
                password: (string) (getenv('TINA4_TEST_WRITE_PATH_PASSWORD') ?: '')
            );

        try {
            $this->db->execute('DROP TABLE t');
        } catch (\Throwable) {
            // first run against this engine
        }

        // AUTOINCREMENT is SQLite's spelling; SQLTranslator rewrites it per
        // engine. `id INTEGER PRIMARY KEY` self-increments on SQLite and is a
        // plain NOT NULL column everywhere else - writing it by hand is how a
        // suite silently stays SQLite-only.
        $ddl = 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(80))';
        $this->db->execute(\Tina4\SQLTranslator::autoIncrementSyntax($ddl, $this->db->getAdapter()->getDatabaseType()));

        // Let the SEQUENCE assign the ids. The assertions key on id 1 and 2, and
        // a fresh sequence yields exactly those in insertion order on every
        // engine. Hand-seeding id => 1, 2 instead leaves a real sequence still
        // pointing at 1, so the first insert that omits an id collides on the
        // primary key - SQLite hides that because its rowid follows the max.
        $this->db->insert('t', ['name' => 'one']);
        $this->db->insert('t', ['name' => 'two']);
        $this->db->commit();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        return $this->db->fetch('SELECT * FROM t ORDER BY id')->toArray();
    }

    // --- pair 1: keyed update ---------------------------------------------

    public function testUpdateWithAPrimaryKeyInDataUpdatesOnlyThatRow(): void
    {
        $this->db->update('t', ['id' => 1, 'name' => 'CHANGED']);
        $rows = $this->rows();
        $this->assertCount(2, $rows);
        $this->assertSame('CHANGED', $rows[0]['name'], 'row 1 was not updated');
        $this->assertSame('two', $rows[1]['name'], 'row 2 was touched');
    }

    public function testNegativeUpdateWithoutAFilterOrPrimaryKeyRaises(): void
    {
        $before = $this->rows();
        $threw = false;
        try {
            $this->db->update('t', ['name' => 'NOPK']);
        } catch (\Throwable $e) {
            $threw = true;
            $this->assertStringContainsStringIgnoringCase('filter', $e->getMessage());
        }
        $this->assertTrue($threw, 'a filterless update did not raise');
        $this->assertSame($before, $this->rows(), 'a filterless update modified rows');
    }

    // --- pair 2: affected rows -------------------------------------------

    public function testUpdateReportsTheRowsItChanged(): void
    {
        $result = $this->db->update('t', ['name' => 'X'], 'id = ?', [1]);
        $this->assertSame(1, $result->affectedRows);
    }

    public function testNegativeUpdateNeverReportsZeroWhenAMatchingRowExists(): void
    {
        $result = $this->db->update('t', ['id' => 2, 'name' => 'TWO']);
        $this->assertNotSame(0, $result->affectedRows);
    }

    // --- pair 3: delete filter forms -------------------------------------

    public function testDeleteAcceptsAKeyValueFilter(): void
    {
        $this->db->delete('t', ['id' => 2]);
        $this->assertCount(1, $this->rows());
    }

    public function testDeleteAcceptsAStringFilterWithParams(): void
    {
        $this->db->delete('t', 'id = ?', [2]);
        $this->assertCount(1, $this->rows());
    }

    // --- pair 4: no accidental truncate ----------------------------------

    public function testTruncateRemovesEveryRow(): void
    {
        $this->db->truncate('t');
        $this->assertSame([], $this->rows());
    }

    public function testNegativeDeleteWithoutAFilterRaises(): void
    {
        $before = $this->rows();
        $threw = false;
        try {
            $this->db->delete('t');
        } catch (\Throwable $e) {
            $threw = true;
            $this->assertStringContainsStringIgnoringCase('filter', $e->getMessage());
        }
        $this->assertTrue($threw, 'a filterless delete did not raise');
        $this->assertSame($before, $this->rows(), 'a filterless delete removed rows');
    }

    // --- pair 5: write result contract -----------------------------------

    public function testInsertReportsALastId(): void
    {
        $result = $this->db->insert('t', ['name' => 'three']);
        $this->assertNotNull($result->lastId);
    }

    public function testNegativeUpdateAndDeleteDoNotReportALastId(): void
    {
        $this->assertNull($this->db->update('t', ['id' => 1, 'name' => 'CHANGED'])->lastId);
        $this->assertNull($this->db->delete('t', ['id' => 2])->lastId);
    }

    // --- primary-key introspection ---------------------------------------

    public function testPrimaryKeyIsIntrospectedRatherThanAssumed(): void
    {
        $this->assertSame(['id'], $this->db->primaryKey('t'));
    }

    // --- composite primary keys ------------------------------------------
    // A PK resolver returning only the FIRST key column reintroduces the whole
    // data-loss bug: WHERE order_id = 1 matches every row in that order.

    private function compositeDb(): Database
    {
        $db = Database::create('sqlite:///' . $this->dir . '/composite.db');
        $db->execute(
            'CREATE TABLE order_items (order_id INTEGER, product_id INTEGER, qty INTEGER,'
            . ' PRIMARY KEY (order_id, product_id))'
        );
        $db->insert('order_items', ['order_id' => 1, 'product_id' => 5, 'qty' => 1]);
        $db->insert('order_items', ['order_id' => 1, 'product_id' => 6, 'qty' => 2]);
        $db->insert('order_items', ['order_id' => 2, 'product_id' => 5, 'qty' => 3]);
        return $db;
    }

    /** @return array<int, array<string, mixed>> */
    private function items(Database $db): array
    {
        return $db->fetch('SELECT * FROM order_items ORDER BY order_id, product_id')->toArray();
    }

    public function testCompositePrimaryKeyReturnsEveryKeyColumn(): void
    {
        $this->assertSame(['order_id', 'product_id'], $this->compositeDb()->primaryKey('order_items'));
    }

    public function testNegativeCompositeKeyNeverCollapsesToTheFirstColumn(): void
    {
        $this->assertCount(2, $this->compositeDb()->primaryKey('order_items'));
    }

    public function testACompositeKeyedUpdateTouchesExactlyOneRow(): void
    {
        $db = $this->compositeDb();
        $db->update('order_items', ['order_id' => 1, 'product_id' => 5, 'qty' => 99]);
        $rows = $this->items($db);
        $this->assertSame(99, (int)$rows[0]['qty'], 'target row not updated');
        $this->assertSame(2, (int)$rows[1]['qty'], 'sibling row in the same order was touched');
        $this->assertSame(3, (int)$rows[2]['qty'], 'a different order was touched');
    }

    public function testNegativeAPartialCompositeKeyRaisesRatherThanMatchingMany(): void
    {
        $db = $this->compositeDb();
        $before = $this->items($db);
        $threw = false;
        try {
            $db->update('order_items', ['order_id' => 1, 'qty' => 42]);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'a partial composite key did not raise');
        $this->assertSame(
            $before,
            $this->items($db),
            'a partial composite key modified rows - it matched on the first column'
        );
    }
}
