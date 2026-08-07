<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\DatabaseResult;

/**
 * Feature 18 (paginated results) contract — ADR-0043.
 *
 * The paginate envelope is EXACTLY seven snake_case keys derived from the query
 * that ran: records, total, page, per_page, total_pages, limit, offset. The
 * method takes NO arguments; passing one raises. Measured 2026-08-05 on a real
 * 250-row table (limit=20 offset=40, page 3 of 13) only PHP was correct on all
 * five values - Ruby returned ZERO records for a valid page, Ruby+Node reported
 * total=20 (rows returned) instead of 250, Python+Node reported page=1 ignoring
 * the offset. These five tests pin the settled contract so it cannot drift again.
 *
 * Real SQLite, a real 250-row table, no mocks and no doubles - the COUNT probe,
 * the derivation and the envelope are all exercised end to end through the live
 * fetch path. Each method name normalises to its pagination_contract.json
 * invariant id (lowercase, strip non-alphanumerics, strip a leading "test").
 */
final class PaginationContractTest extends TestCase
{
    private const TOTAL_ROWS = 250;

    private Database $db;

    protected function setUp(): void
    {
        // Real SQLite, a real 250-row table. No fixtures, no doubles.
        $this->db = Database::create('sqlite::memory:');
        $this->db->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        for ($i = 1; $i <= self::TOTAL_ROWS; $i++) {
            $this->db->insert('items', ['id' => $i, 'name' => 'item ' . $i]);
        }
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    /**
     * Fetch page 3 for real: limit 20, offset 40, against the 250-row table.
     * The framework's fetch() runs a COUNT probe, so ->count is the true 250
     * while ->records holds only the 20 rows of this page (ids 41-60).
     */
    private function pageThree(): DatabaseResult
    {
        return $this->db->fetch('SELECT id, name FROM items ORDER BY id', [], 20, 40);
    }

    /**
     * Fetch the WHOLE 250-row set (records count == count == 250). This is the
     * fixture that makes testPaginateTakesNoArguments a genuine red-before-green
     * gate: the pre-ADR code refused an argument ONLY on a partial result, so on
     * a whole set it silently sliced in memory instead of raising. The canonical
     * rule raises for ANY argument regardless.
     */
    private function wholeSet(): DatabaseResult
    {
        return $this->db->fetch('SELECT id, name FROM items ORDER BY id', [], self::TOTAL_ROWS, 0);
    }

    // invariant: paginate-takes-no-arguments
    public function testPaginateTakesNoArguments(): void
    {
        // Passing ANY argument is a hard error, never a silent reinterpretation
        // (ADR-0043): a DatabaseResult holds no connection, so an argument could
        // only re-slice the rows already in memory and lie about total_pages.
        // Exercised on a WHOLE-set result - the case the pre-ADR code silently
        // sliced instead of refusing.
        $this->expectException(\InvalidArgumentException::class);
        $this->wholeSet()->toPaginate(2, 10);
    }

    // invariant: paginate-page-is-derived-from-the-offset
    public function testPaginatePageIsDerivedFromTheOffset(): void
    {
        $envelope = $this->pageThree()->toPaginate();

        $this->assertSame(3, $envelope['page'], 'page = floor(offset 40 / limit 20) + 1 = 3');
        $this->assertSame(20, $envelope['per_page'], 'per_page is the query limit');
        $this->assertSame(40, $envelope['offset'], 'offset is the SQL offset actually applied');
        $this->assertSame(20, $envelope['limit'], 'limit is the SQL limit actually applied');
    }

    // invariant: paginate-total-is-the-true-total
    public function testPaginateTotalIsTheTrueTotal(): void
    {
        $envelope = $this->pageThree()->toPaginate();

        $this->assertSame(250, $envelope['total'], 'total is the COUNT probe (250), never the rows returned (20)');
        $this->assertSame(13, $envelope['total_pages'], 'total_pages = ceil(250 / 20) = 13');
    }

    // invariant: paginate-records-are-the-rows-the-query-returned
    public function testPaginateRecordsAreTheRowsTheQueryReturned(): void
    {
        $envelope = $this->pageThree()->toPaginate();

        $this->assertCount(20, $envelope['records'], 'a 20-row page carries all 20 rows, never re-sliced');
        $this->assertSame(41, $envelope['records'][0]['id'], 'first row of page 3 is id 41 (offset 40 skips ids 1-40)');
        $this->assertSame(60, $envelope['records'][19]['id'], 'last row of page 3 is id 60');
    }

    // invariant: paginate-key-set-is-identical-in-all-four
    public function testPaginateKeySetIsIdenticalInAllFour(): void
    {
        $envelope = $this->pageThree()->toPaginate();

        $keys = array_keys($envelope);
        sort($keys);
        $expected = ['records', 'total', 'page', 'per_page', 'total_pages', 'limit', 'offset'];
        sort($expected);

        // EXACTLY the seven canonical snake_case keys: no data (alias of records),
        // no count (alias of total), no camelCase totalPages/perPage, no duplicates.
        $this->assertSame($expected, $keys, 'envelope is exactly the seven canonical snake_case keys');
        $this->assertCount(7, $envelope, 'no duplicate spellings inflate the envelope');
    }
}
