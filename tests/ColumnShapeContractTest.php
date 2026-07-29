<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\SQLite3Adapter;

/**
 * Column-shape contract: the primary-key flag is named `primaryKey`, in BOTH
 * of PHP's column shapes.
 *
 * PHP had two shapes with two different names for one concept. The adapters
 * emitted `primary`; DatabaseResult::normalizeAdapterColumns() emitted
 * `primary_key` and translated between them. Neither matched the other, and the
 * published docs described only the second one -- so a developer reading
 * docs/php/05-database.md and calling $db->getColumns() got a key that did not
 * exist and a silent false.
 *
 * The name is "primary key", cased per language: snake_case in Python and Ruby,
 * camelCase in PHP and Node. That is the same rule the rest of the framework
 * follows, and it matches ORM::$primaryKey, which PHP already had.
 *
 * Every assertion runs against a REAL in-memory SQLite -- no doubles.
 *
 * Positive: `primaryKey` is present and correct on both shapes.
 * Negative: the retired spellings are GONE. Without the negative half this test
 * would pass on the old code the moment a compatibility alias was added, which
 * is exactly the ambiguity being removed.
 */
class ColumnShapeContractTest extends TestCase
{
    private SQLite3Adapter $db;
    private Database $facade;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        $this->db->exec(
            "CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT)"
        );
        // A composite key, so the flag is proved on more than one column.
        $this->db->exec(
            "CREATE TABLE order_items (order_id INTEGER NOT NULL, product_id INTEGER NOT NULL,
             qty INTEGER, PRIMARY KEY (order_id, product_id))"
        );

        // Shape 2 lives on the Database facade -- the adapter's own fetch()
        // returns a plain array, not a DatabaseResult.
        $this->facade = Database::create('sqlite::memory:');
        $this->facade->execute(
            "CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT)"
        );
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    /** @return array<string, array> name => column */
    private function byName(array $columns): array
    {
        $out = [];
        foreach ($columns as $col) {
            $out[$col['name']] = $col;
        }
        return $out;
    }

    // --- Shape 1: the adapter's own getColumns() -------------------------

    public function testAdapterGetColumnsNamesThePrimaryKeyFlagPrimaryKey(): void
    {
        $cols = $this->byName($this->db->getColumns('users'));

        $this->assertArrayHasKey('primaryKey', $cols['id'], 'adapter getColumns() must emit primaryKey');
        $this->assertTrue($cols['id']['primaryKey'], 'users.id is the primary key');
        $this->assertFalse($cols['name']['primaryKey'], 'users.name is not a primary key');
    }

    public function testAdapterGetColumnsNoLongerEmitsTheRetiredSpellings(): void
    {
        foreach ($this->db->getColumns('users') as $col) {
            $this->assertArrayNotHasKey(
                'primary',
                $col,
                "the retired 'primary' key must be gone from adapter getColumns() (column {$col['name']})"
            );
            $this->assertArrayNotHasKey(
                'primary_key',
                $col,
                "adapter getColumns() uses camelCase in PHP, not primary_key (column {$col['name']})"
            );
        }
    }

    public function testAdapterGetColumnsFlagsEveryColumnOfACompositeKey(): void
    {
        $cols = $this->byName($this->db->getColumns('order_items'));

        // PRAGMA table_info returns `pk` as the 1-BASED POSITION in the key, not
        // a boolean -- a composite key gives pk=1, pk=2. Testing `pk == 1` here
        // would report a key one column wide.
        $this->assertTrue($cols['order_id']['primaryKey'], 'order_id is key column 1');
        $this->assertTrue($cols['product_id']['primaryKey'], 'product_id is key column 2');
        $this->assertFalse($cols['qty']['primaryKey'], 'qty is not part of the key');
    }

    // --- Shape 2: DatabaseResult's normalised columns --------------------
    //
    // This is the shape docs/php/05-database.md documents (it carries size and
    // decimals, which the raw adapter output does not).

    public function testDatabaseResultColumnInfoNamesTheFlagPrimaryKey(): void
    {
        // columnInfo() takes no argument -- it derives the table from the SQL.
        $cols = $this->byName($this->facade->fetch("SELECT * FROM users")->columnInfo());

        $this->assertArrayHasKey('primaryKey', $cols['id'], 'columnInfo() must emit primaryKey');
        $this->assertTrue($cols['id']['primaryKey']);
        $this->assertFalse($cols['email']['primaryKey']);

        // The rest of the documented shape is unchanged by this rename.
        foreach (['name', 'type', 'size', 'decimals', 'nullable'] as $key) {
            $this->assertArrayHasKey($key, $cols['id'], "documented key {$key} must survive");
        }
    }

    public function testDatabaseResultColumnInfoNoLongerEmitsTheRetiredSpellings(): void
    {
        foreach ($this->facade->fetch("SELECT * FROM users")->columnInfo() as $col) {
            $this->assertArrayNotHasKey(
                'primary_key',
                $col,
                "the retired 'primary_key' must be gone from columnInfo() (column {$col['name']})"
            );
            $this->assertArrayNotHasKey(
                'primary',
                $col,
                "the retired 'primary' must be gone from columnInfo() (column {$col['name']})"
            );
        }
    }

    public function testFallbackColumnInfoAlsoNamesTheFlagPrimaryKey(): void
    {
        // No adapter table to introspect (a computed-column query), so
        // columnInfo() falls back to deriving the shape from the record keys.
        // That path emits the flag too and must use the same spelling -- it was
        // a THIRD emitter of the old name.
        $cols = $this->facade->fetch("SELECT 1 + 1 AS total")->columnInfo();

        $this->assertNotEmpty($cols, 'the fallback path must still describe the columns');
        foreach ($cols as $col) {
            $this->assertArrayHasKey('primaryKey', $col, 'the fallback path must emit primaryKey');
            $this->assertFalse($col['primaryKey'], 'a computed column is not a primary key');
            $this->assertArrayNotHasKey('primary_key', $col);
            $this->assertArrayNotHasKey('primary', $col);
        }
    }

    // --- Source invariant: no adapter is left behind ---------------------
    //
    // Only SQLite runs in this suite without a live service, so a behavioural
    // test cannot reach the other nine adapters. The rename is invisible in a
    // green suite for those, so read their source instead -- the same reason
    // the process-hygiene test reads source.

    public function testNoAdapterStillEmitsTheRetiredPrimaryKeyName(): void
    {
        $dir = __DIR__ . '/../Tina4/Database';
        $offenders = [];

        foreach (glob($dir . '/*.php') as $file) {
            $src = file_get_contents($file);
            // Match the ARRAY-KEY position only: "'primary' =>" (any spacing).
            // A prose mention of the words "primary key" in a comment is fine.
            if (preg_match("/'primary'\s*=>/", $src) || preg_match("/'primary_key'\s*=>/", $src)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'these adapters still emit a retired primary-key name: ' . implode(', ', $offenders)
        );
    }
}
