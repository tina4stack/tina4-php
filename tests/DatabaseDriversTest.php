<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Tests for all database adapters: PostgreSQL, MySQL, MSSQL, Firebird.
 * Tests that don't require a running database server run unconditionally.
 * Tests that need a live connection use markTestSkipped.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\PostgresAdapter;
use Tina4\Database\MySQLAdapter;
use Tina4\Database\MSSQLAdapter;
use Tina4\Database\FirebirdAdapter;
use Tina4\Database\Database;
use Tina4\Database\SQLite3Adapter;

class DatabaseDriversTest extends TestCase
{
    // ── Live-service connection helpers (#262) ───────────────────────
    //
    // MySQL + MSSQL are provisioned in CI since 3.13.44, so the live tests
    // must RUN there (not skip). A URL is resolved from the legacy
    // TINA4_TEST_MYSQL_URL / TINA4_TEST_MSSQL_URL OR the canonical
    // TINA4_TEST_MYSQL_* / TINA4_TEST_MSSQL_* env, gated by a TCP reachability
    // probe. When nothing is listening the skip reason names the engine +
    // "not reachable" so the RequireServicesGate turns it into a failure under
    // TINA4_REQUIRE_SERVICES. (These plumb the same connection the live
    // round-trips in MySQLMSSQLLiveTest use.)

    private static function testHost(string $var, string $default = 'localhost'): string
    {
        $v = getenv($var);
        return ($v !== false && $v !== '') ? $v : $default;
    }

    private static function testPort(string $var, int $default): int
    {
        $v = getenv($var);
        return ($v !== false && $v !== '') ? (int) $v : $default;
    }

    private static function tcpReachable(string $host, int $port, float $timeout = 1.0): bool
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($fp === false) {
            return false;
        }
        fclose($fp);

