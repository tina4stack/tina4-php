<?php

/**
 * Tina4 - This is not a 4ramework.
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
use Tina4\Database\DatabaseFactory;
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
        $db = DatabaseFactory::create('sqlite::memory:');
        $this->assertInstanceOf(SQLite3Adapter::class, $db);
        $db->close();
    }

    public function testFactorySQLiteBareMemory(): void
    {
        $db = DatabaseFactory::create(':memory:');
        $this->assertInstanceOf(SQLite3Adapter::class, $db);
        $db->close();
    }

    public function testFactorySQLiteFilePath(): void
    {
        $path = sys_get_temp_dir() . '/tina4_factory_test_' . uniqid() . '.db';
        $db = DatabaseFactory::create($path);
        $this->assertInstanceOf(SQLite3Adapter::class, $db);
        $db->close();
        @unlink($path);
    }

    public function testFactorySQLiteUrl(): void
    {
        $db = DatabaseFactory::create('sqlite:///:memory:');
        $this->assertInstanceOf(SQLite3Adapter::class, $db);
        $db->close();
    }

    public function testFactoryUnsupportedScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database scheme');
        DatabaseFactory::create('oracle://localhost/test');
    }

    public function testFactoryInvalidUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DatabaseFactory::create('not-a-valid-url');
    }

    public function testFactorySupportedSchemes(): void
    {
        $schemes = DatabaseFactory::supportedSchemes();
        $this->assertContains('sqlite', $schemes);
        $this->assertContains('pgsql', $schemes);
        $this->assertContains('postgres', $schemes);
        $this->assertContains('mysql', $schemes);
        $this->assertContains('mssql', $schemes);
        $this->assertContains('firebird', $schemes);
    }

    public function testFactoryIsSupported(): void
    {
        $this->assertTrue(DatabaseFactory::isSupported('sqlite'));
        $this->assertTrue(DatabaseFactory::isSupported('pgsql'));
        $this->assertTrue(DatabaseFactory::isSupported('mysql'));
        $this->assertTrue(DatabaseFactory::isSupported('mssql'));
        $this->assertTrue(DatabaseFactory::isSupported('firebird'));
        $this->assertFalse(DatabaseFactory::isSupported('oracle'));
        $this->assertFalse(DatabaseFactory::isSupported('cassandra'));
    }

    public function testFactoryFromEnvReturnsNullWhenNotSet(): void
    {
        $result = DatabaseFactory::fromEnv('TINA4_TEST_DB_NONEXISTENT_' . uniqid());
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

        $db = DatabaseFactory::create($url);
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

        $db = DatabaseFactory::create($url);
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

        $db = DatabaseFactory::create($url);
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

        $db = DatabaseFactory::create($url);
        $this->assertInstanceOf(FirebirdAdapter::class, $db);
        $db->close();
    }
}
