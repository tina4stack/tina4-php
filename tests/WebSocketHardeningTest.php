<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Lock-in tests for the WebSocket + SSE hardening sweep (mirrors the
 * Python-master tests/test_websocket_hardening.py):
 *
 *   - Backplane relay across simulated instances (poll/onRaw + origin guard)
 *   - origin-guard-no-echo invariant (we never re-deliver our own broadcast)
 *   - bytes round-trip through the JSON envelope (base64)
 *   - Broadcast resilience (one dead client never aborts delivery; it is pruned)
 *   - SSE streaming error handling (generator raises mid-stream / disconnect)
 *   - originAllowed() allow-list semantics (empty = allow, set = reject)
 *   - Idle reaper opt-in semantics
 *   - OP_CONTINUATION fragmented-frame reassembly (RFC 6455 §5.4)
 *
 * Engine-agnostic: no real Redis/NATS/sockets-over-the-network. A tiny
 * in-memory FakeBackplane proves the relay path end to end; loopback
 * stream_socket_pair()s stand in for live WebSocket connections so we can read
 * back exactly which frames were delivered.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Response;
use Tina4\Server;
use Tina4\WebSocket;
use Tina4\WebSocketBackplaneInterface;
use Tina4\WebSocketBackplaneManager;

/**
 * In-memory pub/sub backplane. publish() synchronously fans the raw message
 * out to every subscribed callback — exactly what a real backplane does, minus
 * the network. Mirrors the Python FakeBackplane.
 */
class FakeBackplane implements WebSocketBackplaneInterface
{
    /** @var array<string, callable[]> */
    public array $subs = [];

    public function publish(string $channel, string $message): void
    {
        foreach ($this->subs[$channel] ?? [] as $cb) {
            $cb($message);
        }
    }

    public function subscribe(string $channel, callable $callback): void
    {
        $this->subs[$channel][] = $callback;
    }

    public function poll(): void
    {
        // Synchronous fan-out happens in publish(); nothing buffered to drain.
    }

    public function getSubscriberStream()
    {
        return null;
    }

    public function unsubscribe(string $channel): void
    {
        unset($this->subs[$channel]);
    }

    public function close(): void
    {
        $this->subs = [];
    }
}

/** A backplane whose publish() always throws — to prove a flaky bus never undoes a local broadcast. */
class ExplodingBackplane extends FakeBackplane
{
    public function publish(string $channel, string $message): void
    {
        throw new \RuntimeException('bus down');
    }
}

class WebSocketHardeningTest extends TestCase
{
    /** @var array<int, array{0: resource, 1: resource}> socket pairs to clean up */
    private array $pairs = [];

    protected function tearDown(): void
    {
        foreach ($this->pairs as $pair) {
            foreach ($pair as $sock) {
                if (is_resource($sock)) {
                    @fclose($sock);
                }
            }
        }
        $this->pairs = [];
    }

