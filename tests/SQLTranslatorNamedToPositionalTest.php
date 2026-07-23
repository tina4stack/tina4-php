<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Unit tests for SQLTranslator::namedToPositional().
 *
 * Why this exists: ORM save() and QueryBuilder emit :named placeholders
 * because that is what PDO would accept. Four of the five v3 database
 * adapters do not use PDO (mysqli, pgsql, sqlsrv, ibase/fbird) and their
 * underlying drivers only speak positional placeholders. This helper does
 * the translation. The bug it closes affected every INSERT/UPDATE via
 * save() on MariaDB; same trap was latent in MSSQL, Firebird, and pg.
 *
 * Adapter integration tests live in the per-adapter test files and skip
 * when no driver/database is reachable. These cases are pure unit tests
 * and always run.
 */

use PHPUnit\Framework\TestCase;
use Tina4\SQLTranslator;

class SQLTranslatorNamedToPositionalTest extends TestCase
{
    // ── 1. Plain translation ─────────────────────────────────────────

    public function testSimpleNamedPlaceholderBecomesQuestionMark(): void
    {
        [$sql, $params] = SQLTranslator::namedToPositional(
            'INSERT INTO users (name) VALUES (:name)',
            [':name' => 'Alice']
        );
        $this->assertSame('INSERT INTO users (name) VALUES (?)', $sql);
        $this->assertSame(['Alice'], $params);
    }

    public function testMultipleDistinctNamesPreserveOrder(): void
    {
        [$sql, $params] = SQLTranslator::namedToPositional(
            'INSERT INTO u (name, email, age) VALUES (:name, :email, :age)',
            [':age' => 30, ':name' => 'Alice', ':email' => 'a@b.test']
        );
        $this->assertSame('INSERT INTO u (name, email, age) VALUES (?, ?, ?)', $sql);
        // Order must match the SQL, not the input array
        $this->assertSame(['Alice', 'a@b.test', 30], $params);
    }

    // ── 2. Duplicate names bind once per occurrence ─────────────────

    public function testDuplicatePlaceholderBindsOncePerOccurrence(): void
    {
        [$sql, $params] = SQLTranslator::namedToPositional(
            'SELECT * FROM t WHERE id = :id OR parent_id = :id',
            [':id' => 7]
        );
        $this->assertSame('SELECT * FROM t WHERE id = ? OR parent_id = ?', $sql);
        $this->assertSame([7, 7], $params);
    }

    // ── 3. Key flexibility (with or without colon prefix) ───────────

    public function testParamKeyAcceptsBothPrefixedAndUnprefixed(): void
    {
        [$sql1, $params1] = SQLTranslator::namedToPositional(
            'SELECT * FROM t WHERE id = :id',
            [':id' => 1]
        );
        [$sql2, $params2] = SQLTranslator::namedToPositional(
            'SELECT * FROM t WHERE id = :id',
            ['id' => 1]
        );
        $this->assertSame($sql1, $sql2);
        $this->assertSame($params1, $params2);
        $this->assertSame([1], $params1);
    }

    public function testMixedPrefixedAndUnprefixedKeys(): void
    {
        [$sql, $params] = SQLTranslator::namedToPositional(
            'INSERT INTO t (a, b) VALUES (:a, :b)',
            [':a' => 'A', 'b' => 'B']
        );
        $this->assertSame('INSERT INTO t (a, b) VALUES (?, ?)', $sql);
        $this->assertSame(['A', 'B'], $params);
    }

    // ── 4. String literals are preserved ─────────────────────────────

    public function testColonInsideSingleQuotedStringIsPreserved(): void
    {
        [$sql, $params] = SQLTranslator::namedToPositional(
            "INSERT INTO logs (msg) VALUES ('hello :world')",
            []
        );
        $this->assertSame("INSERT INTO logs (msg) VALUES ('hello :world')", $sql);
        $this->assertSame([], $params);
    }

