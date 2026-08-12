<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * PAGE-DEC-01 pagination clamp + cap — feature 24 (pagination_contract.json).
 *
 * The audit's PAGE-NEGATIVE-OFFSET finding: `page < 1` was NOT clamped in
 * Python/Ruby/Node's AutoCrud list handler, so `offset = (page - 1) * per_page`
 * handed the driver a NEGATIVE offset — a hard ERROR on PostgreSQL ("OFFSET
 * must not be negative") and a silent 0-offset on SQLite (still wrong: the
 * envelope reported page:0). PHP was ALREADY correct — `page <= 1` forces
 * offset 0 — this suite pins that reference behaviour so it can never regress.
 * PAGE-NO-MAX-LIMIT: an oversized ?limit=/?per_page= was honoured verbatim.
 *
 * PAGE-DEC-01 (OWNER-DECISIONS.md Batch 4): cap the per-page size at
 * AutoCrud::MAX_PER_PAGE (100 — the same row cap the ORM/DB family shares, and
 * Node's own DEFAULT_ROW_CAP).
 *
 * Real SQLite (always) + real PostgreSQL :55432 tina4/tina4 (when reachable,
 * TINA4_TEST_PG_* to relocate; skip is a hard failure under
 * TINA4_REQUIRE_SERVICES — RequireServicesExtension) through the REAL AutoCrud
 * list handler via Router::dispatch — the same entry point the live server
 * uses. No mocks.
 *
 * Mutation-proof (manual): reverting `$limit = min($limit, self::MAX_PER_PAGE)`
 * in Tina4/AutoCrud.php turns `testOversizedPerPageIsCappedSqlite` RED
 * (per_page echoes the huge requested value).
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\AutoCrud;
use Tina4\Database\Database;
use Tina4\Database\DatabaseAdapter;
use Tina4\Database\SQLite3Adapter;
use Tina4\ORM;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

/**
 * Test model for the pagination clamp/cap contract. Unique name + unique
 * table so it can never collide with CrudItem et al in AutoCrudV3Test.php.
 */
class PageClampWidget extends ORM
{
    public string $tableName = 'page_clamp_widget';
    public string $primaryKey = 'id';
}

class PaginationClampContractTest extends TestCase
{
    private const TABLE = 'page_clamp_widget';

    private ?DatabaseAdapter $db = null;

    private static function pgUrl(): string
    {
        $host = getenv('TINA4_TEST_PG_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_PG_PORT') ?: 55432);
        $db   = getenv('TINA4_TEST_PG_DB') ?: 'tina4_php';
        $user = getenv('TINA4_TEST_PG_USERNAME') ?: 'tina4';
        $pass = getenv('TINA4_TEST_PG_PASSWORD') ?: 'tina4';
        return "postgres://{$user}:{$pass}@{$host}:{$port}/{$db}";
    }

    private static function tcpReachable(string $host, int $port): bool
    {
        $conn = @fsockopen($host, $port, $errno, $errstr, 2.0);
        if ($conn === false) {
            return false;
        }
        fclose($conn);
        return true;
    }

    protected function tearDown(): void
    {
        Router::clear();
        if ($this->db !== null) {
            try {
                $this->db->execute('DROP TABLE IF EXISTS ' . self::TABLE);
            } catch (\Throwable) {
                // best effort
            }
            $this->db->close();
            $this->db = null;
        }
    }

    private function seedSqlite(): DatabaseAdapter
    {
        $db = new SQLite3Adapter(':memory:');
        $db->execute('CREATE TABLE ' . self::TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL)');
        for ($i = 0; $i < 5; $i++) {
            $db->execute('INSERT INTO ' . self::TABLE . ' (name) VALUES (:name)', [':name' => "w{$i}"]);
        }
        return $db;
    }

    private function seedPostgres(): DatabaseAdapter
    {
        $host = getenv('TINA4_TEST_PG_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_PG_PORT') ?: 55432);
        if (!self::tcpReachable($host, $port)) {
            $this->markTestSkipped("no reachable postgres at {$host}:{$port} (set TINA4_TEST_PG_*)");
        }
        $db = Database::create(self::pgUrl());
        $db->execute('DROP TABLE IF EXISTS ' . self::TABLE);
        $db->execute('CREATE TABLE ' . self::TABLE . ' (id SERIAL PRIMARY KEY, name VARCHAR(100) NOT NULL)');
        for ($i = 0; $i < 5; $i++) {
            $db->execute('INSERT INTO ' . self::TABLE . ' (name) VALUES (:name)', [':name' => "w{$i}"]);
        }
        return $db;
    }

    private function registerCrud(DatabaseAdapter $db): void
    {
        \Tina4\ORM::bindDatabase($db);
        $crud = new AutoCrud($db);
        $crud->register(PageClampWidget::class);
        $crud->generateRoutes();
    }

    private function assertPageZeroClampsToPageOne(string $engine): void
    {
        foreach (['0', '-3'] as $badPage) {
            $request = Request::create(method: 'GET', path: '/api/page_clamp_widget', query: ['page' => $badPage, 'per_page' => '10']);
            $response = new Response(testing: true);
            $result = Router::dispatch($request, $response);

            $this->assertSame(
                200,
                $result->getStatusCode(),
                "[{$engine}] page={$badPage} must not error (got {$result->getStatusCode()})"
            );
            $body = $result->getJsonBody();
            $this->assertSame(1, $body['page'], "[{$engine}] page={$badPage} -> envelope page {$body['page']}, want 1");
            $this->assertSame(0, $body['offset'], "[{$engine}] page={$badPage} -> envelope offset {$body['offset']}, want 0");
            $this->assertCount(5, $body['records'], "[{$engine}] page={$badPage} -> expected all 5 rows");
        }
    }

    private function assertOversizedPerPageIsCapped(string $engine): void
    {
        $request = Request::create(method: 'GET', path: '/api/page_clamp_widget', query: ['per_page' => '1000000']);
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(200, $result->getStatusCode(), "[{$engine}]");
        $body = $result->getJsonBody();
        $this->assertSame(100, $body['per_page'], "[{$engine}] got per_page={$body['per_page']}");
        $this->assertSame(100, $body['limit'], "[{$engine}] got limit={$body['limit']}");

        // The alternate ?limit= spelling (no ?page=) takes the same cap.
        $request2 = Request::create(method: 'GET', path: '/api/page_clamp_widget', query: ['limit' => '999999']);
        $response2 = new Response(testing: true);
        $result2 = Router::dispatch($request2, $response2);
        $body2 = $result2->getJsonBody();
        $this->assertSame(100, $body2['limit'], "[{$engine}] got limit={$body2['limit']}");
        $this->assertSame(100, $body2['per_page'], "[{$engine}] got per_page={$body2['per_page']}");
    }

    public function testPageZeroClampsToPageOneSqlite(): void
    {
        $this->db = $this->seedSqlite();
        $this->registerCrud($this->db);
        $this->assertPageZeroClampsToPageOne('sqlite');
    }

    public function testPageZeroClampsToPageOnePostgres(): void
    {
        $this->db = $this->seedPostgres();
        $this->registerCrud($this->db);
        $this->assertPageZeroClampsToPageOne('postgres');
    }

    public function testOversizedPerPageIsCappedSqlite(): void
    {
        $this->db = $this->seedSqlite();
        $this->registerCrud($this->db);
        $this->assertOversizedPerPageIsCapped('sqlite');
    }

    public function testOversizedPerPageIsCappedPostgres(): void
    {
        $this->db = $this->seedPostgres();
        $this->registerCrud($this->db);
        $this->assertOversizedPerPageIsCapped('postgres');
    }
}
