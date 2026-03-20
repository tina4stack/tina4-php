# Tina4 v3.0 — WebSocket Specification (Zero-Dep, RFC 6455)

## Philosophy
Fully native WebSocket implementation in each language. No `simple_websocket`, no `ws` npm, no `faye-websocket`, no third-party libraries. Raw TCP socket handling with the RFC 6455 frame protocol implemented from scratch.

## What We Implement

### RFC 6455 — The WebSocket Protocol
The entire spec is ~70 pages but the implementation is ~300-400 lines per language. Here's what's needed:

### 1. HTTP Upgrade Handshake

**Client sends:**
```http
GET /ws/chat HTTP/1.1
Host: example.com
Upgrade: websocket
Connection: Upgrade
Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==
Sec-WebSocket-Version: 13
```

**Server responds:**
```http
HTTP/1.1 101 Switching Protocols
Upgrade: websocket
Connection: Upgrade
Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=
```

**Accept key computation** (the only crypto needed):
```
Sec-WebSocket-Accept = base64(sha1(Sec-WebSocket-Key + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"))
```

This uses SHA-1 + Base64 — both in every language's stdlib.

### 2. Frame Protocol

```
 0                   1                   2                   3
 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
+-+-+-+-+-------+-+-------------+-------------------------------+
|F|R|R|R| opcode|M| Payload len |    Extended payload length    |
|I|S|S|S|  (4)  |A|     (7)     |             (16/64)           |
|N|V|V|V|       |S|             |   (if payload len==126/127)   |
| |1|2|3|       |K|             |                               |
+-+-+-+-+-------+-+-------------+-------------------------------+
|     Masking-key (if MASK set)  |          Payload Data         |
+--------------------------------+-------------------------------+
```

**Opcodes:**
| Code | Name | Description |
|------|------|-------------|
| 0x0 | Continuation | Fragment continuation |
| 0x1 | Text | UTF-8 text frame |
| 0x2 | Binary | Binary data frame |
| 0x8 | Close | Connection close |
| 0x9 | Ping | Keepalive ping |
| 0xA | Pong | Keepalive pong |

**What we implement:**
- FIN bit parsing (fragmentation support)
- Opcode handling (text, binary, close, ping, pong)
- Masking/unmasking (client→server frames are always masked)
- Payload length (7-bit, 16-bit extended, 64-bit extended)
- Frame assembly (combine fragments into complete messages)
- Server frames are never masked (per spec)

### 3. Implementation Per Language

#### Python — asyncio raw sockets
```python
# ~300 lines total
import asyncio
import hashlib
import base64
import struct

class WebSocketServer:
    """Native RFC 6455 WebSocket server using asyncio."""

    MAGIC_STRING = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"

    async def handle_connection(self, reader, writer):
        # 1. Read HTTP upgrade request
        request = await reader.readuntil(b"\r\n\r\n")
        headers = self._parse_headers(request)

        # 2. Validate upgrade request
        if headers.get("upgrade", "").lower() != "websocket":
            writer.write(b"HTTP/1.1 400 Bad Request\r\n\r\n")
            writer.close()
            return

        # 3. Compute accept key
        key = headers["sec-websocket-key"]
        accept = base64.b64encode(
            hashlib.sha1((key + self.MAGIC_STRING).encode()).digest()
        ).decode()

        # 4. Send upgrade response
        writer.write(
            f"HTTP/1.1 101 Switching Protocols\r\n"
            f"Upgrade: websocket\r\n"
            f"Connection: Upgrade\r\n"
            f"Sec-WebSocket-Accept: {accept}\r\n\r\n".encode()
        )
        await writer.drain()

        # 5. Enter frame loop
        ws = WebSocketConnection(reader, writer)
        await self._on_connect(ws)

        try:
            async for message in ws:
                await self._on_message(ws, message)
        except ConnectionError:
            pass
        finally:
            await self._on_disconnect(ws)

    async def _read_frame(self, reader):
        # Read 2-byte header
        header = await reader.readexactly(2)
        fin = (header[0] >> 7) & 1
        opcode = header[0] & 0x0F
        masked = (header[1] >> 7) & 1
        payload_len = header[1] & 0x7F

        # Extended payload length
        if payload_len == 126:
            payload_len = struct.unpack(">H", await reader.readexactly(2))[0]
        elif payload_len == 127:
            payload_len = struct.unpack(">Q", await reader.readexactly(8))[0]

        # Masking key (4 bytes if masked)
        mask_key = await reader.readexactly(4) if masked else None

        # Payload
        payload = await reader.readexactly(payload_len)

        # Unmask
        if mask_key:
            payload = bytes(b ^ mask_key[i % 4] for i, b in enumerate(payload))

        return fin, opcode, payload

    def _write_frame(self, writer, opcode, payload):
        # Server frames are never masked
        frame = bytearray()
        frame.append(0x80 | opcode)  # FIN + opcode

        length = len(payload)
        if length < 126:
            frame.append(length)
        elif length < 65536:
            frame.append(126)
            frame.extend(struct.pack(">H", length))
        else:
            frame.append(127)
            frame.extend(struct.pack(">Q", length))

        frame.extend(payload)
        writer.write(bytes(frame))
```

