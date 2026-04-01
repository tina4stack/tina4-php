<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * WebSocket integration tests — tests Server + WebSocketConnection + Router::websocket()
 * without requiring a running server.
 */

use PHPUnit\Framework\TestCase;
use Tina4\WebSocket;
use Tina4\WebSocketConnection;
use Tina4\Router;
use Tina4\Server;

class WebSocketTest extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
    }

    // ── WebSocket handshake (Sec-WebSocket-Accept calculation) ──────

    public function testHandshakeAcceptKeyRfc6455(): void
    {
        // RFC 6455 Section 4.2.2 official test vector
        $key = 'dGhlIHNhbXBsZSBub25jZQ==';
        $expected = 's3pPLMBiTxaQ9kYGzzhZRbK+xOo=';
        $this->assertEquals($expected, WebSocket::computeAcceptKey($key));
    }

    public function testHandshakeResponseContainsRequiredHeaders(): void
    {
        $key = 'test-websocket-key';
        $response = WebSocket::buildHandshakeResponse($key);

        $this->assertStringContainsString('HTTP/1.1 101 Switching Protocols', $response);
        $this->assertStringContainsString('Upgrade: websocket', $response);
        $this->assertStringContainsString('Connection: Upgrade', $response);
        $accept = WebSocket::computeAcceptKey($key);
        $this->assertStringContainsString("Sec-WebSocket-Accept: {$accept}", $response);
        $this->assertStringEndsWith("\r\n\r\n", $response);
    }

    public function testHandshakeAcceptKeyDeterministic(): void
    {
        $key = 'random-client-key';
        $a = WebSocket::computeAcceptKey($key);
        $b = WebSocket::computeAcceptKey($key);
        $this->assertSame($a, $b);
    }

    public function testHandshakeAcceptKeyAlwaysBase64(): void
    {
        $keys = ['key1', 'key2', str_repeat('a', 100), ''];
        foreach ($keys as $key) {
            $result = WebSocket::computeAcceptKey($key);
            $this->assertNotFalse(base64_decode($result, true), "Key '{$key}' should produce valid base64");
            // SHA1 always produces 20 bytes = 28 base64 chars
            $this->assertEquals(28, strlen($result));
        }
    }

    // ── Frame encoding/decoding ─────────────────────────────────────

    public function testEncodeDecodeTextFrame(): void
    {
        $msg = 'Hello WebSocket!';
        $frame = WebSocket::encodeFrame($msg);
        $decoded = WebSocket::decodeFrame($frame);

        $this->assertNotNull($decoded);
        $this->assertTrue($decoded['fin']);
        $this->assertEquals(WebSocket::OP_TEXT, $decoded['opcode']);
        $this->assertEquals($msg, $decoded['payload']);
    }

    public function testEncodeDecodeEmptyFrame(): void
    {
        $frame = WebSocket::encodeFrame('');
        $decoded = WebSocket::decodeFrame($frame);

        $this->assertNotNull($decoded);
        $this->assertEquals('', $decoded['payload']);
        $this->assertEquals(2, strlen($frame));
    }

    public function testEncodeDecodeMediumFrame(): void
    {
        $msg = str_repeat('M', 200);
        $frame = WebSocket::encodeFrame($msg);
        $decoded = WebSocket::decodeFrame($frame);

        $this->assertNotNull($decoded);
        $this->assertEquals($msg, $decoded['payload']);
        $this->assertEquals(126, ord($frame[1])); // 16-bit extended length
    }

    public function testEncodeDecodeLargeFrame(): void
    {
        $msg = str_repeat('L', 70000);
        $frame = WebSocket::encodeFrame($msg);
        $decoded = WebSocket::decodeFrame($frame);

        $this->assertNotNull($decoded);
        $this->assertEquals($msg, $decoded['payload']);
        $this->assertEquals(127, ord($frame[1])); // 64-bit extended length
    }

    public function testEncodePingFrame(): void
    {
        $payload = 'keepalive';
        $frame = WebSocket::encodeFrame($payload, WebSocket::OP_PING);
        $decoded = WebSocket::decodeFrame($frame);

        $this->assertNotNull($decoded);
        $this->assertEquals(WebSocket::OP_PING, $decoded['opcode']);
        $this->assertEquals($payload, $decoded['payload']);
    }

    public function testEncodePongFrame(): void
    {
        $payload = 'keepalive';
        $frame = WebSocket::encodeFrame($payload, WebSocket::OP_PONG);
        $decoded = WebSocket::decodeFrame($frame);

        $this->assertNotNull($decoded);
        $this->assertEquals(WebSocket::OP_PONG, $decoded['opcode']);
        $this->assertEquals($payload, $decoded['payload']);
    }

    public function testEncodeCloseFrame(): void
    {
        $closePayload = pack('n', WebSocket::CLOSE_NORMAL) . 'bye';
        $frame = WebSocket::encodeFrame($closePayload, WebSocket::OP_CLOSE);
        $decoded = WebSocket::decodeFrame($frame);

        $this->assertNotNull($decoded);
        $this->assertEquals(WebSocket::OP_CLOSE, $decoded['opcode']);
        $code = unpack('n', substr($decoded['payload'], 0, 2))[1];
        $this->assertEquals(1000, $code);
        $this->assertEquals('bye', substr($decoded['payload'], 2));
    }

    public function testDecodeMaskedFrame(): void
    {
        $message = 'Test masked';
        $maskKey = "\x12\x34\x56\x78";

        $frame = chr(0x81); // FIN + TEXT
        $frame .= chr(0x80 | strlen($message)); // MASK bit + length
        $frame .= $maskKey;

        for ($i = 0; $i < strlen($message); $i++) {
            $frame .= chr(ord($message[$i]) ^ ord($maskKey[$i % 4]));
        }

        $decoded = WebSocket::decodeFrame($frame);
        $this->assertNotNull($decoded);
        $this->assertEquals($message, $decoded['payload']);
    }

    public function testDecodeInsufficientDataReturnsNull(): void
    {
        $this->assertNull(WebSocket::decodeFrame(''));
        $this->assertNull(WebSocket::decodeFrame("\x81"));
        // Frame claiming 10 bytes but only 5 given
        $this->assertNull(WebSocket::decodeFrame(chr(0x81) . chr(10) . 'Hello'));
    }

    public function testPingPongRoundTrip(): void
    {
        $pingData = 'ping-123';
        $pingFrame = WebSocket::encodeFrame($pingData, WebSocket::OP_PING);
        $decodedPing = WebSocket::decodeFrame($pingFrame);

        $this->assertNotNull($decodedPing);
        $this->assertEquals(WebSocket::OP_PING, $decodedPing['opcode']);

        // Server responds with pong containing same payload
        $pongFrame = WebSocket::encodeFrame($decodedPing['payload'], WebSocket::OP_PONG);
        $decodedPong = WebSocket::decodeFrame($pongFrame);

        $this->assertNotNull($decodedPong);
        $this->assertEquals(WebSocket::OP_PONG, $decodedPong['opcode']);
        $this->assertEquals($pingData, $decodedPong['payload']);
    }

    public function testFrameRoundTripAllSizes(): void
    {
        $sizes = [0, 1, 125, 126, 127, 200, 65535, 65536];
        foreach ($sizes as $size) {
            $msg = str_repeat('x', $size);
            $frame = WebSocket::encodeFrame($msg);
            $decoded = WebSocket::decodeFrame($frame);
            $this->assertNotNull($decoded, "Round-trip failed for size {$size}");
            $this->assertEquals($msg, $decoded['payload'], "Payload mismatch for size {$size}");
        }
    }

    // ── Router::websocket() registration ────────────────────────────

    public function testWebSocketRouteRegistration(): void
    {
        $handler = function ($connection, $message, $event) {};
        Router::websocket('/ws/chat', $handler);

        $routes = Router::getWebSocketRoutes();
        $this->assertCount(1, $routes);
        $this->assertEquals('/ws/chat', $routes[0]['path']);
        $this->assertSame($handler, $routes[0]['handler']);
    }

    public function testMultipleWebSocketRoutes(): void
    {
        Router::websocket('/ws/chat', function ($c, $m, $e) {});
        Router::websocket('/ws/notifications', function ($c, $m, $e) {});
        Router::websocket('/ws/live', function ($c, $m, $e) {});

        $routes = Router::getWebSocketRoutes();
        $this->assertCount(3, $routes);

        $paths = array_column($routes, 'path');
        $this->assertContains('/ws/chat', $paths);
        $this->assertContains('/ws/notifications', $paths);
        $this->assertContains('/ws/live', $paths);
    }

    public function testWebSocketRouteInGroup(): void
    {
        Router::group('/api/v1', function () {
            Router::websocket('/ws', function ($c, $m, $e) {});
        });

        $routes = Router::getWebSocketRoutes();
        $this->assertCount(1, $routes);
        $this->assertEquals('/api/v1/ws', $routes[0]['path']);
    }

    public function testWebSocketRouteIncludedInList(): void
    {
        Router::get('/hello', function ($req, $res) { return $res->json(['ok' => true]); });
        Router::websocket('/ws/chat', function ($c, $m, $e) {});

        $list = Router::getRoutes();
        $methods = array_column($list, 'method');
        $this->assertContains('WS', $methods);
    }

    public function testWebSocketRouteIncludedInCount(): void
    {
        Router::get('/hello', function ($req, $res) { return $res->json(['ok' => true]); });
        Router::websocket('/ws/chat', function ($c, $m, $e) {});

        // 1 GET + 1 WS = 2
        $this->assertEquals(2, Router::count());
    }

    public function testResetClearsWebSocketRoutes(): void
    {
        Router::websocket('/ws/test', function ($c, $m, $e) {});
        $this->assertCount(1, Router::getWebSocketRoutes());

        Router::clear();
        $this->assertCount(0, Router::getWebSocketRoutes());
    }

    // ── WebSocketConnection ─────────────────────────────────────────

    public function testWebSocketConnectionProperties(): void
    {
        // Create a temporary socket pair for testing
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            $this->markTestSkipped('stream_socket_pair not available');
        }

        $conn = new WebSocketConnection('conn-123', '/ws/chat', $sockets[0]);

        $this->assertEquals('conn-123', $conn->id);
        $this->assertEquals('/ws/chat', $conn->path);
        $this->assertSame($sockets[0], $conn->getSocket());

        fclose($sockets[0]);
        fclose($sockets[1]);
    }

    public function testWebSocketConnectionSend(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            $this->markTestSkipped('stream_socket_pair not available');
        }

        $conn = new WebSocketConnection('conn-abc', '/ws/test', $sockets[0]);
        $conn->send('Hello from server');

        // Read from the other end
        $data = fread($sockets[1], 65536);
        $decoded = WebSocket::decodeFrame($data);

        $this->assertNotNull($decoded);
        $this->assertEquals('Hello from server', $decoded['payload']);
        $this->assertEquals(WebSocket::OP_TEXT, $decoded['opcode']);

        fclose($sockets[0]);
        fclose($sockets[1]);
    }

    public function testWebSocketConnectionClose(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            $this->markTestSkipped('stream_socket_pair not available');
        }

        $conn = new WebSocketConnection('conn-close', '/ws/test', $sockets[0]);
        $conn->close(WebSocket::CLOSE_NORMAL, 'goodbye');

        // Read close frame from the other end
        $data = fread($sockets[1], 65536);
        $decoded = WebSocket::decodeFrame($data);

        $this->assertNotNull($decoded);
        $this->assertEquals(WebSocket::OP_CLOSE, $decoded['opcode']);
        $code = unpack('n', substr($decoded['payload'], 0, 2))[1];
        $this->assertEquals(1000, $code);
        $this->assertEquals('goodbye', substr($decoded['payload'], 2));

        fclose($sockets[0]);
        fclose($sockets[1]);
    }

    public function testWebSocketConnectionCloseDefaultCode(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            $this->markTestSkipped('stream_socket_pair not available');
        }

        $conn = new WebSocketConnection('conn-def', '/ws/test', $sockets[0]);
        $conn->close();

        $data = fread($sockets[1], 65536);
        $decoded = WebSocket::decodeFrame($data);

        $this->assertNotNull($decoded);
        $code = unpack('n', substr($decoded['payload'], 0, 2))[1];
        $this->assertEquals(WebSocket::CLOSE_NORMAL, $code);

        fclose($sockets[0]);
        fclose($sockets[1]);
    }

    // ── Multiple connections on same path ───────────────────────────

    public function testMultipleConnectionsSamePath(): void
    {
        $sockets1 = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $sockets2 = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets1 === false || $sockets2 === false) {
            $this->markTestSkipped('stream_socket_pair not available');
        }

        $conn1 = new WebSocketConnection('conn-1', '/ws/chat', $sockets1[0]);
        $conn2 = new WebSocketConnection('conn-2', '/ws/chat', $sockets2[0]);

        // Both are on same path
        $this->assertEquals($conn1->path, $conn2->path);

        // Different IDs
        $this->assertNotEquals($conn1->id, $conn2->id);

        // Each can send independently
        $conn1->send('msg1');
        $conn2->send('msg2');

        $data1 = fread($sockets1[1], 65536);
        $data2 = fread($sockets2[1], 65536);

        $decoded1 = WebSocket::decodeFrame($data1);
        $decoded2 = WebSocket::decodeFrame($data2);

        $this->assertEquals('msg1', $decoded1['payload']);
        $this->assertEquals('msg2', $decoded2['payload']);

        fclose($sockets1[0]);
        fclose($sockets1[1]);
        fclose($sockets2[0]);
        fclose($sockets2[1]);
    }

    public function testConnectionsDifferentPaths(): void
    {
        $sockets1 = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $sockets2 = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets1 === false || $sockets2 === false) {
            $this->markTestSkipped('stream_socket_pair not available');
        }

        $conn1 = new WebSocketConnection('conn-a', '/ws/chat', $sockets1[0]);
        $conn2 = new WebSocketConnection('conn-b', '/ws/notifications', $sockets2[0]);

        $this->assertNotEquals($conn1->path, $conn2->path);

        fclose($sockets1[0]);
        fclose($sockets1[1]);
        fclose($sockets2[0]);
        fclose($sockets2[1]);
    }

    // ── Server constructor ──────────────────────────────────────────

    public function testServerDefaultHostPort(): void
    {
        $server = new Server();
        $this->assertEquals('0.0.0.0', $server->getHost());
        $this->assertEquals(7146, $server->getPort());
    }

    public function testServerCustomHostPort(): void
    {
        $server = new Server('127.0.0.1', 9090);
        $this->assertEquals('127.0.0.1', $server->getHost());
        $this->assertEquals(9090, $server->getPort());
    }

    public function testServerInitialState(): void
    {
        $server = new Server();
        $this->assertFalse($server->isRunning());
        $this->assertEquals(0, $server->getWebSocketClientCount());
        $this->assertEmpty($server->getWebSocketClients());
    }

    // ── Header parsing for WebSocket detection ──────────────────────

    public function testParseUpgradeHeaders(): void
    {
        $raw = "GET /ws/chat HTTP/1.1\r\n"
             . "Host: localhost:7146\r\n"
             . "Upgrade: websocket\r\n"
             . "Connection: Upgrade\r\n"
             . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
             . "Sec-WebSocket-Version: 13\r\n"
             . "Origin: http://localhost:7146\r\n"
             . "\r\n";

        $headers = WebSocket::parseHttpHeaders($raw);

        $this->assertEquals('GET', $headers['_method']);
        $this->assertEquals('/ws/chat', $headers['_path']);
        $this->assertEquals('websocket', $headers['upgrade']);
        $this->assertEquals('Upgrade', $headers['connection']);
        $this->assertEquals('dGhlIHNhbXBsZSBub25jZQ==', $headers['sec-websocket-key']);
        $this->assertEquals('13', $headers['sec-websocket-version']);
    }

    public function testParseNonUpgradeHeaders(): void
    {
        $raw = "GET /api/users HTTP/1.1\r\n"
             . "Host: localhost:7146\r\n"
             . "Accept: application/json\r\n"
             . "Connection: keep-alive\r\n"
             . "\r\n";

        $headers = WebSocket::parseHttpHeaders($raw);

        $this->assertEquals('GET', $headers['_method']);
        $this->assertEquals('/api/users', $headers['_path']);
        $this->assertNotEquals('websocket', $headers['upgrade'] ?? '');
    }

    // ── WebSocket route path normalization ───────────────────────────

    public function testWebSocketPathNormalization(): void
    {
        Router::websocket('ws/chat', function ($c, $m, $e) {});
        $routes = Router::getWebSocketRoutes();
        $this->assertEquals('/ws/chat', $routes[0]['path']);
    }

    public function testWebSocketPathTrailingSlash(): void
    {
        Router::websocket('/ws/chat/', function ($c, $m, $e) {});
        $routes = Router::getWebSocketRoutes();
        $this->assertEquals('/ws/chat', $routes[0]['path']);
    }

    public function testWebSocketRootPath(): void
    {
        Router::websocket('/', function ($c, $m, $e) {});
        $routes = Router::getWebSocketRoutes();
        $this->assertEquals('/', $routes[0]['path']);
    }
}
