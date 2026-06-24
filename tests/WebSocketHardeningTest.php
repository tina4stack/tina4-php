<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Lock-in tests for the WebSocket + SSE hardening sweep (mirrors the
 * Python-master tests/test_websocket_hardening.py):
 *
 *   - Backplane relay across REAL instances (publish over a real Redis channel,
 *     drain on poll() + origin guard)
 *   - origin-guard-no-echo invariant (we never re-deliver our own broadcast)
 *   - bytes round-trip through the JSON envelope (base64)
 *   - Broadcast resilience (one dead client never aborts delivery; it is pruned)
 *   - Backplane publish failure (REAL socket to a closed port) never undoes the
 *     local broadcast
 *   - SSE streaming error handling (generator raises mid-stream / disconnect)
 *   - originAllowed() allow-list semantics (empty = allow, set = reject)
 *   - Idle reaper opt-in semantics
 *   - OP_CONTINUATION fragmented-frame reassembly (RFC 6455 §5.4)
 *
 * NO mocks/fakes/doubles. The backplane tests speak the REAL RESP protocol over
 * a real TCP socket to a real Redis/Valkey at 127.0.0.1:6379 (the zero-dependency
 * raw path the framework ships when ext-redis is absent) — every relay crosses
 * the live `tina4:ws` pub/sub channel. The failure-handling test triggers a REAL
 * publish failure by pointing a real backplane at a closed port (127.0.0.1:59999).
 * Loopback stream_socket_pair()s are real local TCP sockets standing in for live
 * WebSocket connections so we can read back exactly which frames were delivered.
 * The backplane tests are gated on a reachable Redis and are NOT mock-substituted
 * when it is absent — they skip, never fake.
 */

use PHPUnit\Framework\TestCase;
use Tina4\RedisBackplane;
use Tina4\Response;
use Tina4\Server;
use Tina4\WebSocket;
use Tina4\WebSocketBackplaneManager;

class WebSocketHardeningTest extends TestCase
{
    /** Real Redis/Valkey endpoint the backplane tests exercise. */
    private const REDIS_HOST = '127.0.0.1';
    private const REDIS_PORT = 6379;

    /** @var array<int, array{0: resource, 1: resource}> socket pairs to clean up */
    private array $pairs = [];

    /** @var WebSocketBackplaneManager[] real managers to tear down (closes their Redis sockets) */
    private array $managers = [];

    protected function tearDown(): void
    {
        foreach ($this->managers as $manager) {
            try {
                $manager->close();
            } catch (\Throwable $e) {
                // best-effort
            }
        }
        $this->managers = [];

        foreach ($this->pairs as $pair) {
            foreach ($pair as $sock) {
                if (is_resource($sock)) {
                    @fclose($sock);
                }
            }
        }
        $this->pairs = [];

        putenv('TINA4_WS_BACKPLANE');
        putenv('TINA4_WS_BACKPLANE_URL');
        unset($_ENV['TINA4_WS_BACKPLANE'], $_ENV['TINA4_WS_BACKPLANE_URL']);
    }

