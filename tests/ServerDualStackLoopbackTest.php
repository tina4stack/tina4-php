<?php

declare(strict_types=1);

/**
 * The dev server must be reachable on BOTH loopback families.
 *
 * Written before the dual-stack fix lands — it is the gate for it. Windows
 * resolves `localhost` to ::1 (IPv6) first, so a server bound only to
 * 127.0.0.1 refused the browser with ERR_CONNECTION_REFUSED even though it
 * was serving. Binding both loopback families closes that gap.
 *
 * These are real sockets: the server binds through its own
 * openListenSockets(), and a real TCP client connects on each family. No
 * subprocess is spawned, so it runs on Windows too (where the bug lives) as
 * well as on Linux CI.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Server;

class ServerDualStackLoopbackTest extends TestCase
{
    /**
     * loopbackBindHosts() names the sibling family a direct bind of the host
     * would miss, and leaves an explicit LAN address untouched.
     */
    public function testLoopbackBindHostsNamesTheSiblingFamily(): void
    {
        $this->assertSame(
            ['[::1]'],
            Server::loopbackBindHosts('127.0.0.1'),
            'an IPv4-loopback host needs the IPv6 sibling'
        );
        $this->assertSame(
            ['[::1]'],
            Server::loopbackBindHosts('0.0.0.0'),
            'the IPv4 wildcard still misses IPv6 loopback'
        );
        $this->assertSame(
            ['127.0.0.1'],
            Server::loopbackBindHosts('::1'),
            'an IPv6-loopback host needs the IPv4 sibling'
        );
        $this->assertSame(
            ['127.0.0.1', '[::1]'],
            Server::loopbackBindHosts('localhost'),
            'localhost resolves per-OS, so bind both explicitly'
        );
        $this->assertSame(
            [],
            Server::loopbackBindHosts('192.168.1.10'),
            'an explicit LAN address is bound exactly as asked'
        );
    }

    /**
     * A server bound to IPv4 loopback also answers on IPv6 loopback — the
     * dual-stack behaviour a Windows `localhost` browser depends on.
     */
    public function testServerBoundToIpv4LoopbackAlsoAnswersOnIpv6(): void
    {
        if (!self::ipv6LoopbackAvailable()) {
            $this->markTestSkipped('IPv6 loopback (::1) is unavailable here');
        }

        $port = \FreePort::get();
        $server = new Server('127.0.0.1', $port);

        $this->openListeners($server);

        try {
            $this->assertTrue(
                self::accepts('127.0.0.1', $port),
                'the primary IPv4 loopback listener must accept'
            );
            $this->assertTrue(
                self::accepts('[::1]', $port),
                'IPv6 loopback must ALSO accept after the dual-stack fix'
            );
        } finally {
            $this->closeListeners($server);
        }
    }

    /** Bind the server's listeners without entering the accept loop. */
    private function openListeners(Server $server): void
    {
        (new \ReflectionMethod(Server::class, 'openListenSockets'))
            ->invoke($server);
    }

    /** Release the bound listeners. */
    private function closeListeners(Server $server): void
    {
        (new \ReflectionMethod(Server::class, 'closeListeners'))
            ->invoke($server);
    }

    /** True when a TCP client can connect to $host:$port. */
    private static function accepts(string $host, int $port): bool
    {
        $client = @stream_socket_client("tcp://{$host}:{$port}", $e1, $e2, 1);
        if ($client === false) {
            return false;
        }
        fclose($client);

        return true;
    }

    /** True when this host can bind IPv6 loopback at all. */
    private static function ipv6LoopbackAvailable(): bool
    {
        $probe = @stream_socket_server('tcp://[::1]:0', $e1, $e2);
        if ($probe === false) {
            return false;
        }
        fclose($probe);

        return true;
    }
}
