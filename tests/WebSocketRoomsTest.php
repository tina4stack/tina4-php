<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * WebSocket rooms, driven over REAL sockets.
 *
 * This class lived at the bottom of WebSocketV3Test.php and therefore NEVER
 * RAN: PHPUnit collects the class whose name matches the FILE basename, so a
 * second TestCase class in the same file is silently ignored — by
 * `vendor/bin/phpunit tests`, by phpunit.xml's directory discovery, and by CI.
 * `--list-tests` on the repo config listed zero WebSocketRooms* tests.
 * ConfiguredTestFilesTest guards FILES, not classes, so the hole was invisible.
 * Splitting it into its own file is what makes it a gate at all.
 */

use PHPUnit\Framework\TestCase;
use Tina4\WebSocket;

// ── Rooms / Namespaces ───────────────────────────────────────────────────────

/**
 * Rooms driven over REAL sockets against a REAL listener.
 *
 * This class used to subclass WebSocket and shove `php://memory` handles
 * straight into its private $clients array. That double hid two things:
 * connections were never registered by the production code path, and a
 * php://memory stream ALWAYS accepts a write, so the delivery path could not
 * fail and `assertNotEmpty(stream_get_contents(...))` proved only "some bytes".
 *
 * Now every client is a genuine TCP connection: a real `stream_socket_server`
 * listener (the same call WebSocket::start() makes), a real
 * `stream_socket_client` peer, a real `stream_socket_accept`, and the real
 * RFC 6455 upgrade handled by WebSocket::handleNewConnection — which is what
 * registers the client, assigns its id and stores its socket. Assertions read
 * the PUBLIC room API and DECODE the real frames that arrive on the real client
 * sockets, so a delivered message is checked byte for byte rather than by
 * emptiness.
 *
 * handleNewConnection is private, so it is reached by reflection — that drives
 * the production code, it does not replace it. The same pattern is already used
 * by WebSocketAuthTest for the standalone upgrade path.
 */
class WebSocketRoomsTest extends TestCase
{
    private WebSocket $ws;

    /** @var resource the real listening socket accepting our client connections */
    private $listener;

    /** @var array<string, resource> clientId => the CLIENT end of its TCP connection */
    private array $peers = [];

    protected function setUp(): void
    {
        // Local-only fan-out: no cluster backplane is configured, so
        // broadcastToRoom()'s ensureBackplane() stays a no-op.
        putenv('TINA4_WS_BACKPLANE');
        unset($_ENV['TINA4_WS_BACKPLANE']);

        $port = \FreePort::get();
        $listener = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
        if ($listener === false) {
            $this->fail("could not listen on 127.0.0.1:{$port}: {$errstr} ({$errno})");
        }
        $this->listener = $listener;
        $this->ws = new WebSocket($port);
    }

    protected function tearDown(): void
    {
        foreach ($this->peers as $peer) {
            if (is_resource($peer)) {
                fclose($peer);
            }
        }
        $this->peers = [];
        if (is_resource($this->listener)) {
            fclose($this->listener);
        }
    }

    /**
     * Open one REAL connection, complete the REAL handshake through
     * WebSocket::handleNewConnection, and return the client id the server
     * assigned to it.
     */
    private function connect(string $path = '/'): string
    {
        $before = array_column($this->ws->getClients(), 'id');

        $peer = @stream_socket_client(
            'tcp://' . stream_socket_get_name($this->listener, false),
            $errno,
            $errstr,
            2.0
        );
        $this->assertNotFalse($peer, "client could not connect: {$errstr} ({$errno})");

        $request = "GET {$path} HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";
        $this->assertSame(strlen($request), fwrite($peer, $request));

        $serverEnd = @stream_socket_accept($this->listener, 2.0);
        $this->assertNotFalse($serverEnd, 'the listener never accepted the connection');

        (new \ReflectionMethod(WebSocket::class, 'handleNewConnection'))
            ->invoke($this->ws, $serverEnd);

        // Drain the real 101 response so later reads see only data frames.
        stream_set_timeout($peer, 2);
        $handshake = '';
        while (!str_contains($handshake, "\r\n\r\n")) {
            $line = fgets($peer);
            if ($line === false) {
                break;
            }
            $handshake .= $line;
        }
        $this->assertStringContainsString(
            '101 Switching Protocols',
            $handshake,
            'the real handshake did not complete'
        );
        stream_set_blocking($peer, false);

        $after = array_column($this->ws->getClients(), 'id');
        $new = array_values(array_diff($after, $before));
        $this->assertCount(1, $new, 'exactly one new client must have been registered');

        $this->peers[$new[0]] = $peer;

        return $new[0];
    }

