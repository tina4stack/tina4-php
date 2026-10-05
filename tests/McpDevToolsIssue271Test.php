<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * Regression tests for tina4-php#271 (dev MCP tool rough edges).
 * No mocks: real default McpServer dispatch, real SQLite file (incl. an EMPTY
 * table), real Router, real Server::detectFileChanges() on a real directory.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\McpServer;
use Tina4\Router;
use Tina4\Server;

class McpDevToolsIssue271Test extends TestCase
{
    private string $root;
    private string $oldCwd;
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->oldCwd = getcwd() ?: '';
        $this->root = sys_get_temp_dir() . '/tina4_issue271_' . uniqid('', true);
        mkdir($this->root . '/src/routes', 0755, true);
        foreach (['TINA4_DATABASE_URL' => 'sqlite:///' . $this->root . '/app.db', 'TINA4_DEBUG' => 'true', 'TINA4_MCP' => 'true'] as $k => $v) {
            $this->savedEnv[$k] = getenv($k) === false ? null : getenv($k);
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
        }
        $db = Database::create('sqlite:///' . $this->root . '/app.db');
        $db->execute('CREATE TABLE empty_widget (id INTEGER PRIMARY KEY, label TEXT NOT NULL, price REAL)');
        $db->commit();
        chdir($this->root);
        Router::clear();
        McpServer::resetDefaultServer();
    }

    protected function tearDown(): void
    {
        chdir($this->oldCwd);
        Router::clear();
        McpServer::resetDefaultServer();
        foreach ($this->savedEnv as $k => $v) {
            $v === null ? putenv($k) : putenv("{$k}={$v}");
            if ($v === null) {
                unset($_ENV[$k]);
            }
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    private function call(string $tool, array $args): mixed
    {
        return McpServer::getDefaultServer()->callTool($tool, $args);
    }

    // ── P1 ──────────────────────────────────────────────────────

    public function testDatabaseColumnsOfEmptyTableReturnsColumnsWithTypes(): void
    {
        $columns = $this->call('database_columns', ['table' => 'empty_widget']);
        $this->assertSame(['id', 'label', 'price'], array_column($columns, 'name'));
        $byName = array_column($columns, null, 'name');
        $this->assertSame('TEXT', strtoupper($byName['label']['type']));
        $this->assertFalse($byName['label']['nullable']);
        $this->assertTrue($byName['price']['nullable']);
    }

    public function testDatabaseColumnsOfMissingTableIsAClearError(): void
    {
        $result = $this->call('database_columns', ['table' => 'no_such_table']);
        $this->assertSame(['error' => 'table not found: no_such_table'], $result);
    }

    // ── P2 ──────────────────────────────────────────────────────

    public function testMisnamedArgumentReturnsActionableErrorNotArgumentCountError(): void
    {
        $result = $this->call('api_method', ['class' => 'Auth', 'method' => 'getToken']);
        $this->assertSame(
            ['error' => "missing required argument 'name' (api_method takes class, name)"],
            $result
        );
    }

    public function testUnknownExtraArgumentIsRejected(): void
    {
        $result = $this->call('api_method', ['class' => 'Auth', 'name' => 'getToken', 'bogus' => 1]);
        $this->assertSame(['error' => "unknown argument 'bogus' (api_method takes class, name)"], $result);
    }

    public function testMissingArgumentOverJsonRpcIsAnErrorResultNotAnException(): void
    {
        $raw = McpServer::getDefaultServer()->handleMessage(json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'api_method', 'arguments' => ['class' => 'Auth']],
        ]));
        $decoded = json_decode($raw, true);
        // Parity with the Python reference: the validation error comes back as a
        // NORMAL tool result carrying {"error": ...} content, not a JSON-RPC
        // protocol error and not an isError flag.
        $this->assertArrayNotHasKey('error', $decoded, $raw);
        $this->assertStringContainsString("missing required argument 'name'", $decoded['result']['content'][0]['text']);
    }

    public function testApiMethodPopulatesParamsAndReturn(): void
    {
        $spec = $this->call('api_method', ['class' => 'Auth', 'name' => 'getToken']);
        $this->assertArrayNotHasKey('error', $spec);
        $this->assertNotSame([], $spec['params']);
        $this->assertSame('payload', $spec['params'][0]['name']);
        $this->assertSame('array', $spec['params'][0]['type']);
        $this->assertSame('string', $spec['return']);
        $secret = array_column($spec['params'], null, 'name')['secret'];
        $this->assertTrue($secret['optional']);
    }

    // ── P3 ──────────────────────────────────────────────────────

    public function testRouteListShowsMiddlewareOfNoAuthRoute(): void
    {
        $requireAdmin = static function ($request, $response, $next) {
            return $next($request, $response);
        };
        Router::post('/admin/guarded', fn($req, $res) => $res('ok'))->middleware([$requireAdmin, 'SomeGuard'])->noAuth();
        Router::post('/open', fn($req, $res) => $res('ok'))->noAuth();

        $routes = array_column($this->call('route_list', []), null, 'path');
        $this->assertFalse($routes['/admin/guarded']['auth_required']);
        $this->assertCount(2, $routes['/admin/guarded']['middleware']);
        $this->assertStringStartsWith('Closure@McpDevToolsIssue271Test.php:', $routes['/admin/guarded']['middleware'][0]);
        $this->assertSame('SomeGuard', $routes['/admin/guarded']['middleware'][1]);
        $this->assertSame([], $routes['/open']['middleware']);
    }

    // ── P4 ──────────────────────────────────────────────────────

    public function testNewRouteFileAfterFirstScanRaisesRestartSignal(): void
    {
        $server = new Server();
        $scan = new ReflectionMethod(Server::class, 'detectFileChanges');
        file_put_contents($this->root . '/src/routes/old.php', '<?php');

        $this->assertFalse($scan->invoke($server), 'first scan only primes the file map');
        $this->assertFalse($scan->invoke($server), 'nothing changed');

        file_put_contents($this->root . '/src/routes/new.php', '<?php');
        $this->assertTrue($scan->invoke($server), 'a NEW .php file must be reported');
        $flag = new ReflectionProperty(Server::class, 'phpChangeDetected');
        $this->assertTrue($flag->getValue($server));
        $added = (new ReflectionProperty(Server::class, 'newPhpFiles'))->getValue($server);
        $this->assertCount(1, $added);
        $this->assertStringEndsWith('new.php', $added[0]);

        $this->assertFalse($scan->invoke($server), 'reported once, then tracked');
    }
}
