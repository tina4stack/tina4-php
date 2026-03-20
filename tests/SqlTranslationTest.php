<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\SqlTranslation;

class SqlTranslationTest extends TestCase
{
    protected function setUp(): void
    {
        SqlTranslation::cacheClear();
        SqlTranslation::clearFunctions();
    }

    // -- limitToRows ---------------------------------------------------------

    public function testLimitToRowsWithLimitAndOffset(): void
    {
        $sql = 'SELECT * FROM users LIMIT 10 OFFSET 5';
        $result = SqlTranslation::limitToRows($sql);
        $this->assertEquals('SELECT * FROM users ROWS 6 TO 15', $result);
    }

    public function testLimitToRowsWithLimitOnly(): void
    {
        $sql = 'SELECT * FROM users LIMIT 10';
        $result = SqlTranslation::limitToRows($sql);
        $this->assertEquals('SELECT * FROM users ROWS 1 TO 10', $result);
    }

    public function testLimitToRowsNoChange(): void
    {
        $sql = 'SELECT * FROM users';
        $this->assertEquals($sql, SqlTranslation::limitToRows($sql));
    }

    public function testLimitToRowsZeroOffset(): void
    {
        $sql = 'SELECT * FROM users LIMIT 5 OFFSET 0';
        $result = SqlTranslation::limitToRows($sql);
        $this->assertEquals('SELECT * FROM users ROWS 1 TO 5', $result);
    }

    // -- limitToTop ----------------------------------------------------------

    public function testLimitToTop(): void
    {
        $sql = 'SELECT * FROM users LIMIT 10';
        $result = SqlTranslation::limitToTop($sql);
        $this->assertEquals('SELECT TOP 10 * FROM users', $result);
    }

    public function testLimitToTopIgnoresOffset(): void
    {
        $sql = 'SELECT * FROM users LIMIT 10 OFFSET 5';
        $this->assertEquals($sql, SqlTranslation::limitToTop($sql));
    }

    public function testLimitToTopNoChange(): void
    {
        $sql = 'SELECT * FROM users';
        $this->assertEquals($sql, SqlTranslation::limitToTop($sql));
    }

    // -- booleanToInt --------------------------------------------------------

    public function testBooleanToIntTrue(): void
    {
        $sql = "SELECT * FROM users WHERE active = TRUE";
        $result = SqlTranslation::booleanToInt($sql);
        $this->assertEquals('SELECT * FROM users WHERE active = 1', $result);
    }

    public function testBooleanToIntFalse(): void
    {
        $sql = "UPDATE users SET active = FALSE WHERE id = 1";
        $result = SqlTranslation::booleanToInt($sql);
        $this->assertEquals('UPDATE users SET active = 0 WHERE id = 1', $result);
    }

    public function testBooleanToIntBoth(): void
    {
        $sql = "SELECT TRUE, FALSE";
        $result = SqlTranslation::booleanToInt($sql);
        $this->assertEquals('SELECT 1, 0', $result);
    }

    public function testBooleanToIntCaseInsensitive(): void
    {
        $sql = "SELECT * FROM t WHERE a = true AND b = false";
        $result = SqlTranslation::booleanToInt($sql);
        $this->assertEquals('SELECT * FROM t WHERE a = 1 AND b = 0', $result);
    }

    // -- ilikeToLike ---------------------------------------------------------

    public function testIlikeToLike(): void
    {
        $sql = "SELECT * FROM users WHERE name ILIKE '%john%'";
        $result = SqlTranslation::ilikeToLike($sql);
        $this->assertEquals("SELECT * FROM users WHERE LOWER(name) LIKE LOWER('%john%')", $result);
    }

    public function testIlikeToLikeCaseInsensitive(): void
    {
        $sql = "SELECT * FROM t WHERE col ilike 'val'";
        $result = SqlTranslation::ilikeToLike($sql);
        $this->assertStringContainsString('LOWER(col) LIKE LOWER(', $result);
    }

    // -- concatPipesToFunc ---------------------------------------------------

    public function testConcatPipesToFunc(): void
    {
        $sql = "'a' || 'b' || 'c'";
        $result = SqlTranslation::concatPipesToFunc($sql);
        $this->assertEquals("CONCAT('a', 'b', 'c')", $result);
    }

