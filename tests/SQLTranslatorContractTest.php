<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * SQL translator literal-safe + BIGINT-autoincrement contract — feature 7
 * (sqltranslator_contract.json), parity with
 * tina4-python/tests/test_sqltranslator_contract.py.
 *
 * Locks out a DATA-CORRUPTION defect against REAL databases (NO MOCKS): the
 * dialect rewrites (|| -> CONCAT, ILIKE -> LOWER LIKE, TRUE/FALSE -> 1/0) used to
 * MANGLE STRING LITERALS — a value of 'a||b', a label 'TRUE', or a LIKE pattern
 * that mentions ILIKE was rewritten as if it were SQL, and concat split the WHOLE
 * statement on || (`SELECT a || b FROM t` -> `CONCAT(SELECT a, b FROM t)`). The
 * rewrites are now literal-safe (mask -> rewrite -> restore) and concat only
 * rewrites the operand chain. concat + ilike are now WIRED into the MySQL adapter
 * (MySQLAdapter::translateDialect on the query/execute path) so a portable `||`
 * or ILIKE query RUNS on real MySQL.
 *
 * SQLTRANS-DEC-03: a BIGINT ... AUTOINCREMENT DDL now yields a real 64-bit
 * auto-increment column (PostgreSQL BIGSERIAL, MySQL BIGINT AUTO_INCREMENT).
 *
 * Real services on the .99 lab: MySQL :3306 (tina4/tina4 -> tina4_test),
 * PostgreSQL :55432 (tina4/tina4 -> tina4_php). Under TINA4_REQUIRE_SERVICES a
 * skip whose reason names MySQL/PostgreSQL is a hard failure — these MUST run.
 *
 * Mutation-proof: revert the literal-safe rewrite and the literal cases go RED;
 * revert the PostgreSQL BIGINT branch and the bigint case goes RED.
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\SQLTranslator;

