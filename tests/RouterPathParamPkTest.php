<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;
use Tina4\Database\SQLite3Adapter;

/**
 * Regression / contract test — cross-framework parity lock.
 *
 * A route path parameter like {id}, extracted by the REAL router from a real
 * HTTP request path (e.g. "/users/2"), must bind to a real SQLite database and
 * MATCH an INTEGER-primary-key row via SQLite's numeric affinity.
 *
 * Background: tina4-ruby had a bug where a captured {id} arrived as an
 * ASCII-8BIT (binary) string and bound to SQLite as a BLOB, so `WHERE id = ?`
 * against an INTEGER PK never matched — GET /api/users/{id} returned 404 for a
 * row that exists. Python master is correct: an untyped string path param
 * matches an INTEGER PK via numeric affinity.
 *
 * In PHP, a route capture is a plain PHP string (no ASCII-8BIT byte-string
 * tag), and SQLite3Adapter::bindParams() binds a string as SQLITE3_TEXT, which
 * SQLite gives TEXT storage + numeric affinity — so it MATCHES. This test
 * PROVES that and locks it against silent drift.
 *
 * NO MOCKS: real Router path-matching (compilePath -> preg_match capture) and a
 * real in-memory SQLite database with a real INTEGER PK column.
 */
class RouterPathParamPkTest extends TestCase
{
    private SQLite3Adapter $db;

    protected function setUp(): void
    {
        Router::clear();
        // Real in-memory SQLite with an INTEGER PRIMARY KEY column.
        $this->db = new SQLite3Adapter(':memory:');
        $this->db->exec(
            "CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)"
        );
        // Insert three rows: ids 1, 2, 3.
        $this->db->execute("INSERT INTO users (name) VALUES (?)", ['Alice']);
        $this->db->execute("INSERT INTO users (name) VALUES (?)", ['Bob']);
        $this->db->execute("INSERT INTO users (name) VALUES (?)", ['Carol']);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        Router::clear();
    }

    /**
     * Sanity: the PK column really has INTEGER storage/affinity. If this ever
     * changes the whole premise of the test (numeric affinity match) is void.
     */
    public function testPrimaryKeyColumnIsInteger(): void
    {
        $columns = $this->db->getColumns('users');
        $idCol = null;
        foreach ($columns as $col) {
            if ($col['name'] === 'id') {
                $idCol = $col;
                break;
            }
        }
        $this->assertNotNull($idCol, 'users.id column must exist');
        $this->assertSame('INTEGER', strtoupper($idCol['type']));
        $this->assertTrue($idCol['primaryKey'], 'users.id must be the primary key');
    }

    /**
     * The core parity assertion: an UNTYPED {id} captured by the real router
     * from "/users/2" is a plain string that binds as TEXT and still matches
     * the INTEGER PK row via SQLite numeric affinity.
     */
    public function testUntypedPathParamMatchesIntegerPk(): void
    {
        Router::get('/users/{id}', fn() => null);

        // Real request path — mirrors what the built-in server hands the router
        // from REQUEST_URI.
        $request = new Request(method: 'GET', path: '/users/2');
        $match = Router::match($request->method, $request->path);

        $this->assertNotNull($match, 'router must match /users/2');
        $this->assertArrayHasKey('id', $match['params']);

        $id = $match['params']['id'];

        // The captured value is a plain PHP string (NOT a binary/ASCII-8BIT
        // byte string). This is the crux of why PHP is immune to the Ruby bug.
        $this->assertIsString($id);
        $this->assertSame('2', $id);
        // Byte '2' is 0x32 — a normal ASCII string, no binary tag.
        $this->assertSame('32', bin2hex($id));

        // Bind the router-extracted param to a real INTEGER-PK query.
        $rows = $this->db->query("SELECT id, name FROM users WHERE id = ?", [$id]);

        $this->assertCount(1, $rows, 'WHERE id = ? with the router param must match exactly one row');
        $this->assertSame(2, (int) $rows[0]['id']);
        $this->assertSame('Bob', $rows[0]['name']);
    }

    /**
     * The TYPED {id:int} form: the router casts the capture to a native int,
     * which binds as SQLITE3_INTEGER and matches the INTEGER PK directly.
     */
    public function testTypedIntPathParamMatchesIntegerPk(): void
    {
        Router::get('/users/{id:int}', fn() => null);

        $request = new Request(method: 'GET', path: '/users/3');
        $match = Router::match($request->method, $request->path);

        $this->assertNotNull($match, 'router must match /users/3');
        $id = $match['params']['id'];

        // {id:int} is cast to a native PHP int by matchInTable().
        $this->assertIsInt($id);
        $this->assertSame(3, $id);

        $rows = $this->db->query("SELECT id, name FROM users WHERE id = ?", [$id]);

        $this->assertCount(1, $rows);
        $this->assertSame(3, (int) $rows[0]['id']);
        $this->assertSame('Carol', $rows[0]['name']);
    }

    /**
     * End-to-end through the full dispatch pipeline: a real GET request is
     * dispatched, the handler receives the injected {id}, queries the real DB,
     * and the JSON response body carries the matched row. This exercises the
     * same path an actual HTTP request would take (match -> param inject ->
     * handler -> DB bind), proving GET /users/{id} returns the row, not 404.
     */
    public function testDispatchGetByIdReturnsRowNotNotFound(): void
    {
        $db = $this->db;
        Router::get('/users/{id}', function (Request $request, Response $response, $id) use ($db) {
            $rows = $db->query("SELECT id, name FROM users WHERE id = ?", [$id]);
            if (empty($rows)) {
                return $response->json(['error' => 'Not Found'], 404);
            }
            return $response->json($rows[0]);
        });

        $request = new Request(method: 'GET', path: '/users/2');
        $response = new Response();
        $result = Router::dispatch($request, $response);

        $this->assertSame(200, $result->getStatusCode(), 'existing row must return 200, not 404');
        $body = json_decode($result->getBody(), true);
        $this->assertSame(2, (int) $body['id']);
        $this->assertSame('Bob', $body['name']);
    }

    /**
     * Negative control: a path param that does NOT correspond to any row still
     * returns zero rows (so the "match" above is real, not a false positive
     * that matches everything).
     */
    public function testNonexistentIdMatchesNoRow(): void
    {
        Router::get('/users/{id}', fn() => null);

        $request = new Request(method: 'GET', path: '/users/999');
        $match = Router::match($request->method, $request->path);
        $this->assertNotNull($match);

        $rows = $this->db->query(
            "SELECT id, name FROM users WHERE id = ?",
            [$match['params']['id']]
        );
        $this->assertCount(0, $rows, 'id 999 does not exist — must return no rows');
    }
}
