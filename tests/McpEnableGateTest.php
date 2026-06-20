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
use Tina4\DotEnv;
use Tina4\ErrorTracker;
use Tina4\McpServer;
use Tina4\Router;

/**
 * Canonical MCP enable-gate semantics (Python master parity,
 * tina4_python/mcp/__init__.py is_enabled()):
 *
 *   explicit = env(TINA4_MCP)
 *   if explicit set and non-empty: return truthy(explicit)   # any host
 *   if not truthy(TINA4_DEBUG): return false
 *   return isLocalhost() OR truthy(TINA4_MCP_REMOTE)          # dev = localhost-only
 *
 * WHY: the MCP dev tools expose powerful ops (DB query, file read/WRITE,
 * route list). On a non-localhost TINA4_DEBUG=true deployment they must
 * NOT auto-expose; TINA4_MCP_REMOTE is the documented opt-in escape hatch
 * and explicit TINA4_MCP is the sysadmin override on any host.
 *
 * Locks both the predicate (McpServer::isEnabled()) AND that the actual
 * /__dev/mcp route registration is gated on it.
 */
class McpEnableGateTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $savedEnv = [];

    private const KEYS = ['TINA4_MCP', 'TINA4_DEBUG', 'TINA4_MCP_REMOTE', 'TINA4_HOST_NAME'];

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

    /** Set an env var so DotEnv::getEnv sees it (covers $_ENV + getenv). */
    private function env(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
    }

    // ── Predicate matrix ─────────────────────────────────────────

    public function testNoDebugIsOff(): void
    {
        // No TINA4_MCP, no TINA4_DEBUG → off (even on localhost).
        $this->env('TINA4_HOST_NAME', 'localhost:7146');
        $this->assertFalse(McpServer::isEnabled());
    }

    public function testDebugLocalhostIsOn(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        $this->env('TINA4_HOST_NAME', 'localhost:7146');
        $this->assertTrue(McpServer::isEnabled());
    }

    public function testDebugNonLocalhostNoRemoteIsOff(): void
    {
        // The core security gap: debug on a public host must NOT auto-expose.
        $this->env('TINA4_DEBUG', 'true');
        $this->env('TINA4_HOST_NAME', 'myserver.example.com:7146');
        $this->assertFalse(McpServer::isEnabled());
    }

    public function testDebugNonLocalhostWithRemoteIsOn(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        $this->env('TINA4_HOST_NAME', 'myserver.example.com:7146');
        $this->env('TINA4_MCP_REMOTE', 'true');
        $this->assertTrue(McpServer::isEnabled());
    }

    public function testExplicitTrueOnNonLocalhostIsOn(): void
    {
        // Explicit TINA4_MCP=true wins on any host — even without debug.
        $this->env('TINA4_MCP', 'true');
        $this->env('TINA4_HOST_NAME', 'myserver.example.com:7146');
        $this->assertTrue(McpServer::isEnabled());
    }

    public function testExplicitFalseIsOff(): void
    {
        // Explicit off wins even with debug + localhost.
        $this->env('TINA4_MCP', 'false');
        $this->env('TINA4_DEBUG', 'true');
        $this->env('TINA4_HOST_NAME', 'localhost:7146');
        $this->assertFalse(McpServer::isEnabled());
    }

    // ── Route registration is gated on the predicate ─────────────

    private function mcpRoutesMounted(): bool
    {
        $patterns = array_map(
            fn($r) => $r['method'] . ' ' . $r['pattern'],
            Router::getRoutes()
        );
        return in_array('POST /__dev/mcp', $patterns, true)
            && in_array('POST /__dev/mcp/message', $patterns, true)
            && in_array('GET /__dev/mcp/sse', $patterns, true);
    }

    public function testRoutesMountedWhenEnabled(): void
    {
        $this->env('TINA4_MCP', 'true');
        $this->env('TINA4_HOST_NAME', 'myserver.example.com:7146');
        DevAdmin::register();
        $this->assertTrue($this->mcpRoutesMounted(), 'MCP routes must mount when enabled');
    }

    public function testRoutesAbsentOnDebugNonLocalhostWithoutRemote(): void
    {
        // The fix in action: register() runs (dev), but the gate keeps the
        // MCP routes off a public debug host → dispatcher 404s on them.
        $this->env('TINA4_DEBUG', 'true');
        $this->env('TINA4_HOST_NAME', 'myserver.example.com:7146');
        DevAdmin::register();
        $patterns = array_map(
            fn($r) => $r['method'] . ' ' . $r['pattern'],
            Router::getRoutes()
        );
        $this->assertNotContains('POST /__dev/mcp', $patterns);
        $this->assertNotContains('POST /__dev/mcp/message', $patterns);
        $this->assertNotContains('GET /__dev/mcp/sse', $patterns);
        // The REST shim is part of the same gated block and must also be off.
        $this->assertNotContains('GET /__dev/api/mcp/tools', $patterns);
    }

    public function testRoutesMountedOnDebugLocalhost(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        $this->env('TINA4_HOST_NAME', 'localhost:7146');
        DevAdmin::register();
        $this->assertTrue($this->mcpRoutesMounted(), 'MCP routes must mount on debug+localhost');
    }
}
