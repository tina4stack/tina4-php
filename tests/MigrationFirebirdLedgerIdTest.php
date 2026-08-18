<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * The migration ledger must give every row its own id on Firebird.
 *
 * Firebird has no AUTOINCREMENT, so recordMigration() supplies the tracking
 * table's primary key itself: it reads GEN_TINA4_MIGRATION_ID and puts the
 * value into the INSERT. That read asked for $rows[0]['NEXT_ID'], but since
 * 3.13.99 FirebirdAdapter::columnName folds an unquoted upper-case alias to
 * lower case — the behaviour FirebirdColumnCaseTest pins — so the key is
 * 'next_id', the lookup missed, and the '?? 1' fallback handed EVERY row id 1.
 * The first migration applied and the second died on the ledger's own primary
 * key, so a Firebird database carrying the canonical v3 tracking table could
 * not be migrated past its first file at all.
 *
 * THAT THE ADAPTER FOLDS IS ALREADY COVERED. What was not covered is whether
 * the migration runner reads back what the adapter returns. A fold is only half
 * a contract; the other half is each consumer of it, and this is the missing
 * half for the ledger.
 *
 * WHY IT STAYED INVISIBLE. A tina4_migration upgraded from v2 keeps
 * migration_id as its primary key and has neither an id column nor the
 * generator, so hasLegacyMigrationIdColumn() short-circuits the branch and the
 * bad read never executes. Every long-lived installation is that shape; only a
 * freshly created database gets the canonical v3 table. The failure is total on
 * new databases and absent on existing ones — the worst possible distribution
 * for anyone noticing it.
 *
 * NO DOUBLES — this drives a real migration chain against a REAL Firebird
 * server over TCP. A fake adapter would have to be told what the generator read
 * returns, which is the exact thing in question.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Migration;

class MigrationFirebirdLedgerIdTest extends TestCase
{
    /** Distinct names so a shared test server is never clobbered. */
    private const T_ONE = 'nomock_ledger_one';
    private const T_TWO = 'nomock_ledger_two';

    private string $migrationsDir;

    protected function setUp(): void
    {
        $this->migrationsDir = sys_get_temp_dir() . '/tina4_mig_ledger_' . uniqid();
        mkdir($this->migrationsDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->migrationsDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->migrationsDir);
    }

    private static function firebirdUrl(): string
    {
        $url = getenv('TINA4_TEST_FIREBIRD_URL');

        return $url === false ? '' : $url;
    }

    private function firebirdOrSkip(): Database
    {
        if (!function_exists('ibase_connect') && !in_array('firebird', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('Firebird client not installed — neither ext-interbase nor pdo_firebird is available');
        }

        $url = self::firebirdUrl();
        if ($url === '') {
            $this->markTestSkipped(
                'TINA4_TEST_FIREBIRD_URL is not set — no live Firebird was promised to this run, '
                . 'so the ledger id case cannot run'
            );
        }

        // THE CONNECTION IS THE PROBE, as in the other live Firebird tests: it
        // is the only thing that establishes the database is really there and
        // really usable, and its failure carries the server's own reason.
        try {
            $db = new Database($url);
            $db->fetchOne('SELECT 1 AS N FROM RDB$DATABASE');
        } catch (\Throwable $failure) {
            $this->markTestSkipped(sprintf(
                'Firebird cannot connect at %s — %s',
                $url,
                $failure->getMessage()
            ));
        }

        return $db;
    }

    /**
     * Drops the tracking table and its generator so the runner builds the
     * CANONICAL v3 table, which is the only shape that reaches the branch under
     * test. A v2-upgraded table would skip it and prove nothing.
     */
    private function resetLedger(Database $db): void
    {
        foreach (['DROP TABLE tina4_migration', 'DROP SEQUENCE GEN_TINA4_MIGRATION_ID'] as $ddl) {
            try {
                $db->execute($ddl);
                $db->commit();
            } catch (\Throwable) {
                // Absent already — Firebird has no DROP ... IF EXISTS.
            }
        }
    }

    private function dropScratchTables(Database $db): void
    {
        foreach ([self::T_ONE, self::T_TWO] as $table) {
            try {
                $db->execute('DROP TABLE ' . $table);
                $db->commit();
            } catch (\Throwable) {
                // Never created, or already gone.
            }
        }
    }

    /**
     * Two migrations must BOTH apply, and their ledger rows must not collide.
     *
     * Two files is the smallest chain that can detect the defect: the first row
     * is inserted whatever id it is given, so a chain of one passes even when
     * every row is stamped with the same value. The collision only surfaces on
     * the second.
     */
    public function testSecondMigrationDoesNotCollideOnTheLedgerPrimaryKey(): void
    {
        $db = $this->firebirdOrSkip();

        try {
            $this->dropScratchTables($db);
            $this->resetLedger($db);

            file_put_contents(
                $this->migrationsDir . '/20260101000001_nomock_ledger_one.sql',
                'CREATE TABLE ' . self::T_ONE . ' (id INTEGER)'
            );
            file_put_contents(
                $this->migrationsDir . '/20260101000002_nomock_ledger_two.sql',
                'CREATE TABLE ' . self::T_TWO . ' (id INTEGER)'
            );

            $result = (new Migration($db, $this->migrationsDir))->migrate();

            $this->assertSame(
                [],
                $result['errors'],
                'both migrations must apply; a violation of the tina4_migration '
                . 'primary key here means every ledger row was stamped with the '
                . 'same id'
            );
            $this->assertCount(2, $result['applied'], 'both migrations must be recorded');

            // Read the ids back rather than trusting the return value: the
            // defect is in what was WRITTEN to the ledger.
            $rows = $db->fetchAll('SELECT id FROM tina4_migration');
            $ids  = array_map(
                static fn($row) => (int) (array_change_key_case((array) $row)['id'] ?? 0),
                $rows
            );

            $this->assertCount(2, $ids, 'the ledger must hold one row per migration');
            $this->assertSame(
                $ids,
                array_values(array_unique($ids)),
                'each ledger row must carry its own id, not a repeated fallback'
            );
        } finally {
            $this->dropScratchTables($db);
            $this->resetLedger($db);
            $db->close();
        }
    }
}
