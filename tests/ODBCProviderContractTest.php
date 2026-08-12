<?php

/**
 * ODBC provider contract -- feature 13 (ODBC-DEC-01 provision a REAL ODBC source
 * + run the shared write-path fixture through it; ODBC-DEC-02 the latent fixes).
 *
 * Pins the ODBC write-path behaviours against a REAL ODBC source, no mocks. The
 * SAME cases are proven in all four frameworks; the shared fixture is
 * tina4-documentation/plan/v3/fixtures/odbcprovider_contract.json.
 *
 * ODBC is the generic escape hatch; its query/CRUD path had NEVER run against a
 * real ODBC source, so every finding was latent. The lab provisions unixODBC +
 * the PostgreSQL ODBC driver (psqlodbc), and this suite drives the write path.
 *
 *   * ODBC-PK-STUB: getColumns() reported primaryKey=false for every column, so a
 *     PK-keyed update(table, data) with no explicit filter could not introspect
 *     the key and threw. Fixed to read the PK from INFORMATION_SCHEMA.
 *   * ODBC-FAILLOUD: fetch()/fetchOne() SWALLOWED errors to an empty result while
 *     execute() raised. Fixed to fail loud like PostgresAdapter.
 *   * ODBC-NODE-QUIRKS (cross-language lock): a string-WHERE update targets the
 *     right rows.
 *
 * Env: TINA4_TEST_ODBC_DSN is the connection-string body (after odbc:///) for a
 * loopback source; unset -> skip. TINA4_TEST_ODBC_AUTH_DSN (a password-protected
 * source, no UID/PWD in the string) + TINA4_TEST_ODBC_USERNAME/_PASSWORD drive
 * the credentials case.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

class ODBCProviderContractTest extends TestCase
{
    private string $dsn = '';

    protected function setUp(): void
    {
        $this->dsn = getenv('TINA4_TEST_ODBC_DSN') ?: '';
        if ($this->dsn === '') {
            $this->markTestSkipped('TINA4_TEST_ODBC_DSN not set (needs a live ODBC source)');
        }
    }

    private function db(): Database
    {
        return Database::create('odbc:///' . $this->dsn);
    }

    /** A plain table with an explicit INTEGER primary key. ODBC has no reliable
     *  last-insert-id, so the write-path fixture supplies the key explicitly -
     *  which is exactly what exercises the PK-catalog introspection. */
    private function makeTable(Database $db, string $name, string $pk = 'id'): void
    {
        $db->execute("DROP TABLE IF EXISTS {$name}");
        $db->execute("CREATE TABLE {$name} ({$pk} INTEGER PRIMARY KEY, name VARCHAR(50))");
    }

    // ---- ODBC-DEC-01: SELECT round-trips real rows ----------------------

    public function testASelectReturnsRows(): void
    {
        $db = $this->db();
        $this->makeTable($db, 'odbc_php_select');
        $db->insert('odbc_php_select', ['id' => 1, 'name' => 'alpha']);
        $db->insert('odbc_php_select', ['id' => 2, 'name' => 'beta']);
        $db->insert('odbc_php_select', ['id' => 3, 'name' => 'gamma']);
        $result = $db->fetch('SELECT id, name FROM odbc_php_select ORDER BY id');
        $names = array_map(static fn($r) => $r['name'], $result->records);
        $this->assertSame(['alpha', 'beta', 'gamma'], $names);
        $db->close();
    }

    public function testAnInsertRoundTripsOnAFreshConnection(): void
    {
        $db = $this->db();
        $this->makeTable($db, 'odbc_php_ins');
        $db->insert('odbc_php_ins', ['id' => 1, 'name' => 'x']);
        // Durable + keyed, read back on a SECOND fresh connection.
        $reader = $this->db();
        $row = $reader->fetchOne('SELECT name FROM odbc_php_ins WHERE id = ?', [1]);
        $this->assertNotNull($row);
        $this->assertSame('x', $row['name']);
        $reader->close();
        $db->close();
    }

    // ---- ODBC-PK-STUB: a PK-keyed update introspects the real PK --------

    public function testAPkKeyedUpdateWithNoFilterWorks(): void
    {
        $db = $this->db();
        $this->makeTable($db, 'odbc_php_pk');
        $db->insert('odbc_php_pk', ['id' => 1, 'name' => 'one']);
        $db->insert('odbc_php_pk', ['id' => 2, 'name' => 'two']);
        // No explicit filter: the guard introspects the PK via the ODBC catalog
        // (was hardcoded false -> threw).
        $db->update('odbc_php_pk', ['id' => 1, 'name' => 'ONE']);
        $rows = [];
        foreach ($db->fetch('SELECT id, name FROM odbc_php_pk')->records as $r) {
            $rows[(int)$r['id']] = $r['name'];
        }
        $this->assertSame('ONE', $rows[1], 'the PK-keyed row was not updated');
        $this->assertSame('two', $rows[2], 'a non-matching row was changed');
        $db->close();
    }

    public function testANonIdPrimaryKeyUpdateWorks(): void
    {
        $db = $this->db();
        $this->makeTable($db, 'odbc_php_pk2', 'thing_key');
        $db->insert('odbc_php_pk2', ['thing_key' => 7, 'name' => 'seven']);
        $db->update('odbc_php_pk2', ['thing_key' => 7, 'name' => 'SEVEN']);
        $row = $db->fetchOne('SELECT name FROM odbc_php_pk2 WHERE thing_key = ?', [7]);
        $this->assertNotNull($row);
        $this->assertSame('SEVEN', $row['name']);
        $db->close();
    }

    // ---- feature 4: a filterless write is guarded -----------------------

    public function testAFilterlessUpdateIsGuarded(): void
    {
        $db = $this->db();
        $this->makeTable($db, 'odbc_php_guard');
        $db->insert('odbc_php_guard', ['id' => 1, 'name' => 'keep']);
        $threw = false;
        try {
            $db->update('odbc_php_guard', ['name' => 'WIPED']);
        } catch (\Throwable) {
            $threw = true;
        }
        $this->assertTrue($threw, 'a filterless update was not guarded');
        $row = $db->fetchOne('SELECT name FROM odbc_php_guard WHERE id = ?', [1]);
        $this->assertSame('keep', $row['name'], 'the guard let a filterless update through');
        $db->close();
    }

    public function testAFilterlessDeleteIsGuarded(): void
    {
        $db = $this->db();
        $this->makeTable($db, 'odbc_php_guard_del');
        $db->insert('odbc_php_guard_del', ['id' => 1, 'name' => 'keep']);
        $threw = false;
        try {
            $db->delete('odbc_php_guard_del');
        } catch (\Throwable) {
            $threw = true;
        }
        $this->assertTrue($threw, 'a filterless delete was not guarded');
        $this->assertCount(1, $db->fetch('SELECT id FROM odbc_php_guard_del')->records);
        $db->close();
    }

    // ---- ODBC-FAILLOUD: a fetch on a bad query RAISES -------------------

    public function testAFetchOnABadQueryFailsLoud(): void
    {
        $db = $this->db();
        $threwFetch = false;
        try {
            $db->fetch('SELECT * FROM odbc_php_table_that_does_not_exist_xyz');
        } catch (\Throwable) {
            $threwFetch = true;
        }
        $this->assertTrue($threwFetch, 'fetch() swallowed a bad query to an empty result');

        $threwOne = false;
        try {
            $db->fetchOne('SELECT * FROM odbc_php_table_that_does_not_exist_xyz');
        } catch (\Throwable) {
            $threwOne = true;
        }
        $this->assertTrue($threwOne, 'fetchOne() swallowed a bad query to null');

        // A good query on the same connection still works.
        $row = $db->fetchOne('SELECT 1 AS one');
        $this->assertSame(1, (int)$row['one']);
        $db->close();
    }

    // ---- ODBC-NODE-QUIRKS (cross-language lock): string-WHERE update ----

    public function testAStringWhereUpdateTargetsTheRightRows(): void
    {
        $db = $this->db();
        $this->makeTable($db, 'odbc_php_strwhere');
        for ($i = 1; $i <= 4; $i++) {
            $db->insert('odbc_php_strwhere', ['id' => $i, 'name' => 'orig']);
        }
        $db->update('odbc_php_strwhere', ['name' => 'Z'], 'id <= ?', [3]);
        $rows = [];
        foreach ($db->fetch('SELECT id, name FROM odbc_php_strwhere')->records as $r) {
            $rows[(int)$r['id']] = $r['name'];
        }
        $this->assertSame('Z', $rows[1]);
        $this->assertSame('Z', $rows[2]);
        $this->assertSame('Z', $rows[3]);
        $this->assertSame('orig', $rows[4], 'the string WHERE clause changed the wrong rows');
        $db->close();
    }

    // ---- credentials are honoured ---------------------------------------

    public function testCredentialsPassedAsParamsAreHonoured(): void
    {
        $authDsn = getenv('TINA4_TEST_ODBC_AUTH_DSN') ?: '';
        $user = getenv('TINA4_TEST_ODBC_USERNAME') ?: '';
        $pass = getenv('TINA4_TEST_ODBC_PASSWORD') ?: '';
        if ($authDsn === '' || $user === '' || $pass === '') {
            $this->markTestSkipped('TINA4_TEST_ODBC_AUTH_DSN / _USERNAME / _PASSWORD not set');
        }
        // The connection string carries NO UID/PWD; the creds arrive only as
        // params. Honoured -> it connects to the password-protected source.
        $db = Database::create('odbc:///' . $authDsn, username: $user, password: $pass);
        $row = $db->fetchOne('SELECT 1 AS one');
        $this->assertSame(1, (int)$row['one']);
        $db->close();
    }

    public function testAWrongPasswordFailsLoud(): void
    {
        $authDsn = getenv('TINA4_TEST_ODBC_AUTH_DSN') ?: '';
        $user = getenv('TINA4_TEST_ODBC_USERNAME') ?: '';
        if ($authDsn === '' || $user === '') {
            $this->markTestSkipped('TINA4_TEST_ODBC_AUTH_DSN / _USERNAME not set');
        }
        $this->expectException(\Throwable::class);
        Database::create('odbc:///' . $authDsn, username: $user, password: 'definitely-the-wrong-password');
    }
}