    /**
     * Skip (never fake) when no real Redis/Valkey is listening. A raw RESP PING
     * over a real socket is the same handshake the backplane itself performs.
     */
    private function requireRedis(): void
    {
        $sock = @fsockopen(self::REDIS_HOST, self::REDIS_PORT, $errno, $errstr, 2);
        if (!$sock) {
            $this->markTestSkipped(
                sprintf('Redis/Valkey not reachable at %s:%d (%s) — backplane test needs a real service',
                    self::REDIS_HOST, self::REDIS_PORT, $errstr)
            );
        }
        fwrite($sock, "*1\r\n\$4\r\nPING\r\n");
        $pong = fread($sock, 64);
        fclose($sock);
        if ($pong === false || !str_starts_with($pong, '+PONG')) {
            $this->markTestSkipped(
                sprintf('Service at %s:%d did not answer PING with PONG — not a usable Redis/Valkey',
                    self::REDIS_HOST, self::REDIS_PORT)
            );
        }
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
     * Wire a fresh manager to the REAL Redis backplane the way production does:
     * set TINA4_WS_BACKPLANE=redis + URL, build the manager with the relay sink,
     * then ensure() — which constructs a real RedisBackplane (raw RESP over a
     * real socket, or phpredis if present), subscribes it to the live
     * `tina4:ws` channel, and PINGs the server on connect. No injection, no
     * double — exactly the live wiring path.
     */
    private function wire(Server $server): WebSocketBackplaneManager
    {
        putenv('TINA4_WS_BACKPLANE=redis');
        putenv('TINA4_WS_BACKPLANE_URL=tcp://' . self::REDIS_HOST . ':' . self::REDIS_PORT);
        $_ENV['TINA4_WS_BACKPLANE'] = 'redis';
        $_ENV['TINA4_WS_BACKPLANE_URL'] = 'tcp://' . self::REDIS_HOST . ':' . self::REDIS_PORT;

        $manager = new WebSocketBackplaneManager(
            function (string $kind, ?string $room, ?string $path, ?string $exclude, string $message) use ($server) {
                // Relay to the server's LOCAL connections only (never re-publishes).
                $rm = new \ReflectionMethod($server, 'relayWebSocketLocal');
                $rm->invoke($server, $kind, $room, $path, $exclude, $message);
            }
        );
        $manager->ensure();
        $this->assertTrue(
            $manager->isActive(),
            'manager failed to wire a real Redis backplane — cannot run the relay test against a live service'
        );
        $server->setWebSocketBackplane($manager);
        $this->managers[] = $manager;
        return $manager;
    }

    /**
     * Drain the given managers' real Redis subscriber sockets repeatedly until
     * $done() returns true or the deadline passes. Real pub/sub is asynchronous:
     * a published message lands on the subscriber socket after a network
     * round-trip, so the single-threaded server drains it on its idle tick —
     * this models that tick deterministically in the test.
     *
     * @param WebSocketBackplaneManager[] $managers
     */
    private function pumpUntil(array $managers, callable $done, float $timeoutSeconds = 5.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            foreach ($managers as $manager) {
                $manager->poll();
            }
            if ($done()) {
                return;
            }
            usleep(20000);
        }
        // Final drain so an assertion right after sees the last poll's effect.
        foreach ($managers as $manager) {
            $manager->poll();
        }
    }

    /**
     * Let both managers' SUBSCRIBE land on the server before the first publish,
     * so the publish is not lost to a not-yet-subscribed channel. Drains the
     * subscribe confirmations off each socket.
     *
     * @param WebSocketBackplaneManager[] $managers
     */
    private function settleSubscriptions(array $managers): void
    {
        $deadline = microtime(true) + 1.0;
        while (microtime(true) < $deadline) {
            foreach ($managers as $manager) {
                $manager->poll();
            }
            usleep(20000);
        }
    }

    // ── Backplane relay + origin guard (Server live path, REAL Redis) ────────

    public function testRemoteBroadcastRelayedToLocalConnections(): void
    {
        $this->requireRedis();
        $serverA = new Server('127.0.0.1', 9101);
        $serverB = new Server('127.0.0.1', 9102);
        $mgrA = $this->wire($serverA);
        $mgrB = $this->wire($serverB);
        $this->settleSubscriptions([$mgrA, $mgrB]);

        // B has a local connection that should receive A's broadcast.
        $pair = $this->makePair();
        $serverB->registerWebSocketClient('b1', $pair[0], '/');

        // A broadcasts to all — delivers locally (A has none) then publishes
        // over the real Redis channel.
        $serverA->broadcastWebSocket('hello-cluster');

        $received = null;
        $this->pumpUntil([$mgrA, $mgrB], function () use ($pair, &$received) {
            $payload = $this->readFramePayload($pair[1]);
            if ($payload !== null) {
                $received = $payload;
                return true;
            }
            return false;
        });

        $this->assertSame('hello-cluster', $received, 'B never received A\'s broadcast over real Redis');
    }

    public function testOriginGuardDropsOwnEcho(): void
    {
        $this->requireRedis();
        $server = new Server('127.0.0.1', 9103);
        $mgr = $this->wire($server);
        $this->settleSubscriptions([$mgr]);

        $pair = $this->makePair();
        $server->registerWebSocketClient('c1', $pair[0], '/');

        // Broadcast from this very instance: it publishes to the real channel and
        // its own subscriber will read the echo back. The origin guard (src ==
        // our instance id) must drop that echo so the local connection is NOT
        // double-delivered. (Local delivery already happened in broadcast()).
        $server->broadcastWebSocket('echo-should-not-double');

        // Drain the local delivery first.
        $this->assertSame('echo-should-not-double', $this->readFramePayload($pair[1]));

        // Now pump real Redis: the echo comes back on our own subscriber but the
        // origin guard drops it — no second frame may appear.
        $this->pumpUntil([$mgr], fn() => false, 1.0);
        $this->assertNull(
            $this->readFramePayload($pair[1]),
            'origin guard failed: our own echo was re-delivered (double-delivery)'
        );
    }