    /**
     * Make a connected socket pair: [serverEnd, peerEnd]. The server writes
     * frames to serverEnd; the test reads them off peerEnd.
     *
     * @return array{0: resource, 1: resource}
     */
    private function makePair(): array
    {
        $pair = @stream_socket_pair(
            DIRECTORY_SEPARATOR === '\\' ? STREAM_PF_INET : STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP
        );
        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair not available');
        }
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);
        $this->pairs[] = $pair;
        return $pair;
    }

    /** Read whatever is buffered on the peer end and decode it as a single WS frame payload. */
    private function readFramePayload($peer): ?string
    {
        $raw = @fread($peer, 65536);
        if ($raw === false || $raw === '') {
            return null;
        }
        $frame = WebSocket::decodeFrame($raw);
        return $frame['payload'] ?? null;
    }

    /**
     * Wire a fresh manager to a shared fake bus the way ensureWebSocketBackplane()
     * would in production — but inject the FakeBackplane as the manager's
     * internal bus (via reflection) so publish() actually fans out, and mark it
     * already-started so the server's lazy ensure() never overwrites it with a
     * real factory lookup.
     */
    private function wire(Server $server, FakeBackplane $bus): WebSocketBackplaneManager
    {
        $manager = new WebSocketBackplaneManager(
            function (string $kind, ?string $room, ?string $path, ?string $exclude, string $message) use ($server) {
                // Relay to the server's LOCAL connections only (never re-publishes).
                $rm = new \ReflectionMethod($server, 'relayWebSocketLocal');
                $rm->setAccessible(true);
                $rm->invoke($server, $kind, $room, $path, $exclude, $message);
            }
        );
        $rp = new \ReflectionProperty($manager, 'backplane');
        $rp->setAccessible(true);
        $rp->setValue($manager, $bus);
        $rs = new \ReflectionProperty($manager, 'started');
        $rs->setAccessible(true);
        $rs->setValue($manager, true);

        $bus->subscribe(WebSocketBackplaneManager::CHANNEL, [$manager, 'onRaw']);
        $server->setWebSocketBackplane($manager);
        return $manager;
    }

    // ── Backplane relay + origin guard (Server live path) ────────

    public function testRemoteBroadcastRelayedToLocalConnections(): void
    {
        $bus = new FakeBackplane();
        $serverA = new Server('127.0.0.1', 9101);
        $serverB = new Server('127.0.0.1', 9102);
        $this->wire($serverA, $bus);
        $mgrB = $this->wire($serverB, $bus);

        // B has a local connection that should receive A's broadcast.
        $pair = $this->makePair();
        $serverB->registerWebSocketClient('b1', $pair[0], '/');

        // A broadcasts to all — delivers locally (A has none) then publishes.
        $serverA->broadcastWebSocket('hello-cluster');

        $this->assertSame('hello-cluster', $this->readFramePayload($pair[1]));
    }

    public function testOriginGuardDropsOwnEcho(): void
    {
        $bus = new FakeBackplane();
        $server = new Server('127.0.0.1', 9103);
        $mgr = $this->wire($server, $bus);

        $pair = $this->makePair();
        $server->registerWebSocketClient('c1', $pair[0], '/');

        // Hand-craft an envelope whose src == this manager's instance id.
        $echo = json_encode([
            'src' => $mgr->instanceId,
            'kind' => 'all',
            'exclude' => null,
            'room' => null,
            'path' => null,
            'text' => 'echo-should-be-dropped',
        ]);
        $mgr->onRaw($echo);

        // Origin guard: the connection must NOT have received the echo.
        $this->assertNull($this->readFramePayload($pair[1]));
    }

    public function testRealBroadcastNoDoubleDelivery(): void
    {
        // End-to-end: a single broadcast on A is delivered once on A (locally)
        // and once on B (via relay) — A does not re-deliver its own echo even
        // though the fake bus fans the publish back to A's own onRaw.
        $bus = new FakeBackplane();
        $serverA = new Server('127.0.0.1', 9104);
        $serverB = new Server('127.0.0.1', 9105);
        $this->wire($serverA, $bus);
        $this->wire($serverB, $bus);

        $pairA = $this->makePair();
        $pairB = $this->makePair();
        $serverA->registerWebSocketClient('a1', $pairA[0], '/');
        $serverB->registerWebSocketClient('b1', $pairB[0], '/');

        $serverA->broadcastWebSocket('ping');

        // Exactly once each — the origin guard prevents A from re-delivering its
        // own echo. A's peer buffer must hold exactly ONE 'ping' frame: decode
        // it, then assert there are no trailing bytes (a second frame would mean
        // a double-delivery).
        $aRaw = @fread($pairA[1], 65536);
        $aFrame = WebSocket::decodeFrame($aRaw);
        $this->assertSame('ping', $aFrame['payload']);
        $this->assertSame($aFrame['length'], strlen($aRaw), 'A re-delivered its own echo (double-delivery)');

        $this->assertSame('ping', $this->readFramePayload($pairB[1]));
    }

    public function testRoomRelayTargetsOnlyRoomMembers(): void
    {
        $bus = new FakeBackplane();
        $serverA = new Server('127.0.0.1', 9106);
        $serverB = new Server('127.0.0.1', 9107);
        $this->wire($serverA, $bus);
        $this->wire($serverB, $bus);

        $inRoom = $this->makePair();
        $outRoom = $this->makePair();
        $serverB->registerWebSocketClient('b_in', $inRoom[0], '/');
        $serverB->registerWebSocketClient('b_out', $outRoom[0], '/');
        $serverB->joinRoom('b_in', 'lobby');

        $serverA->broadcastToRoom('lobby', 'room-msg');

        $this->assertSame('room-msg', $this->readFramePayload($inRoom[1]));
        $this->assertNull($this->readFramePayload($outRoom[1]));
    }

    public function testBytesRoundTripThroughEnvelope(): void
    {
        // Binary payloads survive the JSON envelope via base64.
        $bus = new FakeBackplane();
        $serverA = new Server('127.0.0.1', 9108);
        $serverB = new Server('127.0.0.1', 9109);
        $this->wire($serverA, $bus);
        $this->wire($serverB, $bus);

        $pair = $this->makePair();
        $serverB->registerWebSocketClient('b1', $pair[0], '/');

        $payload = "\x00\x01\x02\xfffoo"; // invalid UTF-8 → must travel as b64
        $serverA->broadcastWebSocket($payload);

        $this->assertSame($payload, $this->readFramePayload($pair[1]));
    }

    public function testPublishEncodesBytesAsB64AndStrAsText(): void
    {
        $bus = new FakeBackplane();
        $captured = [];
        $bus->subscribe(WebSocketBackplaneManager::CHANNEL, function ($raw) use (&$captured) {
            $captured[] = json_decode($raw, true);
        });

        $mgr = new WebSocketBackplaneManager(fn() => null);
        // Inject the fake bus via reflection (bypassing factory) so publish() fans out.
        $rp = new \ReflectionProperty($mgr, 'backplane');
        $rp->setAccessible(true);
        $rp->setValue($mgr, $bus);
        $rs = new \ReflectionProperty($mgr, 'started');
        $rs->setAccessible(true);
        $rs->setValue($mgr, true);

        $binary = "\xff\xfe\x00\x01"; // invalid UTF-8 → must travel as base64
        $mgr->publish('all', $binary);
        $mgr->publish('all', 'plain text');

        $this->assertArrayHasKey('b64', $captured[0]);
        $this->assertSame($binary, base64_decode($captured[0]['b64']));
        $this->assertSame('plain text', $captured[1]['text']);
        $this->assertSame($mgr->instanceId, $captured[0]['src']);
    }

    public function testPublishIsNoopWithoutBackplane(): void
    {
        $mgr = new WebSocketBackplaneManager(fn() => null);
        // No backplane wired → publish does nothing and never raises.
        $mgr->publish('all', 'noop');
        $this->assertFalse($mgr->isActive());
    }

    public function testPublishFailureDoesNotCrashBroadcast(): void
    {
        // A flaky message bus must never undo a local broadcast.
        $bus = new ExplodingBackplane();
        $server = new Server('127.0.0.1', 9110);
        $mgr = new WebSocketBackplaneManager(fn() => null);
        $rp = new \ReflectionProperty($mgr, 'backplane');
        $rp->setAccessible(true);
        $rp->setValue($mgr, $bus);
        $rs = new \ReflectionProperty($mgr, 'started');
        $rs->setAccessible(true);
        $rs->setValue($mgr, true);
        $server->setWebSocketBackplane($mgr);

        $pair = $this->makePair();
        $server->registerWebSocketClient('c1', $pair[0], '/');

        // broadcast delivers locally then publishes; publish raises but is
        // caught — local delivery still happened.
        $server->broadcastWebSocket('survive');

        $this->assertSame('survive', $this->readFramePayload($pair[1]));
    }

    // ── Broadcast resilience (dead client never aborts delivery) ──

    public function testDeadConnectionDoesNotAbortDeliveryAndIsPruned(): void
    {
        $server = new Server('127.0.0.1', 9111);

        $good1 = $this->makePair();
        $deadPair = $this->makePair();
        $good2 = $this->makePair();

        $server->registerWebSocketClient('g1', $good1[0], '/');
        $server->registerWebSocketClient('bad', $deadPair[0], '/');
        $server->registerWebSocketClient('g2', $good2[0], '/');

        // Close BOTH ends of the bad client's socket so fwrite() fails.
        fclose($deadPair[0]);
        fclose($deadPair[1]);

        $server->broadcastWebSocket('payload');

        // Both healthy connections received the message despite the bad one.
        $this->assertSame('payload', $this->readFramePayload($good1[1]));
        $this->assertSame('payload', $this->readFramePayload($good2[1]));

        // The dead connection was pruned from the registry.
        $ids = array_column($server->getWebSocketClients(), 'id');
        $this->assertNotContains('bad', $ids);
        $this->assertSame(2, $server->getWebSocketClientCount());
    }

    public function testDeadConnectionPrunedOnRoomBroadcast(): void
    {
        $server = new Server('127.0.0.1', 9112);

        $good = $this->makePair();
        $deadPair = $this->makePair();
        $server->registerWebSocketClient('g', $good[0], '/chat');
        $server->registerWebSocketClient('bad', $deadPair[0], '/chat');
        $server->joinRoom('g', 'r');
        $server->joinRoom('bad', 'r');
        fclose($deadPair[0]);
        fclose($deadPair[1]);

        $server->broadcastToRoom('r', 'hi');

        $this->assertSame('hi', $this->readFramePayload($good[1]));
        $ids = array_column($server->getWebSocketClients(), 'id');
        $this->assertNotContains('bad', $ids);
    }

    // ── SSE / streaming hardening ─────────────────────────────────

    public function testGeneratorRaisingMidStreamDoesNotCrash(): void
    {
        // A generator that raises mid-stream is handled cleanly: whatever was
        // yielded before the error is kept and no exception escapes.
        $response = new Response(testing: true);
        $result = $response->stream(function () {
            yield "data: one\n\n";
            throw new \RuntimeException('generator blew up');
        });

        // No crash propagated to the caller; the first chunk survived.
        $this->assertSame($response, $result);
        $this->assertSame("data: one\n\n", $response->getBody());
        $this->assertTrue($response->isSent());
    }

    public function testGeneratorRaisingImmediatelyDoesNotCrash(): void
    {
        $response = new Response(testing: true);
        $response->stream(function () {
            throw new \RuntimeException('immediate failure');
            yield; // unreachable, makes it a generator
        });

        $this->assertSame('', $response->getBody());
        $this->assertTrue($response->isSent());
    }

    // ── originAllowed() allow-list ────────────────────────────────

    public function testEmptyEnvAllowsAll(): void
    {
        putenv('TINA4_WS_ALLOWED_ORIGINS');
        unset($_ENV['TINA4_WS_ALLOWED_ORIGINS']);
        $this->assertTrue(WebSocket::originAllowed(['origin' => 'https://anything.example']));
        $this->assertTrue(WebSocket::originAllowed([]));
    }

    public function testBlankEnvAllowsAll(): void
    {
        putenv('TINA4_WS_ALLOWED_ORIGINS=   ');
        $_ENV['TINA4_WS_ALLOWED_ORIGINS'] = '   ';
        try {
            $this->assertTrue(WebSocket::originAllowed(['origin' => 'https://anything.example']));
        } finally {
            putenv('TINA4_WS_ALLOWED_ORIGINS');
            unset($_ENV['TINA4_WS_ALLOWED_ORIGINS']);
        }
    }

    public function testListedOriginAllowed(): void
    {
        putenv('TINA4_WS_ALLOWED_ORIGINS=https://app.example.com, https://admin.example.com');
        $_ENV['TINA4_WS_ALLOWED_ORIGINS'] = 'https://app.example.com, https://admin.example.com';
        try {
            $this->assertTrue(WebSocket::originAllowed(['origin' => 'https://app.example.com']));
            $this->assertTrue(WebSocket::originAllowed(['origin' => 'https://admin.example.com']));
        } finally {
            putenv('TINA4_WS_ALLOWED_ORIGINS');
            unset($_ENV['TINA4_WS_ALLOWED_ORIGINS']);
        }
    }

    public function testMismatchedOriginRejected(): void
    {
        putenv('TINA4_WS_ALLOWED_ORIGINS=https://app.example.com');
        $_ENV['TINA4_WS_ALLOWED_ORIGINS'] = 'https://app.example.com';
        try {
            $this->assertFalse(WebSocket::originAllowed(['origin' => 'https://evil.example.com']));
        } finally {
            putenv('TINA4_WS_ALLOWED_ORIGINS');
            unset($_ENV['TINA4_WS_ALLOWED_ORIGINS']);
        }
    }

    public function testMissingOriginRejectedWhenAllowlistActive(): void
    {
        putenv('TINA4_WS_ALLOWED_ORIGINS=https://app.example.com');
        $_ENV['TINA4_WS_ALLOWED_ORIGINS'] = 'https://app.example.com';
        try {
            $this->assertFalse(WebSocket::originAllowed([]));
        } finally {
            putenv('TINA4_WS_ALLOWED_ORIGINS');
            unset($_ENV['TINA4_WS_ALLOWED_ORIGINS']);
        }
    }

    public function testCaseInsensitiveHeaderKey(): void
    {
        putenv('TINA4_WS_ALLOWED_ORIGINS=https://app.example.com');
        $_ENV['TINA4_WS_ALLOWED_ORIGINS'] = 'https://app.example.com';
        try {
            $this->assertTrue(WebSocket::originAllowed(['Origin' => 'https://app.example.com']));
        } finally {
            putenv('TINA4_WS_ALLOWED_ORIGINS');
            unset($_ENV['TINA4_WS_ALLOWED_ORIGINS']);
        }
    }

    // ── Idle reaper opt-in ────────────────────────────────────────

    public function testIdleReaperDisabledIsNoop(): void
    {
        putenv('TINA4_WS_IDLE_TIMEOUT');
        unset($_ENV['TINA4_WS_IDLE_TIMEOUT']);
        $server = new Server('127.0.0.1', 9113);
        $pair = $this->makePair();
        $server->registerWebSocketClient('c1', $pair[0], '/');

        $this->assertSame(0, $server->reapIdleWebSocketClients());
        $this->assertSame(1, $server->getWebSocketClientCount());
    }

    public function testIdleReaperClosesStaleConnections(): void
    {
        putenv('TINA4_WS_IDLE_TIMEOUT=30');
        $_ENV['TINA4_WS_IDLE_TIMEOUT'] = '30';
        try {
            $server = new Server('127.0.0.1', 9114);
            $fresh = $this->makePair();
            $stale = $this->makePair();
            $server->registerWebSocketClient('fresh', $fresh[0], '/');
            $server->registerWebSocketClient('stale', $stale[0], '/');

            // Backdate the stale client's lastActivity well past the timeout.
            $rp = new \ReflectionProperty($server, 'wsClients');
            $rp->setAccessible(true);
            $clients = $rp->getValue($server);
            $clients['stale']['lastActivity'] = microtime(true) - 1000;
            $rp->setValue($server, $clients);

            $reaped = $server->reapIdleWebSocketClients();

            $this->assertSame(1, $reaped);
            $ids = array_column($server->getWebSocketClients(), 'id');
            $this->assertContains('fresh', $ids);
            $this->assertNotContains('stale', $ids);
        } finally {
            putenv('TINA4_WS_IDLE_TIMEOUT');
            unset($_ENV['TINA4_WS_IDLE_TIMEOUT']);
        }
    }

    // ── RFC 6455 §5.4 fragmented-frame reassembly ─────────────────

    public function testFragmentedMessageReassembledOnStandaloneServer(): void
    {
        // Build a fragmented TEXT message: a non-FIN TEXT frame + a FIN
        // continuation frame. The decoder + handler must reassemble "HelloWorld"
        // and dispatch it exactly once, only after the FIN bit.
        $ws = new WebSocket(9115);

        $received = [];
        $ws->on('message', function ($id, $msg) use (&$received) {
            $received[] = $msg;
        });

        // Drive the private frame loop directly via reflection on a fake client.
        $pair = $this->makePair();
        $rp = new \ReflectionProperty($ws, 'clients');
        $rp->setAccessible(true);
        $rp->setValue($ws, [
            'frag' => [
                'socket' => $pair[0],
                'ip' => 'test',
                'connected_at' => time(),
                'lastActivity' => microtime(true),
                'buffer' => '',
                'path' => '/',
                'rooms' => [],
                'fragments' => '',
                'fragmentOpcode' => 0,
            ],
        ]);

        // Two client→server frames (masked, as RFC requires from clients).
        $frame1 = $this->clientFrame(WebSocket::OP_TEXT, 'Hello', false);
        $frame2 = $this->clientFrame(WebSocket::OP_CONTINUATION, 'World', true);
        // Feed them through the peer end so handleClientData reads them.
        fwrite($pair[1], $frame1 . $frame2);

        $rm = new \ReflectionMethod($ws, 'handleClientData');
        $rm->setAccessible(true);
        $rm->invoke($ws, 'frag');

        $this->assertSame(['HelloWorld'], $received);
    }

    /** Build a client→server (masked) WebSocket frame. */
    private function clientFrame(int $opcode, string $payload, bool $fin): string
    {
        $b0 = ($fin ? 0x80 : 0x00) | $opcode;
        $len = strlen($payload);
        $mask = "\x12\x34\x56\x78";
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
        }
        $frame = chr($b0);
        // Payloads here are < 126, so a single length byte with the mask bit set.
        $frame .= chr(0x80 | $len);
        $frame .= $mask;
        $frame .= $masked;
        return $frame;
    }
}
