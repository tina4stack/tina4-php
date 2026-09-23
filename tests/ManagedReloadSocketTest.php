<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * A managed run must still SERVE the reload socket it broadcasts to.
 *
 * `tina4 serve` always launches the framework with `--managed` (the Rust CLI
 * owns file watching, SCSS compilation and change detection). Server::start()
 * used one flag for two unrelated things: it stood the internal file watcher
 * down AND skipped registering the `/__dev_reload` WebSocket route. The route
 * is not part of the watcher — it is the delivery channel the CLI's own
 * POST /__dev/api/reload broadcasts on (DevAdmin::registerRoutes ->
 * Server::broadcastWebSocket($payload, '/__dev_reload')). Disabling it under
 * --managed left that broadcast with nowhere to go on every supported run,
 * while the toolbar and the /__dev dashboard bundle kept dialling the socket
 * and reconnecting on every close, for ever.
 *
 * TINA4_NO_RELOAD=true is the opposite case and must keep working: the
 * developer asked for no live reload, so the socket stays unregistered.
 *
 * NO MOCKS: a real `php dual_port_server.php <port> [--managed]` child (the
 * same App::run() -> Server::start() path `tina4php serve` takes), a real TCP
 * socket, and a real raw RFC 6455 upgrade handshake. `--managed` is passed as
 * a real argv element because that is exactly how Server::start() detects it.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Server;

