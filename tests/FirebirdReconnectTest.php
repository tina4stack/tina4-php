<?php

/**
 * Regression tests for FirebirdAdapter dead-connection recovery.
 *
 * Idle Firebird connections die silently behind NAT timeouts, server-side
 * ConnectionIdleTimeout, or Docker network rotation. Without a transparent
 * reconnect, the next ibase_prepare() crashes with one of:
 *
 *     "Error writing data to the connection."
 *     "Error reading data from the connection."
 *     "connection shutdown"
 *     "Connection is not active"
 *
 * Shipped in 3.11.35: FirebirdAdapter detects the marker, closes the stale
 * handle, reopens using cached connection params, and retries the statement
 * once. Skipped inside an explicit transaction — atomicity wins there.
 *
 * These tests probe the matcher directly. The reconnect-and-retry flow needs
 * a live Firebird server to exercise end-to-end, so it's covered by manual
 * QA against the 24now / 24call-agent reproducer rather than unit tests.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\FirebirdAdapter;

class FirebirdReconnectTest extends TestCase
{
    public static function deadConnectionMessages(): array
    {
        return [
            'writing'           => ['Error writing data to the connection.'],
            'reading'           => ['Error reading data from the connection.'],
            'shutdown'          => ['connection shutdown'],
            'lost'              => ['Connection lost'],
            'network'           => ['network error'],
            'inactive'          => ['Connection is not active'],
            'broken-pipe'       => ['Broken pipe'],
            // Substring + case-insensitive must catch the real-world wrap
            'wrapped'           => ['isc_dsql_prepare: Error writing data to the connection. attached to db'],
        ];
    }

    /**
     * @dataProvider deadConnectionMessages
     */
    public function testIsDeadConnectionMatchesRealWorldMarkers(string $msg): void
    {
        $this->assertTrue(
            FirebirdAdapter::isDeadConnection($msg),
            "Expected '{$msg}' to be classified as a dead-connection error"
        );
    }

    public static function logicalErrorMessages(): array
    {
        return [
            'syntax'   => ['Dynamic SQL Error: syntax error at line 1, column 17'],
            'no-table' => ['Table USERS does not exist'],
            'fk'       => ['violation of FOREIGN KEY constraint'],
            'lock'     => ['lock conflict on no wait transaction'],
            'permission' => ['no permission for SELECT access to TABLE USERS'],
        ];
    }

    /**
     * Logical SQL errors must NOT trigger a reconnect — retrying would mask
     * real bugs (and would just fail again immediately).
     *
     * @dataProvider logicalErrorMessages
     */
    public function testIsDeadConnectionDoesNotMatchLogicalErrors(string $msg): void
    {
        $this->assertFalse(
            FirebirdAdapter::isDeadConnection($msg),
            "Logical error '{$msg}' should NOT be classified as dead-connection"
        );
    }

    public function testIsDeadConnectionHandlesEmptyAndNull(): void
    {
        $this->assertFalse(FirebirdAdapter::isDeadConnection(''));
        $this->assertFalse(FirebirdAdapter::isDeadConnection(null));
    }

    public function testIsDeadConnectionIsCaseInsensitive(): void
    {
        $this->assertTrue(FirebirdAdapter::isDeadConnection('ERROR WRITING DATA TO THE CONNECTION'));
        $this->assertTrue(FirebirdAdapter::isDeadConnection('Connection Shutdown'));
        $this->assertTrue(FirebirdAdapter::isDeadConnection('cOnNecTion lOst'));
    }
}
