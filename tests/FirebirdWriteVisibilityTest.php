<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Live Firebird regression guard for #132 — the LOST-WRITE bug.
 *
 * Root cause (native ext-interbase, pre-fix): a parameterised DELETE/UPDATE run
 * inside an explicit startTransaction()->execute()->commit() (the exact path
 * ORM::delete()/ORM::save()-as-update take) was prepared with the 2-arg
 * ibase_prepare($transaction, $sql) — which mis-binds the TRANSACTION resource
 * as the LINK, so the prepared statement never joined the active transaction.
 * commit() then committed an EMPTY transaction and the write was GENUINELY LOST
 * (not merely a stale read): the row was still present when read from ANY
 * connection. The reporter's tell was that the SAME delete via auto-commit
 * execute() worked — because that path prepared against the real link
 * (ibase_prepare($link, $sql), the valid 2-arg form). Fixed by preparing with
 * the 3-arg ibase_prepare($link, $trans, $sql) so the statement joins the active
 * transaction.
 *
 * This test locks the fix in DECISIVELY: it performs the explicit-transaction
 * DELETE and UPDATE (raw adapter AND via the ORM), then re-reads through a
 * BRAND-NEW, SEPARATE connection. A separate connection cannot be fooled by a
 * frozen snapshot on the writing handle, so it distinguishes a genuine
 * persisted write from a stale same-handle read: with the lost-write bug the
 * count stays 3 on every connection; with the fix it is 2.
 *
 * NO mocks — every assertion talks to a REAL Firebird server over TCP, gated on
 * TINA4_TEST_FIREBIRD_URL (loud skip in CI without a live server, mirroring
 * FirebirdOrmWriteTest / PdoFirebirdAdapterTest / MigrationV3Test). Runs against
 * each Firebird driver that is actually usable on the host: pdo_firebird always
 * (a genuinely independent PDO connection per Database::create), and native
 * ext-interbase when it can actually connect (it is present-but-clumplet-broken
 * on macOS + FB5, so that leg skips there and is exercised on Linux CI).
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\ORM;

class FbWriteVisWidget extends ORM
{
    public string $tableName = "t_fb_write_vis";
    public string $primaryKey = "id";
    public $id;
    public $name;
}

class FirebirdWriteVisibilityTest extends TestCase
{
    private const TABLE = 't_fb_write_vis';

