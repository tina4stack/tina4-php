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
 * CrudData + CrudView (ADR-0094) — the two concerns split out of Crud. Real
 * SQLite, real ORM, real Frond template rendering. No mocks: every assertion
 * runs against the exact rows the query returns and the exact HTML bytes the
 * framework emits.
 */

use PHPUnit\Framework\TestCase;
use Tina4\CrudData;
use Tina4\CrudView;
use Tina4\Database\SQLite3Adapter;
use Tina4\ORM;

class CrudSplitTestModel extends ORM
{
    public string $tableName = 'crudsplitmodels';
    public string $primaryKey = 'id';
    public ?int $id = null;
    public ?string $name = null;
    public ?string $email = null;
    public ?int $age = 0;
}

class CrudSplitTest extends TestCase
{
    private SQLite3Adapter $db;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        $this->db->exec('CREATE TABLE crudsplitmodels (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT, age INTEGER DEFAULT 0)');
        $this->db->exec("INSERT INTO crudsplitmodels (name, email, age) VALUES ('Alice', 'alice@example.com', 30)");
        $this->db->exec("INSERT INTO crudsplitmodels (name, email, age) VALUES ('Bob', 'bob@example.com', 25)");
        $this->db->exec("INSERT INTO crudsplitmodels (name, email, age) VALUES ('Charlie', 'charlie@example.com', 35)");
        ORM::bindDatabase($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    // ---- CrudData: safe-sort resolution (ADR-0069) ------------------------

    public function testCrudSortColumnFallsBackToPrimaryKeyWhenUnset(): void
    {
        $model = new CrudSplitTestModel($this->db);
        $this->assertSame('id', CrudData::crudSortColumn($model, null, null, 'id'));
        $this->assertSame('id', CrudData::crudSortColumn($model, null, '', 'id'));
    }

    public function testCrudSortColumnResolvesAKnownModelField(): void
    {
        $model = new CrudSplitTestModel($this->db);
        $this->assertSame('name', CrudData::crudSortColumn($model, null, 'name', 'id'));
    }

    public function testCrudSortColumnRejectsAnUnknownFieldToThePrimaryKey(): void
    {
        $model = new CrudSplitTestModel($this->db);
        // ADR-0069: an unknown/injected sort never reaches ORDER BY — it is
        // forced back to the primary key.
        $this->assertSame('id', CrudData::crudSortColumn($model, null, 'name; DROP TABLE crudsplitmodels', 'id'));
    }

    // ---- CrudData: model-driven listing + search -------------------------

    public function testFetchModelDataReturnsEveryRowAndTheTrueTotal(): void
    {
        $model = new CrudSplitTestModel($this->db);
        [$records, $total] = CrudData::fetchModelData($model, ['name' => 'string', 'email' => 'string'], '', 'id', 'asc', 10, 0);
        $this->assertCount(3, $records);
        $this->assertSame(3, $total);
        $this->assertSame('Alice', $records[0]['name']);
    }

    public function testFetchModelDataSearchNarrowsAcrossStringColumns(): void
    {
        $model = new CrudSplitTestModel($this->db);
        [$records, $total] = CrudData::fetchModelData($model, ['name' => 'string', 'email' => 'string'], 'Alice', 'id', 'asc', 10, 0);
        $this->assertSame(1, $total);
        $this->assertCount(1, $records);
        $this->assertSame('Alice', $records[0]['name']);
    }

    public function testFetchModelDataHonoursSortDirection(): void
    {
        $model = new CrudSplitTestModel($this->db);
        [$records] = CrudData::fetchModelData($model, ['name' => 'string'], '', 'age', 'desc', 10, 0);
        $this->assertSame('Charlie', $records[0]['name']); // age 35 first when DESC
    }

    // ---- CrudView: HTML assembly (escaped, no inline styles) --------------

    public function testViewTableRendersRowsAndEscapesValues(): void
    {
        $records = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => '<script>x</script>'],
        ];
        $html = CrudView::table($records, 'users', 'id');
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('Alice', $html);
        // XSS: a tag in a value is escaped, never emitted live.
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
    }

    public function testViewFormRendersFields(): void
    {
        $fields = [
            ['name' => 'name', 'type' => 'string', 'label' => 'Full Name', 'required' => true],
            ['name' => 'email', 'type' => 'string', 'label' => 'Email'],
        ];
        $html = CrudView::form($fields, '/api/users', 'POST');
        $this->assertStringContainsString('<form', $html);
        $this->assertStringContainsString('Full Name', $html);
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringContainsString('name="email"', $html);
    }

    public function testViewRenderPageEmitsTheFullAdminPage(): void
    {
        $html = CrudView::renderPage(
            'Split Page',
            'crudsplitmodels',
            'id',
            ['id', 'name', 'email', 'age'],
            ['id' => 'int', 'name' => 'string', 'email' => 'string', 'age' => 'int'],
            [['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30]],
            1,
            1,
            1,
            10,
            '',
            'id',
            'asc',
            '/api/crudsplitmodels',
            '/admin/crudsplitmodels'
        );
        $this->assertStringContainsString('Split Page', $html);
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('Alice', $html);
        // numeric columns align right, text left; never inline style=
        $this->assertStringContainsString('class="text-end"', $html);
        $this->assertStringContainsString('class="text-start"', $html);
        $this->assertSame(0, preg_match_all('/\sstyle\s*=\s*["\']/', $html));
    }
}
