<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * `tina4php serve` decides debug from the operator, never from a default
 * (ADR-0079 s3). Debug decides whether /__dev is mounted, so each case boots the
 * real CLI server in a child process on its own port and asks it for /__dev:
 * 404 means debug is off.
 *
 *   - --production turns debug off (the explicit flag beats .env, ADR-0041); it
 *     used to only pick FrankenPHP and leave TINA4_DEBUG=true from .env on.
 *   - the real environment beats .env.local; serve used to load .env.local with
 *     overwrite, so a stale local file clobbered an operator's exported value.
 *
 * Case names match the auth_token_contract.json "debug-is-explicit" invariant.
 * No mocks: a real process, a real socket, real .env files.
 */

use PHPUnit\Framework\TestCase;

class CliServeDebugEnvTest extends TestCase
{
    private const SECRET = 'cli-serve-debug-secret-0123456789abcdef';

    /** Boot `tina4php serve` in $env and return the HTTP status of GET /__dev. */
    private function devStatus(?string $envFile, array $flags = [], array $realEnv = [], ?string $envLocal = null): int
    {
        $dir = sys_get_temp_dir() . '/tina4_serve_' . uniqid();
        mkdir($dir . '/src/routes', 0755, true);
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        file_put_contents($dir . '/index.php', "<?php\nrequire_once '{$autoload}';\n\$app = new \\Tina4\\App(basePath: __DIR__);\n\$app->handle();\n");
        if ($envFile !== null) {
            file_put_contents($dir . '/.env', $envFile);
        }
        if ($envLocal !== null) {
            file_put_contents($dir . '/.env.local', $envLocal);
        }
        $port = \FreePort::get();
        $env = array_filter(getenv(), fn($key) => !str_starts_with($key, 'TINA4_'), ARRAY_FILTER_USE_KEY);
        $env = array_merge($env, [
            'TINA4_NO_BROWSER' => 'true', 'TINA4_SECRET' => self::SECRET,
            'TINA4_SERVE_FORK' => 'false', 'TINA4_NO_TAKEOVER' => 'true', 'TINA4_OVERRIDE_CLIENT' => 'true',
        ], $realEnv);
        $cli = realpath(__DIR__ . '/../bin/tina4php');
        $cmd = 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli)
            . ' serve --host 127.0.0.1 --port ' . $port . ' --no-browser';
        foreach ($flags as $flag) {
            $cmd .= ' ' . escapeshellarg($flag);
        }
        $log = $dir . '/serve.log';
        $process = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $dir, $env);
        $this->assertIsResource($process);
        try {
            $deadline = microtime(true) + 20;
            while (microtime(true) < $deadline) {
                if (!proc_get_status($process)['running']) {
                    $this->fail('serve exited early: ' . @file_get_contents($log));
                }
                $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);
                if (@file_get_contents("http://127.0.0.1:{$port}/__dev", false, $context) !== false) {
                    return (int)explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1];
                }
                usleep(100000);
            }
            $this->fail('serve never answered: ' . @file_get_contents($log));
        } finally {
            proc_terminate($process, 15);
            $wait = microtime(true) + 5;
            while (proc_get_status($process)['running'] && microtime(true) < $wait) {
                usleep(50000);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
    }

    public function testServeHonoursDebugFalseFromEnvFile(): void
    {
        $this->assertSame(404, $this->devStatus("TINA4_DEBUG=false\n"));
    }

    public function testServeHonoursDebugTrueFromEnvFile(): void
    {
        // Control: proves the /__dev probe can tell debug-on from debug-off.
        $this->assertNotSame(404, $this->devStatus("TINA4_DEBUG=true\n"));
    }

    public function testProductionFlagTurnsDebugOff(): void
    {
        $this->assertSame(404, $this->devStatus("TINA4_DEBUG=true\n", ['--production']));
    }

    public function testAMissingEnvFileDoesNotEnableDebug(): void
    {
        $this->assertSame(404, $this->devStatus(null));
    }

    public function testTheRealEnvironmentBeatsEnvLocal(): void
    {
        $this->assertSame(404, $this->devStatus(null, [], ['TINA4_DEBUG' => 'false'], "TINA4_DEBUG=true\n"));
    }
}
