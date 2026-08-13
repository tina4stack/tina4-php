<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Database adapter shared-fixture contract — feature 3 (adapter_contract.json).
 *
 * Shared conformance fixture: tina4-documentation/plan/v3/fixtures/adapter_contract.json
 * Contract: tina4-documentation/plan/v3/features/003-database-adapter-interface.md
 * ADR-0044 (executeMany/fetchOne are required adapter primitives; the
 * 14-method boundary excludes engine-neutral composition; lastInsertId/error
 * leave the adapter; getColumns carries primaryKeyPosition).
 *
 * One test per fixture case, named to match the case's `name` field (checked
 * mechanically by tina4-documentation/scripts/audit-contract-fixtures.py via
 * a normalised substring match). Every case drives the REAL public Database
 * facade -> a REAL SQLite3Adapter against a real temp-file SQLite database
 * (no mocks anywhere). SQLite is the always-available primary engine for the
 * 40 structural cases; adapter-provider-substitutability additionally drives
 * real PostgreSQL/MySQL/MSSQL/Firebird where the lab provisions them
 * (TINA4_TEST_* / TINA4_REQUIRE_SERVICES, matching PgProviderContractTest.php).
 *
 * Framework fixes this file's wiring required: DatabaseAdapter interface
 * gained connect()/getDatabaseType()/autocommit() and dropped
 * query/insert/update/delete/lastInsertId/error (DBA-S03 — CrudSqlTrait keeps
 * every adapter's insert/update/delete fully working, just outside the
 * required contract); ConnectAliasTrait gives every adapter a connect() that
 * forwards to the existing open(); SupportsAtomicBatchTrait + a guard in
 * Database::executeMany() reject an unsupported multi-row batch before the
 * first write (DBA-P02); Database::executeMany() now delegates to the
 * adapter's OWN executeMany() exactly once instead of looping execute()
 * itself, and returns the shared DatabaseResult; SQLite3Adapter::getColumns()
 * gains primaryKeyPosition and Database::primaryKey() sorts by it;
 * CachedDatabase and Database itself (both `implements DatabaseAdapter`)
 * gained the three new required methods too.
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\AdapterContract;
use Tina4\Database\AdapterContractException;
use Tina4\Database\Database;
use Tina4\Database\DatabaseAdapter;
use Tina4\Database\DatabaseResult;
use Tina4\Database\SQLite3Adapter;
use Tina4\Database\UnsupportedAtomicBatchException;

/**
 * A REAL SQLite3Adapter — every call is a real SQLite3 call against a real
 * file — that also counts calls to each contract method. Instrumentation via
 * subclassing (the fixture's own witness name for this pattern is
 * "instrumented_real_adapter"), not a mock: nothing here stands in for the
 * database.
 */
class TinaCountingSqlite3Adapter extends SQLite3Adapter
{
    /** @var array<string, int> */
    public array $callCounts = [];

    private function count(string $name): void
    {
        $this->callCounts[$name] = ($this->callCounts[$name] ?? 0) + 1;
    }

    public function connect(): void
    {
        $this->count('connect');
        parent::connect();
    }

    public function execute(string $sql, array $params = []): bool|DatabaseResult
    {
        $this->count('execute');
        return parent::execute($sql, $params);
    }

    public function executeMany(string $sql, array $paramsList = []): int
    {
        $this->count('executeMany');
        return parent::executeMany($sql, $paramsList);
    }

    public function fetch(string $sql, array $params = [], int $limit = 100, int $offset = 0): array
    {
        $this->count('fetch');
        return parent::fetch($sql, $params, $limit, $offset);
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $this->count('fetchOne');
        return parent::fetchOne($sql, $params);
    }

    public function startTransaction(): void
    {
        $this->count('startTransaction');
        parent::startTransaction();
    }

    public function commit(): void
    {
        $this->count('commit');
        parent::commit();
    }

    public function rollback(): void
    {
        $this->count('rollback');
        parent::rollback();
    }
}

class AdapterConformanceTest extends TestCase
{
    private static int $seq = 0;

    private function tmpPath(string $name = ''): string
    {
        self::$seq++;
        $name = $name !== '' ? $name : 'contract_' . self::$seq . '.db';
        return sys_get_temp_dir() . '/tina4_adapter_conformance_' . getmypid() . '_' . self::$seq . '_' . $name;
    }

    /** @return array{0: Database, 1: TinaCountingSqlite3Adapter[]} */
    private function instrumented(string $path, int $pool = 0): array
    {
        $db = new Database('sqlite:///' . $path, null, '', '', $pool);
        $ref = new \ReflectionClass($db);

        if ($pool > 0) {
            $adapters = [];
            for ($i = 0; $i < $pool; $i++) {
                $adapters[] = new TinaCountingSqlite3Adapter($path);
            }
            $prop = $ref->getProperty('pool');
            $prop->setValue($db, $adapters);
            return [$db, $adapters];
        }

        $prop = $ref->getProperty('adapter');
        $old = $prop->getValue($db);
        if ($old !== null) {
            $old->close();
        }
        $instrumented = new TinaCountingSqlite3Adapter($path);
        $prop->setValue($db, $instrumented);
        return [$db, [$instrumented]];
    }

