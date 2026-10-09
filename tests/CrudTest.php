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
 * Crud::toCrud (ADR-0094) — real SQLite, real Request, real Crud generator.
 * No mocks: the assertions run against the exact HTML bytes the framework emits
 * and the real routes AutoCrud registers.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Crud;
use Tina4\Database\SQLite3Adapter;
use Tina4\ORM;
use Tina4\Request;
use Tina4\Router;

class CrudTestModel extends ORM
{
    public string $tableName = 'crudtestmodels';
    public string $primaryKey = 'id';
    public ?int $id = null;
    public ?string $name = null;
    public ?string $email = null;
    public ?int $age = 0;
}

class CrudTest extends TestCase
{
    private SQLite3Adapter $db;

    protected function setUp(): void
    {
        Router::clear();
        Crud::clearRegistry();
        $this->db = new SQLite3Adapter(':memory:');
        $this->db->exec('CREATE TABLE crudtestmodels (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT, age INTEGER DEFAULT 0)');
        $this->db->exec("INSERT INTO crudtestmodels (name, email, age) VALUES ('Alice', 'alice@example.com', 30)");
        $this->db->exec("INSERT INTO crudtestmodels (name, email, age) VALUES ('Bob', 'bob@example.com', 25)");
        $this->db->exec("INSERT INTO crudtestmodels (name, email, age) VALUES ('Charlie', 'charlie@example.com', 35)");
        ORM::bindDatabase($this->db);
    }

    protected function tearDown(): void
    {
        Router::clear();
        Crud::clearRegistry();
        $this->db->close();
    }

    private function request(string $path = '/admin/crudtestmodels'): Request
    {
        return Request::create(method: 'GET', path: $path);
    }

    /** @return array<int, string> */
    private function routeStrings(): array
    {
        return array_map(
            static fn (array $r): string => "{$r['method']} {$r['pattern']}",
            Router::getRoutes()
        );
    }

    public function testGeneratesACompleteHtmlPage(): void
    {
        $html = Crud::toCrud($this->request(), ['model' => CrudTestModel::class, 'title' => 'Test CRUD']);
        $this->assertStringContainsString('Test CRUD', $html);
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('Alice', $html);
        $this->assertStringContainsString('Bob', $html);
        $this->assertStringContainsString('Charlie', $html);
    }

    public function testIncludesCreateEditDeleteModals(): void
    {
        $html = Crud::toCrud($this->request(), ['model' => CrudTestModel::class, 'title' => 'Test CRUD']);
        $this->assertStringContainsString('modal-create', $html);
        $this->assertStringContainsString('modal-edit', $html);
        $this->assertStringContainsString('modal-delete', $html);
        $this->assertStringContainsString('Create New Record', $html);
        $this->assertStringContainsString('Edit Record', $html);
        $this->assertStringContainsString('Confirm Delete', $html);
    }

    public function testIncludesLiveSearchInputNotASubmitButton(): void
    {
        $html = Crud::toCrud($this->request(), ['model' => CrudTestModel::class]);
        // ADR-0094 search is live/AJAX: a data-crud-search input, no Search button.
        $this->assertStringContainsString('data-crud-search', $html);
        $this->assertStringContainsString('placeholder="Search..."', $html);
        $this->assertStringNotContainsString('>Search</button>', $html);
    }

    public function testSortHeadersAndPagerDriveTheListEndpointOverAjax(): void
    {
        $html = Crud::toCrud($this->request(), ['model' => CrudTestModel::class, 'limit' => 2]);
        $this->assertStringContainsString('AbortController', $html);
        $this->assertStringContainsString('data-crud-body', $html);
        $this->assertStringContainsString('data-crud-sort=', $html);
        $this->assertStringContainsString('data-crud-page=', $html);
    }

    public function testAlignsNumericRightAndTextLeft(): void
    {
        $html = Crud::toCrud($this->request(), ['model' => CrudTestModel::class]);
        // age + id are integer fields -> text-end; name/email are text -> text-start.
        $this->assertStringContainsString('class="text-end"', $html);
        $this->assertStringContainsString('class="text-start"', $html);
        // no inline style= used for alignment
        $this->assertSame(0, preg_match_all('/\sstyle\s*=\s*["\']/', $html));
    }

    public function testIncludesPaginationInfo(): void
    {
        $html = Crud::toCrud($this->request(), ['model' => CrudTestModel::class]);
        $this->assertStringContainsString('Showing 3 of 3 records', $html);
    }

    public function testIncludesSortLinksInHeaders(): void
    {
        $html = Crud::toCrud($this->request(), ['model' => CrudTestModel::class]);
        $this->assertStringContainsString('sort=id', $html);
        $this->assertStringContainsString('sort=name', $html);
        $this->assertStringContainsString('sort=email', $html);
    }

