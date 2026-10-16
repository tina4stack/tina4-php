<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * Only the dev dashboard's own routes skip the auth gate.
 *
 * The gate used to skip any request whose path started with the characters
 * "/__dev", so a write route of the application's own named /__developer/...,
 * /__devices or /__dev/... ran with no token, with debug on or off. A route
 * now skips the gate because DevAdmin registered it, not because of its path.
 *
 * Run: ./vendor/bin/phpunit tests/DevAdminAuthScopeTest.php
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\DevAdmin;
use Tina4\ErrorTracker;
use Tina4\McpServer;
use Tina4\Middleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class DevAdminAuthScopeTest extends TestCase
{
    private const KEYS = ['TINA4_DEBUG', 'TINA4_SECRET', 'TINA4_API_KEY', 'TINA4_LOG_LEVEL', 'TINA4_MCP'];

    /** @var array<string,string|false> */
    private array $savedEnv = [];
    private string $origCwd = '';
    private string $projectDir = '';

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
        $this->projectDir = sys_get_temp_dir() . '/tina4_devauth_' . bin2hex(random_bytes(8));
        mkdir($this->projectDir . '/src/routes', 0700, true);
        chdir($this->projectDir);
        $this->env('TINA4_SECRET', 'dev-admin-auth-scope-secret-0123456789');
        $this->env('TINA4_LOG_LEVEL', 'NONE');
    }

    protected function tearDown(): void
    {
        chdir($this->origCwd);
        Router::clear();
        Middleware::reset();
        McpServer::resetDefaultServer();
        ErrorTracker::reset();
        \Tina4\Log::reset();
        exec('rm -rf ' . escapeshellarg($this->projectDir));
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

    private function post(string $path): Response
    {
        $request = Request::create(method: 'POST', path: $path, body: '{}',
            headers: ['content-type' => 'application/json', 'sec-fetch-site' => 'same-origin'], remoteIp: '127.0.0.1');
        return Router::dispatch($request, new Response(true));
    }

    /** @return array<string, array{string, string}> */
    public static function applicationRoutes(): array
    {
        $cases = [];
        foreach (['false', 'true'] as $debug) {
            foreach (['/__developer/secret', '/__devices', '/__dev/mine'] as $path) {
                $cases["{$path} debug={$debug}"] = [$path, $debug];
            }
        }
        return $cases;
    }

    #[DataProvider('applicationRoutes')]
    public function testAnApplicationWriteRouteNeedsATokenWhateverItsPath(string $path, string $debug): void
    {
        $this->env('TINA4_DEBUG', $debug);
        if ($debug === 'true') {
            DevAdmin::register();
        }
        Router::post($path, fn($request, $response) => $response->json(['ran' => true]));

        $response = $this->post($path);

        $this->assertSame(401, $response->getStatusCode(), "{$path} ran with no token");
        $this->assertStringNotContainsString('"ran"', $response->getBody());
    }

    public function testEveryDashboardWriteRouteStillSkipsTheGate(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        DevAdmin::register();

        $routes = (new \ReflectionProperty(Router::class, 'routes'))->getValue();
        $gate = new \ReflectionMethod(Router::class, 'enforceRouteAuth');
        $checked = 0;
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            foreach ($routes[$method] ?? [] as $route) {
                $request = Request::create(method: $method, path: $route['pattern'], remoteIp: '127.0.0.1');
                $this->assertNull($gate->invoke(null, $request, new Response(true), $route),
                    "{$method} {$route['pattern']} was refused by the auth gate");
                $checked++;
            }
        }
        $this->assertGreaterThan(20, $checked, 'DevAdmin registered no write routes to check');
    }

    public function testADashboardWriteRouteAnswersWithoutAToken(): void
    {
        $this->env('TINA4_DEBUG', 'true');
        DevAdmin::register();

        $this->assertSame(200, $this->post('/__dev/api/messages/clear')->getStatusCode());
    }

    public function testARouteAddedAfterTheDashboardTakesTheNormalRules(): void
    {
        try {
            Router::devAdminRoutes(function (): void {
                Router::post('/__dev/api/inside', fn($request, $response) => $response->json(['ran' => true]));
                throw new \RuntimeException('registration failed');
            });
        } catch (\RuntimeException) {
        }
        Router::post('/__dev/api/after', fn($request, $response) => $response->json(['ran' => true]));

        $this->assertSame(200, $this->post('/__dev/api/inside')->getStatusCode());
        $this->assertSame(401, $this->post('/__dev/api/after')->getStatusCode());
    }
}