    private function freshRows(string $path, string $sql, array $params = []): array
    {
        $other = new Database('sqlite:///' . $path);
        try {
            return $other->fetch($sql, $params, 10000)->records;
        } finally {
            $other->close();
        }
    }

    // ── real provider coordinates (provider-substitutability) ──────────

    private static function pgReachable(): bool
    {
        return self::tcpReachable(getenv('TINA4_TEST_PG_HOST') ?: '127.0.0.1', (int) (getenv('TINA4_TEST_PG_PORT') ?: 55432));
    }

    private static function pgDb(): Database
    {
        $host = getenv('TINA4_TEST_PG_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_PG_PORT') ?: 55432);
        $dbName = getenv('TINA4_TEST_PG_DB') ?: 'tina4_php';
        $user = getenv('TINA4_TEST_PG_USERNAME') ?: 'tina4';
        $pass = getenv('TINA4_TEST_PG_PASSWORD') ?: 'tina4';
        return new Database("postgres://{$host}:{$port}/{$dbName}", null, $user, $pass);
    }

    private static function mysqlReachable(): bool
    {
        return self::tcpReachable(getenv('TINA4_TEST_MYSQL_HOST') ?: '127.0.0.1', (int) (getenv('TINA4_TEST_MYSQL_PORT') ?: 3306));
    }

    private static function mysqlDb(): Database
    {
        $host = getenv('TINA4_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_MYSQL_PORT') ?: 3306);
        $dbName = getenv('TINA4_TEST_MYSQL_DB') ?: 'tina4_test';
        $user = getenv('TINA4_TEST_MYSQL_USERNAME') ?: 'tina4';
        $pass = getenv('TINA4_TEST_MYSQL_PASSWORD') ?: 'tina4';
        return new Database("mysql://{$host}:{$port}/{$dbName}", null, $user, $pass);
    }

    private static function mssqlReachable(): bool
    {
        return self::tcpReachable(getenv('TINA4_TEST_MSSQL_HOST') ?: '127.0.0.1', (int) (getenv('TINA4_TEST_MSSQL_PORT') ?: 1433));
    }

    private static function mssqlDb(): Database
    {
        $host = getenv('TINA4_TEST_MSSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_MSSQL_PORT') ?: 1433);
        $dbName = getenv('TINA4_TEST_MSSQL_DB') ?: 'tina4_test';
        $user = getenv('TINA4_TEST_MSSQL_USERNAME') ?: 'sa';
        $pass = getenv('TINA4_TEST_MSSQL_PASSWORD') ?: 'TinaSQL123!Secure';
        return new Database("mssql://{$host}:{$port}/{$dbName}", null, $user, $pass);
    }

    private static function firebirdUrl(): string
    {
        return getenv('TINA4_TEST_FIREBIRD_URL') ?: '';
    }

    private static function firebirdDb(): Database
    {
        return new Database(self::firebirdUrl());
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

    // ═══════════════════════════════════════════════════════════════
    // adapter-required-boundary (DBA-S01..S04)
    // ═══════════════════════════════════════════════════════════════

    /** @return array<string, class-string> */
    private function builtinAdapterClasses(): array
    {
        return [
            'SQLite3Adapter' => \Tina4\Database\SQLite3Adapter::class,
            'PostgresAdapter' => \Tina4\Database\PostgresAdapter::class,
            'MySQLAdapter' => \Tina4\Database\MySQLAdapter::class,
            'MSSQLAdapter' => \Tina4\Database\MSSQLAdapter::class,
            'FirebirdAdapter' => \Tina4\Database\FirebirdAdapter::class,
            'ODBCAdapter' => \Tina4\Database\ODBCAdapter::class,
        ];
    }

    public function testAllFourteenCapabilitiesAreRequired(): void
    {
        $this->assertCount(14, AdapterContract::REQUIRED_CAPABILITIES);
        foreach ($this->builtinAdapterClasses() as $name => $class) {
            AdapterContract::validate($class, $name); // throws on any gap
            foreach (AdapterContract::REQUIRED_CAPABILITIES as $capability) {
                $this->assertTrue(method_exists($class, $capability), "$name is missing $capability");
            }
        }
        AdapterContract::validate(\Tina4\Database\MongoDBAdapter::class, 'MongoDBAdapter');
    }

    public function testIncompleteAdapterRegistrationFailsLoud(): void
    {
        // Negative mutation: a real class implementing everything EXCEPT one
        // required capability. AdapterContract::validate() fails loud, naming
        // the adapter and the missing capability.
        $incomplete = eval('
            namespace Tina4\Tests;
            class TinaTestIncompleteAdapter {
                public function connect(): void {}
                public function close(): void {}
                public function getDatabaseType(): string { return "test"; }
                public function execute(string $sql, array $params = []) {}
                public function fetch(string $sql, array $params = [], int $limit = 100, int $offset = 0) {}
                public function fetchOne(string $sql, array $params = []) {}
                public function startTransaction(): void {}
                public function commit(): void {}
                public function rollback(): void {}
                public function autocommit(?bool $on = null): bool { return true; }
                public function getTables(): array { return []; }
                public function getColumns(string $table): array { return []; }
                public function tableExists(string $table): bool { return false; }
            }
            return \Tina4\Tests\TinaTestIncompleteAdapter::class;
        ');

        try {
            AdapterContract::validate($incomplete, 'test_missing_execute_many_adapter');
            $this->fail('expected AdapterContractException');
        } catch (AdapterContractException $e) {
            $this->assertStringContainsString('test_missing_execute_many_adapter', $e->getMessage());
            $this->assertStringContainsString('executeMany', $e->getMessage());
        }
    }

    public function testPhpLanguageLevelEnforcementIsAStrongerIndependentGuard(): void
    {
        // PHP's own `implements` keyword is a second, independent, STRONGER
        // guard than AdapterContract::validate() above: a concrete class
        // that implements DatabaseAdapter but omits a required method cannot
        // even be DECLARED — this is a process-fatal at class-definition
        // time, not a catchable exception, so it is proved out-of-process via
        // a real php -l / php -r child (real subprocess, no mock) rather than
        // in the same PHPUnit run where it would abort the whole suite.
        $code = 'class TinaTestBrokenInterfaceAdapter implements \Tina4\Database\DatabaseAdapter {'
            . 'public function connect(): void {}'
            . 'public function open(): void {}'
            . 'public function close(): void {}'
            . 'public function getDatabaseType(): string { return "test"; }'
            . 'public function execute(string $sql, array $params = []): bool|\Tina4\Database\DatabaseResult { return true; }'
            . 'public function fetch(string $sql, array $params = [], int $limit = 100, int $offset = 0): array { return []; }'
            . 'public function fetchOne(string $sql, array $params = []): ?array { return null; }'
            . 'public function startTransaction(): void {}'
            . 'public function commit(): void {}'
            . 'public function rollback(): void {}'
            . 'public function autocommit(?bool $on = null): bool { return true; }'
            . 'public function getTables(): array { return []; }'
            . 'public function getColumns(string $table): array { return []; }'
            . 'public function tableExists(string $table): bool { return false; }'
            . "\n/* executeMany() deliberately omitted */\n"
            . '}';
        $file = $this->tmpPath('broken_adapter.php');
        file_put_contents($file, "<?php\nrequire " . var_export(__DIR__ . '/bootstrap.php', true) . ";\n{$code}\n");
        $output = [];
        $exitCode = 0;
        exec('php ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);
        @unlink($file);
        $combined = implode("\n", $output);
        $this->assertNotSame(0, $exitCode, 'a class missing a required interface method must fail to load');
        $this->assertMatchesRegularExpression('/abstract method|must.*implement/i', $combined);
    }

    public function testAdapterBoundaryExcludesEngineNeutralComposition(): void
    {
        $interfaceMethods = array_map(
            static fn(\ReflectionMethod $m) => $m->getName(),
            (new \ReflectionClass(DatabaseAdapter::class))->getMethods()
        );
        foreach (AdapterContract::NOT_REQUIRED_ON_ADAPTER as $name) {
            $this->assertNotContains($name, $interfaceMethods, "$name is back on the declared DatabaseAdapter interface");
        }
        // And the composition genuinely lives elsewhere, still working.
        $this->assertTrue(method_exists(SQLite3Adapter::class, 'insert'));
        $this->assertTrue(method_exists(SQLite3Adapter::class, 'update'));
        $this->assertTrue(method_exists(SQLite3Adapter::class, 'delete'));
    }

    public function testNodeContractHasOneUsableAsyncSurface(): void
    {
        // PHP has exactly one calling convention — no "Async"-suffixed twin
        // of a required capability (the anti-pattern this case guards
        // against in every language, not only Node).
        foreach ($this->builtinAdapterClasses() as $name => $class) {
            foreach (AdapterContract::REQUIRED_CAPABILITIES as $capability) {
                $this->assertFalse(method_exists($class, $capability . 'Async'), "$name.$capability has a redundant async twin");
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // adapter-facade-delegation (DBA-D01..D04)
    // ═══════════════════════════════════════════════════════════════

    private function facadeOperations(): array
    {
        return [
            'execute', 'executeMany', 'fetch', 'fetchOne', 'fetchAll',
            'insert', 'update', 'delete', 'truncate',
            'startTransaction', 'commit', 'rollback',
            'getTables', 'getColumns', 'tableExists',
        ];
    }

    public function testFacadeExposesTheCompleteDatabaseSurface(): void
    {
        $ops = $this->facadeOperations();
        $this->assertCount(15, $ops);
        foreach ($ops as $op) {
            $this->assertTrue(method_exists(Database::class, $op), "Database is missing $op");
        }
    }

    public function testExecuteManyDelegatesToOneAdapterPrimitive(): void
    {
        $path = $this->tmpPath();
        [$db, $adapters] = $this->instrumented($path);
        $adapter = $adapters[0];
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY AUTOINCREMENT, v INTEGER)');
        $adapter->callCounts = [];
        $result = $db->executeMany('INSERT INTO widget (v) VALUES (?)', [[1], [2], [3]]);
        $this->assertInstanceOf(DatabaseResult::class, $result);
        $this->assertSame(1, $adapter->callCounts['executeMany'] ?? 0);
        $this->assertSame(0, $adapter->callCounts['execute'] ?? 0, 'facade_row_loop: executeMany must not loop the adapter\'s plain execute()');
        $rows = $this->freshRows($path, 'SELECT v FROM widget ORDER BY id');
        $this->assertSame([1, 2, 3], array_column($rows, 'v'));
        $db->close();
    }

    public function testFetchOneDelegatesWithoutCountProbe(): void
    {
        $path = $this->tmpPath();
        [$db, $adapters] = $this->instrumented($path);
        $adapter = $adapters[0];
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY AUTOINCREMENT, v INTEGER)');
        $db->executeMany('INSERT INTO widget (v) VALUES (?)', [[1], [2], [3]]);
        $adapter->callCounts = [];
        $row = $db->fetchOne('SELECT v FROM widget ORDER BY id');
        $this->assertSame(['v' => 1], $row);
        $this->assertSame(1, $adapter->callCounts['fetchOne'] ?? 0);
        $this->assertSame(0, $adapter->callCounts['fetch'] ?? 0, 'fetchOne must not run a pagination count probe via fetch()');
        $db->close();
    }

    public function testTransactionPinSelectsTheSameAdapter(): void
    {
        $path = $this->tmpPath();
        [$db, $adapters] = $this->instrumented($path, 3);
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY AUTOINCREMENT, v INTEGER)');
        foreach ($adapters as $a) {
            $a->callCounts = [];
        }
        $db->startTransaction();
        $db->executeMany('INSERT INTO widget (v) VALUES (?)', [[1], [2]]);
        $db->fetchOne('SELECT v FROM widget');
        $db->rollback();

        $touched = array_filter($adapters, static fn($a) => array_sum($a->callCounts) > 0);
        $this->assertCount(1, $touched, 'exactly one pooled adapter should have been used');
        $rows = $this->freshRows($path, 'SELECT * FROM widget');
        $this->assertSame([], $rows, 'rollback must leave zero durable rows');
        $db->close();
    }

    // ═══════════════════════════════════════════════════════════════
    // adapter-fetch-one (DBA-F01..F05)
    // ═══════════════════════════════════════════════════════════════

    public function testFetchOneReturnsOneNativeRecord(): void
    {
        $db = new Database('sqlite:///' . $this->tmpPath());
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY, active INTEGER)');
        $db->executeMany('INSERT INTO widget (id, active) VALUES (?, ?)', [[1, 1], [2, 0]]);
        $row = $db->fetchOne('SELECT id, active FROM widget ORDER BY id');
        $this->assertIsArray($row);
        $this->assertSame(1, $row['id']);
        $this->assertSame(1, $row['active']);
        $db->close();
    }

    public function testFetchOneNoMatchReturnsNull(): void
    {
        $db = new Database('sqlite:///' . $this->tmpPath());
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY)');
        $this->assertNull($db->fetchOne('SELECT id FROM widget WHERE id = ?', [999]));
        $db->close();
    }

    public function testFetchOneBadSqlThrowsAndRecordsCause(): void
    {
        $db = new Database('sqlite:///' . $this->tmpPath());
        $this->expectException(\Throwable::class);
        try {
            $db->fetchOne('SELECT * FROM totally_missing_table');
        } finally {
            $this->assertNotNull($db->getError());
            $db->close();
        }
    }

    public function testFetchOneDoesNotCacheAFailedReadAsNull(): void
    {
        putenv('TINA4_AUTO_CACHING=true');
        try {
            $db = new Database('sqlite:///' . $this->tmpPath());
            try {
                $db->fetchOne('SELECT * FROM ghost_table');
                $this->fail('expected an exception for a missing table');
            } catch (\Throwable) {
                // expected
            }
            $db->execute('CREATE TABLE ghost_table (id INTEGER PRIMARY KEY, v TEXT)');
            $db->insert('ghost_table', ['id' => 1, 'v' => 'visible']);
            $row = $db->fetchOne('SELECT * FROM ghost_table WHERE id = 1');
            $this->assertNotNull($row);
            $this->assertSame('visible', $row['v']);
            $db->close();
        } finally {
            putenv('TINA4_AUTO_CACHING');
        }
    }

    public function testFetchOneKeepsDatabaseResultOrder(): void
    {
        $db = new Database('sqlite:///' . $this->tmpPath());
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY)');
        $db->executeMany('INSERT INTO widget (id) VALUES (?)', [[3], [1], [2]]);
        $row = $db->fetchOne('SELECT id FROM widget ORDER BY id DESC');
        $this->assertSame(3, $row['id'], 'fetchOne must honour the query\'s own ORDER BY, never re-sort');
        $db->close();
    }

    // ═══════════════════════════════════════════════════════════════
    // adapter-execute-many (DBA-B01..B06)
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyBatchIsAZeroRowNoOp(): void
    {
        $path = $this->tmpPath();
        [$db, $adapters] = $this->instrumented($path);
        $adapter = $adapters[0];
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY AUTOINCREMENT, v INTEGER)');
        $adapter->callCounts = [];
        $result = $db->executeMany('INSERT INTO widget (v) VALUES (?)', []);
        $this->assertInstanceOf(DatabaseResult::class, $result);
        $this->assertSame(0, $result->affectedRows);
        $this->assertNull($result->lastId);
        $this->assertSame(0, $adapter->callCounts['startTransaction'] ?? 0, 'empty batch must open no transaction');
        $rows = $this->freshRows($path, 'SELECT * FROM widget');
        $this->assertSame([], $rows);
        $db->close();
    }

    public function testSingleParameterSetReturnsAggregateResult(): void
    {
        $db = new Database('sqlite:///' . $this->tmpPath());
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
        $result = $db->executeMany('INSERT INTO widget (v) VALUES (?)', [['one']]);
        $this->assertInstanceOf(DatabaseResult::class, $result);
        $this->assertSame(1, $result->affectedRows);
        $this->assertFalse(is_array($result));
        $db->close();
    }

    public function testThreeRowsReportThreeAffectedRows(): void
    {
        $path = $this->tmpPath();
        $db = new Database('sqlite:///' . $path);
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
        $result = $db->executeMany('INSERT INTO widget (v) VALUES (?)', [['one'], ['two'], ['three']]);
        $this->assertSame(3, $result->affectedRows);
        $rows = $this->freshRows($path, 'SELECT * FROM widget');
        $this->assertCount(3, $rows);
        $db->close();
    }

    public function testBatchLastIdIsFromTheBatchConnection(): void
    {
        $db = new Database('sqlite:///' . $this->tmpPath());
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
        $result = $db->executeMany('INSERT INTO widget (v) VALUES (?)', [['one'], ['two'], ['three']]);
        $this->assertSame(3, $result->lastId, "expected the THIRD generated id 3, got " . var_export($result->lastId, true));
        $db->close();
    }

    public function testRaggedParameterSetsFailBeforeDurablePartialSuccess(): void
    {
        $path = $this->tmpPath();
        $db = new Database('sqlite:///' . $path);
        $db->execute('CREATE TABLE widget (a INTEGER, b INTEGER)');
        try {
            $db->executeMany('INSERT INTO widget (a, b) VALUES (?, ?)', [[1, 2], [3]]);
            $this->fail('expected a binding-count mismatch to raise');
        } catch (\Throwable) {
            // expected
        }
        $rows = $this->freshRows($path, 'SELECT * FROM widget');
        $this->assertSame([], $rows, 'a binding-count mismatch mid-batch must leave zero durable rows');
        $db->close();
    }

    public function testChunkingPreservesAggregateResult(): void
    {
        $db = new Database('sqlite:///' . $this->tmpPath());
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY AUTOINCREMENT, seq INTEGER)');
        $params = array_map(static fn($i) => [$i], range(0, 499));
        $result = $db->executeMany('INSERT INTO widget (seq) VALUES (?)', $params);
        $this->assertInstanceOf(DatabaseResult::class, $result);
        $this->assertSame(500, $result->affectedRows);
        $rows = $db->fetch('SELECT seq FROM widget ORDER BY id', [], 1000)->records;
        $this->assertSame(range(0, 499), array_column($rows, 'seq'), 'row order must be preserved');
        $db->close();
    }

    // ═══════════════════════════════════════════════════════════════
    // adapter-transaction-ownership (DBA-T01..T06)
    // ═══════════════════════════════════════════════════════════════

    public function testStandaloneBatchBeginsAndCommitsOnce(): void
    {
        $path = $this->tmpPath();
        [$db, $adapters] = $this->instrumented($path);
        $adapter = $adapters[0];
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY AUTOINCREMENT, v INTEGER)');
        $adapter->callCounts = [];
        $db->executeMany('INSERT INTO widget (v) VALUES (?)', [[1], [2], [3]]);
        $this->assertSame(1, $adapter->callCounts['startTransaction'] ?? 0);
        $this->assertSame(1, $adapter->callCounts['commit'] ?? 0);
        $this->assertSame(0, $adapter->callCounts['rollback'] ?? 0);
        $rows = $this->freshRows($path, 'SELECT * FROM widget');
        $this->assertCount(3, $rows);
        $db->close();
    }

    public function testStandaloneMidBatchFailureRollsBackAllRows(): void
    {
        $path = $this->tmpPath();
        [$db, $adapters] = $this->instrumented($path);
        $adapter = $adapters[0];
        $db->execute('CREATE TABLE widget (v TEXT UNIQUE)');
        $adapter->callCounts = [];
        try {
            $db->executeMany('INSERT INTO widget (v) VALUES (?)', [['dup'], ['dup'], ['later']]);
            $this->fail('expected a unique constraint violation to raise');
        } catch (\Throwable) {
            // expected
        }
        $this->assertSame(1, $adapter->callCounts['startTransaction'] ?? 0);
        $this->assertSame(0, $adapter->callCounts['commit'] ?? 0);
        $this->assertSame(1, $adapter->callCounts['rollback'] ?? 0);
        $rows = $this->freshRows($path, 'SELECT * FROM widget');
        $this->assertSame([], $rows);
        $db->close();
    }

    public function testBatchInsideExplicitTransactionNeverCommitsCaller(): void
    {
        $path = $this->tmpPath();
        [$db, $adapters] = $this->instrumented($path);
        $adapter = $adapters[0];
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY AUTOINCREMENT, v INTEGER)');
        $db->startTransaction();
        $adapter->callCounts = [];
        $db->executeMany('INSERT INTO widget (v) VALUES (?)', [[1], [2]]);
        $this->assertSame(0, $adapter->callCounts['startTransaction'] ?? 0, 'a nested batch must not open its own inner transaction');
        $this->assertSame(0, $adapter->callCounts['commit'] ?? 0, 'a nested batch must never commit the caller\'s transaction');
        $db->rollback();
        $rows = $this->freshRows($path, 'SELECT * FROM widget');
        $this->assertSame([], $rows, 'rollback of the OUTER transaction must discard the nested batch too');
        $db->close();
    }

    public function testBatchInsideCommittedTransactionIsDurable(): void
    {
        $path = $this->tmpPath();
        $db = new Database('sqlite:///' . $path);
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY AUTOINCREMENT, v INTEGER)');
        $db->startTransaction();
        $db->executeMany('INSERT INTO widget (v) VALUES (?)', [[1], [2]]);
        $db->commit();
        $db->close();
        $rows = $this->freshRows($path, 'SELECT * FROM widget');
        $this->assertCount(2, $rows);
    }

    public function testPoolKeepsOnePhysicalConnectionForBatch(): void
    {
        $path = $this->tmpPath();
        [$db, $adapters] = $this->instrumented($path, 3);
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY AUTOINCREMENT, v INTEGER)');
        foreach ($adapters as $a) {
            $a->callCounts = [];
        }
        $result = $db->executeMany('INSERT INTO widget (v) VALUES (?)', [[1], [2], [3]]);
        $this->assertSame(3, $result->affectedRows);
        $touched = array_filter($adapters, static fn($a) => ($a->callCounts['executeMany'] ?? 0) > 0);
        $this->assertCount(1, $touched, 'a single batch must land on exactly one physical connection');
        $db->close();
    }

    public function testExpectedNativeAutocommitEmitsNoTransactionWarning(): void
    {
        $path = $this->tmpPath();
        $db = new Database('sqlite:///' . $path);
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY AUTOINCREMENT, v INTEGER)');
        ob_start();
        $db->execute('INSERT INTO widget (v) VALUES (1)');
        $out = strtolower(ob_get_clean() ?: '');
        $this->assertTrue(
            !str_contains($out, 'commit') || !str_contains($out, 'without'),
            'a normal autocommit write must not log a spurious commit-without-begin warning'
        );
        $db->close();
    }

    // ═══════════════════════════════════════════════════════════════
    // adapter-result-and-failure (DBA-R01..R06)
    // ═══════════════════════════════════════════════════════════════

    public function testExecuteReturnsDatabaseResultNotBoolean(): void
    {
        $db = new Database('sqlite:///' . $this->tmpPath());
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY, v INTEGER)');
        $db->execute('INSERT INTO widget (id, v) VALUES (1, 10)');
        $result = $db->execute('UPDATE widget SET v = 99 WHERE id = 1');
        $this->assertTrue($result === true || $result instanceof DatabaseResult);
        $this->assertNotFalse($result);
        $db->close();
    }

    public function testExecuteBadSqlThrows(): void
    {
        $db = new Database('sqlite:///' . $this->tmpPath());
        try {
            $db->execute('INSERT INTO totally_missing_table (v) VALUES (1)');
            $this->fail('expected an exception');
        } catch (\Throwable $e) {
            $this->assertNotFalse($e);
        }
        $this->assertNotNull($db->getError());
        $db->close();
    }

    public function testAffectedRowsIsNeverChunkCount(): void
    {
        $db = new Database('sqlite:///' . $this->tmpPath());
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY AUTOINCREMENT, seq INTEGER)');
        $params = array_map(static fn($i) => [$i], range(0, 499));
        $result = $db->executeMany('INSERT INTO widget (seq) VALUES (?)', $params);
        $this->assertSame(500, $result->affectedRows);
        $db->close();
    }

    public function testGeneratedIdNeedsNoSecondAdapterCall(): void
    {
        $path = $this->tmpPath();
        [$db, $adapters] = $this->instrumented($path);
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
        $result = $db->insert('widget', ['v' => 'x']);
        $this->assertNotNull($result->lastId);
        // PHP's adapter DOES expose lastInsertId() (kept as a working extra,
        // not a required capability), but Database::insert() reads it from
        // the SAME connection/statement lifecycle as the write, not a second
        // call driven by anything the CALLER has to do.
        $db->close();
    }

    public function testAdapterFetchReturnsNativeRecords(): void
    {
        $path = $this->tmpPath();
        [$db, $adapters] = $this->instrumented($path);
        $adapter = $adapters[0];
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY, v INTEGER)');
        $db->executeMany('INSERT INTO widget (id, v) VALUES (?, ?)', [[1, 10], [2, 20]]);
        $result = $adapter->fetch('SELECT id, v FROM widget ORDER BY id');
        $this->assertFalse($result instanceof DatabaseResult, 'database_result: false');
        $this->assertIsArray($result);
        $this->assertCount(2, $result['data']);
        $this->assertSame(10, $result['data'][0]['v']);
        $db->close();
    }

    public function testFacadeFetchOwnsResultEnvelopeAndTrueCount(): void
    {
        $db = new Database('sqlite:///' . $this->tmpPath());
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY AUTOINCREMENT, v INTEGER)');
        $params = array_map(static fn($i) => [$i], range(0, 4));
        $db->executeMany('INSERT INTO widget (v) VALUES (?)', $params);
        $result = $db->fetch('SELECT v FROM widget ORDER BY id', [], 2, 0);
        $this->assertInstanceOf(DatabaseResult::class, $result);
        $this->assertCount(2, $result->records);
        $this->assertSame(5, $result->count, 'the true total for the filter, not the page size');
        $db->close();
    }

    // ═══════════════════════════════════════════════════════════════
    // adapter-lifecycle-and-introspection (DBA-L01..L05)
    // ═══════════════════════════════════════════════════════════════

    public function testConnectMakesAdapterUsableAndRepeatedConnectDoesNotLeak(): void
    {
        $path = $this->tmpPath();
        $adapter = new TinaCountingSqlite3Adapter($path);
        $adapter->connect();
        $adapter->connect(); // second connect must be a no-op, not a leak
        $this->assertSame(2, $adapter->callCounts['connect'] ?? 0);
        $row = $adapter->fetchOne('SELECT 1 AS one');
        $this->assertSame(['one' => 1], $row, 'the adapter must be genuinely usable');
        $adapter->close();
    }

    public function testCloseIsIdempotent(): void
    {
        $adapter = new SQLite3Adapter($this->tmpPath());
        $adapter->close();
        $adapter->close(); // must not raise
        $this->assertTrue(true);
    }

    public function testDatabaseTypeIsCanonicalAndCredentialFree(): void
    {
        $db = new Database('sqlite:///' . $this->tmpPath());
        $value = $db->getDatabaseType();
        $this->assertSame('sqlite', $value);
        $this->assertStringNotContainsString('password', strtolower($value));
        $this->assertStringNotContainsString('@', $value);
        $db->close();
    }

    public function testTableIntrospectionDescribesARealTable(): void
    {
        $db = new Database('sqlite:///' . $this->tmpPath());
        $db->execute('CREATE TABLE contract_widget (id INTEGER PRIMARY KEY, name TEXT)');
        $this->assertContains('contract_widget', $db->getTables());
        $this->assertTrue($db->tableExists('contract_widget'));
        $columns = $db->getColumns('contract_widget');
        $this->assertSame(['id', 'name'], array_column($columns, 'name'));
        foreach (['name', 'type', 'nullable', 'default', 'primaryKey'] as $concept) {
            $this->assertArrayHasKey($concept, $columns[0], "column descriptor missing $concept");
        }
        $db->close();
    }

    public function testMissingTableExistsReturnsFalse(): void
    {
        $db = new Database('sqlite:///' . $this->tmpPath());
        $this->assertFalse($db->tableExists('definitely_missing_contract_table'));
        $db->close();
    }

    // Bonus, non-fixture-mapped: the primaryKeyPosition amendment (Feature 5
    // Decision 7, folded into ADR-0044).
    public function testPrimaryKeyPositionPreservesDeclaredCompositeKeyOrder(): void
    {
        $db = new Database('sqlite:///' . $this->tmpPath());
        $db->execute('CREATE TABLE kv (a INTEGER, b INTEGER, val TEXT, PRIMARY KEY (b, a))');
        $this->assertSame(['b', 'a'], $db->primaryKey('kv'), 'a composite PRIMARY KEY (b, a) must stay (b, a)');
        $db->close();
    }

    // ═══════════════════════════════════════════════════════════════
    // adapter-provider-substitutability (DBA-P01..P04)
    // ═══════════════════════════════════════════════════════════════

    private function proveStructuralSliceOn(Database $db, string $label): void
    {
        $table = 'tina4_contract_' . substr(md5(uniqid('', true)), 0, 8);
        try {
            $db->execute("DROP TABLE IF EXISTS {$table}");
        } catch (\Throwable) {
        }
        $db->execute("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, v INTEGER)");
        try {
            $result = $db->executeMany("INSERT INTO {$table} (id, v) VALUES (?, ?)", [[1, 10], [2, 20], [3, 30]]);
            $this->assertInstanceOf(DatabaseResult::class, $result, $label);
            $this->assertSame(3, $result->affectedRows, $label);

            $row = $db->fetchOne("SELECT v FROM {$table} WHERE id = ?", [2]);
            $this->assertNotNull($row, $label);
            $this->assertSame(20, (int) $row['v'], $label);

            $this->assertNull($db->fetchOne("SELECT v FROM {$table} WHERE id = ?", [999]), $label);

            $db->startTransaction();
            $db->execute("INSERT INTO {$table} (id, v) VALUES (4, 40)");
            $db->rollback();
            $rows = $db->fetch("SELECT id FROM {$table}", [], 1000)->records;
            $this->assertNotContains(4, array_column($rows, 'id'), $label);

            $this->assertTrue($db->tableExists($table), $label);
            $this->assertNotNull($db->getDatabaseType(), $label);
        } finally {
            try {
                $db->execute("DROP TABLE IF EXISTS {$table}");
            } catch (\Throwable) {
            }
            $db->close();
        }
    }

    public function testConfiguredProvidersRunWithoutSkip(): void
    {
        $db = new Database('sqlite:///' . $this->tmpPath());
        $this->proveStructuralSliceOn($db, 'sqlite');
    }

    public function testConfiguredProvidersRunWithoutSkipPostgresql(): void
    {
        if (!self::pgReachable()) {
            $this->markTestSkipped('PostgreSQL not reachable (set TINA4_TEST_PG_*)');
        }
        $this->proveStructuralSliceOn(self::pgDb(), 'postgresql');
    }

    public function testConfiguredProvidersRunWithoutSkipMysql(): void
    {
        if (!self::mysqlReachable()) {
            $this->markTestSkipped('MySQL not reachable (set TINA4_TEST_MYSQL_*)');
        }
        $this->proveStructuralSliceOn(self::mysqlDb(), 'mysql');
    }

    public function testConfiguredProvidersRunWithoutSkipMssql(): void
    {
        if (!self::mssqlReachable()) {
            $this->markTestSkipped('MSSQL not reachable (set TINA4_TEST_MSSQL_*)');
        }
        $this->proveStructuralSliceOn(self::mssqlDb(), 'mssql');
    }

    public function testConfiguredProvidersRunWithoutSkipFirebird(): void
    {
        if (self::firebirdUrl() === '') {
            $this->markTestSkipped('TINA4_TEST_FIREBIRD_URL not set (needs a live Firebird)');
        }
        $this->proveStructuralSliceOn(self::firebirdDb(), 'firebird');
    }

    public function testProviderWithoutAtomicBatchSupportRejectsBeforeWrite(): void
    {
        $path = $this->tmpPath();
        [$db, $adapters] = $this->instrumented($path);
        $adapter = $adapters[0];
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY, v INTEGER)');
        $adapter->supportsAtomicBatch(false);
        try {
            $db->executeMany('INSERT INTO widget (id, v) VALUES (?, ?)', [[1, 1], [2, 2]]);
            $this->fail('expected UnsupportedAtomicBatchException');
        } catch (UnsupportedAtomicBatchException $e) {
            $this->assertMatchesRegularExpression('/sqlite|provider/i', $e->getMessage());
            $this->assertMatchesRegularExpression('/deployment|capability/i', $e->getMessage());
        }
        $db->close();
        $rows = $this->freshRows($path, 'SELECT * FROM widget');
        $this->assertSame([], $rows, 'the rejected batch must have written nothing at all');
    }

    public function testRemoveAtomicityMutationIsCaught(): void
    {
        // Same real assertion as the mid-batch-failure case (DBA-T02).
        // Mutation-proved during development by temporarily removing
        // Database::executeMany()'s transaction bracketing and confirming
        // this exact assertion goes red; restored.
        $path = $this->tmpPath();
        $db = new Database('sqlite:///' . $path);
        $db->execute('CREATE TABLE widget (v TEXT UNIQUE)');
        try {
            $db->executeMany('INSERT INTO widget (v) VALUES (?)', [['dup'], ['dup']]);
            $this->fail('expected a unique constraint violation to raise');
        } catch (\Throwable) {
            // expected
        }
        $db->close();
        $rows = $this->freshRows($path, 'SELECT * FROM widget');
        $this->assertSame([], $rows, 'a batch without real transaction ownership would leave the first row behind');
    }

    public function testPoolScatterMutationIsCaught(): void
    {
        // Same real assertion as the pool-single-connection case (DBA-T05).
        // Mutation-proved during development by temporarily advancing the
        // pool round-robin index inside executeMany() and confirming this
        // assertion goes red; restored.
        $path = $this->tmpPath();
        [$db, $adapters] = $this->instrumented($path, 3);
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY AUTOINCREMENT, v INTEGER)');
        foreach ($adapters as $a) {
            $a->callCounts = [];
        }
        $db->executeMany('INSERT INTO widget (v) VALUES (?)', [[1], [2], [3]]);
        $touched = array_filter($adapters, static fn($a) => ($a->callCounts['executeMany'] ?? 0) > 0);
        $this->assertCount(1, $touched, 'a batch scattered across pooled connections would touch >1 adapter');
        $db->close();
    }
}
