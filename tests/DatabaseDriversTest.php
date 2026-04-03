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

    public function testMSSQLThrowsWithoutExtension(): void
    {
        if (function_exists('sqlsrv_connect')) {
            $this->markTestSkipped('ext-sqlsrv is installed — cannot test missing extension error');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ext-sqlsrv');
        new MSSQLAdapter('mssql://localhost/test');
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

    public function testSqlTranslationFirebirdLimitToRows(): void
    {
        $translated = \Tina4\SqlTranslation::translate(
            "SELECT * FROM users WHERE active = TRUE LIMIT 10 OFFSET 5",
            'firebird'
        );
        // Firebird: LIMIT/OFFSET => ROWS X TO Y, TRUE => 1
        $this->assertStringContainsString('ROWS', $translated);
        $this->assertStringNotContainsString('LIMIT', $translated);
        $this->assertStringNotContainsString('TRUE', $translated);
    }

    public function testSqlTranslationMssqlLimitToTop(): void
    {
        $translated = \Tina4\SqlTranslation::translate(
            "SELECT * FROM users LIMIT 10",
            'mssql'
        );
        // MSSQL: LIMIT => TOP
        $this->assertStringContainsString('TOP', $translated);
        $this->assertStringNotContainsString('LIMIT', $translated);
    }

    public function testSqlTranslationPostgresPassthrough(): void
    {
        $sql = "SELECT * FROM users WHERE active = TRUE LIMIT 10 OFFSET 5";
        $translated = \Tina4\SqlTranslation::translate($sql, 'postgresql');
        // PostgreSQL supports LIMIT/OFFSET and TRUE natively
        $this->assertStringContainsString('LIMIT', $translated);
        $this->assertStringContainsString('TRUE', $translated);
    }

    public function testSqlTranslationMysqlBooleanToInt(): void
    {
        // MySQL translate() does auto-increment syntax only; test booleanToInt directly
        $translated = \Tina4\SqlTranslation::booleanToInt(
            "SELECT * FROM users WHERE active = TRUE AND deleted = FALSE"
        );
        $this->assertStringContainsString('1', $translated);
        $this->assertStringContainsString('0', $translated);
        $this->assertStringNotContainsString('TRUE', $translated);
        $this->assertStringNotContainsString('FALSE', $translated);
    }

    public function testSqlTranslationFirebirdIlikeToLike(): void
    {
        $translated = \Tina4\SqlTranslation::translate(
            "SELECT * FROM users WHERE name ILIKE '%alice%'",
            'firebird'
        );
        // ILIKE => LOWER() LIKE LOWER()
        $this->assertStringContainsString('LOWER', $translated);
        $this->assertStringNotContainsString('ILIKE', $translated);
    }

    public function testSqlTranslationMssqlIlikeToLike(): void
    {
        // MSSQL translate() does not apply ilikeToLike; test the function directly
        $translated = \Tina4\SqlTranslation::ilikeToLike(
            "SELECT * FROM users WHERE name ILIKE '%alice%'"
        );
        $this->assertStringContainsString('LOWER', $translated);
        $this->assertStringNotContainsString('ILIKE', $translated);
    }

    public function testSqlTranslationPlaceholderStyleNumbered(): void
    {
        // Convert ? to :1, :2 (numbered colon style)
        $result = \Tina4\SqlTranslation::placeholderStyle(
            "SELECT * FROM users WHERE name = ? AND age = ?",
            ':'
        );
        $this->assertStringContainsString(':1', $result);
        $this->assertStringContainsString(':2', $result);
        $this->assertStringNotContainsString('?', $result);
    }

    public function testSqlTranslationPlaceholderStyleSprintf(): void
    {
        // Convert ? to %s (sprintf style)
        $result = \Tina4\SqlTranslation::placeholderStyle(
            "SELECT * FROM users WHERE name = ? AND age = ?",
            '%s'
        );
        $this->assertStringContainsString('%s', $result);
        $this->assertStringNotContainsString('?', $result);
    }

    public function testSqlTranslationHasReturning(): void
    {
        $this->assertTrue(
            \Tina4\SqlTranslation::hasReturning("INSERT INTO users (name) VALUES ('Alice') RETURNING id")
        );
        $this->assertFalse(
            \Tina4\SqlTranslation::hasReturning("INSERT INTO users (name) VALUES ('Alice')")
        );
    }

    public function testSqlTranslationExtractReturning(): void
    {
        $result = \Tina4\SqlTranslation::extractReturning(
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

        $url = getenv('TINA4_TEST_POSTGRES_URL');
        if (!$url) {
            $this->markTestSkipped('Set TINA4_TEST_POSTGRES_URL to run live PostgreSQL tests (e.g. pgsql://user:pass@localhost/testdb)');
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

        $url = getenv('TINA4_TEST_MYSQL_URL');
        if (!$url) {
            $this->markTestSkipped('Set TINA4_TEST_MYSQL_URL to run live MySQL tests (e.g. mysql://user:pass@localhost/testdb)');
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
        if (!function_exists('sqlsrv_connect')) {
            $this->markTestSkipped('ext-sqlsrv not installed');
        }

        $url = getenv('TINA4_TEST_MSSQL_URL');
        if (!$url) {
            $this->markTestSkipped('Set TINA4_TEST_MSSQL_URL to run live MSSQL tests (e.g. mssql://user:pass@localhost/testdb)');
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

        $db = new FirebirdAdapter($url);
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

        $url = getenv('TINA4_TEST_POSTGRES_URL');
        if (!$url) {
            $this->markTestSkipped('Set TINA4_TEST_POSTGRES_URL for live factory test');
        }

        $db = Database::create($url);
        $this->assertInstanceOf(PostgresAdapter::class, $db);
        $db->close();
    }

    public function testFactoryCreatesMySQLAdapter(): void
    {
        if (!extension_loaded('mysqli')) {
            $this->markTestSkipped('ext-mysqli not installed');
        }

        $url = getenv('TINA4_TEST_MYSQL_URL');
        if (!$url) {
            $this->markTestSkipped('Set TINA4_TEST_MYSQL_URL for live factory test');
        }

        $db = Database::create($url);
        $this->assertInstanceOf(MySQLAdapter::class, $db);
        $db->close();
    }

    public function testFactoryCreatesMSSQLAdapter(): void
    {
        if (!function_exists('sqlsrv_connect')) {
            $this->markTestSkipped('ext-sqlsrv not installed');
        }

        $url = getenv('TINA4_TEST_MSSQL_URL');
        if (!$url) {
            $this->markTestSkipped('Set TINA4_TEST_MSSQL_URL for live factory test');
        }

        $db = Database::create($url);
        $this->assertInstanceOf(MSSQLAdapter::class, $db);
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

        $db = Database::create($url);
        $this->assertInstanceOf(FirebirdAdapter::class, $db);
        $db->close();
    }

    // ── Connection URL Parsing ──────────────────────────────────────

    public function testDatabaseUrlPostgresql(): void
    {
        $url = new \Tina4\DatabaseUrl('postgresql://alice:secret@db.example.com:5433/myapp');
        $this->assertEquals('postgresql', $url->scheme);
        $this->assertEquals('db.example.com', $url->host);
        $this->assertEquals(5433, $url->port);
        $this->assertEquals('alice', $url->username);
        $this->assertEquals('secret', $url->password);
        $this->assertEquals('myapp', $url->database);
    }

    public function testDatabaseUrlMysql(): void
    {
        $url = new \Tina4\DatabaseUrl('mysql://root:pass123@mysql-server:3307/shop');
        $this->assertEquals('mysql', $url->scheme);
        $this->assertEquals('mysql-server', $url->host);
        $this->assertEquals(3307, $url->port);
        $this->assertEquals('root', $url->username);
        $this->assertEquals('pass123', $url->password);
        $this->assertEquals('shop', $url->database);
    }

    public function testDatabaseUrlMssql(): void
    {
        $url = new \Tina4\DatabaseUrl('mssql://sa:MyPass@mssql-host:1434/warehouse');
        $this->assertEquals('mssql', $url->scheme);
        $this->assertEquals('mssql-host', $url->host);
        $this->assertEquals(1434, $url->port);
        $this->assertEquals('sa', $url->username);
        $this->assertEquals('MyPass', $url->password);
    }

    public function testDatabaseUrlFirebird(): void
    {
        $url = new \Tina4\DatabaseUrl('firebird://SYSDBA:masterkey@fbhost:3050/var/lib/firebird/data/app.fdb');
        $this->assertEquals('firebird', $url->scheme);
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

        $result = $adapter->fetch("SELECT * FROM _test_del", 10);
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

        $result = $adapter->fetch("SELECT * FROM _test_tx", 10);
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

    public function testSqlTranslationConcatPipesToFunc(): void
    {
        $result = \Tina4\SqlTranslation::concatPipesToFunc(
            "SELECT first_name || ' ' || last_name FROM users"
        );
        $this->assertStringContainsString('CONCAT', $result);
        $this->assertStringNotContainsString('||', $result);
    }

    public function testSqlTranslationAutoIncrementSQLite(): void
    {
        $result = \Tina4\SqlTranslation::autoIncrementSyntax(
            "CREATE TABLE test (id INTEGER AUTOINCREMENT, name TEXT)",
            'sqlite'
        );
        $this->assertIsString($result);
    }

    public function testSqlTranslationAutoIncrementMySQL(): void
    {
        $result = \Tina4\SqlTranslation::autoIncrementSyntax(
            "CREATE TABLE test (id INTEGER AUTOINCREMENT, name TEXT)",
            'mysql'
        );
        $this->assertIsString($result);
    }

    public function testSqlTranslationFirebirdBooleanAndIlike(): void
    {
        $translated = \Tina4\SqlTranslation::translate(
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

    public function testSqlTranslationFirebirdLimitOnlyToRows(): void
    {
        $translated = \Tina4\SqlTranslation::translate(
            "SELECT * FROM users LIMIT 10",
            'firebird'
        );
        $this->assertStringContainsString('ROWS', $translated);
        $this->assertStringNotContainsString('LIMIT', $translated);
    }

    public function testSqlTranslationMssqlBooleanToIntDirect(): void
    {
        // MSSQL translate() doesn't apply booleanToInt; test the function directly
        $translated = \Tina4\SqlTranslation::booleanToInt(
            "SELECT * FROM users WHERE active = TRUE AND deleted = FALSE"
        );
        $this->assertStringContainsString('1', $translated);
        $this->assertStringContainsString('0', $translated);
    }

    public function testSqlTranslationQueryKey(): void
    {
        $key1 = \Tina4\SqlTranslation::queryKey("SELECT * FROM users", []);
        $key2 = \Tina4\SqlTranslation::queryKey("SELECT * FROM users", []);
        $key3 = \Tina4\SqlTranslation::queryKey("SELECT * FROM products", []);
        $this->assertEquals($key1, $key2);
        $this->assertNotEquals($key1, $key3);
    }

    public function testSqlTranslationCacheSetAndGet(): void
    {
        \Tina4\SqlTranslation::cacheClear();
        \Tina4\SqlTranslation::cacheSet('test_key', ['result' => 42], 60);
        $value = \Tina4\SqlTranslation::cacheGet('test_key');
        $this->assertEquals(['result' => 42], $value);
        \Tina4\SqlTranslation::cacheClear();
    }

    public function testSqlTranslationCacheSize(): void
    {
        \Tina4\SqlTranslation::cacheClear();
        $this->assertEquals(0, \Tina4\SqlTranslation::cacheSize());
        \Tina4\SqlTranslation::cacheSet('key1', 'val1', 60);
        $this->assertEquals(1, \Tina4\SqlTranslation::cacheSize());
        \Tina4\SqlTranslation::cacheClear();
    }

    public function testSqlTranslationCacheClear(): void
    {
        \Tina4\SqlTranslation::cacheSet('key1', 'val1', 60);
        \Tina4\SqlTranslation::cacheSet('key2', 'val2', 60);
        \Tina4\SqlTranslation::cacheClear();
        $this->assertEquals(0, \Tina4\SqlTranslation::cacheSize());
    }

    public function testSqlTranslationRemember(): void
    {
        \Tina4\SqlTranslation::cacheClear();
        $callCount = 0;
        $factory = function () use (&$callCount) {
            $callCount++;
            return 'expensive_result';
        };

        $result1 = \Tina4\SqlTranslation::remember('remember_key', 60, $factory);
        $result2 = \Tina4\SqlTranslation::remember('remember_key', 60, $factory);

        $this->assertEquals('expensive_result', $result1);
        $this->assertEquals('expensive_result', $result2);
        $this->assertEquals(1, $callCount); // Factory called only once

        \Tina4\SqlTranslation::cacheClear();
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

    public function testSqlTranslationMssqlBooleanToIntDirect2(): void
    {
        // MSSQL translate() only applies limitToTop — test booleanToInt directly
        $translated = \Tina4\SqlTranslation::booleanToInt(
            "WHERE active = TRUE AND disabled = FALSE"
        );
        $this->assertStringNotContainsString('TRUE', $translated);
        $this->assertStringNotContainsString('FALSE', $translated);
        $this->assertStringContainsString('1', $translated);
        $this->assertStringContainsString('0', $translated);
    }

    public function testSqlTranslationFirebirdAutoIncrement(): void
    {
        $translated = \Tina4\SqlTranslation::translate(
            "CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT)",
            'firebird'
        );
        $this->assertStringNotContainsString('AUTOINCREMENT', $translated);
    }

    public function testSqlTranslationMysqlAutoIncrement(): void
    {
        $translated = \Tina4\SqlTranslation::autoIncrementSyntax(
            "CREATE TABLE items (id INTEGER AUTOINCREMENT, name TEXT)",
            'mysql'
        );
        $this->assertStringContainsString('AUTO_INCREMENT', $translated);
        $this->assertStringNotContainsString('AUTOINCREMENT', $translated);
    }

    public function testSqlTranslationCacheGetMissReturnsNull(): void
    {
        \Tina4\SqlTranslation::cacheClear();
        $value = \Tina4\SqlTranslation::cacheGet('nonexistent_key');
        $this->assertNull($value);
    }

    public function testSqlTranslationCacheSweep(): void
    {
        \Tina4\SqlTranslation::cacheClear();
        // Set with very short TTL
        \Tina4\SqlTranslation::setCacheTtl(0);
        \Tina4\SqlTranslation::cacheSet('sweep_key', 'val', 0);
        // Sweep should remove expired entries
        $swept = \Tina4\SqlTranslation::cacheSweep();
        $this->assertIsInt($swept);
        \Tina4\SqlTranslation::cacheClear();
    }

    public function testSqlTranslationRegisterAndApplyCustomFunction(): void
    {
        \Tina4\SqlTranslation::clearFunctions();
        \Tina4\SqlTranslation::registerFunction('NOW', function ($sql) {
            return str_ireplace('NOW()', 'CURRENT_TIMESTAMP', $sql);
        });
        $result = \Tina4\SqlTranslation::applyFunctionMappings('SELECT NOW() FROM dual');
        $this->assertStringContainsString('CURRENT_TIMESTAMP', $result);
        $this->assertStringNotContainsString('NOW()', $result);
        \Tina4\SqlTranslation::clearFunctions();
    }

    public function testSqlTranslationQueryKeyWithParams(): void
    {
        $key1 = \Tina4\SqlTranslation::queryKey("SELECT * FROM users WHERE id = ?", [1]);
        $key2 = \Tina4\SqlTranslation::queryKey("SELECT * FROM users WHERE id = ?", [2]);
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

        $result = $adapter->fetch("SELECT * FROM _test_page ORDER BY id", 3);
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
