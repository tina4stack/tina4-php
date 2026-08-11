<?php

/**
 * Real-process conformance for identity-checked port takeover (feature 129).
 *
 * `tina4 serve` reclaims a busy port so a restart does not fail with "address
 * already in use". Before TAKEOVER-DEC-01/02/03 both takeover paths SIGTERM'd
 * WHATEVER held the port -- a foreign dev server, a database, a stray listener --
 * with no check that the victim was a Tina4 dev server. This suite pins the fix.
 *
 * NO MOCKS. Every case starts a REAL child PHP process that binds a REAL port and
 * asserts the outcome BY PID: a foreign holder must still be running afterwards; a
 * Tina4 holder must be gone. The Tina4 holder records its identity through the
 * REAL framework \Tina4\PortTakeover::writePidfile (the same call the dev server
 * makes for itself).
 *
 * Mutation proof: in \Tina4\PortTakeover::takeOverPort replace the identity
 * filter with `$tina4Holders = $holders;` and testAForeignHolder... and
 * testTheRuntimePath... go RED (the foreign child is SIGTERM'd). Restore it and
 * they pass.
 *
 * Parity: Python tests/test_port_takeover_contract.py, Ruby
 * spec/port_takeover_contract_spec.rb, Node test/portTakeoverContract.test.ts.
 */

use PHPUnit\Framework\TestCase;
use Tina4\PortTakeover;

class PortTakeoverContractTest extends TestCase
{
    /** @var array<int, array{proc:resource, pid:int}> */
    private array $spawned = [];
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/tina4-takeover-' . getmypid() . '-' . uniqid();
        @mkdir($this->baseDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Reap EVERYTHING this suite spawned -- leave nothing on the lab.
        foreach ($this->spawned as $child) {
            if (function_exists('posix_kill')) {
                @posix_kill($child['pid'], 9);
            }
            @proc_terminate($child['proc'], 9);
            @proc_close($child['proc']);
        }
        $this->spawned = [];
        array_map('unlink', glob($this->baseDir . '/*') ?: []);
        @rmdir($this->baseDir);
    }

    private function freePort(): int
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($sock, "could not open an ephemeral port: {$errstr}");
        $name = stream_socket_get_name($sock, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($sock);
        return $port;
    }

    /** Start a real child that binds *port*; a Tina4 child also writes its PID file. */
    private function spawn(int $port, bool $tina4): int
    {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        $script = $this->baseDir . "/child-{$port}.php";
        $tina4Write = $tina4
            ? "\\Tina4\\PortTakeover::writePidfile(\$port, \$base);"
            : "";
        file_put_contents($script, <<<PHP
<?php
require '{$autoload}';
\$port = (int)\$argv[1];
\$base = \$argv[2];
\$sock = @stream_socket_server("tcp://127.0.0.1:{\$port}", \$e, \$s);
if (!\$sock) { fwrite(STDERR, "bind failed: {\$s}"); exit(2); }
{$tina4Write}
fwrite(STDOUT, "READY\\n"); fflush(STDOUT);
sleep(60);
PHP);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [PHP_BINARY, $script, (string) $port, $this->baseDir],
            $descriptors,
            $pipes
        );
        $this->assertIsResource($proc, 'proc_open failed');
        $pid = proc_get_status($proc)['pid'];
        $this->spawned[] = ['proc' => $proc, 'pid' => $pid];

