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

    /** Temp-file SQLite DB backing the /call write-witness; removed in tearDown(). */
    private ?string $probeDbFile = null;

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
        if ($this->probeDbFile !== null) {
            @unlink($this->probeDbFile);
            $this->probeDbFile = null;
        }
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

    // ── /call INVOCATION shim gate (MCP-02 regression) ───────────
    //
    // The handler tests above prove the tools-LIST route (GET
    // /__dev/api/mcp/tools) is gated. These drive the surface that actually
    // RUNS a tool: the POST /__dev/api/mcp/call INVOCATION shim mounted by
    // DevAdmin::register(). That surface-specific gap — the LIST route being
    // gated while the CALL shim was audited only via the gate helper — is
    // exactly what let the Python hole ship (a remote unauth caller ran
    // database_execute / file_write on a TINA4_DEBUG 0.0.0.0 box). PHP already
    // gates /call via mcpRequestAllowed(); this locks that in so it can never
    // regress, with a REAL write-witness rather than a status-only check:
    // a temp-file SQLite DB whose probe table is re-counted on a FRESH
    // Database::fromEnv() connection after the call. A denied call must leave
    // the row count unchanged; an authorised call must increment it.

    /**
     * Bind a REAL temp-file SQLite DB with an empty `probe` table and point
     * TINA4_DATABASE_URL at it (the URL database_execute resolves via
     * Database::fromEnv()). A FILE, not sqlite::memory:, is required: the
     * witness reopens the DB on a fresh connection after the call, and an
     * in-memory DB is per-connection so the inserted row would be invisible.
     */
    private function bindProbeDatabase(): void
    {
        $this->probeDbFile = tempnam(sys_get_temp_dir(), 'tina4_mcp_call_') . '.db';
        @unlink($this->probeDbFile);
        // One-slash absolute form => absolute path. ('sqlite://' . $abs would be
        // three slashes = RELATIVE to CWD — the documented SQLite URL footgun.)
        $this->env('TINA4_DATABASE_URL', 'sqlite:' . $this->probeDbFile);
        $db = \Tina4\Database\Database::fromEnv();
        $db->execute('CREATE TABLE probe (id INTEGER PRIMARY KEY AUTOINCREMENT, note TEXT)');
        $db->commit();
    }

    /** Count `probe` rows on a FRESH connection — the real write-witness. */
    private function probeRowCount(): int
    {
        $result = \Tina4\Database\Database::fromEnv()->fetch('SELECT COUNT(*) AS n FROM probe', []);
        $row = $result->records[0] ?? [];
        return (int) ($row['n'] ?? $row['N'] ?? -1);
    }

    /**
     * Dispatch POST /__dev/api/mcp/call — the ACTUAL invocation handler mounted
     * by DevAdmin::register() — with an explicit raw peer + headers and a JSON
     * body invoking database_execute with $sql (a real remote client's wire shape).
     */
    private function hitCall(string $remoteIp, string $sql, array $headers = []): Response
    {
        DevAdmin::register();
        $cb = $this->callbackFor('POST', '/__dev/api/mcp/call');
        $this->assertNotNull($cb, 'call route must be mounted (capability on)');
        $body = json_encode(['name' => 'database_execute', 'arguments' => ['sql' => $sql]]);
        $headers = array_merge(['content-type' => 'application/json'], $headers);
        $request = Request::create('POST', '/__dev/api/mcp/call', body: $body, headers: $headers, remoteIp: $remoteIp);
        return $cb($request, new Response(true));
    }

    public function testCallShimDeniesRemoteUnauthAndDoesNotExecute(): void
    {
        // remote 8.8.8.8, TINA4_DEBUG=true (MCP enabled), NO token -> 404 forbidden
        // AND the tool never runs (probe row NOT inserted).
        $this->env('TINA4_DEBUG', 'true');
        $this->bindProbeDatabase();
        $before = $this->probeRowCount();

        $resp = $this->hitCall('8.8.8.8', "INSERT INTO probe (note) VALUES ('remote-unauth')");

        $this->assertSame(404, $resp->getStatusCode(), 'remote unauth /call must be 404');
        $this->assertSame('MCP forbidden', $resp->getJsonBody()['error'] ?? null);
        $this->assertSame($before, $this->probeRowCount(), 'denied /call must NOT execute the tool');
    }

    public function testCallShimAllowsRemoteWithBearerTokenAndExecutes(): void
    {
        // remote 8.8.8.8 + TINA4_MCP_REMOTE=true + TINA4_MCP_TOKEN + matching
        // Authorization: Bearer -> 200 AND the row WAS inserted (tool really ran).
        $this->env('TINA4_DEBUG', 'true');
        $this->env('TINA4_MCP_REMOTE', 'true');
        $this->env('TINA4_MCP_TOKEN', 's3cr3t-token');
        $this->bindProbeDatabase();
        $before = $this->probeRowCount();

        $resp = $this->hitCall(
            '8.8.8.8',
            "INSERT INTO probe (note) VALUES ('remote-authorised')",
            ['authorization' => 'Bearer s3cr3t-token'],
        );

        $this->assertSame(200, $resp->getStatusCode(), 'authorised remote /call must be 200');
        $this->assertTrue($resp->getJsonBody()['ok'] ?? false);
        $this->assertSame($before + 1, $this->probeRowCount(), 'authorised /call must execute the tool');
    }

    public function testCallShimIgnoresSpoofedForwardedForAndDoesNotExecute(): void
    {
        // A remote caller cannot launder itself as loopback with X-Forwarded-For:
        // the /call gate reads the RAW socket peer only. No token -> 404 AND the
        // tool never runs (proves the socket peer governs, not the header).
        $this->env('TINA4_DEBUG', 'true');
        $this->bindProbeDatabase();
        $before = $this->probeRowCount();

        $resp = $this->hitCall(
            '8.8.8.8',
            "INSERT INTO probe (note) VALUES ('spoofed-xff')",
            ['x-forwarded-for' => '127.0.0.1'],
        );

        $this->assertSame(404, $resp->getStatusCode(), 'spoofed XFF must NOT bypass the /call gate');
        $this->assertSame('MCP forbidden', $resp->getJsonBody()['error'] ?? null);
        $this->assertSame($before, $this->probeRowCount(), 'spoofed XFF /call must NOT execute the tool');
    }
}
