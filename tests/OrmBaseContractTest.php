<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * ORM base class contract -- feature 17 (ORM17-DEC-01).
 *
 * Shared conformance fixture:
 *   tina4-documentation/plan/v3/fixtures/ormbase_contract.json
 *
 * The SAFETY NET for the ORM17-DEC-01 vestigial-state cleanup. In PHP the
 * removals are: the `tableFilter` read in count() (it was NEVER declared on the
 * base class, so it routed through __get -> null and was always skipped) plus its
 * two frameworkProps exclusion-list entries, and the dead local `$pkColumn` in
 * delete() (an unread leftover from the pkWhere refactor). PHP's `_exists` STAYS
 * -- it is READ in save() (the insert-vs-update shortcut for a finder-loaded
 * instance), and this suite exercises exactly that path.
 *
 * The removal is BEHAVIOUR-PRESERVING, so this real round-trip (save -> load ->
 * query -> re-save/update -> count -> delete) passes IDENTICALLY before AND after
 * it. NO mocks: a REAL in-memory SQLite database, real rows, real ORM writes.
 * Positive: a save loads back with identical values, count() is the live total
 * (guards the removed tableFilter read), where() finds by a non-key column, a
 * re-save UPDATEs in place. Negative: the re-save does not duplicate, and a
 * deleted model is ABSENT from findById() and count().
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;

class BaseWidgetModel extends \Tina4\ORM
{
    public string $tableName = 'basewidget';
    public string $primaryKey = 'id';
    public int $id = 0;
    public string $name = '';
    public int $qty = 0;
}

class OrmBaseContractTest extends TestCase
{
    private SQLite3Adapter $db;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        $this->db->exec(
            'CREATE TABLE basewidget (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, qty INTEGER)'
        );
        \Tina4\ORM::bindDatabase($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    private function newWidget(string $name, int $qty): BaseWidgetModel
    {
        $widget = new BaseWidgetModel($this->db);
        $widget->name = $name;
        $widget->qty = $qty;
        $widget->save();
        return $widget;
    }

    // ── Invariant: write/read round-trip; no duplicate on re-save ───────────

    public function testASavedModelLoadsBackWithIdenticalValues(): void
    {
        $widget = new BaseWidgetModel($this->db);
        $widget->name = 'alpha';
        $widget->qty = 7;
        $this->assertNotFalse($widget->save());       // save() returns $this, never false
        $this->assertGreaterThan(0, $widget->id);     // auto-increment PK adopted

        $loaded = BaseWidgetModel::findById($widget->id);
        $this->assertNotNull($loaded);
        $this->assertSame('alpha', $loaded->name);
        $this->assertSame(7, $loaded->qty);
    }

    public function testCountReturnsTheLiveRowTotal(): void
    {
        // Guards PHP's removed tableFilter read: count() is exactly the row total.
        $this->newWidget('w0', 0);
        $this->newWidget('w1', 1);
        $this->newWidget('w2', 2);
        $this->assertSame(3, (new BaseWidgetModel($this->db))->count());
    }

    public function testWhereReturnsARowMatchedByANonKeyColumn(): void
    {
        $this->newWidget('alpha', 1);
        $this->newWidget('beta', 2);

        $rows = (new BaseWidgetModel($this->db))->where('name = ?', ['beta']);
        $this->assertCount(1, $rows);
        $this->assertSame('beta', $rows[0]->name);
        $this->assertSame(2, $rows[0]->qty);
    }

    public function testAReSavedModelUpdatesInPlaceNotADuplicate(): void
    {
        // A finder-loaded instance re-saved must UPDATE (via the _exists shortcut
        // we KEEP), not INSERT a duplicate.
        $this->newWidget('alpha', 1);
        $this->assertSame(1, (new BaseWidgetModel($this->db))->count());

        $loaded = BaseWidgetModel::findById(1);
        $loaded->qty = 99;
        $this->assertNotFalse($loaded->save());

        $this->assertSame(1, (new BaseWidgetModel($this->db))->count());  // UPDATE, not a 2nd INSERT
        $this->assertSame(99, BaseWidgetModel::findById(1)->qty);         // the change persisted
    }

    // ── Invariant: delete removes the row (negative absence) ────────────────

    public function testADeletedModelIsAbsentFromFindAndCount(): void
    {
        // Guards the dead-local ($pkColumn) removal in delete().
        $this->newWidget('alpha', 1);
        $this->assertSame(1, (new BaseWidgetModel($this->db))->count());

        $loaded = BaseWidgetModel::findById(1);
        $this->assertTrue($loaded->delete());

        $this->assertNull(BaseWidgetModel::findById(1));
        $this->assertSame(0, (new BaseWidgetModel($this->db))->count());
    }
}
