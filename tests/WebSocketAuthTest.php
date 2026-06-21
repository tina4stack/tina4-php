<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Per-route WebSocket authentication lock-ins (parity with the Python set in
 * tina4_python tests). A WS route is PUBLIC by default; an @secured handler
 * docblock OR Router::websocket(..., secure: true) marks it auth_required. On
 * the upgrade — AFTER the origin allow-list, BEFORE accepting the handshake —
 * a secured route must REJECT a missing/invalid token (close the upgrade, not
 * accept) and ACCEPT a valid token via the Authorization header, the `bearer`
 * subprotocol, or ?token=. The connection then exposes the verified payload as
 * $connection->auth (null on public routes).
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;
use Tina4\Router;
use Tina4\Server;
use Tina4\WebSocket;
use Tina4\WebSocketConnection;

class WebSocketAuthTest extends TestCase
{
    private string $secret = 'ws-auth-test-secret-0123456789';

    protected function setUp(): void
    {
        Router::clear();
        putenv("TINA4_SECRET={$this->secret}");
        $_ENV['TINA4_SECRET'] = $this->secret;
        // No origin allow-list — keep the origin gate open so we isolate auth.
        putenv('TINA4_WS_ALLOWED_ORIGINS');
        unset($_ENV['TINA4_WS_ALLOWED_ORIGINS']);
    }

    protected function tearDown(): void
    {
        Router::clear();
        putenv('TINA4_SECRET');
        unset($_ENV['TINA4_SECRET']);
    }

    private function token(array $payload = ['user_id' => 1]): string
    {
        return Auth::getToken($payload, $this->secret, 60);
    }

    // ── ws_token() transports ───────────────────────────────────

    public function testWsTokenFromAuthorizationHeader(): void
    {
        $tok = $this->token();
        $this->assertSame($tok, WebSocket::wsToken(['authorization' => "Bearer {$tok}"]));
    }

    public function testWsTokenAuthorizationHeaderCaseInsensitivePrefix(): void
    {
        $tok = $this->token();
        $this->assertSame($tok, WebSocket::wsToken(['authorization' => "bearer {$tok}"]));
    }

    public function testWsTokenFromBearerSubprotocol(): void
    {
        $tok = $this->token();
        // Browser transport: new WebSocket(url, ['bearer', token]) →
        // Sec-WebSocket-Protocol: bearer, <token>
        $this->assertSame($tok, WebSocket::wsToken(['sec-websocket-protocol' => "bearer, {$tok}"]));
    }

    public function testWsTokenFromQueryParam(): void
    {
        $tok = $this->token();
        $this->assertSame($tok, WebSocket::wsToken([], "token={$tok}&foo=bar"));
    }

    public function testWsTokenHeaderWinsOverSubprotocolAndQuery(): void
    {
        $headerTok = $this->token(['user_id' => 'header']);
        $protoTok = $this->token(['user_id' => 'proto']);
        $this->assertSame($headerTok, WebSocket::wsToken(
            ['authorization' => "Bearer {$headerTok}", 'sec-websocket-protocol' => "bearer, {$protoTok}"],
            "token={$protoTok}"
        ));
    }

    public function testWsTokenReturnsNullWhenAbsent(): void
    {
        $this->assertNull(WebSocket::wsToken([]));
        $this->assertNull(WebSocket::wsToken(['authorization' => 'Bearer ']));
        $this->assertNull(WebSocket::wsToken(['sec-websocket-protocol' => 'bearer, ']));
    }

    // ── ws_authorized() ─────────────────────────────────────────

    public function testPublicRoutePassesWithNoToken(): void
    {
        [$payload, $ok] = WebSocket::wsAuthorized(['auth_required' => false], []);
        $this->assertTrue($ok);
        $this->assertNull($payload);
    }

    public function testSecuredRouteRejectsMissingToken(): void
    {
        [$payload, $ok] = WebSocket::wsAuthorized(['auth_required' => true], []);
        $this->assertFalse($ok);
        $this->assertNull($payload);
    }

    public function testSecuredRouteRejectsInvalidToken(): void
    {
        [$payload, $ok] = WebSocket::wsAuthorized(
            ['auth_required' => true],
            ['authorization' => 'Bearer not-a-real-jwt']
        );
        $this->assertFalse($ok);
        $this->assertNull($payload);
    }

