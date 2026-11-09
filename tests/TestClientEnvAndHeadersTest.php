<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

namespace Tina4;

use PHPUnit\Framework\TestCase;

/**
 * Regressions for two TestClient DX footguns (#296, #300).
 *
 * No mocks: a real .env file on disk, the real DotEnv loader the app uses, and a
 * real Response/TestResponse built from real header values.
 *
 * Mirrors: tina4-python tests/test_test_client_env_and_headers.py,
 *          tina4-ruby spec/test_client_env_and_headers_spec.rb,
 *          tina4-nodejs test/testClientEnvAndHeaders.test.ts
 */
class TestClientEnvAndHeadersTest extends TestCase
{
    private string $origCwd;
    private string|false $origSecret;
    private string|false $origEnvFile;

    protected function setUp(): void
    {
        $this->origCwd = getcwd();
        $this->origSecret = getenv('TINA4_SECRET');
        $this->origEnvFile = getenv('TINA4_ENV_FILE');
    }

    protected function tearDown(): void
    {
        chdir($this->origCwd);
        $this->restore('TINA4_SECRET', $this->origSecret);
        $this->restore('TINA4_ENV_FILE', $this->origEnvFile);
    }

    private function clear(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    private function restore(string $key, string|false $value): void
    {
        if ($value === false) {
            $this->clear($key);
            return;
        }
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
    }

    private function makeProject(string $envSecret): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tina4-tc-' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . DIRECTORY_SEPARATOR . '.env', "TINA4_SECRET={$envSecret}\n");
        return $dir;
    }

    // ───────────────────────── #296 — TestClient loads .env ─────────────────────

    public function testTestClientLoadsEnvWithoutBootingApp(): void
    {
        $secret = str_repeat('a', 64);
        $dir = $this->makeProject($secret);
        $this->clear('TINA4_SECRET');
        $this->clear('TINA4_ENV_FILE');
        chdir($dir);

        $this->assertFalse(getenv('TINA4_SECRET'), 'precondition: secret must start unset');

        new TestClient(); // construction loads .env the way App::start() does

        $this->assertSame($secret, getenv('TINA4_SECRET'), 'TestClient did not load .env (#296)');

        @unlink($dir . DIRECTORY_SEPARATOR . '.env');
        @rmdir($dir);
    }

    public function testEnvLoadIsFirstWins(): void
    {
        $real = str_repeat('r', 64);
        $dir = $this->makeProject(str_repeat('d', 64)); // a DIFFERENT value in .env
        putenv("TINA4_SECRET={$real}");
        $_ENV['TINA4_SECRET'] = $real;
        $this->clear('TINA4_ENV_FILE');
        chdir($dir);

        new TestClient();

        $this->assertSame($real, getenv('TINA4_SECRET'), 'TestClient clobbered a real env var (#296 first-wins)');

        @unlink($dir . DIRECTORY_SEPARATOR . '.env');
        @rmdir($dir);
    }

    // ─────────────────── #300 — case-insensitive response headers ───────────────

    public function testResponseHeaderLookupIsCaseInsensitive(): void
    {
        $response = new Response(testing: true);
        $response->header('Location', '/complaints');
        $tr = new TestResponse($response);

        $this->assertSame('/complaints', $tr->header('Location'));  // canonical case
        $this->assertSame('/complaints', $tr->header('location'));  // lower case
        $this->assertSame(['/complaints'], $tr->getList('Location'));
        $this->assertSame('/complaints', $tr->headers['location']); // lower-case array view unchanged
    }

    public function testResponseAbsentHeaderIsAMiss(): void
    {
        $response = new Response(testing: true);
        $response->header('Content-Type', 'text/html');
        $tr = new TestResponse($response);

        $this->assertNull($tr->header('X-Absent'));
        $this->assertSame([], $tr->getList('X-Absent'));
    }
}
