<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\SQLTranslator;

class SQLTranslatorTest extends TestCase
{
    protected function setUp(): void
    {
        SQLTranslator::cacheClear();
        SQLTranslator::clearFunctions();
    }

    // -- limitToRows ---------------------------------------------------------

    public function testLimitToRowsWithLimitAndOffset(): void
    {
        $sql = 'SELECT * FROM users LIMIT 10 OFFSET 5';
        $result = SQLTranslator::limitToRows($sql);
        $this->assertEquals('SELECT * FROM users ROWS 6 TO 15', $result);
    }

    public function testLimitToRowsWithLimitOnly(): void
    {
        $sql = 'SELECT * FROM users LIMIT 10';
        $result = SQLTranslator::limitToRows($sql);
        $this->assertEquals('SELECT * FROM users ROWS 1 TO 10', $result);
    }

    public function testLimitToRowsNoChange(): void
    {
        $sql = 'SELECT * FROM users';
        $this->assertEquals($sql, SQLTranslator::limitToRows($sql));
    }

    public function testLimitToRowsZeroOffset(): void
    {
        $sql = 'SELECT * FROM users LIMIT 5 OFFSET 0';
        $result = SQLTranslator::limitToRows($sql);
        $this->assertEquals('SELECT * FROM users ROWS 1 TO 5', $result);
    }

    // -- limitToTop ----------------------------------------------------------

    public function testLimitToTop(): void
    {
        $sql = 'SELECT * FROM users LIMIT 10';
        $result = SQLTranslator::limitToTop($sql);
        $this->assertEquals('SELECT TOP 10 * FROM users', $result);
    }

    public function testLimitToTopIgnoresOffset(): void
    {
        $sql = 'SELECT * FROM users LIMIT 10 OFFSET 5';
        $this->assertEquals($sql, SQLTranslator::limitToTop($sql));
    }

    public function testLimitToTopNoChange(): void
    {
        $sql = 'SELECT * FROM users';
        $this->assertEquals($sql, SQLTranslator::limitToTop($sql));
    }

    // -- booleanToInt --------------------------------------------------------

    public function testBooleanToIntTrue(): void
    {
        $sql = "SELECT * FROM users WHERE active = TRUE";
        $result = SQLTranslator::booleanToInt($sql);
        $this->assertEquals('SELECT * FROM users WHERE active = 1', $result);
    }

    public function testBooleanToIntFalse(): void
    {
        $sql = "UPDATE users SET active = FALSE WHERE id = 1";
        $result = SQLTranslator::booleanToInt($sql);
        $this->assertEquals('UPDATE users SET active = 0 WHERE id = 1', $result);
    }

    public function testBooleanToIntBoth(): void
    {
        $sql = "SELECT TRUE, FALSE";
        $result = SQLTranslator::booleanToInt($sql);
        $this->assertEquals('SELECT 1, 0', $result);
    }

    public function testBooleanToIntCaseInsensitive(): void
    {
        $sql = "SELECT * FROM t WHERE a = true AND b = false";
        $result = SQLTranslator::booleanToInt($sql);
        $this->assertEquals('SELECT * FROM t WHERE a = 1 AND b = 0', $result);
    }

    // -- ilikeToLike ---------------------------------------------------------

    public function testIlikeToLike(): void
    {
        $sql = "SELECT * FROM users WHERE name ILIKE '%john%'";
        $result = SQLTranslator::ilikeToLike($sql);
        $this->assertEquals("SELECT * FROM users WHERE LOWER(name) LIKE LOWER('%john%')", $result);
    }

    public function testIlikeToLikeCaseInsensitive(): void
    {
        $sql = "SELECT * FROM t WHERE col ilike 'val'";
        $result = SQLTranslator::ilikeToLike($sql);
        $this->assertStringContainsString('LOWER(col) LIKE LOWER(', $result);
    }

    // -- concatPipesToFunc ---------------------------------------------------

