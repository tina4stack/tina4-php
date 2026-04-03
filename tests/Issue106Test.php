<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Tests for equivalent bugs from issue #106 (cross-framework parity).
 */

use PHPUnit\Framework\TestCase;
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;
use Tina4\Database\Database;
use Tina4\Database\DatabaseResult;

class Issue106Test extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
    }

    protected function tearDown(): void
    {
        Router::clear();
    }

    // ── 1. Wildcard param key is "*" ────────────────────────────────────

    public function testWildcardParamKeyIsStar(): void
    {
        Router::get('/docs/*', fn() => 'docs');
        $result = Router::match('GET', '/docs/api/v1/users');

        $this->assertNotNull($result, 'Wildcard route /docs/* should match /docs/api/v1/users');
        $this->assertArrayHasKey('*', $result['params'], 'Wildcard captures should use "*" as the key');
        $this->assertSame('api/v1/users', $result['params']['*']);
    }

    public function testWildcardSingleSegment(): void
    {
        Router::get('/files/*', fn() => 'files');
        $result = Router::match('GET', '/files/readme.txt');

        $this->assertNotNull($result);
        $this->assertSame('readme.txt', $result['params']['*']);
    }

    public function testWildcardDoesNotMatchBase(): void
    {
        Router::get('/docs/*', fn() => 'docs');
        // /docs/ with nothing after the wildcard should not match
        $this->assertNull(Router::match('GET', '/docs'));
    }

    // ── 2. Router::group accessible ────────────────────────────────────

    public function testRouterGroupRegistersRoutesWithPrefix(): void
    {
        $this->assertTrue(
            method_exists(Router::class, 'group'),
            'Router::group() method should exist'
        );

        Router::group('/api/v1', function () {
            Router::get('/users', fn() => 'users list');
            Router::post('/users', fn() => 'create user');
        });

        $getResult = Router::match('GET', '/api/v1/users');
        $this->assertNotNull($getResult, 'Group should prefix GET /users with /api/v1');
        $this->assertSame('/api/v1/users', $getResult['route']['pattern']);

        $postResult = Router::match('POST', '/api/v1/users');
        $this->assertNotNull($postResult, 'Group should prefix POST /users with /api/v1');
    }

    public function testRouterGroupNested(): void
    {
        Router::group('/api', function () {
            Router::group('/v2', function () {
                Router::get('/items', fn() => 'items');
            });
        });

        $result = Router::match('GET', '/api/v2/items');
        $this->assertNotNull($result, 'Nested groups should combine prefixes');
        $this->assertSame('/api/v2/items', $result['route']['pattern']);
    }

    // ── 3. request.files populated for multipart ───────────────────────

    public function testRequestFilesPopulatedForMultipart(): void
    {
        // Create a real temp file to simulate PHP upload
        $tmpFile = tempnam(sys_get_temp_dir(), 'tina4test_');
        file_put_contents($tmpFile, 'fake image content');

        $fakeFiles = [
            'avatar' => [
                'name'     => 'photo.png',
                'type'     => 'image/png',
                'tmp_name' => $tmpFile,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($tmpFile),
            ],
        ];

        $request = new Request(
            method: 'POST',
            path: '/upload',
            body: ['field' => 'value'],
            files: $fakeFiles,
        );

        // Files should be in $request->files, not mixed into $request->body
        $this->assertNotEmpty($request->files, 'Uploaded files should populate $request->files');
        $this->assertArrayHasKey('avatar', $request->files, 'File field "avatar" should be in files');
        // Tina4 normalises to 'filename' key (not 'name')
        $this->assertSame('photo.png', $request->files['avatar']['filename']);
        $this->assertSame('image/png', $request->files['avatar']['type']);

        // Body should contain only form fields, not file data
        $this->assertSame(['field' => 'value'], $request->body);

        @unlink($tmpFile);
    }

    // ── 4. toPaginate() slices correctly ───────────────────────────────

    public function testToPaginateReturnsCorrectStructure(): void
    {
        // Create 50 records
        $allRecords = [];
        for ($i = 1; $i <= 50; $i++) {
            $allRecords[] = ['id' => $i, 'name' => "Item {$i}"];
        }

        // Simulate page 2 with per_page=10 (offset 10, limit 10)
        $page = 2;
        $perPage = 10;
        $offset = ($page - 1) * $perPage;
        $pageRecords = array_slice($allRecords, $offset, $perPage);

        $result = new DatabaseResult(
            records: $pageRecords,
            columns: ['id', 'name'],
            count: 50,
            limit: $perPage,
            offset: $offset,
        );

        $paginated = $result->toPaginate();

        $this->assertCount(10, $paginated['records'], 'Page should have 10 items');
        $this->assertSame(50, $paginated['count'], 'Total count should be 50');
        $this->assertSame(10, $paginated['limit'], 'Limit should be 10 (per_page)');
        $this->assertSame(10, $paginated['offset'], 'Offset should be 10 for page 2');

        // Verify the slice is correct (items 11-20)
        $this->assertSame(11, $paginated['records'][0]['id']);
        $this->assertSame(20, $paginated['records'][9]['id']);
    }

    // ── 5. columnInfo() types ──────────────────────────────────────────

    public function testColumnInfoReturnsRealTypesWithAdapter(): void
    {
        $db = Database::create('sqlite::memory:');
        $db->execute('CREATE TABLE test_types (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            price REAL,
            active INTEGER DEFAULT 1
        )');
        $db->execute("INSERT INTO test_types (id, name, price, active) VALUES (1, 'Widget', 9.99, 1)");

        $result = $db->fetch('SELECT * FROM test_types');
        $info = $result->columnInfo();

        $this->assertNotEmpty($info, 'columnInfo() should return column metadata');

        // Collect type values
        $types = array_column($info, 'type');
        $names = array_column($info, 'name');

        $this->assertContains('id', $names);
        $this->assertContains('name', $names);

        // No column should have type "UNKNOWN" when a real adapter is used
        foreach ($info as $col) {
            $this->assertNotSame(
                'UNKNOWN',
                $col['type'],
                "Column '{$col['name']}' should have a real type, not UNKNOWN"
            );
        }
    }

    // ── 6. Default fetch limit ─────────────────────────────────────────

    public function testDefaultFetchLimitOnSQLiteAdapter(): void
    {
        $db = Database::create('sqlite::memory:');
        $db->execute('CREATE TABLE fetch_test (id INTEGER PRIMARY KEY, val TEXT)');

        for ($i = 1; $i <= 150; $i++) {
            $db->execute("INSERT INTO fetch_test (id, val) VALUES ({$i}, 'row-{$i}')");
        }

        // Call fetch without explicit limit — adapter default should apply
        $result = $db->fetch('SELECT * FROM fetch_test');

        // Default limit is 100 across all frameworks
        $this->assertSame(
            100,
            $result->limit,
            'Default fetch limit should be 100 on the adapter interface'
        );
        $this->assertCount(100, $result->records, 'Default fetch should return 100 records');
        $this->assertSame(150, $result->count, 'Total count should reflect all 150 rows');
    }

    // ── 7. CSS served from framework public ────────────────────────────

    public function testTina4CssExistsInPublicDirectory(): void
    {
        // The framework's public CSS file should exist
        $cssPath = __DIR__ . '/../src/public/css/tina4.css';
        $this->assertFileExists($cssPath, 'tina4.css should exist in src/public/css/');
        $this->assertGreaterThan(0, filesize($cssPath), 'tina4.css should not be empty');
    }
}
