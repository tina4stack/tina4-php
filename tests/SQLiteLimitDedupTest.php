<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;

/**
 * SQLite adapter LIMIT handling: the default row cap, and no double-LIMIT.
 *
 * This class lived inside tests/DevAdminTest.php as a SECOND `extends TestCase`
 * declaration. PHPUnit 11 collects only the class whose name matches the file
 * basename, so it was never executed once — `--list-tests` returned zero matches
 * for it while the DevAdminTest class in the same file contributed 81 tests.
 * tests/ConfiguredTestFilesTest.php guards FILES, not CLASSES, so the hole was
 * invisible to CI. ClassCollectionTest now closes that, and the identical hole
 * found in WebSocketV3Test.php (WebSocketRoomsTest) is closed the same way.
 *
 * When it was finally run it FAILED, and the failure was the TEST being stale,
 * not the adapter:
 *
 *     Failed asserting that actual size 30 matches expected size 10.
 *
 * It asserted the adapter caps an uncapped SELECT at 10 rows. MEASURED
 * 2026-08-01 across all four frameworks, the default cap is 100 everywhere:
 *
 *     python  wrapper 100, adapter 100      ruby   wrapper 100 (driver has no fetch)
 *     php     wrapper 100, adapter 100      node   wrapper 100 (DEFAULT_ROW_CAP)
 *
 * and it is a settled decision, not an accident — plan/v3/DECISIONS-PENDING-REVIEW.md
 * records "ORM row cap = 100 -- DONE in all four". The value 10 appears nowhere in
 * v3 in any framework. So the assertion was corrected to the real contract rather
 * than relaxed to whatever the code happened to return: the fixture now holds 150
 * rows, MORE than the cap, so the test FAILS if the cap is removed, lowered, or
 * raised. A 30-row fixture asserting 30 would have passed with no cap at all.
 *
 * Real SQLite via ext-sqlite3 in :memory: — a real engine, no doubles.
 */
final class SQLiteLimitDedupTest extends TestCase
{
    /** The default row cap for a raw fetch() with no limit argument, all four frameworks. */
    private const DEFAULT_ROW_CAP = 100;

    /**
     * Build a real in-memory SQLite database holding $rows numbered items.
     *
     * @param int $rows How many rows to insert.
     * @return \Tina4\Database\SQLite3Adapter The open adapter; the caller closes it.
     */
    private function seededDb(int $rows): \Tina4\Database\SQLite3Adapter
    {
        $db = new \Tina4\Database\SQLite3Adapter(':memory:');
        $db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
        for ($i = 0; $i < $rows; $i++) {
            $db->exec("INSERT INTO items (id, name) VALUES ({$i}, 'item{$i}')");
        }

        return $db;
    }

    /**
     * SQL that already carries its own LIMIT must not get a second one appended.
     *
     * A naive "append LIMIT n" would produce `... LIMIT 3 LIMIT 100`, which is a
     * syntax error on SQLite, so a regression here fails loudly rather than
     * quietly returning the wrong count.
     */
    public function testFetchWithExistingLimitDoesNotDouble(): void
    {
        $db = $this->seededDb(10);

        $result = $db->fetch('SELECT * FROM items LIMIT 3');

        $this->assertCount(
            3,
            $result['data'],
            'an explicit LIMIT in the SQL must be honoured as-is, not doubled with the default cap'
        );

        $db->close();
    }

    /**
     * A raw fetch() with no limit argument caps at 100 rows.
     *
     * The fixture deliberately holds MORE rows than the cap (150 > 100) so the
     * assertion has teeth: with the cap removed this returns 150 and fails, and
     * with the cap set to any other number it also fails. The original 30-row
     * fixture could not distinguish "cap is 100" from "no cap at all".
     */
    public function testFetchWithoutLimitAppliesTheDefaultRowCap(): void
    {
        $db = $this->seededDb(150);

        $result = $db->fetch('SELECT * FROM items');

        $this->assertCount(
            self::DEFAULT_ROW_CAP,
            $result['data'],
            'a raw fetch() with no limit argument caps at ' . self::DEFAULT_ROW_CAP
            . ' rows in all four frameworks (plan/v3 decision "ORM row cap = 100")'
        );
        $this->assertSame(
            self::DEFAULT_ROW_CAP,
            $result['limit'],
            'the result must REPORT the cap it applied, so a caller can tell a capped page from a complete one'
        );

        $db->close();
    }

    /**
     * An explicit limit argument beats the default cap, in both directions.
     *
     * Pins that the cap is a DEFAULT and not a ceiling: asking for 25 gives 25,
     * and asking for 120 gives 120 even though that exceeds the default 100.
     */
    public function testExplicitLimitArgumentOverridesTheDefaultCap(): void
    {
        $db = $this->seededDb(150);

        $this->assertCount(25, $db->fetch('SELECT * FROM items', [], 25)['data'], 'an explicit smaller limit is honoured');
        $this->assertCount(120, $db->fetch('SELECT * FROM items', [], 120)['data'], 'the default cap is a DEFAULT, not a ceiling');

        $db->close();
    }
}
