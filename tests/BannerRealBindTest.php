<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * THE STARTUP BANNER NAMES THE HOST AND PORT THE SERVER REALLY BINDS.
 *
 * Observed on the lab (Ubuntu, PHP 8.3, 2026-09-24): with TINA4_HOST set to
 * the machine's LAN address, `ss -ltnp` showed the server listening on
 * 192.168.88.99:<port> while the banner said `Server:    http://localhost:<port>`
 * - an address the server was not listening on at all. App::run() hard-coded
 * "localhost" whatever host it bound. The port was already right.
 *
 * Each test boots a REAL `php` child through App::run() (fixture
 * tests/fixtures/banner_real_bind_app.php), proves the server answers on the
 * port under test, then reads the banner from the child's own output. No mocks.
 *
 * Identical case names in the other frameworks
 * (tina4-ruby/spec/banner_real_bind_spec.rb):
 *   - banner_names_the_explicit_port_and_host
 *   - banner_names_tina4_port_and_tina4_host
 *   - explicit_port_argument_beats_tina4_port   (ADR-0041)
 */

use PHPUnit\Framework\TestCase;

class BannerRealBindTest extends TestCase
{
    /** @var array<int, resource> processes spawned this test, reaped in tearDown */
    private array $spawned = [];

    /** @var list<string> files and directories created this test */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->spawned as $process) {
            if (is_resource($process)) {
                @proc_terminate($process, 9);
                @proc_close($process);
            }
        }
        $this->spawned = [];
        foreach (array_reverse($this->cleanup) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        $this->cleanup = [];
    }

    public function testBannerNamesTheExplicitPortAndHost(): void
    {
        $port = FreePort::get();
        $log = $this->boot($port, ['PROBE_PORT' => (string) $port, 'PROBE_HOST' => '127.0.0.1']);

        $this->assertSame(["http://127.0.0.1:{$port}"], $this->serverUrls($log), $log);
    }

    public function testBannerNamesTina4PortAndTina4Host(): void
    {
        $port = FreePort::get();
        $log = $this->boot($port, ['TINA4_PORT' => (string) $port, 'TINA4_HOST' => '127.0.0.1']);

        $this->assertSame(["http://127.0.0.1:{$port}"], $this->serverUrls($log), $log);
    }

    public function testExplicitPortArgumentBeatsTina4Port(): void
    {
        $port = FreePort::get();
        $environmentPort = FreePort::get();
        $log = $this->boot($port, [
            'PROBE_PORT' => (string) $port,
            'PROBE_HOST' => '127.0.0.1',
            'TINA4_PORT' => (string) $environmentPort,
        ]);

        $this->assertSame(["http://127.0.0.1:{$port}"], $this->serverUrls($log), $log);
        $this->assertStringNotContainsString('PORT is deprecated', $log);
    }

    /** @return list<string> the URL on every `Server:` banner line */
    private function serverUrls(string $log): array
    {
        preg_match_all('/^\s*Server:\s+(\S+)/m', $log, $matches);
        return $matches[1];
    }

    /**
     * Boot the fixture and wait until it answers on $port. Returns its output.
     *
     * @param array<string, string> $overrides
     */
    private function boot(int $port, array $overrides): string
    {
        $basePath = sys_get_temp_dir() . '/tina4-banner-' . bin2hex(random_bytes(4));
        mkdir($basePath);
        $logFile = $basePath . '.log';
        $this->cleanup[] = $basePath;
        $this->cleanup[] = $logFile;

        // A clean environment: nothing inherited can move the bind or hide the banner.
        $environment = array_merge([
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
            'PROBE_BASE_PATH' => $basePath,
            'TINA4_OVERRIDE_CLIENT' => 'true',
            'TINA4_AUTO_MIGRATE' => 'false',
            'TINA4_DEBUG' => 'false',
            'TINA4_NO_AI_PORT' => 'true',
            'TINA4_NO_BROWSER' => 'true',
        ], $overrides);

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/banner_real_bind_app.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
            $pipes,
            $basePath,
            $environment
        );
        $this->assertIsResource($process, 'the banner_real_bind_app.php process must start');
        $this->spawned[] = $process;

        for ($attempt = 0; $attempt < 400; $attempt++) {
            if ($this->answers($port)) {
                // The banner is echoed before Server::start() enters its loop.
                return (string) file_get_contents($logFile);
            }
            if (!proc_get_status($process)['running']) {
                $this->fail('the app exited during startup; log: ' . (@file_get_contents($logFile) ?: '(no log)'));
            }
            usleep(25_000);
        }
        $this->fail("the app never served on port {$port}; log: " . (@file_get_contents($logFile) ?: '(no log)'));
    }

    /** A real HTTP round trip: any status line means the server is serving on $port. */
    private function answers(int $port): bool
    {
        $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errorCode, $errorMessage, 0.5);
        if ($socket === false) {
            return false;
        }
        stream_set_timeout($socket, 2);
        fwrite($socket, "GET /health HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nConnection: close\r\n\r\n");
        $statusLine = (string) fgets($socket);
        fclose($socket);
        return str_starts_with($statusLine, 'HTTP/');
    }
}
