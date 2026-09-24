<?php
/*
Copyright (c) 2026 Code Infinity
SPDX-License-Identifier: MPL-2.0
This Source Code Form is subject to the terms of the Mozilla Public
License, v. 2.0. If a copy of the MPL was not distributed with this
file, You can obtain one at https://mozilla.org/MPL/2.0/.
*/

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 *
 * Dev-surface gate contract (dev-surface security regression) — REAL dispatch and a REAL server, no mocks.
 *
 * Exercises privileged dev endpoint access through the
 * real front controller Router::dispatch() with a controllable raw socket peer and
 * headers, and the reload-socket case through a real `App::run()` child process on
 * a real TCP port. The witness of every case is a real side effect: a secret that
 * is not returned, a file that is not written, a socket that is refused.
 */

require_once __DIR__ . '/../Tina4/DevAdmin.php';
require_once __DIR__ . '/FreePort.php';

use PHPUnit\Framework\TestCase;
use Tina4\App;
use Tina4\Auth;
use Tina4\DevAdmin;
use Tina4\ErrorTracker;
use Tina4\McpServer;
use Tina4\Middleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class DevSurfaceContractTest extends TestCase
{
    private const SECRET = 'dev-surface-secret-0078';
    private const KEYS = [
        'TINA4_DEBUG', 'TINA4_MCP', 'TINA4_MCP_REMOTE', 'TINA4_MCP_TOKEN',
        'TINA4_API_KEY', 'TINA4_HOST', 'TINA4_LOG_LEVEL', 'TINA4_DATABASE_URL', 'TINA4_SECRET', 'SECRET',
    ];

    /** @var array<string,string|false> */
    private array $savedEnv = [];
    private string $origCwd = '';
    private string $baseDir = '';
    private string $projectDir = '';
    /** @var list<resource> */
    private array $spawned = [];

    protected function setUp(): void
    {
        Router::clear();
        Middleware::reset();
        McpServer::resetDefaultServer();
        foreach (self::KEYS as $k) {
            $this->savedEnv[$k] = getenv($k);
            putenv($k);
            unset($_ENV[$k]);
        }
        $this->origCwd = getcwd() ?: '.';
        $this->baseDir = sys_get_temp_dir() . '/tina4_surface_' . bin2hex(random_bytes(16));
        mkdir($this->baseDir, 0700);
        $this->projectDir = $this->baseDir . '/app';
        mkdir($this->projectDir . '/src/templates/pages', 0700, true);
        mkdir($this->projectDir . '/src/routes', 0700, true);
        mkdir($this->baseDir . '/app-sibling', 0700, true);
        file_put_contents($this->projectDir . '/.env', 'TINA4_SECRET=' . self::SECRET . "\n");
        file_put_contents($this->projectDir . '/readme.txt', "public-readme\n");
        file_put_contents($this->projectDir . '/src/templates/pages/hello.twig', 'PAGE-OK');
        file_put_contents($this->projectDir . '/src/templates/partial_secret.twig', 'PARTIAL-LEAK');
        file_put_contents($this->projectDir . '/outside.twig', 'ROOT-LEAK');
        file_put_contents($this->baseDir . '/app-sibling/secret.txt', 'SIBLING-LEAK');
        chdir($this->projectDir);
        $this->env('TINA4_DEBUG', 'true');
        $this->env('TINA4_LOG_LEVEL', 'NONE');
    }

    protected function tearDown(): void
    {
        foreach ($this->spawned as $proc) {
            if (is_resource($proc)) {
                @proc_terminate($proc, 9);
                @proc_close($proc);
            }
        }
        chdir($this->origCwd);
        Router::clear();
        Middleware::reset();
        McpServer::resetDefaultServer();
        ErrorTracker::reset();
        \Tina4\Log::reset();
        $this->rrmdir($this->baseDir);
        foreach (self::KEYS as $k) {
            unset($_ENV[$k]);
            $saved = $this->savedEnv[$k] ?? false;
            $saved === false ? putenv($k) : putenv("{$k}={$saved}");
        }
    }

    private function env(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
    }

    private function dispatch(string $method, string $path, array $headers = [], string $remoteIp = '127.0.0.1', mixed $json = null): Response
    {
        $body = null;
        if ($json !== null) {
            $body = json_encode($json);
            $headers = array_merge(['content-type' => 'application/json'], $headers);
        }
        $request = Request::create(method: $method, path: $path, body: $body, headers: $headers, remoteIp: $remoteIp);
        return Router::dispatch($request, new Response(true));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            (is_dir($p) && !is_link($p)) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    // ── Resolve first, then check the secret denylist ────────────

    public function testADotenvPathWithATrailingDotSegmentIsRefused(): void
    {
        DevAdmin::register();
        foreach (['.env/.', '.env/x/..', 'src/../.env', './.env'] as $trick) {
            foreach (['/__dev/api/file', '/__dev/api/file/raw'] as $endpoint) {
                $resp = $this->dispatch('GET', "{$endpoint}?path={$trick}");
                $this->assertContains($resp->getStatusCode(), [403, 404], "{$endpoint}?path={$trick}");
                $this->assertStringNotContainsString(self::SECRET, $resp->getBody(), "{$endpoint}?path={$trick} served .env");
            }
        }
    }

    public function testRegularFileReadsUseActualBytesAndMissingOrDirectoryTargetsAreRefused(): void
    {
        DevAdmin::register();
        foreach (['/__dev/api/file', '/__dev/api/file/raw'] as $endpoint) {
            $ok = $this->dispatch('GET', $endpoint . '?path=readme.txt');
            $this->assertSame(200, $ok->getStatusCode());
            $this->assertStringContainsString('public-readme', $ok->getBody());
            $this->assertSame(404, $this->dispatch('GET', $endpoint . '?path=missing.txt')->getStatusCode());
            $this->assertSame(404, $this->dispatch('GET', $endpoint . '?path=src')->getStatusCode());
        }
    }

    public function testASymlinkToDotenvIsRefused(): void
    {
        DevAdmin::register();
        symlink($this->projectDir . '/.env', $this->projectDir . '/innocent.txt');
        $resp = $this->dispatch('GET', '/__dev/api/file?path=innocent.txt');
        $this->assertSame(403, $resp->getStatusCode());
        $this->assertStringNotContainsString(self::SECRET, $resp->getBody());
    }

    public function testADanglingSymlinkCannotWriteOutsideTheProject(): void
    {
        DevAdmin::register();
        $outside = $this->baseDir . '/app-sibling/missing.txt';
        symlink($outside, $this->projectDir . '/dangling.txt');
        $response = $this->dispatch('POST', '/__dev/api/file/save', [], '127.0.0.1',
            ['path' => 'dangling.txt', 'content' => 'x']);
        $this->assertContains($response->getStatusCode(), [400, 403]);
        $this->assertFileDoesNotExist($outside);
    }

    public function testASiblingPrefixDirectoryIsOutsideTheProject(): void
    {
        DevAdmin::register();
        foreach (['/__dev/api/file', '/__dev/api/file/raw'] as $endpoint) {
            $resp = $this->dispatch('GET', "{$endpoint}?path=../app-sibling/secret.txt");
            $this->assertContains($resp->getStatusCode(), [403, 404], $endpoint);
            $this->assertStringNotContainsString('SIBLING-LEAK', $resp->getBody());
        }
        $save = $this->dispatch('POST', '/__dev/api/file/save', ['sec-fetch-site' => 'same-origin'], '127.0.0.1',
            ['path' => '../app-sibling/written.txt', 'content' => 'x']);
        $this->assertContains($save->getStatusCode(), [400, 403]);
        $this->assertFileDoesNotExist($this->baseDir . '/app-sibling/written.txt');
    }

    public function testMetricsFileRefusesAPathOutsideTheProject(): void
    {
        DevAdmin::register();
        $outside = $this->baseDir . '/app-sibling/secret.txt';
        $this->assertSame(403, $this->dispatch('GET', '/__dev/api/metrics/file?path=' . rawurlencode($outside))->getStatusCode());
        $this->assertSame(403, $this->dispatch('GET', '/__dev/api/metrics/file?path=../app-sibling/secret.txt')->getStatusCode());
    }

    // ── Reads carry the same gate as writes ──────────────────────

    public function testACrossOriginReadIsRefused(): void
    {
        DevAdmin::register();
        $resp = $this->dispatch('GET', '/__dev/api/file?path=readme.txt', ['sec-fetch-site' => 'cross-site']);
        $this->assertSame(403, $resp->getStatusCode());
        $this->assertStringNotContainsString('public-readme', $resp->getBody());
        $ok = $this->dispatch('GET', '/__dev/api/file?path=readme.txt', ['sec-fetch-site' => 'same-origin']);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertStringContainsString('public-readme', $ok->getBody());
    }

    public function testASameSiteFetchIsRefused(): void
    {
        DevAdmin::register();
        $resp = $this->dispatch('GET', '/__dev/api/file?path=readme.txt', ['sec-fetch-site' => 'same-site']);
        $this->assertSame(403, $resp->getStatusCode());
        $save = $this->dispatch('POST', '/__dev/api/file/save', ['sec-fetch-site' => 'same-site'], '127.0.0.1',
            ['path' => 'same_site_probe.txt', 'content' => 'x']);
        $this->assertSame(403, $save->getStatusCode());
        $this->assertFileDoesNotExist($this->projectDir . '/same_site_probe.txt');
    }

    public function testEveryDevMethodRequiresRawPeerTrust(): void
    {
        DevAdmin::register();
        foreach (['GET', 'HEAD', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $resp = $this->dispatch($method, '/__dev/api/file?path=readme.txt', [], '203.0.113.9');
            $this->assertSame(403, $resp->getStatusCode());
            $this->assertStringNotContainsString('public-readme', $resp->getBody());
        }
    }

    public function testOriginIncludesSchemeAndPortAndCannotBeOverriddenByFetchMetadata(): void
    {
        DevAdmin::register();
        foreach (['http://localhost:9999', 'https://localhost:7145', 'null', 'http://sibling.example'] as $origin) {
            $r = $this->dispatch('GET', '/__dev/api/file?path=readme.txt',
                ['host' => 'localhost:7145', 'origin' => $origin, 'sec-fetch-site' => 'same-origin']);
            $this->assertSame(403, $r->getStatusCode());
        }
        $ok = $this->dispatch('GET', '/__dev/api/file?path=readme.txt',
            ['host' => 'localhost:7145', 'origin' => 'http://localhost:7145']);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertStringContainsString('public-readme', $ok->getBody());
        $options = $this->dispatch('OPTIONS', '/__dev/api/file?path=readme.txt', [], '203.0.113.9');
        $this->assertSame(403, $options->getStatusCode());
        $head = $this->dispatch('HEAD', '/__dev/api/file?path=readme.txt', [], '203.0.113.9');
        $this->assertSame(403, $head->getStatusCode());
    }

    // ── Host allow-list (DNS rebinding) ──────────────────────────

    public function testAForeignHostHeaderIsRefused(): void
    {
        DevAdmin::register();
        foreach (['/__dev', '/__dev/api/status', '/__dev/api/file?path=readme.txt'] as $path) {
            $resp = $this->dispatch('GET', $path, ['host' => 'rebind.evil.example:7145']);
            $this->assertSame(403, $resp->getStatusCode(), "{$path} with a foreign Host");
            $this->assertStringNotContainsString('public-readme', $resp->getBody());
        }
    }

    public function testALoopbackHostHeaderIsAllowed(): void
    {
        DevAdmin::register();
        foreach (['localhost:7145', '127.0.0.1:7145', '[::1]:7145', 'localhost'] as $host) {
            $this->assertSame(200, $this->dispatch('GET', '/__dev/api/status', ['host' => $host])->getStatusCode(), "Host {$host}");
        }
        $this->env('TINA4_HOST', 'devbox.internal');
        $this->assertSame(200, $this->dispatch('GET', '/__dev/api/status', ['host' => 'devbox.internal:7145'])->getStatusCode());
    }

    public function testAForeignHostCannotReachMcp(): void
    {
        DevAdmin::register();
        $tools = $this->dispatch('GET', '/__dev/api/mcp/tools', ['host' => 'rebind.evil.example']);
        $this->assertSame(403, $tools->getStatusCode());
        $rpc = $this->dispatch('POST', '/__dev/mcp', ['host' => 'rebind.evil.example'], '127.0.0.1',
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        $this->assertSame(403, $rpc->getStatusCode());
    }

    public function testAForeignHostCannotOpenTheReloadSocket(): void
    {
        $port = $this->spawnServer();
        $this->assertStringContainsString('403', $this->wsUpgradeStatusLine($port, 'rebind.evil.example:' . $port));
        $this->assertStringContainsString('101', $this->wsUpgradeStatusLine($port, 'localhost:' . $port));
    }

    // ── Only TINA4_MCP_TOKEN unlocks the remote dev surface ──────

    public function testTheApiKeyDoesNotUnlockDevWrites(): void
    {
        DevAdmin::register();
        $this->env('TINA4_API_KEY', 'app-api-key');
        foreach ([['authorization' => 'Bearer app-api-key'], ['x-api-key' => 'app-api-key']] as $headers) {
            $resp = $this->dispatch('POST', '/__dev/api/file/save', $headers, '203.0.113.9',
                ['path' => 'api_key_probe.txt', 'content' => 'x']);
            $this->assertSame(403, $resp->getStatusCode());
            $this->assertFileDoesNotExist($this->projectDir . '/api_key_probe.txt');
        }
        $this->env('TINA4_MCP_REMOTE', 'true');
        $mcp = $this->dispatch('GET', '/__dev/api/mcp/tools', ['authorization' => 'Bearer app-api-key'], '203.0.113.9');
        $this->assertSame(200, $mcp->getStatusCode());
    }

    public function testDedicatedTokenDoesNotBypassHostOrOrigin(): void
    {
        DevAdmin::register();
        $this->env('TINA4_MCP_TOKEN', 'mcp-token-0078');
        foreach ([['host' => 'foreign.example'], ['origin' => 'http://foreign.example']] as $invalid) {
            $denied = $this->dispatch('POST', '/__dev/api/file/save',
                array_merge(['authorization' => 'Bearer mcp-token-0078'], $invalid), '203.0.113.9',
                ['path' => 'token_boundary_probe.txt', 'content' => 'x']);
            $this->assertSame(403, $denied->getStatusCode());
            $this->assertFileDoesNotExist($this->projectDir . '/token_boundary_probe.txt');
        }
        $ok = $this->dispatch('POST', '/__dev/api/file/save', ['authorization' => 'Bearer mcp-token-0078', 'host' => 'localhost:7145'], '203.0.113.9',
            ['path' => 'api_key_probe.txt', 'content' => 'ok']);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame('ok', file_get_contents($this->projectDir . '/api_key_probe.txt'));
    }

    // ── Table viewer takes only a real table name ────────────────

    public function testTableInfoRejectsAnUnknownTableName(): void
    {
        $dbFile = $this->projectDir . '/surface.db';
        $this->env('TINA4_DATABASE_URL', 'sqlite:' . $dbFile);
        $db = \Tina4\Database\Database::fromEnv();
        $db->execute('CREATE TABLE people (id INTEGER PRIMARY KEY, name TEXT)');
        $db->execute("INSERT INTO people (name) VALUES ('ada')");
        $db->commit();
        App::setDatabase($db);
        DevAdmin::register();

        $ok = $this->dispatch('GET', '/__dev/api/table?name=people');
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertStringContainsString('ada', $ok->getBody());

        foreach (["(SELECT 'INJECTED' AS leak)", "people WHERE 1=0 UNION SELECT 1,'INJECTED'", 'no_such_table'] as $bad) {
            $resp = $this->dispatch('GET', '/__dev/api/table?name=' . rawurlencode($bad));
            $this->assertSame(404, $resp->getStatusCode(), "name={$bad}");
            $this->assertStringNotContainsString('INJECTED', $resp->getBody());
        }
    }

    // ── Template auto-routing stays inside the pages root ────────

    public function testTemplateAutoRoutingCannotLeaveTheTemplatesRoot(): void
    {
        Router::$basePath = $this->projectDir;
        try {
            foreach (['/../partial_secret', '/../../outside', '/sub/../../partial_secret'] as $path) {
                $this->assertNull(Router::resolveTemplate($path), "{$path} resolved outside pages/");
            }
            $this->assertSame('pages/hello.twig', Router::resolveTemplate('/hello'));
        } finally {
            Router::$basePath = '.';
        }
    }

    // ── Health retains the release version in production ───────

    public function testHealthRetainsTheVersionOutsideDebug(): void
    {
        $this->env('TINA4_DEBUG', 'false');
        $app = new App(basePath: $this->projectDir);
        foreach (['/health', '/__health'] as $path) {
            $resp = $this->dispatch('GET', $path);
            $this->assertSame(200, $resp->getStatusCode());
            $keys = array_keys($resp->getJsonBody());
            sort($keys);
            $this->assertSame(['framework', 'status', 'uptime', 'version'], $keys);
        }
        AppTestSupport::releaseHandlers($app);
    }

    public function testHealthCarriesTheVersionInDebug(): void
    {
        $app = new App(basePath: $this->projectDir);
        try {
            $this->assertSame(App::$VERSION, $this->dispatch('GET', '/health')->getJsonBody()['version'] ?? null);
        } finally {
            AppTestSupport::releaseHandlers($app);
        }
    }

    // ── PHP-only rows ────────────────────────────────────────────

    public function testGalleryRoutesAreNotExemptOutsideDebug(): void
    {
        $this->env('TINA4_DEBUG', 'false');
        Router::post('/api/gallery/products', fn(Request $request, Response $response) => $response->json(['created' => true]));
        $resp = $this->dispatch('POST', '/api/gallery/products', [], '127.0.0.1', ['name' => 'x']);
        $this->assertSame(401, $resp->getStatusCode(), 'a production /api/gallery/ write skipped auth');

        $this->env('TINA4_DEBUG', 'true');
        $this->assertSame(200, $this->dispatch('POST', '/api/gallery/products', [], '127.0.0.1', ['name' => 'x'])->getStatusCode());
    }

    public function testGalleryDemoDoesNotMintARealToken(): void
    {
        $this->env('TINA4_SECRET', 'the-real-application-secret');
        $this->env('SECRET', 'the-real-application-secret');
        require __DIR__ . '/../Tina4/gallery/auth/src/routes/api/gallery_auth.php';
        $resp = $this->dispatch('POST', '/api/gallery/auth/login', [], '127.0.0.1', ['username' => 'admin', 'password' => 'secret']);
        $this->assertSame(200, $resp->getStatusCode());
        $token = (string) ($resp->getJsonBody()['token'] ?? '');
        $this->assertNotSame('', $token);
        $this->assertEmpty(Auth::validToken($token, 'the-real-application-secret'), 'the gallery demo minted a token the real app accepts');
        $this->assertEmpty(Auth::validToken($token), 'the gallery demo minted a token the real app accepts');
    }

    public function testADoubleSlashDevPathIsStillGated(): void
    {
        DevAdmin::register();
        foreach (['//__dev/api/file/save', '///__dev/api/file/save'] as $path) {
            $resp = $this->dispatch('POST', $path, ['sec-fetch-site' => 'cross-site'], '127.0.0.1',
                ['path' => 'double_slash_probe.txt', 'content' => 'x']);
            $this->assertContains($resp->getStatusCode(), [403, 404], $path);
            $this->assertFileDoesNotExist($this->projectDir . '/double_slash_probe.txt', "{$path} skipped the dev gate");
        }
    }

    public function testTheFallbackServerQuotesTheHost(): void
    {
        $command = App::builtinServerCommand('127.0.0.1;touch pwned', 7145, '/srv/app dir', '/srv/app dir/index.php');
        $this->assertStringContainsString(escapeshellarg('127.0.0.1;touch pwned:7145'), $command);
        $this->assertStringNotContainsString(' 127.0.0.1;touch', $command);
        $output = [];
        exec('printf "%s\n" ' . substr($command, strlen('php ')), $output);
        $this->assertSame('-S', $output[0]);
        $this->assertSame('127.0.0.1;touch pwned:7145', $output[1], 'the host reached the shell unquoted');
    }

    // ── real server plumbing ─────────────────────────────────────

    private function spawnServer(): int
    {
        $port = FreePort::get();
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
            'TINA4_OVERRIDE_CLIENT' => 'true',
            'TINA4_SUPPRESS' => 'true',
            'TINA4_AUTO_MIGRATE' => 'false',
            'TINA4_DEBUG' => 'true',
            'TINA4_NO_AI_PORT' => 'true',
            'TINA4_NO_BROWSER' => 'true',
        ];
        $proc = proc_open([PHP_BINARY, __DIR__ . '/fixtures/dual_port_server.php', (string) $port],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
            $pipes, dirname(__DIR__), $env);
        $this->assertIsResource($proc);
        $this->spawned[] = $proc;
        for ($i = 0; $i < 200; $i++) {
            $s = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 1.0);
            if ($s !== false) {
                fwrite($s, "GET /health HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
                $raw = stream_get_contents($s);
                fclose($s);
                if ($raw !== '' && $raw !== false) {
                    return $port;
                }
            }
            usleep(25_000);
        }
        $this->fail("server never answered on {$port}");
    }

    private function wsUpgradeStatusLine(int $port, string $hostHeader): string
    {
        $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 5.0);
        $this->assertIsResource($socket);
        $key = base64_encode(random_bytes(16));
        fwrite($socket, "GET /__dev_reload HTTP/1.1\r\nHost: {$hostHeader}\r\nUpgrade: websocket\r\n"
            . "Connection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");
        stream_set_timeout($socket, 5);
        $line = (string) fgets($socket);
        fclose($socket);
        return $line;
    }
}
