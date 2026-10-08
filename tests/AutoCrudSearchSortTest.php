<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 *
 * ADR-0094: the AutoCrud list endpoint honours ?search= and ?sort=/?sort_dir=
 * so the CRUD admin grid can filter and order through AutoCrud alone (to_crud
 * owns no routes). Real SQLite, real Request, real Router::dispatch — no mocks.
 */

use PHPUnit\Framework\TestCase;
use Tina4\AutoCrud;
use Tina4\Database\SQLite3Adapter;
use Tina4\ORM;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class SearchSortItem extends ORM
{
    public string $tableName = 'searchsortitems';
    public string $primaryKey = 'id';
    public ?int $id = null;
    public ?string $name = null;
    public ?int $price = 0;
}

class AutoCrudSearchSortTest extends TestCase
{
    private SQLite3Adapter $db;

    protected function setUp(): void
    {
        Router::clear();
        $this->db = new SQLite3Adapter(':memory:');
        $this->db->exec('CREATE TABLE searchsortitems (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, price INTEGER DEFAULT 0)');
        ORM::bindDatabase($this->db);
        foreach ([['Widget', 100], ['Gadget', 200], ['Doohickey', 50]] as [$name, $price]) {
            $this->db->exec('INSERT INTO searchsortitems (name, price) VALUES (:n, :p)', [':n' => $name, ':p' => $price]);
        }
        $crud = new AutoCrud($this->db);
        $crud->register(SearchSortItem::class);
        $crud->generateRoutes();
    }

    protected function tearDown(): void
    {
        Router::clear();
        $this->db->close();
    }

    /** @return array<string, mixed> */
    private function list(string $query): array
    {
        $req = Request::create(method: 'GET', path: '/api/searchsortitems?' . $query);
        $res = Router::dispatch($req, new Response(true));
        return $res->getJsonBody();
    }

    /** @return array<int, string> */
    private function names(array $body): array
    {
        return array_map(static fn (array $r): string => $r['name'], $body['records']);
    }

    public function testSearchFiltersAcrossStringColumns(): void
    {
        $body = $this->list('search=Widget');
        $this->assertSame(['Widget'], $this->names($body));
        // The envelope total reflects the FILTERED count, not the table size.
        $this->assertSame(1, $body['total']);
    }

    public function testSearchReturnsNothingForNonMatch(): void
    {
        $body = $this->list('search=zzzznope');
        $this->assertSame([], $body['records']);
        $this->assertSame(0, $body['total']);
    }

    public function testSortDescending(): void
    {
        $this->assertSame(['Widget', 'Gadget', 'Doohickey'], $this->names($this->list('sort=name&sort_dir=desc')));
    }

    public function testSortAscending(): void
    {
        $this->assertSame(['Doohickey', 'Gadget', 'Widget'], $this->names($this->list('sort=name&sort_dir=asc')));
    }

    public function testCombinesSearchWithSort(): void
    {
        $body = $this->list('search=get&sort=price&sort_dir=desc');
        // Widget (100) and Gadget (200) both contain 'get'; Doohickey does not.
        $this->assertSame(['Gadget', 'Widget'], $this->names($body));
        $this->assertSame(2, $body['total']);
    }
}