class ManagedReloadSocketTest extends TestCase
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

    // ── the reported defect ──────────────────────────────────────────────────

    public function testAManagedRunServesTheReloadSocketItBroadcastsTo(): void
    {
        $port = $this->spawnServer(managed: true);

        $statusLine = $this->wsUpgradeStatusLine('127.0.0.1', $port, '/__dev_reload');
        $this->assertStringContainsString(
            '101',
            $statusLine,
            'a --managed run must serve /__dev_reload: the CLI POSTs /__dev/api/reload '
            . "and the handler broadcasts on that socket. Got: {$statusLine}"
        );
    }

    public function testAManagedReloadTriggerReachesABrowserOnTheSocket(): void
    {
        $port = $this->spawnServer(managed: true);

        // A real browser: the toolbar and the /__dev bundle both hold this
        // socket open for the life of the page.
        $browser = $this->openReloadSocket('127.0.0.1', $port);

        try {
            // A real Rust CLI: this is the request `tina4 serve` sends the
            // instant it sees a file change (src/watcher.rs).
            $status = $this->httpPostJson(
                '127.0.0.1',
                $port,
                '/__dev/api/reload',
                ['type' => 'reload', 'file' => 'src/templates/index.twig']
            );
            $this->assertStringContainsString('200', $status, "the CLI's reload trigger must be accepted: {$status}");

            $frame = $this->readTextFrame($browser, 5.0);
            $this->assertNotSame('', $frame, 'the reload trigger must arrive on the socket, not only in the mtime poll');

            $payload = json_decode($frame, true);
            $this->assertIsArray($payload, "the frame must be the {type, file, mtime} payload: {$frame}");
            $this->assertSame('reload', $payload['type'] ?? null, $frame);
            $this->assertSame('src/templates/index.twig', $payload['file'] ?? null, $frame);
        } finally {
            fclose($browser);
        }
    }

    // ── the control: nothing about an unmanaged debug run changes ────────────

    public function testAnUnmanagedDebugRunServesTheReloadSocket(): void
    {
        $port = $this->spawnServer(managed: false);

        $statusLine = $this->wsUpgradeStatusLine('127.0.0.1', $port, '/__dev_reload');
        $this->assertStringContainsString(
            '101',
            $statusLine,
            "an unmanaged debug run must serve /__dev_reload: {$statusLine}"
        );
    }

    // ── the opt-out must still opt out ───────────────────────────────────────

    public function testNoReloadEnvRefusesTheReloadSocket(): void
    {
        $port = $this->spawnServer(managed: false, noReloadEnv: true);

        $statusLine = $this->wsUpgradeStatusLine('127.0.0.1', $port, '/__dev_reload');
        $this->assertStringContainsString(
            '404',
            $statusLine,
            "TINA4_NO_RELOAD=true must leave /__dev_reload unregistered: {$statusLine}"
        );
        $this->assertStringNotContainsString('101', $statusLine, $statusLine);
    }

    public function testNoReloadEnvRefusesTheReloadSocketUnderManagedToo(): void
    {
        $port = $this->spawnServer(managed: true, noReloadEnv: true);

        $statusLine = $this->wsUpgradeStatusLine('127.0.0.1', $port, '/__dev_reload');
        $this->assertStringContainsString(
            '404',
            $statusLine,
            'TINA4_NO_RELOAD=true is the developer opting out; --managed must not '
            . "turn the socket back on. Got: {$statusLine}"
        );
        $this->assertStringNotContainsString('101', $statusLine, $statusLine);
    }

    public function testANonDebugRunRefusesTheReloadSocket(): void
    {
        $port = $this->spawnServer(managed: true, debug: false);

        $statusLine = $this->wsUpgradeStatusLine('127.0.0.1', $port, '/__dev_reload');
        $this->assertStringContainsString(
            '404',
            $statusLine,
            "live reload is a debug-mode feature; a non-debug run must not serve the socket: {$statusLine}"
        );
        $this->assertStringNotContainsString('101', $statusLine, $statusLine);
    }

    // ── the decision itself, every cell of it ────────────────────────────────

    /**
     * @return array<string, array{0: list<string>, 1: bool, 2: bool, 3: bool, 4: bool}>
     *         label => [argv, isDebug, noReloadEnv, expectSocket, expectWatcher]
     */
    public static function reloadPlanCases(): array
    {
        return [
            'debug, unmanaged: both on'          => [['tina4php', 'serve'], true,  false, true,  true],
            'debug, managed: socket on, watcher off'
                                                 => [['tina4php', 'serve', '--managed'], true, false, true, false],
            'debug, unmanaged, opted out'        => [['tina4php', 'serve'], true,  true,  false, false],
            'debug, managed, opted out'          => [['tina4php', 'serve', '--managed'], true, true, false, false],
            'no debug, unmanaged'                => [['tina4php', 'serve'], false, false, false, false],
            'no debug, managed'                  => [['tina4php', 'serve', '--managed'], false, false, false, false],
            'no debug, unmanaged, opted out'     => [['tina4php', 'serve'], false, true,  false, false],
            'no debug, managed, opted out'       => [['tina4php', 'serve', '--managed'], false, true, false, false],
        ];
    }

    /**
     * @param list<string> $argv
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('reloadPlanCases')]
    public function testReloadPlanCoversEveryCombination(
        array $argv,
        bool $isDebug,
        bool $noReloadEnv,
        bool $expectSocket,
        bool $expectWatcher
    ): void {
        $plan = Server::reloadPlan($argv, $isDebug, $noReloadEnv);

        $this->assertSame($expectSocket, $plan['socket'], 'socket');
        $this->assertSame($expectWatcher, $plan['watcher'], 'watcher');
    }

    public function testOnlyTheExactManagedFlagStandsTheWatcherDown(): void
    {
        // A host, a path or a project named "managed" is not the CLI flag.
        $plan = Server::reloadPlan(
            ['tina4php', 'serve', '--host', 'managed', '/srv/--managed/app', 'managed'],
            true,
            false
        );

        $this->assertTrue($plan['socket'], 'socket');
        $this->assertTrue($plan['watcher'], 'only a literal --managed argument stands the watcher down');
    }

    // ── real server plumbing ─────────────────────────────────────────────────

    /** Spawn the fixture on a fresh free port; returns that port. */
    private function spawnServer(bool $managed, bool $noReloadEnv = false, bool $debug = true): int
    {
        $port = FreePort::get();
        $logFile = sys_get_temp_dir() . '/tina4-managed-reload-' . $port . '-' . bin2hex(random_bytes(4)) . '.log';
        $this->logFiles[] = $logFile;

        $isWindows = DIRECTORY_SEPARATOR === '\\';

        $environment = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
            'TINA4_OVERRIDE_CLIENT' => 'true',
            'TINA4_SUPPRESS' => 'true',
            'TINA4_AUTO_MIGRATE' => 'false',
            'TINA4_DEBUG' => $debug ? 'true' : 'false',
            'TINA4_NO_AI_PORT' => 'true',
        ];
        if ($noReloadEnv) {
            $environment['TINA4_NO_RELOAD'] = 'true';
        }
        if ($isWindows) {
            // A PHP child started with a hand-built environment cannot load
            // winsock or resolve its own temp files without these, and dies
            // before it reaches App::run().
            foreach (['SystemRoot', 'SystemDrive', 'WINDIR', 'COMSPEC', 'TEMP', 'TMP'] as $name) {
                $value = getenv($name);
                if ($value !== false) {
                    $environment[$name] = $value;
                }
            }
        }

        $command = [PHP_BINARY, __DIR__ . '/fixtures/dual_port_server.php', (string)$port];
        if ($managed) {
            // Exactly how `tina4 serve` launches it, and exactly what
            // Server::start() reads back out of $_SERVER['argv'].
            $command[] = '--managed';
        }

        // Windows has no /dev/null, and proc_open fails outright on a
        // descriptor it cannot open — the whole process suite never ran
        // there before this.
        $nullDevice = $isWindows ? 'NUL' : '/dev/null';

        $process = proc_open(
            $command,
            [
                0 => ['file', $nullDevice, 'r'],
                1 => ['file', $logFile, 'a'],
                2 => ['file', $logFile, 'a'],
            ],
            $pipes,
            dirname(__DIR__),
            $environment
        );
        $this->assertIsResource($process, 'the dual_port_server.php process must start');
        $this->spawned[] = $process;

        // A REAL round trip, not a bare connect: the kernel queues a connection
        // on the listen backlog before acceptLoop() is running, so only a
        // response proves the server has finished start() and is answering.
        for ($attempt = 0; $attempt < 200; $attempt++) {
            $status = $this->httpStatus('127.0.0.1', $port, '/health');
            if ($status !== '') {
                return $port;
            }
            $state = proc_get_status($process);
            if (!$state['running']) {
                $this->fail(
                    'dual_port_server.php exited during startup; log: '
                    . (@file_get_contents($logFile) ?: '(no log)')
                );
            }
            usleep(25_000);
        }

        $this->fail("dual_port_server.php never answered on port {$port}; log: " . (@file_get_contents($logFile) ?: '(no log)'));
    }

    /** First status line of a plain GET, or '' when the connection could not be made. */
    private function httpStatus(string $host, int $port, string $path): string
    {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5.0);
        if ($socket === false) {
            return '';
        }
        fwrite($socket, "GET {$path} HTTP/1.1\r\nHost: {$host}:{$port}\r\nConnection: close\r\n\r\n");
        $raw = $this->readUntilClose($socket, 5.0);
        fclose($socket);

        return strtok($raw, "\r\n") ?: '';
    }

    /** Real RFC 6455 upgrade request; returns the response's first status line. */
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

    /** Completes a real upgrade and hands back the LIVE socket, the way a browser holds it. */
    private function openReloadSocket(string $host, int $port)
    {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5.0);
        $this->assertIsResource($socket, "could not connect to {$host}:{$port}: {$errstr} ({$errno})");

        $key = base64_encode(random_bytes(16));
        fwrite(
            $socket,
            "GET /__dev_reload HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n"
        );

        stream_set_blocking($socket, false);
        $deadline = microtime(true) + 5.0;
        $head = '';
        while (microtime(true) < $deadline && !str_contains($head, "\r\n\r\n")) {
            $chunk = @fread($socket, 4096);
            if ($chunk === false) {
                break;
            }
            $head .= $chunk;
            if ($chunk === '') {
                usleep(10_000);
            }
        }
        $this->assertStringContainsString('101', strtok($head, "\r\n") ?: '', "upgrade refused: {$head}");

        return $socket;
    }

    /** @param array<string, mixed> $body */
    private function httpPostJson(string $host, int $port, string $path, array $body): string
    {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5.0);
        $this->assertIsResource($socket, "could not connect to {$host}:{$port}: {$errstr} ({$errno})");

        $json = json_encode($body);
        fwrite(
            $socket,
            "POST {$path} HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($json) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $json
        );

        $raw = $this->readUntilClose($socket, 5.0);
        fclose($socket);

        return strtok($raw, "\r\n") ?: '';
    }

    /**
     * Payload of the next server->client text frame, or '' if none arrives in
     * time. Server frames are never masked (RFC 6455 5.1), and the reload
     * payload is a single unfragmented text frame.
     */
    private function readTextFrame($socket, float $timeoutSeconds): string
    {
        stream_set_blocking($socket, false);
        $deadline = microtime(true) + $timeoutSeconds;
        $buffer = '';

        while (microtime(true) < $deadline) {
            $chunk = @fread($socket, 65536);
            if ($chunk === false) {
                break;
            }
            $buffer .= $chunk;

            if (strlen($buffer) >= 2) {
                $length = ord($buffer[1]) & 0x7F;
                $offset = 2;
                if ($length === 126 && strlen($buffer) >= 4) {
                    $length = unpack('n', substr($buffer, 2, 2))[1];
                    $offset = 4;
                }
                if ($length < 126 || $offset === 4) {
                    if (strlen($buffer) >= $offset + $length) {
                        return substr($buffer, $offset, $length);
                    }
                }
            }
            if ($chunk === '') {
                usleep(10_000);
            }
        }

        return '';
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
                // A refusal closes; a 101 does not. Either way the status line
                // is in the first chunk, and waiting for EOF on an upgraded
                // socket would burn the whole timeout.
                break;
            }
            if (feof($socket)) {
                break;
            }
            usleep(10_000);
        }

        return $buffer;
    }
}
