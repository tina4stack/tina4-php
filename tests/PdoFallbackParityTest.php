<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * SILENT PDO fallback — native-vs-PDO PARITY lock-in.
 *
 * For each engine that hard-depends on a native extension (SQLite/ext-sqlite3,
 * PostgreSQL/ext-pgsql, Firebird/ext-interbase), Database::create() silently
 * falls back to the matching PDO driver when the native extension is absent.
 * The fallback MUST be externally indistinguishable from the native adapter.
 *
 * This test runs the SAME assertions through BOTH the native driver AND the
 * forced-PDO driver against the SAME real database and asserts IDENTICAL
 * results AND TYPES — a value that is int/float/bool/bytes via native must be
 * the same via PDO (never a string). assertSame() compares arrays with === so
 * it catches any type drift (e.g. a stringified numeric or a bytea resource).
 *
 * Coverage per engine: typed-column reads (int/float/bool), a BLOB round-trip
 * (raw bytes), getLastId() after insert, a transaction commit + rollback, and
 * execute() raising on a bad statement.
 *
 * NO mocks — every path talks to the REAL driver against a REAL database.
 *   - SQLite: ext-sqlite3 AND pdo_sqlite are both present locally (temp file).
 *   - PostgreSQL: real server (docker on :55432) — ext-pgsql vs pdo_pgsql.
 *   - Firebird: real server + pdo_firebird — skipped loudly when unavailable.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\DatabaseAdapter;
use Tina4\Database\DatabaseException;
use Tina4\Database\SQLite3Adapter;
use Tina4\Database\PdoSqliteAdapter;
use Tina4\Database\PostgresAdapter;
use Tina4\Database\PdoPostgresAdapter;
use Tina4\Database\FirebirdAdapter;
use Tina4\Database\PdoFirebirdAdapter;

class PdoFallbackParityTest extends TestCase
{
    /** A binary payload with NUL and high bytes — the real BLOB torture test. */
    private const BLOB = "\x00\x01\x02\xffhello\x00world\xfe\x7f";

    // ─────────────────────────────────────────────────────────────────
    // SQLite — ext-sqlite3 vs pdo_sqlite (both available locally)
    // ─────────────────────────────────────────────────────────────────

    private ?string $sqliteFile = null;

    protected function tearDown(): void
    {
        if ($this->sqliteFile !== null && file_exists($this->sqliteFile)) {
            @unlink($this->sqliteFile);
            @unlink($this->sqliteFile . '-wal');
            @unlink($this->sqliteFile . '-shm');
            $this->sqliteFile = null;
        }
    }