    /**
     * Decode the frames that really arrived on a client's socket.
     *
     * A real socket is asynchronous: an fwrite on the server side has not
     * necessarily landed in the peer's receive buffer by the next statement, so
     * a POSITIVE expectation waits (up to $timeout) for the frames it expects.
     * $expect = 0 means "read whatever is there right now" and is only used for
     * NEGATIVE assertions — always after a positive read on another peer has
     * already forced the wait, because deliverLocal() writes to its targets in
     * order within a single call.
     *
     * @return string[]
     */
    private function received(string $clientId, int $expect = 0, float $timeout = 2.0): array
    {
        $peer = $this->peers[$clientId];
        $buffer = '';
        $payloads = [];
        $deadline = microtime(true) + $timeout;

        while (true) {
            $buffer .= (string)@stream_get_contents($peer);
            while ($buffer !== '') {
                $frame = WebSocket::decodeFrame($buffer);
                if ($frame === null) {
                    break;
                }
                $payloads[] = $frame['payload'];
                $buffer = substr($buffer, $frame['length']);
            }
            if (count($payloads) >= $expect) {
                break;
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }
            $read = [$peer];
            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 0, (int)min(50000, $remaining * 1_000_000));
        }

        return $payloads;
    }

    /**
     * Assert a peer received NOTHING. Called only after a positive read on a
     * peer written LATER in the same delivery pass, plus a short settle so a
     * stray frame would have had every chance to arrive.
     */
    private function assertReceivedNothing(string $clientId, string $message): void
    {
        usleep(20000);
        $this->assertSame([], $this->received($clientId), $message);
    }

    // ── Room count ───────────────────────────────────────────────

    public function testRoomCountZeroForUnknownRoom(): void
    {
        $this->assertSame(0, $this->ws->roomCount('ghost'));
    }

    public function testRoomCountReflectsJoinedMembers(): void
    {
        $a = $this->connect();
        $b = $this->connect();
        $this->ws->joinRoom($a, 'chat');
        $this->ws->joinRoom($b, 'chat');
        $this->assertSame(2, $this->ws->roomCount('chat'));
    }

    public function testRoomCountDecreasesOnLeave(): void
    {
        $a = $this->connect();
        $this->ws->joinRoom($a, 'chat');
        $this->ws->leaveRoom($a, 'chat');
        $this->assertSame(0, $this->ws->roomCount('chat'));
    }

    // ── joinRoom ─────────────────────────────────────────────────

    public function testJoinRoomAddsClientToRoom(): void
    {
        $a = $this->connect();
        $this->ws->joinRoom($a, 'chat');

        $this->assertContains($a, $this->ws->getRoomConnections('chat'));
        $this->assertContains('chat', $this->ws->getClientRooms($a));
    }

    public function testJoinRoomUnknownClientIsIgnored(): void
    {
        // NEGATIVE: only a really-connected client can join. Nothing is
        // registered for an id that never completed a handshake.
        $this->ws->joinRoom('never-connected', 'chat');
        $this->assertSame(0, $this->ws->roomCount('chat'));
        $this->assertSame([], $this->ws->getRoomConnections('chat'));
    }

    public function testJoinRoomIsIdempotent(): void
    {
        $a = $this->connect();
        $this->ws->joinRoom($a, 'chat');
        $this->ws->joinRoom($a, 'chat');
        $this->assertSame(1, $this->ws->roomCount('chat'));
        $this->assertSame(['chat'], $this->ws->getClientRooms($a));
    }

    public function testClientInMultipleRooms(): void
    {
        $a = $this->connect();
        $this->ws->joinRoom($a, 'chat');
        $this->ws->joinRoom($a, 'lobby');

        $rooms = $this->ws->getClientRooms($a);
        $this->assertContains('chat', $rooms);
        $this->assertContains('lobby', $rooms);
        $this->assertSame(1, $this->ws->roomCount('chat'));
        $this->assertSame(1, $this->ws->roomCount('lobby'));
    }

    // ── leaveRoom ────────────────────────────────────────────────

    public function testLeaveRoomRemovesClientFromRoom(): void
    {
        $a = $this->connect();
        $this->ws->joinRoom($a, 'chat');
        $this->ws->leaveRoom($a, 'chat');

        $this->assertNotContains($a, $this->ws->getRoomConnections('chat'));
        $this->assertNotContains('chat', $this->ws->getClientRooms($a));
    }

    public function testLeaveRoomNonMemberIsNoOp(): void
    {
        $a = $this->connect();
        $this->ws->leaveRoom($a, 'nonexistent'); // must not throw
        $this->assertSame(0, $this->ws->roomCount('nonexistent'));
    }

    public function testLeavingOneRoomKeepsOthers(): void
    {
        $a = $this->connect();
        $this->ws->joinRoom($a, 'chat');
        $this->ws->joinRoom($a, 'lobby');
        $this->ws->leaveRoom($a, 'chat');

        $this->assertSame(['lobby'], $this->ws->getClientRooms($a));
        $this->assertSame(0, $this->ws->roomCount('chat'));
        $this->assertSame(1, $this->ws->roomCount('lobby'));
    }

    // ── getRoomConnections ────────────────────────────────────────

    public function testGetRoomConnectionsReturnsOnlyMembers(): void
    {
        $a = $this->connect();
        $b = $this->connect();
        $c = $this->connect();
        $this->ws->joinRoom($a, 'chat');
        $this->ws->joinRoom($b, 'chat');

        $members = $this->ws->getRoomConnections('chat');
        $this->assertContains($a, $members);
        $this->assertContains($b, $members);
        $this->assertNotContains($c, $members);
    }

    public function testGetRoomConnectionsEmptyForUnknownRoom(): void
    {
        $this->assertSame([], $this->ws->getRoomConnections('ghost'));
    }

    public function testRoomEmptyAfterLastMemberLeaves(): void
    {
        $a = $this->connect();
        $this->ws->joinRoom($a, 'temp');
        $this->ws->leaveRoom($a, 'temp');
        $this->assertSame([], $this->ws->getRoomConnections('temp'));
    }

    public function testMultipleConnectionsDifferentRooms(): void
    {
        $a = $this->connect();
        $b = $this->connect();
        $this->ws->joinRoom($a, 'alpha');
        $this->ws->joinRoom($b, 'beta');

        $this->assertNotContains($b, $this->ws->getRoomConnections('alpha'));
        $this->assertNotContains($a, $this->ws->getRoomConnections('beta'));
    }

    // ── broadcastToRoom ───────────────────────────────────────────

    public function testBroadcastToRoomSendsToMembersOnly(): void
    {
        $a = $this->connect();
        $b = $this->connect();
        $c = $this->connect(); // not in the room
        $this->ws->joinRoom($a, 'chat');
        $this->ws->joinRoom($b, 'chat');

        $this->ws->broadcastToRoom('chat', 'hello room');

        $this->assertSame(['hello room'], $this->received($a, 1), 'member a received the exact payload');
        $this->assertSame(['hello room'], $this->received($b, 1), 'member b received the exact payload');
        $this->assertReceivedNothing($c, 'NEGATIVE: non-member c must receive nothing');
    }

    public function testBroadcastToRoomExcludesSpecifiedIds(): void
    {
        $a = $this->connect();
        $b = $this->connect();
        $this->ws->joinRoom($a, 'chat');
        $this->ws->joinRoom($b, 'chat');

        $this->ws->broadcastToRoom('chat', 'msg', excludeIds: [$a]);

        // Positive first: b is written AFTER a in the same delivery pass, so a
        // frame for the excluded a would already have arrived by now.
        $this->assertSame(['msg'], $this->received($b, 1));
        $this->assertReceivedNothing($a, 'NEGATIVE: excluded a must receive nothing');
    }

    public function testBroadcastToRoomEmptyRoomDoesNotThrow(): void
    {
        $this->ws->broadcastToRoom('ghost', 'msg');
        $this->assertSame(0, $this->ws->roomCount('ghost'));
    }

    public function testBroadcastToRoomDeliversBinarySafePayload(): void
    {
        // A real socket carries bytes, not strings — pin that a payload with
        // NULs and high bytes survives the frame round-trip on the wire.
        $a = $this->connect();
        $this->ws->joinRoom($a, 'chat');

        $payload = "\x00\x01\xFEbinary\xFF\x00tail";
        $this->ws->broadcastToRoom('chat', $payload);

        $this->assertSame([$payload], $this->received($a, 1));
    }

    public function testBroadcastToRoomPrunesAClientWhoseSocketIsReallyGone(): void
    {
        // Only reachable with a real socket: the old php://memory double always
        // accepted a write, so the dead-peer prune could never run here.
        $a = $this->connect();
        $b = $this->connect();
        $this->ws->joinRoom($a, 'chat');
        $this->ws->joinRoom($b, 'chat');

        // Really tear a's connection down from the client side.
        fclose($this->peers[$a]);
        unset($this->peers[$a]);

        // Two broadcasts: the first write may still be absorbed by the kernel
        // buffer before the peer's RST comes back, the second cannot be.
        $this->ws->broadcastToRoom('chat', 'first');
        usleep(50000);
        $this->ws->broadcastToRoom('chat', 'second');

        $this->assertNotContains(
            $a,
            array_column($this->ws->getClients(), 'id'),
            'a client whose socket really died must be pruned from the connection table'
        );
        $this->assertNotContains(
            $a,
            $this->ws->getRoomConnections('chat'),
            'a pruned client must also leave its rooms'
        );
        // Delivery to the surviving member never stopped.
        $this->assertSame(['first', 'second'], $this->received($b, 2));
    }
}