        // Wait until it really holds the port (and, for a Tina4 child, has
        // written its PID file) so takeover sees a consistent state.
        $pidfile = PortTakeover::pidfilePath($port, $this->baseDir);
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $this->fail('child exited early: ' . stream_get_contents($pipes[2]));
            }
            if ($this->listening($port) && (!$tina4 || is_file($pidfile))) {
                return $pid;
            }
            usleep(50000);
        }
        $this->fail("child never bound port {$port}");
    }

    private function running(int $index): bool
    {
        return proc_get_status($this->spawned[$index]['proc'])['running'];
    }

    private function waitExit(int $index, float $timeout = 3.0): bool
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if (!$this->running($index)) {
                return true;
            }
            usleep(50000);
        }
        return !$this->running($index);
    }

    private function listening(int $port): bool
    {
        $client = @stream_socket_client("tcp://127.0.0.1:{$port}", $e, $s, 0.3);
        if (is_resource($client)) {
            fclose($client);
            return true;
        }
        return false;
    }

    // ── the four conformance cases (all real processes, asserted by PID) ────

    public function testAForeignHolderIsNotKilledAndTakeoverRefuses(): void
    {
        $port = $this->freePort();
        $this->spawn($port, tina4: false);

        $result = PortTakeover::takeOverPort($port, dev: true, noTakeover: false, baseDir: $this->baseDir);

        $this->assertSame(PortTakeover::REFUSED_FOREIGN, $result['status']);
        $this->assertStringContainsString('non-Tina4', $result['message']);
        $this->assertSame([], $result['killed']);
        // The foreign process must STILL be running -- proven by PID, not a mock.
        $this->assertTrue($this->running(0), 'takeover killed a foreign (non-Tina4) process');
        $this->assertTrue($this->listening($port), 'the foreign listener was terminated');
    }

    public function testATina4DevServerHolderIsReclaimed(): void
    {
        $port = $this->freePort();
        $pid = $this->spawn($port, tina4: true);

        $result = PortTakeover::takeOverPort($port, dev: true, noTakeover: false, baseDir: $this->baseDir);

        $this->assertSame(PortTakeover::KILLED, $result['status']);
        $this->assertSame([$pid], $result['killed']);
        $this->assertTrue($this->waitExit(0), 'the Tina4 dev server was not reclaimed');
    }

    public function testOptOutRefusesToKillTheHolder(): void
    {
        $port = $this->freePort();
        $this->spawn($port, tina4: true);

        $result = PortTakeover::takeOverPort($port, dev: true, noTakeover: true, baseDir: $this->baseDir);

        $this->assertSame(PortTakeover::REFUSED_OPTOUT, $result['status']);
        $this->assertSame([], $result['killed']);
        $this->assertTrue($this->running(0), 'opt-out still killed the holder');
    }

    public function testProductionModeRefusesToKillTheHolder(): void
    {
        $port = $this->freePort();
        $this->spawn($port, tina4: true);

        $result = PortTakeover::takeOverPort($port, dev: false, noTakeover: false, baseDir: $this->baseDir);

        $this->assertSame(PortTakeover::REFUSED_PROD, $result['status']);
        $this->assertSame([], $result['killed']);
        $this->assertTrue($this->running(0), 'production bind killed a port holder');
    }

    public function testTheRuntimePathAlsoSparesAForeignHolder(): void
    {
        // The runtime bind-failure fallback (Server::freePort) runs the SAME
        // identity gate (DEC-02): a foreign holder makes it throw and stay alive.
        $port = $this->freePort();
        $this->spawn($port, tina4: false);

        // Force dev mode + takeover-on deterministically at DotEnv's
        // highest-priority store, so the runtime path reaches the IDENTITY gate
        // (a foreign holder -> "non-Tina4"), not merely the dev/opt-out gate.
        $store = new \ReflectionProperty(\Tina4\DotEnv::class, 'variables');
        $snapshot = $store->getValue();
        $store->setValue(null, array_merge((array) $snapshot, [
            'TINA4_DEBUG' => 'true',
            'TINA4_NO_TAKEOVER' => 'false',
        ]));
        try {
            $server = new \Tina4\Server('127.0.0.1', $port);
            $method = new \ReflectionMethod(\Tina4\Server::class, 'freePort');
            $threw = false;
            try {
                $method->invoke($server, $port);
            } catch (\RuntimeException $e) {
                $threw = true;
                $this->assertStringContainsString('non-Tina4', $e->getMessage());
            }
            $this->assertTrue($threw, 'the runtime path did not refuse a foreign holder');
            $this->assertTrue($this->running(0), 'the runtime path killed a foreign process');
        } finally {
            $store->setValue(null, $snapshot);
        }
    }
}