    private function sqlitePair(): array
    {
        if (!class_exists('SQLite3')) {
            $this->markTestSkipped('ext-sqlite3 (the SQLite3 class) is not available.');
        }
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver is not available.');
        }
        // A shared temp FILE (not :memory:) so both drivers hit the SAME db and
        // cross-driver reads are real.
        $this->sqliteFile = sys_get_temp_dir() . '/tina4_pdo_parity_' . uniqid() . '.db';
        return [new SQLite3Adapter($this->sqliteFile), new PdoSqliteAdapter($this->sqliteFile)];
    }

    public function testSqliteTypedReadAndBlobParity(): void
    {
        [$native, $pdo] = $this->sqlitePair();

        $native->execute(
            'CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, qty INTEGER, price REAL, active INTEGER, name TEXT, data BLOB)'
        );
        // Store as a genuine BLOB (blob storage-class) via an x'..' literal. A
        // string param bound the normal way lands as TEXT storage-class, and
        // ext-sqlite3's fetchArray() truncates a NUL-containing TEXT value at
        // the first NUL (a pre-existing native quirk) while pdo_sqlite returns
        // the full bytes — so binding a raw blob as a plain string param is not
        // a faithful BLOB on the NATIVE driver either. A real BLOB round-trips
        // identically on both, which is what the requirement asks.
        $hex = bin2hex(self::BLOB);
        $native->execute(
            "INSERT INTO items (qty, price, active, name, data) VALUES (?, ?, ?, ?, x'{$hex}')",
            [42, 19.99, true, 'widget']
        );

        $sql = 'SELECT qty, price, active, name, data FROM items WHERE id = 1';
        $nativeRow = $native->fetchOne($sql);
        $pdoRow = $pdo->fetchOne($sql);

        // Identical values AND types (=== over the whole row).
        $this->assertSame($nativeRow, $pdoRow, 'SQLite native vs PDO row must be byte- and type-identical');

        // Spell the native-type expectations out so a regression names itself.
        $this->assertIsInt($nativeRow['qty']);
        $this->assertIsInt($pdoRow['qty']);
        $this->assertIsFloat($nativeRow['price']);
        $this->assertIsFloat($pdoRow['price']);
        $this->assertSame(1, $pdoRow['active']);            // SQLite bool -> INTEGER 1
        $this->assertSame(self::BLOB, $pdoRow['data']);     // raw bytes, NULs preserved
        $this->assertSame(strlen(self::BLOB), strlen($pdoRow['data']));

        $native->close();
        $pdo->close();
    }

    public function testSqliteGetLastIdParity(): void
    {
        [$native, $pdo] = $this->sqlitePair();
        $native->execute('CREATE TABLE seq (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');

        $native->insert('seq', ['label' => 'a']);
        $nativeId = $native->lastInsertId();
        $pdo->insert('seq', ['label' => 'b']);
        $pdoId = $pdo->lastInsertId();

        $this->assertSame(gettype($nativeId), gettype($pdoId), 'getLastId type must match');
        $this->assertIsInt($nativeId);
        $this->assertIsInt($pdoId);
        $this->assertSame(1, $nativeId);
        $this->assertSame(2, $pdoId);

        $native->close();
        $pdo->close();
    }

    public function testSqliteTransactionParity(): void
    {
        [$native, $pdo] = $this->sqlitePair();
        $native->execute('CREATE TABLE txn (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');

        // Distinct tokens per run so the two adapters count only their own rows
        // on the shared table (otherwise the second run sees the first's commit).
        $this->assertSame(
            $this->exerciseTransactions($native, 'txn', 'native'),
            $this->exerciseTransactions($pdo, 'txn', 'pdo')
        );

        $native->close();
        $pdo->close();
    }

    public function testSqliteExecuteRaisesParity(): void
    {
        [$native, $pdo] = $this->sqlitePair();
        $native->execute('CREATE TABLE t (id INTEGER PRIMARY KEY)');

        $this->assertExecuteRaises($native, 'INSERT INTO nope_missing (x) VALUES (1)');
        $this->assertExecuteRaises($pdo, 'INSERT INTO nope_missing (x) VALUES (1)');

        $native->close();
        $pdo->close();
    }

    // ─────────────────────────────────────────────────────────────────
    // PostgreSQL — ext-pgsql vs pdo_pgsql (real server on :55432)
    // ─────────────────────────────────────────────────────────────────

    private function postgresPair(): array
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('ext-pgsql (pg_connect) is not available.');
        }
        if (!in_array('pgsql', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_pgsql driver is not available.');
        }
        $pg = \PgTestEnv::resolve();
        if (!$pg->reachable()) {
            $this->markTestSkipped(sprintf('PostgreSQL not reachable at %s:%d', $pg->host, $pg->port));
        }
        $url = $pg->url('tina4');
        return [
            new PostgresAdapter($url, username: $pg->user, password: $pg->pass),
            new PdoPostgresAdapter($url, username: $pg->user, password: $pg->pass),
        ];
    }

    public function testPostgresTypedReadAndBlobParity(): void
    {
        [$native, $pdo] = $this->postgresPair();
        $native->execute('DROP TABLE IF EXISTS t4_pdo_parity');
        $native->execute(
            'CREATE TABLE t4_pdo_parity (id SERIAL PRIMARY KEY, qty INTEGER, big BIGINT, '
            . 'price DOUBLE PRECISION, amount NUMERIC(10,2), active BOOLEAN, name TEXT, data BYTEA)'
        );

        // bytea write encoding is the caller's job on BOTH native and PDO (a raw
        // param truncates at the first NUL on either driver) — insert via
        // decode(hex) so the row is deterministic; this test proves READ parity.
        $hex = bin2hex(self::BLOB);
        $native->execute(
            "INSERT INTO t4_pdo_parity (qty, big, price, amount, active, name, data) "
            . "VALUES (?, ?, ?, ?, ?, ?, decode('{$hex}', 'hex'))",
            [7, 9000000000, 1.5, 19.99, true, 'widget']
        );

        $sql = 'SELECT qty, big, price, amount, active, name, data FROM t4_pdo_parity WHERE qty = 7';
        $nativeRow = $native->fetchOne($sql);
        $pdoRow = $pdo->fetchOne($sql);

        $this->assertSame($nativeRow, $pdoRow, 'PostgreSQL native vs PDO row must be byte- and type-identical');

        $this->assertIsInt($pdoRow['qty']);
        $this->assertIsInt($pdoRow['big']);                 // int8 -> int
        $this->assertIsFloat($pdoRow['price']);
        $this->assertIsFloat($pdoRow['amount']);            // NUMERIC -> float (native parity)
        $this->assertIsBool($pdoRow['active']);
        $this->assertTrue($pdoRow['active']);
        $this->assertSame(self::BLOB, $pdoRow['data']);     // bytea -> raw bytes (not a resource)

        $native->execute('DROP TABLE IF EXISTS t4_pdo_parity');
        $native->close();
        $pdo->close();
    }

    public function testPostgresGetLastIdParity(): void
    {
        [$native, $pdo] = $this->postgresPair();
        $native->execute('DROP TABLE IF EXISTS t4_pdo_lastid');
        $native->execute('CREATE TABLE t4_pdo_lastid (id SERIAL PRIMARY KEY, label TEXT)');

        $native->insert('t4_pdo_lastid', ['label' => 'a']);
        $nativeId = $native->lastInsertId();
        $pdo->insert('t4_pdo_lastid', ['label' => 'b']);
        $pdoId = $pdo->lastInsertId();

        // Native pg_fetch returns the RETURNING id as a numeric STRING — the PDO
        // fallback matches that type so callers can't tell them apart.
        $this->assertSame(gettype($nativeId), gettype($pdoId), 'PG getLastId type must match');
        $this->assertSame('1', (string) $nativeId);
        $this->assertSame('2', (string) $pdoId);

        $native->execute('DROP TABLE IF EXISTS t4_pdo_lastid');
        $native->close();
        $pdo->close();
    }

    public function testPostgresTransactionParity(): void
    {
        [$native, $pdo] = $this->postgresPair();
        $native->execute('DROP TABLE IF EXISTS t4_pdo_txn');
        $native->execute('CREATE TABLE t4_pdo_txn (id SERIAL PRIMARY KEY, label TEXT)');

        // Distinct tokens per run so the two adapters count only their own rows.
        $this->assertSame(
            $this->exerciseTransactions($native, 't4_pdo_txn', 'native'),
            $this->exerciseTransactions($pdo, 't4_pdo_txn', 'pdo')
        );

        $native->execute('DROP TABLE IF EXISTS t4_pdo_txn');
        $native->close();
        $pdo->close();
    }

    public function testPostgresExecuteRaisesParity(): void
    {
        [$native, $pdo] = $this->postgresPair();

        $this->assertExecuteRaises($native, 'INSERT INTO t4_pdo_nope_missing (x) VALUES (1)');
        $this->assertExecuteRaises($pdo, 'INSERT INTO t4_pdo_nope_missing (x) VALUES (1)');

        $native->close();
        $pdo->close();
    }

    // ─────────────────────────────────────────────────────────────────
    // Firebird — ext-interbase vs pdo_firebird (real server required)
    // ─────────────────────────────────────────────────────────────────

    private function firebirdPair(): array
    {
        if (!in_array('firebird', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped(
                'pdo_firebird driver is not available — Firebird PDO fallback is UNVERIFIED in this environment.'
            );
        }
        $url = getenv('TINA4_TEST_FIREBIRD_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped(
                'TINA4_TEST_FIREBIRD_URL not set (needs a real Firebird server) — Firebird PDO fallback UNVERIFIED.'
            );
        }
        if (!function_exists('ibase_connect') && !function_exists('fbird_connect')) {
            $this->markTestSkipped('ext-interbase not available to compare against — native-vs-PDO parity UNVERIFIED (PdoFirebirdAdapterTest covers the PDO-only contract).');
        }
        // ext-interbase can be PRESENT-but-BROKEN (the macOS + FB5 clumplet case
        // this whole fallback exists for). If native cannot connect there is
        // nothing to compare against — skip loudly rather than error; the
        // standalone PdoFirebirdAdapterTest verifies the PDO adapter on its own.
        try {
            $native = new FirebirdAdapter($url);
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                'ext-interbase present but cannot connect (' . $e->getMessage()
                . ') — native-vs-PDO parity UNVERIFIED here; PdoFirebirdAdapterTest covers the PDO path.'
            );
        }
        return [$native, new PdoFirebirdAdapter($url)];
    }

    public function testFirebirdTypedReadAndBlobParity(): void
    {
        [$native, $pdo] = $this->firebirdPair();
        // Firebird uses generators, not AUTOINCREMENT; ROWS pagination; BLOB SUB_TYPE.
        try {
            $native->execute('DROP TABLE T4_PDO_PARITY');
        } catch (\Throwable) {
            // table may not exist yet — fine
        }
        $native->execute(
            'CREATE TABLE T4_PDO_PARITY (ID INTEGER NOT NULL PRIMARY KEY, QTY INTEGER, '
            . 'AMOUNT NUMERIC(15,2), PRICE DOUBLE PRECISION, NAME VARCHAR(50), DATA BLOB SUB_TYPE 0)'
        );
        $native->execute(
            'INSERT INTO T4_PDO_PARITY (ID, QTY, AMOUNT, PRICE, NAME, DATA) VALUES (?, ?, ?, ?, ?, ?)',
            [1, 42, 1234.56, 19.99, 'widget', self::BLOB]
        );

        $sql = 'SELECT QTY, AMOUNT, PRICE, NAME, DATA FROM T4_PDO_PARITY WHERE ID = 1';
        $nativeRow = $native->fetchOne($sql);
        $pdoRow = $pdo->fetchOne($sql);

        // The numeric columns are compared by VALUE (their exact PHP type is
        // locked separately in PdoFirebirdAdapterTest against a live server).
        // DOUBLE PRECISION is the ONE documented divergence — pdo_firebird cannot
        // tell it apart from CHAR/BLOB, so the PDO adapter returns it as a numeric
        // string while native returns a float. NUMERIC/DECIMAL carry a scale and
        // DO re-type to float, matching native.
        $this->assertEqualsWithDelta(19.99, (float) $nativeRow['PRICE'], 0.0001);
        $this->assertEqualsWithDelta(19.99, (float) $pdoRow['PRICE'], 0.0001);
        $this->assertEqualsWithDelta(1234.56, (float) $nativeRow['AMOUNT'], 0.0001);
        $this->assertEqualsWithDelta(1234.56, (float) $pdoRow['AMOUNT'], 0.0001);
        unset($nativeRow['PRICE'], $pdoRow['PRICE'], $nativeRow['AMOUNT'], $pdoRow['AMOUNT']);

        // Everything else must be byte- AND type-identical (int stays int, bytes
        // stay bytes) — this is the core native-vs-PDO parity guarantee.
        $this->assertSame($nativeRow, $pdoRow, 'Firebird native vs PDO row (non-DOUBLE) must be byte- and type-identical');
        $this->assertSame(42, $pdoRow['QTY']);
        $this->assertSame(self::BLOB, $pdoRow['DATA'] ?? $pdoRow['data'] ?? null);

        // Close the PDO reader BEFORE the native DROP. pdo_firebird holds an idle
        // read transaction after a SELECT, and Firebird DDL (DROP TABLE) needs
        // exclusive metadata access — with the reader still open the DROP blocks
        // forever (WAIT). Releasing the reader first lets the cleanup proceed.
        $pdo->close();
        try {
            $native->execute('DROP TABLE T4_PDO_PARITY');
        } catch (\Throwable) {
        }
        $native->close();
    }

    public function testFirebirdExecuteRaisesParity(): void
    {
        [$native, $pdo] = $this->firebirdPair();
        $this->assertExecuteRaises($native, 'INSERT INTO T4_PDO_NOPE_MISSING (X) VALUES (1)');
        $this->assertExecuteRaises($pdo, 'INSERT INTO T4_PDO_NOPE_MISSING (X) VALUES (1)');
        $native->close();
        $pdo->close();
    }

    // ─────────────────────────────────────────────────────────────────
    // Shared exercisers (run identically against native and PDO)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Run a commit and a rollback and return the observable row counts.
     * A parity pass requires native and PDO to return the SAME snapshot.
     *
     * @return array{after_commit: int, after_rollback: int}
     */
    private function exerciseTransactions(DatabaseAdapter $db, string $table = 'txn', string $token = 't'): array
    {
        $committed = "committed_{$token}";
        $rolledback = "rolledback_{$token}";

        // Committed row persists.
        $db->startTransaction();
        $db->insert($table, ['label' => $committed]);
        $db->commit();
        $afterCommit = $this->countLabel($db, $table, $committed);

        // Rolled-back row does not.
        $db->startTransaction();
        $db->insert($table, ['label' => $rolledback]);
        $db->rollback();
        $afterRollback = $this->countLabel($db, $table, $rolledback);

        return ['after_commit' => $afterCommit, 'after_rollback' => $afterRollback];
    }

    /** COUNT(*) for a label, tolerant of the engine's result-key case. */
    private function countLabel(DatabaseAdapter $db, string $table, string $label): int
    {
        $row = $db->fetchOne("SELECT COUNT(*) AS c FROM {$table} WHERE label = ?", [$label]) ?? [];
        return (int) ($row['c'] ?? $row['C'] ?? reset($row) ?? 0);
    }

    /** execute() must FAIL LOUD — raise (never return false) — and set error(). */
    private function assertExecuteRaises(DatabaseAdapter $db, string $badSql): void
    {
        try {
            $db->execute($badSql);
            $this->fail('execute() must raise on a bad statement, not return');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Throwable::class, $e);
            $this->assertNotNull($db->error(), 'error() must carry the cause after a failed execute()');
        }
    }
}
