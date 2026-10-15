<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * The dev-admin routes outside /__dev take the /__dev gate.
 *
 * POST /ai/api/chat and POST /image/v1/images/generations forward a request to
 * the developer's AI gateway and return its reply; /ai, /vision, /embed, /image
 * and /rag report the configured service URLs. They are served by the dev admin
 * but sit outside the /__dev prefix, so they must still pass the same Host,
 * same-origin and loopback-peer checks.
 *
 * REAL dispatch through Router::dispatch(). The gateway URLs point at a closed
 * local port, so a request that reaches a proxy handler answers 502 "backend
 * unreachable"; one the gate refuses answers 403 and never gets that far.
 */

require_once __DIR__ . '/../Tina4/DevAdmin.php';

use PHPUnit\Framework\TestCase;
use Tina4\DevAdmin;
use Tina4\ErrorTracker;
use Tina4\McpServer;
use Tina4\Middleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class DevAdminAiProxyGateTest extends TestCase
{
    private const KEYS = [
        'TINA4_DEBUG', 'TINA4_MCP', 'TINA4_MCP_REMOTE', 'TINA4_MCP_TOKEN', 'TINA4_API_KEY',
        'TINA4_HOST', 'TINA4_DEV_ALLOWED_PEERS', 'TINA4_AI_URL', 'TINA4_IMAGE_URL',
    ];
    private const PROXIES = ['/ai/api/chat', '/image/v1/images/generations'];
    private const PROBES = ['/ai', '/vision', '/embed', '/image', '/rag'];
    private const CLOSED = 'http://127.0.0.1:1/api/chat';

    /** @var array<string,string|false> */
    private array $savedEnv = [];

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
        $this->env('TINA4_DEBUG', 'true');
        $this->env('TINA4_AI_URL', self::CLOSED);
        $this->env('TINA4_IMAGE_URL', self::CLOSED);
        DevAdmin::register();
    }

    protected function tearDown(): void
    {
        Router::clear();
        Middleware::reset();
        McpServer::resetDefaultServer();
        ErrorTracker::reset();
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

    private function dispatch(string $method, string $path, array $headers = [], string $remoteIp = '127.0.0.1'): Response
    {
        $body = null;
        if ($method === 'POST') {
            $body = json_encode(['model' => 'm', 'prompt' => 'hi', 'messages' => [['role' => 'user', 'content' => 'hi']]]);
            $headers = array_merge(['content-type' => 'application/json'], $headers);
        }
        $request = Request::create(method: $method, path: $path, body: $body, headers: $headers, remoteIp: $remoteIp);
        return Router::dispatch($request, new Response(true));
    }

    private function assertRefused(Response $resp, string $label): void
    {
        $this->assertSame(403, $resp->getStatusCode(), "{$label} must be refused");
        $this->assertStringNotContainsString('unreachable', (string) $resp->getBody(), "{$label} reached the proxy");
        $this->assertStringNotContainsString('127.0.0.1:1', (string) $resp->getBody(), "{$label} read the gateway URL");
    }

    public function testANonLoopbackPeerCannotUseTheAiProxies(): void
    {
        foreach (self::PROXIES as $path) {
            foreach ([$path, $path . '/', '/' . $path] as $shape) {
                $this->assertRefused($this->dispatch('POST', $shape, ['sec-fetch-site' => 'same-origin'], '203.0.113.9'), "remote POST {$shape}");
            }
        }
    }

    public function testACrossOriginPageCannotUseTheAiProxies(): void
    {
        foreach (self::PROXIES as $path) {
            $this->assertRefused($this->dispatch('POST', $path, ['origin' => 'http://evil.example']), "cross-origin POST {$path}");
            $this->assertRefused($this->dispatch('POST', $path, ['sec-fetch-site' => 'cross-site']), "cross-site POST {$path}");
        }
    }

    public function testAForeignHostCannotUseTheAiProxies(): void
    {
        foreach (self::PROXIES as $path) {
            $this->assertRefused($this->dispatch('POST', $path, ['host' => 'rebind.evil.example:7145']), "rebound POST {$path}");
        }
    }

    public function testTheDashboardStillReachesTheAiProxies(): void
    {
        foreach (self::PROXIES as $path) {
            $resp = $this->dispatch('POST', $path, ['sec-fetch-site' => 'same-origin']);
            $this->assertSame(502, $resp->getStatusCode(), "dashboard POST {$path}");
            $this->assertStringContainsString('unreachable', (string) $resp->getBody());
        }
    }

    public function testServiceProbesAreRefusedToANonLoopbackPeer(): void
    {
        foreach (self::PROBES as $path) {
            $this->assertRefused($this->dispatch('GET', $path, [], '203.0.113.9'), "remote GET {$path}");
            $this->assertSame(200, $this->dispatch('GET', $path)->getStatusCode(), "loopback GET {$path}");
        }
    }
}