    public function testConcatPipesNoChange(): void
    {
        $sql = "SELECT * FROM users";
        $this->assertEquals($sql, SqlTranslation::concatPipesToFunc($sql));
    }

    public function testConcatPipesTwoParts(): void
    {
        $sql = "first_name || last_name";
        $result = SqlTranslation::concatPipesToFunc($sql);
        $this->assertEquals("CONCAT(first_name, last_name)", $result);
    }

    // -- placeholderStyle ----------------------------------------------------

    public function testPlaceholderStyleColon(): void
    {
        $sql = "SELECT * FROM users WHERE id = ? AND name = ?";
        $result = SqlTranslation::placeholderStyle($sql, ':');
        $this->assertEquals("SELECT * FROM users WHERE id = :1 AND name = :2", $result);
    }

    public function testPlaceholderStyleSprintf(): void
    {
        $sql = "SELECT * FROM users WHERE id = ? AND name = ?";
        $result = SqlTranslation::placeholderStyle($sql, '%s');
        $this->assertEquals("SELECT * FROM users WHERE id = %s AND name = %s", $result);
    }

    public function testPlaceholderStyleUnknown(): void
    {
        $sql = "SELECT * FROM users WHERE id = ?";
        $this->assertEquals($sql, SqlTranslation::placeholderStyle($sql, 'unknown'));
    }

    // -- autoIncrementSyntax -------------------------------------------------

    public function testAutoIncrementMySQL(): void
    {
        $sql = "CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT)";
        $result = SqlTranslation::autoIncrementSyntax($sql, 'mysql');
        $this->assertStringContainsString('AUTO_INCREMENT', $result);
    }

    public function testAutoIncrementMSSQL(): void
    {
        $sql = "CREATE TABLE t (id INTEGER AUTOINCREMENT)";
        $result = SqlTranslation::autoIncrementSyntax($sql, 'mssql');
        $this->assertStringContainsString('IDENTITY(1,1)', $result);
    }

    public function testAutoIncrementPostgreSQL(): void
    {
        $sql = "CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT)";
        $result = SqlTranslation::autoIncrementSyntax($sql, 'postgresql');
        $this->assertStringContainsString('SERIAL PRIMARY KEY', $result);
    }

    public function testAutoIncrementFirebird(): void
    {
        $sql = "CREATE TABLE t (id INTEGER AUTOINCREMENT)";
        $result = SqlTranslation::autoIncrementSyntax($sql, 'firebird');
        $this->assertStringNotContainsString('AUTOINCREMENT', $result);
    }

    // -- hasReturning / extractReturning -------------------------------------

    public function testHasReturning(): void
    {
        $this->assertTrue(SqlTranslation::hasReturning('INSERT INTO t (a) VALUES (1) RETURNING id'));
        $this->assertFalse(SqlTranslation::hasReturning('INSERT INTO t (a) VALUES (1)'));
    }

    public function testExtractReturning(): void
    {
        $result = SqlTranslation::extractReturning('INSERT INTO t (a) VALUES (1) RETURNING id, name');
        $this->assertEquals('INSERT INTO t (a) VALUES (1)', $result['sql']);
        $this->assertEquals(['id', 'name'], $result['columns']);
    }

    public function testExtractReturningNoClause(): void
    {
        $sql = 'INSERT INTO t (a) VALUES (1)';
        $result = SqlTranslation::extractReturning($sql);
        $this->assertEquals($sql, $result['sql']);
        $this->assertEmpty($result['columns']);
    }

    // -- registerFunction / applyFunctionMappings ----------------------------

    public function testCustomFunctionMapping(): void
    {
        SqlTranslation::registerFunction('NOW', function (string $sql): string {
            return str_ireplace('NOW()', 'CURRENT_TIMESTAMP', $sql);
        });

        $sql = 'SELECT NOW() FROM dual';
        $result = SqlTranslation::applyFunctionMappings($sql);
        $this->assertEquals('SELECT CURRENT_TIMESTAMP FROM dual', $result);
    }

    public function testClearFunctions(): void
    {
        SqlTranslation::registerFunction('NOW', fn($s) => $s);
        SqlTranslation::clearFunctions();
        $sql = 'SELECT NOW()';
        $this->assertEquals($sql, SqlTranslation::applyFunctionMappings($sql));
    }

    // -- translate (full dialect) --------------------------------------------

