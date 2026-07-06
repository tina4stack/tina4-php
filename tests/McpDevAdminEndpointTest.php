<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

// MessageLog / RequestInspector / DevAdmin live together in DevAdmin.php
// and PSR-4 cannot autoload them individually, so force-include the file.
require_once __DIR__ . '/../Tina4/DevAdmin.php';

use PHPUnit\Framework\TestCase;
use Tina4\DevAdmin;
use Tina4\ErrorTracker;
use Tina4\McpProtocol;
use Tina4\McpServer;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

/**
 * The dev MCP server exposes a browser REST shim (/__dev/api/mcp/*) AND
 * the JSON-RPC 2.0 + SSE transport that real MCP clients (Claude Code /
 * Claude Desktop) speak (/__dev/mcp, /__dev/mcp/message, /__dev/mcp/sse).
 *
 * These tests lock in that the JSON-RPC + SSE endpoints are mounted on
 * the dev-admin dispatch (the same Router used by the REST shim), share
 * the same default McpServer (so tools match), and behave per the MCP
 * spec. Mirrors the tina4-python suite added alongside the same fix.
 */
class McpDevAdminEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
        McpServer::resetDefaultServer();
        // The MCP endpoints are now gated on McpServer::isEnabled().
        // These tests assert the endpoints ARE mounted, so explicitly
        // enable MCP for the duration of the test (explicit TINA4_MCP
        // wins on any host).
        putenv('TINA4_MCP=true');
        $_ENV['TINA4_MCP'] = 'true';
    }

    protected function tearDown(): void
    {
        ErrorTracker::reset();
        Router::clear();
        McpServer::resetDefaultServer();
        putenv('TINA4_MCP');
        unset($_ENV['TINA4_MCP']);
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

    /** Round-trip one JSON-RPC message through the mounted POST endpoint. */
    private function rpc(array $message, string $pattern = '/__dev/mcp/message'): Response
    {
        $callback = $this->findRouteCallback('POST', $pattern);
        $this->assertNotNull($callback, "POST {$pattern} must be registered");
        $request = Request::create('POST', $pattern, body: $message);
        $response = new Response(true);
        return $callback($request, $response);
    }

    // ── Endpoint registration ────────────────────────────────────

    public function testJsonRpcEndpointsAreRegistered(): void
    {
        DevAdmin::register();
        $patterns = array_map(
            fn($r) => $r['method'] . ' ' . $r['pattern'],
            Router::getRoutes()
        );
        $this->assertContains('POST /__dev/mcp', $patterns);
        $this->assertContains('POST /__dev/mcp/message', $patterns);
        $this->assertContains('GET /__dev/mcp/sse', $patterns);
    }

    public function testRestShimStillRegistered(): void
    {
        // The new JSON-RPC mount must not displace the browser REST shim.
        DevAdmin::register();
        $patterns = array_map(
            fn($r) => $r['method'] . ' ' . $r['pattern'],
            Router::getRoutes()
        );
        $this->assertContains('GET /__dev/api/mcp/tools', $patterns);
        $this->assertContains('POST /__dev/api/mcp/call', $patterns);
    }

    public function testEndpointsAreOpen(): void
    {
        // MCP clients don't carry the dev JWT, so the JSON-RPC + SSE
        // routes must be flagged noAuth (auth_required === false) exactly
        // like the supervisor proxy routes.
        DevAdmin::register();
        foreach (Router::getRoutes() as $route) {
            if (in_array($route['pattern'], ['/__dev/mcp', '/__dev/mcp/message', '/__dev/mcp/sse'], true)) {
                $this->assertFalse(
                    $route['auth_required'],
                    "{$route['method']} {$route['pattern']} must be open to unauthenticated MCP clients"
                );
            }
        }
    }

    // ── Disabled (production) → 404 ───────────────────────────────

    public function testEndpointsAbsentWhenDevAdminNotRegistered(): void
    {
        // register() only runs in development mode (gated on TINA4_DEBUG).
        // With it not called — i.e. production — the routes simply do not
        // exist and the dispatcher returns 404. We assert absence here.
        $this->assertNull($this->findRouteCallback('POST', '/__dev/mcp'));
        $this->assertNull($this->findRouteCallback('POST', '/__dev/mcp/message'));
        $this->assertNull($this->findRouteCallback('GET', '/__dev/mcp/sse'));
    }

    // ── initialize handshake ──────────────────────────────────────

    public function testInitializeReturnsServerInfo(): void
    {
        DevAdmin::register();
        $response = $this->rpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [],
        ]);
        $this->assertSame(200, $response->getStatusCode());
        $json = json_decode($response->getBody(), true);
        $this->assertSame('2.0', $json['jsonrpc']);
        $this->assertSame(1, $json['id']);
        $this->assertSame('Tina4 Dev Tools', $json['result']['serverInfo']['name']);
        $this->assertArrayHasKey('protocolVersion', $json['result']);
        $this->assertArrayHasKey('tools', $json['result']['capabilities']);
    }

    public function testBarePathAlsoHandlesInitialize(): void
    {
        // POST /__dev/mcp (no /message suffix) must feed the same handler.
        DevAdmin::register();
        $response = $this->rpc([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'initialize',
            'params' => [],
        ], '/__dev/mcp');
        $json = json_decode($response->getBody(), true);
        $this->assertSame('Tina4 Dev Tools', $json['result']['serverInfo']['name']);
    }

    // ── tools/list ────────────────────────────────────────────────

    public function testToolsListReturnsTools(): void
    {
        DevAdmin::register();
        $response = $this->rpc([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ]);
        $json = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('tools', $json['result']);
        $this->assertNotEmpty($json['result']['tools']);
        $names = array_column($json['result']['tools'], 'name');
        $this->assertContains('route_list', $names);
    }

    public function testEndpointSharesDefaultServerToolset(): void
    {
        // The JSON-RPC endpoint must expose the SAME tools the REST shim
        // serves — both read from McpServer::getDefaultServer(). Compare
        // the tool name sets so they can never drift apart.
        DevAdmin::register();

        $response = $this->rpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ]);
        $rpcNames = array_column(json_decode($response->getBody(), true)['result']['tools'], 'name');
        sort($rpcNames);

        $shimNames = array_column(McpServer::getDefaultServer()->getToolList(), 'name');
        sort($shimNames);

        $this->assertSame($shimNames, $rpcNames);
    }

    // ── tools/call (safe read-only tool) ──────────────────────────

    public function testToolsCallReturnsContent(): void
    {
        DevAdmin::register();
        // route_list is stateless + read-only and present in every app.
        Router::get('/sample/route', function ($req, $resp) {
            return $resp('ok');
        });

        $response = $this->rpc([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'route_list', 'arguments' => []],
        ]);
        $json = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('content', $json['result']);
        $this->assertSame('text', $json['result']['content'][0]['type']);

        // route_list returns a JSON array of {method, path, auth_required}.
        $decoded = json_decode($json['result']['content'][0]['text'], true);
        $this->assertIsArray($decoded);
        $paths = array_column($decoded, 'path');
        $this->assertContains('/sample/route', $paths);
    }

    public function testUnknownToolReturnsError(): void
    {
        DevAdmin::register();
        $response = $this->rpc([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => ['name' => 'no_such_tool', 'arguments' => []],
        ]);
        $json = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $json);
        $this->assertSame(McpProtocol::INTERNAL_ERROR, $json['error']['code']);
    }

    public function testUnknownMethodReturnsError(): void
    {
        DevAdmin::register();
        $response = $this->rpc([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'does/not/exist',
            'params' => [],
        ]);
        $json = json_decode($response->getBody(), true);
        $this->assertSame(McpProtocol::METHOD_NOT_FOUND, $json['error']['code']);
    }

    // ── Notifications (no id) → 202 Accepted ──────────────────────
    // The MCP Streamable HTTP transport (2025-03-26 / 2025-06-18) mandates
    // 202 Accepted with an empty body for a notification-only POST — parity
    // with the Python master (test_notification_yields_202_empty), Node
    // (mcpEndpoint.test.ts) and Ruby (mcp_dev_endpoint_spec.rb). 204 was the
    // stale legacy expectation before the 3.13.51 Streamable HTTP work.

    public function testNotificationReturns202(): void
    {
        DevAdmin::register();
        $response = $this->rpc([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);
        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('', $response->getBody());
    }

    // ── SSE handshake ─────────────────────────────────────────────

    public function testSseHandshakeAdvertisesEndpoint(): void
    {
        DevAdmin::register();
        $callback = $this->findRouteCallback('GET', '/__dev/mcp/sse');
        $this->assertNotNull($callback, 'GET /__dev/mcp/sse must be registered');

        $request = Request::create('GET', '/__dev/mcp/sse');
        $response = new Response(true);
        /** @var Response $result */
        $result = $callback($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $body = $result->getBody();
        $this->assertStringContainsString('event: endpoint', $body);
        $this->assertStringContainsString('data: /__dev/mcp/message', $body);
        // SSE frames terminate on a blank line.
        $this->assertStringEndsWith("\n\n", $body);

        $headers = $result->getHeaders();
        $this->assertSame('text/event-stream', $headers['Content-Type'] ?? null);
    }

    // ── Tool-coverage: the core dev toolkit is reachable ──────────

    public function testCoreToolSetIsRegistered(): void
    {
        DevAdmin::register();
        $response = $this->rpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ]);
        $names = array_column(json_decode($response->getBody(), true)['result']['tools'], 'name');

        $expected = [
            'database_query', 'database_execute',
            'file_read', 'file_write', 'file_list',
            'route_list',
            'migration_status',
            'plan_list', 'plan_create',
            'log_tail',
            'docs_list',
            'project_overview',
        ];
        foreach ($expected as $tool) {
            $this->assertContains($tool, $names, "Core MCP tool missing: {$tool}");
        }
    }

    /**
     * A handful of stateless, read-only tools must execute through
     * tools/call without raising a JSON-RPC protocol error. (Their
     * payload may legitimately be an empty list or an {error} dict when
     * the project has no plans / logs yet — we only assert the transport
     * succeeds, not the contents.)
     */
    public function testSafeReadOnlyToolsExecuteWithoutProtocolError(): void
    {
        DevAdmin::register();

        $cases = [
            ['route_list', []],
            ['file_list', ['path' => '.']],
            ['plan_list', []],
            ['docs_list', []],
            ['log_tail', ['lines' => 5]],
        ];

        foreach ($cases as [$tool, $args]) {
            $response = $this->rpc([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $args],
            ]);
            $json = json_decode($response->getBody(), true);
            $this->assertArrayNotHasKey(
                'error',
                $json,
                "tool {$tool} raised a JSON-RPC protocol error"
            );
            $this->assertArrayHasKey('content', $json['result'], "tool {$tool} returned no content");
        }
    }
}
