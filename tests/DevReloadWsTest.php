<?php

// MessageLog and the dev-admin classes live in DevAdmin.php but PSR-4 cannot
// autoload the secondary classes individually, so force-include the file.
require_once __DIR__ . '/../Tina4/DevAdmin.php';

use PHPUnit\Framework\TestCase;
use Tina4\DevAdmin;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;
use Tina4\Server;
use Tina4\WebSocket;

/**
 * Tests for the WebSocket-primary DevReload path (PHP parity).
 *
 * DevReload is WebSocket-primary: POST /__dev/api/reload re-discovers routes
 * in-process and then broadcasts an instant reload message to every browser
 * connected on /__dev_reload. The mtime poll is only a fallback. Mirrors
 * tina4-python tests/test_dev_reload_ws.py:
 *
 *   - The /__dev_reload WS route is registered (debug mode) and matchable.
 *   - POST /__dev/api/reload broadcasts a JSON {type, file, mtime} frame on
 *     /__dev_reload (code -> "reload", css -> "css" on the wire).
 *   - A broadcast failure (or zero clients) never 500s the reload endpoint.
 *   - The injected toolbar client is WebSocket-primary (poll is fallback only).
 */
class DevReloadWsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // The reload path lazily bootstraps App / Log, which install
        // process-wide error and exception handlers (Tina4/App.php documents
        // this as the reason PHPUnit can flag a test "risky"). Warm the exact
        // path once here, before any per-test risk snapshot is taken.
        // Immediately release the App's handlers and neutralise its __destruct
        // so the warm-up App can't restore a handler at a later GC (which would
        // pop it inside an unrelated test's boundary and flag that test risky).
        // Production behaviour is unchanged.
        $warmupApp = new \Tina4\App();
        AppTestSupport::releaseHandlers($warmupApp);
        unset($warmupApp);
        DevAdmin::register();
        foreach (Router::getRoutes() as $route) {
            if ($route['pattern'] === '/__dev/api/reload' && $route['method'] === 'POST') {
                ($route['callback'])(
                    Request::create('POST', '/__dev/api/reload', null, ['type' => 'code', 'file' => 'warmup.php']),
                    new Response(true),
                );
                break;
            }
        }
    }

    protected function setUp(): void
    {
        Router::clear();
        $this->resetServerInstance(null);
        $this->resetReloadState();
    }

    protected function tearDown(): void
    {
        Router::clear();
        $this->resetServerInstance(null);
    }

    // ── helpers ──────────────────────────────────────────────────────

    private function resetReloadState(): void
    {
        $ref = new \ReflectionClass(DevAdmin::class);
        foreach (['reloadMtime' => 0, 'reloadFile' => '', 'pendingReload' => false] as $name => $value) {
            // setAccessible() is a no-op on PHP 8.1+ (deprecated on 8.5), so
            // we rely on ReflectionProperty being accessible by default here.
            $ref->getProperty($name)->setValue(null, $value);
        }
    }

    private function resetServerInstance(?Server $server): void
    {
        (new \ReflectionClass(Server::class))->getProperty('instance')->setValue(null, $server);
    }

    private function findRouteCallback(string $method, string $pattern): ?callable
    {
        foreach (Router::getRoutes() as $route) {
            if ($route['pattern'] === $pattern && $route['method'] === $method) {
                return $route['callback'];
            }
        }
        return null;
    }

    private function callReloadPost(array $body = []): array
    {
        $callback = $this->findRouteCallback('POST', '/__dev/api/reload');
        $this->assertNotNull($callback, 'Reload route should be registered');
        $request = Request::create('POST', '/__dev/api/reload', null, $body);
        $response = new Response(true);
        $result = $callback($request, $response);
        return json_decode($result->getBody(), true);
    }

    /**
     * Register a fake WebSocket client on the running server via reflection,
     * backed by a real socket pair so broadcastWebSocket() actually writes a
     * frame we can read back from the far end. Returns the far-end socket.
     *
     * @return resource
     */
    private function attachFakeWsClient(Server $server, string $path)
    {
        [$near, $far] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($far, false);

        $prop = (new \ReflectionClass(Server::class))->getProperty('wsClients');
        $clients = $prop->getValue($server);
        $clients['fake-' . bin2hex(random_bytes(4))] = [
            'socket' => $near,
            'path' => $path,
            'buffer' => '',
            'id' => 'fake',
        ];
        $prop->setValue($server, $clients);

        return $far;
    }

    private function readBroadcastPayload($far): ?array
    {
        $raw = '';
        $deadline = microtime(true) + 1.0;
        // The frame header is at most 10 bytes; read until decodeFrame is happy.
        while (microtime(true) < $deadline) {
            $chunk = @fread($far, 8192);
            if ($chunk !== false && $chunk !== '') {
                $raw .= $chunk;
                $frame = WebSocket::decodeFrame($raw);
                if ($frame !== null) {
                    return json_decode($frame['payload'], true);
                }
            }
            usleep(2000);
        }
        return null;
    }

    // ── Registration: /__dev_reload is a registered WS route ──────────

    public function testDevReloadWsRouteRegisterable(): void
    {
        // Mirrors test_dev_reload_ws_route_registered: registering the route
        // makes it matchable by the upgrade handler (debug mode). PHP already
        // registers it in Server::start(); here we register it directly and
        // assert the registry picks it up by exact path match.
        Router::websocket('/__dev_reload', function ($connection, $data, $event) {
            // no-op — clients connect only to receive reload signals
        });

        $matched = null;
        foreach (Router::getWebSocketRoutes() as $wsRoute) {
            if ($wsRoute['path'] === '/__dev_reload') {
                $matched = $wsRoute;
                break;
            }
        }

        $this->assertNotNull($matched, '/__dev_reload must be a registered WS route');
        $this->assertSame('/__dev_reload', $matched['path']);
    }

    // ── Broadcast: code change -> {type:reload} on /__dev_reload ──────

    public function testReloadPostBroadcastsReloadPayload(): void
    {
        DevAdmin::register();

        $server = new Server('127.0.0.1', 0);
        $this->resetServerInstance($server);
        $far = $this->attachFakeWsClient($server, '/__dev_reload');

        $result = $this->callReloadPost(['type' => 'code', 'file' => 'src/routes/index.php']);
        $this->assertTrue($result['ok']);
        $this->assertSame('code', $result['type']); // HTTP echoes caller's type

        $payload = $this->readBroadcastPayload($far);
        $this->assertNotNull($payload, 'WS client should have received a broadcast frame');
        $this->assertSame('reload', $payload['type']); // code normalised to reload on the wire
        $this->assertSame('src/routes/index.php', $payload['file']);
        $this->assertIsInt($payload['mtime']);

        fclose($far);
    }

    public function testReloadPostBroadcastsCssType(): void
    {
        DevAdmin::register();

        $server = new Server('127.0.0.1', 0);
        $this->resetServerInstance($server);
        $far = $this->attachFakeWsClient($server, '/__dev_reload');

        $this->callReloadPost(['type' => 'css', 'file' => 'src/public/css/app.css']);

        $payload = $this->readBroadcastPayload($far);
        $this->assertNotNull($payload);
        $this->assertSame('css', $payload['type']);
        $this->assertSame('src/public/css/app.css', $payload['file']);

        fclose($far);
    }

    public function testReloadOnlyBroadcastsToDevReloadPath(): void
    {
        // A client on a different WS path must NOT receive the dev-reload frame.
        DevAdmin::register();

        $server = new Server('127.0.0.1', 0);
        $this->resetServerInstance($server);
        $other = $this->attachFakeWsClient($server, '/some/other/ws');

        $this->callReloadPost(['type' => 'code', 'file' => 'src/routes/index.php']);

        $this->assertNull(
            $this->readBroadcastPayload($other),
            'A client on a non-/__dev_reload path must not receive the reload frame'
        );

        fclose($other);
    }

    // ── Resilience: broadcast failure never 500s the endpoint ─────────

    public function testReloadSurvivesNoRunningServer(): void
    {
        // No Server instance set (the common unit-test / CLI case). The
        // endpoint must still return ok and leave $pendingReload set so the
        // idle-tick fallback can broadcast once a server is running.
        DevAdmin::register();
        $this->resetServerInstance(null);

        $result = $this->callReloadPost(['type' => 'code', 'file' => 'src/routes/x.php']);
        $this->assertTrue($result['ok']);
        $this->assertTrue(DevAdmin::$pendingReload, 'pendingReload must remain set when no server broadcast ran');
    }

    public function testReloadClearsPendingAfterDirectBroadcast(): void
    {
        // When the broadcast does run against a live server, the idle-tick
        // flag is cleared so the server doesn't double-fire a payload-less
        // reload a moment later.
        DevAdmin::register();

        $server = new Server('127.0.0.1', 0);
        $this->resetServerInstance($server);
        $far = $this->attachFakeWsClient($server, '/__dev_reload');

        $this->callReloadPost(['type' => 'code', 'file' => 'src/routes/index.php']);
        $this->assertFalse(DevAdmin::$pendingReload, 'pendingReload must clear after a live broadcast');

        fclose($far);
    }

    // ── Injected client: WebSocket-primary, poll is fallback only ─────

    public function testInjectedClientIsWebSocketPrimary(): void
    {
        $oldSuppress = DevAdmin::$suppressReload;
        DevAdmin::$suppressReload = false;
        try {
            $html = DevAdmin::renderToolbar('GET', '/', '/', 'req1', 1);
        } finally {
            DevAdmin::$suppressReload = $oldSuppress;
        }

        // Opens a WebSocket to /__dev_reload (ws/wss by page protocol).
        $this->assertStringContainsString("'/__dev_reload'", $html);
        $this->assertStringContainsString("location.protocol==='https:'?'wss':'ws'", $html);
        // Stops the poll on socket open; (re)starts it on close.
        $this->assertStringContainsString('_t4_stopPoll', $html);
        $this->assertStringContainsString('_t4_startPoll', $html);
        // Reconnects after the socket drops (~2s).
        $this->assertStringContainsString('setTimeout(_t4_connect,2000)', $html);
        // Poll uses a null sentinel and reloads when mtime DIFFERS (not just >),
        // so the first change after load is not swallowed.
        $this->assertStringContainsString('_t4_mtime===null', $html);
        $this->assertStringContainsString('d.mtime!==_t4_mtime', $html);
        $this->assertStringNotContainsString('d.mtime>mt', $html);
        // CSS swaps stylesheet hrefs; everything else does a full reload.
        $this->assertStringContainsString('location.reload()', $html);
        $this->assertStringContainsString("link[rel=\"stylesheet\"]", $html);
    }

    public function testInjectedClientSuppressedOnAiPort(): void
    {
        // The reload client must be omitted when suppressed (AI/stable port).
        $oldSuppress = DevAdmin::$suppressReload;
        DevAdmin::$suppressReload = true;
        try {
            $html = DevAdmin::renderToolbar('GET', '/', '/', 'req1', 1);
        } finally {
            DevAdmin::$suppressReload = $oldSuppress;
        }

        $this->assertStringNotContainsString('/__dev_reload', $html);
        $this->assertStringNotContainsString('_t4_connect', $html);
    }
}
