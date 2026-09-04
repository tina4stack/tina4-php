<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Lock-in tests for ORM::where(orderBy) — v3.13.66 ORM where-ordering parity.
 *
 * where() was the only filtered finder that could not order its results
 * (find / all / QueryBuilder all could). These tests pin the new behaviour:
 *   * orderBy sorts the filtered result (ASC and DESC)
 *   * omitting orderBy injects NO ORDER BY (rows come back in natural order)
 *
 * Mirrors tina4-python/tests/test_orm_where_order_by.py (the Python master).
 * Real in-memory SQLite (SQLite3Adapter), no mocks, positive + negative. Rows
 * are inserted OUT OF alphabetical order so a missing/extra ORDER BY shows.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;
use Tina4\ORM;

class WhereOrderPerson extends ORM
{
    public string $tableName  = 'wpeople';
    public string $primaryKey = 'id';
    public bool   $autoMap    = true;

    public ?int    $id   = null;
    public ?string $name = null;
}

class OrmWhereOrderByTest extends TestCase
{
    private SQLite3Adapter $db;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        $this->db->exec('CREATE TABLE wpeople (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        // Out of alphabetical order: Charlie(id=1), Alice(id=2), Bob(id=3)
        $this->db->exec("INSERT INTO wpeople (name) VALUES ('Charlie')");
        $this->db->exec("INSERT INTO wpeople (name) VALUES ('Alice')");
        $this->db->exec("INSERT INTO wpeople (name) VALUES ('Bob')");
        ORM::bindDatabase($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    /** @param iterable<int, WhereOrderPerson> $rows @return array<int, string> */
    private function names(iterable $rows): array
    {
        // iterable so a where()/all() ModelCollection (ADR-0064) passes as-is
        // alongside a bare array; iterate rather than array_map (which needs one).
        $names = [];
        foreach ($rows as $r) {
            $names[] = $r->name;
        }
        return $names;
    }

    public function testOrderByAscSortsResults(): void
    {
        $rows = (new WhereOrderPerson($this->db))->where('1=1', [], 20, 0, null, 'name ASC');
        $this->assertSame(['Alice', 'Bob', 'Charlie'], $this->names($rows));
    }

    public function testOrderByDescReversesResults(): void
    {
        // id DESC -> 3, 2, 1 -> Bob, Alice, Charlie
        $rows = (new WhereOrderPerson($this->db))->where('1=1', [], 20, 0, null, 'id DESC');
        $this->assertSame(['Bob', 'Alice', 'Charlie'], $this->names($rows));
    }

    public function testWithoutOrderByIsUnchanged(): void
    {
        // negative: no orderBy -> no ORDER BY injected -> natural (insertion) order
        $rows = (new WhereOrderPerson($this->db))->where('1=1');
        $this->assertSame(['Charlie', 'Alice', 'Bob'], $this->names($rows));
    }
}