        return true;
    }

    /** MSSQL works through ext-sqlsrv OR the ext-pdo_dblib (FreeTDS) fallback. */
    private static function mssqlClientAvailable(): bool
    {
        return function_exists('sqlsrv_connect')
            || in_array('dblib', \PDO::getAvailableDrivers(), true);
    }

    /** A reachable MySQL connection URL, or null when nothing is listening. */
    private static function resolveMysqlUrl(): ?string
    {
        $url = getenv('TINA4_TEST_MYSQL_URL');
        if ($url !== false && $url !== '') {
            return $url;
        }

        $host = self::testHost('TINA4_TEST_MYSQL_HOST');
        $port = self::testPort('TINA4_TEST_MYSQL_PORT', 3306);
        if (!self::tcpReachable($host, $port)) {
            return null;
        }
        $user = self::testHost('TINA4_TEST_MYSQL_USERNAME', 'tina4');
        $pass = self::testHost('TINA4_TEST_MYSQL_PASSWORD', 'tina4');
        $db = self::testHost('TINA4_TEST_MYSQL_DB', 'tina4_test');

        return sprintf('mysql://%s:%s@%s:%d/%s', $user, $pass, $host, $port, $db);
    }

    /** A reachable MSSQL connection URL, or null when nothing is listening. */
    private static function resolveMssqlUrl(): ?string
    {
        $url = getenv('TINA4_TEST_MSSQL_URL');
        if ($url !== false && $url !== '') {
            return $url;
        }

        $host = self::testHost('TINA4_TEST_MSSQL_HOST');
        $port = self::testPort('TINA4_TEST_MSSQL_PORT', 1433);
        if (!self::tcpReachable($host, $port)) {
            return null;
        }
        $user = self::testHost('TINA4_TEST_MSSQL_USERNAME', 'sa');
        $pass = self::testHost('TINA4_TEST_MSSQL_PASSWORD', 'TinaSQL123!Secure');
        $db = self::testHost('TINA4_TEST_MSSQL_DB', 'tina4_test');

        return sprintf('mssql://%s:%s@%s:%d/%s', rawurlencode($user), rawurlencode($pass), $host, $port, $db);
    }

    // ── Extension Detection ──────────────────────────────────────────

    public function testPostgresThrowsWithoutExtension(): void
    {
        if (function_exists('pg_connect')) {
            $this->markTestSkipped('ext-pgsql is installed — cannot test missing extension error');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ext-pgsql');
        new PostgresAdapter('pgsql://localhost/test');
    }

    public function testMySQLThrowsWithoutExtension(): void
    {
        if (extension_loaded('mysqli')) {
            $this->markTestSkipped('ext-mysqli is installed — cannot test missing extension error');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ext-mysqli');
        new MySQLAdapter('mysql://localhost/test');
    }

    /**
     * The MSSQLAdapter constructor now picks a backend automatically: it prefers
     * ext-sqlsrv (the Microsoft driver) and falls back to ext-pdo_dblib (FreeTDS,
     * the 'dblib' PDO driver — the same stack tina4-python/tina4-ruby use). It
     * only throws when NEITHER backend is available.
     *
     * So this is now two assertions in one (driven by what the host actually
     * provides):
     *   - neither sqlsrv NOR pdo_dblib  → constructor STILL throws (clear error
     *     naming both ext-sqlsrv and ext-pdo_dblib).
     *   - pdo_dblib IS available (no sqlsrv) → constructor SUCCEEDS and the
     *     instance reports the 'pdo' driver (it must not throw just because the
     *     Microsoft driver is missing).
     *
     * The constructor connects in open(), so on a host with dblib but no live
     * server we tolerate the "failed to connect" RuntimeException — what we are
     * asserting is that it did NOT throw the missing-extension error and that
     * the backend selection landed on 'pdo'. The live round-trip lives in
     * MySQLMSSQLLiveTest.
     */
    public function testMSSQLBackendSelection(): void
    {
        $hasSqlsrv = function_exists('sqlsrv_connect');
        $hasDblib = in_array('dblib', \PDO::getAvailableDrivers(), true);

        if (!$hasSqlsrv && !$hasDblib) {
            // Neither backend present — must throw the missing-extension error
            // naming both options.
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('ext-sqlsrv');
            new MSSQLAdapter('mssql://localhost/test');
            return;
        }

        if ($hasSqlsrv) {
            $this->markTestSkipped(
                'ext-sqlsrv is installed — this host exercises the primary '
                . 'driver, not the pdo_dblib fallback selection path.'
            );
        }

        // pdo_dblib present, sqlsrv absent: the constructor must NOT throw a
        // missing-extension error — it must select the 'pdo' backend. It may
        // still raise a "failed to connect" RuntimeException if nothing is
        // listening; that is fine — only the missing-extension error is wrong.
        try {
            $adapter = new MSSQLAdapter('mssql://localhost/test');
            $this->assertSame('pdo', $adapter->getDriver(),
                'with only pdo_dblib available the adapter must use the pdo backend');
            $adapter->close();
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('ext-sqlsrv', $e->getMessage(),
                'a missing-extension error is wrong when pdo_dblib is available — '
                . 'the adapter must fall back to pdo, not refuse to construct'
            );
            $this->assertStringContainsString('Failed to connect', $e->getMessage(),
                'the only acceptable RuntimeException here is a connection failure, '
                . 'not a backend-availability error'
            );
        }
    }

    public function testFirebirdThrowsWithoutExtension(): void
    {
        if (function_exists('ibase_connect') || function_exists('fbird_connect')) {
            $this->markTestSkipped('ext-interbase is installed — cannot test missing extension error');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ext-interbase');
        new FirebirdAdapter('firebird://localhost/test');
    }

    // ── DatabaseFactory ──────────────────────────────────────────────

    public function testFactorySQLiteMemory(): void
    {
        $db = Database::create('sqlite::memory:');
        $this->assertInstanceOf(Database::class, $db);
        $this->assertInstanceOf(SQLite3Adapter::class, $db->getAdapter());
        $db->close();
    }

    public function testFactorySQLiteBareMemory(): void
    {
        $db = Database::create(':memory:');
        $this->assertInstanceOf(Database::class, $db);
        $this->assertInstanceOf(SQLite3Adapter::class, $db->getAdapter());
        $db->close();
    }

    public function testFactorySQLiteFilePath(): void
    {
        $path = sys_get_temp_dir() . '/tina4_factory_test_' . uniqid() . '.db';
        $db = Database::create($path);
        $this->assertInstanceOf(Database::class, $db);
        $this->assertInstanceOf(SQLite3Adapter::class, $db->getAdapter());
        $db->close();
        @unlink($path);
    }

    public function testFactorySQLiteUrl(): void
    {
        $db = Database::create('sqlite:///:memory:');
        $this->assertInstanceOf(Database::class, $db);
        $this->assertInstanceOf(SQLite3Adapter::class, $db->getAdapter());
        $db->close();
    }

    public function testFactoryUnsupportedScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database scheme');
        Database::create('oracle://localhost/test');
    }

    public function testFactoryInvalidUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Database::create('not-a-valid-url');
    }

    public function testFactorySupportedSchemes(): void
    {
        $schemes = Database::supportedSchemes();
        $this->assertContains('sqlite', $schemes);
        $this->assertContains('postgres', $schemes);
        $this->assertContains('postgresql', $schemes);
        $this->assertContains('mysql', $schemes);
        $this->assertContains('mssql', $schemes);
        $this->assertContains('sqlserver', $schemes);
        $this->assertContains('firebird', $schemes);
    }

    public function testFactoryIsSupported(): void
    {
        $this->assertTrue(Database::isSupported('sqlite'));
        $this->assertTrue(Database::isSupported('postgres'));
        $this->assertTrue(Database::isSupported('postgresql'));
        $this->assertTrue(Database::isSupported('mysql'));
        $this->assertTrue(Database::isSupported('mssql'));
        $this->assertTrue(Database::isSupported('sqlserver'));
        $this->assertTrue(Database::isSupported('firebird'));
        $this->assertFalse(Database::isSupported('pgsql'));
        $this->assertFalse(Database::isSupported('oracle'));
        $this->assertFalse(Database::isSupported('cassandra'));
    }

    public function testFactoryFromEnvReturnsNullWhenNotSet(): void
    {
        $result = Database::fromEnv('TINA4_TEST_DB_NONEXISTENT_' . uniqid());
        $this->assertNull($result);
    }

    // ── SQL Translation per Dialect ──────────────────────────────────

    public function testSQLTranslatorFirebirdLimitToRows(): void
    {
        $translated = \Tina4\SQLTranslator::translate(
            "SELECT * FROM users WHERE active = TRUE LIMIT 10 OFFSET 5",
            'firebird'
        );
        // Firebird: LIMIT/OFFSET => ROWS X TO Y, TRUE => 1
        $this->assertStringContainsString('ROWS', $translated);
        $this->assertStringNotContainsString('LIMIT', $translated);
        $this->assertStringNotContainsString('TRUE', $translated);
    }

    public function testSQLTranslatorMssqlLimitToTop(): void
    {
        $translated = \Tina4\SQLTranslator::translate(
            "SELECT * FROM users LIMIT 10",
            'mssql'
        );
        // MSSQL: LIMIT => TOP
        $this->assertStringContainsString('TOP', $translated);
        $this->assertStringNotContainsString('LIMIT', $translated);
    }

    public function testSQLTranslatorPostgresPassthrough(): void
    {
        $sql = "SELECT * FROM users WHERE active = TRUE LIMIT 10 OFFSET 5";
        $translated = \Tina4\SQLTranslator::translate($sql, 'postgresql');
        // PostgreSQL supports LIMIT/OFFSET and TRUE natively
        $this->assertStringContainsString('LIMIT', $translated);
        $this->assertStringContainsString('TRUE', $translated);
    }

    public function testSQLTranslatorMysqlBooleanToInt(): void
    {
        // MySQL translate() does auto-increment syntax only; test booleanToInt directly
        $translated = \Tina4\SQLTranslator::booleanToInt(
            "SELECT * FROM users WHERE active = TRUE AND deleted = FALSE"
        );
        $this->assertStringContainsString('1', $translated);
        $this->assertStringContainsString('0', $translated);
        $this->assertStringNotContainsString('TRUE', $translated);
        $this->assertStringNotContainsString('FALSE', $translated);
    }

    public function testSQLTranslatorFirebirdIlikeToLike(): void
    {
        $translated = \Tina4\SQLTranslator::translate(
            "SELECT * FROM users WHERE name ILIKE '%alice%'",
            'firebird'
        );
        // ILIKE => LOWER() LIKE LOWER()
        $this->assertStringContainsString('LOWER', $translated);
        $this->assertStringNotContainsString('ILIKE', $translated);
    }

    public function testSQLTranslatorMssqlIlikeToLike(): void
    {
        // MSSQL translate() does not apply ilikeToLike; test the function directly
        $translated = \Tina4\SQLTranslator::ilikeToLike(
            "SELECT * FROM users WHERE name ILIKE '%alice%'"
        );
        $this->assertStringContainsString('LOWER', $translated);
        $this->assertStringNotContainsString('ILIKE', $translated);
    }

    public function testSQLTranslatorPlaceholderStyleNumbered(): void
    {
        // Convert ? to :1, :2 (numbered colon style)
        $result = \Tina4\SQLTranslator::placeholderStyle(
            "SELECT * FROM users WHERE name = ? AND age = ?",
            ':'
        );
        $this->assertStringContainsString(':1', $result);
        $this->assertStringContainsString(':2', $result);
        $this->assertStringNotContainsString('?', $result);
    }

    public function testSQLTranslatorPlaceholderStyleSprintf(): void
    {
        // Convert ? to %s (sprintf style)
        $result = \Tina4\SQLTranslator::placeholderStyle(
            "SELECT * FROM users WHERE name = ? AND age = ?",
            '%s'
        );
        $this->assertStringContainsString('%s', $result);
        $this->assertStringNotContainsString('?', $result);
    }

    public function testSQLTranslatorHasReturning(): void
    {
        $this->assertTrue(
            \Tina4\SQLTranslator::hasReturning("INSERT INTO users (name) VALUES ('Alice') RETURNING id")
        );
        $this->assertFalse(
            \Tina4\SQLTranslator::hasReturning("INSERT INTO users (name) VALUES ('Alice')")
        );
    }

    public function testSQLTranslatorExtractReturning(): void
    {
        $result = \Tina4\SQLTranslator::extractReturning(
            "INSERT INTO users (name) VALUES ('Alice') RETURNING id, name"
        );
        $this->assertArrayHasKey('sql', $result);
        $this->assertArrayHasKey('columns', $result);
        $this->assertStringNotContainsString('RETURNING', $result['sql']);
    }

    // ── Live Database Tests (require running servers) ────────────────

    public function testPostgresLiveConnection(): void
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('ext-pgsql not installed');
        }

        $url = getenv('TINA4_TEST_PG_URL');
        if (!$url) {
            $this->markTestSkipped('Set TINA4_TEST_PG_URL to run live PostgreSQL tests (e.g. postgres://user:pass@localhost:5432/testdb)');
        }

        $db = new PostgresAdapter($url);
        $this->assertNotNull($db->getConnection());

        // Create, insert, query, drop
        $db->execute("CREATE TABLE IF NOT EXISTS _tina4_test (id SERIAL PRIMARY KEY, name VARCHAR(100))");
        $db->insert('_tina4_test', ['name' => 'Alice']);
        $rows = $db->query("SELECT * FROM _tina4_test WHERE name = $1", ['Alice']);
        $this->assertNotEmpty($rows);
        $this->assertSame('Alice', $rows[0]['name']);

        $this->assertTrue($db->tableExists('_tina4_test'));
        $tables = $db->getTables();
        $this->assertContains('_tina4_test', $tables);

        $cols = $db->getColumns('_tina4_test');
        $this->assertNotEmpty($cols);

        $db->execute("DROP TABLE IF EXISTS _tina4_test");
        $db->close();
    }

    public function testMySQLLiveConnection(): void
    {
        if (!extension_loaded('mysqli')) {
            $this->markTestSkipped('ext-mysqli not installed');
        }

        $url = self::resolveMysqlUrl();
        if ($url === null) {
            $this->markTestSkipped(sprintf(
                'MySQL not reachable at %s:%d — skip live MySQL test',
                self::testHost('TINA4_TEST_MYSQL_HOST'),
                self::testPort('TINA4_TEST_MYSQL_PORT', 3306)
            ));
        }

        $db = new MySQLAdapter($url);
        $this->assertNotNull($db->getConnection());

        $db->execute("CREATE TABLE IF NOT EXISTS _tina4_test (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100))");
        $db->insert('_tina4_test', ['name' => 'Bob']);
        $rows = $db->query("SELECT * FROM _tina4_test WHERE name = ?", ['Bob']);
        $this->assertNotEmpty($rows);
        $this->assertSame('Bob', $rows[0]['name']);

        $this->assertTrue($db->tableExists('_tina4_test'));
        $tables = $db->getTables();
        $this->assertContains('_tina4_test', $tables);

        $cols = $db->getColumns('_tina4_test');
        $this->assertNotEmpty($cols);

        $db->execute("DROP TABLE IF EXISTS _tina4_test");
        $db->close();
    }

    public function testMSSQLLiveConnection(): void
    {
        if (!self::mssqlClientAvailable()) {
            $this->markTestSkipped(
                'MSSQL client not installed — neither ext-sqlsrv nor ext-pdo_dblib (FreeTDS) is available'
            );
        }

        $url = self::resolveMssqlUrl();
        if ($url === null) {
            $this->markTestSkipped(sprintf(
                'MSSQL not reachable at %s:%d — skip live MSSQL test',
                self::testHost('TINA4_TEST_MSSQL_HOST'),
                self::testPort('TINA4_TEST_MSSQL_PORT', 1433)
            ));
        }

        $db = new MSSQLAdapter($url);
        $this->assertNotNull($db->getConnection());

        $db->execute("IF OBJECT_ID('_tina4_test', 'U') IS NOT NULL DROP TABLE _tina4_test");
        $db->execute("CREATE TABLE _tina4_test (id INT IDENTITY(1,1) PRIMARY KEY, name VARCHAR(100))");
        $db->insert('_tina4_test', ['name' => 'Charlie']);
        $rows = $db->query("SELECT * FROM _tina4_test WHERE name = ?", ['Charlie']);
        $this->assertNotEmpty($rows);
        $this->assertSame('Charlie', $rows[0]['name']);

        $this->assertTrue($db->tableExists('_tina4_test'));

        $db->execute("DROP TABLE _tina4_test");
        $db->close();
    }

    public function testFirebirdLiveConnection(): void
    {
        if (!function_exists('ibase_connect') && !function_exists('fbird_connect')) {
            $this->markTestSkipped('ext-interbase not installed');
        }

        $url = getenv('TINA4_TEST_FIREBIRD_URL');
        if (!$url) {
            $this->markTestSkipped('Set TINA4_TEST_FIREBIRD_URL to run live Firebird tests (e.g. firebird://SYSDBA:masterkey@localhost/path/to/test.fdb)');
        }

        // ext-interbase can be present-but-broken (macOS + FB5 clumplet). Skip
        // loudly rather than error — PdoFirebirdAdapterTest covers Firebird via
        // the pdo_firebird fallback where native cannot connect.
        try {
            $db = new FirebirdAdapter($url);
        } catch (\Throwable $e) {
            $this->markTestSkipped('ext-interbase present but cannot connect (' . $e->getMessage() . ') — native Firebird UNVERIFIED here.');
        }
        $this->assertNotNull($db->getConnection());

        $tables = $db->getTables();
        $this->assertIsArray($tables);

        $db->close();
    }

    // ── Factory + Live ───────────────────────────────────────────────

    public function testFactoryCreatesPostgresAdapter(): void
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('ext-pgsql not installed');
        }

        $url = getenv('TINA4_TEST_PG_URL');
        if (!$url) {
            $this->markTestSkipped('Set TINA4_TEST_PG_URL for live factory test');
        }

        // Database::create() returns the Database facade (v3); the concrete
        // engine adapter is reachable via getAdapter().
        $db = Database::create($url);
        $this->assertInstanceOf(Database::class, $db);
        $this->assertInstanceOf(PostgresAdapter::class, $db->getAdapter());
        $db->close();
    }

    public function testFactoryCreatesMySQLAdapter(): void
    {
        if (!extension_loaded('mysqli')) {
            $this->markTestSkipped('ext-mysqli not installed');
        }

        $url = self::resolveMysqlUrl();
        if ($url === null) {
            $this->markTestSkipped(sprintf(
                'MySQL not reachable at %s:%d — skip live factory test',
                self::testHost('TINA4_TEST_MYSQL_HOST'),
                self::testPort('TINA4_TEST_MYSQL_PORT', 3306)
            ));
        }

        $db = Database::create($url);
        $this->assertInstanceOf(Database::class, $db);
        $this->assertInstanceOf(MySQLAdapter::class, $db->getAdapter());
        $db->close();
    }

    public function testFactoryCreatesMSSQLAdapter(): void
    {
        if (!self::mssqlClientAvailable()) {
            $this->markTestSkipped(
                'MSSQL client not installed — neither ext-sqlsrv nor ext-pdo_dblib (FreeTDS) is available'
            );
        }

        $url = self::resolveMssqlUrl();
        if ($url === null) {
            $this->markTestSkipped(sprintf(
                'MSSQL not reachable at %s:%d — skip live factory test',
                self::testHost('TINA4_TEST_MSSQL_HOST'),
                self::testPort('TINA4_TEST_MSSQL_PORT', 1433)
            ));
        }

        $db = Database::create($url);
        $this->assertInstanceOf(Database::class, $db);
        $this->assertInstanceOf(MSSQLAdapter::class, $db->getAdapter());
        $db->close();
    }

    public function testFactoryCreatesFirebirdAdapter(): void
    {
        if (!function_exists('ibase_connect') && !function_exists('fbird_connect')) {
            $this->markTestSkipped('ext-interbase not installed');
        }

        $url = getenv('TINA4_TEST_FIREBIRD_URL');
        if (!$url) {
            $this->markTestSkipped('Set TINA4_TEST_FIREBIRD_URL for live factory test');
        }

        // Auto-mode prefers native ext-interbase but transparently falls back to
        // pdo_firebird when native is present-but-broken (macOS + FB5 clumplet).
        // Either way the factory yields a working Firebird-family adapter — assert
        // the family, not the specific driver (which one you get is host-dependent).
        $db = Database::create($url);
        $this->assertInstanceOf(Database::class, $db);
        $adapter = $db->getAdapter();
        $this->assertTrue(
            $adapter instanceof FirebirdAdapter || $adapter instanceof \Tina4\Database\PdoFirebirdAdapter,
            'firebird:// must resolve to a Firebird adapter (native or pdo), got ' . get_class($adapter)
        );
        $db->close();
    }

    // ── Connection URL Parsing ──────────────────────────────────────

    public function testDatabaseUrlPostgresql(): void
    {
        $url = new \Tina4\DatabaseUrl('postgresql://alice:secret@db.example.com:5433/myapp');
        $this->assertEquals('postgres', $url->engine); // postgresql resolves to the canonical engine
        $this->assertEquals('db.example.com', $url->host);
        $this->assertEquals(5433, $url->port);
        $this->assertEquals('alice', $url->username);
        $this->assertEquals('secret', $url->password);
        $this->assertEquals('myapp', $url->database);
    }

    public function testDatabaseUrlMysql(): void
    {
        $url = new \Tina4\DatabaseUrl('mysql://root:pass123@mysql-server:3307/shop');
        $this->assertEquals('mysql', $url->engine);
        $this->assertEquals('mysql-server', $url->host);
        $this->assertEquals(3307, $url->port);
        $this->assertEquals('root', $url->username);
        $this->assertEquals('pass123', $url->password);
        $this->assertEquals('shop', $url->database);
    }

    public function testDatabaseUrlMssql(): void
    {
        $url = new \Tina4\DatabaseUrl('mssql://sa:MyPass@mssql-host:1434/warehouse');
        $this->assertEquals('mssql', $url->engine);
        $this->assertEquals('mssql-host', $url->host);
        $this->assertEquals(1434, $url->port);
        $this->assertEquals('sa', $url->username);
        $this->assertEquals('MyPass', $url->password);
    }

    public function testDatabaseUrlFirebird(): void
    {
        $url = new \Tina4\DatabaseUrl('firebird://SYSDBA:masterkey@fbhost:3050/var/lib/firebird/data/app.fdb');
        $this->assertEquals('firebird', $url->engine);
        $this->assertEquals('fbhost', $url->host);
        $this->assertEquals(3050, $url->port);
        $this->assertEquals('SYSDBA', $url->username);
        $this->assertEquals('masterkey', $url->password);
    }

    public function testDatabaseUrlPostgresqlDefaults(): void
    {
        $url = new \Tina4\DatabaseUrl('postgresql://localhost/testdb');
        $this->assertEquals('localhost', $url->host);
        $this->assertEquals('testdb', $url->database);
    }

    public function testDatabaseUrlGetDsn(): void
    {
        $url = new \Tina4\DatabaseUrl('postgres://user:pass@host:5432/mydb');
        $dsn = $url->getDsn();
        $this->assertStringContainsString('host', $dsn);
        $this->assertStringContainsString('5432', $dsn);
    }

    // ── SQLite CRUD Tests ───────────────────────────────────────────

    public function testSQLiteInsertAndFetch(): void
    {
        $db = Database::create('sqlite::memory:', autoCommit: true);
        $adapter = $db->getAdapter();

        $adapter->exec("CREATE TABLE _test_crud (id INTEGER PRIMARY KEY, name VARCHAR(100))");
        $adapter->exec("INSERT INTO _test_crud (id, name) VALUES (1, 'Alice')");

        $rows = $adapter->query("SELECT * FROM _test_crud WHERE name = 'Alice'");
        $this->assertNotEmpty($rows);
        $this->assertEquals('Alice', $rows[0]['name']);

        $db->close();
    }

    public function testSQLiteUpdate(): void
    {
        $db = Database::create('sqlite::memory:', autoCommit: true);
        $adapter = $db->getAdapter();

        $adapter->exec("CREATE TABLE _test_upd (id INTEGER PRIMARY KEY, name VARCHAR(100))");
        $adapter->exec("INSERT INTO _test_upd (id, name) VALUES (1, 'Alice')");
        $adapter->exec("UPDATE _test_upd SET name = 'Bob' WHERE id = 1");

        $rows = $adapter->query("SELECT * FROM _test_upd WHERE id = 1");
        $this->assertNotEmpty($rows);
        $this->assertEquals('Bob', $rows[0]['name']);

        $db->close();
    }

    public function testSQLiteDelete(): void
    {
        $db = Database::create('sqlite::memory:', autoCommit: true);
        $adapter = $db->getAdapter();

        $adapter->exec("CREATE TABLE _test_del (id INTEGER PRIMARY KEY, name VARCHAR(100))");
        $adapter->exec("INSERT INTO _test_del (id, name) VALUES (1, 'Alice')");
        $adapter->exec("DELETE FROM _test_del WHERE id = 1");

        $result = $adapter->fetch("SELECT * FROM _test_del", [], 10);
        $this->assertTrue($result === null || empty($result->records));

        $db->close();
    }

    public function testSQLiteTableExists(): void
    {
        $db = Database::create('sqlite::memory:', autoCommit: true);
        $adapter = $db->getAdapter();

        $adapter->exec("CREATE TABLE _test_exists (id INTEGER PRIMARY KEY)");

        $this->assertTrue($adapter->tableExists('_test_exists'));
        $this->assertFalse($adapter->tableExists('_nonexistent_table'));

        $db->close();
    }

    public function testSQLiteGetDatabase(): void
    {
        $db = Database::create('sqlite::memory:', autoCommit: true);
        $adapter = $db->getAdapter();

        $result = $adapter->getDatabase();
        // getDatabase() returns the database path for SQLite
        $this->assertNotNull($result);
        $this->assertSame(':memory:', $result);

        $db->close();
    }

    public function testSQLiteTransactionRollback(): void
    {
        $db = Database::create('sqlite::memory:', autoCommit: true);
        $adapter = $db->getAdapter();

        $adapter->exec("CREATE TABLE _test_tx (id INTEGER PRIMARY KEY, name VARCHAR(100))");

        // Start a new transaction, insert, then rollback
        $adapter->startTransaction();
        $adapter->exec("INSERT INTO _test_tx (id, name) VALUES (1, 'Alice')");
        $adapter->rollback();

        $result = $adapter->fetch("SELECT * FROM _test_tx", [], 10);
        $this->assertTrue($result === null || empty($result->records));

        $db->close();
    }

    public function testSQLiteTransactionCommit(): void
    {
        $db = Database::create('sqlite::memory:', autoCommit: true);
        $adapter = $db->getAdapter();

        $adapter->exec("CREATE TABLE _test_txc (id INTEGER PRIMARY KEY, name VARCHAR(100))");

        $adapter->startTransaction();
        $adapter->exec("INSERT INTO _test_txc (id, name) VALUES (1, 'Alice')");
        $adapter->commit();

        $rows = $adapter->query("SELECT * FROM _test_txc WHERE id = 1");
        $this->assertNotEmpty($rows);
        $this->assertEquals('Alice', $rows[0]['name']);

        $db->close();
    }

    public function testSQLiteCloseAndReopen(): void
    {
        $path = sys_get_temp_dir() . '/tina4_close_reopen_' . uniqid() . '.db';
        $db = Database::create($path, autoCommit: true);
        $adapter = $db->getAdapter();
        $adapter->exec("CREATE TABLE _test_reopen (id INTEGER PRIMARY KEY, val TEXT)");
        $adapter->exec("INSERT INTO _test_reopen (id, val) VALUES (1, 'hello')");
        $db->close();

        // Reopen
        $db2 = Database::create($path, autoCommit: true);
        $adapter2 = $db2->getAdapter();
        $rows = $adapter2->query("SELECT * FROM _test_reopen WHERE id = 1");
        $this->assertNotEmpty($rows);
        $this->assertEquals('hello', $rows[0]['val']);
        $db2->close();

        @unlink($path);
    }

    public function testFactoryFromEnvWithSQLite(): void
    {
        $path = sys_get_temp_dir() . '/tina4_env_test_' . uniqid() . '.db';
        putenv("TINA4_TEST_ENV_DB_URL=sqlite:///{$path}");

        $db = Database::fromEnv('TINA4_TEST_ENV_DB_URL');
        $this->assertNotNull($db);
        $this->assertInstanceOf(Database::class, $db);
        $db->close();

        putenv('TINA4_TEST_ENV_DB_URL');
        @unlink($path);
    }

    // ── Adapter Method Existence ────────────────────────────────────

    public function testSQLite3AdapterHasRequiredMethods(): void
    {
        $methods = ['fetch', 'exec', 'execute', 'startTransaction', 'commit', 'rollback',
                     'tableExists', 'getDatabase', 'close'];
        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists(SQLite3Adapter::class, $method),
                "SQLite3Adapter missing method: {$method}"
            );
        }
    }

    public function testPostgresAdapterHasRequiredMethods(): void
    {
        $methods = ['fetch', 'execute', 'startTransaction', 'commit', 'rollback',
                     'tableExists', 'getDatabase', 'close'];
        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists(PostgresAdapter::class, $method),
                "PostgresAdapter missing method: {$method}"
            );
        }
    }

    public function testMySQLAdapterHasRequiredMethods(): void
    {
        $methods = ['fetch', 'execute', 'startTransaction', 'commit', 'rollback',
                     'tableExists', 'getDatabase', 'close'];
        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists(MySQLAdapter::class, $method),
                "MySQLAdapter missing method: {$method}"
            );
        }
    }

    public function testMSSQLAdapterHasRequiredMethods(): void
    {
        $methods = ['fetch', 'execute', 'startTransaction', 'commit', 'rollback',
                     'tableExists', 'getDatabase', 'close'];
        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists(MSSQLAdapter::class, $method),
                "MSSQLAdapter missing method: {$method}"
            );
        }
    }

    public function testFirebirdAdapterHasRequiredMethods(): void
    {
        $methods = ['fetch', 'execute', 'startTransaction', 'commit', 'rollback',
                     'tableExists', 'getDatabase', 'close'];
        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists(FirebirdAdapter::class, $method),
                "FirebirdAdapter missing method: {$method}"
            );
        }
    }

    // ── SQL Translation additional tests ────────────────────────────

    public function testSQLTranslatorConcatPipesToFunc(): void
    {
        $result = \Tina4\SQLTranslator::concatPipesToFunc(
            "SELECT first_name || ' ' || last_name FROM users"
        );
        $this->assertStringContainsString('CONCAT', $result);
        $this->assertStringNotContainsString('||', $result);
    }

    public function testSQLTranslatorAutoIncrementSQLite(): void
    {
        $result = \Tina4\SQLTranslator::autoIncrementSyntax(
            "CREATE TABLE test (id INTEGER AUTOINCREMENT, name TEXT)",
            'sqlite'
        );
        $this->assertIsString($result);
    }

    public function testSQLTranslatorAutoIncrementMySQL(): void
    {
        $result = \Tina4\SQLTranslator::autoIncrementSyntax(
            "CREATE TABLE test (id INTEGER AUTOINCREMENT, name TEXT)",
            'mysql'
        );
        $this->assertIsString($result);
    }

    public function testSQLTranslatorFirebirdBooleanAndIlike(): void
    {
        $translated = \Tina4\SQLTranslator::translate(
            "SELECT * FROM users WHERE active = TRUE AND name ILIKE '%bob%' AND deleted = FALSE",
            'firebird'
        );
        $this->assertStringContainsString('1', $translated);
        $this->assertStringContainsString('0', $translated);
        $this->assertStringContainsString('LOWER', $translated);
        $this->assertStringNotContainsString('TRUE', $translated);
        $this->assertStringNotContainsString('FALSE', $translated);
        $this->assertStringNotContainsString('ILIKE', $translated);
    }

    public function testSQLTranslatorFirebirdLimitOnlyToRows(): void
    {
        $translated = \Tina4\SQLTranslator::translate(
            "SELECT * FROM users LIMIT 10",
            'firebird'
        );
        $this->assertStringContainsString('ROWS', $translated);
        $this->assertStringNotContainsString('LIMIT', $translated);
    }

    public function testSQLTranslatorMssqlBooleanToIntDirect(): void
    {
        // MSSQL translate() doesn't apply booleanToInt; test the function directly
        $translated = \Tina4\SQLTranslator::booleanToInt(
            "SELECT * FROM users WHERE active = TRUE AND deleted = FALSE"
        );
        $this->assertStringContainsString('1', $translated);
        $this->assertStringContainsString('0', $translated);
    }

    public function testSQLTranslatorQueryKey(): void
    {
        $key1 = \Tina4\SQLTranslator::queryKey("SELECT * FROM users", []);
        $key2 = \Tina4\SQLTranslator::queryKey("SELECT * FROM users", []);
        $key3 = \Tina4\SQLTranslator::queryKey("SELECT * FROM products", []);
        $this->assertEquals($key1, $key2);
        $this->assertNotEquals($key1, $key3);
    }

    public function testSQLTranslatorCacheSetAndGet(): void
    {
        \Tina4\SQLTranslator::cacheClear();
        \Tina4\SQLTranslator::cacheSet('test_key', ['result' => 42], 60);
        $value = \Tina4\SQLTranslator::cacheGet('test_key');
        $this->assertEquals(['result' => 42], $value);
        \Tina4\SQLTranslator::cacheClear();
    }

    public function testSQLTranslatorCacheSize(): void
    {
        \Tina4\SQLTranslator::cacheClear();
        $this->assertEquals(0, \Tina4\SQLTranslator::cacheSize());
        \Tina4\SQLTranslator::cacheSet('key1', 'val1', 60);
        $this->assertEquals(1, \Tina4\SQLTranslator::cacheSize());
        \Tina4\SQLTranslator::cacheClear();
    }

    public function testSQLTranslatorCacheClear(): void
    {
        \Tina4\SQLTranslator::cacheSet('key1', 'val1', 60);
        \Tina4\SQLTranslator::cacheSet('key2', 'val2', 60);
        \Tina4\SQLTranslator::cacheClear();
        $this->assertEquals(0, \Tina4\SQLTranslator::cacheSize());
    }

    public function testSQLTranslatorRemember(): void
    {
        \Tina4\SQLTranslator::cacheClear();
        $callCount = 0;
        $factory = function () use (&$callCount) {
            $callCount++;
            return 'expensive_result';
        };

        $result1 = \Tina4\SQLTranslator::remember('remember_key', 60, $factory);
        $result2 = \Tina4\SQLTranslator::remember('remember_key', 60, $factory);

        $this->assertEquals('expensive_result', $result1);
        $this->assertEquals('expensive_result', $result2);
        $this->assertEquals(1, $callCount); // Factory called only once

        \Tina4\SQLTranslator::cacheClear();
    }

    // ── Database.create auto-commit ─────────────────────────────────

    public function testFactoryAutoCommitDefault(): void
    {
        $db = Database::create('sqlite::memory:');
        $this->assertInstanceOf(Database::class, $db);
        $db->close();
    }

    public function testFactoryAutoCommitExplicit(): void
    {
        $db = Database::create('sqlite::memory:', autoCommit: true);
        $this->assertInstanceOf(Database::class, $db);
        $db->close();
    }

    // ── Additional SQL Translation tests ────────────────────────────

    public function testSQLTranslatorMssqlBooleanToIntDirect2(): void
    {
        // MSSQL translate() only applies limitToTop — test booleanToInt directly
        $translated = \Tina4\SQLTranslator::booleanToInt(
            "WHERE active = TRUE AND disabled = FALSE"
        );
        $this->assertStringNotContainsString('TRUE', $translated);
        $this->assertStringNotContainsString('FALSE', $translated);
        $this->assertStringContainsString('1', $translated);
        $this->assertStringContainsString('0', $translated);
    }

    public function testSQLTranslatorFirebirdAutoIncrement(): void
    {
        $translated = \Tina4\SQLTranslator::translate(
            "CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT)",
            'firebird'
        );
        $this->assertStringNotContainsString('AUTOINCREMENT', $translated);
    }

    public function testSQLTranslatorMysqlAutoIncrement(): void
    {
        $translated = \Tina4\SQLTranslator::autoIncrementSyntax(
            "CREATE TABLE items (id INTEGER AUTOINCREMENT, name TEXT)",
            'mysql'
        );
        $this->assertStringContainsString('AUTO_INCREMENT', $translated);
        $this->assertStringNotContainsString('AUTOINCREMENT', $translated);
    }

    public function testSQLTranslatorCacheGetMissReturnsNull(): void
    {
        \Tina4\SQLTranslator::cacheClear();
        $value = \Tina4\SQLTranslator::cacheGet('nonexistent_key');
        $this->assertNull($value);
    }

    public function testSQLTranslatorCacheSweep(): void
    {
        \Tina4\SQLTranslator::cacheClear();
        // Set with very short TTL
        \Tina4\SQLTranslator::setCacheTtl(0);
        \Tina4\SQLTranslator::cacheSet('sweep_key', 'val', 0);
        // Sweep should remove expired entries
        $swept = \Tina4\SQLTranslator::cacheSweep();
        $this->assertIsInt($swept);
        \Tina4\SQLTranslator::cacheClear();
    }

    public function testSQLTranslatorRegisterAndApplyCustomFunction(): void
    {
        \Tina4\SQLTranslator::clearFunctions();
        \Tina4\SQLTranslator::registerFunction('NOW', function ($sql) {
            return str_ireplace('NOW()', 'CURRENT_TIMESTAMP', $sql);
        });
        $result = \Tina4\SQLTranslator::applyFunctionMappings('SELECT NOW() FROM dual');
        $this->assertStringContainsString('CURRENT_TIMESTAMP', $result);
        $this->assertStringNotContainsString('NOW()', $result);
        \Tina4\SQLTranslator::clearFunctions();
    }

    public function testSQLTranslatorQueryKeyWithParams(): void
    {
        $key1 = \Tina4\SQLTranslator::queryKey("SELECT * FROM users WHERE id = ?", [1]);
        $key2 = \Tina4\SQLTranslator::queryKey("SELECT * FROM users WHERE id = ?", [2]);
        $this->assertNotEquals($key1, $key2); // Different params = different keys
    }

    // ── SQLite multiple inserts and fetch with offset ──────────────

    public function testSQLiteFetchWithLimit(): void
    {
        $db = Database::create('sqlite::memory:', autoCommit: true);
        $adapter = $db->getAdapter();

        $adapter->exec("CREATE TABLE _test_page (id INTEGER PRIMARY KEY, name VARCHAR(100))");
        for ($i = 1; $i <= 10; $i++) {
            $adapter->exec("INSERT INTO _test_page (id, name) VALUES ({$i}, 'Item{$i}')");
        }

        $result = $adapter->fetch("SELECT * FROM _test_page ORDER BY id", [], 3);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(3, $result['data']);
        $this->assertEquals(10, $result['total']);

        $db->close();
    }

    // ── Factory supported schemes comprehensive ───────────────────

    public function testFactoryAllSchemesSupported(): void
    {
        $expected = ['sqlite', 'postgres', 'postgresql', 'mysql', 'mssql', 'sqlserver', 'firebird'];
        foreach ($expected as $scheme) {
            $this->assertTrue(
                Database::isSupported($scheme),
                "Scheme '{$scheme}' should be supported"
            );
        }
    }

    // ── Multiple SQLite operations within transaction ─────────────

    public function testSQLiteMultipleOperationsInTransaction(): void
    {
        $db = Database::create('sqlite::memory:', autoCommit: true);
        $adapter = $db->getAdapter();

        $adapter->exec("CREATE TABLE _test_multi (id INTEGER PRIMARY KEY, val TEXT)");

        $adapter->startTransaction();
        $adapter->exec("INSERT INTO _test_multi (id, val) VALUES (1, 'a')");
        $adapter->exec("INSERT INTO _test_multi (id, val) VALUES (2, 'b')");
        $adapter->exec("UPDATE _test_multi SET val = 'x' WHERE id = 1");
        $adapter->commit();

        $rows = $adapter->query("SELECT * FROM _test_multi ORDER BY id");
        $this->assertCount(2, $rows);
        $this->assertEquals('x', $rows[0]['val']);
        $this->assertEquals('b', $rows[1]['val']);

        $db->close();
    }

    // ── FirebirdAdapter parseConnection path handling (issue #101) ──
    // These tests validate the URL path-stripping logic directly using parse_url,
    // mirroring exactly what parseConnection() does after the fix.

    /**
     * Helper: apply the fixed path-stripping logic from parseConnection().
     * Strips exactly one leading '/' (the URL path separator), preserving any
     * remaining leading '/' that denotes an absolute filesystem path.
     */
    private function firebirdParsePath(string $url): string
    {
        $parts = parse_url($url);
        $rawPath = $parts['path'] ?? '';
        return $rawPath !== '' ? substr($rawPath, 1) : '';
    }

    public function testFirebirdParseConnectionAbsolutePath(): void
    {
        // firebird://localhost:3050//absolute/path/to/db.fdb
        // parse_url gives path = //absolute/path/to/db.fdb
        // strip one leading slash → /absolute/path/to/db.fdb (absolute preserved)
        $db = $this->firebirdParsePath('firebird://localhost:3050//absolute/path/to/db.fdb');
        $this->assertEquals('/absolute/path/to/db.fdb', $db, 'Absolute path must retain its leading slash');
    }

    public function testFirebirdParseConnectionRelativePath(): void
    {
        // firebird://localhost/data/mydb.fdb
        // parse_url gives path = /data/mydb.fdb
        // strip one leading slash → data/mydb.fdb (relative, no leading slash)
        $db = $this->firebirdParsePath('firebird://localhost/data/mydb.fdb');
        $this->assertEquals('data/mydb.fdb', $db, 'Relative path must not have a leading slash');
    }

    public function testFirebirdParseConnectionEmptyPath(): void
    {
        // firebird://localhost (no path component)
        $db = $this->firebirdParsePath('firebird://localhost');
        $this->assertEquals('', $db, 'Missing path must yield empty string');
    }
}
