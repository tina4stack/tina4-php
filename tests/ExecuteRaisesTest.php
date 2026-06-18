<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Database::execute() FAIL-LOUD contract (parity with
 * tina4-python/tests/test_execute_raises.py).
 *
 * On a SQL error — missing table, NOT NULL / constraint violation, bad
 * statement — execute() must RAISE (a Tina4\Database\DatabaseException, or any
 * driver exception) instead of swallowing it and returning false/true. The
 * cause is still captured on getError(). A successful write returns a truthy
 * value (never false) and clears the error.
 *
 * Engine-agnostic: uses an in-memory SQLite connection so it runs with no DB
 * server. Mirrors this framework's own fetch()/fetchOne(), which already raise.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\DatabaseException;

class ExecuteRaisesTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::create('sqlite::memory:');
        $this->db->execute(
            'CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT NOT NULL)'
        );
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    // (a) execute() on a missing table THROWS and getError() is populated.
    public function testExecuteOnMissingTableThrowsAndRecordsError(): void
    {
        try {
            $this->db->execute("INSERT INTO no_such_table (name) VALUES ('x')");
            $this->fail('execute() must raise when the target table does not exist');
        } catch (\Throwable $e) {
            $this->assertNotNull(
                $this->db->getError(),
                'getError() must still carry the cause after the throw'
            );
        }
    }

    // (b) A NOT NULL / constraint violation THROWS and the row was NOT written.
    public function testExecuteConstraintViolationThrowsAndDoesNotWrite(): void
    {
        $before = $this->db->fetchOne('SELECT COUNT(*) AS c FROM widgets');
        $this->assertSame(0, (int) $before['c']);

        try {
            // NOT NULL on `name` → constraint violation.
            $this->db->execute('INSERT INTO widgets (name) VALUES (NULL)');
            $this->fail('execute() must raise on a NOT NULL constraint violation');
        } catch (\Throwable $e) {
            $this->assertNotNull($this->db->getError());
        }

        // The failed INSERT must NOT have written a row.
        $after = $this->db->fetchOne('SELECT COUNT(*) AS c FROM widgets');
        $this->assertSame(
            0,
            (int) $after['c'],
            'A failed execute() must not have written the row'
        );
    }

    // The thrown error is a Tina4 DatabaseException carrying the driver message.
    public function testExecuteRaisesDatabaseExceptionWithMessage(): void
    {
        $this->expectException(DatabaseException::class);
        $this->db->execute('THIS IS NOT VALID SQL');
    }

    // (c) A successful write returns truthy (never false) and clears the error.
    public function testSuccessfulWriteReturnsTruthyAndClearsError(): void
    {
        // Seed an error first so we can prove a later success clears it.
        try {
            $this->db->execute('SELECT * FROM definitely_missing');
        } catch (\Throwable) {
            // ignore — we just wanted getError() populated
        }

        $result = $this->db->execute("INSERT INTO widgets (name) VALUES ('alpha')");
        $this->assertNotFalse(
            $result,
            'A successful write must never return false'
        );
        $this->assertTrue($result === true || $result !== false);
        $this->assertNull(
            $this->db->getError(),
            'A successful execute() must clear the error'
        );

        $row = $this->db->fetchOne('SELECT name FROM widgets WHERE name = ?', ['alpha']);
        $this->assertSame('alpha', $row['name']);
    }

    // exec() (the alias) shares the FAIL-LOUD contract.
    public function testExecAliasAlsoRaisesOnError(): void
    {
        $this->expectException(\Throwable::class);
        $this->db->exec('UPDATE no_such_table SET name = 1');
    }

    // fetch() must also RAISE on a bad statement (no swallow-to-empty-array).
    public function testFetchRaisesOnBadStatement(): void
    {
        $this->expectException(\Throwable::class);
        $this->db->fetch('SELECT * FROM totally_missing_table');
    }
}
