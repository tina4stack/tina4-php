# WebSocket

The `WebSocket` class implements a zero-dependency RFC 6455 WebSocket server using PHP's `stream_socket_server()` with `stream_select()` for non-blocking I/O. It supports event-based handlers for connection lifecycle, broadcasting, and direct messaging.

## Basic Server

```php
use Tina4\WebSocket;

$ws = new WebSocket(port: 8080);

$ws->on('open', function (string $clientId) {
    echo "Client connected: {$clientId}\n";
});

$ws->on('message', function (string $clientId, string $message) use ($ws) {
    echo "Received from {$clientId}: {$message}\n";

    // Echo back
    $ws->send($clientId, "You said: {$message}");
});

$ws->on('close', function (string $clientId) {
    echo "Client disconnected: {$clientId}\n";
});

$ws->on('error', function (string $clientId, string $error) {
    echo "Error for {$clientId}: {$error}\n";
});

// Start the server (blocking)
$ws->start();
```

## Event Handlers

| Event | Arguments | Triggered When |
|-------|-----------|----------------|
| `open` | `(string $clientId)` | Client completes WebSocket handshake |
| `message` | `(string $clientId, string $message)` | Client sends a text frame |
| `close` | `(string $clientId)` | Client disconnects |
| `error` | `(string $clientId, string $error)` | Protocol or I/O error occurs |

## Broadcasting

Send a message to all connected clients.

```php
$ws->on('message', function (string $clientId, string $message) use ($ws) {
    // Broadcast to everyone
    $ws->broadcast("User {$clientId}: {$message}");

    // Broadcast to everyone except the sender
    $ws->broadcast("User {$clientId}: {$message}", excludeIds: [$clientId]);
});
```

## Direct Messaging

Send a message to a specific client by ID.

```php
$ws->send($clientId, json_encode([
    'type' => 'notification',
    'message' => 'You have a new message',
]));
```

## Configuration

```php
$ws = new WebSocket(port: 9000, config: [
    'host' => '0.0.0.0',         // Bind address
    'maxConnections' => 100,      // Maximum concurrent clients
]);
```

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_WS_PORT` | `8080` | WebSocket server port |

## Chat Room Example

```php
$ws = new WebSocket(port: 8080);
$usernames = [];

$ws->on('open', function (string $clientId) use ($ws, &$usernames) {
    $usernames[$clientId] = 'Anonymous';
    $ws->broadcast(json_encode([
        'type' => 'system',
        'message' => 'A new user joined',
    ]));
});

$ws->on('message', function (string $clientId, string $message) use ($ws, &$usernames) {
    $data = json_decode($message, true);

    if (isset($data['type'])) {
        match ($data['type']) {
            'set_name' => $usernames[$clientId] = $data['name'],
            'chat' => $ws->broadcast(json_encode([
                'type' => 'chat',
                'from' => $usernames[$clientId],
                'message' => $data['message'],
            ])),
            default => null,
        };
    }
});

$ws->on('close', function (string $clientId) use ($ws, &$usernames) {
    $name = $usernames[$clientId] ?? 'Unknown';
    unset($usernames[$clientId]);
    $ws->broadcast(json_encode([
        'type' => 'system',
        'message' => "{$name} left the chat",
    ]));
});

$ws->start();
```

## Client-Side JavaScript

```javascript
const ws = new WebSocket('ws://localhost:8080');

ws.onopen = () => {
    console.log('Connected');
    ws.send(JSON.stringify({ type: 'set_name', name: 'Alice' }));
};

ws.onmessage = (event) => {
    const data = JSON.parse(event.data);
    console.log(`${data.from}: ${data.message}`);
};

ws.onclose = () => {
    console.log('Disconnected');
};

// Send a chat message
ws.send(JSON.stringify({ type: 'chat', message: 'Hello everyone!' }));
```

## WebSocket Opcodes

The class defines standard RFC 6455 opcodes as constants:

```php
WebSocket::OP_TEXT;          // 0x1 — text frame
WebSocket::OP_BINARY;        // 0x2 — binary frame
WebSocket::OP_CLOSE;         // 0x8 — close frame
WebSocket::OP_PING;          // 0x9 — ping frame
WebSocket::OP_PONG;          // 0xA — pong frame
WebSocket::OP_CONTINUATION;  // 0x0 — continuation frame

WebSocket::CLOSE_NORMAL;         // 1000
WebSocket::CLOSE_GOING_AWAY;     // 1001
WebSocket::CLOSE_PROTOCOL_ERROR; // 1002
```

## Tips

- The WebSocket server uses `stream_select()` for non-blocking I/O — it handles multiple clients in a single process.
- Use JSON for message payloads to maintain structure between client and server.
- The `broadcast()` method with `excludeIds` is efficient for chat-style applications.
- Run the WebSocket server as a background service using `ServiceRunner` with `daemon: true`.
- The server performs the RFC 6455 handshake automatically using the `MAGIC_GUID` constant.
- For production deployments, put a reverse proxy (nginx) in front of the WebSocket server with SSL termination.
