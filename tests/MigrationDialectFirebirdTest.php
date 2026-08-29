<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Real coverage for the migration-dialect DDL-types fix (parity with the
 * tina4-python master `tests/test_migration_dialect_firebird.py`).
 *
 * The scaffolding emitted SQLite-only DDL (`TEXT`, `REAL`,
 * `CREATE TABLE IF NOT EXISTS`, and a `created_at DATETIME` that is not a valid
 * Firebird/PostgreSQL type) that Firebird rejects (-607 on `TEXT`). The fix is
 * two parts, both exercised here:
 *
 *   - the generator emits portable canonical types (`VARCHAR(255)` for strings,
 *     `TIMESTAMP` for datetimes), and
 *   - `SQLTranslator::ddlTypes()` completes the apply-time translation so `TEXT`
 *     -> `BLOB SUB_TYPE TEXT`, `REAL` -> `DOUBLE PRECISION`, and `IF NOT EXISTS`
 *     is stripped on Firebird (and `TIMESTAMP` -> the right datetime type on
 *     MSSQL/MySQL), wired into every Firebird/MSSQL/MySQL adapter execute path.
 *
 * No mocks: the round-trip runs against a LIVE Firebird (`TINA4_TEST_FIREBIRD_URL`)
 * and applies the REALLY-generated migration DDL, then inserts and reads a row.
 * The translation-unit tests are pure functions over strings (no dependency, no
 * double); the generator test invokes the REAL `bin/tina4php` CLI.
 */

use PHPUnit\Framework\TestCase;
use Tina4\SQLTranslator;
use Tina4\Database\Database;
use Tina4\Database\DatabaseAdapter;

class MigrationDialectFirebirdTest extends TestCase
{
    /** SQLite-canonical DDL — the DRY core that fixes migrations AND ORM::createTable AND hand-written DDL. */
    private const RAW = "CREATE TABLE IF NOT EXISTS t (\n"
        . "  id INTEGER PRIMARY KEY,\n"
        . "  bio TEXT,\n"
        . "  price REAL,\n"
        . "  due TIMESTAMP\n)";

    // ── Pure ddlTypes() translation ─────────────────────────────────────────

    public function testFirebirdMapsTextRealAndStripsIfNotExists(): void
    {
        $out = SQLTranslator::ddlTypes(self::RAW, 'firebird');
        $this->assertStringNotContainsStringIgnoringCase('IF NOT EXISTS', $out);
        $this->assertStringContainsString('BLOB SUB_TYPE TEXT', $out);
        $this->assertStringContainsString('DOUBLE PRECISION', $out);
        // No bare TEXT survives — every TEXT is part of a BLOB SUB_TYPE TEXT.
        $upper = strtoupper($out);
        $this->assertSame(substr_count($upper, 'TEXT'), substr_count($upper, 'SUB_TYPE TEXT'));
        // No bare REAL survives (it became DOUBLE PRECISION).
        $this->assertStringNotContainsString(' REAL', $upper);
    }

    public function testFirebirdDoesNotDoubleMapExistingBlobSubTypeText(): void
    {
        // An engine-aware DDL (e.g. ORM::createTable already emits BLOB SUB_TYPE
        // TEXT for Firebird) must not be mangled into BLOB SUB_TYPE BLOB SUB_TYPE …
        $sql = "CREATE TABLE t (id INTEGER PRIMARY KEY, bio BLOB SUB_TYPE TEXT)";
        $out = SQLTranslator::ddlTypes($sql, 'firebird');
        $this->assertStringContainsString('bio BLOB SUB_TYPE TEXT', $out);
        $this->assertStringNotContainsStringIgnoringCase('SUB_TYPE BLOB SUB_TYPE', $out);
        $this->assertSame(1, substr_count(strtoupper($out), 'SUB_TYPE TEXT'));
    }

    public function testMssqlStripsIfNotExistsAndMapsTimestamp(): void
    {
        $out = SQLTranslator::ddlTypes(self::RAW, 'mssql');
        $this->assertStringNotContainsStringIgnoringCase('IF NOT EXISTS', $out);
        $this->assertStringContainsString('DATETIME2', $out);
        $this->assertStringNotContainsStringIgnoringCase('TIMESTAMP', $out);
    }

    public function testMysqlMapsTimestampToDatetime(): void
    {
        $out = SQLTranslator::ddlTypes(self::RAW, 'mysql');
        $this->assertStringContainsStringIgnoringCase('DATETIME', $out);
        $this->assertStringNotContainsStringIgnoringCase('TIMESTAMP', $out);
    }

    public function testIsDdlGatedASelectIsNeverRewritten(): void
    {
        // A query that merely mentions TEXT/REAL must pass through unchanged —
        // type translation applies to DDL only.
        $query = "SELECT id, note FROM t WHERE kind = 'TEXT' AND ratio > 0.5";
        $this->assertSame($query, SQLTranslator::ddlTypes($query, 'firebird'));
        $this->assertSame($query, SQLTranslator::ddlTypes($query, 'mssql'));
        $this->assertSame($query, SQLTranslator::ddlTypes($query, 'mysql'));
    }