    public function testConcatPipesToFunc(): void
    {
        $sql = "'a' || 'b' || 'c'";
        $result = SQLTranslator::concatPipesToFunc($sql);
        $this->assertEquals("CONCAT('a', 'b', 'c')", $result);
    }

    public function testConcatPipesNoChange(): void
    {
        $sql = "SELECT * FROM users";
        $this->assertEquals($sql, SQLTranslator::concatPipesToFunc($sql));
    }

    public function testConcatPipesTwoParts(): void
    {
        $sql = "first_name || last_name";
        $result = SQLTranslator::concatPipesToFunc($sql);
        $this->assertEquals("CONCAT(first_name, last_name)", $result);
    }

    // -- placeholderStyle ----------------------------------------------------

    public function testPlaceholderStyleColon(): void
    {
        $sql = "SELECT * FROM users WHERE id = ? AND name = ?";
        $result = SQLTranslator::placeholderStyle($sql, ':');
        $this->assertEquals("SELECT * FROM users WHERE id = :1 AND name = :2", $result);
    }

    public function testPlaceholderStyleSprintf(): void
    {
        $sql = "SELECT * FROM users WHERE id = ? AND name = ?";
        $result = SQLTranslator::placeholderStyle($sql, '%s');
        $this->assertEquals("SELECT * FROM users WHERE id = %s AND name = %s", $result);
    }

    public function testPlaceholderStyleUnknown(): void
    {
        $sql = "SELECT * FROM users WHERE id = ?";
        $this->assertEquals($sql, SQLTranslator::placeholderStyle($sql, 'unknown'));
    }

    // -- autoIncrementSyntax -------------------------------------------------

    public function testAutoIncrementMySQL(): void
    {
        $sql = "CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT)";
        $result = SQLTranslator::autoIncrementSyntax($sql, 'mysql');
        $this->assertStringContainsString('AUTO_INCREMENT', $result);
    }

    public function testAutoIncrementMSSQL(): void
    {
        $sql = "CREATE TABLE t (id INTEGER AUTOINCREMENT)";
        $result = SQLTranslator::autoIncrementSyntax($sql, 'mssql');
        $this->assertStringContainsString('IDENTITY(1,1)', $result);
    }

    public function testAutoIncrementPostgreSQL(): void
    {
        $sql = "CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT)";
        $result = SQLTranslator::autoIncrementSyntax($sql, 'postgresql');
        $this->assertStringContainsString('SERIAL PRIMARY KEY', $result);
    }

    public function testAutoIncrementFirebird(): void
    {
        $sql = "CREATE TABLE t (id INTEGER AUTOINCREMENT)";
        $result = SQLTranslator::autoIncrementSyntax($sql, 'firebird');
        $this->assertStringNotContainsString('AUTOINCREMENT', $result);
    }

    // -- hasReturning / extractReturning -------------------------------------

    public function testHasReturning(): void
    {
        $this->assertTrue(SQLTranslator::hasReturning('INSERT INTO t (a) VALUES (1) RETURNING id'));
        $this->assertFalse(SQLTranslator::hasReturning('INSERT INTO t (a) VALUES (1)'));
    }

    public function testExtractReturning(): void
    {
        $result = SQLTranslator::extractReturning('INSERT INTO t (a) VALUES (1) RETURNING id, name');
        $this->assertEquals('INSERT INTO t (a) VALUES (1)', $result['sql']);
        $this->assertEquals(['id', 'name'], $result['columns']);
    }

    public function testExtractReturningNoClause(): void
    {
        $sql = 'INSERT INTO t (a) VALUES (1)';
        $result = SQLTranslator::extractReturning($sql);
        $this->assertEquals($sql, $result['sql']);
        $this->assertEmpty($result['columns']);
    }

    // -- registerFunction / applyFunctionMappings ----------------------------

