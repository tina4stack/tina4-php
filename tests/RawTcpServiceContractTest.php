<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tina4\Tcp;
use Tina4\TcpService;
use Tina4\TcpConnection;

/**
 * Raw TCP service contract — feature 142, ADR-0102 (PHP port of the Python
 * reference tests). Every case runs a REAL client socket against a REAL
 * in-process listener — no mocks, no doubles. The pure cases drive the service
 * through its real pump() (one stream_select pass, the same handler methods
 * Server's loop drives); two cases boot a REAL framework child server so the
 * listener folds into the genuine accept loop and shutdown path.
 *
 * Each test names, in its docblock, the mutation that must turn it red.
 */
class RawTcpServiceContractTest extends TestCase
{
    /** @var resource|null */
    private $proc = null;
    private ?string $appDir = null;
    /** @var TcpService[] */
    private array $started = [];

    protected function tearDown(): void
    {
        foreach ($this->started as $svc) {
            $svc->stop();
        }
        $this->started = [];
        Tcp::clearRegistry();
        if (is_resource($this->proc)) {
            @proc_terminate($this->proc, defined('SIGKILL') ? SIGKILL : 9);
            @proc_close($this->proc);
            $this->proc = null;
        }
    }

    // ── Helpers (real sockets only) ──────────────────────────────────────────

    private function makeService(array $options): TcpService
    {
        $svc = new TcpService(0, $options);
        $svc->start('127.0.0.1');
        $this->started[] = $svc;
        return $svc;
    }

