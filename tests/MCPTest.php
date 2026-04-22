<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\McpProtocol;
use Tina4\McpServer;
use Tina4\McpSchema;
use Tina4\McpTool;
use Tina4\McpResource;
use Tina4\McpDevTools;

class MCPTest extends TestCase
{
    // ── JSON-RPC 2.0 Protocol Tests ──────────────────────────────

    public function testEncodeResponse(): void
    {
        $raw = McpProtocol::encodeResponse(1, ['tools' => []]);
        $msg = json_decode($raw, true);
        $this->assertSame('2.0', $msg['jsonrpc']);
        $this->assertSame(1, $msg['id']);
        $this->assertSame(['tools' => []], $msg['result']);
    }

    public function testEncodeError(): void
    {
        $raw = McpProtocol::encodeError(2, McpProtocol::METHOD_NOT_FOUND, 'Not found');
        $msg = json_decode($raw, true);
        $this->assertSame('2.0', $msg['jsonrpc']);
        $this->assertSame(2, $msg['id']);
        $this->assertSame(-32601, $msg['error']['code']);
        $this->assertSame('Not found', $msg['error']['message']);
    }

    public function testEncodeErrorWithData(): void
    {
        $raw = McpProtocol::encodeError(3, McpProtocol::INTERNAL_ERROR, 'Oops', ['detail' => 'stack']);
        $msg = json_decode($raw, true);
        $this->assertSame('stack', $msg['error']['data']['detail']);
    }

    public function testEncodeNotification(): void
    {
        $raw = McpProtocol::encodeNotification('tools/changed', ['type' => 'added']);
        $msg = json_decode($raw, true);
        $this->assertSame('2.0', $msg['jsonrpc']);
        $this->assertSame('tools/changed', $msg['method']);
        $this->assertSame(['type' => 'added'], $msg['params']);
        $this->assertArrayNotHasKey('id', $msg);
    }

    public function testEncodeNotificationWithoutParams(): void
    {
        $raw = McpProtocol::encodeNotification('ping');
        $msg = json_decode($raw, true);
        $this->assertArrayNotHasKey('params', $msg);
    }