    public function testCustomFunctionMapping(): void
    {
        SQLTranslator::registerFunction('NOW', function (string $sql): string {
            return str_ireplace('NOW()', 'CURRENT_TIMESTAMP', $sql);
        });

        $sql = 'SELECT NOW() FROM dual';
        $result = SQLTranslator::applyFunctionMappings($sql);
        $this->assertEquals('SELECT CURRENT_TIMESTAMP FROM dual', $result);
    }

    public function testClearFunctions(): void
    {
        SQLTranslator::registerFunction('NOW', fn($s) => $s);
        SQLTranslator::clearFunctions();
        $sql = 'SELECT NOW()';
        $this->assertEquals($sql, SQLTranslator::applyFunctionMappings($sql));
    }

    // -- translate (full dialect) --------------------------------------------

    public function testTranslateFirebird(): void
    {
        $sql = 'SELECT * FROM users WHERE active = TRUE LIMIT 10 OFFSET 5';
        $result = SQLTranslator::translate($sql, 'firebird');
        $this->assertStringContainsString('ROWS 6 TO 15', $result);
        $this->assertStringNotContainsString('TRUE', $result);
    }

    public function testTranslateMSSQL(): void
    {
        $sql = 'SELECT * FROM users LIMIT 10';
        $result = SQLTranslator::translate($sql, 'mssql');
        $this->assertStringContainsString('TOP 10', $result);
    }

    public function testTranslateMySQL(): void
    {
        $sql = 'CREATE TABLE t (id INTEGER AUTOINCREMENT)';
        $result = SQLTranslator::translate($sql, 'mysql');
        $this->assertStringContainsString('AUTO_INCREMENT', $result);
    }