#### PHP — stream_socket (or Swoole upgrade)
```php
// ~350 lines total
class WebSocketServer {
    private const MAGIC_STRING = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";

    public function handleUpgrade(string $request, $socket): void {
        $headers = $this->parseHeaders($request);
        $key = $headers['Sec-WebSocket-Key'];
        $accept = base64_encode(sha1($key . self::MAGIC_STRING, true));

        $response = "HTTP/1.1 101 Switching Protocols\r\n" .
                    "Upgrade: websocket\r\n" .
                    "Connection: Upgrade\r\n" .
                    "Sec-WebSocket-Accept: {$accept}\r\n\r\n";

        fwrite($socket, $response);
    }

    public function readFrame($socket): array {
        $header = fread($socket, 2);
        $fin = (ord($header[0]) >> 7) & 1;
        $opcode = ord($header[0]) & 0x0F;
        $masked = (ord($header[1]) >> 7) & 1;
        $payloadLen = ord($header[1]) & 0x7F;

        if ($payloadLen === 126) {
            $payloadLen = unpack('n', fread($socket, 2))[1];
        } elseif ($payloadLen === 127) {
            $payloadLen = unpack('J', fread($socket, 8))[1];
        }

        $maskKey = $masked ? fread($socket, 4) : null;
        $payload = $payloadLen > 0 ? fread($socket, $payloadLen) : '';

        if ($maskKey) {
            for ($i = 0; $i < strlen($payload); $i++) {
                $payload[$i] = $payload[$i] ^ $maskKey[$i % 4];
            }
        }

        return ['fin' => $fin, 'opcode' => $opcode, 'payload' => $payload];
    }

    public function writeFrame($socket, int $opcode, string $payload): void {
        $frame = chr(0x80 | $opcode);
        $length = strlen($payload);

        if ($length < 126) {
            $frame .= chr($length);
        } elseif ($length < 65536) {
            $frame .= chr(126) . pack('n', $length);
        } else {
            $frame .= chr(127) . pack('J', $length);
        }

        $frame .= $payload;
        fwrite($socket, $frame);
    }
}

// PHP also supports Swoole WebSocket natively when ext-openswoole is available.
// The framework auto-detects Swoole and uses its built-in WebSocket server
// instead of the stream_socket implementation for better performance.
```

#### Ruby — TCPSocket
```ruby
# ~300 lines total
require 'socket'
require 'digest/sha1'
require 'base64'

class WebSocketServer
  MAGIC_STRING = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"

  def handle_upgrade(client, headers)
    key = headers["Sec-WebSocket-Key"]
    accept = Base64.strict_encode64(
      Digest::SHA1.digest(key + MAGIC_STRING)
    )

    client.write(
      "HTTP/1.1 101 Switching Protocols\r\n" \
      "Upgrade: websocket\r\n" \
      "Connection: Upgrade\r\n" \
      "Sec-WebSocket-Accept: #{accept}\r\n\r\n"
    )
  end

  def read_frame(client)
    header = client.read(2).bytes
    fin = (header[0] >> 7) & 1
    opcode = header[0] & 0x0F
    masked = (header[1] >> 7) & 1
    payload_len = header[1] & 0x7F

    if payload_len == 126
      payload_len = client.read(2).unpack("n")[0]
    elsif payload_len == 127
      payload_len = client.read(8).unpack("Q>")[0]
    end

    mask_key = masked == 1 ? client.read(4).bytes : nil
    payload = client.read(payload_len).bytes

    if mask_key
      payload = payload.each_with_index.map { |b, i| b ^ mask_key[i % 4] }
    end

    { fin: fin, opcode: opcode, payload: payload.pack("C*") }
  end

  def write_frame(client, opcode, payload)
    frame = [(0x80 | opcode)].pack("C")
    length = payload.bytesize

    if length < 126
      frame << [length].pack("C")
    elsif length < 65536
      frame << [126, length].pack("Cn")
    else
      frame << [127, length].pack("CQ>")
    end

    frame << payload
    client.write(frame)
  end
end
```

