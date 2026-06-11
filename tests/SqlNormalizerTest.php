<?php

/**
 * Tina4 PHP — v3.13.12 SQL normalization (strip trailing ;).
 *
 * The framework wraps user SQL with COUNT(*) subqueries in fetch()
 * and appends LIMIT/OFFSET (or engine-specific equivalent). A
 * trailing `;` breaks both wrappers. This test pins the
 * normalisation behaviour at two levels:
 *
 *   1. Unit: SqlNormalizerTrait::stripTrailingSemicolons
 *   2. Integration: SQLite3Adapter::fetch / fetchOne survive a
 *      user-supplied trailing `;`
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;

class SqlNormalizerTest extends TestCase
{
    /** Use reflection so the protected static method is testable directly. */
    private function strip(string $sql): string
    {
        $ref = new ReflectionClass(SQLite3Adapter::class);
        $method = $ref->getMethod('stripTrailingSemicolons');
        $method->setAccessible(true);
        return $method->invoke(null, $sql);
    }

    public function testStripsSingleTrailingSemicolon(): void
    {
        $this->assertSame('SELECT 1', $this->strip('SELECT 1;'));
    }

    public function testStripsTrailingWhitespaceAndSemicolons(): void
    {
        $this->assertSame('SELECT 1', $this->strip('SELECT 1   ;  '));
    }

    public function testStripsMultipleTrailingSemicolons(): void
    {
        $this->assertSame('SELECT 1', $this->strip('SELECT 1;;;'));
    }

    public function testStripsNewlineSemicolon(): void
    {
        $this->assertSame('SELECT 1', $this->strip("SELECT 1\n;\n"));
    }

    public function testEmptyStringPassthrough(): void
    {
        $this->assertSame('', $this->strip(''));
    }

    public function testAllSemicolons(): void
    {
        $this->assertSame('', $this->strip(';;;'));
    }

    public function testNoTrailingSemicolonUnchanged(): void
    {
        $this->assertSame('SELECT 1', $this->strip('SELECT 1'));
    }

    public function testInternalSemicolonUntouched(): void
    {
        // Trailing `;` after the literal — should still strip
        $this->assertSame("SELECT ';' AS x", $this->strip("SELECT ';' AS x;"));
    }

    // ── Integration test against SQLite ────────────────────────────────

    public function testFetchSurvivesTrailingSemicolon(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'tina4_sql_norm_') . '.db';
        $adapter = new SQLite3Adapter($tmpFile);
        $adapter->open();
        try {
            $adapter->execute('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
            for ($i = 0; $i < 5; $i++) {
                $adapter->execute('INSERT INTO widgets (name) VALUES (?)', ["widget-{$i}"]);
            }

            // Pre-v3.13.12: would fail with "near \";\": syntax error" because
            // the framework wraps in COUNT(*) FROM (SELECT * FROM widgets;) AS …
            $result = $adapter->fetch('SELECT * FROM widgets;');
            $this->assertCount(5, $result['data'], 'fetch must return rows when user SQL ends with ;');
            $this->assertSame(5, $result['total'], 'COUNT(*) probe must survive trailing ;');
        } finally {
            $adapter->close();
            unlink($tmpFile);
        }
    }

    public function testFetchOneSurvivesTrailingSemicolon(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'tina4_sql_norm_') . '.db';
        $adapter = new SQLite3Adapter($tmpFile);
        $adapter->open();
        try {
            $adapter->execute('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
            $adapter->execute('INSERT INTO widgets (name) VALUES (?)', ['alpha']);

            $row = $adapter->fetchOne('SELECT * FROM widgets WHERE id = ?;', [1]);
            $this->assertNotNull($row);
            $this->assertSame('alpha', $row['name']);
        } finally {
            $adapter->close();
            unlink($tmpFile);
        }
    }
}
