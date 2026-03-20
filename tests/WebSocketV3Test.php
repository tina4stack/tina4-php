<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\WebSocket;

class WebSocketV3Test extends TestCase
{
    // ── Handshake key computation ───────────────────────────────

    public function testComputeAcceptKey(): void
    {
        // RFC 6455 Section 4.2.2 example
        $key = 'dGhlIHNhbXBsZSBub25jZQ==';
        $expected = 's3pPLMBiTxaQ9kYGzzhZRbK+xOo=';
        $this->assertEquals($expected, WebSocket::computeAcceptKey($key));
    }

    public function testComputeAcceptKeyDeterministic(): void
    {
        $key = 'test-key-123';
        $result1 = WebSocket::computeAcceptKey($key);
        $result2 = WebSocket::computeAcceptKey($key);
        $this->assertEquals($result1, $result2);
    }

    public function testComputeAcceptKeyBase64Output(): void
    {
        $key = 'some-random-key';
        $result = WebSocket::computeAcceptKey($key);
        $this->assertNotFalse(base64_decode($result, true));
    }

    // ── Handshake response ──────────────────────────────────────

    public function testBuildHandshakeResponse(): void
    {
        $key = 'dGhlIHNhbXBsZSBub25jZQ==';
        $response = WebSocket::buildHandshakeResponse($key);

        $this->assertStringContainsString('HTTP/1.1 101 Switching Protocols', $response);
        $this->assertStringContainsString('Upgrade: websocket', $response);
        $this->assertStringContainsString('Connection: Upgrade', $response);
        $this->assertStringContainsString('Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $response);
        $this->assertStringEndsWith("\r\n\r\n", $response);
    }

    public function testBuildHandshakeResponseContainsAccept(): void
    {
        $key = 'testkey';
        $accept = WebSocket::computeAcceptKey($key);
        $response = WebSocket::buildHandshakeResponse($key);
        $this->assertStringContainsString("Sec-WebSocket-Accept: {$accept}", $response);
    }

    // ── Header parsing ──────────────────────────────────────────

    public function testParseHttpHeaders(): void
    {
        $raw = "GET /chat HTTP/1.1\r\n"
             . "Host: server.example.com\r\n"
             . "Upgrade: websocket\r\n"
             . "Connection: Upgrade\r\n"
             . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
             . "Sec-WebSocket-Version: 13\r\n"
             . "\r\n";

        $headers = WebSocket::parseHttpHeaders($raw);

        $this->assertEquals('GET', $headers['_method']);
        $this->assertEquals('/chat', $headers['_path']);
        $this->assertEquals('websocket', $headers['upgrade']);
        $this->assertEquals('Upgrade', $headers['connection']);
        $this->assertEquals('dGhlIHNhbXBsZSBub25jZQ==', $headers['sec-websocket-key']);
        $this->assertEquals('13', $headers['sec-websocket-version']);
    }

    public function testParseHttpHeadersCaseInsensitive(): void
    {
        $raw = "GET / HTTP/1.1\r\n"
             . "Content-Type: application/json\r\n"
             . "\r\n";

        $headers = WebSocket::parseHttpHeaders($raw);
        $this->assertEquals('application/json', $headers['content-type']);
    }

    // ── Frame encoding ──────────────────────────────────────────

    public function testEncodeShortFrame(): void
    {
        $msg = 'Hello';
        $frame = WebSocket::encodeFrame($msg);

        $this->assertEquals(0x81, ord($frame[0])); // FIN + TEXT
        $this->assertEquals(5, ord($frame[1]));     // length
        $this->assertEquals('Hello', substr($frame, 2));
    }

    public function testEncodeMediumFrame(): void
    {
        $msg = str_repeat('A', 200);
        $frame = WebSocket::encodeFrame($msg);

        $this->assertEquals(0x81, ord($frame[0]));
        $this->assertEquals(126, ord($frame[1])); // Extended 16-bit length
        $len = unpack('n', substr($frame, 2, 2))[1];
        $this->assertEquals(200, $len);
        $this->assertEquals($msg, substr($frame, 4));
    }

    public function testEncodeLargeFrame(): void
    {
        $msg = str_repeat('B', 70000);
        $frame = WebSocket::encodeFrame($msg);

        $this->assertEquals(0x81, ord($frame[0]));
        $this->assertEquals(127, ord($frame[1])); // Extended 64-bit length
        $len = unpack('J', substr($frame, 2, 8))[1];
        $this->assertEquals(70000, $len);
    }

    public function testEncodeEmptyFrame(): void
    {
        $frame = WebSocket::encodeFrame('');
        $this->assertEquals(0x81, ord($frame[0]));
        $this->assertEquals(0, ord($frame[1]));
        $this->assertEquals(2, strlen($frame));
    }

    public function testEncodeBinaryOpcode(): void
    {
        $frame = WebSocket::encodeFrame('data', WebSocket::OP_BINARY);
        $this->assertEquals(0x80 | WebSocket::OP_BINARY, ord($frame[0]));
    }

    public function testEncodePingFrame(): void
    {
        $frame = WebSocket::encodeFrame('', WebSocket::OP_PING);
        $this->assertEquals(0x80 | WebSocket::OP_PING, ord($frame[0]));
    }

    // ── Frame decoding ──────────────────────────────────────────

    public function testDecodeUnmaskedFrame(): void
    {
        $frame = WebSocket::encodeFrame('Hello');
        $decoded = WebSocket::decodeFrame($frame);

        $this->assertNotNull($decoded);
        $this->assertTrue($decoded['fin']);
        $this->assertEquals(WebSocket::OP_TEXT, $decoded['opcode']);
        $this->assertEquals('Hello', $decoded['payload']);
    }

    public function testDecodeMaskedFrame(): void
    {
        $message = 'Hello';
        $maskKey = "\x37\xfa\x21\x3d";

        $frame = chr(0x81); // FIN + TEXT
        $frame .= chr(0x80 | strlen($message)); // MASK bit + length
        $frame .= $maskKey;

        for ($i = 0; $i < strlen($message); $i++) {
            $frame .= chr(ord($message[$i]) ^ ord($maskKey[$i % 4]));
        }

        $decoded = WebSocket::decodeFrame($frame);
        $this->assertNotNull($decoded);
        $this->assertEquals('Hello', $decoded['payload']);
    }

    public function testDecodeInsufficientData(): void
    {
        $this->assertNull(WebSocket::decodeFrame(''));
        $this->assertNull(WebSocket::decodeFrame("\x81"));
    }

    public function testDecodeRoundTrip(): void
    {
        $messages = ['short', str_repeat('x', 200), str_repeat('y', 70000)];

        foreach ($messages as $msg) {
            $frame = WebSocket::encodeFrame($msg);
            $decoded = WebSocket::decodeFrame($frame);
            $this->assertNotNull($decoded);
            $this->assertEquals($msg, $decoded['payload']);
        }
    }

    // ── Event registration ──────────────────────────────────────

    public function testOnReturnsself(): void
    {
        $ws = new WebSocket(9999);
        $result = $ws->on('open', function ($id) {});
        $this->assertSame($ws, $result);
    }

    public function testOnMultipleEvents(): void
    {
        $ws = new WebSocket(9999);
        $ws->on('open', function ($id) {})
           ->on('message', function ($id, $msg) {})
           ->on('close', function ($id) {})
           ->on('error', function ($id, $e) {});

        // If we got here without exceptions, handlers are registered
        $this->assertTrue(true);
    }

    // ── Constructor / config ────────────────────────────────────

    public function testDefaultPort(): void
    {
        $ws = new WebSocket();
        $this->assertEquals(8080, $ws->getPort());
    }

    public function testCustomPort(): void
    {
        $ws = new WebSocket(9090);
        $this->assertEquals(9090, $ws->getPort());
    }

    public function testGetClientsEmpty(): void
    {
        $ws = new WebSocket(9999);
        $this->assertEmpty($ws->getClients());
    }

    // ── Constants ───────────────────────────────────────────────

    public function testOpcodeConstants(): void
    {
        $this->assertEquals(0x0, WebSocket::OP_CONTINUATION);
        $this->assertEquals(0x1, WebSocket::OP_TEXT);
        $this->assertEquals(0x2, WebSocket::OP_BINARY);
        $this->assertEquals(0x8, WebSocket::OP_CLOSE);
        $this->assertEquals(0x9, WebSocket::OP_PING);
        $this->assertEquals(0xA, WebSocket::OP_PONG);
    }

    public function testCloseCodeConstants(): void
    {
        $this->assertEquals(1000, WebSocket::CLOSE_NORMAL);
        $this->assertEquals(1001, WebSocket::CLOSE_GOING_AWAY);
        $this->assertEquals(1002, WebSocket::CLOSE_PROTOCOL_ERROR);
    }

    public function testMagicGUID(): void
    {
        $this->assertEquals('258EAFA5-E914-47DA-95CA-C5AB0DC85B11', WebSocket::MAGIC_GUID);
    }
}