    public function testRealBroadcastNoDoubleDelivery(): void
    {
        $this->requireRedis();
        // End-to-end across REAL Redis: a single broadcast on A is delivered once
        // on A (locally) and once on B (via the real channel) — A does not
        // re-deliver its own echo even though Redis fans the publish back to A's
        // own subscriber.
        $serverA = new Server('127.0.0.1', 9104);
        $serverB = new Server('127.0.0.1', 9105);
        $mgrA = $this->wire($serverA);
        $mgrB = $this->wire($serverB);
        $this->settleSubscriptions([$mgrA, $mgrB]);

        $pairA = $this->makePair();
        $pairB = $this->makePair();
        $serverA->registerWebSocketClient('a1', $pairA[0], '/');
        $serverB->registerWebSocketClient('b1', $pairB[0], '/');

        $serverA->broadcastWebSocket('ping');

        $bReceived = null;
        $this->pumpUntil([$mgrA, $mgrB], function () use ($pairB, &$bReceived) {
            $payload = $this->readFramePayload($pairB[1]);
            if ($payload !== null) {
                $bReceived = $payload;
                return true;
            }
            return false;
        });
        $this->assertSame('ping', $bReceived, 'B never received A\'s broadcast over real Redis');

        // A's peer buffer must hold exactly ONE 'ping' frame (the local delivery):
        // decode it, then assert there are no trailing bytes (a second frame would
        // mean the origin guard failed and A re-delivered its own echo).
        $aRaw = @fread($pairA[1], 65536);
        $aFrame = WebSocket::decodeFrame($aRaw);
        $this->assertSame('ping', $aFrame['payload']);
        $this->assertSame($aFrame['length'], strlen($aRaw), 'A re-delivered its own echo (double-delivery)');
    }

    public function testRoomRelayTargetsOnlyRoomMembers(): void
    {
        $this->requireRedis();
        $serverA = new Server('127.0.0.1', 9106);
        $serverB = new Server('127.0.0.1', 9107);
        $mgrA = $this->wire($serverA);
        $mgrB = $this->wire($serverB);
        $this->settleSubscriptions([$mgrA, $mgrB]);

        $inRoom = $this->makePair();
        $outRoom = $this->makePair();
        $serverB->registerWebSocketClient('b_in', $inRoom[0], '/');
        $serverB->registerWebSocketClient('b_out', $outRoom[0], '/');
        $serverB->joinRoom('b_in', 'lobby');

        $serverA->broadcastToRoom('lobby', 'room-msg');

        $inReceived = null;
        $this->pumpUntil([$mgrA, $mgrB], function () use ($inRoom, &$inReceived) {
            $payload = $this->readFramePayload($inRoom[1]);
            if ($payload !== null) {
                $inReceived = $payload;
                return true;
            }
            return false;
        });

        $this->assertSame('room-msg', $inReceived, 'room member never received the relayed broadcast');
        $this->assertNull($this->readFramePayload($outRoom[1]), 'non-member received a room broadcast');
    }

    public function testBytesRoundTripThroughEnvelope(): void
    {
        $this->requireRedis();
        // Binary payloads survive the JSON envelope via base64, end-to-end over
        // real Redis.
        $serverA = new Server('127.0.0.1', 9108);
        $serverB = new Server('127.0.0.1', 9109);
        $mgrA = $this->wire($serverA);
        $mgrB = $this->wire($serverB);
        $this->settleSubscriptions([$mgrA, $mgrB]);

        $pair = $this->makePair();
        $serverB->registerWebSocketClient('b1', $pair[0], '/');

        $payload = "\x00\x01\x02\xfffoo"; // invalid UTF-8 → must travel as b64
        $serverA->broadcastWebSocket($payload);

        $received = null;
        $this->pumpUntil([$mgrA, $mgrB], function () use ($pair, &$received) {
            $got = $this->readFramePayload($pair[1]);
            if ($got !== null) {
                $received = $got;
                return true;
            }
            return false;
        });

        $this->assertSame($payload, $received, 'binary payload did not survive the real-Redis round-trip');
    }

