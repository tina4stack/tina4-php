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
use Tina4\Router;

/**
 * MCP enable-gate semantics after the 3.13.40 capability / per-request split
 * (Python master parity, tina4_python/mcp/__init__.py is_enabled()):
 *
 *   isEnabled() is a pure CAPABILITY gate, host-INDEPENDENT:
 *     explicit = env(TINA4_MCP)
 *     if explicit set and non-empty: return truthy(explicit)   # sysadmin override
 *     return truthy(TINA4_DEBUG)                                # any host
 *
 * The OLD model gated route MOUNTING on a configured-host "localhost" check,
 * which treated a 0.0.0.0 bind as local and then ran the handlers with NO
 * per-request check — a remote unauthenticated caller could reach the DB /
 * file tools. The fix splits the gate in two: isEnabled() decides whether MCP
 * is a capability of the deployment (mount the routes), and
 * isRequestAllowed() decides whether a given CALLER may use it (loopback
 * always; remote only with TINA4_MCP_REMOTE + a valid token). The raw socket
 * peer drives that decision, never X-Forwarded-For.
 *
 * This file locks the CAPABILITY predicate and that the /__dev/mcp routes
 * mount on it. The per-request authorisation is locked in McpSecurityTest.
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

    // ── Capability predicate matrix ──────────────────────────────

    public function testNoDebugIsOff(): void
    {
        // No TINA4_MCP, no TINA4_DEBUG → capability off (even on localhost).
        $this->env('TINA4_HOST_NAME', 'localhost:7146');
        $this->assertFalse(McpServer::isEnabled());
    }

    public function testDebugIsOnRegardlessOfHost(): void
    {
        // Capability is host-independent: debug on a PUBLIC host still has the
        // capability. Remote callers are stopped at request time, not here.
        $this->env('TINA4_DEBUG', 'true');
        $this->env('TINA4_HOST_NAME', 'myserver.example.com:7146');
        $this->assertTrue(McpServer::isEnabled());

        // ...and on localhost.
        $this->env('TINA4_HOST_NAME', 'localhost:7146');
        $this->assertTrue(McpServer::isEnabled());
    }

    public function testExplicitTrueWinsOnAnyHostWithoutDebug(): void
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

    public function testHostNameNoLongerFlipsTheGate(): void
    {
        // Regression: a configured 0.0.0.0 host must NOT change the capability
        // gate. Old code routed isEnabled() through isLocalhost(), which
        // treated 0.0.0.0 as local. isEnabled() now ignores the host entirely.
        $this->env('TINA4_DEBUG', 'true');
        $this->env('TINA4_HOST_NAME', '0.0.0.0:7145');
        $this->assertTrue(McpServer::isEnabled());
    }

    // ── Route registration is gated on the CAPABILITY predicate ──

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

    public function testRoutesMountedWhenExplicitlyEnabled(): void
    {
        $this->env('TINA4_MCP', 'true');
        $this->env('TINA4_HOST_NAME', 'myserver.example.com:7146');
        DevAdmin::register();
        $this->assertTrue($this->mcpRoutesMounted(), 'MCP routes must mount when enabled');
    }

    public function testRoutesMountedOnDebugPublicHost(): void
    {
        // New behaviour: routes MOUNT on any debug host (capability). The
        // per-request gate (McpSecurityTest) is what 404s a remote caller —
        // mounting is no longer the security boundary.
        $this->env('TINA4_DEBUG', 'true');
        $this->env('TINA4_HOST_NAME', 'myserver.example.com:7146');
        DevAdmin::register();
        $this->assertTrue($this->mcpRoutesMounted(), 'MCP routes mount on any debug host');
    }

    public function testRoutesAbsentWhenCapabilityOff(): void
    {
        // No debug, no explicit MCP → capability off → routes never mount.
        $this->env('TINA4_HOST_NAME', 'localhost:7146');
        DevAdmin::register();
        $patterns = array_map(
            fn($r) => $r['method'] . ' ' . $r['pattern'],
            Router::getRoutes()
        );
        $this->assertNotContains('POST /__dev/mcp', $patterns);
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