    public function testLeadingCommentsDoNotDefeatTheGate(): void
    {
        // Migration files prefix the CREATE with `-- ...` comment lines.
        $commented = "-- Migration: x\n-- Created: now\n\n" . self::RAW;
        $out = SQLTranslator::ddlTypes($commented, 'firebird');
        $this->assertStringContainsString('BLOB SUB_TYPE TEXT', $out);
        $this->assertStringNotContainsStringIgnoringCase('IF NOT EXISTS', $out);
    }

    // ── Generator: portable canonical types ─────────────────────────────────

    public function testGeneratorEmitsPortableTypes(): void
    {
        $body = $this->generatedMigrationBody();
        $this->assertStringContainsString('name VARCHAR(255)', $body);
        $this->assertStringNotContainsString('name TEXT', $body);          // not SQLite-only TEXT
        $this->assertStringContainsString('created_at TIMESTAMP', $body);
        $this->assertStringNotContainsString('created_at DATETIME', $body); // not the invalid-on-Firebird DATETIME
        $this->assertStringNotContainsString('created_at TEXT', $body);     // Firebird -607 guard
        $this->assertStringContainsString('due TIMESTAMP', $body);          // datetime field -> TIMESTAMP
    }

    // ── LIVE Firebird round-trip (the proof) ────────────────────────────────

    public function testGeneratedMigrationAppliesAndRowRoundTripsOnLiveFirebird(): void
    {
        $url = getenv('TINA4_TEST_FIREBIRD_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped('TINA4_TEST_FIREBIRD_URL not set (needs a live Firebird server) — UNVERIFIED.');
        }
        if (!in_array('firebird', \PDO::getAvailableDrivers(), true) && !extension_loaded('interbase')) {
            $this->markTestSkipped('No Firebird driver (pdo_firebird / ext-interbase) present — UNVERIFIED.');
        }

        $createSql = $this->generatedCreateStatement();
        $db = Database::create($url, username: 'SYSDBA', password: 'masterkey');

        $this->dropProbe($db);
        try {
            // execute() runs the adapter's DDL translation (autoIncrementSyntax +
            // ddlTypes), so the SQLite-canonical generated DDL is made
            // Firebird-legal on the way in — where the old TEXT/REAL/IF NOT
            // EXISTS/AUTOINCREMENT DDL raised -607 / -104.
            $db->execute($createSql);

            $db->execute(
                'INSERT INTO dialect_probe (id, name, bio, price, due) VALUES (?, ?, ?, ?, ?)',
                [1, 'Alice', 'a long bio', 9.99, '2026-01-02 03:04:05']
            );

            $row = $db->fetchOne('SELECT id, name, bio, price FROM dialect_probe WHERE id = ?', [1]);
            $this->assertIsArray($row);
            $this->assertSame('Alice', $row['name']);
            $this->assertSame('a long bio', $row['bio']);          // BLOB SUB_TYPE TEXT round-trip
            $this->assertEqualsWithDelta(9.99, (float) $row['price'], 1e-6); // DECIMAL round-trip
        } finally {
            $this->dropProbe($db);
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function dropProbe(DatabaseAdapter $db): void
    {
        try {
            $db->execute('DROP TABLE dialect_probe');
        } catch (\Throwable) {
            // Not present (or held) — the CREATE is the real assertion.
        }
    }

    /**
     * Run the REAL `bin/tina4php generate migration` CLI in a throwaway working
     * directory and return the generated UP-migration file body. No mock — the
     * actual generator a developer runs.
     */
    private function generatedMigrationBody(): string
    {
        $tmp = sys_get_temp_dir() . '/tina4_dialect_' . bin2hex(random_bytes(6));
        if (!mkdir($tmp, 0755, true) && !is_dir($tmp)) {
            $this->fail("could not create temp dir {$tmp}");
        }

        $bin = dirname(__DIR__) . '/bin/tina4php';
        $fields = 'name:string,bio:text,price:float,due:datetime';
        $cmd = 'cd ' . escapeshellarg($tmp) . ' && '
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin)
            . ' generate migration create_dialect_probe --fields ' . escapeshellarg($fields)
            . ' >/dev/null 2>&1';

        $rc = 0;
        $out = [];
        exec($cmd, $out, $rc);

        $files = array_values(array_filter(
            glob($tmp . '/migrations/*_create_dialect_probe.sql') ?: [],
            static fn(string $f): bool => !str_contains($f, '.down.')
        ));

        try {
            $this->assertNotEmpty($files, "generator wrote no migration (rc={$rc})");
            $body = file_get_contents($files[0]);
            $this->assertIsString($body);
            return $body;
        } finally {
            $this->rrmdir($tmp);
        }
    }

    /** The bare CREATE TABLE statement from the generated up-file (comments dropped, no trailing `;`). */
    private function generatedCreateStatement(): string
    {
        $body = $this->generatedMigrationBody();
        $pos = stripos($body, 'CREATE TABLE');
        $this->assertNotFalse($pos, 'generated migration has no CREATE TABLE');
        return rtrim(trim(substr($body, $pos)), ';');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
