<?php

/**
 * MCP Streamable HTTP transport tests.
 *
 * No mocks: every assertion runs against a real McpServer and real JSON-RPC
 * messages through dispatchHttp(). Locks in the wire contract Claude Code and
 * other current MCP clients depend on. Mirrors the Python master + Node tests.
 * The transport is fully synchronous (no long-lived connection / stream_select),
 * so it is exercised here without a running server.
 */

use PHPUnit\Framework\TestCase;
use Tina4\McpServer;

class McpTransportTest extends TestCase
{
    private function server(): McpServer
    {
        $s = new McpServer('/t-http', 'Transport Test');
        $s->registerTool('greet', static fn (string $name) => "hi {$name}", 'Greet');
        return $s;
    }

    public function testNegotiatesProtocolVersion(): void
    {
        $s = $this->server();
        $this->assertSame('2025-06-18', $s->negotiateProtocolVersion('2025-06-18'));
        $this->assertSame('2024-11-05', $s->negotiateProtocolVersion('2024-11-05'), 'legacy version still accepted');
        $this->assertSame('2025-06-18', $s->negotiateProtocolVersion('1999-01-01'), 'unknown -> newest');
        $this->assertSame('2025-06-18', $s->negotiateProtocolVersion(null), 'unversioned -> newest');
    }

    public function testSessionLifecycle(): void
    {
        $s = $this->server();
        $sid = $s->openSession();
        $this->assertNotSame('', $sid);
        $this->assertTrue($s->isValidSession($sid));
        $this->assertTrue($s->closeSession($sid));
        $this->assertFalse($s->isValidSession($sid));
        $this->assertFalse($s->closeSession('nope'));
    }

    public function testInitializeIssuesSessionHeaderAndNegotiatedVersion(): void
    {
        $s = $this->server();
        $out = $s->dispatchHttp([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18'],
        ]);
        $this->assertSame(200, $out['status']);
        $sid = $out['headers']['Mcp-Session-Id'] ?? '';
        $this->assertNotSame('', $sid, 'initialize must issue an Mcp-Session-Id header');
        $this->assertTrue($s->isValidSession($sid));
        $body = json_decode($out['body'], true);
        $this->assertSame('2025-06-18', $body['result']['protocolVersion']);
    }

    public function testUnknownSessionIsRejectedWith404(): void
    {
        $s = $this->server();
        $out = $s->dispatchHttp(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []],
            'never-issued'
        );
        $this->assertSame(404, $out['status'], 'unknown session -> 404 so the client re-initializes');
        $this->assertNotEmpty(json_decode($out['body'], true)['error']);
    }

    public function testValidSessionAllowsToolsList(): void
    {
        $s = $this->server();
        $init = $s->dispatchHttp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]);
        $sid = $init['headers']['Mcp-Session-Id'];
        $out = $s->dispatchHttp(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list', 'params' => []], $sid);
        $this->assertSame(200, $out['status']);
        $names = array_column(json_decode($out['body'], true)['result']['tools'], 'name');
        $this->assertContains('greet', $names);
    }

    public function testNotificationYields202EmptyAndNoSessionHeader(): void
    {
        $s = $this->server();
        $out = $s->dispatchHttp(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $this->assertSame(202, $out['status']);
        $this->assertSame('', $out['body']);
        $this->assertArrayNotHasKey('Mcp-Session-Id', $out['headers']);
    }

    public function testMissingSessionIsLenient(): void
    {
        $s = $this->server();
        // A stateless client that never sends a session id still works.
        $out = $s->dispatchHttp(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list', 'params' => []], '');
        $this->assertSame(200, $out['status']);
    }
}
