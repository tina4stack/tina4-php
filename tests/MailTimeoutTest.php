<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tina4\Messenger;

/**
 * TINA4_MAIL_TIMEOUT — the SMTP socket bound must be reachable.
 *
 *   unit:    SECONDS, the connect AND every read/write
 *   default: 30 (Messenger::TIMEOUT_DEFAULT) — unchanged
 *   order:   constructor $timeout > TINA4_MAIL_TIMEOUT > the default
 *   garbage: warn and use the default; 0 and negative are garbage too
 *
 * The bound used to be a hard-coded 30 with no parameter, no env, no setter,
 * and `private` so not even a subclass could reach it. A relay that accepts the
 * connection and then says nothing held every send() — and the request behind
 * it — for the full 30s, and nothing an application could do shortened it.
 *
 * NO MOCKS. The peer is the OS TCP stack: a REAL listening socket whose owner
 * never accept()s it, so the kernel completes the handshake from the listen
 * backlog and the SMTP greeting never arrives. That is the wedged-relay shape;
 * a closed port would give an instant ECONNREFUSED and exercise no timeout.
 *
 * Each send runs in its OWN process (tests/fixtures/mailTimeoutProbe.php),
 * because the defect is a wait nothing can shorten: in-process, a regression
 * would hold the suite for 30s rather than fail it. The parent caps every probe
 * and reaps it.
 */
final class MailTimeoutTest extends TestCase
{
    private const VAR = 'TINA4_MAIL_TIMEOUT';

    /**
     * Seconds a probe is given before it is called unbounded and killed: ten
     * times the bound, for a loaded CI host, and still well under the 30s default.
     */
    private const PROBE_CAP = 20.0;

    /** What every probe is told to wait. */
    private const BOUND = 2;

    /** @var resource|null Real listening socket that is never accept()ed. */
    private $blackHoleServer = null;

    /** @var resource|null Real listening socket whose accept queue is full, so new SYNs are dropped. */
    private $fullBacklogServer = null;

    /** @var array<int, resource> Connections that fill that queue. */
    private array $backlogFillers = [];

    /** @var array<int, array{proc: resource, pipes: array}> Children to reap. */
    private array $children = [];

