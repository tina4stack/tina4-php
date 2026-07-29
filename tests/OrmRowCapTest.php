<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Lock-in contract: EVERY ORM read path that takes a $limit caps at 100 by default.
 *
 * Pagination is a default principle in Tina4: an un-paginated read of a table
 * that grew to a million rows is a memory and latency incident waiting for
 * production. Before this contract the family disagreed with ITSELF about the
 * number — PHP and Python capped all()/find() at 100 but select()/where()/
 * withTrashed()/cached()/scope at 20, Ruby's all/select/where passed
 * `limit: nil` and so returned EVERYTHING, and Node's all/select had no limit
 * parameter at all. Four frameworks, four answers, same method names.
 *
 * One number, everywhere: 100.
 *
 * These tests are deliberately BEHAVIOURAL, never source-reading. They insert
 * 150 rows and count what comes back, so the contract cannot be satisfied by a
 * signature that says 100 while the body ignores it, and it cannot rot into a
 * grep of the default value.
 *
 * Two paths are EXCLUDED from the cap, on purpose, each with its own test below
 * so the exclusion is a decision on the record rather than an oversight:
 *
 *   - QueryBuilder::get() — uncapped since v3.13.39. A silent default LIMIT 100
 *     there was a data-loss-on-read footgun: ->where(...)->get() dropped the
 *     101st row with no signal, because get() has no $limit parameter to make
 *     the cap visible. Re-adding it would re-introduce that exact bug.
 *   - fetchAll() — its name IS the request for every row.
 *
 * The distinction that reconciles the two groups: a path whose signature
 * ADVERTISES $limit caps at 100 (visible, documented, overridable in the call);
 * a path with NO limit parameter must never cap, because there the cap can only
 * ever be silent.
 *
 * Mirrors tina4-python/tests/test_orm_row_cap.py (the Python master).
 *
 * Engine-agnostic: in-memory SQLite, so it runs with no DB server.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;
use Tina4\ORM;
use Tina4\QueryBuilder;

/** A widget table that holds MORE rows than the cap. */
class CapWidget extends ORM
{
    public string $tableName  = 'cap_widgets';
    public string $primaryKey = 'id';
    public bool   $autoMap    = true;

    public ?int    $id        = null;
    public ?string $label     = null;
    public ?int    $isDeleted = null;
}

/** The same table, with soft-delete on, so withTrashed() has a real target. */
class CapWidgetSoft extends ORM
{
    public string $tableName  = 'cap_widgets';
    public string $primaryKey = 'id';
    public bool   $autoMap    = true;
    public bool   $softDelete = true;

    public ?int    $id        = null;
    public ?string $label     = null;
    public ?int    $isDeleted = null;
}

class OrmRowCapTest extends TestCase
{
    private const ROWS = 150;
    private const CAP  = 100;

    private SQLite3Adapter $db;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        $this->db->exec(
            'CREATE TABLE cap_widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT, is_deleted INTEGER DEFAULT 0)'
        );
        for ($i = 0; $i < self::ROWS; $i++) {
            $this->db->exec("INSERT INTO cap_widgets (label, is_deleted) VALUES ('w{$i}', 0)");
        }
        ORM::bindDatabase($this->db);

        // Sanity: the fixture really does exceed the cap, else every assertion
        // below would pass for the wrong reason.
        // SQLite3Adapter::fetch() returns the raw
        // ['data' => rows, 'total' => n, 'limit' => n, 'offset' => n] shape;
        // only Database::fetch() wraps that in a DatabaseResult.
        $seed = $this->db->fetch('SELECT COUNT(*) AS c FROM cap_widgets', [], 1);
        $this->assertSame(self::ROWS, (int)$seed['data'][0]['c'], 'fixture must exceed the cap');
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    // ── The default cap is 100 on every path that advertises $limit ───────────

    public function testAllDefaultsToOneHundred(): void
    {
        $this->assertCount(self::CAP, (new CapWidget())->all());
    }

    public function testFindDefaultsToOneHundred(): void
    {
        $this->assertCount(self::CAP, CapWidget::find([]));
    }

    public function testSelectDefaultsToOneHundred(): void
    {
        // Was 20. A caller asking for a page got a fifth of one.
        $this->assertCount(self::CAP, (new CapWidget())->select('SELECT * FROM cap_widgets'));
    }

    public function testWhereDefaultsToOneHundred(): void
    {
        $this->assertCount(self::CAP, (new CapWidget())->where('1=1'));
    }

    public function testWithTrashedDefaultsToOneHundred(): void
    {
        $this->assertCount(self::CAP, (new CapWidgetSoft())->withTrashed());
    }

    public function testCachedDefaultsToOneHundred(): void
    {
        $this->assertCount(self::CAP, (new CapWidget())->cached('SELECT * FROM cap_widgets'));
    }

    public function testScopeDefaultsToOneHundred(): void
    {
        (new CapWidget())->scope('every', '1=1');
        $this->assertCount(self::CAP, CapWidget::every());
    }

    public function testDatabaseFetchDefaultsToOneHundred(): void
    {
        // records(), NOT the total count — `count` is the TOTAL matching rows
        // (what pagination needs) and stays 150 regardless of truncation.
        $this->assertCount(self::CAP, $this->db->fetch('SELECT * FROM cap_widgets')['data']);
    }

    // ── The negative half: the cap is a DEFAULT, not a ceiling ────────────────
    // Without these, a hardcoded LIMIT 100 would satisfy every test above.

    public function testASmallerLimitIsHonoured(): void
    {
        $w = new CapWidget();
        $this->assertCount(7, $w->select('SELECT * FROM cap_widgets', [], 7));
        $this->assertCount(7, $w->where('1=1', [], 7));
        $this->assertCount(7, $w->all(7));
        $this->assertCount(7, (new CapWidgetSoft())->withTrashed('1=1', [], 7));
        $this->assertCount(7, $w->cached('SELECT * FROM cap_widgets', [], 60, 7));
    }

    public function testALargerLimitReachesPastTheCap(): void
    {
        $w = new CapWidget();
        $this->assertCount(self::ROWS, $w->select('SELECT * FROM cap_widgets', [], self::ROWS));
        $this->assertCount(self::ROWS, $w->where('1=1', [], self::ROWS));
        $this->assertCount(self::ROWS, $w->all(self::ROWS));
        $this->assertCount(
            self::ROWS,
            $this->db->fetch('SELECT * FROM cap_widgets', [], self::ROWS)['data']
        );
    }

    // ── The deliberate exclusions ────────────────────────────────────────────
    // A path with NO limit parameter must stay UNCAPPED: a cap the signature
    // cannot express is a silent cap, which is the footgun.

    public function testQueryBuilderGetReturnsEveryRow(): void
    {
        // v3.13.39 removed a silent LIMIT 100 here. If this goes red, it is back.
        $this->assertCount(self::ROWS, QueryBuilder::fromTable('cap_widgets', $this->db)->get()['data']);
    }

    public function testQueryBuilderHonoursAnExplicitLimit(): void
    {
        $this->assertCount(9, QueryBuilder::fromTable('cap_widgets', $this->db)->limit(9)->get()['data']);
    }
}
