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
 * The CRUD HTML component is CSP-clean under the strict default policy
 * (ADR-0088): default-src 'self'. A nonce authorises a <script>/<style>
 * ELEMENT but NEVER an inline on*= event-handler attribute, so Crud must emit
 * ZERO inline on*= / style= attributes and bind every action with
 * addEventListener inside its nonce'd <script>.
 *
 * NO MOCKS: a real in-memory SQLite, a real Request, the real Crud generator.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Crud;
use Tina4\Database\SQLite3Adapter;
use Tina4\ORM;
use Tina4\Request;
use Tina4\Router;

class CrudCspModel extends ORM
{
    public string $tableName = 'crudcspmodels';
    public string $primaryKey = 'id';
    public ?int $id = null;
    public ?string $name = null;
    public ?string $email = null;
}

class CrudCspTest extends TestCase
{
    // An inline HTML event-handler attribute (onclick=, onsubmit=, …), NOT a JS
    // property assignment (el.onclick = fn), which is CSP-allowed.
    private const INLINE_HANDLER_ATTR = '/\son[a-z]+\s*=\s*["\']/';
    // An inline style= attribute — dead under default-src 'self' (ADR-0088).
    private const INLINE_STYLE_ATTR = '/\sstyle\s*=\s*["\']/';

    private SQLite3Adapter $db;

    protected function setUp(): void
    {
        Router::clear();
        Crud::clearRegistry();
        $this->db = new SQLite3Adapter(':memory:');
        $this->db->exec('CREATE TABLE crudcspmodels (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT)');
        $this->db->exec("INSERT INTO crudcspmodels (name, email) VALUES ('Alice', 'alice@example.com')");
        $this->db->exec("INSERT INTO crudcspmodels (name, email) VALUES ('Bob', 'bob@example.com')");
        ORM::bindDatabase($this->db);
    }

    protected function tearDown(): void
    {
        Router::clear();
        Crud::clearRegistry();
        $this->db->close();
    }

    private function page(): string
    {
        return Crud::toCrud(
            Request::create(method: 'GET', path: '/admin/crudcspmodels'),
            ['model' => CrudCspModel::class, 'title' => 'CSP CRUD']
        );
    }

    public function testEmitsRecordsAndModals(): void
    {
        $html = $this->page();
        $this->assertStringContainsString('Alice', $html);
        $this->assertStringContainsString('Bob', $html);
        $this->assertStringContainsString('modal-create', $html);
    }

    public function testZeroInlineOnHandlerAttributes(): void
    {
        $this->assertSame(0, preg_match_all(self::INLINE_HANDLER_ATTR, $this->page()));
    }

    public function testZeroInlineStyleAttributes(): void
    {
        $this->assertSame(0, preg_match_all(self::INLINE_STYLE_ATTR, $this->page()));
    }

    public function testTheGatesAreRealMutationProof(): void
    {
        // Prove the regexes are genuine gates: a page carrying the forbidden
        // attributes MUST be flagged. A gate never seen to fail is not known
        // to work.
        $mutated = str_replace('<h2>', '<h2 onclick="x()" style="color:red">', $this->page());
        $this->assertGreaterThan(0, preg_match_all(self::INLINE_HANDLER_ATTR, $mutated));
        $this->assertGreaterThan(0, preg_match_all(self::INLINE_STYLE_ATTR, $mutated));
    }

    public function testRegistersFullAutoCrudBackend(): void
    {
        $this->page();
        $routes = array_map(
            static fn (array $r): string => "{$r['method']} {$r['pattern']}",
            Router::getRoutes()
        );
        $this->assertContains('GET /api/crudcspmodels', $routes);
        $this->assertContains('GET /api/crudcspmodels/{id}', $routes);
        $this->assertContains('POST /api/crudcspmodels', $routes);
        $this->assertContains('PUT /api/crudcspmodels/{id}', $routes);
        $this->assertContains('DELETE /api/crudcspmodels/{id}', $routes);
    }

    public function testWiresButtonsWithDataCrudAction(): void
    {
        $html = $this->page();
        $this->assertStringContainsString('data-crud-action="create"', $html);
        $this->assertStringContainsString('data-crud-action="edit"', $html);
        $this->assertStringContainsString('data-crud-action="delete"', $html);
        $this->assertStringContainsString('data-crud-action="save"', $html);
        $this->assertStringContainsString('data-crud-action="confirm-delete"', $html);
        $this->assertStringContainsString('data-id="', $html);
        $this->assertStringContainsString('data-crud-mode="', $html);
    }

    public function testBindsActionsThroughADelegatedListenerInANoncedScript(): void
    {
        $html = $this->page();
        $this->assertStringContainsString('<script nonce=', $html);
        $this->assertStringContainsString("addEventListener('click'", $html);
        $this->assertStringContainsString('button.dataset.crudAction', $html);
        // the modal form's native submit is stopped by a listener, not onsubmit=
        $this->assertStringContainsString("addEventListener('submit'", $html);
        $this->assertStringContainsString('data-crud-form="1"', $html);
    }

    // --- generate_table inline-edit fragment ---

    private function fragment(): string
    {
        $records = [
            ['id' => 1, 'name' => 'Alice\'s "Gadget"', 'email' => 'alice@example.com'],
            ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com'],
        ];
        return Crud::generateTable($records, 'crudcspmodels', 'id');
    }

    public function testFragmentZeroInlineOnHandlers(): void
    {
        $this->assertSame(0, preg_match_all(self::INLINE_HANDLER_ATTR, $this->fragment()));
    }

    public function testFragmentZeroInlineStyle(): void
    {
        $this->assertSame(0, preg_match_all(self::INLINE_STYLE_ATTR, $this->fragment()));
    }

    public function testFragmentWiresSaveDeleteWithDelegatedListener(): void
    {
        $html = $this->fragment();
        $this->assertStringContainsString('data-crud-inline="save"', $html);
        $this->assertStringContainsString('data-crud-inline="delete"', $html);
        $this->assertStringContainsString('data-table="crudcspmodels"', $html);
        $this->assertStringContainsString('<script nonce=', $html);
        $this->assertStringContainsString("addEventListener('click'", $html);
        $this->assertStringContainsString('button.dataset.crudInline', $html);
    }

    // ADR-0094: every crud/ template is app-overridable — src/templates/crud/
    // wins over the framework's shipped template.
    public function testAppTemplateOverride(): void
    {
        $origCwd = getcwd();
        $project = sys_get_temp_dir() . '/tina4-crud-override-' . getmypid() . '-' . uniqid();
        mkdir($project . '/src/templates/crud', 0755, true);
        file_put_contents(
            $project . '/src/templates/crud/table.twig',
            '<div class="app-override-marker">OVERRIDDEN TABLE</div>'
        );

        try {
            chdir($project);
            // The Frond singleton caches compiled templates by path; clear it so
            // the freshly-written app override is picked up.
            \Tina4\Response::getFrond()->clearCache();
            $html = $this->page();
        } finally {
            chdir($origCwd);
            $this->rmrf($project);
        }

        $this->assertStringContainsString('app-override-marker', $html);
        $this->assertStringContainsString('OVERRIDDEN TABLE', $html);
        // The page shell (not overridden) still rendered around it.
        $this->assertStringContainsString('CSP CRUD', $html);
        $this->assertStringContainsString('modal-create', $html);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
