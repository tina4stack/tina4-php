<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * tina4stack/tina4-python#57 — a bad write must FAIL LOUD, not silently drop.
 *
 * The reporter bound a PHP/Python boolean to an INTEGER column, never checked
 * the return of execute(), called commit(), and got an empty table. The root
 * cause was a pre-3.13.38 execute() that SWALLOWED the driver exception and
 * returned false. The v3 fail-loud contract (parity with the Python master and
 * with fetch()/fetchOne()) makes execute() RAISE instead — the cause is still
 * captured on getError(). No code change is needed at HEAD; this is the missing
 * lock-in test.
 *
 * PHP-specific mechanism (verified against real PostgreSQL 16): the adapter
 * normalises a bound bool via SqlNormalizerTrait to PG's boolean literal
 * ('t'/'f'), and PostgreSQL then rejects 't' for an INTEGER column with
 * `invalid input syntax for type integer`. (Python's psycopg2 instead adapts
 * True -> SQL `true` and PG raises DatatypeMismatch — a different error, same
 * fail-loud outcome.) Either way execute() must THROW, getError() must be
 * populated, and the table must stay EMPTY.
 *
 * SQLite cannot catch this (dynamically typed — it stores 't' happily), so the
 * test MUST run on real PostgreSQL. PG-gated: skipped when ext-pgsql is missing
 * or PostgreSQL is unreachable, matching BooleanParamBindingTest /
 * ExecuteFailurePostgresTest. No mock — a real PostgreSQL connection throughout.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

class Issue57BoolToIntegerPostgresTest extends TestCase
{
    private const PG_DB = 'tina4_php';
    private const TABLE = 't4_issue57_bool_int';

    private ?Database $db = null;

    protected function setUp(): void
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('PostgresAdapter requires ext-pgsql.');
        }
        $pg = \PgTestEnv::resolve();
        if (!$pg->reachable()) {
            $this->markTestSkipped(sprintf('PostgreSQL not reachable at %s:%d — skip', $pg->host, $pg->port));
        }
        $this->db = Database::create(
            $pg->url(self::PG_DB),
            username: $pg->user,
            password: $pg->pass,
            autoCommit: true
        );
        $this->db->execute('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->db->execute('CREATE TABLE ' . self::TABLE . ' (id SERIAL PRIMARY KEY, qty INTEGER NOT NULL)');
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            $this->db->execute('DROP TABLE IF EXISTS ' . self::TABLE);
        }
    }

    /**
     * The exact #57 shape: bind a PHP `true` to an INTEGER column. execute()
     * MUST raise (never silently return false), getError() must carry the cause,
     * and the table must stay EMPTY — the write was rejected, not half-applied.
     */
    public function testBoolBoundToIntegerColumnRaisesAndLeavesTableEmpty(): void
    {
        $threw = false;
        try {
            $this->db->execute('INSERT INTO ' . self::TABLE . ' (qty) VALUES (?)', [true]);
        } catch (\Throwable $e) {
            $threw = true;
            // Cause captured on getError() even after the throw (the #57 contract).
            $this->assertNotNull($this->db->getError(), 'getError() must carry the cause after a failed write');
            $this->assertStringContainsStringIgnoringCase('integer', (string) $this->db->getError());
        }

        $this->assertTrue($threw, '#57: binding a bool to an INTEGER column MUST fail loud (execute must raise)');

        // The write never landed — the table is still empty. This is the empty
        // table the reporter saw, except now it is impossible to miss: the
        // exception fires instead of a silent false.
        $rows = $this->db->fetch('SELECT * FROM ' . self::TABLE);
        $this->assertCount(0, $rows->records, 'a rejected write must leave the table empty, not partially applied');
    }

    /**
     * Positive control on the SAME real table: a valid integer write succeeds
     * and is visible — proving the table/connection are healthy and the failure
     * above is specifically the bad bind, not a broken fixture.
     */
    public function testValidIntegerWriteSucceedsOnSameTable(): void
    {
        $this->assertTrue($this->db->execute('INSERT INTO ' . self::TABLE . ' (qty) VALUES (?)', [42]));
        $this->assertNull($this->db->getError(), 'a valid write must not set an error');

        $rows = $this->db->fetch('SELECT qty FROM ' . self::TABLE);
        $this->assertCount(1, $rows->records);
        $this->assertSame(42, (int) $rows->records[0]['qty']);
    }
}
