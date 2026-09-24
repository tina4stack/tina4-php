<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 *
 * The Redis WebSocket backplane against a PASSWORD-protected Redis.
 *
 * Bug: RedisBackplane read only host and port from TINA4_WS_BACKPLANE_URL and
 * never sent AUTH, so against a Redis with requirepass the first PING was
 * answered with NOAUTH and the backplane silently degraded to local-only.
 *
 * Security: the password in TINA4_WS_BACKPLANE_URL must never reach a log line
 * or an exception message. Every case runs the backplane in a REAL child PHP
 * process against the REAL password Redis named by TINA4_TEST_REDIS_AUTH_URL,
 * and asserts on the child's REAL stdout, stderr and log files - the bytes the
 * framework's own logger wrote. No double of any kind.
 */

use PHPUnit\Framework\TestCase;

final class WebSocketBackplaneAuthTest extends TestCase
{
    private string $workDirectory = '';

    private static function authUrl(): string
    {
        return (string)(getenv('TINA4_TEST_REDIS_AUTH_URL') ?: '');
    }

    /** Skip, or FAIL under TINA4_REQUIRE_SERVICES. A silent skip is not proof. */
    private function requirePasswordRedis(): array
    {
        $url = self::authUrl();
        $parts = $url === '' ? false : parse_url($url);
        if (!is_array($parts) || ($parts['pass'] ?? '') === '') {
            $reason = 'TINA4_TEST_REDIS_AUTH_URL (redis://:password@host:port/db) is not set';
            if (getenv('TINA4_REQUIRE_SERVICES')) {
                self::fail("TINA4_REQUIRE_SERVICES is set but {$reason}");
            }
            self::markTestSkipped($reason);
        }
        $socket = @fsockopen($parts['host'], (int)($parts['port'] ?? 6379), $errorNumber, $errorText, 2);
        if (!$socket) {
            $reason = "password Redis not reachable at {$parts['host']}:" . ($parts['port'] ?? 6379);
            if (getenv('TINA4_REQUIRE_SERVICES')) {
                self::fail("TINA4_REQUIRE_SERVICES is set but {$reason}");
            }
            self::markTestSkipped($reason);
        }
        fclose($socket);
        return $parts;
    }

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir() . '/tina4-backplane-auth-' . bin2hex(random_bytes(6));
        mkdir($this->workDirectory . '/logs', 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->workDirectory !== '' && is_dir($this->workDirectory)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->workDirectory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->workDirectory);
        }
    }

    /**
     * Run two real backplane managers in a child PHP process: A publishes, B
     * must relay it. Returns [result array, every byte the logger wrote].
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function runChild(string $backplaneUrl): array
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $resultFile = $this->workDirectory . '/result.json';
        $script = $this->workDirectory . '/child.php';
        file_put_contents($script, <<<'PHP'
<?php
require getenv('CHILD_AUTOLOAD');
$received = null;
$managerA = new \Tina4\WebSocketBackplaneManager(function () {});
$managerB = new \Tina4\WebSocketBackplaneManager(
    function ($kind, $room, $path, $exclude, $message) use (&$received) { $received = $message; }
);
$managerA->ensure();
$managerB->ensure();
// Let B's SUBSCRIBE land on the server before A publishes.
$deadline = microtime(true) + 1.0;
while (microtime(true) < $deadline) { $managerB->poll(); usleep(20000); }
$managerA->publish('all', 'hello-over-password-redis');
$deadline = microtime(true) + 5.0;
while ($received === null && microtime(true) < $deadline) { $managerB->poll(); usleep(20000); }
file_put_contents(getenv('CHILD_RESULT'), json_encode([
    'activeA' => $managerA->isActive(),
    'activeB' => $managerB->isActive(),
    'received' => $received,
]));
$managerA->close();
$managerB->close();
PHP);

        $environment = getenv();
        foreach (array_keys($environment) as $name) {
            if (str_starts_with($name, 'TINA4_LOG') || str_starts_with($name, 'TINA4_WS_')) {
                unset($environment[$name]);
            }
        }
        $environment['CHILD_AUTOLOAD'] = $autoload;
        $environment['CHILD_RESULT'] = $resultFile;
        $environment['TINA4_WS_BACKPLANE'] = 'redis';
        $environment['TINA4_WS_BACKPLANE_URL'] = $backplaneUrl;
        $environment['TINA4_LOG_LEVEL'] = 'DEBUG';
        $environment['TINA4_LOG_FILE_LEVEL'] = 'DEBUG';
        $environment['TINA4_LOG_OUTPUT'] = 'both';
        $environment['TINA4_LOG_DIR'] = $this->workDirectory . '/logs';
        $environment['TINA4_NO_BROWSER'] = 'true';

        $process = proc_open(
            [PHP_BINARY, $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->workDirectory,
            $environment
        );
        $this->assertIsResource($process, 'could not start the child PHP process');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $logged = $stdout . $stderr;
        foreach (glob($this->workDirectory . '/logs/*') ?: [] as $logFile) {
            $logged .= file_get_contents($logFile);
        }
        $result = is_file($resultFile) ? json_decode((string)file_get_contents($resultFile), true) : null;
        $this->assertIsArray($result, "child produced no result. Output:\n" . $logged);
        return [$result, $logged];
    }

    public function testBackplaneAuthenticatesAgainstPasswordRedisAndRelays(): void
    {
        $parts = $this->requirePasswordRedis();

        [$result, $logged] = $this->runChild(self::authUrl());

        $this->assertTrue($result['activeA'], "backplane A did not wire against the password Redis:\n" . $logged);
        $this->assertTrue($result['activeB'], "backplane B did not wire against the password Redis:\n" . $logged);
        $this->assertSame('hello-over-password-redis', $result['received'], "publish -> subscribe round trip failed:\n" . $logged);
        $this->assertStringContainsString('WebSocket backplane active', $logged);
        $this->assertStringNotContainsString($parts['pass'], $logged, 'the Redis password reached the log output');
    }

    public function testWrongPasswordFailsLoudWithHostButNeverThePassword(): void
    {
        $parts = $this->requirePasswordRedis();
        $wrongPassword = 'wr0ng-' . bin2hex(random_bytes(4));
        $host = $parts['host'];
        $port = (int)($parts['port'] ?? 6379);
        $url = "redis://:{$wrongPassword}@{$host}:{$port}" . ($parts['path'] ?? '');

        [$result, $logged] = $this->runChild($url);

        $this->assertFalse($result['activeA'], 'a wrong password must not wire the backplane');
        $this->assertNull($result['received']);
        $this->assertStringContainsString('WebSocket backplane wiring failed', $logged);
        $this->assertStringContainsString("{$host}:{$port}", $logged, 'the failure must name the target host');
        $this->assertStringContainsString('AUTH', $logged, 'the failure must say authentication was refused');
        $this->assertStringNotContainsString($wrongPassword, $logged, 'the Redis password reached the log output');
    }
}