    public function testSecuredRouteAcceptsValidTokenAndExposesPayload(): void
    {
        $tok = $this->token(['user_id' => 42, 'role' => 'admin']);
        [$payload, $ok] = WebSocket::wsAuthorized(
            ['auth_required' => true],
            ['authorization' => "Bearer {$tok}"]
        );
        $this->assertTrue($ok);
        $this->assertIsArray($payload);
        $this->assertSame(42, $payload['user_id']);
        $this->assertSame('admin', $payload['role']);
    }

    public function testSecuredRouteHonoursSecureKeyWhenAuthRequiredAbsent(): void
    {
        // A route table may carry only 'secure' (e.g. older entries) — treat it
        // the same as auth_required.
        [, $ok] = WebSocket::wsAuthorized(['secure' => true], []);
        $this->assertFalse($ok);
    }

    // ── Both declaration paths set auth_required ────────────────

    public function testDocblockSecuredHandlerSetsAuthRequired(): void
    {
        /**
         * @secured
         */
        $handler = function ($conn, $data, $event) {};
        Router::websocket('/ws/secret-docblock', $handler);

        $routes = Router::getWebSocketRoutes();
        $route = $this->findRoute($routes, '/ws/secret-docblock');
        $this->assertNotNull($route);
        $this->assertTrue($route['secure']);
        $this->assertTrue($route['auth_required']);
    }

    public function testImperativeSecureFlagSetsAuthRequired(): void
    {
        Router::websocket('/ws/secret-imperative', function ($conn, $data, $event) {}, true);

        $routes = Router::getWebSocketRoutes();
        $route = $this->findRoute($routes, '/ws/secret-imperative');
        $this->assertNotNull($route);
        $this->assertTrue($route['secure']);
        $this->assertTrue($route['auth_required']);
    }

    public function testWebSocketRouteIsPublicByDefault(): void
    {
        Router::websocket('/ws/public', function ($conn, $data, $event) {});

        $routes = Router::getWebSocketRoutes();
        $route = $this->findRoute($routes, '/ws/public');
        $this->assertNotNull($route);
        $this->assertFalse($route['secure']);
        $this->assertFalse($route['auth_required']);
    }

    public function testStandaloneRouteDecoratorImperativeSecureSetsAuthRequired(): void
    {
        $ws = new WebSocket(8099);
        $ws->route('/ws/standalone-secure', function ($conn) {}, true);

        $routes = Router::getWebSocketRoutes();
        $route = $this->findRoute($routes, '/ws/standalone-secure');
        $this->assertNotNull($route);
        $this->assertTrue($route['auth_required']);
    }

    // ── Handshake subprotocol echo ──────────────────────────────

    public function testHandshakeEchoesBearerSubprotocol(): void
    {
        $response = WebSocket::buildHandshakeResponse('dGhlIHNhbXBsZSBub25jZQ==', 'bearer');
        $this->assertStringContainsString('Sec-WebSocket-Protocol: bearer', $response);
        $this->assertStringEndsWith("\r\n\r\n", $response);
    }

    public function testHandshakeOmitsSubprotocolWhenNotOffered(): void
    {
        $response = WebSocket::buildHandshakeResponse('dGhlIHNhbXBsZSBub25jZQ==');
        $this->assertStringNotContainsString('Sec-WebSocket-Protocol', $response);
    }

    public function testAcceptedSubprotocolDetectsBearer(): void
    {
        $this->assertSame('bearer', WebSocket::acceptedSubprotocol(['sec-websocket-protocol' => 'bearer, xyz']));
        $this->assertNull(WebSocket::acceptedSubprotocol(['sec-websocket-protocol' => 'chat']));
        $this->assertNull(WebSocket::acceptedSubprotocol([]));
    }

    // ── End-to-end through Server::handleWebSocketUpgrade ───────
    //
    // These drive the REAL integrated upgrade entry point with a socket pair so
    // the assertions cover the wired enforcement, not just the helper. The
    // upgrade is "accepted" when a 101 is written and a WS client is registered;
    // "rejected" when a 401 is written and NO WS client is registered.

