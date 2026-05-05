<?php

/**
 * MySQLAdapter unit tests — TCP host rewrite.
 *
 * PHP's \mysqli has a known quirk where host == "localhost" triggers a
 * Unix-socket lookup and the port argument is silently ignored. That
 * breaks any TCP test setup such as
 * ``mysql://tina4user:tina4@localhost:53306/tina4`` against a Docker
 * container, because mysqli is hunting for /tmp/mysql.sock.
 *
 * The fix: when host is "localhost" AND a port was specified, rewrite
 * the host to "127.0.0.1" so mysqli takes the TCP code path. If no port
 * is specified, preserve "localhost" so socket-based deployments still
 * work as before.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\MySQLAdapter;

class MySQLAdapterTest extends TestCase
{
    public function testLocalhostWithPortIsRewrittenToTcp(): void
    {
        // A non-default port means the developer wants TCP — Unix socket
        // lookup would fail. Rewrite to 127.0.0.1.
        $this->assertSame(
            '127.0.0.1',
            MySQLAdapter::rewriteHostForTcp('localhost', 53306)
        );
    }

    public function testLocalhostWithDefaultPortIsRewrittenToTcp(): void
    {
        // Even the default 3306 port should be rewritten — mysqli's
        // socket trap fires on "localhost" regardless of port value.
        $this->assertSame(
            '127.0.0.1',
            MySQLAdapter::rewriteHostForTcp('localhost', 3306)
        );
    }

    public function testLocalhostWithoutPortKeepsSocketBehaviour(): void
    {
        // No port → user almost certainly wants the default Unix socket.
        // Don't rewrite; preserve socket-based deployments.
        $this->assertSame(
            'localhost',
            MySQLAdapter::rewriteHostForTcp('localhost', null)
        );
    }

    public function testLocalhostWithZeroPortKeepsSocketBehaviour(): void
    {
        // Port == 0 is the same signal as null — no port, use socket.
        $this->assertSame(
            'localhost',
            MySQLAdapter::rewriteHostForTcp('localhost', 0)
        );
    }

    public function testLoopbackIpIsLeftAlone(): void
    {
        // Already TCP — never rewrite.
        $this->assertSame(
            '127.0.0.1',
            MySQLAdapter::rewriteHostForTcp('127.0.0.1', 53306)
        );
    }

    public function testRemoteHostIsLeftAlone(): void
    {
        // Remote hosts are always TCP — never rewrite.
        $this->assertSame(
            'mysql.internal',
            MySQLAdapter::rewriteHostForTcp('mysql.internal', 3306)
        );
    }

    public function testEmptyHostIsLeftAlone(): void
    {
        // An empty string isn't "localhost" — leave it untouched and
        // let mysqli surface its own error.
        $this->assertSame(
            '',
            MySQLAdapter::rewriteHostForTcp('', 3306)
        );
    }
}
