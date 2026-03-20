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

    // ── Frame encoding with different opcodes ────────────────────

    public function testEncodeCloseFrame(): void
    {
        $frame = WebSocket::encodeFrame('', WebSocket::OP_CLOSE);
        $this->assertEquals(0x80 | WebSocket::OP_CLOSE, ord($frame[0]));
        $this->assertEquals(0, ord($frame[1]));
    }

    public function testEncodePongFrame(): void
    {
        $payload = 'pong-data';
        $frame = WebSocket::encodeFrame($payload, WebSocket::OP_PONG);
        $this->assertEquals(0x80 | WebSocket::OP_PONG, ord($frame[0]));
        $this->assertEquals(strlen($payload), ord($frame[1]));
        $this->assertEquals($payload, substr($frame, 2));
    }

    public function testEncodeContinuationFrame(): void
    {
        $frame = WebSocket::encodeFrame('continued', WebSocket::OP_CONTINUATION);
        $this->assertEquals(0x80 | WebSocket::OP_CONTINUATION, ord($frame[0]));
    }

    // ── Frame encoding — boundary payload sizes ─────────────────

    public function testEncodePayloadExactly125Bytes(): void
    {
        $msg = str_repeat('X', 125);
        $frame = WebSocket::encodeFrame($msg);
        $this->assertEquals(125, ord($frame[1]));
        $this->assertEquals(127, strlen($frame)); // 2 header + 125 payload
        $this->assertEquals($msg, substr($frame, 2));
    }

    public function testEncodePayloadExactly126Bytes(): void
    {
        $msg = str_repeat('Y', 126);
        $frame = WebSocket::encodeFrame($msg);
        $this->assertEquals(126, ord($frame[1])); // triggers 16-bit extended
        $len = unpack('n', substr($frame, 2, 2))[1];
        $this->assertEquals(126, $len);
    }

    public function testEncodePayloadExactly65535Bytes(): void
    {
        $msg = str_repeat('Z', 65535);
        $frame = WebSocket::encodeFrame($msg);
        $this->assertEquals(126, ord($frame[1])); // still 16-bit
        $len = unpack('n', substr($frame, 2, 2))[1];
        $this->assertEquals(65535, $len);
    }

    public function testEncodePayloadExactly65536Bytes(): void
    {
        $msg = str_repeat('W', 65536);
        $frame = WebSocket::encodeFrame($msg);
        $this->assertEquals(127, ord($frame[1])); // triggers 64-bit
        $len = unpack('J', substr($frame, 2, 8))[1];
        $this->assertEquals(65536, $len);
    }

    // ── Frame encoding — FIN bit always set ─────────────────────

    public function testEncodeFrameAlwaysSetsFin(): void
    {
        $opcodes = [
            WebSocket::OP_TEXT,
            WebSocket::OP_BINARY,
            WebSocket::OP_CLOSE,
            WebSocket::OP_PING,
            WebSocket::OP_PONG,
            WebSocket::OP_CONTINUATION,
        ];

        foreach ($opcodes as $opcode) {
            $frame = WebSocket::encodeFrame('test', $opcode);
            $finBit = (ord($frame[0]) >> 7) & 1;
            $this->assertEquals(1, $finBit, "FIN bit should be set for opcode {$opcode}");
        }
    }

    // ── Frame decoding — different opcodes ──────────────────────

    public function testDecodeCloseFrame(): void
    {
        $frame = WebSocket::encodeFrame('', WebSocket::OP_CLOSE);
        $decoded = WebSocket::decodeFrame($frame);
        $this->assertNotNull($decoded);
        $this->assertEquals(WebSocket::OP_CLOSE, $decoded['opcode']);
        $this->assertEquals('', $decoded['payload']);
    }

    public function testDecodePingFrame(): void
    {
        $payload = 'ping-payload';
        $frame = WebSocket::encodeFrame($payload, WebSocket::OP_PING);
        $decoded = WebSocket::decodeFrame($frame);
        $this->assertNotNull($decoded);
        $this->assertEquals(WebSocket::OP_PING, $decoded['opcode']);
        $this->assertEquals($payload, $decoded['payload']);
    }

    public function testDecodePongFrame(): void
    {
        $payload = 'pong-payload';
        $frame = WebSocket::encodeFrame($payload, WebSocket::OP_PONG);
        $decoded = WebSocket::decodeFrame($frame);
        $this->assertNotNull($decoded);
        $this->assertEquals(WebSocket::OP_PONG, $decoded['opcode']);
        $this->assertEquals($payload, $decoded['payload']);
    }

    public function testDecodeBinaryFrame(): void
    {
        $payload = "\x00\x01\x02\xFF\xFE";
        $frame = WebSocket::encodeFrame($payload, WebSocket::OP_BINARY);
        $decoded = WebSocket::decodeFrame($frame);
        $this->assertNotNull($decoded);
        $this->assertEquals(WebSocket::OP_BINARY, $decoded['opcode']);
        $this->assertEquals($payload, $decoded['payload']);
    }

    // ── Frame decoding — masking ────────────────────────────────

    public function testDecodeMaskedEmptyPayload(): void
    {
        $maskKey = "\xAA\xBB\xCC\xDD";
        $frame = chr(0x81) . chr(0x80) . $maskKey; // FIN+TEXT, masked, len=0
        $decoded = WebSocket::decodeFrame($frame);
        $this->assertNotNull($decoded);
        $this->assertEquals('', $decoded['payload']);
    }

    public function testDecodeMaskedMediumFrame(): void
    {
        $message = str_repeat('A', 200);
        $maskKey = "\x12\x34\x56\x78";

        $frame = chr(0x81); // FIN + TEXT
        $frame .= chr(0x80 | 126); // MASK + extended 16-bit
        $frame .= pack('n', 200);
        $frame .= $maskKey;

        for ($i = 0; $i < 200; $i++) {
            $frame .= chr(ord($message[$i]) ^ ord($maskKey[$i % 4]));
        }

        $decoded = WebSocket::decodeFrame($frame);
        $this->assertNotNull($decoded);
        $this->assertEquals($message, $decoded['payload']);
    }

    public function testDecodeMaskedFrameDifferentKeys(): void
    {
        $message = 'Test123';
        $keys = ["\x00\x00\x00\x00", "\xFF\xFF\xFF\xFF", "\x01\x02\x03\x04"];

        foreach ($keys as $maskKey) {
            $frame = chr(0x81);
            $frame .= chr(0x80 | strlen($message));
            $frame .= $maskKey;

            for ($i = 0; $i < strlen($message); $i++) {
                $frame .= chr(ord($message[$i]) ^ ord($maskKey[$i % 4]));
            }

            $decoded = WebSocket::decodeFrame($frame);
            $this->assertNotNull($decoded);
            $this->assertEquals($message, $decoded['payload']);
        }
    }

    // ── Frame decoding — insufficient data ──────────────────────

    public function testDecodeInsufficientDataForMediumLength(): void
    {
        // 2 bytes header + needs 2 more for 16-bit length but only 1 given
        $frame = chr(0x81) . chr(126) . chr(0);
        $this->assertNull(WebSocket::decodeFrame($frame));
    }

    public function testDecodeInsufficientDataForLargeLength(): void
    {
        // 2 bytes header + needs 8 more for 64-bit length but only 5 given
        $frame = chr(0x81) . chr(127) . str_repeat("\x00", 5);
        $this->assertNull(WebSocket::decodeFrame($frame));
    }

    public function testDecodeInsufficientDataForMaskKey(): void
    {
        // Masked frame, length 5, but not enough data for mask key
        $frame = chr(0x81) . chr(0x85) . "\x01\x02"; // only 2 of 4 mask bytes
        $this->assertNull(WebSocket::decodeFrame($frame));
    }

    public function testDecodeInsufficientDataForPayload(): void
    {
        // Unmasked frame claiming 10 bytes payload but only 5 given
        $frame = chr(0x81) . chr(10) . 'Hello';
        $this->assertNull(WebSocket::decodeFrame($frame));
    }

    // ── Frame decoding — decoded length field ───────────────────

    public function testDecodedFrameIncludesLength(): void
    {
        $msg = 'Hello';
        $frame = WebSocket::encodeFrame($msg);
        $decoded = WebSocket::decodeFrame($frame);
        $this->assertNotNull($decoded);
        $this->assertArrayHasKey('length', $decoded);
        $this->assertEquals(strlen($frame), $decoded['length']);
    }

    public function testDecodedFrameLengthMedium(): void
    {
        $msg = str_repeat('B', 200);
        $frame = WebSocket::encodeFrame($msg);
        $decoded = WebSocket::decodeFrame($frame);
        $this->assertNotNull($decoded);
        $this->assertEquals(strlen($frame), $decoded['length']);
    }

    // ── Close codes ─────────────────────────────────────────────

    public function testCloseFrameWithCode(): void
    {
        // Build a close frame containing a 2-byte status code
        $closePayload = pack('n', WebSocket::CLOSE_NORMAL) . 'goodbye';
        $frame = WebSocket::encodeFrame($closePayload, WebSocket::OP_CLOSE);
        $decoded = WebSocket::decodeFrame($frame);

        $this->assertNotNull($decoded);
        $this->assertEquals(WebSocket::OP_CLOSE, $decoded['opcode']);
        $code = unpack('n', substr($decoded['payload'], 0, 2))[1];
        $this->assertEquals(1000, $code);
        $this->assertEquals('goodbye', substr($decoded['payload'], 2));
    }

    public function testCloseGoingAwayCode(): void
    {
        $closePayload = pack('n', WebSocket::CLOSE_GOING_AWAY);
        $frame = WebSocket::encodeFrame($closePayload, WebSocket::OP_CLOSE);
        $decoded = WebSocket::decodeFrame($frame);
        $code = unpack('n', substr($decoded['payload'], 0, 2))[1];
        $this->assertEquals(1001, $code);
    }

    public function testCloseProtocolErrorCode(): void
    {
        $closePayload = pack('n', WebSocket::CLOSE_PROTOCOL_ERROR);
        $frame = WebSocket::encodeFrame($closePayload, WebSocket::OP_CLOSE);
        $decoded = WebSocket::decodeFrame($frame);
        $code = unpack('n', substr($decoded['payload'], 0, 2))[1];
        $this->assertEquals(1002, $code);
    }

    // ── Ping / Pong round-trip ──────────────────────────────────

    public function testPingPongRoundTrip(): void
    {
        $pingData = 'keepalive-123';
        $pingFrame = WebSocket::encodeFrame($pingData, WebSocket::OP_PING);
        $decodedPing = WebSocket::decodeFrame($pingFrame);

        $this->assertNotNull($decodedPing);
        $this->assertEquals(WebSocket::OP_PING, $decodedPing['opcode']);
        $this->assertEquals($pingData, $decodedPing['payload']);

        // Server would respond with a pong containing the same payload
        $pongFrame = WebSocket::encodeFrame($decodedPing['payload'], WebSocket::OP_PONG);
        $decodedPong = WebSocket::decodeFrame($pongFrame);

        $this->assertNotNull($decodedPong);
        $this->assertEquals(WebSocket::OP_PONG, $decodedPong['opcode']);
        $this->assertEquals($pingData, $decodedPong['payload']);
    }

    // ── Header parsing — edge cases ─────────────────────────────

    public function testParseHttpHeadersEmptyString(): void
    {
        $headers = WebSocket::parseHttpHeaders('');
        $this->assertIsArray($headers);
    }

    public function testParseHttpHeadersWithQueryString(): void
    {
        $raw = "GET /path?foo=bar HTTP/1.1\r\n"
             . "Host: localhost\r\n"
             . "\r\n";
        $headers = WebSocket::parseHttpHeaders($raw);
        $this->assertEquals('GET', $headers['_method']);
        $this->assertEquals('/path?foo=bar', $headers['_path']);
        $this->assertEquals('localhost', $headers['host']);
    }

    public function testParseHttpHeadersMultipleHeaders(): void
    {
        $raw = "GET / HTTP/1.1\r\n"
             . "X-Custom-A: alpha\r\n"
             . "X-Custom-B: bravo\r\n"
             . "X-Custom-C: charlie\r\n"
             . "\r\n";
        $headers = WebSocket::parseHttpHeaders($raw);
        $this->assertEquals('alpha', $headers['x-custom-a']);
        $this->assertEquals('bravo', $headers['x-custom-b']);
        $this->assertEquals('charlie', $headers['x-custom-c']);
    }

    public function testParseHttpHeadersWithColonInValue(): void
    {
        $raw = "GET / HTTP/1.1\r\n"
             . "Origin: http://localhost:8080\r\n"
             . "\r\n";
        $headers = WebSocket::parseHttpHeaders($raw);
        $this->assertEquals('http://localhost:8080', $headers['origin']);
    }

    // ── Handshake response — structure ──────────────────────────

    public function testHandshakeResponseLineCount(): void
    {
        $key = 'test-key';
        $response = WebSocket::buildHandshakeResponse($key);
        $lines = explode("\r\n", $response);
        // Should have: status, upgrade, connection, accept, empty, empty
        $this->assertGreaterThanOrEqual(5, count($lines));
    }

    public function testHandshakeResponseDifferentKeys(): void
    {
        $key1 = 'key-one';
        $key2 = 'key-two';
        $resp1 = WebSocket::buildHandshakeResponse($key1);
        $resp2 = WebSocket::buildHandshakeResponse($key2);
        $this->assertNotEquals($resp1, $resp2);
    }

    // ── Connection management ───────────────────────────────────

    public function testStopSetsRunningFalse(): void
    {
        $ws = new WebSocket(9999);
        $ws->stop();
        // Calling stop without start should not throw
        $this->assertEmpty($ws->getClients());
    }

    public function testCustomHostConfig(): void
    {
        $ws = new WebSocket(7777, ['host' => '127.0.0.1']);
        $this->assertEquals(7777, $ws->getPort());
    }

    public function testGetClientsReturnsArray(): void
    {
        $ws = new WebSocket(9999);
        $clients = $ws->getClients();
        $this->assertIsArray($clients);
        $this->assertCount(0, $clients);
    }

    // ── Event handler registration ──────────────────────────────

    public function testOnErrorHandler(): void
    {
        $ws = new WebSocket(9999);
        $result = $ws->on('error', function ($id, $e) {});
        $this->assertSame($ws, $result);
    }

    public function testOnOverwritesPreviousHandler(): void
    {
        $ws = new WebSocket(9999);
        $ws->on('message', function ($id, $msg) { return 'first'; });
        $ws->on('message', function ($id, $msg) { return 'second'; });
        // Should not throw — overwriting is valid
        $this->assertTrue(true);
    }

    // ── Accept key — different inputs ───────────────────────────

    public function testComputeAcceptKeyEmptyString(): void
    {
        $result = WebSocket::computeAcceptKey('');
        $this->assertNotEmpty($result);
        $this->assertNotFalse(base64_decode($result, true));
    }

    public function testComputeAcceptKeyLongInput(): void
    {
        $key = str_repeat('abcdef', 100);
        $result = WebSocket::computeAcceptKey($key);
        $this->assertNotFalse(base64_decode($result, true));
        // SHA1 output is always 20 bytes = 28 base64 chars
        $this->assertEquals(28, strlen($result));
    }

    public function testComputeAcceptKeySpecialChars(): void
    {
        $key = '+/=special+chars/==';
        $result = WebSocket::computeAcceptKey($key);
        $this->assertNotFalse(base64_decode($result, true));
    }
}