    public function testUpgradePublicRoutePassesWithNoToken(): void
    {
        Router::websocket('/ws/pub', function ($conn, $data, $event) {});
        [$server, $clientEnd, $response] = $this->upgrade('/ws/pub', []);

        $this->assertStringContainsString('101 Switching Protocols', $response);
        $this->assertSame(1, $server->getWebSocketClientCount());
        // Public route → connection.auth is null.
        $conn = $this->onlyConnection($server);
        $this->assertNull($conn->auth);
    }

    public function testUpgradeSecuredRouteRejectsMissingToken(): void
    {
        Router::websocket('/ws/sec', function ($conn, $data, $event) {}, true);
        [$server, , $response] = $this->upgrade('/ws/sec', []);

        $this->assertStringContainsString('401', $response);
        $this->assertStringNotContainsString('101 Switching Protocols', $response);
        $this->assertSame(0, $server->getWebSocketClientCount());
    }

    public function testUpgradeSecuredRouteRejectsInvalidToken(): void
    {
        Router::websocket('/ws/sec', function ($conn, $data, $event) {}, true);
        [$server, , $response] = $this->upgrade('/ws/sec', ['Authorization' => 'Bearer garbage.token.here']);

        $this->assertStringContainsString('401', $response);
        $this->assertStringNotContainsString('101 Switching Protocols', $response);
        $this->assertSame(0, $server->getWebSocketClientCount());
    }

    public function testUpgradeSecuredRouteAcceptsValidTokenViaHeader(): void
    {
        Router::websocket('/ws/sec', function ($conn, $data, $event) {}, true);
        $tok = $this->token(['user_id' => 7]);
        [$server, , $response] = $this->upgrade('/ws/sec', ['Authorization' => "Bearer {$tok}"]);

        $this->assertStringContainsString('101 Switching Protocols', $response);
        $this->assertSame(1, $server->getWebSocketClientCount());
        $conn = $this->onlyConnection($server);
        $this->assertIsArray($conn->auth);
        $this->assertSame(7, $conn->auth['user_id']);
    }

    public function testUpgradeSecuredRouteAcceptsValidTokenViaBearerSubprotocol(): void
    {
        Router::websocket('/ws/sec', function ($conn, $data, $event) {}, true);
        $tok = $this->token(['user_id' => 8]);
        [$server, , $response] = $this->upgrade('/ws/sec', ['Sec-WebSocket-Protocol' => "bearer, {$tok}"]);

        $this->assertStringContainsString('101 Switching Protocols', $response);
        // The accepted subprotocol must be echoed back for the browser handshake.
        $this->assertStringContainsString('Sec-WebSocket-Protocol: bearer', $response);
        $this->assertSame(1, $server->getWebSocketClientCount());
        $conn = $this->onlyConnection($server);
        $this->assertSame(8, $conn->auth['user_id']);
    }

    public function testUpgradeSecuredRouteAcceptsValidTokenViaQueryParam(): void
    {
        Router::websocket('/ws/sec', function ($conn, $data, $event) {}, true);
        $tok = $this->token(['user_id' => 9]);
        [$server, , $response] = $this->upgrade("/ws/sec?token={$tok}", []);

        $this->assertStringContainsString('101 Switching Protocols', $response);
        $this->assertSame(1, $server->getWebSocketClientCount());
        $conn = $this->onlyConnection($server);
        $this->assertSame(9, $conn->auth['user_id']);
    }

    public function testUpgradeDocblockSecuredRouteRejectsMissingToken(): void
    {
        /**
         * @secured
         */
        $handler = function ($conn, $data, $event) {};
        Router::websocket('/ws/sec-doc', $handler);
        [$server, , $response] = $this->upgrade('/ws/sec-doc', []);

        $this->assertStringContainsString('401', $response);
        $this->assertSame(0, $server->getWebSocketClientCount());
    }

    // ── End-to-end through the standalone WebSocket server ─────
    //
    // The standalone server (stream_socket_server) is the SECOND upgrade entry
    // point — WebSocket::handleNewConnection. Drive it via reflection with a
    // socket pair to confirm enforcement is wired there too.

    public function testStandaloneUpgradeSecuredRouteRejectsMissingToken(): void
    {
        $ws = new WebSocket(8099);
        $ws->route('/ws/sec', function ($conn) {}, true);

        [$response, $clientCount] = $this->standaloneUpgrade($ws, '/ws/sec', []);
        $this->assertStringContainsString('401', $response);
        $this->assertStringNotContainsString('101 Switching Protocols', $response);
        $this->assertSame(0, $clientCount);
    }

