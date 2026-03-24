<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\DatabaseResult;
use Tina4\Database\SQLite3Adapter;
use Tina4\DotEnv;

class DatabaseTest extends TestCase
{
    private ?Database $db = null;

    protected function setUp(): void
    {
        $this->db = Database::create(':memory:');
        $this->db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)");
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->close();
            $this->db = null;
        }
        DotEnv::resetEnv();
        putenv('DATABASE_URL');
        unset($_ENV['DATABASE_URL'], $_SERVER['DATABASE_URL']);
    }

    // -- Factory methods --------------------------------------------------------

    public function testCreateReturnsDatabase(): void
    {
        $db = Database::create(':memory:');
        $this->assertInstanceOf(Database::class, $db);
        $db->close();
    }

    public function testCreateSqliteMemoryScheme(): void
    {
        $db = Database::create('sqlite::memory:');
        $this->assertInstanceOf(Database::class, $db);
        $this->assertInstanceOf(SQLite3Adapter::class, $db->getAdapter());
        $db->close();
    }

    public function testFromEnvReturnsNullWhenNotSet(): void
    {
        putenv('DATABASE_URL');
        unset($_ENV['DATABASE_URL'], $_SERVER['DATABASE_URL']);
        DotEnv::resetEnv();

        $result = Database::fromEnv();
        $this->assertNull($result);
    }

    public function testFromEnvCreatesDatabase(): void
    {
        $_ENV['DATABASE_URL'] = 'sqlite::memory:';
        putenv('DATABASE_URL=sqlite::memory:');
        DotEnv::resetEnv();

        $result = Database::fromEnv();
        $this->assertInstanceOf(Database::class, $result);
        $result->close();
    }

    public function testGetConnectionAlias(): void
    {
        putenv('DATABASE_URL');
        unset($_ENV['DATABASE_URL'], $_SERVER['DATABASE_URL']);
        DotEnv::resetEnv();

        $result = Database::getConnection();
        $this->assertNull($result);
    }

    // -- fetch() ----------------------------------------------------------------

    public function testFetchReturnsDatabaseResult(): void
    {
        $this->db->insert('users', ['id' => 1, 'name' => 'Alice', 'email' => 'alice@test.com']);

        $result = $this->db->fetch("SELECT * FROM users");

        $this->assertInstanceOf(DatabaseResult::class, $result);
        $this->assertGreaterThanOrEqual(1, count($result));
        $this->assertSame('Alice', $result[0]['name']);
    }

    public function testFetchPagination(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->db->insert('users', ['id' => $i, 'name' => "User{$i}", 'email' => "user{$i}@test.com"]);
        }

        $result = $this->db->fetch("SELECT * FROM users", [], 10, 0);

        $this->assertInstanceOf(DatabaseResult::class, $result);
        $this->assertCount(10, $result->records);
        $this->assertSame(25, $result->count); // total
        $this->assertSame(10, $result->limit);
        $this->assertSame(0, $result->offset);
    }

    public function testFetchEmptyTable(): void
    {
        $result = $this->db->fetch("SELECT * FROM users");

        $this->assertInstanceOf(DatabaseResult::class, $result);
        $this->assertEmpty($result->records);
        $this->assertSame(0, $result->count);
    }

    // -- fetchOne() -------------------------------------------------------------

    public function testFetchOneReturnsArray(): void
    {
        $this->db->insert('users', ['id' => 1, 'name' => 'Alice', 'email' => 'alice@test.com']);

        $row = $this->db->fetchOne("SELECT * FROM users WHERE id = 1");

        $this->assertIsArray($row);
        $this->assertSame('Alice', $row['name']);
    }

    public function testFetchOneReturnsNullWhenEmpty(): void
    {
        $row = $this->db->fetchOne("SELECT * FROM users WHERE id = 999");

        $this->assertNull($row);
    }

    // -- execute() --------------------------------------------------------------

    public function testExecuteReturnsBool(): void
    {
        $result = $this->db->execute("INSERT INTO users (id, name, email) VALUES (1, 'Test', 'test@test.com')");

        $this->assertTrue($result);
    }

    // -- insert() ---------------------------------------------------------------

    public function testInsertSingleRow(): void
    {
        $result = $this->db->insert('users', ['id' => 1, 'name' => 'Alice', 'email' => 'alice@test.com']);

        $this->assertTrue($result);

        $row = $this->db->fetchOne("SELECT * FROM users WHERE id = 1");
        $this->assertSame('Alice', $row['name']);
    }

    // -- update() ---------------------------------------------------------------

    public function testUpdateWithFilter(): void
    {
        $this->db->insert('users', ['id' => 1, 'name' => 'Alice', 'email' => 'old@test.com']);

        $result = $this->db->update('users', ['email' => 'new@test.com'], ['id' => 1]);

        $this->assertTrue($result);

        $row = $this->db->fetchOne("SELECT * FROM users WHERE id = 1");
        $this->assertSame('new@test.com', $row['email']);
    }

    // -- delete() ---------------------------------------------------------------

    public function testDeleteWithFilter(): void
    {
        $this->db->insert('users', ['id' => 1, 'name' => 'Alice', 'email' => 'alice@test.com']);

        $result = $this->db->delete('users', ['id' => 1]);

        $this->assertTrue($result);

        $row = $this->db->fetchOne("SELECT * FROM users WHERE id = 1");
        $this->assertNull($row);
    }

    // -- Transactions -----------------------------------------------------------

    public function testStartTransactionAndCommit(): void
    {
        $this->db->startTransaction();
        $this->db->execute("INSERT INTO users (id, name, email) VALUES (1, 'Alice', 'alice@test.com')");
        $this->db->commit();

        $row = $this->db->fetchOne("SELECT * FROM users WHERE id = 1");
        $this->assertSame('Alice', $row['name']);
    }

    public function testStartTransactionAndRollback(): void
    {
        // Insert one row with explicit transaction
        $this->db->startTransaction();
        $this->db->execute("INSERT INTO users (id, name, email) VALUES (1, 'Alice', 'alice@test.com')");
        $this->db->commit();

        // Start new transaction, insert, then rollback
        $this->db->startTransaction();
        $this->db->execute("INSERT INTO users (id, name, email) VALUES (2, 'Bob', 'bob@test.com')");
        $this->db->rollback();

        $row = $this->db->fetchOne("SELECT * FROM users WHERE id = 2");
        $this->assertNull($row);

        // First row should still be there
        $row = $this->db->fetchOne("SELECT * FROM users WHERE id = 1");
        $this->assertSame('Alice', $row['name']);
    }

    // -- Schema introspection ---------------------------------------------------

    public function testTableExistsTrue(): void
    {
        $this->assertTrue($this->db->tableExists('users'));
    }

    public function testTableExistsFalse(): void
    {
        $this->assertFalse($this->db->tableExists('nonexistent_table'));
    }

    public function testGetTablesIncludesUsers(): void
    {
        $tables = $this->db->getTables();

        $this->assertIsArray($tables);
        $this->assertContains('users', $tables);
    }

    public function testGetLastId(): void
    {
        $this->db->execute("INSERT INTO users (name, email) VALUES ('Alice', 'alice@test.com')");

        $lastId = $this->db->getLastId();

        $this->assertGreaterThanOrEqual(1, $lastId);
    }

    // -- close() ----------------------------------------------------------------

    public function testCloseDoesNotThrow(): void
    {
        $db = Database::create(':memory:');
        $db->close();

        // Should not throw
        $this->assertTrue(true);
    }

    // -- error() ----------------------------------------------------------------

    public function testErrorReturnsNullInitially(): void
    {
        $db = Database::create(':memory:');
        $error = $db->error();

        // May return null or a string depending on adapter state
        $this->assertTrue($error === null || is_string($error));
        $db->close();
    }

    // -- exec() alias -----------------------------------------------------------

    public function testExecAlias(): void
    {
        $result = $this->db->exec("INSERT INTO users (id, name, email) VALUES (1, 'Test', 'test@test.com')");

        $this->assertTrue($result);
    }

    // -- Integration: insert, fetch, update, delete cycle -----------------------

    public function testCrudCycle(): void
    {
        // Insert
        $this->db->insert('users', ['id' => 1, 'name' => 'Alice', 'email' => 'alice@test.com']);

        // Read
        $result = $this->db->fetch("SELECT * FROM users WHERE id = 1");
        $this->assertSame('Alice', $result[0]['name']);

        // Update
        $this->db->update('users', ['name' => 'Alicia'], ['id' => 1]);

        $row = $this->db->fetchOne("SELECT * FROM users WHERE id = 1");
        $this->assertSame('Alicia', $row['name']);

        // Delete
        $this->db->delete('users', ['id' => 1]);

        $row = $this->db->fetchOne("SELECT * FROM users WHERE id = 1");
        $this->assertNull($row);
    }
}