    public function testColonInsideDoubleQuotedStringIsPreserved(): void
    {
        [$sql, $params] = SQLTranslator::namedToPositional(
            'SELECT "col:name" FROM t WHERE id = :id',
            [':id' => 5]
        );
        $this->assertSame('SELECT "col:name" FROM t WHERE id = ?', $sql);
        $this->assertSame([5], $params);
    }

    public function testEscapedQuoteInsideStringHandled(): void
    {
        [$sql, $params] = SQLTranslator::namedToPositional(
            "SELECT * FROM t WHERE note = 'it\\'s a :trap' AND id = :id",
            [':id' => 9]
        );
        $this->assertSame("SELECT * FROM t WHERE note = 'it\\'s a :trap' AND id = ?", $sql);
        $this->assertSame([9], $params);
    }

    // ── 5. Comments are preserved ────────────────────────────────────

    public function testLineCommentIsPreserved(): void
    {
        [$sql, $params] = SQLTranslator::namedToPositional(
            "SELECT id FROM t -- where :foo would be wrong\nWHERE name = :name",
            [':name' => 'Alice']
        );
        $this->assertSame(
            "SELECT id FROM t -- where :foo would be wrong\nWHERE name = ?",
            $sql
        );
        $this->assertSame(['Alice'], $params);
    }

    public function testBlockCommentIsPreserved(): void
    {
        [$sql, $params] = SQLTranslator::namedToPositional(
            'SELECT id FROM t /* keep :foo here */ WHERE name = :name',
            [':name' => 'Alice']
        );
        $this->assertSame(
            'SELECT id FROM t /* keep :foo here */ WHERE name = ?',
            $sql
        );
        $this->assertSame(['Alice'], $params);
    }

    // ── 6. Edge cases ────────────────────────────────────────────────

    public function testUnknownPlaceholderLeftInPlace(): void
    {
        // The driver will surface the real error; we do not invent values.
        [$sql, $params] = SQLTranslator::namedToPositional(
            'SELECT * FROM t WHERE id = :id AND status = :status',
            [':id' => 1]
        );
        $this->assertStringContainsString(':status', $sql);
        $this->assertSame([1], $params);
    }

    public function testSqlWithoutColonPassesThrough(): void
    {
        [$sql, $params] = SQLTranslator::namedToPositional(
            'SELECT * FROM t WHERE id = ?',
            [':id' => 1, ':name' => 'Alice']
        );
        // No translation needed — fast path returns array_values() for safety
        $this->assertSame('SELECT * FROM t WHERE id = ?', $sql);
        $this->assertSame([1, 'Alice'], $params);
    }

    public function testExistingQuestionMarkPassThrough(): void
    {
        // Adapter passes us SQL with ? already; nothing should change beyond
        // dropping array keys.
        [$sql, $params] = SQLTranslator::namedToPositional(
            'UPDATE t SET name = ? WHERE id = ?',
            ['Alice', 1]
        );
        $this->assertSame('UPDATE t SET name = ? WHERE id = ?', $sql);
        $this->assertSame(['Alice', 1], $params);
    }

    public function testNullValueBindsAsNull(): void
    {
        [$sql, $params] = SQLTranslator::namedToPositional(
            'UPDATE t SET email = :email WHERE id = :id',
            [':email' => null, ':id' => 1]
        );
        $this->assertSame('UPDATE t SET email = ? WHERE id = ?', $sql);
        $this->assertSame([null, 1], $params);
    }

    public function testZeroIntegerBindsCorrectly(): void
    {
        // Guards against truthy-checks accidentally treating 0 as missing.
        [$sql, $params] = SQLTranslator::namedToPositional(
            'UPDATE t SET active = :active WHERE id = :id',
            [':active' => 0, ':id' => 0]
        );
        $this->assertSame('UPDATE t SET active = ? WHERE id = ?', $sql);
        $this->assertSame([0, 0], $params);
    }
}