    protected function setUp(): void
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorString);
        if ($server === false) {
            $this->fail("could not open the black-hole listener: {$errorString}");
        }
        $this->blackHoleServer = $server;
        $this->clearVar();
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            $this->reap($child);
        }
        $this->children = [];
        foreach ($this->backlogFillers as $filler) {
            if (is_resource($filler)) {
                fclose($filler);
            }
        }
        $this->backlogFillers = [];
        if (is_resource($this->fullBacklogServer)) {
            fclose($this->fullBacklogServer);
        }
        $this->fullBacklogServer = null;
        if (is_resource($this->blackHoleServer)) {
            fclose($this->blackHoleServer);
        }
        $this->blackHoleServer = null;
        $this->clearVar();
    }

    private function clearVar(): void
    {
        putenv(self::VAR);
        unset($_ENV[self::VAR], $_SERVER[self::VAR]);
    }

    private function setVar(string $value): void
    {
        $_ENV[self::VAR] = $value;
        putenv(self::VAR . '=' . $value);
    }

    /** The port of the real socket that accepts and never replies. */
    private function blackHolePort(): int
    {
        $name = (string) stream_socket_get_name($this->blackHoleServer, false);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /**
     * A port whose handshake NEVER completes: a real listener that is never
     * accept()ed, with a backlog of 1 that is filled until the kernel starts
     * dropping new SYNs. connect() itself then hangs, which is the half of the
     * bound the black hole cannot reach (there the handshake succeeds and only
     * the read waits).
     */
    private function unconnectablePort(): int
    {
        $server = @stream_socket_server(
            'tcp://127.0.0.1:0',
            $errorNumber,
            $errorString,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            stream_context_create(['socket' => ['backlog' => 1]])
        );
        if ($server === false) {
            $this->fail("could not open the full-backlog listener: {$errorString}");
        }
        $this->fullBacklogServer = $server;
        $name = (string) stream_socket_get_name($server, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        // Fill the accept queue; the first connect that WAITS out its second
        // proves it is full. One that fails at once (refused, unreachable) proves
        // nothing, and the connect test would then pass without any hang to bound.
        for ($i = 0; $i < 8; $i++) {
            $startedAt = microtime(true);
            $filler = @stream_socket_client("tcp://127.0.0.1:{$port}", $en, $es, 1);
            if ($filler === false) {
                if (microtime(true) - $startedAt >= 0.9) {
                    return $port;
                }
                $this->markTestSkipped("a connect to the full-backlog listener failed without waiting: {$es}");
            }
            $this->backlogFillers[] = $filler;
        }
        $this->markTestSkipped('this kernel did not drop SYNs on a full accept queue');
    }

    /**
     * Start a relay that stalls INSIDE the STARTTLS handshake and return its
     * port: it greets, advertises STARTTLS, accepts the command, and goes
     * silent. A child process because the listener has to answer while the
     * probe runs; reaped in tearDown, and it ends itself after 40s regardless.
     */
    private function starttlsStallPort(): int
    {
        return $this->childServerPort('mailStarttlsStallServer.php');
    }

    /** Start a relay that accepts and hangs up at once, and return its port. */
    private function hangUpPort(): int
    {
        return $this->childServerPort('mailHangUpServer.php');
    }

    /** Start tests/fixtures/$script, which prints its port first, and return that port. */
    private function childServerPort(string $script): int
    {
        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/' . $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($proc)) {
            $this->fail("could not start {$script}");
        }
        $this->children[] = ['proc' => $proc, 'pipes' => $pipes];
        stream_set_blocking($pipes[1], false);
        $deadline = microtime(true) + 5.0;
        $port = '';
        while (microtime(true) < $deadline && !str_contains($port, "\n")) {
            $port .= (string) fgets($pipes[1]);
            usleep(20_000);
        }
        $this->assertMatchesRegularExpression('/^\d+$/', trim($port), "{$script} did not report a port");

        return (int) trim($port);
    }

    /**
     * The bound the Messenger actually resolved. Read off the private property
     * because that IS the mechanism: it is the only value the connect and the
     * read/write bound are taken from, and reading it costs no 30-second wait.
     */
    private function resolvedTimeout(Messenger $messenger): int
    {
        return (new \ReflectionProperty(Messenger::class, 'timeout'))->getValue($messenger);
    }

    private function messenger(?int $timeout = null): Messenger
    {
        $arguments = [
            'host' => '127.0.0.1',
            'port' => $this->blackHolePort(),
            'username' => 'probe@example.com',
            'password' => 'probe',
            'fromAddress' => 'probe@example.com',
            'encryption' => 'none',
        ];
        if ($timeout !== null) {
            $arguments['timeout'] = $timeout;
        }

        return new Messenger(...$arguments);
    }

    /**
     * Run one send() out of process and return [finished, elapsed, output].
     *
     * @return array{finished: bool, elapsed: float, output: string}
     */
    private function probe(?string $envValue, ?int $explicit, ?int $port = null, string $encryption = 'none'): array
    {
        $environment = getenv();
        if ($envValue === null) {
            unset($environment[self::VAR]);
        } else {
            $environment[self::VAR] = $envValue;
        }

        $command = [PHP_BINARY, __DIR__ . '/fixtures/mailTimeoutProbe.php', (string) ($port ?? $this->blackHolePort())];
        // Positional: <port> <constructor-timeout, '' for none> <encryption>
        $command[] = $explicit !== null ? (string) $explicit : '';
        $command[] = $encryption;

        $pipes = [];
        $proc = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
            $environment
        );
        if (!is_resource($proc)) {
            $this->fail('could not start the mail probe');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $child = ['proc' => $proc, 'pipes' => $pipes];
        $this->children[] = $child;

        $startedAt = microtime(true);
        $deadline = $startedAt + self::PROBE_CAP;
        $output = '';
        $finished = false;
        while (microtime(true) < $deadline) {
            $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            if (proc_get_status($proc)['running'] === false) {
                $finished = true;
                break;
            }
            usleep(50_000);
        }
        $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

        return ['finished' => $finished, 'elapsed' => microtime(true) - $startedAt, 'output' => $output];
    }

    /**
     * @param array{finished: bool, elapsed: float, output: string} $result
     */
    private function assertBounded(array $result, string $label): void
    {
        $this->assertTrue(
            $result['finished'],
            "{$label}: still blocked after {$result['elapsed']}s, so the bound was never applied"
        );
        $this->assertStringContainsString(
            'success=false',
            $result['output'],
            "{$label}: the probe did not report a failed send. Got: {$result['output']}"
        );
        $this->assertMatchesRegularExpression('/ELAPSED=(\d+\.\d+)/', $result['output'], $result['output']);
        preg_match('/ELAPSED=(\d+\.\d+)/', $result['output'], $m);
        $elapsed = (float) $m[1];
        // A band, not an equality: the bound governs the wait, the process start
        // and the socket setup do not. Anything near the 30s default is the bug.
        $this->assertGreaterThan(
            self::BOUND - 1.0,
            $elapsed,
            "{$label}: finished in {$elapsed}s, sooner than the {$label} bound — it did not wait on the socket at all"
        );
        $this->assertLessThan(
            self::BOUND + 5.0,
            $elapsed,
            "{$label}: waited {$elapsed}s against a bound of " . self::BOUND
        );
    }

    public function testEnvVarBoundsTheSend(): void
    {
        $this->assertBounded($this->probe((string) self::BOUND, null), self::VAR . '=' . self::BOUND);
    }

    /** The connect is bounded by the same setting, not only the greeting read. */
    public function testEnvVarBoundsTheConnectToo(): void
    {
        $this->assertBounded(
            $this->probe((string) self::BOUND, null, $this->unconnectablePort()),
            self::VAR . '=' . self::BOUND . ' against a connect that never completes'
        );
    }

    /**
     * The TLS cells. Every other test is plain text; a relay can also wedge
     * while the TLS handshake is under way, and that wait is bounded by the
     * connect and by the read/write setting respectively, so each is exercised
     * on its own path rather than assumed from the plain-text ones.
     */
    public function testEnvVarBoundsAnImplicitTlsHandshake(): void
    {
        $this->assertBounded(
            // 'ssl' is implicit TLS (usesImplicitTls()); 'tls' is STARTTLS.
            $this->probe((string) self::BOUND, null, $this->blackHolePort(), 'ssl'),
            self::VAR . '=' . self::BOUND . ' against an implicit-TLS relay that never answers the handshake'
        );
    }

    public function testEnvVarBoundsAStarttlsHandshake(): void
    {
        $this->assertBounded(
            $this->probe((string) self::BOUND, null, $this->starttlsStallPort(), 'starttls'),
            self::VAR . '=' . self::BOUND . ' against a relay that stalls after STARTTLS'
        );
    }

    /**
     * A silent relay is reported as silent. fgets() answers false for a read
     * that ran out of time and for a closed connection alike, and both used to
     * read "Lost connection", which sends the reader looking for a crash on a
     * relay that is merely slow or unreachable.
     */
    public function testATimeoutIsReportedAsATimeout(): void
    {
        $result = $this->probe((string) self::BOUND, null);

        $this->assertBounded($result, self::VAR . '=' . self::BOUND);
        $this->assertStringContainsString('Timed out after ' . self::BOUND . 's', $result['output']);
        $this->assertStringNotContainsString('Lost connection', $result['output']);
    }

    /** A relay that hangs up is still a lost connection, and is reported at once. */
    public function testAHangUpIsStillReportedAsALostConnection(): void
    {
        $result = $this->probe((string) self::BOUND, null, $this->hangUpPort());

        $this->assertTrue($result['finished'], "the probe did not finish: {$result['output']}");
        $this->assertStringContainsString('Lost connection to SMTP server', $result['output']);
        $this->assertMatchesRegularExpression('/ELAPSED=(\d+\.\d+)/', $result['output'], $result['output']);
        preg_match('/ELAPSED=(\d+\.\d+)/', $result['output'], $m);
        $this->assertLessThan(self::BOUND - 1.0, (float) $m[1], 'a hang-up waited for the bound as though it were silence');
    }

    public function testConstructorArgumentBoundsTheSend(): void
    {
        $this->assertBounded($this->probe(null, self::BOUND), 'constructor timeout: ' . self::BOUND);
    }

    public function testConstructorArgumentBeatsTheEnvVar(): void
    {
        // env says 25 (under the 30s default, so a pass cannot come from the
        // default), the constructor says 2. The constructor must win.
        $this->assertBounded($this->probe('25', self::BOUND), 'constructor beats ' . self::VAR . '=25');
    }

    public function testUnsetIsStillThirtySeconds(): void
    {
        $this->assertSame(
            Messenger::TIMEOUT_DEFAULT,
            $this->resolvedTimeout($this->messenger()),
            'an unset ' . self::VAR . ' must not change the long-standing default'
        );
        $this->assertSame(30, Messenger::TIMEOUT_DEFAULT);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function garbageProvider(): array
    {
        return [
            'not a number' => ['banana'],
            'fractional' => ['2.5'],
            'zero' => ['0'],
            'negative' => ['-5'],
            'blank' => ['   '],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('garbageProvider')]
    public function testGarbageFallsBackToTheDefault(string $value): void
    {
        $this->setVar($value);
        $this->assertSame(
            Messenger::TIMEOUT_DEFAULT,
            $this->resolvedTimeout($this->messenger()),
            self::VAR . "='{$value}' must fall back to the default, never be guessed at"
        );
    }

    public function testEnvVarIsRead(): void
    {
        $this->setVar('7');
        $this->assertSame(7, $this->resolvedTimeout($this->messenger()));
    }

    public function testConstructorRejectsAnImpossibleBound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->messenger(0);
    }

    /** @param array{proc: resource, pipes: array} $child */
    private function reap(array $child): void
    {
        foreach ($child['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($child['proc'])) {
            if (proc_get_status($child['proc'])['running'] === true) {
                proc_terminate($child['proc'], 9); // SIGKILL; the constant needs ext-pcntl
            }
            proc_close($child['proc']);
        }
    }
}
