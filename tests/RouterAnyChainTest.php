<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * Modifiers chained on Router::any() must reach every method any() registered.
 *
 * any() registers GET, POST, PUT, PATCH and DELETE. Chained modifiers used to
 * touch the GET route only, so ->noAuth() left the four write routes at 401 and
 * ->role() left them with no role check at all. Real Router::dispatch.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class RouterAnyChainTest extends TestCase
{
    private const SECRET = 'router-any-chain-secret-0123456789abcdef';
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['TINA4_SECRET', 'TINA4_DEBUG', 'TINA4_API_KEY', 'TINA4_CSRF'] as $key) {
            $this->savedEnv[$key] = getenv($key);
        }
        Router::clear();
        putenv('TINA4_SECRET=' . self::SECRET);
        $_ENV['TINA4_SECRET'] = self::SECRET;
        putenv('TINA4_DEBUG=false');
        putenv('TINA4_API_KEY');
        putenv('TINA4_CSRF');
        unset($_ENV['TINA4_DEBUG'], $_ENV['TINA4_API_KEY'], $_ENV['TINA4_CSRF']);
    }

    protected function tearDown(): void
    {
        Router::clear();
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }

    private function call(string $method, string $path, array $headers = []): int
    {
        return Router::dispatch(
            Request::create(method: $method, path: $path, headers: $headers, remoteIp: '127.0.0.1'),
            new Response(testing: true)
        )->getStatusCode();
    }

    private function bearer(array $payload): array
    {
        return ['Authorization' => 'Bearer ' . Auth::getToken($payload, self::SECRET, 600)];
    }

    private function handler(): callable
    {
        return fn(Request $request, Response $response) => $response(['ok' => true]);
    }

    public function testNoAuthOnAnyOpensEveryMethod(): void
    {
        Router::any('/open', $this->handler())->noAuth();

        foreach (self::METHODS as $method) {
            $this->assertSame(200, $this->call($method, '/open'), "{$method} should be public");
        }
    }

    public function testAnyWithoutNoAuthStillSecuresWrites(): void
    {
        Router::any('/closed', $this->handler());

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->assertSame(401, $this->call($method, '/closed'), "{$method} should stay secured");
        }
    }

    public function testNoAuthOnAnyDoesNotLeakToOtherPaths(): void
    {
        Router::any('/open', $this->handler())->noAuth();
        Router::any('/other', $this->handler());

        $this->assertSame(401, $this->call('POST', '/other'));
    }

    public function testSecureOnAnySecuresEveryMethod(): void
    {
        Router::any('/locked', $this->handler())->secure();

        foreach (self::METHODS as $method) {
            $this->assertSame(401, $this->call($method, '/locked'), "{$method} should require a token");
        }
    }

    public function testRoleOnAnyGatesEveryMethod(): void
    {
        Router::any('/admin', $this->handler())->role('admin');

        $user = $this->bearer(['sub' => 'u1', 'roles' => ['user']]);
        $admin = $this->bearer(['sub' => 'u2', 'roles' => ['admin']]);
        foreach (self::METHODS as $method) {
            $this->assertSame(403, $this->call($method, '/admin', $user), "{$method} must refuse a non-admin");
            $this->assertSame(200, $this->call($method, '/admin', $admin), "{$method} must admit an admin");
        }
    }

    public function testCanOnAnyGatesEveryMethod(): void
    {
        Router::any('/posts', $this->handler())->can('posts.write');

        $without = $this->bearer(['sub' => 'u1', 'permissions' => ['posts.read']]);
        foreach (self::METHODS as $method) {
            $this->assertSame(403, $this->call($method, '/posts', $without), "{$method} must refuse a caller without the permission");
        }
    }

    public function testCacheAndSwaggerAndNoCsrfOnAnyReachEveryMethod(): void
    {
        Router::any('/meta', $this->handler())->cache()->swagger(['summary' => 'S'])->noCsrf()->secure();

        foreach (self::METHODS as $method) {
            $route = Router::match($method, '/meta')['route'];
            $this->assertTrue($route['cache'], "{$method} cache flag");
            $this->assertSame('S', $route['swagger']['summary'], "{$method} swagger meta");
            $this->assertTrue($route['noCsrf'], "{$method} noCsrf flag");
            $this->assertTrue($route['secure'], "{$method} secure flag");
        }
    }

    public function testSingleMethodRouteModifiersStillTouchOnlyThatRoute(): void
    {
        Router::get('/pair', $this->handler());
        Router::post('/pair', $this->handler())->noAuth();

        $this->assertSame(200, $this->call('POST', '/pair'));
        $this->assertTrue(Router::match('POST', '/pair')['route']['noAuth']);
        $this->assertFalse(Router::match('GET', '/pair')['route']['noAuth']);
    }

    public function testModifiersAfterAnyDoNotReachAnEarlierAnyRoute(): void
    {
        Router::any('/first', $this->handler());
        Router::any('/second', $this->handler())->noAuth();

        $this->assertSame(401, $this->call('POST', '/first'));
        $this->assertSame(200, $this->call('POST', '/second'));
    }
}
