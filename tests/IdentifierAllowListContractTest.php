<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\DatabaseAdapter;
use Tina4\Auth;
use Tina4\AutoCrud;
use Tina4\ORM;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;
use function Tina4\getCollection;
use function Tina4\resetDefaultStore;

/**
 * ADR-0069: identifiers that reach SQL come from the model, never the request.
 * Governing decision: ADR-0069.
 *
 * Shared case names (verbatim across the four frameworks, gated by the contract
 * fixture auditor):
 *   AutoCrud  - unknown_filter_field_returns_400, unknown_sort_field_returns_400,
 *               declared_filter_and_sort_still_work, odd_typed_query_values_return_400,
 *               autocrud_list_uses_the_registered_connection,
 *               autocrud_write_body_accepts_only_declared_fields,
 *               autocrud_id_route_addresses_only_that_row
 *   ORM       - orm_find_rejects_undeclared_filter_key, orm_save_writes_only_declared_fields
 *               (SQLite, PostgreSQL, MySQL, MSSQL, Firebird)
 *   Database  - db_write_helpers_reject_non_identifier_keys (same engines)
 *   GraphQL   - graphql_id_argument_addresses_only_that_row (auto-schema from an ORM model)
 *   DocStore  - docstore_rejects_unsafe_field_path, docstore_accepts_safe_field_paths,
 *               docstore_safe_paths_match_on_real_mongo
 *
 * NO MOCKS. The AutoCrud cases go over a real socket to a real `php -S` server
 * (TestServer, as AutocrudContractTest does) backed by a real SQLite file; the
 * ORM case runs against every real engine the lab provides; the DocStore parity
 * case compares the SQLite fallback against a REAL MongoDB. Under
 * TINA4_REQUIRE_SERVICES=1 an unreachable engine or Mongo fails the run.
 *
 * Inputs are neutral: a real column the model does not declare, and keys that
 * contain a space, a quote or a bracket.
 */

/** Declared fields + a mapped field; the table has an extra, undeclared column. */
class AllowListOrmItem extends ORM
{
    public string $tableName = 'ala_php_item';
    public string $primaryKey = 'id';
    public DatabaseAdapter|string|null $_db = 'identifier_allow_list';
    public int $id = 0;
    public ?string $name = null;
    public ?string $givenName = null;
    public ?int $sortRank = null;   // autoMap: column sort_rank
    public array $fieldMapping = ['givenName' => 'first_name'];
}

/** No declared fields: the allow-list is the table's introspected columns. */
class AllowListOrmDynamic extends ORM
{
    public string $tableName = 'ala_php_item';
    public string $primaryKey = 'id';
    public DatabaseAdapter|string|null $_db = 'identifier_allow_list';
}

/** A declared model registered with a NON-global connection (autocrud_list_uses_the_registered_connection). */
class AllowListConnItem extends ORM
{
    public string $tableName = 'allow_list_conn';
    public string $primaryKey = 'id';
    public int $id = 0;
    public ?string $name = null;
}

/** A declared model exposed through GraphQL::fromOrm() (graphql_id_argument_addresses_only_that_row). */
class AllowListGqlItem extends ORM
{
    public string $tableName = 'allow_list_gql';
    public string $primaryKey = 'id';
    public int $id = 0;
    public ?string $name = null;
}

final class IdentifierAllowListContractTest extends TestCase
{
    private static ?TestServer $server = null;
    private const SECRET = 'identifier-allow-list-contract-secret';
    private static string $dbPath = '';

