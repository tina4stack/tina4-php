<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Session\DatabaseSessionHandler;

/**
 * The database session backend, exercised on a LIVE Firebird.
 *
 * Firebird is the one engine with NO TEXT type (its session payload column is a
 * VARCHAR(8191)) AND the one that folds unquoted identifiers to UPPER, so it is
 * the only engine that exercises BOTH the per-engine CREATE TABLE branch and the
 * adapter's UPPER->lower column folding on the read path. The session engine
 * coverage previously skipped Firebird entirely, which is exactly how a
 * Firebird-incompatible session DDL could have hidden (it did in tina4-python:
 * a single "data TEXT" CREATE failed with -607 there).
 *
 * Gated on TINA4_TEST_FIREBIRD_URL (loud skip without a live server, never a
 * silent pass), mirroring every other live-Firebird test in this suite. The lab
 * exports it; the pdo_firebird leg runs there. VERIFIED end-to-end against the
 * live lab Firebird 5.0.4: a nested payload written by one handler and read back
 * by a FRESH one round-trips intact through the VARCHAR column.
 *
 * NO MOCKS. Real Firebird, real connection, real rows.
 */
class SessionDatabaseFirebirdTest extends TestCase
{
    /** Base URL for a real Firebird server, or a loud skip. */
    private function baseUrl(): string
    {
        $url = getenv('TINA4_TEST_FIREBIRD_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped(
                'Set TINA4_TEST_FIREBIRD_URL to run the live Firebird session test '
                . '(e.g. firebird://SYSDBA:masterkey@localhost:3050//tmp/test.fdb)'
            );
        }
        return $url;
    }

    /** Force the pdo_firebird driver on the base URL (the leg the lab runs). */
    private function pdoUrl(): string
    {
        $url = $this->baseUrl();
        return $url . (str_contains($url, '?') ? '&' : '?') . 'driver=pdo';
    }

    /** A fresh Firebird connection, or a leg skip if pdo_firebird cannot serve here. */
    private function connectOrSkip(): Database
    {
        $url = $this->pdoUrl();
        if (!in_array('firebird', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_firebird driver not present - session-on-Firebird UNVERIFIED here.');
        }
        try {
            $db = Database::create($url);
            $db->fetchOne('SELECT 1 AS N FROM RDB$DATABASE'); // touch it - a broken driver fails HERE
        } catch (\Throwable $e) {
            $this->markTestSkipped("pdo_firebird cannot connect here ({$e->getMessage()}) - leg UNVERIFIED.");
        }
        return $db;
    }

    private function dropSessionTable(Database $db): void
    {
        try {
            if ($db->tableExists('tina4_session')) {
                $db->execute('DROP TABLE tina4_session');
                $db->commit();
            }
        } catch (\Throwable) {
            // Best effort - a fresh database has nothing to drop.
        }
    }

    /**
     * write() then read() on a FRESH handler/connection round-trips a nested
     * payload, the row is present ON the engine itself, and the table was created
     * with Firebird's UPPER-cased VARCHAR shape.
     */
    public function testSessionRoundTripsOnLiveFirebird(): void
    {
        $db = $this->connectOrSkip();
        $this->dropSessionTable($db);

        $sessionId = 'fb-session-' . bin2hex(random_bytes(4));
        $payload = ['hello' => 'firebird', 'n' => 42, 'nested' => ['a' => [1, 2, 3]]];

        $writer = new DatabaseSessionHandler(['db' => $db]);
        $writer->write($sessionId, $payload);

        // A FRESH handler on a FRESH connection - nothing in-process can answer
        // from memory. This is the read that used to be UNVERIFIED on Firebird.
        $reader = new DatabaseSessionHandler(['db' => Database::create($this->pdoUrl())]);
        $this->assertSame($payload, $reader->read($sessionId), 'session must round-trip on Firebird');

        // OUT OF BAND: ask the engine ourselves. A handler that wrote elsewhere
        // cannot fake this. The adapter folds DATA -> data on the way back.
        $probe = Database::create($this->pdoUrl());
        $row = array_change_key_case($probe->fetchOne(
            'SELECT data FROM tina4_session WHERE session_id = ?',
            [$sessionId]
        ) ?? []);
        $this->assertArrayHasKey('data', $row, 'row must be present on the engine itself');
        $this->assertSame('firebird', json_decode((string) $row['data'], true)['hello'] ?? null);

        // The table exists with the Firebird per-engine shape (VARCHAR, not TEXT).
        $cols = $probe->fetch(
            "SELECT TRIM(RDB\$FIELD_NAME) AS name FROM RDB\$RELATION_FIELDS "
            . "WHERE RDB\$RELATION_NAME = 'TINA4_SESSION'"
        );
        $names = array_map(
            static fn ($r) => trim((string) (array_change_key_case((array) $r)['name'] ?? '')),
            $cols->records ?? []
        );
        $this->assertContains('SESSION_ID', $names);
        $this->assertContains('DATA', $names);
        $this->assertContains('EXPIRES_AT', $names);

        $reader->destroy($sessionId);
        $probe->close();
    }
}
