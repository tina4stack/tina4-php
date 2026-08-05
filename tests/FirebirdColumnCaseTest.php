<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Firebird column names fold back only when Firebird folded them.
 *
 * Firebird's identifier folding is ASYMMETRIC:
 *
 *     SELECT 1 AS x        ->  stored X       (unquoted folds to UPPER)
 *     SELECT 1 AS "MyCol"  ->  stored MyCol   (quoted keeps its case)
 *
 * Every other engine Tina4 supports gives `x` for the first form — PostgreSQL
 * folds to lower, MySQL/SQLite/MSSQL preserve — so portable code reading
 * $row['x'] broke on Firebird alone. The tests in this repo papered over it by
 * reading `$row['X'] ?? $row['x']`, which is how the divergence stayed invisible.
 *
 * BOTH halves are asserted on purpose, and BOTH drivers are exercised. A blanket
 * strtolower() passes the first case and fails the second — that is precisely
 * what the Python master used to do, so `AS "MyCol"` came back `mycol` and a
 * mixed-case key was unreachable. A fix that merely stopped folding passes the
 * second and fails the first.
 *
 * NO mocks — every assertion talks to a REAL Firebird server over TCP.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

class FirebirdColumnCaseTest extends TestCase
{
    /** Base URL for a real Firebird server, or a loud skip (never a silent pass). */
    private function urlFor(string $driver): string
    {
        $url = getenv('TINA4_TEST_FIREBIRD_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped(
                'Set TINA4_TEST_FIREBIRD_URL to run the live Firebird column-case test'
            );
        }
        if ($driver === 'pdo' && !in_array('firebird', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_firebird not present — that leg is UNVERIFIED here.');
        }
        if ($driver === 'interbase' && !function_exists('ibase_connect') && !function_exists('fbird_connect')) {
            $this->markTestSkipped('ext-interbase not installed — that leg is UNVERIFIED here.');
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . 'driver=' . $driver;
    }

    private function connectOrSkip(string $driver): Database
    {
        $url = $this->urlFor($driver);
        try {
            $db = Database::create($url);
            $db->fetchOne('SELECT 1 AS N FROM RDB$DATABASE');
            return $db;
        } catch (\Throwable $e) {
            $this->markTestSkipped("Firebird driver '{$driver}' cannot connect here ({$e->getMessage()})");
        }
    }

    public static function driverProvider(): array
    {
        return ['native ext-interbase' => ['interbase'], 'pdo_firebird' => ['pdo']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('driverProvider')]
    public function testUnquotedAliasReadsBackLowercase(string $driver): void
    {
        $db = $this->connectOrSkip($driver);
        try {
            $row = $db->fetchOne('SELECT 1 AS x FROM RDB$DATABASE');
            $this->assertSame(
                ['x'],
                array_keys($row),
                "driver={$driver}: an unquoted alias must read like every other engine"
            );
            $this->assertSame(1, (int) $row['x']);
        } finally {
            $db->close();
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('driverProvider')]
    public function testQuotedMixedCaseAliasKeepsItsCase(string $driver): void
    {
        $db = $this->connectOrSkip($driver);
        try {
            $row = $db->fetchOne('SELECT 1 AS "MyCol" FROM RDB$DATABASE');
            $this->assertSame(
                ['MyCol'],
                array_keys($row),
                "driver={$driver}: a quoted alias was cased deliberately; do not fold it"
            );
            $this->assertSame(1, (int) $row['MyCol']);
        } finally {
            $db->close();
        }
    }
}
