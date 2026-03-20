<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\DatabaseUrl;
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
        $db = new DatabaseUrl('pgsql://admin:secret@db.example.com:5432/myapp');

        $this->assertEquals('pgsql', $db->scheme);
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
        $db = new DatabaseUrl('mariadb://user:pass@host/db');

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
        $pg = new DatabaseUrl('pgsql://user:pass@host/db');
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
        $db = new DatabaseUrl('pgsql://user:pass@host/db');

        $this->assertEquals('Tina4\\DataPostgresql', $db->getDriverClass());
    }

    public function testGetDsnForSqlite(): void
    {
        $db = new DatabaseUrl('sqlite:///tmp/test.db');

        $this->assertEquals('/tmp/test.db', $db->getDsn());
    }

    public function testGetDsnForNetworkDb(): void
    {
        $db = new DatabaseUrl('pgsql://user:pass@db.host:5432/mydb');

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
}
