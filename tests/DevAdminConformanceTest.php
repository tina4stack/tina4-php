<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Dev-admin security conformance (feature 127) — REAL dispatch, no mocks.
 *
 * Drives the shared devadmin_contract.json cases through the REAL front
 * controller Router::dispatch() — the identical entry the built-in Server,
 * Swoole and PHP-FPM funnel through — with a controllable raw socket peer
 * (Request::$remoteIp) and headers. NOTHING is mocked: the witness of every
 * case is a real side effect (a file that is / is not written, a real SQLite
 * row that is / is not inserted, a secret that never appears in a body).
 *
 * Fixture: tina4-documentation/plan/v3/fixtures/devadmin_contract.json
 * Owner decisions: DEVADMIN-DEC-01 (same-origin gate), 02 (loopback), 03 (.env
 * denylist), 04 (toolbar escape), 05 (mcp/call gate), 06 (this suite).
 */

// DevAdmin + its middleware live in files PSR-4 cannot autoload individually.
require_once __DIR__ . '/../Tina4/DevAdmin.php';

use PHPUnit\Framework\TestCase;
use Tina4\DevAdmin;
use Tina4\ErrorTracker;
use Tina4\McpServer;
use Tina4\Middleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class DevAdminConformanceTest extends TestCase
{
    private const SECRET = 'sup3r-sekret-do-not-leak-127';
    private const KEYS = [
        'TINA4_DEBUG', 'TINA4_MCP', 'TINA4_MCP_REMOTE', 'TINA4_MCP_TOKEN',
        'TINA4_API_KEY', 'TINA4_HOST', 'TINA4_DATABASE_URL',
    ];

    /** @var array<string,string|false> */
    private array $savedEnv = [];
    private string $origCwd = '';
    private string $projectDir = '';
    private ?string $probeDbFile = null;

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
        $this->projectDir = sys_get_temp_dir() . '/tina4_devadmin_' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/src/routes', 0777, true);
        file_put_contents($this->projectDir . '/.env',
            "TINA4_SECRET=" . self::SECRET . "\nTINA4_DATABASE_PASSWORD=pg-password-leak\nTINA4_MCP_TOKEN=mcp-token-leak\n");
        file_put_contents($this->projectDir . '/.env.example', "TINA4_SECRET=\n");
        file_put_contents($this->projectDir . '/readme.txt', "public\n");
        chdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        chdir($this->origCwd);
        Router::clear();
        Middleware::reset();
        McpServer::resetDefaultServer();
        ErrorTracker::reset();
        $this->rrmdir($this->projectDir);
        if ($this->probeDbFile !== null) {
            @unlink($this->probeDbFile);
            $this->probeDbFile = null;
        }
        foreach (self::KEYS as $k) {
            unset($_ENV[$k]);
            $saved = $this->savedEnv[$k] ?? false;
            $saved === false ? putenv($k) : putenv("{$k}={$saved}");
        }
    }

    // ── helpers ──────────────────────────────────────────────────

    private function env(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
    }

    /** Drive the REAL front controller with an explicit peer + headers. */
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

    private function probeExists(string $rel): bool
    {
        return is_file($this->projectDir . '/' . ltrim($rel, '/'));
    }

    private function bindProbeDatabase(): void
    {
        $this->probeDbFile = tempnam(sys_get_temp_dir(), 'tina4_devadmin_call_') . '.db';
        @unlink($this->probeDbFile);
        $this->env('TINA4_DATABASE_URL', 'sqlite:' . $this->probeDbFile);
        $db = \Tina4\Database\Database::fromEnv();
        $db->execute('CREATE TABLE gate_probe (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
        $db->commit();
    }

    private function probeRowCount(): int
    {
        $result = \Tina4\Database\Database::fromEnv()->fetch('SELECT COUNT(*) AS n FROM gate_probe', []);
        $row = $result->records[0] ?? [];
        return (int) ($row['n'] ?? $row['N'] ?? -1);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    // ── DEVADMIN-DEC-06: behavioural prod-404 ────────────────────

    public function testDevAdminIsAbsentWithoutDebug(): void
    {
        // Production: TINA4_DEBUG off, so App::isDevelopment() is false (App.php:599
        // gates DevAdmin::register() on it), so register() is NEVER called — the
        // whole /__dev surface is absent (404) and a write does nothing. We mirror
        // production by NOT registering, then prove the surface is absent.
        putenv('TINA4_DEBUG');
        unset($_ENV['TINA4_DEBUG']);
        $this->assertFalse(
            \Tina4\DotEnv::isTruthy(\Tina4\DotEnv::getEnv('TINA4_DEBUG', 'false')),
            'dev gate must be off with TINA4_DEBUG unset');

        $this->assertSame(404, $this->dispatch('GET', '/__dev')->getStatusCode());
        $save = $this->dispatch('POST', '/__dev/api/file/save',
            ['sec-fetch-site' => 'same-origin'], '127.0.0.1',
            ['path' => 'prod404_probe.txt', 'content' => 'nope']);
        $this->assertSame(404, $save->getStatusCode());
        $this->assertFalse($this->probeExists('prod404_probe.txt'), 'prod /file/save wrote a file');
    }

    // ── DEVADMIN-DEC-01: fail-closed same-origin gate ────────────

    public function testACrossOriginWriteIsRefusedAndWritesNothing(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        DevAdmin::register();

        $resp = $this->dispatch('POST', '/__dev/api/file/save',
            ['sec-fetch-site' => 'cross-site', 'origin' => 'https://evil.example'], '127.0.0.1',
            ['path' => 'csrf_probe.txt', 'content' => 'pwned by drive-by CSRF']);
        $this->assertSame(403, $resp->getStatusCode(), 'cross-site write must be refused');
        $this->assertFalse($this->probeExists('csrf_probe.txt'), 'cross-origin /file/save wrote the file — drive-by CSRF open');

        // Older-browser path: an explicit cross-origin Origin, no Sec-Fetch-Site.
        $resp2 = $this->dispatch('POST', '/__dev/api/file/save',
            ['origin' => 'https://evil.example', 'host' => 'localhost:7145'], '127.0.0.1',
            ['path' => 'csrf_probe.txt', 'content' => 'pwned']);
        $this->assertSame(403, $resp2->getStatusCode());
        $this->assertFalse($this->probeExists('csrf_probe.txt'));
    }

    public function testASameOriginWriteIsAllowed(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        DevAdmin::register();

        $resp = $this->dispatch('POST', '/__dev/api/file/save',
            ['sec-fetch-site' => 'same-origin'], '127.0.0.1',
            ['path' => 'same_origin_probe.txt', 'content' => 'legit save']);
        $this->assertSame(200, $resp->getStatusCode(), 'a same-origin write must be allowed');
        $this->assertSame('legit save', file_get_contents($this->projectDir . '/same_origin_probe.txt'));
    }

    // ── DEVADMIN-DEC-03: .env / secret denylist ──────────────────

    public function testTheFileReadEndpointRefusesDotenvSecrets(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        DevAdmin::register();

        $read = $this->dispatch('GET', '/__dev/api/file?path=.env');
        $this->assertSame(403, $read->getStatusCode());
        $this->assertStringNotContainsString(self::SECRET, $read->getBody());
        $this->assertStringNotContainsString('pg-password-leak', $read->getBody());

        $raw = $this->dispatch('GET', '/__dev/api/file/raw?path=.env');
        $this->assertSame(403, $raw->getStatusCode());
        $this->assertStringNotContainsString(self::SECRET, $raw->getBody());

        // .env.example is a safe template and stays readable.
        $this->assertSame(200, $this->dispatch('GET', '/__dev/api/file?path=.env.example')->getStatusCode());
    }

    public function testTheFileListingHidesDotenv(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        DevAdmin::register();

        $resp = $this->dispatch('GET', '/__dev/api/files?path=.');
        $this->assertSame(200, $resp->getStatusCode());
        $names = array_column($resp->getJsonBody()['entries'] ?? [], 'name');
        $this->assertNotContains('.env', $names, '.env is listed by the file browser');
        $this->assertContains('readme.txt', $names, 'listing lost a legitimate file');
        $this->assertStringNotContainsString(self::SECRET, $resp->getBody());
    }

    // ── DEVADMIN-DEC-02: loopback-only mutations ─────────────────

    public function testANonLoopbackPeerCannotMutate(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        DevAdmin::register();

        $resp = $this->dispatch('POST', '/__dev/api/file/save',
            ['sec-fetch-site' => 'same-origin'], '8.8.8.8',
            ['path' => 'loopback_probe.txt', 'content' => 'remote write']);
        $this->assertSame(403, $resp->getStatusCode(), 'a non-loopback peer must be refused');
        $this->assertFalse($this->probeExists('loopback_probe.txt'), 'a non-loopback peer wrote a file');

        // Spoofed X-Forwarded-For does NOT launder a remote peer as loopback.
        $spoof = $this->dispatch('POST', '/__dev/api/file/save',
            ['sec-fetch-site' => 'same-origin', 'x-forwarded-for' => '127.0.0.1'], '8.8.8.8',
            ['path' => 'loopback_probe.txt', 'content' => 'xff bypass']);
        $this->assertSame(403, $spoof->getStatusCode());
        $this->assertFalse($this->probeExists('loopback_probe.txt'), 'spoofed X-Forwarded-For bypassed the loopback gate');
    }

    public function testALoopbackPeerMayMutate(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        DevAdmin::register();

        $resp = $this->dispatch('POST', '/__dev/api/file/save',
            ['sec-fetch-site' => 'same-origin'], '127.0.0.1',
            ['path' => 'loopback_ok_probe.txt', 'content' => 'local write']);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('local write', file_get_contents($this->projectDir . '/loopback_ok_probe.txt'));
    }

    // ── DEVADMIN-DEC-04: toolbar path escaping ───────────────────

    public function testTheInjectedToolbarEscapesTheRequestPath(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        DevAdmin::register();

        $resp = $this->dispatch('GET', '/x<script>alert(1)</script>y', ['accept' => 'text/html']);
        $body = $resp->getBody();
        $this->assertStringContainsString('tina4-dev-toolbar', $body, 'the dev toolbar was not injected');
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body, 'raw <script> reflected in the toolbar (XSS)');
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    // ── DEVADMIN-DEC-05: mcp/call tool-execution gate ────────────

    public function testARemoteUnauthenticatedMcpCallIsRefusedAndTheToolDoesNotRun(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        $this->bindProbeDatabase();
        DevAdmin::register();
        $this->assertSame(0, $this->probeRowCount());

        $insert = ['name' => 'database_execute', 'arguments' => ['sql' => "INSERT INTO gate_probe (v) VALUES ('x')"]];

        // Remote, no token -> 404 and NOTHING inserted.
        $remote = $this->dispatch('POST', '/__dev/api/mcp/call', [], '8.8.8.8', $insert);
        $this->assertSame(404, $remote->getStatusCode(), 'remote unauth mcp/call must be 404');
        $this->assertSame(0, $this->probeRowCount(), 'remote unauthenticated mcp/call executed database_execute');

        // Spoofed XFF still 404.
        $spoof = $this->dispatch('POST', '/__dev/api/mcp/call', ['x-forwarded-for' => '127.0.0.1'], '8.8.8.8', $insert);
        $this->assertSame(404, $spoof->getStatusCode());
        $this->assertSame(0, $this->probeRowCount(), 'spoofed X-Forwarded-For bypassed the mcp/call gate');

        // Loopback -> the tool runs (positive control): the row lands.
        $local = $this->dispatch('POST', '/__dev/api/mcp/call', [], '127.0.0.1', $insert);
        $this->assertSame(200, $local->getStatusCode());
        $this->assertSame(1, $this->probeRowCount());
    }
}