#### Node.js — node:net / node:http upgrade
```typescript
// ~300 lines total
import { createServer, IncomingMessage } from 'node:http';
import { createHash } from 'node:crypto';
import { Socket } from 'node:net';

const MAGIC_STRING = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

function handleUpgrade(req: IncomingMessage, socket: Socket): void {
  const key = req.headers['sec-websocket-key']!;
  const accept = createHash('sha1')
    .update(key + MAGIC_STRING)
    .digest('base64');

  socket.write(
    'HTTP/1.1 101 Switching Protocols\r\n' +
    'Upgrade: websocket\r\n' +
    'Connection: Upgrade\r\n' +
    `Sec-WebSocket-Accept: ${accept}\r\n\r\n`
  );
}

function readFrame(buffer: Buffer): { fin: boolean; opcode: number; payload: Buffer } {
  let offset = 0;
  const fin = (buffer[offset] >> 7) & 1;
  const opcode = buffer[offset] & 0x0f;
  offset++;

  const masked = (buffer[offset] >> 7) & 1;
  let payloadLen = buffer[offset] & 0x7f;
  offset++;

  if (payloadLen === 126) {
    payloadLen = buffer.readUInt16BE(offset);
    offset += 2;
  } else if (payloadLen === 127) {
    payloadLen = Number(buffer.readBigUInt64BE(offset));
    offset += 8;
  }

  let maskKey: Buffer | null = null;
  if (masked) {
    maskKey = buffer.subarray(offset, offset + 4);
    offset += 4;
  }

  let payload = buffer.subarray(offset, offset + payloadLen);

  if (maskKey) {
    payload = Buffer.from(payload.map((b, i) => b ^ maskKey![i % 4]));
  }

  return { fin: !!fin, opcode, payload };
}

function writeFrame(socket: Socket, opcode: number, payload: Buffer): void {
  const length = payload.length;
  const header: number[] = [0x80 | opcode];

  if (length < 126) {
    header.push(length);
  } else if (length < 65536) {
    header.push(126);
    const buf = Buffer.alloc(2);
    buf.writeUInt16BE(length);
    header.push(...buf);
  } else {
    header.push(127);
    const buf = Buffer.alloc(8);
    buf.writeBigUInt64BE(BigInt(length));
    header.push(...buf);
  }

  socket.write(Buffer.from([...header, ...payload]));
}
```

## High-Level API (What Developers Use)

The raw frame protocol is internal. Developers use a clean API:

### Route Registration

**Python:**
```python
from tina4_python import websocket

@websocket("/ws/chat")
async def chat(ws):
    await ws.send("Welcome!")

    async for message in ws:
        # Broadcast to all connections on this path
        await ws.broadcast(f"{ws.id}: {message}")

@websocket("/ws/chat")
async def on_connect(ws):
    print(f"Connected: {ws.id} from {ws.ip}")

@websocket("/ws/chat")
async def on_disconnect(ws):
    print(f"Disconnected: {ws.id}")
```

**PHP:**
```php
use Tina4\WebSocket;

WebSocket::on("/ws/chat", function($ws) {
    $ws->send("Welcome!");

    $ws->onMessage(function($message) use ($ws) {
        $ws->broadcast("{$ws->id}: {$message}");
    });

    $ws->onClose(function() use ($ws) {
        echo "Disconnected: {$ws->id}\n";
    });
});
```

**Ruby:**
```ruby
websocket "/ws/chat" do |ws|
  ws.send("Welcome!")

  ws.on_message do |message|
    ws.broadcast("#{ws.id}: #{message}")
  end

  ws.on_close do
    puts "Disconnected: #{ws.id}"
  end
end
```

**TypeScript:**
```typescript
// src/routes/ws/chat/ws.ts (file-based WebSocket route)
import { WebSocketConnection } from '@tina4/core';

export default (ws: WebSocketConnection) => {
  ws.send('Welcome!');

  ws.onMessage((message) => {
    ws.broadcast(`${ws.id}: ${message}`);
  });

  ws.onClose(() => {
    console.log(`Disconnected: ${ws.id}`);
  });
};
```

### WebSocketConnection API

