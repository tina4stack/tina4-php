<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Feature 128 — dual development/test port (the "AI port" at base+1000).
 *
 * While a developer edits code, the main dev port hot-reloads. In debug mode
 * Tina4 also opens a SECOND listener at base+1000 serving the identical app
 * with the reload signal turned off — a stable connection for an AI agent, a
 * test runner, or an MCP session. See
 * tina4-documentation/plan/v3/features/128-dual-test-port.md and
 * tina4-documentation/plan/v3/OWNER-DECISIONS.md (DUALPORT-DEC-01/02).
 *
 * DUALPORT-DEC-01 (DUALPORT-TEST-GAP): before this suite, the bound
 * base+1000 port had no real test in PHP — DualPortReloadTest covers only the
 * injection-suppression unit (DevAdmin::renderToolbar) and its docblock admits
 * the socket behaviour was "verified live via tina4 serve" only. This suite
 * closes that gap with a REAL child process, REAL sockets:
 *
 *   1. base+1000 accepts a connection and serves the SAME app (a known route
 *      — /health, auto-registered by App::__construct — returns its real
 *      body).
 *   2. a reload-WebSocket UPGRADE on base+1000 is REFUSED (404, not 101).
 *   3. TINA4_NO_AI_PORT=true leaves ONLY the base port listening.
 *   4. a BUSY base+1000 (pre-bound by this test, before the child even
 *      starts) yields "Test Port: SKIPPED" and the base port still serves —
 *      non-fatal skip, deliberately the OPPOSITE of the main port's takeover
 *      (feature 129).
 *
 * DUALPORT-DEC-02 (DUALPORT-DEFAULT-SMELL): Server::__construct's default
 * port disagreed with the documented 7145 (bin/tina4php, App::resolveBindPort)
 * — masked because every real caller passes an explicit port. Now aligned;
 * pinned here by a direct `new Server()` with no caller in the way.
 *
 * NO MOCKS: a real `php dual_port_server.php <port>` child process (the same
 * App::run() -> Server::start() path a real `tina4php serve` takes), real TCP
 * sockets, and a real raw RFC 6455 upgrade handshake.
 *
 * Case names (shared with Python/Ruby/Node —
 * tina4-documentation/plan/v3/fixtures/dual_port_contract.json):
 *   - debug_mode_opens_the_ai_port_at_base_plus_1000
 *   - the_ai_port_refuses_a_reload_websocket_upgrade
 *   - no_ai_port_env_leaves_only_the_base_port
 *   - a_busy_ai_port_is_skipped_without_failing_the_base_port
 *
 * Mutation-proved (2026-08-13, macOS + the Linux lab): temporarily changing
 * Server::start()'s gate from `$this->isDebug && !$noAiPort` to
 * `$this->isDebug` (ignoring TINA4_NO_AI_PORT) turns
 * testNoAiPortEnvLeavesOnlyTheBasePort RED — the AI port opens anyway.
 * Reverted.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Server;

class DualPortContractTest extends TestCase
{
    /** @var array<int, resource> processes spawned this test, reaped in tearDown */
    private array $spawned = [];