    public function testStandaloneUpgradeSecuredRouteAcceptsValidTokenViaQuery(): void
    {
        $ws = new WebSocket(8099);
        $ws->route('/ws/sec', function ($conn) {}, true);

        $tok = $this->token(['user_id' => 11]);
        [$response, $clientCount] = $this->standaloneUpgrade($ws, "/ws/sec?token={$tok}", []);
        $this->assertStringContainsString('101 Switching Protocols', $response);
        $this->assertSame(1, $clientCount);
    }

    public function testStandaloneUpgradePublicRoutePassesWithNoToken(): void
    {
        $ws = new WebSocket(8099);
        $ws->route('/ws/pub', function ($conn) {});

        [$response, $clientCount] = $this->standaloneUpgrade($ws, '/ws/pub', []);
        $this->assertStringContainsString('101 Switching Protocols', $response);
        $this->assertSame(1, $clientCount);
    }

    /**
     * Drive WebSocket::handleNewConnection with a socket pair by writing a real
     * raw HTTP upgrade request into the server end and reading the response.
     *
     * @return array{0: string, 1: int} [responseBytes, clientCount]
     */
    private function standaloneUpgrade(WebSocket $ws, string $path, array $extraHeaders): array
    {
        $sockets = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            $this->markTestSkipped('stream_socket_pair not available');
        }
        [$serverEnd, $clientEnd] = $sockets;

        $request = "GET {$path} HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            . "Sec-WebSocket-Version: 13\r\n";
        foreach ($extraHeaders as $k => $v) {
            $request .= "{$k}: {$v}\r\n";
        }
        $request .= "\r\n";

        // Write the request, then let the server read it from its end.
        fwrite($clientEnd, $request);

        $ref = new \ReflectionMethod(WebSocket::class, 'handleNewConnection');
        $ref->invoke($ws, $serverEnd);

        stream_set_blocking($clientEnd, false);
        $response = (string)@fread($clientEnd, 65536);

        $clientsProp = new \ReflectionProperty(WebSocket::class, 'clients');
        $clientCount = count($clientsProp->getValue($ws));

        return [$response, $clientCount];
    }

    // ── Helpers ─────────────────────────────────────────────────

    /**
     * Drive Server::handleWebSocketUpgrade with a socket pair.
     *
     * @return array{0: Server, 1: resource, 2: string} [server, clientEnd, responseBytes]
     */
    private function upgrade(string $path, array $extraHeaders): array
    {
        $sockets = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            $this->markTestSkipped('stream_socket_pair not available');
        }
        [$serverEnd, $clientEnd] = $sockets;

        $headers = [
            '_method' => 'GET',
            '_path' => $path,
            'upgrade' => 'websocket',
            'connection' => 'Upgrade',
            'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'sec-websocket-version' => '13',
        ];
        foreach ($extraHeaders as $k => $v) {
            $headers[strtolower($k)] = $v;
        }

        $server = new Server('127.0.0.1', 7146);
        $ref = new \ReflectionMethod(Server::class, 'handleWebSocketUpgrade');
        $ref->invoke($server, $serverEnd, $headers);

        // Read whatever the upgrade wrote back on the client end (non-blocking).
        stream_set_blocking($clientEnd, false);
        $response = (string)@fread($clientEnd, 65536);

        return [$server, $clientEnd, $response];
    }

    /** Return the single registered WebSocketConnection on a server. */
    private function onlyConnection(Server $server): WebSocketConnection
    {
        $prop = new \ReflectionProperty(Server::class, 'wsClients');
        $wsClients = $prop->getValue($server);
        $this->assertNotEmpty($wsClients);
        $first = reset($wsClients);
        $conn = $first['connection'] ?? null;
        $this->assertInstanceOf(WebSocketConnection::class, $conn);
        return $conn;
    }

    /** @param array<int,array> $routes */
    private function findRoute(array $routes, string $path): ?array
    {
        foreach ($routes as $route) {
            if ($route['path'] === $path) {
                return $route;
            }
        }
        return null;
    }
}