    public function testDecodeRequest(): void
    {
        $input = json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/list',
            'params' => [],
        ]);
        [$method, $params, $rid] = McpProtocol::decodeRequest($input);
        $this->assertSame('tools/list', $method);
        $this->assertSame([], $params);
        $this->assertSame(3, $rid);
    }

    public function testDecodeRequestFromArray(): void
    {
        [$method, $params, $rid] = McpProtocol::decodeRequest([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'ping',
            'params' => ['key' => 'value'],
        ]);
        $this->assertSame('ping', $method);
        $this->assertSame(['key' => 'value'], $params);
        $this->assertSame(5, $rid);
    }

    public function testDecodeNotification(): void
    {
        [$method, $params, $rid] = McpProtocol::decodeRequest([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);
        $this->assertSame('notifications/initialized', $method);
        $this->assertNull($rid);
    }

    public function testDecodeInvalidJson(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessageMatches('/Invalid JSON/');
        McpProtocol::decodeRequest('not json');
    }

    public function testDecodeMissingMethod(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessageMatches('/method/');
        McpProtocol::decodeRequest(['jsonrpc' => '2.0', 'id' => 1]);
    }

    public function testDecodeMissingJsonrpc(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessageMatches('/jsonrpc/');
        McpProtocol::decodeRequest(['method' => 'test', 'id' => 1]);
    }

    // ── McpServer Core Tests ─────────────────────────────────────

    public function testInitializeHandshake(): void
    {
        $server = new McpServer('/test-mcp', 'Test Server', '0.1.0');
        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ]), true);

        $this->assertSame('2024-11-05', $resp['result']['protocolVersion']);
        $this->assertSame('Test Server', $resp['result']['serverInfo']['name']);
        $this->assertSame('0.1.0', $resp['result']['serverInfo']['version']);
        $this->assertArrayHasKey('tools', $resp['result']['capabilities']);
        $this->assertArrayHasKey('resources', $resp['result']['capabilities']);
    }

    public function testPing(): void
    {
        $server = new McpServer('/test-mcp', 'Test Server');
        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'ping',
            'params' => [],
        ]), true);
        $this->assertSame([], $resp['result']);
    }

    public function testMethodNotFound(): void
    {
        $server = new McpServer('/test-mcp', 'Test Server');
        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'nonexistent',
            'params' => [],
        ]), true);
        $this->assertSame(-32601, $resp['error']['code']);
    }

    public function testNotificationNoResponse(): void
    {
        $server = new McpServer('/test-mcp', 'Test Server');
        $resp = $server->handleMessage([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);
        $this->assertSame('', $resp);
    }

    public function testParseError(): void
    {
        $server = new McpServer('/test-mcp', 'Test Server');
        $resp = json_decode($server->handleMessage('not valid json'), true);
        $this->assertSame(McpProtocol::PARSE_ERROR, $resp['error']['code']);
    }

    // ── Tool Registration and Invocation Tests ───────────────────

    public function testRegisterTool(): void
    {
        $server = new McpServer('/test-tools', 'Tool Test');
        $server->registerTool('greet', function (string $name): string {
            return "Hello, $name!";
        }, 'Greet someone');

        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ]), true);

        $tools = $resp['result']['tools'];
        $this->assertCount(1, $tools);
        $this->assertSame('greet', $tools[0]['name']);
        $this->assertSame('string', $tools[0]['inputSchema']['properties']['name']['type']);
        $this->assertContains('name', $tools[0]['inputSchema']['required']);
    }

    public function testCallTool(): void
    {
        $server = new McpServer('/test-tools', 'Tool Test');
        $server->registerTool('add', function (int $a, int $b): int {
            return $a + $b;
        }, 'Add two numbers');

        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'add', 'arguments' => ['a' => 3, 'b' => 5]],
        ]), true);

        $content = $resp['result']['content'];
        $this->assertCount(1, $content);
        $this->assertSame('text', $content[0]['type']);
        $this->assertStringContainsString('8', $content[0]['text']);
    }

    public function testCallToolWithStringResult(): void
    {
        $server = new McpServer('/test-tools', 'Tool Test');
        $server->registerTool('greet', function (string $name): string {
            return "Hello, $name!";
        }, 'Greet');

        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'greet', 'arguments' => ['name' => 'World']],
        ]), true);

        $this->assertSame('Hello, World!', $resp['result']['content'][0]['text']);
    }

    public function testCallToolWithArrayResult(): void
    {
        $server = new McpServer('/test-tools', 'Tool Test');
        $server->registerTool('data', function (): array {
            return ['users' => ['Alice', 'Bob']];
        }, 'Get data');

        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'data', 'arguments' => []],
        ]), true);

        $decoded = json_decode($resp['result']['content'][0]['text'], true);
        $this->assertSame(['Alice', 'Bob'], $decoded['users']);
    }

    public function testUnknownTool(): void
    {
        $server = new McpServer('/test-tools', 'Tool Test');
        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'missing', 'arguments' => []],
        ]), true);
        $this->assertSame(McpProtocol::INTERNAL_ERROR, $resp['error']['code']);
    }

    public function testToolWithDefaultParams(): void
    {
        $server = new McpServer('/test-tools', 'Tool Test');
        $server->registerTool('repeat', function (string $text, int $times = 3): string {
            return str_repeat($text, $times);
        }, 'Repeat text');

        // Call without optional param
        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'repeat', 'arguments' => ['text' => 'hi']],
        ]), true);

        $this->assertSame('hihihi', $resp['result']['content'][0]['text']);
    }

    // ── Schema Extraction Tests ──────────────────────────────────

    public function testSchemaFromClosure(): void
    {
        $fn = function (string $name, int $count = 5, bool $active = true) {};
        $schema = McpSchema::fromCallable($fn);

        $this->assertSame('string', $schema['properties']['name']['type']);
        $this->assertSame('integer', $schema['properties']['count']['type']);
        $this->assertSame(5, $schema['properties']['count']['default']);
        $this->assertSame('boolean', $schema['properties']['active']['type']);
        $this->assertTrue($schema['properties']['active']['default']);
        $this->assertSame(['name'], $schema['required']);
    }

    public function testSchemaFromClosureNoParams(): void
    {
        $fn = function () {};
        $schema = McpSchema::fromCallable($fn);
        $this->assertSame('object', $schema['type']);
        $this->assertEmpty($schema['properties']);
        $this->assertArrayNotHasKey('required', $schema);
    }

    public function testSchemaFromClosureAllRequired(): void
    {
        $fn = function (string $a, int $b, float $c) {};
        $schema = McpSchema::fromCallable($fn);
        $this->assertSame(['a', 'b', 'c'], $schema['required']);
        $this->assertSame('number', $schema['properties']['c']['type']);
    }

    // ── Resource Registration and Reading Tests ──────────────────

    public function testRegisterResource(): void
    {
        $server = new McpServer('/test-resources', 'Resource Test');
        $server->registerResource('app://tables', function () {
            return ['users', 'products'];
        }, 'Database tables');

        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/list',
            'params' => [],
        ]), true);

        $resources = $resp['result']['resources'];
        $this->assertCount(1, $resources);
        $this->assertSame('app://tables', $resources[0]['uri']);
    }

    public function testReadResource(): void
    {
        $server = new McpServer('/test-resources', 'Resource Test');
        $server->registerResource('app://info', function () {
            return ['version' => '1.0', 'name' => 'Test App'];
        }, 'App info');

        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'resources/read',
            'params' => ['uri' => 'app://info'],
        ]), true);

        $contents = $resp['result']['contents'];
        $this->assertCount(1, $contents);
        $data = json_decode($contents[0]['text'], true);
        $this->assertSame('1.0', $data['version']);
        $this->assertSame('Test App', $data['name']);
    }

    public function testReadResourceString(): void
    {
        $server = new McpServer('/test-resources', 'Resource Test');
        $server->registerResource('app://readme', function () {
            return 'This is the readme';
        }, 'Readme', 'text/plain');

        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/read',
            'params' => ['uri' => 'app://readme'],
        ]), true);

        $this->assertSame('text/plain', $resp['result']['contents'][0]['mimeType']);
        $this->assertSame('This is the readme', $resp['result']['contents'][0]['text']);
    }

    public function testUnknownResource(): void
    {
        $server = new McpServer('/test-resources', 'Resource Test');
        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'resources/read',
            'params' => ['uri' => 'app://missing'],
        ]), true);
        $this->assertSame(McpProtocol::INTERNAL_ERROR, $resp['error']['code']);
    }

    // ── PHP 8.1+ Attribute Tests ─────────────────────────────────

    public function testRegisterFromAttributes(): void
    {
        $server = new McpServer('/test-attrs', 'Attr Test');

        $service = new class {
            #[McpTool('hello', 'Say hello')]
            public function hello(string $name): string
            {
                return "Hello, $name!";
            }

            #[McpResource('app://version', 'App version')]
            public function version(): array
            {
                return ['version' => '2.0'];
            }
        };

        $server->registerFromAttributes($service);

        // Check tool registered
        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ]), true);
        $this->assertCount(1, $resp['result']['tools']);
        $this->assertSame('hello', $resp['result']['tools'][0]['name']);

        // Check resource registered
        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'resources/list',
            'params' => [],
        ]), true);
        $this->assertCount(1, $resp['result']['resources']);
        $this->assertSame('app://version', $resp['result']['resources'][0]['uri']);

        // Call tool
        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'hello', 'arguments' => ['name' => 'PHP']],
        ]), true);
        $this->assertSame('Hello, PHP!', $resp['result']['content'][0]['text']);
    }

    // ── Localhost Detection Tests ────────────────────────────────

    public function testLocalhostDetection(): void
    {
        $original = getenv('HOST_NAME');

        putenv('HOST_NAME=localhost:7146');
        $this->assertTrue(McpServer::isLocalhost());

        putenv('HOST_NAME=127.0.0.1:7146');
        $this->assertTrue(McpServer::isLocalhost());

        putenv('HOST_NAME=0.0.0.0:7146');
        $this->assertTrue(McpServer::isLocalhost());

        putenv('HOST_NAME=myserver.example.com:7146');
        $this->assertFalse(McpServer::isLocalhost());

        // Restore
        if ($original !== false) {
            putenv("HOST_NAME=$original");
        } else {
            putenv('HOST_NAME');
        }
    }

    // ── File Sandbox Tests ───────────────────────────────────────

    public function testFileSandboxBlocksEscape(): void
    {
        $server = new McpServer('/test-sandbox', 'Sandbox Test');
        $tmpDir = sys_get_temp_dir() . '/mcp_test_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $oldCwd = getcwd();
        chdir($tmpDir);

        try {
            McpDevTools::register($server);

            // Try to read a file outside project dir
            $resp = json_decode($server->handleMessage([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'file_read', 'arguments' => ['path' => '../../../etc/passwd']],
            ]), true);

            // Should error - path escapes project directory
            $this->assertArrayHasKey('error', $resp, 'Expected error response for path escape attempt');
            $errorMsg = strtolower($resp['error']['message']);
            $this->assertTrue(
                str_contains($errorMsg, 'escapes') || str_contains($errorMsg, 'path'),
                "Error message should mention path escape: {$resp['error']['message']}"
            );
        } finally {
            chdir($oldCwd);
            @rmdir($tmpDir);
        }
    }

    public function testFileWriteSandboxBlocksEscape(): void
    {
        $server = new McpServer('/test-sandbox2', 'Sandbox Test 2');
        $tmpDir = sys_get_temp_dir() . '/mcp_test_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $oldCwd = getcwd();
        chdir($tmpDir);

        try {
            McpDevTools::register($server);

            $resp = json_decode($server->handleMessage([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'file_write', 'arguments' => ['path' => '../../evil.txt', 'content' => 'hacked']],
            ]), true);

            // Should error - path escapes project directory
            $this->assertArrayHasKey('error', $resp, 'Expected error response for path escape attempt');
        } finally {
            chdir($oldCwd);
            @rmdir($tmpDir);
        }
    }

    // ── WriteClaudeConfig Tests ──────────────────────────────────

    public function testWriteClaudeConfig(): void
    {
        $tmpDir = sys_get_temp_dir() . '/mcp_config_test_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $oldCwd = getcwd();
        chdir($tmpDir);

        try {
            $server = new McpServer('/my-mcp', 'My MCP Server');
            $server->writeClaudeConfig(8080);

            $configFile = $tmpDir . '/.claude/settings.json';
            $this->assertFileExists($configFile);

            $config = json_decode(file_get_contents($configFile), true);
            $this->assertArrayHasKey('mcpServers', $config);
            $this->assertArrayHasKey('my-mcp-server', $config['mcpServers']);
            $this->assertSame(
                'http://localhost:8080/my-mcp/sse',
                $config['mcpServers']['my-mcp-server']['url']
            );
        } finally {
            chdir($oldCwd);
            // Cleanup
            @unlink($tmpDir . '/.claude/settings.json');
            @rmdir($tmpDir . '/.claude');
            @rmdir($tmpDir);
        }
    }

    // ── Multiple Tools and Resources ─────────────────────────────

    public function testMultipleToolsRegistration(): void
    {
        $server = new McpServer('/test-multi', 'Multi Test');
        $server->registerTool('add', fn(int $a, int $b) => $a + $b, 'Add');
        $server->registerTool('sub', fn(int $a, int $b) => $a - $b, 'Subtract');
        $server->registerTool('mul', fn(int $a, int $b) => $a * $b, 'Multiply');

        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ]), true);

        $this->assertCount(3, $resp['result']['tools']);
        $names = array_column($resp['result']['tools'], 'name');
        $this->assertContains('add', $names);
        $this->assertContains('sub', $names);
        $this->assertContains('mul', $names);
    }

    public function testMultipleResourcesRegistration(): void
    {
        $server = new McpServer('/test-multi', 'Multi Test');
        $server->registerResource('app://tables', fn() => ['users'], 'Tables');
        $server->registerResource('app://version', fn() => '1.0', 'Version');

        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/list',
            'params' => [],
        ]), true);

        $this->assertCount(2, $resp['result']['resources']);
    }

    // ── Error Constants ──────────────────────────────────────────

    public function testErrorConstants(): void
    {
        $this->assertSame(-32700, McpProtocol::PARSE_ERROR);
        $this->assertSame(-32600, McpProtocol::INVALID_REQUEST);
        $this->assertSame(-32601, McpProtocol::METHOD_NOT_FOUND);
        $this->assertSame(-32602, McpProtocol::INVALID_PARAMS);
        $this->assertSame(-32603, McpProtocol::INTERNAL_ERROR);
    }

    // ── Dev Tools Registration Count ─────────────────────────────

    public function testDevToolsRegistersExpectedToolkit(): void
    {
        $server = new McpServer('/test-dev', 'Dev Test');
        $tmpDir = sys_get_temp_dir() . '/mcp_dev_test_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $oldCwd = getcwd();
        chdir($tmpDir);

        try {
            McpDevTools::register($server);

            $resp = json_decode($server->handleMessage([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ]), true);

            // Exact parity with Python (tina4_python/mcp/tools.py):
            // 24 base tools + 9 plan_* + 3 docs_* + git_status + deps_list
            // + project_overview = 39. Assert it doesn't regress below.
            $this->assertGreaterThanOrEqual(39, count($resp['result']['tools']));

            // Verify all expected tool names are present
            $names = array_column($resp['result']['tools'], 'name');
            $expected = [
                'database_query', 'database_execute', 'database_tables', 'database_columns',
                'route_list', 'route_test', 'swagger_spec',
                'template_render',
                'file_read', 'file_write', 'file_list', 'asset_upload',
                'migration_status', 'migration_create', 'migration_run',
                'queue_status',
                'session_list', 'cache_stats',
                'orm_describe',
                'log_tail', 'error_log', 'env_list',
                'seed_table',
                'system_info',
            ];
            foreach ($expected as $toolName) {
                $this->assertContains($toolName, $names, "Missing tool: $toolName");
            }
        } finally {
            chdir($oldCwd);
            @rmdir($tmpDir);
        }
    }

    // ── mcp_tool() / mcp_resource() module-level helpers ──────────

    public function testMcpToolRegistersOnServer(): void
    {
        $server = new McpServer('/test', 'Test');
        $decorator = \Tina4\mcp_tool('greet', 'Say hello', $server);
        $decorator(function (string $name): string {
            return "Hello $name";
        });

        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ]), true);

        $names = array_column($resp['result']['tools'], 'name');
        $this->assertContains('greet', $names);
    }

    public function testMcpToolReturnsOriginalHandler(): void
    {
        $server = new McpServer('/test2', 'Test2');
        $handler = fn(string $x): string => strtoupper($x);
        $decorator = \Tina4\mcp_tool('up', '', $server);
        $returned = $decorator($handler);
        $this->assertSame($handler, $returned);
    }

    public function testMcpResourceRegistersOnServer(): void
    {
        $server = new McpServer('/test3', 'Test3');
        $decorator = \Tina4\mcp_resource('test://data', 'Test data', 'application/json', $server);
        $decorator(function (): array {
            return ['items' => []];
        });

        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/list',
            'params' => [],
        ]), true);

        $uris = array_column($resp['result']['resources'], 'uri');
        $this->assertContains('test://data', $uris);
    }

    // ── Default-server bootstrap + REST shim parity with Python ─────

    public function testDefaultServerAutoRegistersDevTools(): void
    {
        McpServer::resetDefaultServer();
        $server = McpServer::getDefaultServer();
        $tools = $server->getToolList();
        $this->assertGreaterThan(0, count($tools), 'default server should register the built-in dev toolkit');
        $names = array_column($tools, 'name');
        foreach (['database_tables', 'route_list', 'file_read', 'file_list', 'system_info'] as $expected) {
            $this->assertContains($expected, $names, "default server missing tool: {$expected}");
        }
    }

    public function testGetToolListShapeMatchesPython(): void
    {
        $server = new McpServer('/rest-shape', 'Test');
        $server->registerTool('echo', function (string $text): string {
            return $text;
        }, 'Echo a string');

        $list = $server->getToolList();
        $this->assertCount(1, $list);
        $this->assertSame('echo', $list[0]['name']);
        $this->assertSame('Echo a string', $list[0]['description']);
        $this->assertArrayHasKey('schema', $list[0]);
        $this->assertSame('object', $list[0]['schema']['type']);
        $this->assertArrayHasKey('text', $list[0]['schema']['properties']);
    }

    public function testCallToolInvokesByName(): void
    {
        $server = new McpServer('/rest-call', 'Test');
        $server->registerTool('square', fn(int $n): int => $n * $n, 'Square a number');
        $this->assertSame(49, $server->callTool('square', ['n' => 7]));
    }

    public function testCallToolUnknownThrows(): void
    {
        $server = new McpServer('/rest-call-unknown', 'Test');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown tool: nope');
        $server->callTool('nope', []);
    }

    public function testCallToolHonoursDefaultParameters(): void
    {
        $server = new McpServer('/rest-call-defaults', 'Test');
        $server->registerTool('greet', function (string $name = 'world'): string {
            return "hi {$name}";
        });
        $this->assertSame('hi world', $server->callTool('greet', []));
        $this->assertSame('hi alice', $server->callTool('greet', ['name' => 'alice']));
    }
}
