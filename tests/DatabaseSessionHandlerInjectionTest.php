<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * SQL-injection REGRESSION lock-in for the database session handler.
 *
 * The session_id is client-controlled (it IS the value of the tina4_session
 * cookie). Before v3.13.37 the handler string-interpolated it (and the stored
 * JSON data) straight into SQL, so a crafted cookie executed arbitrary SQL.
 * These tests drive a malicious session_id and quote-laden data through a real
 * SQLite adapter and prove the payload is treated as DATA, never executed —
 * the table survives and every value round-trips byte-for-byte. This is the
 * test that would have caught the original bug.
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\SQLite3Adapter;
use Tina4\Session\DatabaseSessionHandler;

class DatabaseSessionHandlerInjectionTest extends TestCase
{
    private function freshHandler(): array
    {
        $db = Database::create('sqlite::memory:');
        $handler = new DatabaseSessionHandler(['db' => $db, 'ttl' => 3600]);
        return [$handler, $db];
    }

    // -- The headline attack: a DROP TABLE payload in the session_id ----------

    public function testDropTablePayloadInSessionIdDoesNotExecute(): void
    {
        [$handler, $db] = $this->freshHandler();
        $adapter = $db->getAdapter();

        $maliciousId = "x'; DROP TABLE tina4_session; --";

        // Write a session under the malicious id, then read it back.
        $handler->write($maliciousId, ['user_id' => 7, 'role' => 'admin']);
        $back = $handler->read($maliciousId);

        // The DROP TABLE must NOT have run — the table is still there.
        $this->assertTrue(
            $adapter->tableExists('tina4_session'),
            'tina4_session table was dropped — the session_id was executed as SQL, not bound as data'
        );

        // And the payload round-trips: the malicious id was treated as a literal key.
        $this->assertIsArray($back);
        $this->assertSame(7, $back['user_id']);
        $this->assertSame('admin', $back['role']);

        $db->close();
    }

    // -- A quote in the STORED DATA must round-trip, not break the statement --

    public function testQuoteInStoredDataRoundTripsIntact(): void
    {
        [$handler, $db] = $this->freshHandler();
        $adapter = $db->getAdapter();

        $sessionId = 'legit-session-123';
        $payload = [
            'name'  => "O'Brien",                       // single quote
            'note'  => 'he said "hello"; DROP TABLE x', // double quote + SQL-looking text
            'path'  => "C:\\Users\\a'b",                // backslash + quote
        ];

        $handler->write($sessionId, $payload);
        $back = $handler->read($sessionId);

        $this->assertTrue($adapter->tableExists('tina4_session'));
        $this->assertSame($payload, $back, 'Quote-laden session data did not round-trip intact');

        $db->close();
    }

    // -- A quote in the session_id on the UPDATE path (write twice) -----------

    public function testMaliciousSessionIdSurvivesUpdatePath(): void
    {
        [$handler, $db] = $this->freshHandler();
        $adapter = $db->getAdapter();

        $maliciousId = "abc' OR '1'='1";

        // First write -> INSERT branch.
        $handler->write($maliciousId, ['v' => 1]);
        // Second write -> UPDATE branch (existence check + UPDATE, both parameterised).
        $handler->write($maliciousId, ['v' => 2]);

        $back = $handler->read($maliciousId);

        $this->assertTrue($adapter->tableExists('tina4_session'));
        $this->assertSame(2, $back['v'], 'UPDATE path did not bind the session_id as data');

        // The OR '1'='1' tautology must NOT leak other sessions: a different id reads null.
        $handler->write('other-session', ['v' => 99]);
        $this->assertSame(2, $handler->read($maliciousId)['v']);

        $db->close();
    }

    // -- destroy() with a malicious id must not execute injected SQL ----------

    public function testMaliciousSessionIdInDestroyDoesNotExecute(): void
    {
        [$handler, $db] = $this->freshHandler();
        $adapter = $db->getAdapter();

        $keep = 'keep-me';
        $evil = "z'; DROP TABLE tina4_session; --";

        $handler->write($keep, ['v' => 'safe']);
        $handler->write($evil, ['v' => 'evil']);

        // Destroying the malicious id must only delete that row — not run the DROP.
        $handler->destroy($evil);

        $this->assertTrue($adapter->tableExists('tina4_session'));
        $this->assertNull($handler->read($evil), 'malicious session was not deleted');
        $this->assertSame('safe', $handler->read($keep)['v'], 'unrelated session was destroyed');

        $db->close();
    }
}