    /** Keys that must never resolve: a real-but-undeclared column + non-identifiers. */
    private const UNKNOWN_KEYS = ['internal_note', 'na me', "na'me", 'na[me'];

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
        if (self::$dbPath !== '') {
            @unlink(self::$dbPath);
        }
    }

    // ── AutoCrud over a real socket ─────────────────────────────────────────

    private function server(): TestServer
    {
        if (self::$server !== null) {
            return self::$server;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'tina4_allow_list_');
        unlink($tmp);
        self::$dbPath = $tmp . '.db';

        $db = Database::create('sqlite:///' . self::$dbPath);
        $db->execute('CREATE TABLE allow_list_item (id INTEGER PRIMARY KEY, name TEXT, score INTEGER, first_name TEXT, sort_rank INTEGER, internal_note TEXT)');
        foreach ([[1, 'alpha', 30, 'Ann', 3, 'n1'], [2, 'bravo', 10, 'Ben', 1, 'n2'], [3, 'charlie', 20, 'Ann', 4, 'n3'], [4, 'delta', 20, 'Cid', 2, 'n4']] as $row) {
            $db->execute('INSERT INTO allow_list_item (id, name, score, first_name, sort_rank, internal_note) VALUES (?, ?, ?, ?, ?, ?)', $row);
        }
        $db->execute('CREATE TABLE allow_list_dynamic (id INTEGER PRIMARY KEY, label TEXT, weight INTEGER, display_order INTEGER)');
        foreach ([[1, 'x', 5, 2], [2, 'y', 7, 3], [3, 'x', 9, 1]] as $row) {
            $db->execute('INSERT INTO allow_list_dynamic (id, label, weight, display_order) VALUES (?, ?, ?, ?)', $row);
        }
        $db->close();

        self::$server = TestServer::start(__DIR__ . '/fixtures/identifier_allow_list_app.php', [
            'TINA4_TEST_DB_PATH' => self::$dbPath,
            'TINA4_DEBUG' => 'false',
            'TINA4_SECRET' => self::SECRET,
        ]);
        return self::$server;
    }

    /** @return array{0: int, 1: mixed, 2: string} */
    private function get(string $pathAndQuery): array
    {
        $ch = curl_init($this->server()->base() . $pathAndQuery);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $this->fail('curl error: ' . curl_error($ch));
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$status, json_decode((string)$raw, true), (string)$raw];
    }

    /** @return array<int, int> the ids of a list response, in response order */
    private function listIds(string $pathAndQuery): array
    {
        [$status, $json, $raw] = $this->get($pathAndQuery);
        $this->assertSame(200, $status, "{$pathAndQuery}: {$raw}");
        return array_map(static fn (array $record): int => (int)$record['id'], $json['records']);
    }

    private function assertUnknownField(string $pathAndQuery, string $kind, string $key): void
    {
        [$status, $json, $raw] = $this->get($pathAndQuery);
        $this->assertSame(400, $status, "{$pathAndQuery}: {$raw}");
        $this->assertSame(
            ['error' => true, 'code' => 'UNKNOWN_FIELD', 'message' => "Unknown {$kind} field '{$key}'", 'status' => 400],
            $json,
            "{$pathAndQuery}: {$raw}",
        );
    }

    public function testUnknownFilterFieldReturns400(): void
    {
        foreach (self::UNKNOWN_KEYS as $key) {
            $this->assertUnknownField('/api/allow_list_item?filter[' . rawurlencode($key) . ']=n1', 'filter', $key);
        }
        // A declared key alongside an unknown one is still rejected.
        $this->assertUnknownField('/api/allow_list_item?filter[name]=alpha&filter[internal_note]=n1', 'filter', 'internal_note');
        // A model with no declared fields rejects a key that is not a real column.
        $this->assertUnknownField('/api/allow_list_dynamic?filter[not_a_column]=x', 'filter', 'not_a_column');
        $this->assertUnknownField('/api/allow_list_dynamic?filter[' . rawurlencode('la bel') . ']=x', 'filter', 'la bel');
    }

    public function testUnknownSortFieldReturns400(): void
    {
        foreach (self::UNKNOWN_KEYS as $key) {
            $this->assertUnknownField('/api/allow_list_item?sort=' . rawurlencode($key), 'sort', $key);
            $this->assertUnknownField('/api/allow_list_item?sort=-' . rawurlencode($key), 'sort', $key);
            $this->assertUnknownField('/api/allow_list_item?sort=' . rawurlencode("name,{$key}"), 'sort', $key);
            // with a filter present too
            $this->assertUnknownField('/api/allow_list_item?filter[name]=alpha&sort=' . rawurlencode($key), 'sort', $key);
        }
        $this->assertUnknownField('/api/allow_list_dynamic?sort=-not_a_column', 'sort', 'not_a_column');
    }

    public function testDeclaredFilterAndSortStillWork(): void
    {
        // declared field
        $this->assertSame([2], $this->listIds('/api/allow_list_item?filter[name]=bravo'));
        // mapped field, by property AND by its column
        $this->assertSame([1, 3], $this->listIds('/api/allow_list_item?filter[givenName]=Ann&sort=id'));
        $this->assertSame([1, 3], $this->listIds('/api/allow_list_item?filter[first_name]=Ann&sort=id'));
        // -field is DESC, with a filter
        $this->assertSame([3, 1], $this->listIds('/api/allow_list_item?filter[givenName]=Ann&sort=-id'));
        // sort WITHOUT a filter, multi-field, mixed directions
        $this->assertSame([1, 3, 4, 2], $this->listIds('/api/allow_list_item?sort=' . rawurlencode('-score,name')));
        $this->assertSame([2, 4, 3, 1], $this->listIds('/api/allow_list_item?sort=' . rawurlencode('score,-name')));
        // mapped field in sort, by property and by column; empty parts are skipped
        $this->assertSame([1, 3, 2, 4], $this->listIds('/api/allow_list_item?sort=' . rawurlencode('givenName,,-score')));
        $this->assertSame([1, 3, 2, 4], $this->listIds('/api/allow_list_item?sort=' . rawurlencode('first_name,-score')));
        // an autoMap camelCase property (sortRank -> sort_rank), filter AND sort,
        // by the property and by its column
        $this->assertSame([3], $this->listIds('/api/allow_list_item?filter[sortRank]=4'));
        $this->assertSame([2], $this->listIds('/api/allow_list_item?filter[sort_rank]=1'));
        $this->assertSame([2, 4, 1, 3], $this->listIds('/api/allow_list_item?sort=sortRank'));
        $this->assertSame([3, 1, 4, 2], $this->listIds('/api/allow_list_item?sort=-sort_rank'));
        $this->assertSame([3, 1], $this->listIds('/api/allow_list_item?filter[givenName]=Ann&sort=-sortRank'));
        // a model with no declared fields filters and sorts on its real columns,
        // by column name and by the camelCase spelling autoMap maps to it
        $this->assertSame([3, 1], $this->listIds('/api/allow_list_dynamic?filter[label]=x&sort=-weight'));
        $this->assertSame([3, 2, 1], $this->listIds('/api/allow_list_dynamic?sort=-weight'));
        $this->assertSame([2], $this->listIds('/api/allow_list_dynamic?filter[displayOrder]=3'));
        $this->assertSame([3, 1, 2], $this->listIds('/api/allow_list_dynamic?sort=displayOrder'));
        $this->assertSame([1, 3], $this->listIds('/api/allow_list_dynamic?filter[label]=x&sort=-display_order'));
    }

    private function assertInvalidQueryParameter(string $pathAndQuery, string $message): void
    {
        [$status, $json, $raw] = $this->get($pathAndQuery);
        $this->assertSame(400, $status, "{$pathAndQuery}: {$raw}");
        $this->assertSame(
            ['error' => true, 'code' => 'INVALID_QUERY_PARAMETER', 'message' => $message, 'status' => 400],
            $json,
            "{$pathAndQuery}: {$raw}",
        );
    }

    public function testOddTypedQueryValuesReturn400(): void
    {
        $sortMessage = "Query parameter 'sort' must be a single comma-separated string";
        $this->assertInvalidQueryParameter('/api/allow_list_item?sort[]=name', $sortMessage);
        $this->assertInvalidQueryParameter('/api/allow_list_item?sort[a]=name', $sortMessage);
        $this->assertInvalidQueryParameter('/api/allow_list_item?filter[name]=alpha&sort[]=name', $sortMessage);

        $this->assertInvalidQueryParameter('/api/allow_list_item?filter[name][]=alpha', "Filter value for 'name' must be a single value");
        $this->assertInvalidQueryParameter('/api/allow_list_item?filter[name][x]=alpha', "Filter value for 'name' must be a single value");
        $this->assertInvalidQueryParameter('/api/allow_list_item?filter[givenName][x]=Ann', "Filter value for 'givenName' must be a single value");
        $this->assertInvalidQueryParameter('/api/allow_list_dynamic?filter[label][]=x', "Filter value for 'label' must be a single value");

        // the plain forms still work
        $this->assertSame([1], $this->listIds('/api/allow_list_item?filter[name]=alpha&sort=name'));
    }

    // ── AutoCrud write bodies (ADR-0069 G1) ─────────────────────────────────

    /** @return array{0: int, 1: mixed, 2: string} */
    private function write(string $method, string $path, array $body): array
    {
        $ch = curl_init($this->server()->base() . $path);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . Auth::getToken(['sub' => 'allow-list-tester'], self::SECRET),
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $raw = curl_exec($ch);
        if ($raw === false) {
            $this->fail('curl error: ' . curl_error($ch));
        }
        return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string)$raw, true), (string)$raw];
    }

    /**
     * The stored row, read straight from the SQLite file with ext-sqlite3 - an
     * independent reader, so no framework-level query cache can answer instead.
     *
     * @return array<string, mixed>|null
     */
    private function storedRow(string $table, int $id): ?array
    {
        $sqlite = new \SQLite3(self::$dbPath, SQLITE3_OPEN_READONLY);
        try {
            $statement = $sqlite->prepare("SELECT * FROM {$table} WHERE id = :id");
            $statement->bindValue(':id', $id, SQLITE3_INTEGER);
            $row = $statement->execute()->fetchArray(SQLITE3_ASSOC);
            return $row === false ? null : $row;
        } finally {
            $sqlite->close();
        }
    }

    public function testAutocrudWriteBodyAcceptsOnlyDeclaredFields(): void
    {
        // CREATE on a declared model: declared fields (by property and by column)
        // are written; an undeclared real column and non-identifier keys are dropped.
        [$status, $json, $raw] = $this->write('POST', '/api/allow_list_item', [
            'name' => 'echo', 'score' => 5, 'givenName' => 'Eve', 'sort_rank' => 9,
            'internal_note' => 'dropped', 'na me' => 'dropped', "na'me" => 'dropped',
        ]);
        $this->assertSame(201, $status, $raw);
        foreach (['internal_note', 'na me', "na'me"] as $dropped) {
            $this->assertArrayNotHasKey($dropped, $json, $raw);
        }
        $row = $this->storedRow('allow_list_item', (int)$json['id']);
        $this->assertSame('echo', $row['name']);
        $this->assertSame('Eve', $row['first_name']);
        $this->assertSame(9, (int)$row['sort_rank']);
        $this->assertNull($row['internal_note']);

        // UPDATE: the undeclared column keeps its stored value
        $id = (int)$json['id'];
        [$status, $json, $raw] = $this->write('PUT', "/api/allow_list_item/{$id}", [
            'name' => 'echo-2', 'internal_note' => 'dropped', 'na[me' => 'dropped',
        ]);
        $this->assertSame(200, $status, $raw);
        $this->assertArrayNotHasKey('na[me', $json, $raw);
        $this->assertNotSame('dropped', $json['internal_note'] ?? null, $raw);
        $row = $this->storedRow('allow_list_item', $id);
        $this->assertSame('echo-2', $row['name'], $raw);
        $this->assertNull($row['internal_note']);

        // A model with no declared fields writes its REAL columns and drops the rest.
        [$status, $json, $raw] = $this->write('POST', '/api/allow_list_dynamic', [
            'label' => 'z', 'weight' => 11, 'displayOrder' => 4, 'not_a_column' => 'dropped', 'la bel' => 'dropped',
        ]);
        $this->assertSame(201, $status, $raw);
        $this->assertArrayNotHasKey('not_a_column', $json, $raw);
        $this->assertArrayNotHasKey('la bel', $json, $raw);
        $row = $this->storedRow('allow_list_dynamic', (int)$json['id']);
        $this->assertSame('z', $row['label']);
        $this->assertSame(11, (int)$row['weight']);
        $this->assertSame(4, (int)$row['display_order']);
    }

    /** @return int the stored row count of a table, read straight from the SQLite file */
    private function storedCount(string $table): int
    {
        $sqlite = new \SQLite3(self::$dbPath, SQLITE3_OPEN_READONLY);
        try {
            return (int)$sqlite->querySingle("SELECT COUNT(*) FROM {$table}");
        } finally {
            $sqlite->close();
        }
    }

    /** @return array{0: int, 1: mixed, 2: string} */
    private function authorised(string $method, string $path): array
    {
        $ch = curl_init($this->server()->base() . $path);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . Auth::getToken(['sub' => 'allow-list-tester'], self::SECRET)]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $this->fail('curl error: ' . curl_error($ch));
        }
        return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string)$raw, true), (string)$raw];
    }

    /** GET / PUT / DELETE /api/{table}/{id} address exactly the row with that primary key. */
    public function testAutocrudIdRouteAddressesOnlyThatRow(): void
    {
        [, $first] = $this->write('POST', '/api/allow_list_item', ['name' => 'row-a', 'score' => 1]);
        [, $second] = $this->write('POST', '/api/allow_list_item', ['name' => 'row-b', 'score' => 2]);
        $firstId = (int)$first['id'];
        $secondId = (int)$second['id'];

        [$status, $json, $raw] = $this->get("/api/allow_list_item/{$secondId}");
        $this->assertSame(200, $status, $raw);
        $this->assertSame($secondId, (int)$json['id'], $raw);
        $this->assertSame('row-b', $json['name'], $raw);

        [$status, $json, $raw] = $this->write('PUT', "/api/allow_list_item/{$secondId}", ['name' => 'row-b-2']);
        $this->assertSame(200, $status, $raw);
        $this->assertSame($secondId, (int)$json['id'], $raw);
        $this->assertSame('row-b-2', $this->storedRow('allow_list_item', $secondId)['name']);
        $this->assertSame('row-a', $this->storedRow('allow_list_item', $firstId)['name']);
        $this->assertSame('alpha', $this->storedRow('allow_list_item', 1)['name']);

        // an id that is not a key value of any row is a 404 that changes nothing
        $before = $this->storedCount('allow_list_item');
        foreach (['not-an-id', rawurlencode('2 x'), '999999'] as $missing) {
            [$status, , $raw] = $this->get("/api/allow_list_item/{$missing}");
            $this->assertSame(404, $status, "GET {$missing}: {$raw}");
            [$status, , $raw] = $this->write('PUT', "/api/allow_list_item/{$missing}", ['name' => 'nope']);
            $this->assertSame(404, $status, "PUT {$missing}: {$raw}");
            [$status, , $raw] = $this->authorised('DELETE', "/api/allow_list_item/{$missing}");
            $this->assertSame(404, $status, "DELETE {$missing}: {$raw}");
        }
        $this->assertSame($before, $this->storedCount('allow_list_item'));
        $this->assertSame('alpha', $this->storedRow('allow_list_item', 1)['name']);

        // DELETE removes exactly the addressed row, on a model with no declared fields too
        [, $third] = $this->write('POST', '/api/allow_list_dynamic', ['label' => 'd1', 'weight' => 1]);
        [, $fourth] = $this->write('POST', '/api/allow_list_dynamic', ['label' => 'd2', 'weight' => 2]);
        [$status, , $raw] = $this->authorised('DELETE', '/api/allow_list_dynamic/' . (int)$fourth['id']);
        $this->assertSame(200, $status, $raw);
        $this->assertNull($this->storedRow('allow_list_dynamic', (int)$fourth['id']));
        $this->assertSame('d1', $this->storedRow('allow_list_dynamic', (int)$third['id'])['label']);
        $this->assertSame('x', $this->storedRow('allow_list_dynamic', 1)['label']);
    }

    // ── AutoCrud uses the connection it was constructed with ────────────────

    public function testAutocrudListUsesTheRegisteredConnection(): void
    {
        $globalPath = sys_get_temp_dir() . '/ala_global_' . bin2hex(random_bytes(5)) . '.db';
        $registeredPath = sys_get_temp_dir() . '/ala_registered_' . bin2hex(random_bytes(5)) . '.db';
        $global = Database::create('sqlite:///' . $globalPath);
        $registered = Database::create('sqlite:///' . $registeredPath);
        foreach ([$global, $registered] as $db) {
            $db->execute('CREATE TABLE allow_list_conn (id INTEGER PRIMARY KEY, name TEXT)');
        }
        $global->execute("INSERT INTO allow_list_conn (id, name) VALUES (1, 'global-row')");
        $registered->execute("INSERT INTO allow_list_conn (id, name) VALUES (7, 'registered-row')");
        $registered->execute("INSERT INTO allow_list_conn (id, name) VALUES (8, 'other-row')");

        $globalProperty = new \ReflectionProperty(ORM::class, '_globalDb');
        $previousGlobal = $globalProperty->getValue();
        ORM::bindDatabase($global);
        Router::clear();
        try {
            $crud = new AutoCrud($registered);
            $crud->register(AllowListConnItem::class);
            $crud->generateRoutes();

            $ids = static function (array $query): array {
                $response = Router::dispatch(
                    Request::create(method: 'GET', path: '/api/allow_list_conn', query: $query),
                    new Response(testing: true),
                );
                return array_map(static fn (array $record): int => (int)$record['id'], $response->getJsonBody()['records']);
            };

            // filtered, sorted and unfiltered lists all read the REGISTERED database
            $this->assertSame([7], $ids(['filter' => ['name' => 'registered-row']]));
            $this->assertSame([8, 7], $ids(['sort' => '-id']));
            $this->assertSame([7, 8], $ids([]));
            $this->assertSame([], $ids(['filter' => ['name' => 'global-row']]));
        } finally {
            Router::clear();
            $globalProperty->setValue(null, $previousGlobal);
            $global->close();
            $registered->close();
            @unlink($globalPath);
            @unlink($registeredPath);
        }
    }

    // ── GraphQL auto-schema id argument ─────────────────────────────────────

    public function testGraphqlIdArgumentAddressesOnlyThatRow(): void
    {
        $db = Database::create('sqlite::memory:');
        $db->execute('CREATE TABLE allow_list_gql (id INTEGER PRIMARY KEY, name TEXT)');
        $db->insert('allow_list_gql', [['id' => 1, 'name' => 'alpha'], ['id' => 2, 'name' => 'bravo'], ['id' => 3, 'name' => 'charlie']]);
        $names = static fn (): array => array_column($db->fetch('SELECT id, name FROM allow_list_gql ORDER BY id')->records, 'name', 'id');

        $graphql = new \Tina4\GraphQL();
        $graphql->fromOrm(new AllowListGqlItem($db));

        $result = $graphql->execute('{ allowListGqlItem(id: "2") { id name } }');
        $this->assertSame('bravo', $result['data']['allowListGqlItem']['name'] ?? null, json_encode($result));

        $result = $graphql->execute('mutation { updateAllowListGqlItem(id: "3", name: "charlie-2") { id name } }');
        $this->assertSame('charlie-2', $result['data']['updateAllowListGqlItem']['name'] ?? null, json_encode($result));
        $this->assertSame([1 => 'alpha', 2 => 'bravo', 3 => 'charlie-2'], $names());

        $result = $graphql->execute('mutation { deleteAllowListGqlItem(id: "2") }');
        $this->assertTrue($result['data']['deleteAllowListGqlItem'] ?? null, json_encode($result));
        $this->assertSame([1 => 'alpha', 3 => 'charlie-2'], $names());

        // an id that is not a key value of any row addresses nothing
        foreach (['not-an-id', '2 x', '999'] as $missing) {
            $query = sprintf('{ allowListGqlItem(id: %s) { id name } }', json_encode($missing));
            $result = $graphql->execute($query);
            $this->assertArrayHasKey('data', $result, json_encode($result));
            $this->assertNull($result['data']['allowListGqlItem'] ?? null, json_encode($result));
            $result = $graphql->execute(sprintf('mutation { updateAllowListGqlItem(id: %s, name: "nope") { id } }', json_encode($missing)));
            $this->assertNull($result['data']['updateAllowListGqlItem'] ?? null, json_encode($result));
            $result = $graphql->execute(sprintf('mutation { deleteAllowListGqlItem(id: %s) }', json_encode($missing)));
            $this->assertFalse($result['data']['deleteAllowListGqlItem'] ?? null, json_encode($result));
        }
        $this->assertSame([1 => 'alpha', 3 => 'charlie-2'], $names());
        $db->close();
    }

    // ── ORM::find(filter-map) on every engine ───────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function engineProvider(): array
    {
        return [
            'sqlite'   => ['sqlite'],
            'postgres' => ['postgres'],
            'mysql'    => ['mysql'],
            'mssql'    => ['mssql'],
            'firebird' => ['firebird'],
        ];
    }

    /** Same coordinates and gating as OrmFieldsContractTest::engineDb(). */
    private function engineDb(string $engine): Database
    {
        $reach = static function (string $host, int $port): bool {
            $connection = @fsockopen($host, $port, $errorCode, $errorMessage, 2.0);
            if ($connection) {
                fclose($connection);
                return true;
            }
            return false;
        };
        if ($engine === 'sqlite') {
            return Database::create('sqlite::memory:');
        }
        if ($engine === 'postgres') {
            $host = getenv('TINA4_TEST_PG_HOST') ?: '127.0.0.1';
            $port = (int)(getenv('TINA4_TEST_PG_PORT') ?: 55432);
            if (!$reach($host, $port)) {
                $this->markTestSkipped("[needs:postgres] postgres unreachable at {$host}:{$port} (set TINA4_TEST_PG_*)");
            }
            $name = getenv('TINA4_TEST_PG_DB') ?: 'tina4_php';
            $user = getenv('TINA4_TEST_PG_USERNAME') ?: 'tina4';
            $password = getenv('TINA4_TEST_PG_PASSWORD') ?: 'tina4';
            return Database::create("postgres://{$user}:{$password}@{$host}:{$port}/{$name}");
        }
        if ($engine === 'mysql') {
            $host = getenv('TINA4_TEST_MYSQL_HOST') ?: '127.0.0.1';
            $port = (int)(getenv('TINA4_TEST_MYSQL_PORT') ?: 3306);
            if (!$reach($host, $port)) {
                $this->markTestSkipped("[needs:mysql] mysql unreachable at {$host}:{$port} (set TINA4_TEST_MYSQL_*)");
            }
            $name = getenv('TINA4_TEST_MYSQL_DB') ?: 'tina4_test';
            $user = getenv('TINA4_TEST_MYSQL_USERNAME') ?: 'tina4';
            $password = getenv('TINA4_TEST_MYSQL_PASSWORD') ?: 'tina4';
            return Database::create("mysql://{$user}:{$password}@{$host}:{$port}/{$name}");
        }
        if ($engine === 'mssql') {
            $host = getenv('TINA4_TEST_MSSQL_HOST') ?: '127.0.0.1';
            $port = (int)(getenv('TINA4_TEST_MSSQL_PORT') ?: 1433);
            if (!$reach($host, $port)) {
                $this->markTestSkipped("[needs:mssql] mssql unreachable at {$host}:{$port} (set TINA4_TEST_MSSQL_*)");
            }
            $name = getenv('TINA4_TEST_MSSQL_DB') ?: 'tina4_test';
            $user = getenv('TINA4_TEST_MSSQL_USERNAME') ?: 'sa';
            $password = getenv('TINA4_TEST_MSSQL_PASSWORD') ?: 'TinaSQL123!Secure';
            return Database::create("mssql://{$host}:{$port}/{$name}", username: $user, password: $password);
        }
        $url = getenv('TINA4_TEST_FIREBIRD_URL') ?: '';
        if ($url === '') {
            $this->markTestSkipped('[needs:firebird] TINA4_TEST_FIREBIRD_URL not set (needs a live Firebird)');
        }
        return Database::create($url);
    }

    private function dropItemTable(Database $db, string $engine): void
    {
        try {
            if ($engine === 'mssql') {
                $db->execute("IF OBJECT_ID('ala_php_item', 'U') IS NOT NULL DROP TABLE ala_php_item");
            } elseif ($engine === 'firebird') {
                if ($db->tableExists('ala_php_item')) {
                    $db->execute('DROP TABLE ala_php_item');
                    $db->commit();
                }
            } else {
                $db->execute('DROP TABLE IF EXISTS ala_php_item');
            }
        } catch (\Throwable) {
            // best effort
        }
    }

    private function createItemTable(Database $db, string $engine): void
    {
        $this->dropItemTable($db, $engine);
        // MSSQL over FreeTDS defaults a column without NULL/NOT NULL to NOT NULL;
        // say NULL there (Firebird does not accept the keyword, the rest default to it).
        $nullable = $engine === 'mssql' ? ' NULL' : '';
        $db->execute("CREATE TABLE ala_php_item (id INTEGER NOT NULL PRIMARY KEY, name VARCHAR(50){$nullable}, first_name VARCHAR(50){$nullable}, sort_rank INTEGER{$nullable}, internal_note VARCHAR(50){$nullable})");
        if ($engine === 'firebird') {
            $db->commit();
        }
        foreach ([[1, 'alpha', 'Ann', 3, 'n1'], [2, 'bravo', 'Ben', 1, 'n2'], [3, 'charlie', 'Ann', 4, 'n3']] as $row) {
            $db->execute('INSERT INTO ala_php_item (id, name, first_name, sort_rank, internal_note) VALUES (?, ?, ?, ?, ?)', $row);
        }
        if ($engine === 'firebird') {
            $db->commit();
        }
    }

    /** @return array<int, int> */
    private static function ids(iterable $models): array
    {
        $ids = [];
        foreach ($models as $model) {
            $ids[] = (int)$model->id;
        }
        return $ids;
    }

    private function assertFindRejects(string $modelClass, string $key): void
    {
        $shortName = (new \ReflectionClass($modelClass))->getShortName();
        try {
            $modelClass::find([$key => 'n1']);
            $this->fail("{$shortName}::find() accepted the unknown key '{$key}'");
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame("Unknown filter field '{$key}' for model {$shortName}", $exception->getMessage());
        }
    }

    #[DataProvider('engineProvider')]
    public function testOrmFindRejectsUndeclaredFilterKey(string $engine): void
    {
        $db = $this->engineDb($engine);
        ORM::bindDatabase($db, 'identifier_allow_list');
        try {
            $this->createItemTable($db, $engine);

            // positive: a declared field, a mapped field by property AND by column
            $this->assertSame([1], self::ids(AllowListOrmItem::find(['name' => 'alpha'])));
            $this->assertSame([1, 3], self::ids(AllowListOrmItem::find(['givenName' => 'Ann'], 100, 0, 'id')));
            $this->assertSame([1, 3], self::ids(AllowListOrmItem::find(['first_name' => 'Ann'], 100, 0, 'id')));
            $this->assertSame([2], self::ids(AllowListOrmItem::find(['id' => 2])));
            // an autoMap camelCase property, by the property and by its column
            $this->assertSame([3], self::ids(AllowListOrmItem::find(['sortRank' => 4])));
            $this->assertSame([2], self::ids(AllowListOrmItem::find(['sort_rank' => 1])));
            // the raw $orderBy argument is unchanged (a documented raw ORDER BY clause)
            $this->assertSame([3, 1], self::ids(AllowListOrmItem::find(['givenName' => 'Ann'], 100, 0, 'id DESC')));

            // negative: nothing outside the declared fields resolves
            foreach (self::UNKNOWN_KEYS as $key) {
                $this->assertFindRejects(AllowListOrmItem::class, $key);
            }

            // a model with NO declared fields resolves against the real columns
            $this->assertSame([2], self::ids(AllowListOrmDynamic::find(['name' => 'bravo'])));
            $this->assertSame([3], self::ids(AllowListOrmDynamic::find(['internal_note' => 'n3'])));
            $this->assertSame([2], self::ids(AllowListOrmDynamic::find(['sortRank' => 1])));
            $this->assertSame([1], self::ids(AllowListOrmDynamic::find(['sort_rank' => 3])));
            foreach (['not_a_column', 'na me', "na'me", 'na[me'] as $key) {
                $this->assertFindRejects(AllowListOrmDynamic::class, $key);
            }
        } finally {
            $this->dropItemTable($db, $engine);
            if ($engine === 'firebird') {
                try {
                    $db->close();
                } catch (\Throwable) {
                }
            }
        }
    }

    /** @return array<string, mixed>|null one ala_php_item row with lower-cased keys (Firebird reports upper case) */
    private static function itemRow(Database $db, int $id): ?array
    {
        $row = $db->fetchOne('SELECT * FROM ala_php_item WHERE id = ?', [$id]);
        return $row === null ? null : array_change_key_case($row, CASE_LOWER);
    }

    private static function itemCount(Database $db): int
    {
        $row = $db->fetchOne('SELECT COUNT(*) AS row_total FROM ala_php_item');
        return (int)array_change_key_case($row, CASE_LOWER)['row_total'];
    }

    #[DataProvider('engineProvider')]
    public function testOrmSaveWritesOnlyDeclaredFields(string $engine): void
    {
        $db = $this->engineDb($engine);
        ORM::bindDatabase($db, 'identifier_allow_list');
        try {
            $this->createItemTable($db, $engine);

            // INSERT through a declared model: an undeclared real column set by
            // fill() and by plain assignment is not written.
            $item = new AllowListOrmItem(['id' => 10, 'name' => 'ten', 'givenName' => 'Tia', 'internal_note' => 'dropped']);
            $item->other_note = 'dropped';
            $this->assertNotFalse($item->save(), (string)$item->getError());
            $row = self::itemRow($db, 10);
            $this->assertSame('ten', $row['name']);
            $this->assertSame('Tia', $row['first_name']);
            $this->assertNull($row['internal_note']);

            // UPDATE through a declared model keeps the undeclared column as stored.
            $loaded = new AllowListOrmItem();
            $this->assertTrue($loaded->load('id = ?', [1]));
            $loaded->name = 'alpha-2';
            $loaded->internal_note = 'dropped';
            $this->assertNotFalse($loaded->save(), (string)$loaded->getError());
            $row = self::itemRow($db, 1);
            $this->assertSame('alpha-2', $row['name']);
            $this->assertSame('n1', $row['internal_note']);

            // A model with no declared fields writes real columns only; a key that
            // is not a column is dropped instead of reaching the column list.
            $dynamic = new AllowListOrmDynamic(['id' => 11, 'name' => 'eleven', 'internal_note' => 'kept', 'not_a_column' => 'dropped', 'na me' => 'dropped']);
            $this->assertNotFalse($dynamic->save(), (string)$dynamic->getError());
            $row = self::itemRow($db, 11);
            $this->assertSame('eleven', $row['name']);
            $this->assertSame('kept', $row['internal_note']);
        } finally {
            $this->dropItemTable($db, $engine);
            if ($engine === 'firebird') {
                try {
                    $db->close();
                } catch (\Throwable) {
                }
            }
        }
    }

    private const NON_IDENTIFIER_KEYS = ['na me', "na'me", 'na[me', 'na(me', '1name', ''];

    private function assertInvalidColumn(string $key, callable $write): void
    {
        try {
            $write();
            $this->fail('the write helper accepted the column key ' . json_encode($key));
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame("Invalid column name '{$key}'", $exception->getMessage());
        }
    }

    #[DataProvider('engineProvider')]
    public function testDbWriteHelpersRejectNonIdentifierKeys(string $engine): void
    {
        $db = $this->engineDb($engine);
        try {
            $this->createItemTable($db, $engine);
            $before = self::itemCount($db);

            foreach (self::NON_IDENTIFIER_KEYS as $key) {
                $this->assertInvalidColumn($key, fn () => $db->insert('ala_php_item', ['id' => 20, $key => 'x']));
                $this->assertInvalidColumn($key, fn () => $db->insert('ala_php_item', [['id' => 21, $key => 'x'], ['id' => 22, $key => 'y']]));
                $this->assertInvalidColumn($key, fn () => $db->update('ala_php_item', [$key => 'x'], 'id = ?', [1]));
                $this->assertInvalidColumn($key, fn () => $db->update('ala_php_item', ['name' => 'x'], [$key => 1]));
                $this->assertInvalidColumn($key, fn () => $db->delete('ala_php_item', [$key => 1]));
                $this->assertInvalidColumn($key, fn () => $db->delete('ala_php_item', [['id' => 1], [$key => 2]]));
            }
            if ($engine === 'firebird') {
                $db->commit();
            }
            $this->assertSame($before, self::itemCount($db));
            $this->assertSame('alpha', self::itemRow($db, 1)['name']);

            // positive: plain identifiers still insert / update / delete
            $db->insert('ala_php_item', ['id' => 30, 'name' => 'thirty', 'first_name' => 'Tom']);
            $db->insert('ala_php_item', [['id' => 31, 'name' => 'a31'], ['id' => 32, 'name' => 'a32']]);
            $db->update('ala_php_item', ['name' => 'thirty-2'], ['id' => 30]);
            $db->update('ala_php_item', ['name' => 'a31-2'], 'id = ?', [31]);
            $db->delete('ala_php_item', ['id' => 32]);
            if ($engine === 'firebird') {
                $db->commit();
            }
            $this->assertSame('thirty-2', self::itemRow($db, 30)['name']);
            $this->assertSame('Tom', self::itemRow($db, 30)['first_name']);
            $this->assertSame('a31-2', self::itemRow($db, 31)['name']);
            $this->assertNull(self::itemRow($db, 32));
        } finally {
            $this->dropItemTable($db, $engine);
            if ($engine === 'firebird') {
                try {
                    $db->close();
                } catch (\Throwable) {
                }
            }
        }
    }

    /** The batch (list) form of delete removes each listed row (it used to remove none). */
    public function testDatabaseDeleteAcceptsAListOfFilterMaps(): void
    {
        $db = Database::create('sqlite::memory:');
        $db->execute('CREATE TABLE ala_php_item (id INTEGER NOT NULL PRIMARY KEY, name VARCHAR(50))');
        $db->insert('ala_php_item', [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b'], ['id' => 3, 'name' => 'c']]);

        $result = $db->delete('ala_php_item', [['id' => 1], ['id' => 2]]);

        $this->assertSame(2, $result->affectedRows);
        $this->assertSame([3], array_map(static fn (array $row): int => (int)$row['id'], $db->fetch('SELECT id FROM ala_php_item')->records));
        $db->close();
    }

    // ── DocStore SQLite fallback + real MongoDB ─────────────────────────────

    private const UNSAFE_PATHS = ['na me', "na'me", 'na"me', 'na[0]', 'a..b', '.a', 'a.', '', "a_b\n", 'a$b'];

    private const DOCUMENTS = [
        ['_id' => 'd1', 'a_b' => 1, 'a-b' => 'x', 'A1' => 10, 'nested' => ['key' => 'k1']],
        ['_id' => 'd2', 'a_b' => 2, 'a-b' => 'y', 'A1' => 20, 'nested' => ['key' => 'k2']],
        ['_id' => 'd3', 'a_b' => 1, 'a-b' => 'y', 'A1' => 30, 'nested' => ['key' => 'k1']],
    ];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['TINA4_MONGO_URI', 'TINA4_SESSION_MONGO_URI', 'TINA4_SESSION_MONGO_URL', 'TINA4_DOC_STORE_PATH', 'TINA4_MONGO_DB'] as $key) {
            $this->savedEnv[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            $value === false ? putenv($key) : putenv("{$key}={$value}");
        }
        resetDefaultStore();
    }

    /** A fresh fallback collection on its own SQLite file, reached the way a call site reaches it. */
    private function fallbackCollection(): object
    {
        foreach (['TINA4_MONGO_URI', 'TINA4_SESSION_MONGO_URI', 'TINA4_SESSION_MONGO_URL'] as $key) {
            putenv($key);
        }
        resetDefaultStore();
        putenv('TINA4_DOC_STORE_PATH=' . sys_get_temp_dir() . '/ala_ds_' . bin2hex(random_bytes(6)) . '.db');
        return getCollection('ala_' . bin2hex(random_bytes(5)));
    }

    private function assertRejectsPath(string $path, callable $operation): void
    {
        try {
            $operation();
            $this->fail('DocStore accepted the field path ' . json_encode($path));
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(
                "DocStore: invalid field path '{$path}' - each dot-separated segment must match [A-Za-z0-9_-]+",
                $exception->getMessage(),
            );
        }
    }

    public function testDocstoreRejectsUnsafeFieldPath(): void
    {
        $collection = $this->fallbackCollection();
        $collection->insertMany(self::DOCUMENTS);

        foreach (self::UNSAFE_PATHS as $path) {
            // filter key
            $this->assertRejectsPath($path, fn () => $collection->find([$path => 1])->toArray());
            // nested inside $or / $and
            $this->assertRejectsPath($path, fn () => $collection->find(['$or' => [['a_b' => 1], [$path => 1]]])->toArray());
            $this->assertRejectsPath($path, fn () => $collection->find(['$and' => [['a_b' => 1], ['$or' => [[$path => 1]]]]])->toArray());
            // operator field
            $this->assertRejectsPath($path, fn () => $collection->find([$path => ['$gt' => 1]])->toArray());
            $this->assertRejectsPath($path, fn () => $collection->countDocuments([$path => ['$exists' => true]]));
            // sort key, both spellings
            $this->assertRejectsPath($path, fn () => $collection->find([])->sort($path, -1)->toArray());
            $this->assertRejectsPath($path, fn () => $collection->find([])->sort([$path => 1])->toArray());
            // a write whose filter is rejected touches nothing
            $this->assertRejectsPath($path, fn () => $collection->deleteMany([$path => 1]));
            $this->assertRejectsPath($path, fn () => $collection->updateMany([$path => 1], ['$set' => ['a_b' => 9]]));
        }
        $this->assertSame(3, $collection->countDocuments([]));
        $this->assertSame(2, $collection->countDocuments(['a_b' => 1]));
    }

    /**
     * The same queries, used by both the fallback case and the real-Mongo case.
     *
     * @return array<string, callable(object): array<int, string>>
     */
    private static function safePathQueries(): array
    {
        $ids = static function (iterable $documents): array {
            $out = [];
            foreach ($documents as $document) {
                $out[] = (string)$document['_id'];
            }
            return $out;
        };
        return [
            'a_b'          => fn (object $c) => $ids($c->find(['a_b' => 1])->sort('_id', 1)->toArray()),
            'a-b'          => fn (object $c) => $ids($c->find(['a-b' => 'y'])->sort('_id', 1)->toArray()),
            'A1 operator'  => fn (object $c) => $ids($c->find(['A1' => ['$gte' => 20]])->sort('_id', 1)->toArray()),
            'nested.key'   => fn (object $c) => $ids($c->find(['nested.key' => 'k1'])->sort('_id', 1)->toArray()),
            '_id'          => fn (object $c) => $ids($c->find(['_id' => 'd2'])->toArray()),
            '$or'          => fn (object $c) => $ids($c->find(['$or' => [['a_b' => 2], ['nested.key' => 'k1']]])->sort('_id', 1)->toArray()),
            'sort A1'      => fn (object $c) => $ids($c->find([])->sort('A1', -1)->toArray()),
            'sort a-b,_id' => fn (object $c) => $ids($c->find([])->sort(['a-b' => 1, '_id' => -1])->toArray()),
            'sort nested'  => fn (object $c) => $ids($c->find([])->sort([['nested.key', -1], ['_id', 1]])->toArray()),
            'count a-b'    => fn (object $c) => [(string)$c->countDocuments(['a-b' => 'y'])],
        ];
    }

    /** @return array<string, array<int, string>> */
    private static function expectedSafePathResults(): array
    {
        return [
            'a_b'          => ['d1', 'd3'],
            'a-b'          => ['d2', 'd3'],
            'A1 operator'  => ['d2', 'd3'],
            'nested.key'   => ['d1', 'd3'],
            '_id'          => ['d2'],
            '$or'          => ['d1', 'd2', 'd3'],
            'sort A1'      => ['d3', 'd2', 'd1'],
            'sort a-b,_id' => ['d1', 'd3', 'd2'],
            'sort nested'  => ['d2', 'd1', 'd3'],
            'count a-b'    => ['2'],
        ];
    }

    public function testDocstoreAcceptsSafeFieldPaths(): void
    {
        $collection = $this->fallbackCollection();
        $collection->insertMany(self::DOCUMENTS);

        $results = [];
        foreach (self::safePathQueries() as $name => $query) {
            $results[$name] = $query($collection);
        }
        $this->assertSame(self::expectedSafePathResults(), $results);
    }

    public function testDocstoreSafePathsMatchOnRealMongo(): void
    {
        $uri = getenv('TINA4_TEST_MONGO_URI') ?: 'mongodb://127.0.0.1:27017';
        if (!extension_loaded('mongodb') || !class_exists(\MongoDB\Client::class)) {
            $this->markTestSkipped("MongoDB unreachable at {$uri}: the mongodb PHP extension is not installed");
        }
        $client = new \MongoDB\Client($uri, [], ['serverSelectionTimeoutMS' => 3000]);
        try {
            $client->selectDatabase('admin')->command(['ping' => 1]);
        } catch (\Throwable $exception) {
            $this->markTestSkipped("MongoDB unreachable at {$uri}: " . $exception->getMessage());
        }

        $fallback = $this->fallbackCollection();
        $fallback->insertMany(self::DOCUMENTS);

        $databaseName = 'tina4_sqli_php_' . getmypid() . '_' . bin2hex(random_bytes(3));
        putenv("TINA4_MONGO_URI={$uri}");
        putenv("TINA4_MONGO_DB={$databaseName}");
        try {
            $mongo = getCollection('ala_parity');
            $mongo->insertMany(self::DOCUMENTS);

            $fallbackResults = [];
            $mongoResults = [];
            foreach (self::safePathQueries() as $name => $query) {
                $fallbackResults[$name] = $query($fallback);
                $mongoResults[$name] = $query($mongo);
            }
            $this->assertSame(self::expectedSafePathResults(), $mongoResults, 'real MongoDB');
            $this->assertSame($mongoResults, $fallbackResults, 'fallback must return what real MongoDB returns');

            // A path the fallback rejects raises there rather than silently matching nothing.
            $this->assertRejectsPath('na me', fn () => $fallback->find(['na me' => 1])->toArray());
        } finally {
            $client->dropDatabase($databaseName);
        }
    }
}
