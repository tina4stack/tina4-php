<?php

/**
 * Bound PHP booleans must round-trip as query parameters.
 *
 * Every PHP DB extension stringifies a bound `false` to '' (ext-pgsql,
 * mysqli, ext-sqlite3), which the engine then rejects or mismatches. The
 * adapters now normalise booleans via SqlNormalizerTrait::normalizeBoolParams
 * to the literal the column accepts — 't'/'f' on PostgreSQL's native BOOLEAN,
 * 1/0 on the integer/BIT-backed engines (SQLite, MySQL, MSSQL, Firebird).
 *
 * SQLite runs everywhere (no server). The PostgreSQL case is gated on a
 * reachable server (skip-if-no-PG, like CreateTablePostgresTest) and is the
 * exact reported repro: `fetch('... WHERE active = ?', [false])`.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

class BooleanParamBindingTest extends TestCase
{
    // ── SQLite (integer-backed boolean), always runs ────────────────────
    public function testSqliteBoundBooleanRoundTrips(): void
    {
        $db = Database::create('sqlite::memory:', autoCommit: true);
        $db->execute('CREATE TABLE xb (id INTEGER PRIMARY KEY AUTOINCREMENT, active INTEGER)');
        // Bind PHP booleans on insert (true, false, false).
        $db->execute('INSERT INTO xb (active) VALUES (?)', [true]);
        $db->execute('INSERT INTO xb (active) VALUES (?)', [false]);
        $db->execute('INSERT INTO xb (active) VALUES (?)', [false]);

        $false = $db->fetch('SELECT * FROM xb WHERE active = ?', [false]);
        $this->assertNull($db->getError(), 'bound false must not error');
        $this->assertCount(2, $false->records, 'WHERE active = false must match the two false rows');

        $true = $db->fetch('SELECT * FROM xb WHERE active = ?', [true]);
        $this->assertCount(1, $true->records, 'WHERE active = true must match the one true row');
    }

    // ── PostgreSQL (native BOOLEAN), skip-if-no-PG ──────────────────────
    private const PG_HOST = 'localhost';
    private const PG_PORT = 5432;

    private static function pgReachable(): bool
    {
        $fp = @fsockopen(self::PG_HOST, self::PG_PORT, $e, $s, 1.0);
        if ($fp) { fclose($fp); return true; }
        return false;
    }

    public function testPostgresBoundBooleanRoundTrips(): void
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('PostgresAdapter requires ext-pgsql.');
        }
        if (!self::pgReachable()) {
            $this->markTestSkipped('PostgreSQL not reachable — skip');
        }

        $db = Database::create(
            sprintf('postgres://%s:%d/tina4_php', self::PG_HOST, self::PG_PORT),
            username: 'tina4',
            password: 'tina4',
            autoCommit: true
        );
        $db->execute('DROP TABLE IF EXISTS xb');
        $db->execute('CREATE TABLE xb (id SERIAL PRIMARY KEY, active BOOLEAN)');
        $db->execute('INSERT INTO xb (active) VALUES (TRUE),(FALSE),(FALSE)');

        // The exact reported repro — bound false used to become '' → PG error.
        $false = $db->fetch('SELECT * FROM xb WHERE active = ?', [false]);
        $this->assertNull($db->getError(), 'bound false must not produce a PG boolean-syntax error');
        $this->assertCount(2, $false->records, 'WHERE active = false must match the two false rows');
        $this->assertFalse($false->records[0]['active'], 'read back as a native PHP bool');

        $true = $db->fetch('SELECT * FROM xb WHERE active = ?', [true]);
        $this->assertCount(1, $true->records);
        $this->assertTrue($true->records[0]['active']);

        $db->execute('DROP TABLE IF EXISTS xb');
    }
}