    /** @return resource */
    private function connect(int $port)
    {
        $client = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 1.0);
        $this->assertIsResource($client, "client connect failed: {$errstr} ({$errno})");
        stream_set_blocking($client, false);
        return $client;
    }

    private function pumpUntil(TcpService $svc, callable $cond, float $timeout = 2.0): bool
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $svc->pump(0.02);
            if ($cond()) {
                return true;
            }
        }
        return $cond();
    }

    /** Read exactly $n bytes from a client, pumping the server as we wait. */
    private function clientRead(TcpService $svc, $client, int $n, float $timeout = 2.0): string
    {
        $buf = '';
        $deadline = microtime(true) + $timeout;
        while (strlen($buf) < $n && microtime(true) < $deadline) {
            $svc->pump(0.01);
            $chunk = @fread($client, $n - strlen($buf));
            if ($chunk !== false && $chunk !== '') {
                $buf .= $chunk;
            }
        }
        return $buf;
    }

    /** True when the client socket has hit EOF (peer closed), pumping to settle. */
    private function clientAtEof(TcpService $svc, $client, float $timeout = 2.0): bool
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $svc->pump(0.01);
            $chunk = @fread($client, 1);
            if ($chunk === '' && feof($client)) {
                return true;
            }
        }
        return feof($client);
    }

    /** A service whose on_data identifies by a 'LOGIN:<id>' frame. */
    private function loginService(): TcpService
    {
        return $this->makeService([
            'on_data' => function (TcpConnection $conn, string $data) {
                if (str_starts_with($data, 'LOGIN:')) {
                    $conn->identify(substr($data, 6));
                }
            },
        ]);
    }

    // ── tcp-callbacks-raw-and-line ───────────────────────────────────────────

    /**
     * on_data sees raw bytes, not newline-split, not decoded. Mutation: split
     * on_data on newlines and the joined bytes differ.
     */
    public function testOnDataDeliversRawBytesNotSplitOnNewlines(): void
    {
        $received = [];
        $svc = $this->makeService([
            'on_data' => function (TcpConnection $c, string $d) use (&$received) {
                $received[] = $d;
            },
        ]);
        $client = $this->connect($svc->boundPort());
        fwrite($client, "a\nb\nc");
        $this->pumpUntil($svc, fn() => implode('', $received) === "a\nb\nc");
        $joined = implode('', $received);
        $this->assertSame("a\nb\nc", $joined, 'raw bytes must arrive intact');
        $this->assertStringContainsString("\n", $joined, 'newlines must NOT be stripped');
        fclose($client);
    }

    /**
     * on_line delivers one line at a time, terminator stripped, tail buffered
     * until its newline. Mutation: stop stripping \r, or deliver the tail early.
     */
    public function testOnLineSplitsANewlineDelimitedStream(): void
    {
        $lines = [];
        $svc = $this->makeService([
            'on_line' => function (TcpConnection $c, string $line) use (&$lines) {
                $lines[] = $line;
            },
        ]);
        $client = $this->connect($svc->boundPort());
        fwrite($client, "a\r\nb\nc");
        $this->pumpUntil($svc, fn() => $lines === ['a', 'b']);
        $this->assertSame(['a', 'b'], $lines, 'terminator must be stripped; tail buffered');
        fwrite($client, "\n");
        $this->pumpUntil($svc, fn() => $lines === ['a', 'b', 'c']);
        $this->assertSame(['a', 'b', 'c'], $lines, 'the tail delivers only once terminated');
        fclose($client);
    }

    /**
     * on_close fires exactly once on peer close and the connection leaves the
     * registry. Mutation: fire on_close twice, or never, or skip removal.
     */
    public function testOnCloseFiresOnceWhenPeerCloses(): void
    {
        $closes = 0;
        $svc = $this->makeService([
            'on_close' => function (TcpConnection $c) use (&$closes) {
                $closes++;
            },
        ]);
        $client = $this->connect($svc->boundPort());
        $this->pumpUntil($svc, fn() => $svc->count() === 1);
        $this->assertSame(1, $svc->count());
        fclose($client);
        $this->pumpUntil($svc, fn() => $closes === 1 && $svc->count() === 0);
        $this->assertSame(1, $closes, 'on_close must fire exactly once');
        $this->assertSame(0, $svc->count(), 'a closed connection must leave the registry');
    }

    // ── tcp-binary-roundtrip-ack ─────────────────────────────────────────────

    /**
     * A binary frame in, a fixed ACK out on the same socket, bytes exact and in
     * order. Mutation: have send() mangle/reorder bytes and the read fails.
     */
    public function testBinaryFrameRoundtripsAndIsAcked(): void
    {
        $ack = "\x78\x78\x05\x01";
        $frame = "\x78\x78\x11\x0A\xFF\x00";
        $svc = $this->makeService([
            'on_data' => function (TcpConnection $conn, string $data) use ($ack) {
                $conn->send($ack);
            },
        ]);
        $client = $this->connect($svc->boundPort());
        fwrite($client, $frame);
        $got = $this->clientRead($svc, $client, strlen($ack));
        $this->assertSame($ack, $got, 'ACK bytes must round-trip exactly');
        fclose($client);
    }

    // ── tcp-identify-registry-push ───────────────────────────────────────────

    /**
     * identify() registers; sendTo() reaches that exact socket. Mutation: make
     * registerId a no-op and sendTo never arrives.
     */
    public function testIdentifyRegistersAndSendToReachesThatSocket(): void
    {
        $svc = $this->loginService();
        $client = $this->connect($svc->boundPort());
        fwrite($client, 'LOGIN:imeiA');
        $this->assertTrue(
            $this->pumpUntil($svc, fn() => $svc->connection('imeiA') !== null),
            'identify() must register the connection under its id'
        );
        $this->assertTrue($svc->sendTo('imeiA', 'CMD1'));
        $got = $this->clientRead($svc, $client, 4);
        $this->assertSame('CMD1', $got, 'sendTo must reach the identified socket');
        fclose($client);
    }

    /**
     * sendTo('imeiA') reaches A and NOT B. Mutation: broadcast instead of
     * addressing one, and B wrongly receives.
     */
    public function testSendToIsIsolatedToTheAddressedDevice(): void
    {
        $svc = $this->loginService();
        $a = $this->connect($svc->boundPort());
        $b = $this->connect($svc->boundPort());
        fwrite($a, 'LOGIN:imeiA');
        fwrite($b, 'LOGIN:imeiB');
        $this->pumpUntil($svc, fn() => $svc->connection('imeiA') && $svc->connection('imeiB'));
        $svc->sendTo('imeiA', 'ONLYA');
        $this->assertSame('ONLYA', $this->clientRead($svc, $a, 5));
        // B must receive nothing.
        $svc->pump(0.05);
        $this->assertSame('', (string)@fread($b, 16), 'sendTo must not reach the other device');
        fclose($a);
        fclose($b);
    }

    /**
     * sendTo an unknown id returns falsey without raising. Mutation: raise on a
     * missing id and this errors instead of passing.
     */
    public function testSendToUnknownIdIsANoopNotAThrow(): void
    {
        $svc = $this->loginService();
        $this->assertFalse($svc->sendTo('ghost', 'CMD'), 'unknown id must be a no-op');
    }

    /**
     * broadcast() reaches all three sockets. Mutation: iterate a subset and a
     * client misses the message.
     */
    public function testBroadcastReachesEveryLiveConnection(): void
    {
        $svc = $this->loginService();
        $clients = [];
        foreach (['imei1', 'imei2', 'imei3'] as $id) {
            $c = $this->connect($svc->boundPort());
            fwrite($c, 'LOGIN:' . $id);
            $clients[] = $c;
        }
        $this->pumpUntil($svc, fn() => $svc->count() === 3);
        $this->assertSame(3, $svc->broadcast('PING!'), 'broadcast must reach all 3');
        foreach ($clients as $c) {
            $this->assertSame('PING!', $this->clientRead($svc, $c, 5));
            fclose($c);
        }
    }

    /**
     * A second connection identifying as 'imeiA' supersedes the first. Mutation:
     * keep the first registered and sendTo reaches the stale socket.
     */
    public function testReidentifyWithTheSameIdSupersedesTheFirst(): void
    {
        $svc = $this->loginService();
        $c1 = $this->connect($svc->boundPort());
        fwrite($c1, 'LOGIN:imeiA');
        $this->pumpUntil($svc, fn() => $svc->connection('imeiA') !== null);
        $first = $svc->connection('imeiA');

        $c2 = $this->connect($svc->boundPort());
        fwrite($c2, 'LOGIN:imeiA');
        $this->pumpUntil($svc, fn() => $svc->connection('imeiA') !== $first);

        $svc->sendTo('imeiA', 'TO2!!');
        $this->assertSame('TO2!!', $this->clientRead($svc, $c2, 5), 'sendTo must reach the live connection');
        $svc->pump(0.05);
        $this->assertSame('', (string)@fread($c1, 16), 'the superseded socket must not receive');
        fclose($c1);
        fclose($c2);
    }

    // ── tcp-connection-object ────────────────────────────────────────────────

    /**
     * remoteAddr is the raw socket peer, never a forwarded header. Mutation:
     * return a header value and the loopback assert fails.
     */
    public function testRemoteAddrIsTheRawSocketPeer(): void
    {
        $seen = [];
        $svc = $this->makeService([
            'on_connect' => function (TcpConnection $c) use (&$seen) {
                $seen[] = $c->remoteAddr;
            },
        ]);
        $client = $this->connect($svc->boundPort());
        $this->pumpUntil($svc, fn() => $seen !== []);
        $this->assertNotEmpty($seen, 'on_connect must have run');
        $this->assertStringContainsString('127.0.0.1', $seen[0], 'remoteAddr must be the raw loopback peer');
        fclose($client);
    }

    /**
     * context persists across on_data for one connection, and a different
     * connection has its own. Mutation: rebuild context per call and the
     * accumulated buffer is lost.
     */
    public function testContextPersistsAcrossOnDataForOneConnection(): void
    {
        $svc = $this->makeService([
            'on_data' => function (TcpConnection $conn, string $data) {
                $conn->context['buf'] = ($conn->context['buf'] ?? '') . $data;
            },
        ]);
        $c1 = $this->connect($svc->boundPort());
        fwrite($c1, 'AA');
        $svc->pump(0.05);
        fwrite($c1, 'BB');
        $this->assertTrue($this->pumpUntil(
            $svc,
            fn() => $this->anyContextBuf($svc, 'AABB')
        ), 'context must accumulate across on_data calls');
        $c2 = $this->connect($svc->boundPort());
        fwrite($c2, 'ZZ');
        $this->pumpUntil($svc, fn() => $this->anyContextBuf($svc, 'ZZ'));
        $this->assertTrue($this->anyContextBuf($svc, 'AABB'), 'first connection keeps its own buffer');
        $this->assertTrue($this->anyContextBuf($svc, 'ZZ'), 'second connection has its own buffer');
        fclose($c1);
        fclose($c2);
    }

    private function anyContextBuf(TcpService $svc, string $want): bool
    {
        $prop = new \ReflectionProperty(TcpService::class, 'connections');
        foreach ($prop->getValue($svc) as $conn) {
            if (($conn->context['buf'] ?? null) === $want) {
                return true;
            }
        }
        return false;
    }

    /**
     * close() closes the socket, fires on_close once, removes the registry
     * entry. Mutation: skip removal in close() and count stays 1.
     */
    public function testCloseEndsTheConnectionAndFiresOnClose(): void
    {
        $closed = [];
        $svc = $this->loginService();
        $svc->onClose(function (TcpConnection $c) use (&$closed) {
            $closed[] = $c->id;
        });
        $client = $this->connect($svc->boundPort());
        fwrite($client, 'LOGIN:imeiZ');
        $this->pumpUntil($svc, fn() => $svc->connection('imeiZ') !== null);
        $conn = $svc->connection('imeiZ');
        $conn->close();
        $this->assertTrue($this->clientAtEof($svc, $client), 'close() must close the client socket');
        $this->pumpUntil($svc, fn() => $closed === ['imeiZ'] && $svc->count() === 0);
        $this->assertSame(['imeiZ'], $closed, 'on_close must fire once');
        $this->assertNull($svc->connection('imeiZ'), 'registry entry must be removed');
        if (is_resource($client)) {
            fclose($client);
        }
    }

    // ── tcp-max-connections-refusal ──────────────────────────────────────────

    /**
     * With max_connections=2 the third connection is refused/closed and the two
     * live ones are unaffected. Mutation: drop the limit check and count hits 3.
     */
    public function testNthPlusOneConnectionIsRefused(): void
    {
        $svc = $this->makeService(['max_connections' => 2]);
        $c1 = $this->connect($svc->boundPort());
        $c2 = $this->connect($svc->boundPort());
        $this->pumpUntil($svc, fn() => $svc->count() === 2);
        $c3 = $this->connect($svc->boundPort());
        $this->assertTrue($this->clientAtEof($svc, $c3), 'the (N+1)th connection must be refused/closed');
        $svc->pump(0.05);
        $this->assertSame(2, $svc->count(), 'existing connections must be unaffected');
        fclose($c1);
        fclose($c2);
        if (is_resource($c3)) {
            fclose($c3);
        }
    }

    // ── tcp-idle-reaper-default-off ──────────────────────────────────────────

    /**
     * idle_timeout=0 (default) never reaps a silent connection. Mutation: reap
     * when timeout==0 and the silent connection is closed.
     */
    public function testIdleTimeoutZeroKeepsASilentConnectionOpen(): void
    {
        $svc = $this->makeService(['idle_timeout' => 0]);
        $client = $this->connect($svc->boundPort());
        $this->pumpUntil($svc, fn() => $svc->count() === 1);
        $deadline = microtime(true) + 0.6;
        while (microtime(true) < $deadline) {
            $svc->pump(0.05);
        }
        $this->assertSame(1, $svc->count(), 'a silent connection must stay open when timeout=0');
        fclose($client);
    }

    /**
     * idle_timeout>0 reaps a silent connection (on_close fires) but spares one
     * that keeps sending. Mutation: reap on activity too and the active one dies.
     */
    public function testIdleTimeoutPositiveReapsOnlyTheSilentConnection(): void
    {
        $closes = 0;
        $svc = $this->makeService([
            'idle_timeout' => 0.3,
            'on_close' => function (TcpConnection $c) use (&$closes) {
                $closes++;
            },
        ]);
        $silent = $this->connect($svc->boundPort());
        $active = $this->connect($svc->boundPort());
        $this->pumpUntil($svc, fn() => $svc->count() === 2);
        // Keep the active one busy across the reap window.
        $deadline = microtime(true) + 0.8;
        while (microtime(true) < $deadline) {
            fwrite($active, 'x');
            $svc->pump(0.08);
        }
        $this->assertTrue($this->pumpUntil($svc, fn() => $closes === 1, 1.0), 'the silent connection must be reaped');
        $this->assertSame(1, $svc->count(), 'the active connection must NOT be reaped');
        fclose($silent);
        fclose($active);
    }

    // ── tcp-backpressure ─────────────────────────────────────────────────────

    /**
     * A client that never reads is bounded to the backlog ceiling and dropped,
     * while other connections keep serving. Mutation: remove the backlog check
     * in send() and the send loop never returns false (unbounded).
     */
    public function testAStalledReaderIsBoundedAndDropped(): void
    {
        $conns = [];
        $svc = $this->makeService([
            'max_backlog' => 65536,
            'on_connect' => function (TcpConnection $c) use (&$conns) {
                $conns[] = $c;
            },
            'on_data' => function (TcpConnection $c, string $d) {
                $c->send($d); // echo — proves others keep serving
            },
        ]);
        // A: connects and NEVER reads.
        $stalled = $this->connect($svc->boundPort());
        $this->pumpUntil($svc, fn() => count($conns) === 1);
        $serverConn = $conns[0];

        $dropped = false;
        for ($i = 0; $i < 400; $i++) { // bounded — must drop well before this
            if (!$serverConn->send(str_repeat('x', 16384))) {
                $dropped = true;
                break;
            }
        }
        $this->assertTrue($dropped, 'a stalled reader must be dropped, not buffered without end');
        $this->assertTrue($serverConn->isClosed(), 'the dropped connection must be closed');

        // B: a healthy client still gets served.
        $ok = $this->connect($svc->boundPort());
        fwrite($ok, 'ping');
        $this->assertSame('ping', $this->clientRead($svc, $ok, 4), 'other connections must keep serving');
        if (is_resource($stalled)) {
            fclose($stalled);
        }
        fclose($ok);
    }

    // ── tcp-single-owner-bind ────────────────────────────────────────────────

    /**
     * Two services on the same port model two workers: exactly one binds, the
     * other skips with no error, and the owner still accepts. Mutation: let the
     * second bind succeed / raise and this fails.
     */
    public function testOnlyOneOwnerBindsTheTcpPort(): void
    {
        $port = \FreePort::get();
        $owner = new TcpService($port, ['name' => 'owner']);
        $second = new TcpService($port, ['name' => 'owner']);
        $this->started[] = $owner;
        $this->started[] = $second;

        $this->assertTrue($owner->start('127.0.0.1'), 'the first worker must bind');
        $this->assertTrue($owner->isOwner());
        $this->assertFalse($second->start('127.0.0.1'), 'the second worker must NOT bind the same port');
        $this->assertFalse($second->isOwner(), 'only one owner, no bind error raised');

        $client = $this->connect($port);
        $this->assertTrue($this->pumpUntil($owner, fn() => $owner->count() === 1), 'the one owner still accepts');
        fclose($client);
    }

    // ── the public Tcp facade + registry accessor ────────────────────────────

    /**
     * Tcp::service() registers a named service reachable via Tcp::get(); an
     * unknown name throws (a programming error), unlike an unknown device id.
     */
    public function testServiceAndGetShareTheRegistry(): void
    {
        $svc = Tcp::service(0, ['name' => 'trackers']);
        $this->assertSame($svc, Tcp::get('trackers'), 'Tcp::get(name) must return the registered service');
        $this->expectException(\RuntimeException::class);
        Tcp::get('nope');
    }

    /**
     * stop() closes every live connection (client sees EOF) and fires on_close —
     * the drain mechanism the server's shutdown path calls. Mutation: make
     * drain() skip closing connections and the client never sees EOF.
     */
    public function testServiceStopDrainsLiveConnectionsAndFiresOnClose(): void
    {
        $closes = 0;
        $svc = $this->makeService([
            'on_close' => function (TcpConnection $c) use (&$closes) {
                $closes++;
            },
        ]);
        $client = $this->connect($svc->boundPort());
        $this->pumpUntil($svc, fn() => $svc->count() === 1);
        $svc->stop();
        $this->assertTrue($this->clientAtEof($svc, $client), 'stop() must close the live connection');
        $this->assertSame(1, $closes, 'on_close must fire during drain');
        if (is_resource($client)) {
            fclose($client);
        }
    }

    // ── tcp-listen-accept-in-process (REAL child server) ─────────────────────

    private function bootChild(array $extraEnv = []): array
    {
        $this->appDir = \TempPath::dir('tina4_tcp_');
        $httpPort = \FreePort::get();
        $tcpPort = $httpPort + 1;
        @mkdir($this->appDir . '/src/routes', 0755, true);
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';

        file_put_contents($this->appDir . '/index.php', <<<PHP
<?php
require '{$autoload}';
\\Tina4\\Router::get('/ping', function (\$request, \$response) {
    return \$response(['ok' => true]);
});
\\Tina4\\Tcp::service({$tcpPort}, [
    'name' => 'trackers',
    'on_connect' => function (\\Tina4\\TcpConnection \$conn) { \$conn->send("HELLO\\n"); },
    'on_data' => function (\\Tina4\\TcpConnection \$conn, string \$data) { \$conn->send('ECHO:' . \$data); },
]);
\$app = new \\Tina4\\App(__DIR__);
\$app->run('127.0.0.1', {$httpPort});
PHP);

        $env = "TINA4_OVERRIDE_CLIENT=true\nTINA4_NO_BROWSER=true\nTINA4_SUPPRESS=true\n"
            . "TINA4_DEBUG=false\nTINA4_SERVE_FORK=false\n";
        foreach ($extraEnv as $k => $v) {
            $env .= "{$k}={$v}\n";
        }
        file_put_contents($this->appDir . '/.env', $env);

        $this->proc = proc_open(
            [PHP_BINARY, 'index.php'],
            [
                1 => ['file', $this->appDir . '/server.log', 'w'],
                2 => ['file', $this->appDir . '/server.log', 'a'],
            ],
            $pipes,
            $this->appDir
        );
        $this->assertIsResource($this->proc, 'could not start the test server');

        $deadline = microtime(true) + 20;
        $ready = false;
        while (microtime(true) < $deadline) {
            $h = @fsockopen('127.0.0.1', $httpPort, $e1, $s1, 0.3);
            $t = @fsockopen('127.0.0.1', $tcpPort, $e2, $s2, 0.3);
            if ($h && $t) {
                fclose($h);
                fclose($t);
                $ready = true;
                break;
            }
            if ($h) {
                fclose($h);
            }
            if ($t) {
                fclose($t);
            }
            usleep(150000);
        }
        $this->assertTrue(
            $ready,
            'the child server never opened both ports — log: ' . @file_get_contents($this->appDir . '/server.log')
        );
        return [$httpPort, $tcpPort];
    }

    /**
     * A real framework server: a client connects to the TCP service (on_connect
     * fires IN the server process, proven by the greeting) WHILE an HTTP request
     * to the same process succeeds. Mutation: don't start TCP services in the
     * accept loop and the TCP connect never greets.
     */
    public function testListenAcceptInProcessWhileHttpStillServes(): void
    {
        [$httpPort, $tcpPort] = $this->bootChild();

        // 1. Raw TCP: the on_connect greeting proves the listener runs in-process.
        $tcp = @stream_socket_client("tcp://127.0.0.1:{$tcpPort}", $errno, $errstr, 2.0);
        $this->assertIsResource($tcp, "TCP connect failed: {$errstr}");
        stream_set_timeout($tcp, 3);
        $greeting = fread($tcp, 6);
        $this->assertSame("HELLO\n", $greeting, 'on_connect must fire in-process');

        // 2. HTTP on the SAME process still serves, concurrently.
        $http = @stream_socket_client("tcp://127.0.0.1:{$httpPort}", $e, $s, 2.0);
        $this->assertIsResource($http, "HTTP connect failed: {$s}");
        fwrite($http, "GET /ping HTTP/1.1\r\nHost: 127.0.0.1:{$httpPort}\r\nConnection: close\r\n\r\n");
        stream_set_timeout($http, 3);
        $status = fgets($http);
        $this->assertStringContainsString('200', (string)$status, 'HTTP must still serve concurrently');
        fclose($http);
        fclose($tcp);
    }

    // ── tcp-graceful-shutdown-drain (REAL child server + SIGTERM) ────────────

    /**
     * A real server with a live TCP device connection receives SIGTERM: the
     * connection closes cleanly and the process exits 0. Mutation: skip
     * drainAll() on shutdown and the client never sees a clean EOF / exit.
     */
    public function testSigtermDrainsLiveTcpConnectionAndExitsZero(): void
    {
        if (!defined('SIGTERM') || !function_exists('proc_terminate')) {
            $this->markTestSkipped('[needs:runtime=posix-signals] SIGTERM/proc_terminate unavailable here');
        }
        [$httpPort, $tcpPort] = $this->bootChild(['TINA4_SHUTDOWN_TIMEOUT' => '10']);

        $tcp = @stream_socket_client("tcp://127.0.0.1:{$tcpPort}", $errno, $errstr, 2.0);
        $this->assertIsResource($tcp, "TCP connect failed: {$errstr}");
        stream_set_timeout($tcp, 3);
        $this->assertSame("HELLO\n", fread($tcp, 6));

        proc_terminate($this->proc, SIGTERM);

        // The live TCP connection must be closed cleanly (EOF) within the drain.
        stream_set_blocking($tcp, true);
        stream_set_timeout($tcp, 10);
        $tail = fread($tcp, 1);
        $this->assertSame('', $tail, 'SIGTERM must drain the live TCP connection cleanly');
        fclose($tcp);

        // The process exits 0 within the deadline.
        $exit = null;
        $deadline = microtime(true) + 12;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->proc);
            if ($status['running'] === false) {
                $exit = $status['exitcode'];
                break;
            }
            usleep(50000);
        }
        $this->assertSame(0, $exit, 'graceful shutdown must exit 0');
    }
}
