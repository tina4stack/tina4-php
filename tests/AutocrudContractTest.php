<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;
use Tina4\Database\Database;

/**
 * Shared contract suite for feature 27 -- AutoCrud (REST from ORM models).
 *
 * Fixture: tina4-documentation/plan/v3/fixtures/autocrud_contract.json
 * Decisions: CRUD-DEC-01 (a consistent 422 with field errors on an invalid
 * create/update -- fixes the HTTP 500-with-a-stale-$db->error() bug, since
 * a validation failure never touches the database at all) + CRUD-DEC-02
 * (allow-list writable columns -- guard is_deleted, strip the PK on
 * create/update -- and add wire tests, CRUD-WRITE-TESTS).
 *
 * NO MOCKS: a REAL `php -S` server (TestServer, the same helper
 * SwaggerContractTest uses) serves a REAL AutoCrud-registered model over a
 * REAL SQLite file, driven with real sockets (curl) and a real JWT
 * (Auth::getToken). The existing AutoCrudV3Test dispatches in-process via
 * Router::dispatch with hand-built Request/Response objects (bypassing the
 * real HTTP transport) -- this is the first AutoCrud test in this repo that
 * goes over a genuine socket.
 */
final class AutocrudContractTest extends TestCase
{
    private static TestServer $server;
    private static string $dbPath;
    private static string $secret = 'autocrud-contract-test-secret';

    public static function setUpBeforeClass(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tina4_autocrud_contract_');
        unlink($tmp);
        self::$dbPath = $tmp . '.db';

        $app = __DIR__ . '/fixtures/autocrud_contract_app.php';
        self::$server = TestServer::start($app, [
            'TINA4_TEST_DB_PATH' => self::$dbPath,
            'TINA4_SECRET' => self::$secret,
            'TINA4_DEBUG' => 'false',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
        @unlink(self::$dbPath);
    }

    private function token(): string
    {
        return Auth::getToken(['sub' => 'autocrud-contract-tester'], self::$secret);
    }

    private function db(): Database
    {
        return Database::create('sqlite:///' . self::$dbPath);
    }

    private function row(int $id): ?array
    {
        $records = $this->db()->fetch('SELECT * FROM autocrud_contract_item WHERE id = ?', [$id])->records;
        return $records[0] ?? null;
    }

    private function rowCount(): int
    {
        return (int)($this->db()->fetch('SELECT COUNT(*) AS c FROM autocrud_contract_item')->records[0]['c'] ?? -1);
    }

    /**
     * A REAL HTTP round trip over curl -- no in-process shortcut.
     *
     * @return array{0: int, 1: mixed, 2: string}
     */
    private function request(string $method, string $path, array $headers = [], ?array $body = null): array
    {
        $ch = curl_init(self::$server->base() . $path);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(['Content-Type: application/json'], $headers));
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $this->fail('curl error: ' . curl_error($ch));
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close() is a no-op since PHP 8.0 (deprecated in 8.5) -- the
        // handle is freed automatically; omit the call rather than trigger
        // the deprecation notice on newer runtimes.
        $json = json_decode((string)$raw, true);
        return [$status, $json, (string)$raw];
    }

    public function testTokenlessWriteReturns401(): void
    {
        [$status] = $this->request('POST', '/api/autocrud_contract_item', [], ['name' => 'no-token']);
        $this->assertSame(401, $status, 'POST without a token must be 401');

        [$status] = $this->request('PUT', '/api/autocrud_contract_item/1', [], ['name' => 'no-token']);
        $this->assertSame(401, $status, 'PUT without a token must be 401');

        [$status] = $this->request('DELETE', '/api/autocrud_contract_item/1');
        $this->assertSame(401, $status, 'DELETE without a token must be 401');
    }

    public function testValidAuthenticatedPostReturns201(): void
    {
        $token = $this->token();
        [$status, $json, $raw] = $this->request(
            'POST',
            '/api/autocrud_contract_item',
            ["Authorization: Bearer {$token}"],
            ['name' => 'widget-1'],
        );
        $this->assertSame(201, $status, $raw);
        $this->assertSame('widget-1', $json['name']);
        $this->assertNotNull($json['id']);

        $row = $this->row((int)$json['id']);
        $this->assertNotNull($row);
        $this->assertSame('widget-1', $row['name']);
    }

    public function testInvalidPostReturns422WithFieldErrors(): void
    {
        $token = $this->token();
        $before = $this->rowCount();

        [$status, $json, $raw] = $this->request(
            'POST',
            '/api/autocrud_contract_item',
            ["Authorization: Bearer {$token}"],
            [],
        );
        $this->assertSame(422, $status, $raw);
        $this->assertSame('Validation failed', $json['error']);
        $this->assertIsArray($json['detail']);
        $mentionsName = false;
        foreach ($json['detail'] as $message) {
            if (str_contains((string)$message, 'name')) {
                $mentionsName = true;
            }
        }
        $this->assertTrue($mentionsName, $raw);

        $this->assertSame($before, $this->rowCount());
    }

    public function testInvalidPutIsRejected(): void
    {
        $token = $this->token();
        [, $created] = $this->request(
            'POST',
            '/api/autocrud_contract_item',
            ["Authorization: Bearer {$token}"],
            ['name' => 'put-target'],
        );
        $id = (int)$created['id'];

        [$status, , $raw] = $this->request(
            'PUT',
            "/api/autocrud_contract_item/{$id}",
            ["Authorization: Bearer {$token}"],
            ['name' => str_repeat('x', 100)], // exceeds maxLength: 20
        );
        $this->assertSame(422, $status, $raw);

        // Unchanged in the DB -- the invalid PUT never wrote through.
        $row = $this->row($id);
        $this->assertSame('put-target', $row['name']);
    }

    public function testMassAssignmentIsBlocked(): void
    {
        $token = $this->token();
        [$status, $created, $raw] = $this->request(
            'POST',
            '/api/autocrud_contract_item',
            ["Authorization: Bearer {$token}"],
            ['name' => 'mass-assign', 'is_deleted' => 1, 'id' => 999999],
        );
        $this->assertSame(201, $status, $raw);
        $id = (int)$created['id'];
        // The client-supplied PK never won -- a fresh id was assigned, not an
        // overwrite of (or a claim on) row 999999.
        $this->assertNotSame(999999, $id);

        $row = $this->row($id);
        $this->assertSame(0, (int)$row['is_deleted']);

        [$status, , $raw] = $this->request(
            'PUT',
            "/api/autocrud_contract_item/{$id}",
            ["Authorization: Bearer {$token}"],
            ['is_deleted' => 1],
        );
        $this->assertSame(200, $status, $raw);

        $row = $this->row($id);
        $this->assertSame(0, (int)$row['is_deleted']);
    }

    public function testListIsTheSevenKeyEnvelope(): void
    {
        [$status, $json, $raw] = $this->request('GET', '/api/autocrud_contract_item');
        $this->assertSame(200, $status, $raw);
        $keys = array_keys($json);
        sort($keys);
        $this->assertSame(
            ['limit', 'offset', 'page', 'per_page', 'records', 'total', 'total_pages'],
            $keys,
        );
        $this->assertSame($this->rowCount(), $json['total']);
    }
}