class SQLTranslatorContractTest extends TestCase
{
    private static function mysqlUrl(): string
    {
        $host = getenv('TINA4_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_MYSQL_PORT') ?: 3306);
        $db   = getenv('TINA4_TEST_MYSQL_DB') ?: 'tina4_test';
        $user = getenv('TINA4_TEST_MYSQL_USERNAME') ?: 'tina4';
        $pass = getenv('TINA4_TEST_MYSQL_PASSWORD') ?: 'tina4';
        return "mysql://{$user}:{$pass}@{$host}:{$port}/{$db}";
    }

    private static function pgUrl(): string
    {
        $host = getenv('TINA4_TEST_PG_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_PG_PORT') ?: 55432);
        $db   = getenv('TINA4_TEST_PG_DB') ?: 'tina4_php';
        $user = getenv('TINA4_TEST_PG_USERNAME') ?: 'tina4';
        $pass = getenv('TINA4_TEST_PG_PASSWORD') ?: 'tina4';
        return "postgres://{$user}:{$pass}@{$host}:{$port}/{$db}";
    }

    private static function reachable(string $host, int $port): bool
    {
        $conn = @fsockopen($host, $port, $errno, $errstr, 2.0);
        if ($conn === false) {
            return false;
        }
        fclose($conn);
        return true;
    }

    private function requireMysql(): Database
    {
        $host = getenv('TINA4_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_MYSQL_PORT') ?: 3306);
        if (!self::reachable($host, $port)) {
            $this->markTestSkipped("MySQL not reachable at {$host}:{$port} for the SQL-translator contract");
        }
        return Database::create(self::mysqlUrl());
    }

    private function requirePg(): Database
    {
        $host = getenv('TINA4_TEST_PG_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_PG_PORT') ?: 55432);
        if (!self::reachable($host, $port)) {
            $this->markTestSkipped("PostgreSQL not reachable at {$host}:{$port} for the SQL-translator contract");
        }
        return Database::create(self::pgUrl());
    }

    private static function table(string $prefix): string
    {
        return 'tina4_sqltrans_' . $prefix . '_' . bin2hex(random_bytes(5));
    }

    // ── Invariant 1: literal-safe concat / bool / ilike, RUN on a real engine ──

    /** case: concat pipes translate outside literals and run */
    public function testConcatPipesTranslateOutsideLiteralsAndRun(): void
    {
        $db = $this->requireMysql();
        $t = self::table('concat');
        $db->execute("CREATE TABLE {$t} (id INTEGER PRIMARY KEY AUTO_INCREMENT, first_name VARCHAR(50), last_name VARCHAR(50))");
        try {
            $db->execute("INSERT INTO {$t} (first_name, last_name) VALUES (?, ?)", ['Jane', 'Doe']);
            $row = $db->fetchOne("SELECT (first_name || ' ' || last_name) AS fullname FROM {$t}");
            $this->assertNotNull($row);
            $this->assertSame('Jane Doe', $row['fullname']);
        } finally {
            $db->execute("DROP TABLE {$t}");
        }
    }

    /** case: pipes inside a string literal are preserved */
    public function testPipesInsideAStringLiteralArePreserved(): void
    {
        $db = $this->requireMysql();
        $t = self::table('litpipe');
        $db->execute("CREATE TABLE {$t} (id INTEGER PRIMARY KEY AUTO_INCREMENT, data VARCHAR(50))");
        try {
            $db->execute("INSERT INTO {$t} (data) VALUES (?)", ['a||b']);
            $db->execute("INSERT INTO {$t} (data) VALUES (?)", ['plain']);
            $result = $db->fetch("SELECT id, data FROM {$t} WHERE data = 'a||b'");
            $this->assertCount(1, $result->records);
            $this->assertSame('a||b', $result->records[0]['data']);
        } finally {
            $db->execute("DROP TABLE {$t}");
        }
    }

    /** case: ilike pattern with multiple words survives and runs */
    public function testIlikePatternWithMultipleWordsSurvivesAndRuns(): void
    {
        $db = $this->requireMysql();
        $t = self::table('ilike');
        $db->execute("CREATE TABLE {$t} (id INTEGER PRIMARY KEY AUTO_INCREMENT, bio VARCHAR(100))");
        try {
            $db->execute("INSERT INTO {$t} (bio) VALUES (?)", ['Loves TWO WORDS and coffee']);
            $db->execute("INSERT INTO {$t} (bio) VALUES (?)", ['nothing here']);
            $result = $db->fetch("SELECT id, bio FROM {$t} WHERE bio ILIKE '%two words%'");
            $this->assertCount(1, $result->records);
            $this->assertStringContainsString('TWO WORDS', $result->records[0]['bio']);
        } finally {
            $db->execute("DROP TABLE {$t}");
        }
    }

    /** case: boolean token inside a string literal is preserved */
    public function testBooleanTokenInsideAStringLiteralIsPreserved(): void
    {
        $db = $this->requireMysql();
        $t = self::table('boollit');
        $db->execute("CREATE TABLE {$t} (id INTEGER PRIMARY KEY AUTO_INCREMENT, flag INTEGER, label VARCHAR(20))");
        try {
            $db->execute("INSERT INTO {$t} (flag, label) VALUES (?, ?)", [1, 'TRUE']);
            $db->execute("INSERT INTO {$t} (flag, label) VALUES (?, ?)", [0, 'other']);
            $canonical = "SELECT id, label FROM {$t} WHERE flag = TRUE AND label = 'TRUE'";
            $translated = SQLTranslator::booleanToInt($canonical);
            $this->assertStringContainsString('flag = 1', $translated);
            $this->assertStringContainsString("label = 'TRUE'", $translated);
            $result = $db->fetch($translated);
            $this->assertCount(1, $result->records);
            $this->assertSame('TRUE', $result->records[0]['label']);
        } finally {
            $db->execute("DROP TABLE {$t}");
        }
    }

    // ── Invariant 2: BIGINT autoincrement creates a real 64-bit column ──

    private function bigintCase(Database $db, string $engine): void
    {
        $t = self::table('bigint');
        $ddl = "CREATE TABLE {$t} (id BIGINT PRIMARY KEY AUTOINCREMENT, name VARCHAR(50))";
        $db->execute(SQLTranslator::autoIncrementSyntax($ddl, $engine));
        try {
            // Insert with NO id -> must auto-generate (a plain BIGINT PK with the
            // keyword stripped would fail the NOT NULL key here).
            $db->execute("INSERT INTO {$t} (name) VALUES (?)", ['alpha']);
            $row = $db->fetchOne("SELECT id FROM {$t} WHERE name = ?", ['alpha']);
            $this->assertNotNull($row);
            $this->assertGreaterThanOrEqual(1, (int) $row['id']);
            // The column is really 64-bit. Result-key casing differs by driver,
            // so read the single value rather than a fixed key.
            $typeRow = $db->fetchOne(
                "SELECT data_type AS dtype FROM information_schema.columns WHERE table_name = ? AND column_name = 'id'",
                [$t]
            );
            $this->assertNotNull($typeRow);
            $this->assertSame('bigint', strtolower((string) reset($typeRow)));
        } finally {
            $db->execute("DROP TABLE {$t}");
        }
    }

    /** case: bigint autoincrement creates a real bigint column (postgres) */
    public function testBigintAutoincrementCreatesARealBigintColumnPostgres(): void
    {
        $this->bigintCase($this->requirePg(), 'postgresql');
    }

    /** case: bigint autoincrement creates a real bigint column (mysql) */
    public function testBigintAutoincrementCreatesARealBigintColumnMysql(): void
    {
        $this->bigintCase($this->requireMysql(), 'mysql');
    }
}
