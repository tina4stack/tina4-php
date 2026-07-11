<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * #165 — an INSERT must OMIT a column the caller never assigned so a
 * `NOT NULL DEFAULT <x>` column gets its DB default, while still writing NULL
 * for a column the caller explicitly set to null.
 *
 * Before the fix, ORM::save() serialised EVERY declared column on INSERT,
 * including ones the caller never touched (value null), emitting an explicit
 * `NULL`. A DB DEFAULT applies only when the column is OMITTED, not when NULL is
 * passed — so a `NOT NULL DEFAULT ''` / `NOT NULL DEFAULT 0` column made the
 * INSERT fail. v2 omitted unset columns; v3 now does too.
 *
 * The distinction locked in here (positive AND negative):
 *   - a column left UNSET  -> omitted -> DB default applies  (INSERT succeeds)
 *   - a column set to null -> written -> explicit NULL       (fails a NOT NULL col)
 *
 * Mirrors tina4-python/tests/test_orm_null_for_unset.py (the Python master).
 *
 * NOT a mock: a real SQLite3Adapter, real DDL with real DEFAULT constraints,
 * real save()/reload round-trips.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;
use Tina4\ORM;

// ── Test Models ──────────────────────────────────────────────────────────────

/**
 * The dominant PHP idiom: typed nullable columns WITH a null default. label and
 * quantity are NOT NULL DEFAULT at the DB; note is nullable (to show
 * explicit-null -> NULL is accepted where the column allows it).
 */
class Widget165 extends ORM
{
    public string $tableName  = 'widget165';
    public string $primaryKey = 'id';

    public ?int    $id       = null;
    public ?string $label    = null;
    public ?int    $quantity = null;
    public ?string $note     = null;
}

/**
 * Same table, but `quantity` is a typed property with NO default (`public
 * ?int $quantity;`). An uninitialised no-default property is skipped entirely,
 * so once it holds a null value it can only have been ASSIGNED — a direct
 * `$w->quantity = null` must therefore be written as NULL, not omitted. This is
 * how PHP recovers the assignment signal that a direct set to a declared public
 * property otherwise loses (there is no __set hook for it).
 */
class Widget165NoDefaultQuantity extends ORM
{
    public string $tableName  = 'widget165';
    public string $primaryKey = 'id';

    public ?int    $id    = null;
    public ?string $label = null;
    public ?int    $quantity;
    public ?string $note  = null;
}

/**
 * A model with a non-null ORM-level default on `label`. The default is written,
 * not omitted (the omission targets unset-AND-null columns only), so
 * static/callable ORM defaults do not regress.
 */
class Widget165Defaulted extends ORM
{
    public string $tableName  = 'widget165';
    public string $primaryKey = 'id';

    public ?int    $id       = null;
    public ?string $label    = 'from-orm';   // non-null ORM default
    public ?int    $quantity = null;
    public ?string $note     = null;
}