```
interface WebSocketConnection:
    id: string                              # Unique connection ID
    ip: string                              # Client IP address
    path: string                            # WebSocket route path
    headers: dict                           # Original HTTP headers
    params: dict                            # URL parameters
    connected_at: datetime                  # Connection timestamp

    send(message: string | bytes)           # Send to this connection
    send_json(data: object)                 # Send JSON (auto-serialized)
    broadcast(message, exclude_self=false)  # Send to all on same path
    broadcast_to(path, message)             # Send to all on different path
    close(code=1000, reason="")             # Close connection
    ping()                                  # Send ping frame

    on_message(handler)                     # Register message handler
    on_close(handler)                       # Register close handler
    on_error(handler)                       # Register error handler
    on_ping(handler)                        # Register ping handler (auto-pong by default)
```

### Connection Manager (Internal)

Tracks all active connections:

```
WebSocketManager:
    connections: { id → WebSocketConnection }
    paths: { path → Set[WebSocketConnection] }

    get(id) → WebSocketConnection | null
    get_by_path(path) → [WebSocketConnection]
    count() → int
    count_by_path(path) → int
    broadcast(path, message)               # Send to all on path
    broadcast_all(message)                 # Send to all connections
    disconnect(id)                         # Force disconnect
    disconnect_all(path)                   # Force disconnect all on path
```

### Integration with Frond Live Blocks

`{% live %}` blocks use WebSocket when configured:

```html
{% live "notifications" ws "/ws/live" %}
  <span>{{ notifications | length }} new</span>
{% endlive %}
```

The framework auto-registers a `/ws/live` WebSocket endpoint that:
1. Accepts connections from frond.js
2. Watches for data changes (via event system)
3. Re-renders the Frond block server-side
4. Pushes the HTML fragment to the client
5. frond.js swaps the DOM

### PHP Swoole Detection

When `ext-openswoole` or `ext-swoole` is available, the framework auto-detects and uses Swoole's built-in WebSocket server instead of `stream_socket`. Same API, better performance:

```php
// Auto-detected at startup
if (extension_loaded('openswoole') || extension_loaded('swoole')) {
    // Use Swoole\WebSocket\Server — handles thousands of connections
    $server = new \Swoole\WebSocket\Server("0.0.0.0", 7145);
} else {
    // Fallback to native stream_socket implementation
    $server = new Tina4\WebSocketServer("0.0.0.0", 7145);
}
```

## Testing Requirements

### Positive Tests
1. `test_upgrade_handshake` — HTTP upgrade produces 101 with correct accept key
2. `test_send_text_frame` — server sends text frame, client receives it
3. `test_receive_text_frame` — client sends masked text frame, server reads it
4. `test_send_binary_frame` — binary data roundtrip
5. `test_ping_pong` — server ping → client pong (auto)
6. `test_close_handshake` — close frame exchange, clean disconnect
7. `test_fragmented_message` — multi-frame message assembled correctly
8. `test_large_payload_126` — 16-bit extended length (126-65535 bytes)
9. `test_large_payload_127` — 64-bit extended length (>65535 bytes)
10. `test_broadcast` — message sent to all connections on same path
11. `test_broadcast_exclude_self` — sender excluded from broadcast
12. `test_multiple_paths` — /ws/chat and /ws/live are independent
13. `test_connection_params` — URL params available in ws.params
14. `test_send_json` — auto-serialization to JSON
15. `test_concurrent_connections` — 100 simultaneous connections
16. `test_connection_manager_count` — manager tracks connection count accurately

### Negative Tests
1. `test_invalid_upgrade` — non-WebSocket upgrade request → 400
2. `test_missing_key` — missing Sec-WebSocket-Key → 400
3. `test_wrong_version` — Sec-WebSocket-Version != 13 → 426
4. `test_unmasked_client_frame` — client sends unmasked frame → close connection
5. `test_invalid_opcode` — unknown opcode → close with 1002
6. `test_oversized_frame` — frame exceeding max size (configurable, default 1MB) → close with 1009
7. `test_send_after_close` — send after close is silent no-op, no crash
8. `test_disconnect_cleanup` — connection removed from manager after disconnect

### Configuration
```env
TINA4_WS_MAX_FRAME_SIZE=1048576            # Max frame size in bytes (default: 1MB)
TINA4_WS_MAX_CONNECTIONS=10000             # Max concurrent connections (default: 10000)
TINA4_WS_PING_INTERVAL=30                  # Server-side ping interval in seconds (default: 30)
TINA4_WS_PING_TIMEOUT=10                   # Pong timeout before disconnect (default: 10)
```