    /** Base URL for a real Firebird server, or a loud skip (never a silent pass). */
    private function baseUrl(): string
    {
        $url = getenv('TINA4_TEST_FIREBIRD_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped(
                'Set TINA4_TEST_FIREBIRD_URL to run the live Firebird write-visibility test '
                . '(e.g. firebird://SYSDBA:masterkey@localhost:3050//tmp/test.fdb)'
            );
        }
        return $url;
    }

    /** Force a specific driver on the base URL. */
    private function urlFor(string $driver): string
    {
        $url = $this->baseUrl();
        return $url . (str_contains($url, '?') ? '&' : '?') . 'driver=' . $driver;
    }

    /**
     * Open a connection for $driver, or skip THIS leg if the driver cannot serve
     * a real connection here (pdo_firebird absent, or native present-but-broken).
     */
    private function connectOrSkip(string $driver, string $expectedAdapter): Database
    {
        // Resolve the target URL and driver availability BEFORE the connect try —
        // a "no server" / "driver absent" skip must propagate cleanly, not be
        // caught and re-wrapped as a connection failure below.
        $url = $this->urlFor($driver);
        if ($driver === 'pdo' && !in_array('firebird', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_firebird driver not present — pdo write-visibility UNVERIFIED here.');
        }
        if ($driver === 'interbase' && !function_exists('ibase_connect') && !function_exists('fbird_connect')) {
            $this->markTestSkipped('ext-interbase not installed — native write-visibility UNVERIFIED here.');
        }
        try {
            $db = Database::create($url);
            // Touch the connection so a present-but-broken native driver (macOS +
            // FB5 clumplet) fails HERE and this leg skips instead of failing.
            $db->fetchOne('SELECT 1 AS N FROM RDB$DATABASE');
        } catch (\Throwable $e) {
            $this->markTestSkipped("Firebird driver '{$driver}' cannot connect here ({$e->getMessage()}) — leg UNVERIFIED.");
        }
        $this->assertInstanceOf($expectedAdapter, $db->getAdapter(), "driver={$driver} must select {$expectedAdapter}");
        return $db;
    }

    /** COUNT(*) via a BRAND-NEW connection (defeats a stale snapshot on the writer). */
    private function freshCount(string $driver): int
    {
        $v = Database::create($this->urlFor($driver));
        $row = array_change_key_case($v->fetchOne('SELECT COUNT(*) AS c FROM ' . self::TABLE) ?? []);
        $v->close();
        return (int) ($row['c'] ?? 0);
    }

    /** name of a row via a BRAND-NEW connection, or null if the row is gone. */
    private function freshName(string $driver, int $id): ?string
    {
        $v = Database::create($this->urlFor($driver));
        $row = $v->fetchOne('SELECT name FROM ' . self::TABLE . ' WHERE id = ?', [$id]);
        $v->close();
        if ($row === null) {
            return null;
        }
        return array_change_key_case($row)['name'] ?? null;
    }

    /** Recreate the table with three known rows, all committed. */
    private function seedThreeRows(Database $writer): void
    {
        try {
            $writer->execute('DROP TABLE ' . self::TABLE);
            $writer->commit();
        } catch (\Throwable) {
            // absent — the CREATE below is the real assertion
        }
        $writer->execute('CREATE TABLE ' . self::TABLE . ' (id INTEGER NOT NULL PRIMARY KEY, name VARCHAR(50))');
        $writer->commit();
        foreach ([[1, 'alpha'], [2, 'beta'], [3, 'gamma']] as [$id, $name]) {
            $writer->execute('INSERT INTO ' . self::TABLE . ' (id, name) VALUES (?, ?)', [$id, $name]);
        }
        $writer->commit();
    }

    public function testExplicitTransactionWriteIsVisibleToSeparateConnectionPdo(): void
    {
        $this->runExplicitTransactionChecks('pdo', \Tina4\Database\PdoFirebirdAdapter::class);
    }

    public function testExplicitTransactionWriteIsVisibleToSeparateConnectionNative(): void
    {
        $this->runExplicitTransactionChecks('interbase', \Tina4\Database\FirebirdAdapter::class);
    }

    public function testOrmDeleteAndUpdateAreVisibleToSeparateConnectionPdo(): void
    {
        $this->runOrmChecks('pdo', \Tina4\Database\PdoFirebirdAdapter::class);
    }

    public function testOrmDeleteAndUpdateAreVisibleToSeparateConnectionNative(): void
    {
        $this->runOrmChecks('interbase', \Tina4\Database\FirebirdAdapter::class);
    }

    /**
     * The raw adapter explicit-transaction path — startTransaction()->execute()
     * ->commit() with a NAMED :id param (exactly what ORM::delete()/save() emit).
     */
    private function runExplicitTransactionChecks(string $driver, string $expectedAdapter): void
    {
        $writer = $this->connectOrSkip($driver, $expectedAdapter);
        try {
            $this->seedThreeRows($writer);
            $this->assertSame(3, $this->freshCount($driver), 'precondition: three rows committed');

            // DELETE inside an explicit transaction.
            $writer->startTransaction();
            $writer->execute('DELETE FROM ' . self::TABLE . ' WHERE id = :id', [':id' => 2]);
            $writer->commit();

            // UPDATE inside an explicit transaction.
            $writer->startTransaction();
            $writer->execute('UPDATE ' . self::TABLE . ' SET name = :nm WHERE id = :id', [':nm' => 'BETA_GONE_ALPHA_NEW', ':id' => 1]);
            $writer->commit();

            // The decisive checks — read through a BRAND-NEW connection.
            $this->assertSame(2, $this->freshCount($driver),
                'explicit-transaction DELETE must PERSIST and be visible to a separate connection (#132 lost-write guard)');
            $this->assertNull($this->freshName($driver, 2),
                'the deleted row must be gone on a separate connection (not merely a stale same-handle read)');
            $this->assertSame('BETA_GONE_ALPHA_NEW', $this->freshName($driver, 1),
                'explicit-transaction UPDATE must PERSIST and be visible to a separate connection');
        } finally {
            try { $writer->execute('DROP TABLE ' . self::TABLE); $writer->commit(); } catch (\Throwable) {}
            $writer->close();
        }
    }

    /** The reporter's real API — ORM::delete() and ORM::save()-as-update. */
    private function runOrmChecks(string $driver, string $expectedAdapter): void
    {
        $writer = $this->connectOrSkip($driver, $expectedAdapter);
        try {
            $this->seedThreeRows($writer);
            ORM::bindDatabase($writer);
            $this->assertSame(3, $this->freshCount($driver), 'precondition: three rows committed');

            $toDelete = new FbWriteVisWidget();
            $this->assertTrue($toDelete->load('id = ?', [2]), 'row 2 loads before delete');
            $this->assertNotFalse($toDelete->delete(), 'ORM::delete() must report success');

            $toUpdate = new FbWriteVisWidget();
            $this->assertTrue($toUpdate->load('id = ?', [1]), 'row 1 loads before update');
            $toUpdate->name = 'ORM_UPDATED';
            $this->assertNotFalse($toUpdate->save(), 'ORM::save()-as-update must report success');

            $this->assertSame(2, $this->freshCount($driver),
                'ORM::delete() must PERSIST and be visible to a separate connection (#132 lost-write guard)');
            $this->assertNull($this->freshName($driver, 2),
                'the ORM-deleted row must be gone on a separate connection');
            $this->assertSame('ORM_UPDATED', $this->freshName($driver, 1),
                'ORM::save()-as-update must PERSIST and be visible to a separate connection');
        } finally {
            try { $writer->execute('DROP TABLE ' . self::TABLE); $writer->commit(); } catch (\Throwable) {}
            $writer->close();
        }
    }
}
