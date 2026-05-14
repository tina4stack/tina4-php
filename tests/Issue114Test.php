<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Regression test for tina4-php#114 — ORM->save() silently swallowed
 * update()/insert() failures.
 *
 * The bug: save() called update()/insert() but ignored their bool
 * return value — it only caught exceptions. The PHP adapter's exec()
 * returns `false` on a bad statement instead of throwing, so a failed
 * UPDATE (e.g. one referencing a public model property with no
 * matching DB column) slipped through: the empty transaction got
 * committed and save() returned $this — the documented success
 * signal. Callers believed the row persisted when nothing changed.
 * Silent data loss.
 *
 * The fix: save() now checks the bool return of update()/insert(),
 * rolls back and returns false on a falsy result — honouring the
 * documented `save(): static|false` contract.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;

class Issue114Test extends TestCase
{
    private SQLite3Adapter $db;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        $this->db->exec("
            CREATE TABLE reservation (
                id           INTEGER PRIMARY KEY,
                status       TEXT,
                is_confirmed INTEGER DEFAULT 0
            )
        ");
        $this->db->exec(
            "INSERT INTO reservation (id, status, is_confirmed) VALUES (1, 'PAID', 0)"
        );
        \Tina4\ORM::setGlobalDb($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    public function testSaveReturnsFalseWhenUpdateHitsUnknownColumn(): void
    {
        // A model with a public property (`computedLabel`) that is NOT
        // a real DB column. getDbData() includes every public property,
        // so the generated UPDATE references a non-existent column and
        // the adapter's exec() returns false.
        $model = new Issue114Reservation($this->db);
        $model->id = 1;                          // existing row → UPDATE path
        $model->status = 'CANCELLED';
        $model->computedLabel = 'not a column';  // ← the trap

        $result = $model->save();

        $this->assertFalse(
            $result,
            'save() must return false when the underlying UPDATE fails — '
            . 'returning $this on a failed write is silent data loss (#114).'
        );

        // The row must be unchanged — the failed UPDATE was rolled back.
        $row = $this->db->fetchOne("SELECT status FROM reservation WHERE id = 1");
        $this->assertSame(
            'PAID',
            $row['status'],
            'A failed save() must roll back — the row should be untouched.'
        );
    }

    public function testSaveStillSucceedsForValidUpdate(): void
    {
        // Control case: a model with only real columns saves cleanly
        // and returns $this.
        $model = new Issue114ValidReservation($this->db);
        $model->id = 1;
        $model->status = 'CONFIRMED';

        $result = $model->save();

        $this->assertInstanceOf(
            Issue114ValidReservation::class,
            $result,
            'A valid save() must still return the model instance.'
        );

        $row = $this->db->fetchOne("SELECT status FROM reservation WHERE id = 1");
        $this->assertSame('CONFIRMED', $row['status']);
    }

    public function testSaveStillSucceedsForValidInsert(): void
    {
        $model = new Issue114ValidReservation($this->db);
        $model->status = 'NEW';

        $result = $model->save();

        $this->assertInstanceOf(Issue114ValidReservation::class, $result);
        $this->assertNotNull(
            $model->getPrimaryKeyValue(),
            'insert() should backfill the primary key.'
        );
    }
}

/**
 * Model with a non-DB public property — reproduces the #114 trigger.
 */
class Issue114Reservation extends \Tina4\ORM
{
    public string $tableName  = 'reservation';
    public string $primaryKey = 'id';
    public bool   $autoMap    = true;

    public ?int    $id          = null;
    public ?string $status      = null;
    public ?int    $isConfirmed = null;

    /** Not a DB column — the trap. */
    public ?string $computedLabel = null;
}

/**
 * Clean model — every public property maps to a real column.
 */
class Issue114ValidReservation extends \Tina4\ORM
{
    public string $tableName  = 'reservation';
    public string $primaryKey = 'id';
    public bool   $autoMap    = true;

    public ?int    $id          = null;
    public ?string $status      = null;
    public ?int    $isConfirmed = null;
}
