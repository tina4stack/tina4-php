<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\DatabaseUrl;
use Tina4\Database\Database;
use Tina4\Database\SQLite3Adapter;
use Tina4\DotEnv;

class DatabaseUrlTest extends TestCase
{
    protected function setUp(): void
    {
        DotEnv::resetEnv();
    }

    protected function tearDown(): void
    {
        DotEnv::resetEnv();
        putenv('DATABASE_URL');
        unset($_ENV['DATABASE_URL'], $_SERVER['DATABASE_URL']);
    }

    public function testParseSqlitePath(): void
    {
        $db = new DatabaseUrl('sqlite:///path/to/database.db');

        $this->assertEquals('sqlite', $db->scheme);
        $this->assertEquals('DataSQLite3', $db->driver);
        $this->assertEquals('/path/to/database.db', $db->database);
        $this->assertEquals('', $db->host);
        $this->assertEquals(0, $db->port);
        $this->assertEquals('', $db->username);
        $this->assertEquals('', $db->password);
    }

    public function testParseSqliteMemory(): void
    {
        $db = new DatabaseUrl('sqlite::memory:');

        $this->assertEquals('sqlite', $db->scheme);
        $this->assertEquals(':memory:', $db->database);
    }

    public function testParseSqliteMemoryWithSlashes(): void
    {
        $db = new DatabaseUrl('sqlite:///:memory:');

        $this->assertEquals(':memory:', $db->database);
    }

    public function testParsePostgresql(): void
    {
        $db = new DatabaseUrl('postgres://admin:secret@db.example.com:5432/myapp');

        $this->assertEquals('postgres', $db->scheme);
        $this->assertEquals('DataPostgresql', $db->driver);
        $this->assertEquals('db.example.com', $db->host);
        $this->assertEquals(5432, $db->port);
        $this->assertEquals('myapp', $db->database);
        $this->assertEquals('admin', $db->username);
        $this->assertEquals('secret', $db->password);
    }

    public function testParsePostgresAlias(): void
    {
        $db = new DatabaseUrl('postgres://user:pass@host/db');

        $this->assertEquals('DataPostgresql', $db->driver);
    }

    public function testParseMysql(): void
    {
        $db = new DatabaseUrl('mysql://root:password@localhost:3306/shop');

        $this->assertEquals('mysql', $db->scheme);
        $this->assertEquals('DataMySQL', $db->driver);
        $this->assertEquals('localhost', $db->host);
        $this->assertEquals(3306, $db->port);
        $this->assertEquals('shop', $db->database);
        $this->assertEquals('root', $db->username);
        $this->assertEquals('password', $db->password);
    }

    public function testParseMariadb(): void
    {
        $db = new DatabaseUrl('mysql://user:pass@host/db');

        $this->assertEquals('DataMySQL', $db->driver);
    }

    public function testParseMssql(): void
    {
        $db = new DatabaseUrl('mssql://sa:P%40ssw0rd@sqlserver:1433/enterprise');

        $this->assertEquals('DataMSSQL', $db->driver);
        $this->assertEquals('sqlserver', $db->host);
        $this->assertEquals(1433, $db->port);
        $this->assertEquals('enterprise', $db->database);
        $this->assertEquals('sa', $db->username);
        $this->assertEquals('P@ssw0rd', $db->password); // URL-decoded
    }

    public function testParseFirebird(): void
    {
        $db = new DatabaseUrl('firebird://sysdba:masterkey@localhost:3050/opt/firebird/data.fdb');

        $this->assertEquals('DataFirebird', $db->driver);
        $this->assertEquals('localhost', $db->host);
        $this->assertEquals(3050, $db->port);
        $this->assertEquals('opt/firebird/data.fdb', $db->database);
    }

    public function testDefaultPorts(): void
    {
        $pg = new DatabaseUrl('postgres://user:pass@host/db');
        $this->assertEquals(5432, $pg->port);

        $mysql = new DatabaseUrl('mysql://user:pass@host/db');
        $this->assertEquals(3306, $mysql->port);

        $mssql = new DatabaseUrl('mssql://user:pass@host/db');
        $this->assertEquals(1433, $mssql->port);

        $fb = new DatabaseUrl('firebird://user:pass@host/db');
        $this->assertEquals(3050, $fb->port);
    }

