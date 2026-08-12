<?php

/**
 * Firebird provider contract -- feature 12 (FB-DEC-01/02/03).
 *
 * Pins the Firebird write-path + resilience behaviours against a REAL Firebird 5,
 * no mocks. The SAME cases are proven in all four frameworks; the shared fixture
 * is tina4-documentation/plan/v3/fixtures/firebirdprovider_contract.json.
 *
 *   * FB-DEC-02: db.insert() returns the GENERATOR-assigned last-id (non-`id` PK
 *     too, via native RETURNING *); update/delete report the REAL affected count
 *     (ibase_affected_rows on the transaction the statement ran on).
 *   * FB-DEC-03: a binary blob round-trips byte-for-byte.
 *   * FB-DEC-01: a forced server-side disconnect transparently reconnects; a
 *     logical SQL error does NOT.
 *
 * Firebird has no generic last_insert_id, so each table is created with a
 * GEN_<TABLE>_ID generator + a BEFORE INSERT trigger. TINA4_TEST_FIREBIRD_URL
 * unset -> skip. Database::create picks the native FirebirdAdapter on the lab.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

class FirebirdProviderContractTest extends TestCase
{
    /** A NUL byte, high bytes 0xFD..0xFF, an embedded NUL, and ASCII. */
    private const BLOB = "\x00\x01\x02\xFD\xFE\xFF\x41\x42\x00\x43";

    private string $url = '';

    protected function setUp(): void
    {
        $this->url = getenv('TINA4_TEST_FIREBIRD_URL') ?: '';
        if ($this->url === '') {
            $this->markTestSkipped('TINA4_TEST_FIREBIRD_URL not set (needs a live Firebird)');
        }
    }

    private function db(): Database
    {
        return Database::create($this->url);
    }

    /** A table whose PK is assigned by a GEN_<TABLE>_ID generator via a BEFORE
     *  INSERT trigger -- the real Firebird auto-key idiom. */
    private function makeTable(Database $db, string $name, string $pk = 'id', string $extra = ''): void
    {
        foreach (["DROP TRIGGER {$name}_bi", "DROP TABLE {$name}", "DROP GENERATOR gen_{$name}_id"] as $sql) {
            try {
                $db->execute($sql);
            } catch (\Throwable) {
                // not present yet
            }
        }
        $cols = "{$pk} INTEGER NOT NULL PRIMARY KEY, name VARCHAR(50)";
        if ($extra !== '') {
            $cols .= ", {$extra}";
        }
        $db->execute("CREATE TABLE {$name} ({$cols})");
        $db->execute("CREATE GENERATOR gen_{$name}_id");
        $db->execute(
            "CREATE TRIGGER {$name}_bi FOR {$name} ACTIVE BEFORE INSERT POSITION 0 "
            . "AS BEGIN IF (NEW.{$pk} IS NULL) THEN NEW.{$pk} = GEN_ID(gen_{$name}_id, 1); END"
        );
    }

    // ---- FB-DEC-02: generator last-id -----------------------------------

    public function testAnInsertReturnsTheGeneratorLastId(): void
    {
        $db = $this->db();
        $this->makeTable($db, 'phc_gen');
        $result = $db->insert('phc_gen', ['name' => 'alpha']);
        $this->assertSame(1, (int)$result->lastId);
        $db->close();
    }

    public function testASecondInsertReturnsTheNextGeneratedId(): void
    {
        $db = $this->db();
        $this->makeTable($db, 'phc_gen2');
        $first = $db->insert('phc_gen2', ['name' => 'a']);
        $second = $db->insert('phc_gen2', ['name' => 'b']);
        $this->assertSame(1, (int)$first->lastId);
        $this->assertSame(2, (int)$second->lastId, 'expected the NEXT generated id');
        // Durable + correctly keyed, read on a fresh connection.
        $row = $this->db()->fetchOne('SELECT name FROM phc_gen2 WHERE id = ?', [2]);
        $this->assertSame('b', $row['name']);
        $db->close();
    }

    public function testAnInsertReportsAffectedRowsOfOne(): void
    {
        $db = $this->db();
        $this->makeTable($db, 'phc_ins1');
        $this->assertSame(1, (int)$db->insert('phc_ins1', ['name' => 'x'])->affectedRows);
        $db->close();
    }

    // ---- FB-DEC-02: real affected count ---------------------------------

    public function testAMultiRowUpdateReportsTheRealAffectedCount(): void
    {
        $db = $this->db();
        $this->makeTable($db, 'phc_upd');
        foreach (['a', 'b', 'c', 'd'] as $name) {
            $db->insert('phc_upd', ['name' => $name]);
        }
        $this->assertSame(3, (int)$db->update('phc_upd', ['name' => 'Z'], 'id <= ?', [3])->affectedRows);
        $db->close();
    }

    public function testAnUpdateMatchingNoRowsReportsZeroAffected(): void
    {
        $db = $this->db();
        $this->makeTable($db, 'phc_upd0');
        $db->insert('phc_upd0', ['name' => 'a']);
        $this->assertSame(0, (int)$db->update('phc_upd0', ['name' => 'Z'], 'name = ?', ['nope'])->affectedRows);
        $db->close();
    }

    public function testADeleteReportsTheRealAffectedCount(): void
    {
        $db = $this->db();
        $this->makeTable($db, 'phc_del');
        foreach (['a', 'b', 'c'] as $name) {
            $db->insert('phc_del', ['name' => $name]);
        }
        $this->assertSame(2, (int)$db->delete('phc_del', 'id <= ?', [2])->affectedRows);
        $db->close();
    }

    // ---- FB-DEC-03: blob round-trip -------------------------------------

    public function testABinaryBlobRoundTripsByteForByte(): void
    {
        $db = $this->db();
        $this->makeTable($db, 'phc_blob', 'id', 'payload BLOB SUB_TYPE 0');
        $db->insert('phc_blob', ['name' => 'b', 'payload' => self::BLOB]);
        // Read back on a fresh connection.
        $row = $this->db()->fetchOne('SELECT payload FROM phc_blob WHERE name = ?', ['b']);
        $this->assertSame(bin2hex(self::BLOB), bin2hex((string)$row['payload']), 'blob corrupted');
        $db->close();
    }

    // ---- FB-DEC-01: real reconnect --------------------------------------

    public function testAForcedDisconnectReconnectsAndTheNextQuerySucceeds(): void
    {
        $db = $this->db();
        $this->makeTable($db, 'phc_recon');
        $db->insert('phc_recon', ['name' => 'before']);
        // The adapter's OWN attachment id, then kill it server-side from a SECOND
        // connection -- a genuine forced disconnect, no mock. ext-interbase shares
        // ONE native link per (path,user,pass,charset), so the killer uses a
        // DIFFERENT charset to be a genuinely separate attachment.
        $connId = $db->fetchOne('SELECT CURRENT_CONNECTION AS c FROM RDB$DATABASE')['c'];
        $killerUrl = $this->url . (str_contains($this->url, '?') ? '&' : '?') . 'charset=NONE';
        $killer = Database::create($killerUrl);
        $killer->execute('DELETE FROM MON$ATTACHMENTS WHERE MON$ATTACHMENT_ID = ?', [$connId]);
        $killer->close();
        // The next query on the dead attachment must transparently reconnect + succeed.
        $row = $db->fetchOne('SELECT COUNT(*) AS n FROM phc_recon');
        $this->assertSame(1, (int)$row['n'], 'adapter did not reconnect after a forced disconnect');
        $db->close();
    }

    public function testALogicalSqlErrorDoesNotTriggerAReconnect(): void
    {
        // The dead-connection matcher must classify a real SQL error as NOT dead,
        // so a syntax/logical error surfaces instead of a reconnect loop.
        $this->assertFalse(\Tina4\Database\FirebirdAdapter::isDeadConnection('Dynamic SQL Error: syntax error at line 1'));
        $this->assertFalse(\Tina4\Database\FirebirdAdapter::isDeadConnection('Table PHC_NOPE does not exist'));
        // And a genuinely bad query RAISES through the adapter (does not silently retry).
        $db = $this->db();
        $threw = false;
        try {
            $db->fetchOne('SELECT * FROM phc_table_that_does_not_exist_xyz');
        } catch (\Throwable) {
            $threw = true;
        }
        $this->assertTrue($threw, 'a logical SQL error must raise');
        // The connection is still usable -- no spurious reconnect churn broke it.
        $this->assertSame(1, (int)$db->fetchOne('SELECT 1 AS x FROM RDB$DATABASE')['x']);
        $db->close();
    }

    // ---- FB-DEC-02: non-`id` primary key --------------------------------

    public function testANonIdPrimaryKeyInsertReturnsTheGeneratedLastId(): void
    {
        $db = $this->db();
        $this->makeTable($db, 'phc_thing', 'thing_key');
        // The generator-derived last-id is column-name-independent, so it is
        // correct even though the PK is not named `id`.
        $this->assertSame(1, (int)$db->insert('phc_thing', ['name' => 'hi'])->lastId);
        $db->close();
    }
}
