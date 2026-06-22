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
use Tina4\McpServer;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

/**
 * MCP endpoint security guard (3.13.40 P0 — PHP mirror of the Python master
 * tests/test_mcp_security.py).
 *
 * Locks in the capability / per-request authorisation split that replaced the
 * old config-host isEnabled() gate, plus the read-only guard on the
 * database_query tool and the identifier guard on database_columns. These are
 * regression tests for the audit finding that a 0.0.0.0-bound TINA4_DEBUG box
 * exposed the full MCP tool registry (DB query/execute, file write) to remote
 * unauthenticated callers.
 *
 * The trust decision is driven by the RAW socket peer (Request::$remoteIp),
 * NEVER X-Forwarded-For — a spoofed-XFF case is asserted explicitly.
 */
class McpSecurityTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $savedEnv = [];

    private const KEYS = [
        'TINA4_MCP', 'TINA4_DEBUG', 'TINA4_MCP_REMOTE',
        'TINA4_MCP_TOKEN', 'TINA4_API_KEY', 'TINA4_HOST_NAME', 'TINA4_DATABASE_URL',
    ];

    protected function setUp(): void
    {
        Router::clear();
        McpServer::resetDefaultServer();
        foreach (self::KEYS as $k) {
            $this->savedEnv[$k] = getenv($k);
            putenv($k);
            unset($_ENV[$k]);
        }
    }

    protected function tearDown(): void
    {
        ErrorTracker::reset();
        Router::clear();
        McpServer::resetDefaultServer();
        foreach (self::KEYS as $k) {
            unset($_ENV[$k]);
            $saved = $this->savedEnv[$k] ?? false;
            if ($saved === false) {
                putenv($k);
            } else {
                putenv("{$k}={$saved}");
            }
        }
    }

    private function env(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
    }

    // ── isLoopback ───────────────────────────────────────────────

    public function testLoopbackAddresses(): void
    {
        foreach (['127.0.0.1', '127.0.0.5', '::1', '::ffff:127.0.0.1', 'localhost', ''] as $ip) {
            $this->assertTrue(McpServer::isLoopback($ip), $ip === '' ? '(empty)' : $ip);
        }
    }

    public function testNonLoopbackAddresses(): void
    {
        // 0.0.0.0 is a BIND address, never a client address -> not loopback.
        foreach (['0.0.0.0', '1.2.3.4', '10.0.0.5', '192.168.1.10',
                  '::ffff:1.2.3.4', '2001:db8::1', '203.0.113.9'] as $ip) {
            $this->assertFalse(McpServer::isLoopback($ip), $ip);
        }
    }

    // ── isEnabled (capability, host-independent) ─────────────────

    public function testEnabledOffByDefault(): void
    {
        $this->assertFalse(McpServer::isEnabled());
    }

    public function testDebugEnables(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        $this->assertTrue(McpServer::isEnabled());
    }

    public function testExplicitFalseOverridesDebug(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        $this->env('TINA4_MCP', 'false');
        $this->assertFalse(McpServer::isEnabled());
    }

    public function testHostNameIsNotTheGate(): void
    {
        // Old bug: a configured 0.0.0.0 host flipped the gate on via
        // isLocalhost(). isEnabled() now ignores the host entirely.
        $this->env('TINA4_DEBUG', 'true');
        $this->env('TINA4_HOST_NAME', '0.0.0.0:7145');
        $this->assertTrue(McpServer::isEnabled());
    }

    // ── isRequestAllowed (per-request authorisation) ─────────────

    public function testDisabledDeniesEvenLoopback(): void
    {
        $this->assertFalse(McpServer::isRequestAllowed('127.0.0.1'));
    }

    public function testLoopbackAllowedWhenEnabled(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        $this->assertTrue(McpServer::isRequestAllowed('127.0.0.1'));
        $this->assertTrue(McpServer::isRequestAllowed(''));   // in-process / built-in dev
    }

    public function testRemoteDeniedWithoutOptIn(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        $this->assertFalse(McpServer::isRequestAllowed('1.2.3.4'));
    }

    public function testRemoteDeniedWithoutToken(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        $this->env('TINA4_MCP_REMOTE', 'true');
        $this->assertFalse(McpServer::isRequestAllowed('1.2.3.4', false));
    }

    public function testRemoteAllowedWithOptInAndToken(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        $this->env('TINA4_MCP_REMOTE', 'true');
        $this->assertTrue(McpServer::isRequestAllowed('1.2.3.4', true));
    }

    public function test0000BindDoesNotAdmitRemote(): void
    {
        // THE regression: a debug box bound to 0.0.0.0 must NOT auto-allow a
        // remote caller just because the configured host looks "local".
        $this->env('TINA4_DEBUG', 'true');
        $this->env('TINA4_HOST_NAME', '0.0.0.0:7145');
        $this->assertFalse(McpServer::isRequestAllowed('203.0.113.9'));
    }

    // ── End-to-end handler gate (real route dispatch) ────────────

    private function callbackFor(string $method, string $pattern): ?callable
    {
        foreach (Router::getRoutes() as $route) {
            if ($route['pattern'] === $pattern && $route['method'] === $method) {
                return $route['callback'];
            }
        }
        return null;
    }

    /** Dispatch GET /__dev/api/mcp/tools with an explicit raw peer + headers. */
    private function hitTools(string $remoteIp, array $headers = []): Response
    {
        DevAdmin::register();
        $cb = $this->callbackFor('GET', '/__dev/api/mcp/tools');
        $this->assertNotNull($cb, 'tools route must be mounted (capability on)');
        $request = Request::create('GET', '/__dev/api/mcp/tools', headers: $headers, remoteIp: $remoteIp);
        return $cb($request, new Response(true));
    }

    public function testHandlerAllowsLoopback(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        $this->assertSame(200, $this->hitTools('127.0.0.1')->getStatusCode());
    }

    public function testHandlerDeniesRemote(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        $this->assertSame(404, $this->hitTools('8.8.8.8')->getStatusCode());
    }

    public function testHandlerAllowsRemoteWithBearerToken(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        $this->env('TINA4_MCP_REMOTE', 'true');
        $this->env('TINA4_MCP_TOKEN', 's3cr3t-token');
        $ok = $this->hitTools('8.8.8.8', ['authorization' => 'Bearer s3cr3t-token']);
        $this->assertSame(200, $ok->getStatusCode());

        $bad = $this->hitTools('8.8.8.8', ['authorization' => 'Bearer wrong']);
        $this->assertSame(404, $bad->getStatusCode());
    }

    public function testHandlerIgnoresSpoofedForwardedFor(): void
    {
        // A remote caller cannot launder itself as loopback by setting
        // X-Forwarded-For: the gate reads the RAW socket peer only.
        $this->env('TINA4_DEBUG', 'true');
        $resp = $this->hitTools('8.8.8.8', ['x-forwarded-for' => '127.0.0.1']);
        $this->assertSame(404, $resp->getStatusCode());
    }

    // ── database_query read-only guard + params-slot bugfix ──────

    private function dbQuery(string $sql, string $params = '[]'): mixed
    {
        $this->env('TINA4_DATABASE_URL', 'sqlite::memory:');
        return McpServer::getDefaultServer()->callTool('database_query', ['sql' => $sql, 'params' => $params]);
    }

    public function testDatabaseQueryAllowsSelect(): void
    {
        $out = $this->dbQuery('SELECT 1 as n');
        $this->assertArrayHasKey('records', $out);
        $this->assertArrayNotHasKey('error', $out);
    }

    public function testDatabaseQueryRejectsUpdate(): void
    {
        $out = $this->dbQuery('UPDATE users SET is_admin = 1');
        $this->assertArrayHasKey('error', $out);
        $this->assertStringContainsStringIgnoringCase('read-only', $out['error']);
    }

    public function testDatabaseQueryRejectsDeleteAndDrop(): void
    {
        $this->assertArrayHasKey('error', $this->dbQuery('DELETE FROM users'));
        $this->assertArrayHasKey('error', $this->dbQuery('DROP TABLE users'));
    }

    public function testDatabaseQueryRejectsStackedStatement(): void
    {
        $out = $this->dbQuery('SELECT 1; DROP TABLE users');
        $this->assertArrayHasKey('error', $out);
        $this->assertStringContainsStringIgnoringCase('multiple statements', $out['error']);
    }

    public function testDatabaseQueryBindsParams(): void
    {
        // The old call dropped bound params (passed 10 into the params slot).
        // A parameterised SELECT must now run and bind correctly.
        $out = $this->dbQuery('SELECT ? as n', '[42]');
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame(42, (int) ($out['records'][0]['n'] ?? 0));
    }

    public function testDatabaseColumnsRejectsBadIdentifier(): void
    {
        $this->env('TINA4_DATABASE_URL', 'sqlite::memory:');
        $out = McpServer::getDefaultServer()->callTool('database_columns', ['table' => 'users; DROP TABLE users']);
        $this->assertIsArray($out);
        $this->assertArrayHasKey('error', $out);
        $this->assertStringContainsStringIgnoringCase('invalid table', $out['error']);
    }
}