    public function testTranslateFirebird(): void
    {
        $sql = 'SELECT * FROM users WHERE active = TRUE LIMIT 10 OFFSET 5';
        $result = SqlTranslation::translate($sql, 'firebird');
        $this->assertStringContainsString('ROWS 6 TO 15', $result);
        $this->assertStringNotContainsString('TRUE', $result);
    }

    public function testTranslateMSSQL(): void
    {
        $sql = 'SELECT * FROM users LIMIT 10';
        $result = SqlTranslation::translate($sql, 'mssql');
        $this->assertStringContainsString('TOP 10', $result);
    }

    public function testTranslateMySQL(): void
    {
        $sql = 'CREATE TABLE t (id INTEGER AUTOINCREMENT)';
        $result = SqlTranslation::translate($sql, 'mysql');
        $this->assertStringContainsString('AUTO_INCREMENT', $result);
    }

    public function testTranslatePostgreSQL(): void
    {
        $sql = 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT)';
        $result = SqlTranslation::translate($sql, 'postgresql');
        $this->assertStringContainsString('SERIAL PRIMARY KEY', $result);
    }

    public function testTranslateSQLite(): void
    {
        $sql = 'SELECT * FROM users LIMIT 10';
        $result = SqlTranslation::translate($sql, 'sqlite');
        $this->assertEquals($sql, $result);
    }

    public function testTranslateAppliesCustomFunctions(): void
    {
        SqlTranslation::registerFunction('NOW', function (string $sql): string {
            return str_ireplace('NOW()', 'CURRENT_TIMESTAMP', $sql);
        });
        $sql = 'SELECT NOW() FROM dual';
        $result = SqlTranslation::translate($sql, 'sqlite');
        $this->assertStringContainsString('CURRENT_TIMESTAMP', $result);
    }

    // -- Query cache ---------------------------------------------------------

    public function testCacheSetAndGet(): void
    {
        SqlTranslation::cacheSet('test-key', ['row1', 'row2'], 60);
        $result = SqlTranslation::cacheGet('test-key');
        $this->assertEquals(['row1', 'row2'], $result);
    }

    public function testCacheGetMiss(): void
    {
        $this->assertNull(SqlTranslation::cacheGet('nonexistent'));
    }

    public function testCacheExpiry(): void
    {
        // Set with very short TTL
        SqlTranslation::cacheSet('expire-key', 'value', 1);
        $this->assertEquals('value', SqlTranslation::cacheGet('expire-key'));

        // Wait for expiry
        usleep(1100000); // 1.1 seconds
        $this->assertNull(SqlTranslation::cacheGet('expire-key'));
    }

    public function testRememberReturnsFactory(): void
    {
        $callCount = 0;
        $result = SqlTranslation::remember('rem-key', 60, function () use (&$callCount) {
            $callCount++;
            return 'computed';
        });
        $this->assertEquals('computed', $result);
        $this->assertEquals(1, $callCount);

        // Second call should return cached value
        $result2 = SqlTranslation::remember('rem-key', 60, function () use (&$callCount) {
            $callCount++;
            return 'recomputed';
        });
        $this->assertEquals('computed', $result2);
        $this->assertEquals(1, $callCount);
    }

    public function testCacheSweep(): void
    {
        SqlTranslation::cacheSet('a', 1, 1);
        SqlTranslation::cacheSet('b', 2, 1);
        SqlTranslation::cacheSet('c', 3, 300);

        usleep(1100000); // 1.1 seconds

        $removed = SqlTranslation::cacheSweep();
        $this->assertEquals(2, $removed);
        $this->assertEquals(1, SqlTranslation::cacheSize());
        $this->assertEquals(3, SqlTranslation::cacheGet('c'));
    }

    public function testCacheClear(): void
    {
        SqlTranslation::cacheSet('x', 1, 60);
        SqlTranslation::cacheSet('y', 2, 60);
        SqlTranslation::cacheClear();
        $this->assertEquals(0, SqlTranslation::cacheSize());
    }

    public function testQueryKey(): void
    {
        $key1 = SqlTranslation::queryKey('SELECT * FROM t', [1]);
        $key2 = SqlTranslation::queryKey('SELECT * FROM t', [2]);
        $key3 = SqlTranslation::queryKey('SELECT * FROM t', [1]);

        $this->assertNotEquals($key1, $key2);
        $this->assertEquals($key1, $key3);
        $this->assertStringStartsWith('query:', $key1);
    }
}