    public function testIncludesJavaScriptForCrudOperations(): void
    {
        $html = Crud::toCrud($this->request(), ['model' => CrudTestModel::class]);
        $this->assertStringContainsString('crudShowCreate', $html);
        $this->assertStringContainsString('saveRecord', $html);
        $this->assertStringContainsString('crudConfirmDelete', $html);
        // A failed save surfaces the AutoCrud non-2xx {errors}/{error} inline and
        // keeps the modal open (it checks r.ok, not a false success).
        $this->assertStringContainsString('data-crud-errors', $html);
        $this->assertStringContainsString('showErrors', $html);
        $this->assertStringContainsString('res.ok', $html);
    }

    public function testRegistersTheFullAutoCrudBackend(): void
    {
        Crud::toCrud($this->request(), ['model' => CrudTestModel::class]);
        $routes = $this->routeStrings();
        // ADR-0094: toCrud delegates the ENTIRE backend to AutoCrud — all five
        // REST routes, incl. the GET list + GET/{id} the old sql-mode never had.
        $this->assertContains('GET /api/crudtestmodels', $routes);
        $this->assertContains('GET /api/crudtestmodels/{id}', $routes);
        $this->assertContains('POST /api/crudtestmodels', $routes);
        $this->assertContains('PUT /api/crudtestmodels/{id}', $routes);
        $this->assertContains('DELETE /api/crudtestmodels/{id}', $routes);
    }

    public function testDoesNotRegisterAnyBespokeWriteRoute(): void
    {
        Crud::toCrud($this->request(), ['model' => CrudTestModel::class]);
        $tableRoutes = array_values(array_filter(
            $this->routeStrings(),
            static fn (string $r): bool => str_contains($r, '/api/crudtestmodels')
        ));
        sort($tableRoutes);
        $this->assertSame([
            'DELETE /api/crudtestmodels/{id}',
            'GET /api/crudtestmodels',
            'GET /api/crudtestmodels/{id}',
            'POST /api/crudtestmodels',
            'PUT /api/crudtestmodels/{id}',
        ], $tableRoutes);
    }

    public function testIsIdempotent(): void
    {
        Crud::toCrud($this->request(), ['model' => CrudTestModel::class]);
        Crud::toCrud($this->request(), ['model' => CrudTestModel::class]);
        $count = count(array_filter(
            $this->routeStrings(),
            static fn (string $r): bool => str_contains($r, '/api/crudtestmodels')
        ));
        $this->assertSame(5, $count);
    }

    public function testCustomSqlShapesGridWhileModelDrivesBackend(): void
    {
        $html = Crud::toCrud($this->request(), [
            'model' => CrudTestModel::class,
            'sql' => 'SELECT id, name, email FROM crudtestmodels',
            'title' => 'SQL CRUD',
        ]);
        $this->assertStringContainsString('SQL CRUD', $html);
        $this->assertStringContainsString('Alice', $html);
        $this->assertStringContainsString('Bob', $html);
        // The backend still comes from the model via AutoCrud.
        $routes = $this->routeStrings();
        $this->assertContains('GET /api/crudtestmodels', $routes);
        $this->assertContains('POST /api/crudtestmodels', $routes);
    }

    public function testSearchFiltersRecords(): void
    {
        $html = Crud::toCrud($this->request('/admin/crudtestmodels?search=Alice'), ['model' => CrudTestModel::class, 'title' => 'Search Test']);
        $this->assertStringContainsString('Alice', $html);
        $this->assertStringNotContainsString('Bob', $html);
        $this->assertStringNotContainsString('Charlie', $html);
    }

    public function testPaginates(): void
    {
        $html = Crud::toCrud($this->request('/admin/crudtestmodels?page=1'), ['model' => CrudTestModel::class, 'limit' => 2]);
        $this->assertStringContainsString('page 1 of 2', $html);
        $this->assertStringContainsString('Next', $html);
    }

    public function testShowsSecondPage(): void
    {
        $html = Crud::toCrud($this->request('/admin/crudtestmodels?page=2'), ['model' => CrudTestModel::class, 'limit' => 2]);
        $this->assertStringContainsString('page 2 of 2', $html);
        $this->assertStringContainsString('Prev', $html);
    }

    public function testModelIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Crud::toCrud($this->request(), ['title' => 'Broken']);
    }

    public function testSqlOnlyWithoutModelRaises(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Crud::toCrud($this->request(), ['sql' => 'SELECT id, name FROM crudtestmodels']);
    }

    public function testGenerateTableFromRecords(): void
    {
        $records = [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']];
        $html = Crud::generateTable($records, 'users', 'id');
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('Alice', $html);
        $this->assertStringContainsString('Bob', $html);
        $this->assertStringContainsString('crudSave', $html);
    }

    public function testGenerateTableEmpty(): void
    {
        $html = Crud::generateTable([], 'users');
        $this->assertStringContainsString('No records found', $html);
    }

    public function testGenerateForm(): void
    {
        $fields = [
            ['name' => 'name', 'type' => 'string', 'label' => 'Full Name', 'required' => true],
            ['name' => 'email', 'type' => 'string', 'label' => 'Email'],
        ];
        $html = Crud::generateForm($fields, '/api/users', 'POST');
        $this->assertStringContainsString('<form', $html);
        $this->assertStringContainsString('Full Name', $html);
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringContainsString('name="email"', $html);
    }
}
