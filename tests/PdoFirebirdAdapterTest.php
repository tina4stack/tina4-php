<?php

/**
 * PdoFirebirdAdapter — the modern pdo_firebird transport for Firebird.
 *
 * Motivating regression: on Firebird 4 the engine widens SUM()/AVG() over an
 * exact-numeric column to the new 128-bit INT128 type. The deprecated
 * ext-interbase driver (php_interbase, built against the Firebird 3 API) cannot
 * marshal that result descriptor and hard-crashes the PHP process (SIGSEGV /
 * 0xC0000005) — a native fault no try/catch can trap. The maintained core
 * pdo_firebird driver reads INT128/DECFLOAT back as a string, so the aggregate
 * returns normally. This adapter runs Firebird over pdo_firebird while keeping
 * the FirebirdAdapter type identity the framework dispatches on.
 *
 * Live tests need a Firebird server (no mocks): gated on the pdo_firebird
 * extension AND TINA4_TEST_FIREBIRD_URL; otherwise skipped.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\FirebirdAdapter;
use Tina4\Database\PdoFirebirdAdapter;

class PdoFirebirdAdapterTest extends TestCase
{
    private ?PdoFirebirdAdapter $db = null;
    private string $table = 'T4_PDO_FB_TEST';
    private string $gen = 'GEN_T4_PDO_FB_TEST_ID';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_firebird')) {
            $this->markTestSkipped('pdo_firebird extension not installed');
        }
        if (!getenv('TINA4_TEST_FIREBIRD_URL')) {
            $this->markTestSkipped('Set TINA4_TEST_FIREBIRD_URL to run live PdoFirebirdAdapter tests (e.g. firebird://SYSDBA:masterkey@localhost:3050/path/to/test.fdb)');
        }
        $this->db = new PdoFirebirdAdapter(getenv('TINA4_TEST_FIREBIRD_URL'));
        $this->dropFixtures();
        $this->db->execute(
            "CREATE TABLE {$this->table} (ID INTEGER NOT NULL PRIMARY KEY, NAME VARCHAR(50), AMOUNT NUMERIC(18,2))"
        );
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->dropFixtures();
            $this->db->close();
            $this->db = null;
        }
    }

    private function dropFixtures(): void
    {
        // DROP GENERATOR is FB2, DROP SEQUENCE is FB3+ — try both; either the
        // table/generator exists (dropped) or it doesn't (harmless throw).
        foreach (["DROP TABLE {$this->table}", "DROP SEQUENCE {$this->gen}", "DROP GENERATOR {$this->gen}"] as $ddl) {
            try {
                $this->db->execute($ddl);
            } catch (\Throwable) {
                // fixture not present
            }
        }
    }

    /** Set (or clear with null) the driver-selection override the factory reads. */
    private function setDriver(?string $value): void
    {
        if ($value === null) {
            unset($_ENV['TINA4_FIREBIRD_DRIVER']);
            putenv('TINA4_FIREBIRD_DRIVER');
        } else {
            $_ENV['TINA4_FIREBIRD_DRIVER'] = $value;
            putenv("TINA4_FIREBIRD_DRIVER={$value}");
        }
    }

    public function testConnectsAndKeepsFirebirdIdentity(): void
    {
        $this->assertInstanceOf(\PDO::class, $this->db->getConnection());
        // Extends FirebirdAdapter so every `instanceof FirebirdAdapter` dispatch
        // (Database::getNextId generator path, Migration DDL branch, dialect
        // detection) keeps working on the PDO transport.
        $this->assertInstanceOf(FirebirdAdapter::class, $this->db);
        $this->assertIsArray($this->db->getTables());
    }

    public function testFactoryAutoSelectsPdoWhenExtensionLoaded(): void
    {
        $this->setDriver(null); // auto
        $db = Database::create(getenv('TINA4_TEST_FIREBIRD_URL'));
        $this->assertInstanceOf(PdoFirebirdAdapter::class, $db->getAdapter());
        $db->close();
    }

    public function testFactoryHonoursIbaseOverride(): void
    {
        if (!function_exists('ibase_connect') && !function_exists('fbird_connect')) {
            $this->markTestSkipped('ext-interbase not installed — cannot build the legacy adapter');
        }
        $this->setDriver('ibase');
        try {
            $db = Database::create(getenv('TINA4_TEST_FIREBIRD_URL'));
            $adapter = $db->getAdapter();
            $this->assertInstanceOf(FirebirdAdapter::class, $adapter);
            $this->assertNotInstanceOf(PdoFirebirdAdapter::class, $adapter);
            $db->close();
        } finally {
            $this->setDriver(null);
        }
    }

    /**
     * The core regression. On Firebird 4 SUM(NUMERIC) is an INT128 result that
     * crashes ext-interbase; a crash would take the whole test process down
     * before any assertion runs, so simply completing with the right total
     * proves pdo_firebird reads the widened aggregate.
     */
    public function testSumOverNumericDoesNotCrashOnFirebird4(): void
    {
        $this->db->execute("INSERT INTO {$this->table} (ID, NAME, AMOUNT) VALUES (1, 'a', 100.50)");
        $this->db->execute("INSERT INTO {$this->table} (ID, NAME, AMOUNT) VALUES (2, 'b', 200.00)");

        $bare = $this->db->query("SELECT SUM(AMOUNT) AS S FROM {$this->table}");
        $this->assertEqualsWithDelta(300.50, (float) ($bare[0]['S'] ?? $bare[0]['s'] ?? 0), 0.001);

        // coalesce(sum(...)) also widens to INT128 under FB4.
        $coalesce = $this->db->query("SELECT COALESCE(SUM(AMOUNT), 0) AS S FROM {$this->table}");
        $this->assertEqualsWithDelta(300.50, (float) ($coalesce[0]['S'] ?? $coalesce[0]['s'] ?? 0), 0.001);

        $one = $this->db->fetchOne("SELECT SUM(AMOUNT) AS S FROM {$this->table}");
        $this->assertEqualsWithDelta(300.50, (float) ($one['S'] ?? $one['s'] ?? 0), 0.001);
    }

    public function testInsertFetchUpdateDelete(): void
    {
        $this->db->execute("INSERT INTO {$this->table} (ID, NAME, AMOUNT) VALUES (1, 'alice', 10)");
        $row = $this->db->fetchOne("SELECT NAME FROM {$this->table} WHERE ID = ?", [1]);
        $this->assertSame('alice', $row['NAME'] ?? $row['name']);

        $this->db->execute("UPDATE {$this->table} SET NAME = ? WHERE ID = ?", ['bob', 1]);
        $row = $this->db->fetchOne("SELECT NAME FROM {$this->table} WHERE ID = ?", [1]);
        $this->assertSame('bob', $row['NAME'] ?? $row['name']);

        $this->db->execute("DELETE FROM {$this->table} WHERE ID = ?", [1]);
        $this->assertNull($this->db->fetchOne("SELECT ID FROM {$this->table} WHERE ID = ?", [1]));
    }

    public function testNullParameterBindsAsSqlNull(): void
    {
        // PDO binds a null param as SQL NULL natively — the ibase #123
        // null-rewrite workaround is not needed on this path.
        $this->db->execute("INSERT INTO {$this->table} (ID, NAME, AMOUNT) VALUES (?, ?, ?)", [1, null, 5]);
        $row = $this->db->fetchOne("SELECT NAME FROM {$this->table} WHERE ID = ?", [1]);
        $this->assertIsArray($row);
        $this->assertArrayHasKey('NAME', $row);
        $this->assertNull($row['NAME']);
    }

    public function testFetchPaginatesWithRows(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->db->execute("INSERT INTO {$this->table} (ID, NAME, AMOUNT) VALUES (?, ?, ?)", [$i, "n{$i}", $i]);
        }
        $page = $this->db->fetch("SELECT ID FROM {$this->table} ORDER BY ID", [], 2, 0);
        $this->assertSame(5, $page['total']);
        $this->assertCount(2, $page['data']);
    }

    public function testTransactionCommitAndRollback(): void
    {
        $this->db->startTransaction();
        $this->db->execute("INSERT INTO {$this->table} (ID, NAME, AMOUNT) VALUES (1, 'x', 1)");
        $this->db->commit();
        $this->assertNotNull($this->db->fetchOne("SELECT ID FROM {$this->table} WHERE ID = 1"));

        $this->db->startTransaction();
        $this->db->execute("INSERT INTO {$this->table} (ID, NAME, AMOUNT) VALUES (2, 'y', 2)");
        $this->db->rollback();
        $this->assertNull($this->db->fetchOne("SELECT ID FROM {$this->table} WHERE ID = 2"));
    }

    public function testGetNextIdUsesFirebirdGeneratorOnPdoAdapter(): void
    {
        // Through the Database facade so the Firebird generator branch
        // (guarded by `instanceof FirebirdAdapter`) runs against the PDO adapter.
        $this->setDriver('pdo');
        try {
            $db = Database::create(getenv('TINA4_TEST_FIREBIRD_URL'));
            $this->assertInstanceOf(PdoFirebirdAdapter::class, $db->getAdapter());
            $a = $db->getNextId($this->table, 'ID', $this->gen);
            $b = $db->getNextId($this->table, 'ID', $this->gen);
            $this->assertSame($a + 1, $b);
            $db->close();
        } finally {
            $this->setDriver(null);
        }
    }
}