    public function testPublishEncodesBytesAsB64AndStrAsText(): void
    {
        $this->requireRedis();
        // A second REAL backplane subscribes to the live `tina4:ws` channel and
        // captures exactly what the manager publishes — proving the on-wire
        // envelope shape over a real socket (no double).
        $url = 'tcp://' . self::REDIS_HOST . ':' . self::REDIS_PORT;
        $captureSub = new RedisBackplane($url);
        $captured = [];
        $captureSub->subscribe(WebSocketBackplaneManager::CHANNEL, function ($raw) use (&$captured) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $captured[] = $decoded;
            }
        });

        putenv('TINA4_WS_BACKPLANE=redis');
        putenv('TINA4_WS_BACKPLANE_URL=' . $url);
        $_ENV['TINA4_WS_BACKPLANE'] = 'redis';
        $_ENV['TINA4_WS_BACKPLANE_URL'] = $url;
        $mgr = new WebSocketBackplaneManager(fn() => null);
        $mgr->ensure();
        $this->managers[] = $mgr;
        $this->assertTrue($mgr->isActive(), 'manager failed to wire real Redis');

        // Let the capture subscriber's SUBSCRIBE land on the server.
        $deadline = microtime(true) + 1.0;
        while (microtime(true) < $deadline) {
            $captureSub->poll();
            usleep(20000);
        }

        $binary = "\xff\xfe\x00\x01"; // invalid UTF-8 → must travel as base64
        $mgr->publish('all', $binary);
        $mgr->publish('all', 'plain text');

        // Drain the two published envelopes off the real channel.
        $pumpDeadline = microtime(true) + 5.0;
        while (microtime(true) < $pumpDeadline && count($captured) < 2) {
            $captureSub->poll();
            usleep(20000);
        }
        $captureSub->close();

        $this->assertCount(2, $captured, 'capture subscriber did not receive both envelopes over real Redis');
        $this->assertArrayHasKey('b64', $captured[0]);
        $this->assertSame($binary, base64_decode($captured[0]['b64']));
        $this->assertSame('plain text', $captured[1]['text']);
        $this->assertSame($mgr->instanceId, $captured[0]['src']);
    }

    public function testPublishIsNoopWithoutBackplane(): void
    {
        // Pure-logic: no backplane wired → publish does nothing and never raises.
        // (No dependency, no double — a genuine unit test.)
        $mgr = new WebSocketBackplaneManager(fn() => null);
        $mgr->publish('all', 'noop');
        $this->assertFalse($mgr->isActive());
    }

    public function testPublishFailureDoesNotCrashBroadcast(): void
    {
        $this->requireRedis();
        // A flaky message bus must never undo a local broadcast. We trigger a
        // REAL publish failure: construct a real RedisBackplane against the live
        // server (so it connects + PINGs successfully), then break its transport
        // for real. No fake/stub — the break differs by which client the build
        // uses, so handle BOTH:
        //   * raw RESP path (no ext-redis): each publish opens a fresh socket to
        //     host:port, so repointing at a CLOSED port (127.0.0.1:59999) makes
        //     fsockopen genuinely refuse and publish() throw.
        //   * ext-redis path (phpredis present, e.g. the CI runner image): the
        //     connection is already open, so moving host/port fields would not
        //     move it. Instead CLOSE the live phpredis handle — a real "server
        //     went away" — so the next publish() fails against a dead connection.
        $bus = new RedisBackplane('tcp://' . self::REDIS_HOST . ':' . self::REDIS_PORT);
        $rpPort = new \ReflectionProperty($bus, 'port');
        $rpPort->setValue($bus, 59999);
        $rpHost = new \ReflectionProperty($bus, 'host');
        $rpHost->setValue($bus, '127.0.0.1');

        $useRaw = (new \ReflectionProperty($bus, 'useRaw'))->getValue($bus);
        if (!$useRaw) {
            // Close the real, non-persistent phpredis handle. A non-persistent
            // connection does NOT auto-reconnect, so the next publish() throws
            // RedisException ("Redis server went away") against the dead handle.
            $redis = (new \ReflectionProperty($bus, 'redis'))->getValue($bus);
            $redis->close();
        }

        // Sanity: prove publish() genuinely fails over the real closed socket.
        $threw = false;
        try {
            $bus->publish('tina4:ws', 'should-fail');
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'expected a REAL publish failure to the closed port 127.0.0.1:59999');

        $server = new Server('127.0.0.1', 9110);
        $mgr = new WebSocketBackplaneManager(fn() => null);
        $rp = new \ReflectionProperty($mgr, 'backplane');
        $rp->setValue($mgr, $bus);
        $rs = new \ReflectionProperty($mgr, 'started');
        $rs->setValue($mgr, true);
        $server->setWebSocketBackplane($mgr);

        $pair = $this->makePair();
        $server->registerWebSocketClient('c1', $pair[0], '/');

        // broadcast delivers locally then publishes; publish raises (real socket
        // failure) but the manager catches it — local delivery still happened.
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
