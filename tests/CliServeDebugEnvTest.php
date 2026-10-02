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

    /** GET http://127.0.0.1:$port$path; HTTP status, or 0 on a socket error. */
    private function httpStatus(int $port, string $path): int
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);
        $http_response_header = [];
        $body = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
        if ($body === false) {
            return 0;
        }
        return (int)(explode(' ', ($http_response_header[0] ?? 'HTTP/1.1 0'))[1] ?? 0);
    }

    /**
     * Poll /__dev until it settles: the first non-404 the instant it appears
     * (debug mounted it), or a settled 404 after the window (genuinely off) -
     * neither answer weakened.
     */
    private function pollDev(int $port, float $settle = 5.0): int
    {
        $deadline = microtime(true) + $settle;
        $last = 404;
        while (true) {
            $status = $this->httpStatus($port, '/__dev');
            if ($status !== 0 && $status !== 404) {
                return $status;
            }
            if ($status !== 0) {
                $last = $status;
            }
            if (microtime(true) >= $deadline) {
                return $last;
            }
            usleep(100000);
        }
    }

    private function stopProcess($process): void
    {
        if (!is_resource($process)) {
            return;
        }
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

    /**
     * Boot `tina4php serve` in $env and return the HTTP status of GET /__dev.
     *
     * Harness shape is IDENTICAL across tina4-python / tina4-php / tina4-ruby so
     * there are no cross-framework surprises:
     *
     * 1. CLEAN child env (the real cure). proc_open is given an explicit $env
     *    with every TINA4_ key filtered out, so no stale TINA4_DEBUG leaks in
     *    from the parent and the temp .env alone decides debug. This is PHP's
     *    idiomatic equivalent of Ruby's Process.spawn `unsetenv_others: true`
     *    (PHP's $env already REPLACES the environment; Ruby MERGES, which is why
     *    the flake was Ruby-only).
     * 2. IDENTITY-GUARDED readiness. Readiness waits for the child's OWN
     *    `Server: http://...:<thisport>` banner in its serve.log - the same
     *    banner all four frameworks print once they have bound THIS port -
     *    before probing /__dev. A 200/404 on the port alone proves only that
     *    SOMETHING listens, not that it is OUR child; a port a foreign server
     *    already holds never yields our banner, so the boot times out and
     *    retries on a FRESH port.
     * 3. Poll /__dev and return its status (debug-on non-404 at once, debug-off
     *    a settled 404 by outlasting the window).
     *
     * No mocks: a real process, a real socket, real .env files.
     */
    private function devStatus(?string $envFile, array $flags = [], array $realEnv = [], ?string $envLocal = null): int
    {
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $cli = realpath(__DIR__ . '/../bin/tina4php');
        $bootAttempts = 5;
        for ($attempt = 0; $attempt < $bootAttempts; $attempt++) {
            $dir = sys_get_temp_dir() . '/tina4_serve_' . uniqid();
            mkdir($dir . '/src/routes', 0755, true);
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
            $cmd = 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli)
                . ' serve --host 127.0.0.1 --port ' . $port . ' --no-browser';
            foreach ($flags as $flag) {
                $cmd .= ' ' . escapeshellarg($flag);
            }
            $log = $dir . '/serve.log';
            $ownServer = '~Server:\s+http://\S*:' . $port . '\b~';
            $process = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $dir, $env);
            $this->assertIsResource($process);
            try {
                $deadline = microtime(true) + 20;
                $owned = false;
                while (microtime(true) < $deadline) {
                    if (!proc_get_status($process)['running']) {
                        break; // child exited (could not own a contended port) -> fresh port
                    }
                    if (preg_match($ownServer, (string)@file_get_contents($log))) {
                        $owned = true;
                        break;
                    }
                    usleep(100000);
                }
                if ($owned) {
                    return $this->pollDev($port);
                }
                // The child never claimed THIS port; retry on a fresh one.
            } finally {
                $this->stopProcess($process);
            }
        }
        $this->fail("serve never printed its own Server banner after {$bootAttempts} attempts");
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