    public function testGetDriverClass(): void
    {
        $db = new DatabaseUrl('postgres://user:pass@host/db');

        $this->assertEquals('Tina4\\DataPostgresql', $db->getDriverClass());
    }

    public function testGetDsnForSqlite(): void
    {
        $db = new DatabaseUrl('sqlite:///tmp/test.db');

        $this->assertEquals('/tmp/test.db', $db->getDsn());
    }

    public function testGetDsnForNetworkDb(): void
    {
        $db = new DatabaseUrl('postgres://user:pass@db.host:5432/mydb');

        $this->assertEquals('db.host:5432/mydb', $db->getDsn());
    }

    public function testToSafeStringMasksPassword(): void
    {
        $db = new DatabaseUrl('mysql://root:supersecret@localhost:3306/mydb');

        $safe = $db->toSafeString();

        $this->assertStringContainsString('root', $safe);
        $this->assertStringContainsString('***', $safe);
        $this->assertStringNotContainsString('supersecret', $safe);
    }

    public function testToSafeStringForSqlite(): void
    {
        $db = new DatabaseUrl('sqlite:///tmp/test.db');

        $this->assertEquals('sqlite:////tmp/test.db', $db->toSafeString());
    }

    public function testInvalidUrlThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DatabaseUrl('not-a-valid-url');
    }

    public function testUnsupportedSchemeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database scheme');

        new DatabaseUrl('redis://localhost:6379/0');
    }

    public function testFromEnvReturnsNullWhenNotSet(): void
    {
        $result = DatabaseUrl::fromEnv();

        $this->assertNull($result);
    }

    public function testFromEnvParsesWhenSet(): void
    {
        $_ENV['DATABASE_URL'] = 'sqlite:///tmp/test.db';
        putenv('DATABASE_URL=sqlite:///tmp/test.db');

        // Reset DotEnv so it picks up the env var
        DotEnv::resetEnv();

        $result = DatabaseUrl::fromEnv();

        $this->assertNotNull($result);
        $this->assertEquals('sqlite', $result->scheme);
    }

    public function testUrlEncodedCredentials(): void
    {
        $db = new DatabaseUrl('mysql://user%40domain:p%23ss%26word@host/db');

        $this->assertEquals('user@domain', $db->username);
        $this->assertEquals('p#ss&word', $db->password);
    }

    // --- Database Tests ---

    public function testFactoryCreateSqliteMemory(): void
    {
        $db = Database::create(':memory:');
        $this->assertInstanceOf(Database::class, $db);
        $this->assertInstanceOf(SQLite3Adapter::class, $db->getAdapter());
    }

    public function testFactoryCreateSqliteMemoryWithScheme(): void
    {
        $db = Database::create('sqlite::memory:');
        $this->assertInstanceOf(Database::class, $db);
        $this->assertInstanceOf(SQLite3Adapter::class, $db->getAdapter());
    }

    public function testFactoryCreateSqliteMemoryWithSlashes(): void
    {
        $db = Database::create('sqlite:///:memory:');
        $this->assertInstanceOf(Database::class, $db);
        $this->assertInstanceOf(SQLite3Adapter::class, $db->getAdapter());
    }

    public function testFactoryCreateSqliteFromPath(): void
    {
        $db = Database::create('sqlite:///tmp/test_factory.db');
        $this->assertInstanceOf(Database::class, $db);
        $this->assertInstanceOf(SQLite3Adapter::class, $db->getAdapter());
    }

    public function testFactoryCreateSqliteFromBareFilePath(): void
    {
        $db = Database::create('/tmp/test_factory_bare.db');
        $this->assertInstanceOf(Database::class, $db);
        $this->assertInstanceOf(SQLite3Adapter::class, $db->getAdapter());
    }

    public function testFactoryCreateSqliteFromSqlite3Extension(): void
    {
        $db = Database::create('/tmp/test_factory.sqlite3');
        $this->assertInstanceOf(Database::class, $db);
        $this->assertInstanceOf(SQLite3Adapter::class, $db->getAdapter());
    }

    public function testFactoryInvalidUrlThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot determine database type');

        Database::create('not-a-valid-url');
    }

    public function testFactoryUnsupportedSchemeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database scheme');

        Database::create('redis://localhost:6379/0');
    }

    public function testFactorySupportedSchemes(): void
    {
        $schemes = Database::supportedSchemes();

        $this->assertContains('sqlite', $schemes);
        $this->assertContains('postgres', $schemes);
        $this->assertContains('mysql', $schemes);
        $this->assertContains('mssql', $schemes);
        $this->assertContains('firebird', $schemes);
    }

    public function testFactoryIsSupportedTrue(): void
    {
        $this->assertTrue(Database::isSupported('sqlite'));
        $this->assertTrue(Database::isSupported('SQLITE'));
        $this->assertTrue(Database::isSupported('postgres'));
        $this->assertTrue(Database::isSupported('mysql'));
    }

    public function testFactoryIsSupportedFalse(): void
    {
        $this->assertFalse(Database::isSupported('redis'));
        $this->assertFalse(Database::isSupported('mongodb'));
        $this->assertFalse(Database::isSupported(''));
    }

    public function testFactoryFromEnvReturnsNullWhenNotSet(): void
    {
        putenv('DATABASE_URL');
        unset($_ENV['DATABASE_URL'], $_SERVER['DATABASE_URL']);
        DotEnv::resetEnv();

        $result = Database::fromEnv();
        $this->assertNull($result);
    }

    public function testFactoryFromEnvCreatesSqliteAdapter(): void
    {
        $_ENV['DATABASE_URL'] = 'sqlite::memory:';
        putenv('DATABASE_URL=sqlite::memory:');
        DotEnv::resetEnv();

        $result = Database::fromEnv();
        $this->assertInstanceOf(Database::class, $result);
        $this->assertInstanceOf(SQLite3Adapter::class, $result->getAdapter());
    }

    public function testFactoryCreateWithSeparateCredentials(): void
    {
        // The factory accepts separate username/password params
        // For SQLite these are ignored, but we verify the method signature works
        $db = Database::create(':memory:', null, 'testuser', 'testpass');
        $this->assertInstanceOf(Database::class, $db);
        $this->assertInstanceOf(SQLite3Adapter::class, $db->getAdapter());
    }

    public function testFactoryAutoCommitParam(): void
    {
        // Verify autoCommit parameter is accepted
        $db = Database::create(':memory:', false);
        $this->assertInstanceOf(Database::class, $db);
        $this->assertInstanceOf(SQLite3Adapter::class, $db->getAdapter());

        $db2 = Database::create(':memory:', true);
        $this->assertInstanceOf(Database::class, $db2);
        $this->assertInstanceOf(SQLite3Adapter::class, $db2->getAdapter());
    }

    // --- DatabaseUrl Additional Alias Tests ---

    public function testSqlite3AliasRemoved(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DatabaseUrl('sqlite3:///tmp/test.db');
    }

    public function testSqlsrvAliasRemoved(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DatabaseUrl('sqlsrv://sa:pass@host/db');
    }

    public function testFdbAliasRemoved(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DatabaseUrl('fdb://user:pass@host/path/to/db.fdb');
    }

    public function testGetDsnWithoutPort(): void
    {
        // SQLite has port 0, so getDsn should just return the database
        $db = new DatabaseUrl('sqlite:///tmp/test.db');
        $this->assertEquals('/tmp/test.db', $db->getDsn());
    }

    public function testGetDsnWithoutDatabase(): void
    {
        // When only host:port is provided
        $db = new DatabaseUrl('postgres://user:pass@host:5432/');
        $dsn = $db->getDsn();
        $this->assertStringContainsString('host', $dsn);
        $this->assertStringContainsString('5432', $dsn);
    }

    public function testToSafeStringWithoutPassword(): void
    {
        $db = new DatabaseUrl('postgres://user@host/db');
        $safe = $db->toSafeString();
        $this->assertStringContainsString('user@', $safe);
        $this->assertStringNotContainsString('***', $safe);
    }

    public function testToSafeStringWithoutCredentials(): void
    {
        // URL with host but no user/pass
        $db = new DatabaseUrl('postgres://host:5432/db');
        $safe = $db->toSafeString();
        $this->assertStringNotContainsString('@', $safe);
    }
}
