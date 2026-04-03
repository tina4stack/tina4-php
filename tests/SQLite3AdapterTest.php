<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;

class SQLite3AdapterTest extends TestCase
{
    private SQLite3Adapter $db;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT, age INTEGER)");
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    // --- Connection ---

    public function testOpenInMemory(): void
    {
        $db = new SQLite3Adapter(':memory:');
        $this->assertNotNull($db->getConnection());
        $db->close();
    }

    public function testClose(): void
    {
        $db = new SQLite3Adapter(':memory:');
        $db->close();
        $this->assertNull($db->getConnection());
    }

    public function testGetDatabase(): void
    {
        $this->assertSame(':memory:', $this->db->getDatabase());
    }

    // --- Exec ---

    public function testExecCreateTable(): void
    {
        $result = $this->db->exec("CREATE TABLE test_table (id INTEGER PRIMARY KEY, value TEXT)");
        $this->assertTrue($result);
        $this->assertTrue($this->db->tableExists('test_table'));
    }

    public function testExecWithParams(): void
    {
        $result = $this->db->exec(
            "INSERT INTO users (name, email) VALUES (:name, :email)",
            [':name' => 'Alice', ':email' => 'alice@example.com']
        );
        $this->assertTrue($result);
    }

    public function testExecInvalidSql(): void
    {
        $result = $this->db->exec("INVALID SQL STATEMENT");
        $this->assertFalse($result);
        $this->assertNotNull($this->db->error());
    }

    // --- Query ---

    public function testQuerySimple(): void
    {
        $this->db->exec("INSERT INTO users (name, email) VALUES ('Alice', 'alice@test.com')");
        $this->db->exec("INSERT INTO users (name, email) VALUES ('Bob', 'bob@test.com')");

        $rows = $this->db->query("SELECT * FROM users ORDER BY name");
        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('Bob', $rows[1]['name']);
    }

    public function testQueryWithParams(): void
    {
        $this->db->exec("INSERT INTO users (name, email) VALUES ('Alice', 'alice@test.com')");
        $this->db->exec("INSERT INTO users (name, email) VALUES ('Bob', 'bob@test.com')");

        $rows = $this->db->query("SELECT * FROM users WHERE name = :name", [':name' => 'Alice']);
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
    }

    public function testQueryEmptyResult(): void
    {
        $rows = $this->db->query("SELECT * FROM users WHERE name = 'Nonexistent'");
        $this->assertSame([], $rows);
    }

    // --- Fetch (Pagination) ---

    public function testFetchWithPagination(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->db->exec("INSERT INTO users (name, email) VALUES (:name, :email)", [
                ':name' => "User{$i}",
                ':email' => "user{$i}@test.com",
            ]);
        }

        $result = $this->db->fetch("SELECT * FROM users ORDER BY id", 10, 0);

        $this->assertCount(10, $result['data']);
        $this->assertSame(25, $result['total']);
        $this->assertSame(10, $result['limit']);
        $this->assertSame(0, $result['offset']);
    }

    public function testFetchSecondPage(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->db->exec("INSERT INTO users (name) VALUES (:name)", [':name' => "User{$i}"]);
        }

        $result = $this->db->fetch("SELECT * FROM users ORDER BY id", 10, 10);

        $this->assertCount(10, $result['data']);
        $this->assertSame(25, $result['total']);
        $this->assertSame(10, $result['offset']);
    }

    public function testFetchLastPage(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->db->exec("INSERT INTO users (name) VALUES (:name)", [':name' => "User{$i}"]);
        }

        $result = $this->db->fetch("SELECT * FROM users ORDER BY id", 10, 20);

        $this->assertCount(5, $result['data']);
    }

    // --- Table Operations ---

    public function testTableExists(): void
    {
        $this->assertTrue($this->db->tableExists('users'));
        $this->assertFalse($this->db->tableExists('nonexistent_table'));
    }

    public function testGetColumns(): void
    {
        $columns = $this->db->getColumns('users');

        $this->assertCount(4, $columns);

        $names = array_column($columns, 'name');
        $this->assertContains('id', $names);
        $this->assertContains('name', $names);
        $this->assertContains('email', $names);
        $this->assertContains('age', $names);

        // id is primary key
        $idCol = array_filter($columns, fn($c) => $c['name'] === 'id');
        $idCol = array_values($idCol)[0];
        $this->assertTrue($idCol['primary']);
    }

    // --- Last Insert ID ---

    public function testLastInsertId(): void
    {
        $this->db->exec("INSERT INTO users (name) VALUES ('Alice')");
        $id1 = $this->db->lastInsertId();

        $this->db->exec("INSERT INTO users (name) VALUES ('Bob')");
        $id2 = $this->db->lastInsertId();

        $this->assertGreaterThan(0, $id1);
        $this->assertGreaterThan($id1, $id2);
    }

    // --- Transactions ---

    public function testTransactionCommit(): void
    {
        $this->db->begin();
        $this->db->exec("INSERT INTO users (name) VALUES ('Alice')");
        $this->db->exec("INSERT INTO users (name) VALUES ('Bob')");
        $this->db->commit();

        $rows = $this->db->query("SELECT * FROM users");
        $this->assertCount(2, $rows);
    }

    public function testTransactionRollback(): void
    {
        $this->db->exec("INSERT INTO users (name) VALUES ('Existing')");

        $this->db->begin();
        $this->db->exec("INSERT INTO users (name) VALUES ('Temp1')");
        $this->db->exec("INSERT INTO users (name) VALUES ('Temp2')");
        $this->db->rollback();

        $rows = $this->db->query("SELECT * FROM users");
        $this->assertCount(1, $rows);
        $this->assertSame('Existing', $rows[0]['name']);
    }

    // --- Error Handling ---

    public function testError(): void
    {
        $this->assertNull($this->db->error());

        $this->db->exec("INVALID SQL");
        $this->assertNotNull($this->db->error());
    }

    // --- Parameter Types ---

    public function testIntegerBinding(): void
    {
        $this->db->exec("INSERT INTO users (name, age) VALUES (:name, :age)", [
            ':name' => 'Alice',
            ':age' => 30,
        ]);

        $rows = $this->db->query("SELECT age FROM users WHERE name = 'Alice'");
        $this->assertSame(30, $rows[0]['age']);
    }

    public function testNullBinding(): void
    {
        $this->db->exec("INSERT INTO users (name, email) VALUES (:name, :email)", [
            ':name' => 'Alice',
            ':email' => null,
        ]);

        $rows = $this->db->query("SELECT email FROM users WHERE name = 'Alice'");
        $this->assertNull($rows[0]['email']);
    }

    // --- File-based Database ---

    public function testFileDatabaseCreateAndClose(): void
    {
        $path = sys_get_temp_dir() . '/tina4_sqlite_test_' . uniqid() . '.db';

        $db = new SQLite3Adapter($path);
        $db->exec("CREATE TABLE test (id INTEGER PRIMARY KEY, value TEXT)");
        $db->exec("INSERT INTO test (value) VALUES ('hello')");
        $db->close();

        // Reopen and verify data persists
        $db2 = new SQLite3Adapter($path);
        $rows = $db2->query("SELECT * FROM test");
        $this->assertCount(1, $rows);
        $this->assertSame('hello', $rows[0]['value']);
        $db2->close();

        unlink($path);
    }
}