class OrmNullForUnsetTest extends TestCase
{
    private SQLite3Adapter $db;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        // DDL owns the DEFAULT constraints the ORM must respect. label/quantity
        // are NOT NULL DEFAULT; note is nullable.
        $this->db->exec(
            'CREATE TABLE widget165 (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                label    TEXT    NOT NULL DEFAULT \'\',
                quantity INTEGER NOT NULL DEFAULT 0,
                note     TEXT
            )'
        );
        ORM::bindDatabase($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    private function row(int|string $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM widget165 WHERE id = ?', [$id]);
    }

    private function rowCount(): int
    {
        $r = $this->db->fetchOne('SELECT COUNT(*) AS cnt FROM widget165');
        return (int) ($r['cnt'] ?? 0);
    }

    // ── Positive: unset columns fall through to the DB default ──────────────

    /**
     * A model with NOTHING assigned inserts successfully and every column shows
     * its DB default (the empty-insert -> DEFAULT VALUES path).
     */
    public function testAllColumnsUnsetInsertsWithDbDefaults(): void
    {
        $w = new Widget165();
        $this->assertNotFalse($w->save(), 'save failed: ' . var_export($w->getError(), true));

        $row = $this->row($w->id);
        $this->assertNotNull($row, 'the row must exist');
        $this->assertSame('', $row['label'], "NOT NULL DEFAULT '' should apply to an unset column");
        $this->assertSame(0, (int) $row['quantity'], 'NOT NULL DEFAULT 0 should apply to an unset column');
        $this->assertNull($row['note']);
    }

    /**
     * Setting only `label` leaves `quantity` unset — it must get the DB default
     * 0, not an explicit NULL that violates NOT NULL.
     */
    public function testPartialUnsetColumnsUseDbDefault(): void
    {
        $w = new Widget165(['label' => 'hello']);
        $this->assertNotFalse($w->save(), 'save failed: ' . var_export($w->getError(), true));

        $row = $this->row($w->id);
        $this->assertSame('hello', $row['label']);
        $this->assertSame(0, (int) $row['quantity'], 'unset NOT NULL DEFAULT column must use its DB default');
        $this->assertNull($row['note']);
    }

    // ── Positive: an assigned value is written verbatim ─────────────────────

    public function testNormalValueIsWritten(): void
    {
        $w = new Widget165(['label' => 'widget', 'quantity' => 7]);
        $this->assertNotFalse($w->save(), 'save failed: ' . var_export($w->getError(), true));

        $row = $this->row($w->id);
        $this->assertSame('widget', $row['label']);
        $this->assertSame(7, (int) $row['quantity']);
    }

    // ── Positive: explicit null on a NULLABLE column writes NULL ────────────

    /**
     * `note` is nullable — assigning null explicitly must persist NULL (the
     * value IS written, it is not omitted), and quantity (unset) still gets its
     * DB default.
     */
    public function testExplicitNullOnNullableColumnWritesNull(): void
    {
        $w = new Widget165(['label' => 'x', 'note' => null]);
        $this->assertNotFalse($w->save(), 'save failed: ' . var_export($w->getError(), true));

        $row = $this->row($w->id);
        $this->assertNull($row['note']);
        $this->assertSame(0, (int) $row['quantity'], 'unset column still uses its DB default');
    }

    // ── Negative: explicit null IS written (as NULL), so it fails a NOT NULL
    //    column — proving the value is not silently swapped for the default ──

    /**
     * Setting `quantity = null` explicitly via the constructor data writes NULL,
     * which the NOT NULL column rejects — save() fails loud and no row lands.
     * Counterpart to the unset case: unset omits (default applies), explicit
     * null writes NULL.
     */
    public function testExplicitNullOnNotNullColumnFails(): void
    {
        $w = new Widget165(['label' => 'x', 'quantity' => null]);
        $this->assertFalse($w->save(), 'explicit null into a NOT NULL column must fail');
        $this->assertNotNull($w->getError());
        $this->assertSame(0, $this->rowCount(), 'no row should have landed');
    }

    /**
     * The assignment signal also survives a direct attribute set: on a model
     * whose `quantity` is a no-default typed property, `$w->quantity = null`
     * writes NULL (not omitted) and is rejected by the NOT NULL column.
     */
    public function testExplicitNullViaAttributeAssignmentFails(): void
    {
        $w = new Widget165NoDefaultQuantity(['label' => 'x']);
        $w->quantity = null; // explicit — must be written as NULL, not omitted
        $this->assertFalse($w->save(), 'explicit null via attribute must fail on NOT NULL');
        $this->assertSame(0, $this->rowCount());
    }

    // ── Regression guard: an ORM-level default (non-null) is still written ──

    /**
     * A field with an ORM default that resolves to a non-null value must still
     * be inserted (the omission only targets unset-AND-null columns), so
     * static/callable ORM defaults do not regress.
     */
    public function testOrmLevelDefaultIsStillWritten(): void
    {
        $w = new Widget165Defaulted();  // label unset by caller, but ORM default is non-null
        $this->assertNotFalse($w->save(), 'save failed: ' . var_export($w->getError(), true));

        $row = $this->row($w->id);
        $this->assertSame('from-orm', $row['label'], 'non-null ORM default must be written, not omitted');
        $this->assertSame(0, (int) $row['quantity'], 'unset NOT NULL DEFAULT column still uses its DB default');
    }
}