    public function testTranslatePostgreSQL(): void
    {
        $sql = 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT)';
        $result = SQLTranslator::translate($sql, 'postgresql');
        $this->assertStringContainsString('SERIAL PRIMARY KEY', $result);
    }

    public function testTranslateSQLite(): void
    {
        $sql = 'SELECT * FROM users LIMIT 10';
        $result = SQLTranslator::translate($sql, 'sqlite');
        $this->assertEquals($sql, $result);
    }

    public function testTranslateAppliesCustomFunctions(): void
    {
        SQLTranslator::registerFunction('NOW', function (string $sql): string {
            return str_ireplace('NOW()', 'CURRENT_TIMESTAMP', $sql);
        });
        $sql = 'SELECT NOW() FROM dual';
        $result = SQLTranslator::translate($sql, 'sqlite');
        $this->assertStringContainsString('CURRENT_TIMESTAMP', $result);
    }

    // -- Query cache ---------------------------------------------------------

    public function testCacheSetAndGet(): void
    {
        SQLTranslator::cacheSet('test-key', ['row1', 'row2'], 60);
        $result = SQLTranslator::cacheGet('test-key');
        $this->assertEquals(['row1', 'row2'], $result);
    }

    public function testCacheGetMiss(): void
    {
        $this->assertNull(SQLTranslator::cacheGet('nonexistent'));
    }

    public function testCacheExpiry(): void
    {
        // Set with very short TTL
        SQLTranslator::cacheSet('expire-key', 'value', 1);
        $this->assertEquals('value', SQLTranslator::cacheGet('expire-key'));

        // Wait for expiry
        usleep(1100000); // 1.1 seconds
        $this->assertNull(SQLTranslator::cacheGet('expire-key'));
    }

    public function testRememberReturnsFactory(): void
    {
        $callCount = 0;
        $result = SQLTranslator::remember('rem-key', 60, function () use (&$callCount) {
            $callCount++;
            return 'computed';
        });
        $this->assertEquals('computed', $result);
        $this->assertEquals(1, $callCount);

        // Second call should return cached value
        $result2 = SQLTranslator::remember('rem-key', 60, function () use (&$callCount) {
            $callCount++;
            return 'recomputed';
        });
        $this->assertEquals('computed', $result2);
        $this->assertEquals(1, $callCount);
    }

    public function testCacheSweep(): void
    {
        SQLTranslator::cacheSet('a', 1, 1);
        SQLTranslator::cacheSet('b', 2, 1);
        SQLTranslator::cacheSet('c', 3, 300);

        usleep(1100000); // 1.1 seconds

        $removed = SQLTranslator::cacheSweep();
        $this->assertEquals(2, $removed);
        $this->assertEquals(1, SQLTranslator::cacheSize());
        $this->assertEquals(3, SQLTranslator::cacheGet('c'));
    }

    public function testCacheClear(): void
    {
        SQLTranslator::cacheSet('x', 1, 60);
        SQLTranslator::cacheSet('y', 2, 60);
        SQLTranslator::cacheClear();
        $this->assertEquals(0, SQLTranslator::cacheSize());
    }

    public function testQueryKey(): void
    {
        $key1 = SQLTranslator::queryKey('SELECT * FROM t', [1]);
        $key2 = SQLTranslator::queryKey('SELECT * FROM t', [2]);
        $key3 = SQLTranslator::queryKey('SELECT * FROM t', [1]);

        $this->assertNotEquals($key1, $key2);
        $this->assertEquals($key1, $key3);
        $this->assertStringStartsWith('query:', $key1);
    }

    // -- class-naming contract (cross-framework parity lock-in) --------------
    //
    // The dialect-translation class is named SQLTranslator in the Python
    // master (tina4_python/database/adapter.py), Ruby (lib/tina4/
    // sql_translation.rb) and Node (packages/orm/src/sqlTranslation.ts). PHP
    // was the lone outlier as `SqlTranslation`, drifting on both the acronym
    // casing and the noun. These tests pin the converged name so the drift
    // cannot silently return, and pin the deliberate absence of a
    // backwards-compatibility alias.

    /**
     * POSITIVE: the class resolves through the REAL composer PSR-4 autoloader
     * under its parity name, and PSR-4 requires the file basename to match.
     */
    public function testClassIsAutoloadableUnderTheParityName(): void
    {
        $this->assertTrue(
            class_exists(SQLTranslator::class),
            'Tina4\SQLTranslator must resolve through composer PSR-4 autoloading'
        );
        $this->assertSame('Tina4\SQLTranslator', SQLTranslator::class);

        $resolvedFile = (new \ReflectionClass(SQLTranslator::class))->getFileName();
        $this->assertSame(
            'SQLTranslator.php',
            basename($resolvedFile),
            'PSR-4 requires the file name to match the class name'
        );
    }

    /**
     * NEGATIVE: the pre-rename name is GONE. No class_alias(), no deprecated
     * subclass. This assertion fails against the old code, where
     * Tina4\SqlTranslation was the declared class.
     */
    public function testPreRenameClassNameNoLongerExists(): void
    {
        $this->assertFalse(
            class_exists('Tina4\SqlTranslation'),
            'The pre-rename name must NOT exist - this rename ships without a backwards-compatibility alias'
        );
        $this->assertFileDoesNotExist(__DIR__ . '/../Tina4/SqlTranslation.php');
    }

    /**
     * A representative translate() call per dialect still behaves identically
     * after the rename - the rename is identifier-only, never behavioural.
     */
    public function testRepresentativeTranslateStillWorksUnderTheNewName(): void
    {
        $this->assertSame(
            'SELECT * FROM t WHERE active = 1 ROWS 6 TO 15',
            SQLTranslator::translate('SELECT * FROM t WHERE active = TRUE LIMIT 10 OFFSET 5', 'firebird')
        );
        $this->assertSame(
            'SELECT TOP 10 * FROM t',
            SQLTranslator::translate('SELECT * FROM t LIMIT 10', 'mssql')
        );
        $this->assertSame(
            'SELECT * FROM t LIMIT 10 OFFSET 5',
            SQLTranslator::translate('SELECT * FROM t LIMIT 10 OFFSET 5', 'postgresql')
        );
    }
}
