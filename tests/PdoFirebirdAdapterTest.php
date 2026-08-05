<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * PdoFirebirdAdapter — STANDALONE contract, verified with pdo_firebird ALONE.
 *
 * The native-vs-PDO parity lock-in (PdoFallbackParityTest) can only run where
 * ext-interbase is ALSO present, so it skips on the exact platform the fallback
 * exists for: macOS + Firebird 5, where ext-interbase is clumplet-broken and
 * pdo_firebird is the only working driver. This test fills that gap — it drives
 * the PDO adapter directly against a REAL Firebird server with NO native
 * extension required, so the fallback is actually verified where it is used.
 *
 * Set TINA4_TEST_FIREBIRD_URL to a reachable server, e.g.
 *   firebird://SYSDBA:masterkey@localhost:3052//var/lib/firebird/data/test.fdb
 * Skips loudly (never silently passes) when pdo_firebird or the server is absent.
 *
 * NO mocks — every assertion talks to the real driver against the real database.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\PdoFirebirdAdapter;

class PdoFirebirdAdapterTest extends TestCase
{
    /** A binary payload with NUL and high bytes — the real BLOB torture test. */
    private const BLOB = "\x00\x01\x02\xffhello\x00world\xfe\x7f";

    private function adapter(): PdoFirebirdAdapter
    {
        if (!in_array('firebird', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_firebird driver not present — PDO Firebird fallback UNVERIFIED here.');
        }
        $url = getenv('TINA4_TEST_FIREBIRD_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped('TINA4_TEST_FIREBIRD_URL not set (needs a real Firebird server) — UNVERIFIED.');
        }
        return new PdoFirebirdAdapter($url);
    }

    /** DROP defensively so a lingering table from a crashed run never poisons a fresh run. */
    private function drop(PdoFirebirdAdapter $db, string $table): void
    {
        try {
            $db->execute("DROP TABLE {$table}");
        } catch (\Throwable) {
            // Not present (or held) — the following CREATE is the real assertion.
        }
    }

    public function testTypedReadsAndBlobRoundTrip(): void
    {
        $db = $this->adapter();
        $this->drop($db, 'T4_PDO_ADAPTER');
        $db->execute(
            'CREATE TABLE T4_PDO_ADAPTER (' .
            'ID INTEGER NOT NULL PRIMARY KEY, ' .
            'QTY INTEGER, ' .
            'PRICE_NUM NUMERIC(15,2), ' .   // decimal scale -> float
            'PRICE_DBL DOUBLE PRECISION, ' . // no scale -> stays string (documented)
            'NAME VARCHAR(50), ' .
            'CODE CHAR(8), ' .              // space-padded -> trimmed
            'DATA BLOB SUB_TYPE 0)'         // raw bytes
        );
        $db->execute(
            'INSERT INTO T4_PDO_ADAPTER (ID, QTY, PRICE_NUM, PRICE_DBL, NAME, CODE, DATA) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [1, 42, 1234.56, 19.99, 'widget', 'AB12', self::BLOB]
        );

        $row = $db->fetchOne('SELECT QTY, PRICE_NUM, PRICE_DBL, NAME, CODE, DATA FROM T4_PDO_ADAPTER WHERE ID = 1');
        $this->assertIsArray($row);

        // INTEGER — a real PHP int, never a stringified '42'.
        $this->assertSame(42, $row['qty']);

        // NUMERIC/DECIMAL — re-typed to float to match the native adapter.
        $this->assertSame(1234.56, $row['price_num']);

        // DOUBLE PRECISION — the ONE documented divergence: pdo_firebird cannot
        // distinguish it from CHAR/BLOB, so it stays a numeric STRING. The value
        // is exact; only the PHP type differs from native.
        $this->assertIsString($row['price_dbl']);
        $this->assertSame(19.99, (float) $row['price_dbl']);

        // VARCHAR — verbatim.
        $this->assertSame('widget', $row['name']);

        // CHAR(8) — trailing space padding trimmed, like native.
        $this->assertSame('AB12', $row['code']);

        // BLOB — full byte fidelity, NUL and high bytes intact.
        $this->assertSame(self::BLOB, $row['data']);

        $this->drop($db, 'T4_PDO_ADAPTER');
        $db->close();
    }

    public function testTransactionCommitAndRollback(): void
    {
        $db = $this->adapter();
        $this->drop($db, 'T4_PDO_TXN');
        $db->execute('CREATE TABLE T4_PDO_TXN (ID INTEGER NOT NULL PRIMARY KEY, LABEL VARCHAR(30))');

        // Committed row persists.
        $db->startTransaction();
        $db->insert('T4_PDO_TXN', ['ID' => 1, 'LABEL' => 'committed']);
        $db->commit();
        $this->assertSame(1, $this->countLabel($db, 'committed'));

        // Rolled-back row does not.
        $db->startTransaction();
        $db->insert('T4_PDO_TXN', ['ID' => 2, 'LABEL' => 'rolledback']);
        $db->rollback();
        $this->assertSame(0, $this->countLabel($db, 'rolledback'));

        $this->drop($db, 'T4_PDO_TXN');
        $db->close();
    }

    public function testExecuteFailsLoudOnBadStatement(): void
    {
        $db = $this->adapter();
        try {
            $db->execute('INSERT INTO T4_PDO_NOPE_MISSING (X) VALUES (1)');
            $this->fail('execute() must RAISE on a bad statement, never return quietly');
        } catch (\Throwable $e) {
            $this->assertNotNull($db->error(), 'error() must carry the cause after a failed execute()');
        }
        $db->close();
    }

    public function testIntrospectionSeesTheTable(): void
    {
        $db = $this->adapter();
        $this->drop($db, 'T4_PDO_INTRO');
        $db->execute('CREATE TABLE T4_PDO_INTRO (ID INTEGER NOT NULL PRIMARY KEY, NAME VARCHAR(20))');

        $this->assertTrue($db->tableExists('T4_PDO_INTRO'));
        $this->assertContains('T4_PDO_INTRO', $db->getTables());

        $cols = array_column($db->getColumns('T4_PDO_INTRO'), 'name');
        $this->assertContains('ID', $cols);
        $this->assertContains('NAME', $cols);

        $this->drop($db, 'T4_PDO_INTRO');
        $db->close();
    }

    private function countLabel(PdoFirebirdAdapter $db, string $label): int
    {
        $row = $db->fetchOne('SELECT COUNT(*) AS C FROM T4_PDO_TXN WHERE LABEL = ?', [$label]) ?? [];
        return (int) ($row['c'] ?? reset($row) ?? 0);
    }
}