    /** @var list<string> log files written this test, removed in tearDown */
    private array $logFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->spawned as $proc) {
            if (is_resource($proc)) {
                @proc_terminate($proc, 9);
                @proc_close($proc);
            }
        }
        $this->spawned = [];

        foreach ($this->logFiles as $logFile) {
            @unlink($logFile);
        }
        $this->logFiles = [];
    }

    // ── DUALPORT-DEC-02: constructor default aligned to 7145 ────────────────

    public function testDefaultConstructedServerUsesPort7145(): void
    {
        $server = new Server();
        $this->assertSame(
            7145,
            $server->getPort(),
            'a direct `new Server()` must default to the documented port 7145 '
            . '(bin/tina4php, App::resolveBindPort), not a value nothing else agrees with'
        );
    }

    // ── DUALPORT-DEC-01: the four shared conformance cases ───────────────────

    public function testDebugModeOpensTheAiPortAtBasePlus1000(): void
    {
        $port = $this->spawnServer(aiPort: true);

        $response = $this->httpGet('127.0.0.1', $port + 1000, '/health');
        $this->assertStringStartsWith('200', $response['status'], "AI port /health: {$response['status']}");
        $this->assertStringContainsString(
            'tina4-php',
            $response['body'],
            "AI port must serve the SAME real app: {$response['body']}"
        );
    }

    public function testTheAiPortRefusesAReloadWebsocketUpgrade(): void
    {
        $port = $this->spawnServer(aiPort: true);

        $statusLine = $this->wsUpgradeStatusLine('127.0.0.1', $port + 1000, '/__dev_reload');
        $this->assertStringContainsString(
            '404',
            $statusLine,
            "AI port must refuse the reload WebSocket upgrade: {$statusLine}"
        );
        $this->assertStringNotContainsString(
            '101',
            $statusLine,
            "AI port must NOT upgrade the reload WebSocket: {$statusLine}"
        );
    }

    public function testNoAiPortEnvLeavesOnlyTheBasePort(): void
    {
        $port = $this->spawnServer(aiPort: false);

        $response = $this->httpGet('127.0.0.1', $port, '/health');
        $this->assertStringStartsWith('200', $response['status'], "base port /health: {$response['status']}");

        $probe = @stream_socket_client("tcp://127.0.0.1:" . ($port + 1000), $errno, $errstr, 1.0);
        $this->assertFalse(
            $probe,
            'TINA4_NO_AI_PORT=true must leave NOTHING listening on base+1000'
        );
        if (is_resource($probe)) {
            fclose($probe);
        }
    }

    public function testABusyAiPortIsSkippedWithoutFailingTheBasePort(): void
    {
        $base = FreePort::get();
        $blocker = @stream_socket_server("tcp://127.0.0.1:" . ($base + 1000), $errno, $errstr);
        $this->assertIsResource($blocker, "could not reserve base+1000 to block it: {$errstr} ({$errno})");

        try {
            $logFile = $this->spawnServerOnPort($base, aiPort: true);

            $response = $this->httpGet('127.0.0.1', $base, '/health');
            $this->assertStringStartsWith(
                '200',
                $response['status'],
                "base port must still serve when the AI port is busy: {$response['status']}"
            );

            // The bind attempt + its log line happen synchronously inside
            // Server::start(), before the event loop runs — but give the
            // (buffered) child stdout a moment to actually land on disk.
            usleep(300_000);
            $log = @file_get_contents($logFile) ?: '';
            $this->assertStringContainsString((string)($base + 1000), $log, "warning must name the busy port; log: {$log}");
            $this->assertMatchesRegularExpression(
                '/skip/i',
                $log,
                "a busy AI port must warn + skip, never fail the base port; log: {$log}"
            );
            $this->assertMatchesRegularExpression('/in use/i', $log, "log: {$log}");

            // Our own blocker is still the one holding the port — the server
            // never touched it.
            $probe = @stream_socket_client("tcp://127.0.0.1:" . ($base + 1000), $errno, $errstr, 1.0);
            $this->assertIsResource($probe, 'the pre-bound AI port must remain exactly as we left it');
            fclose($probe);
        } finally {
            fclose($blocker);
        }
    }

    // ── real server plumbing ─────────────────────────────────────────────────

    /** Spawn the fixture on a fresh free port; returns that port. */
    private function spawnServer(bool $aiPort): int
    {
        $port = FreePort::get();
        $this->spawnServerOnPort($port, $aiPort);
        return $port;
    }

    /** Spawn the fixture on a GIVEN port (needed to coordinate around base+1000); returns the log file path. */
    private function spawnServerOnPort(int $port, bool $aiPort): string
    {
        $logFile = sys_get_temp_dir() . '/tina4-dualport-' . $port . '-' . bin2hex(random_bytes(4)) . '.log';
        $this->logFiles[] = $logFile;

        $environment = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
            'TINA4_OVERRIDE_CLIENT' => 'true',
            'TINA4_SUPPRESS' => 'true',
            'TINA4_AUTO_MIGRATE' => 'false',
            'TINA4_DEBUG' => 'true',
        ];
        if (!$aiPort) {
            $environment['TINA4_NO_AI_PORT'] = 'true';
        }

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/dual_port_server.php', (string)$port],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $logFile, 'a'],
                2 => ['file', $logFile, 'a'],
            ],
            $pipes,
            dirname(__DIR__),
            $environment
        );
        $this->assertIsResource($process, 'the dual_port_server.php process must start');
        $this->spawned[] = $process;

        for ($attempt = 0; $attempt < 200; $attempt++) {
            // A REAL round trip, not a bare connect: Server::start() binds the
            // main socket, THEN attempts the AI-port bind, and only THEN enters
            // acceptLoop() (which is what actually answers a request). A bare
            // TCP connect can succeed the instant the main listen() syscall
            // returns -- the kernel queues it in the backlog whether or not
            // acceptLoop() has started -- so it can race ahead of the AI-port
            // bind attempt this test is about to probe. A response can only
            // arrive once acceptLoop() is running, which is strictly AFTER the
            // AI-port bind (success or SKIPPED) has already happened.
            $response = @$this->httpGet('127.0.0.1', $port, '/health', assertConnect: false);
            if ($response !== null && $response['status'] !== '') {
                return $logFile;
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $this->fail(
                    "dual_port_server.php exited during startup; log: "
                    . (@file_get_contents($logFile) ?: '(no log)')
                );
            }
            usleep(25_000);
        }

        $this->fail("dual_port_server.php never bound port {$port}; log: " . (@file_get_contents($logFile) ?: '(no log)'));
    }

    /**
     * @return array{status: string, body: string}|null null only when
     *   assertConnect is false and the connection genuinely could not be made
     *   (used by the readiness poll, which must retry rather than fail loudly
     *   on the very first attempt).
     */
    private function httpGet(string $host, int $port, string $path, bool $assertConnect = true): ?array
    {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5.0);
        if ($socket === false) {
            if ($assertConnect) {
                $this->assertIsResource($socket, "could not connect to {$host}:{$port}: {$errstr} ({$errno})");
            }
            return null;
        }
        fwrite($socket, "GET {$path} HTTP/1.1\r\nHost: {$host}:{$port}\r\nConnection: close\r\n\r\n");

        $raw = $this->readUntilClose($socket, 5.0);
        fclose($socket);

        [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $statusLine = strtok($head, "\r\n") ?: '';
        // "HTTP/1.1 200 OK" -> "200 OK"
        $status = trim((string)preg_replace('#^HTTP/\d\.\d\s+#', '', $statusLine));

        return ['status' => $status, 'body' => $body];
    }

    /** Real RFC 6455 upgrade request; returns the response's first status line (never completes the handshake on a refusal). */
    private function wsUpgradeStatusLine(string $host, int $port, string $path): string
    {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5.0);
        $this->assertIsResource($socket, "could not connect to {$host}:{$port}: {$errstr} ({$errno})");

        $key = base64_encode(random_bytes(16));
        fwrite(
            $socket,
            "GET {$path} HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n"
        );

        $raw = $this->readUntilClose($socket, 5.0);
        fclose($socket);

        return strtok($raw, "\r\n") ?: '';
    }

    private function readUntilClose($socket, float $timeoutSeconds): string
    {
        stream_set_blocking($socket, false);
        $deadline = microtime(true) + $timeoutSeconds;
        $buffer = '';
        while (microtime(true) < $deadline) {
            $chunk = @fread($socket, 65536);
            if ($chunk === false) {
                break;
            }
            if ($chunk !== '') {
                $buffer .= $chunk;
                continue;
            }
            if (feof($socket)) {
                break;
            }
            usleep(10_000);
        }

        return $buffer;
    }
}
