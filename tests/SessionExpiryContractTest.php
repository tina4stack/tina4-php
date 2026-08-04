<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Session expiry contract — the regression gates for the session-backend
 * defect batch measured on v3 (2026-08-01).
 *
 * THE CONTRACT (settled against real mainstream implementations, not internal
 * precedent — PHP's own native files handler, Django, Rails, Laravel,
 * express-session, connect-mongo and connect-redis were all measured, and NONE
 * of them deletes a record that carries no expiry):
 *
 *   1. An expiry stamp that is ABSENT or ZERO means "never expires". It is
 *      guarded OUT of the comparison, never fed INTO it, and the record is
 *      returned and kept.
 *   2. A stamp that is genuinely PRESENT and in the PAST still expires the
 *      record, and the record is still reaped. Fixing (1) must not turn data
 *      loss into a sessions-never-expire security bug — every positive gate
 *      here is paired with a negative control that proves expiry still works.
 *   3. The PERSISTENCE LAYER owns the stamp, never the caller's payload.
 *   4. The ttl is consumed at WRITE time; nothing at read time needs it.
 *
 * NO MOCKS. The file gates use a real temp directory; the database gates use a
 * real SQL engine. Every out-of-band assertion (did the record survive?) is
 * made with an INDEPENDENT client — a directory listing or raw PDO — never
 * through the code under test.
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Session;
use Tina4\Session\DatabaseSessionHandler;

class SessionExpiryContractTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tina4-sess-contract-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function session(int $ttl = 3600): Session
    {
        return new Session('file', ['path' => $this->dir, 'ttl' => $ttl]);
    }

    /** Out-of-band existence check — a directory listing, not the code under test. */
    /**
     * The on-disk name is sha256(id).json, not the raw id (ADR-0021 - a session
     * id is opaque and never becomes a path component). Globbing the raw id
     * finds nothing whether or not the record exists, so every filename in this
     * file goes through here.
     */
    private function recordPath(string $sessionId): string
    {
        return $this->dir . '/' . hash('sha256', $sessionId) . '.json';
    }

    private function recordOnDisk(string $sessionId): bool
    {
        return file_exists($this->recordPath($sessionId));
    }

    // ── T1: a record with NO expiry stamp survives a read ────────────────

    /**
     * A record carrying no expiry stamp at all must be RETURNED and KEPT.
     *
     * Before the fix, loadFromFile() defaulted the missing stamp to 0 and fed it
     * into `time() - 0 > ttl`, which is unconditionally true, so the record was
     * judged infinitely old and UNLINKED on read.
     */
    public function testSessionMissingExpiryStampSurvivesRead(): void
    {
        file_put_contents(
            $this->recordPath('naked'),
            json_encode(['seeded' => true])          // no _meta, no stamp of any kind
        );

        $result = $this->session()->read('naked');

        $this->assertIsArray($result, 'an unstamped record must be returned, not null');
        $this->assertTrue($result['seeded'], 'the payload must survive intact');
        $this->assertTrue(
            $this->recordOnDisk('naked'),
            'an unstamped record must NOT be deleted — absent expiry means never expires'
        );
    }

    /** A stamp that is present but ZERO means the same thing: never expires. */
    public function testSessionZeroExpiryStampSurvivesRead(): void
    {
        file_put_contents(
            $this->recordPath('zeroed'),
            json_encode(['seeded' => true, '_meta' => ['created_at' => 0, 'last_accessed' => 0]])
        );

        $result = $this->session()->read('zeroed');

        $this->assertIsArray($result);
        $this->assertTrue($result['seeded']);
        $this->assertTrue($this->recordOnDisk('zeroed'), 'a zero stamp must not delete the record');
    }

    // ── T2: the NEGATIVE control — genuine expiry still reaps ────────────

    /**
     * THE NON-NEGOTIABLE NEGATIVE CONTROL.
     *
     * Without this, a "fix" that simply removes all expiry passes every positive
     * gate above and ships a sessions-never-expire security bug. Real wall-clock
     * time, no clock mocking.
     */
    public function testSessionGenuinelyExpiredRecordIsReaped(): void
    {
        $session = $this->session(1);
        $session->write('expiring', ['seeded' => true], 1);
        $this->assertTrue($this->recordOnDisk('expiring'), 'precondition: the record was written');

        sleep(3);                                    // real time, past the 1s ttl

        $this->assertNull(
            $this->session(1)->read('expiring'),
            'a genuinely expired record must read as null'
        );
        $this->assertFalse(
            $this->recordOnDisk('expiring'),
            'a genuinely expired record must still be REAPED'
        );
    }

    /** A stamp planted in the past is expired and reaped, with no waiting. */
    public function testSessionPastExpiryStampIsReaped(): void
    {
        $stale = time() - 99999;
        file_put_contents(
            $this->recordPath('stale'),
            json_encode(['seeded' => true, '_meta' => ['created_at' => $stale, 'last_accessed' => $stale]])
        );

        $this->assertNull($this->session(60)->read('stale'));
        $this->assertFalse($this->recordOnDisk('stale'), 'a past-stamped record must be reaped');
    }

    // ── T3: the public write()/read() facade round-trips ─────────────────

    /**
     * The literal reported repro: write() through the public facade, then read()
     * it back through a FRESH Session. This pair had zero coverage, which is
     * exactly why the defect shipped.
     */
    public function testSessionPublicWriteReadFacadeRoundTrips(): void
    {
        $this->session()->write('my-session-id', ['seeded' => true], 3600);

        $result = $this->session()->read('my-session-id');

        $this->assertIsArray($result, 'write() then read() must round-trip');
        $this->assertTrue($result['seeded']);
        $this->assertTrue($this->recordOnDisk('my-session-id'), 'read() must not destroy a live record');
    }

    /** The persistence layer owns the stamp — write() must produce one itself. */
    public function testSessionWriteStampsExpiryItself(): void
    {
        $this->session()->write('stamped', ['seeded' => true], 3600);

        $decoded = json_decode(file_get_contents($this->recordPath('stamped')), true);

        $this->assertArrayHasKey('_meta', $decoded, 'the persistence layer must stamp, not the caller');
        $this->assertGreaterThan(
            0,
            $decoded['_meta']['last_accessed'] ?? 0,
            'a written record must carry a real stamp so it can ever expire'
        );
    }

    // ── T7: a cleared-but-live session reads as empty, not null ──────────

    /**
     * clear() keeps the session alive. Reading it back must give an EMPTY
     * session, not "no such session" — read() used to decide "not found" from a
     * timestamp heuristic that a freshly cleared session matches exactly.
     */
    public function testSessionClearedButLiveSessionReadsAsEmptyNotNull(): void
    {
        $session = $this->session();
        $id = $session->start();
        $session->set('user', 'alice');
        $session->clear();
        $session->save();

        $this->assertTrue($this->recordOnDisk($id), 'precondition: clear() keeps the record');

        $result = $this->session()->read($id);

        $this->assertNotNull($result, 'a live cleared session must not read as null');
        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('user', $result, 'clear() must have removed the data');
    }

    /** The counterpart: a genuinely absent session still reads as null. */
    public function testSessionMissingSessionStillReadsAsNull(): void
    {
        $this->assertNull($this->session()->read('no-such-session-id'));
    }

    // ── T5: every handler accepts and honours a ttl ──────────────────────

    /**
     * `write($id, $data, $ttl)` is the cross-framework contract. A handler that
     * does not take the third argument makes the ttl silently unenforceable.
     */
    public function testSessionWriteAcceptsTtlOnEveryHandler(): void
    {
        foreach ([
            \Tina4\Session\RedisSessionHandler::class,
            \Tina4\Session\ValkeySessionHandler::class,
            \Tina4\Session\MemcachedSessionHandler::class,
            \Tina4\Session\MongoSessionHandler::class,
            \Tina4\Session\DatabaseSessionHandler::class,
        ] as $class) {
            $write = (new \ReflectionClass($class))->getMethod('write');
            $this->assertSame(
                3,
                $write->getNumberOfParameters(),
                "{$class}::write() must accept (sessionId, data, ttl)"
            );
            $this->assertSame(
                'ttl',
                $write->getParameters()[2]->getName(),
                "{$class}::write()'s third parameter must be named ttl"
            );
        }
    }

    /** A per-call ttl must actually shorten the session, not be discarded. */
    public function testSessionWriteTtlArgumentIsHonoured(): void
    {
        $this->session(3600)->write('shortlived', ['seeded' => true], 1);

        sleep(3);

        $this->assertNull(
            $this->session(3600)->read('shortlived'),
            'a per-call ttl of 1s must expire the record even when the instance ttl is 3600'
        );
        $this->assertFalse($this->recordOnDisk('shortlived'));
    }

    // ── T6/T8: the database backend on a REAL engine ─────────────────────

    /**
     * The database handler must actually WRITE. It called $db->exec(), which
     * exists only on SQLite3Adapter — every other adapter defines execute() — so
     * on PostgreSQL/MySQL every write threw "undefined method", was swallowed by
     * the degrade path, and the session silently stored nothing.
     *
     * Uses a real SQLite engine here so the gate runs everywhere; the PostgreSQL
     * and MySQL runs are in SessionDatabaseEngineTest.
     */
    public function testSessionDatabaseBackendActuallyWrites(): void
    {
        $file = $this->dir . '/sessions.db';
        $db = Database::create('sqlite:///' . $file);
        $adapter = $db->getAdapter();

        $handler = new DatabaseSessionHandler(['db' => $adapter, 'ttl' => 3600]);
        $handler->write('dbsid', ['seeded' => true], 3600);

        // Out of band: a SEPARATE connection, not the handler.
        $probe = new \SQLite3($file);
        $rows = $probe->querySingle("SELECT count(*) FROM tina4_session WHERE session_id = 'dbsid'");
        $probe->close();

        $this->assertSame(1, (int)$rows, 'the row must actually reach the database');
        $this->assertSame(['seeded' => true], $handler->read('dbsid'));
    }

    /**
     * The handler must not call a method that is absent from the adapter
     * interface. exec() lives only on SQLite3Adapter (and is deprecated there);
     * execute() is the one method every adapter implements.
     */
    public function testSessionDatabaseHandlerOnlyCallsInterfaceMethods(): void
    {
        $source = file_get_contents(
            dirname(__DIR__) . '/Tina4/Session/DatabaseSessionHandler.php'
        );

        $this->assertStringNotContainsString(
            '$this->db->exec(',
            $source,
            'DatabaseSessionHandler must call execute() — exec() is not on the DatabaseAdapter interface, '
            . 'so it is a fatal "undefined method" on every engine except SQLite'
        );
        $this->assertTrue(
            method_exists(\Tina4\Database\DatabaseAdapter::class, 'execute'),
            'execute() must be part of the adapter interface'
        );
        $this->assertFalse(
            method_exists(\Tina4\Database\DatabaseAdapter::class, 'exec'),
            'exec() is deliberately NOT on the interface — this test exists to catch a handler calling it'
        );
    }

    /**
     * expires_at must be an EIGHT-BYTE float on EVERY engine: one ULP of a
     * four-byte float is 128 SECONDS at the current epoch, so a short session
     * would expire up to a minute early.
     *
     * This used to ban the token "expires_at REAL" anywhere in the handler and
     * require "expires_at DOUBLE PRECISION" somewhere in it. That was right
     * while ONE generic statement served every engine, and wrong the moment the
     * DDL became per-engine, for two independent reasons:
     *
     *   - REAL is float4 on PostgreSQL but a full eight-byte IEEE-754 binary64
     *     in SQLite, so the ban forbade the CORRECT SQLite spelling (and the
     *     one the other three frameworks write).
     *   - A single "DOUBLE PRECISION" anywhere in the file satisfied the
     *     requirement on behalf of all five engines at once.
     *
     * Per engine is also strictly stronger: it catches FLOAT(24) on SQL Server
     * and a bare FLOAT on Firebird, both genuinely four-byte, which a token ban
     * over the whole file cannot see.
     */
    public function testSessionExpiresAtColumnIsNotSinglePrecision(): void
    {
        // The eight-byte spelling on each engine, and what the four-byte trap
        // would have been there.
        $eightByteSpellings = [
            'sqlite' => 'expires_at REAL NOT NULL',                 // SQLite REAL is binary64; there is no float4
            'postgres' => 'expires_at DOUBLE PRECISION NOT NULL',   // REAL would be float4 here
            'mysql' => 'expires_at DOUBLE NOT NULL',                // MySQL FLOAT is the four-byte one
            'mssql' => 'expires_at FLOAT NOT NULL',                 // T-SQL FLOAT is FLOAT(53); FLOAT(24) is float4
            'firebird' => 'EXPIRES_AT DOUBLE PRECISION NOT NULL',   // Firebird FLOAT is four-byte
        ];

        $handler = new \ReflectionClass(\Tina4\Session\DatabaseSessionHandler::class);
        $createTable = $handler->getConstant('CREATE_TABLE');

        $this->assertIsArray(
            $createTable,
            'DatabaseSessionHandler must declare its CREATE TABLE per engine — one statement for all '
            . 'five is not legal SQL on SQL Server or Firebird'
        );

        foreach ($eightByteSpellings as $engine => $spelling) {
            $this->assertArrayHasKey(
                $engine,
                $createTable,
                $engine . ' has no CREATE TABLE, so its expires_at precision cannot be checked at all'
            );
            $this->assertStringContainsString(
                $spelling,
                (string)$createTable[$engine],
                'expires_at must be an eight-byte float on ' . $engine . ' (expected "' . $spelling
                . '") — a four-byte float has a 128-second ULP at the current epoch, so a short session '
                . 'expires up to a minute early'
            );
        }

        // The statement sent to an adapter with no measured DDL of its own.
        $this->assertStringContainsString(
            'expires_at DOUBLE PRECISION NOT NULL',
            (string)$handler->getConstant('CREATE_TABLE_FALLBACK'),
            'the fallback statement carries the same precision requirement as the engines that have one'
        );
    }
}
