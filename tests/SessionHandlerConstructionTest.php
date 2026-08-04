<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * SESSION CONTRACT: a handler constructor performs NO network I/O.
 *
 * ADR-0021: connection happens on FIRST USE, inside the log-loud-and-degrade
 * policy. A constructor sits OUTSIDE that policy, so anything it does cannot be
 * logged, cannot be degraded, and cannot be re-raised by TINA4_SESSION_STRICT.
 *
 * WHY THIS MATTERS, stated as the operator sees it: the one place the failure
 * policy cannot protect is the FIRST thing that runs. An unreachable backend
 * takes the app down at construction instead of degrading per-request as
 * designed - so the very scenario the policy exists for is the one it never
 * sees.
 *
 * MEASURED at this branch's HEAD, Tina4/Session/DatabaseSessionHandler.php:
 *
 *     public function __construct(array $config = [])
 *     {
 *         ...
 *         $db = Database::fromEnv();      <-- builds an adapter
 *     }
 *
 * and EVERY Tina4 adapter constructor ends with `$this->open()` - a real
 * pg_connect / mysqli_real_connect / sqlsrv_connect / ibase_connect. So
 * constructing the handler dialled the database. Against the counting listener
 * below that constructor accepted 1 connection.
 *
 * The DDL half of the same class was ALREADY correct: `ensureTable()` is called
 * from read/write/destroy/gc and guarded by $tableCreated, never from the
 * constructor. Only the CONNECT half was broken.
 *
 * HOW THIS IS PROVED WITHOUT MOCKS.
 *
 *   * A real TCP listener this test starts, which COUNTS accepted connections.
 *     Construct the handler pointed at it and the count must still be zero; do
 *     one real operation and the count must rise. That is a direct observation
 *     of network behaviour, not an assertion about code shape - a handler
 *     cannot connect without the kernel completing an accept() we can see. The
 *     listener speaks no protocol and pretends to be nothing; it is a socket,
 *     and the only thing it reports is a fact the kernel decided. No test
 *     double can answer that honestly.
 *
 *   * A real SQL engine for the DatabaseSessionHandler, which owns no socket of
 *     its own to watch when an adapter is injected. Its constructor is measured
 *     by its SIDE EFFECT instead: drop tina4_session, construct, and ask a
 *     SECOND independent connection whether the table came back.
 *
 * The negative control matters as much: deferring the connection must not break
 * the connection. A healthy backend still has to round-trip after the change,
 * or "do nothing in the constructor" has been satisfied by doing nothing at all.
 *
 * NO MOCKS. Real sockets, real Redis, real SQLite, real PostgreSQL. Where a
 * provisioned service is unreachable the test skips naming it, unless
 * TINA4_REQUIRE_SERVICES is set - then a missing service is a hard FAILURE.
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;
use Tina4\Session\DatabaseSessionHandler;
use Tina4\Session\MemcachedSessionHandler;
use Tina4\Session\MongoSessionHandler;
use Tina4\Session\RedisSessionHandler;
use Tina4\Session\ValkeySessionHandler;

/**
 * A REAL TCP server that counts the connections it accepts.
 *
 * It runs as a separate PHP process because it must accept AND HANG UP while
 * the test is blocked inside the handler's own read. A single-threaded
 * in-process accept loop cannot do that, and without the immediate hang-up the
 * Redis handler blocks on its hardcoded 30-second stream timeout.
 *
 * Each accepted connection appends ONE BYTE to a count file, so the count is
 * simply the file's size - an append is atomic, so the reader can never catch a
 * half-written number.
 */
final class CountingTcpListener
{
    /**
     * The listener body, run by `php -r`. Kept inline so this suite is one file
     * with no fixture to drift from it.
     */
    private const LISTENER_SOURCE = <<<'LISTENER'
$countFile = getenv('TINA4_ACCEPT_LOG');
$parentPid = (int)getenv('TINA4_PARENT_PID');
$server = stream_socket_server('tcp://127.0.0.1:0', $errNo, $errStr);
if ($server === false) {
    fwrite(STDOUT, "ERROR {$errStr}\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
fwrite(STDOUT, 'PORT ' . substr($name, strrpos($name, ':') + 1) . "\n");
fflush(STDOUT);
while (true) {
    // Never outlive the test that started us.
    if (function_exists('posix_getppid') && posix_getppid() !== $parentPid) {
        exit(0);
    }
    $client = @stream_socket_accept($server, 1);
    if ($client === false) {
        continue;
    }
    file_put_contents($countFile, 'x', FILE_APPEND);
    fclose($client);   // accept, count, hang up
}
LISTENER;

    /** Long enough for any background connect to actually land. */
    private const SETTLE_MICROSECONDS = 200000;

    public readonly int $port;

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $countFile;

    public function __construct()
    {
        $this->countFile = (string)tempnam(sys_get_temp_dir(), 'tina4-accepts-');
        file_put_contents($this->countFile, '');

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, '-d', 'error_reporting=0', '-r', self::LISTENER_SOURCE],
            $descriptors,
            $this->pipes,
            null,
            ['TINA4_ACCEPT_LOG' => $this->countFile, 'TINA4_PARENT_PID' => (string)getmypid()]
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('could not start the counting TCP listener');
        }
        $this->process = $process;

        $announcement = (string)fgets($this->pipes[1]);
        if (!str_starts_with($announcement, 'PORT ')) {
            $this->close();
            throw new \RuntimeException('counting TCP listener did not announce a port: ' . $announcement);
        }
        $this->port = (int)trim(substr($announcement, 5));
    }

    /**
     * How many connections the kernel has completed to this listener so far.
     */
    public function accepted(): int
    {
        clearstatcache(true, $this->countFile);
        return (int)@filesize($this->countFile);
    }

    /**
     * Give any connection a real chance to land before it is counted.
     */
    public function settle(): void
    {
        usleep(self::SETTLE_MICROSECONDS);
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
        }
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];
        if (is_resource($this->process)) {
            proc_close($this->process);
        }
        $this->process = null;
        @unlink($this->countFile);
    }
}

class SessionHandlerConstructionTest extends TestCase
{
    private ?CountingTcpListener $listener = null;

    /** @var string[] SQLite files this test created. */
    private array $temporaryFiles = [];

    /** @var array<string, string|false> Env vars saved for restoration. */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        $this->listener = new CountingTcpListener();
    }

    protected function tearDown(): void
    {
        $this->listener?->close();
        $this->listener = null;

        foreach ($this->savedEnvironment as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv("{$name}={$value}");
            }
        }
        $this->savedEnvironment = [];
        \Tina4\DotEnv::resetEnv();

        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        $this->temporaryFiles = [];
    }

    /**
     * Every handler that speaks over a socket, all pointed at one endpoint.
     *
     * @return array<string, callable(): object>
     */
    private function socketHandlers(string $host, int $port): array
    {
        return [
            'redis' => static fn() => new RedisSessionHandler(['host' => $host, 'port' => $port, 'ttl' => 60]),
            'valkey' => static fn() => new ValkeySessionHandler(['host' => $host, 'port' => $port, 'ttl' => 60]),
            'memcached' => static fn() => new MemcachedSessionHandler(['host' => $host, 'port' => $port, 'ttl' => 60]),
            'mongodb' => static fn() => new MongoSessionHandler(['url' => "mongodb://{$host}:{$port}", 'ttl' => 60]),
        ];
    }

    /**
     * Constructing a handler must not touch the network. Proved by a real
     * accept() count, plus a real DDL side-effect for the database backend.
     */
    public function testASessionHandlerConstructorPerformsNoNetworkIo(): void
    {
        foreach ($this->socketHandlers('127.0.0.1', $this->listener->port) as $name => $build) {
            $before = $this->listener->accepted();
            $build();
            $this->listener->settle();
            $after = $this->listener->accepted();

            $this->assertSame(
                $before,
                $after,
                "{$name}: the CONSTRUCTOR opened a connection (" . ($after - $before)
                . ' accepted). Connection belongs on first use, inside the failure policy - a '
                . 'constructor sits outside it, so nothing it does can be logged, degraded or '
                . 're-raised.'
            );
        }

        // The database backend owns no socket of its own to watch when an
        // adapter is injected, so its constructor is measured by its SIDE
        // EFFECT against a real engine: drop the table, construct, and a SECOND
        // independent connection must still find it absent. That is a real
        // out-of-band observation, not an assertion about code shape.
        $databaseFile = $this->temporaryPath('construction');
        $adapter = new SQLite3Adapter($databaseFile);
        $adapter->execute('DROP TABLE IF EXISTS tina4_session');
        $adapter->commit();

        new DatabaseSessionHandler(['db' => $adapter]);

        $probe = new SQLite3Adapter($databaseFile);
        $tableExists = $probe->tableExists('tina4_session');
        $probe->close();
        $adapter->close();

        $this->assertFalse(
            $tableExists,
            'database: the DatabaseSessionHandler CONSTRUCTOR created its table - real DDL on a '
            . 'real connection, outside the failure policy. Because the request path builds a '
            . 'Session per request, that would run on EVERY request.'
        );

        // The other half of the same constructor: with no adapter injected it
        // used to call Database::fromEnv(), and every adapter constructor ends
        // in open() - a REAL connect. Point TINA4_DATABASE_URL at the counting
        // listener and the count answers directly.
        $this->requirePostgresDriver();

        $this->setEnvironment('TINA4_DATABASE_URL', "postgres://127.0.0.1:{$this->listener->port}/probe");
        $this->setEnvironment('TINA4_DATABASE_USERNAME', 'probe');
        $this->setEnvironment('TINA4_DATABASE_PASSWORD', 'probe');
        \Tina4\DotEnv::resetEnv();

        $before = $this->listener->accepted();
        try {
            new DatabaseSessionHandler();
        } catch (\Throwable $failure) {
            // Irrelevant here: we are measuring the DIAL, not the conversation.
            // A constructor that connects fails against a listener that speaks
            // no protocol - and a constructor that does not connect cannot.
        }
        $this->listener->settle();
        $after = $this->listener->accepted();

        $this->assertSame(
            $before,
            $after,
            'database (env fallback): the CONSTRUCTOR dialled the database (' . ($after - $before)
            . ' accepted). Database::fromEnv() builds an adapter and every adapter constructor '
            . 'calls open(), so an unreachable database took the app down at construction '
            . 'instead of degrading per request.'
        );
    }

    /**
     * Deferred is not the same as never. The FIRST OPERATION must connect.
     *
     * Without this, "do no I/O in the constructor" is trivially satisfied by a
     * handler that does no I/O at all, and the case above would pass on a store
     * that never talks to anything.
     */
    public function testTheBackendConnectionHappensOnFirstUseNotConstruction(): void
    {
        foreach ($this->socketHandlers('127.0.0.1', $this->listener->port) as $name => $build) {
            $handler = $build();
            $this->listener->settle();
            $afterConstruction = $this->listener->accepted();

            // One real operation against the listener. It answers nothing
            // useful, so the handler will fail - that is fine and expected; we
            // are measuring the DIAL, not the conversation.
            try {
                $handler->read('connection-probe-' . bin2hex(random_bytes(4)));
            } catch (\Throwable $expected) {
                // The listener speaks no protocol; a real dial cannot succeed.
            }
            $this->listener->settle();

            $this->assertGreaterThan(
                $afterConstruction,
                $this->listener->accepted(),
                "{$name}: the first operation opened NO connection - the handler is not lazy, it "
                . 'is inert, and the no-I/O-in-the-constructor case would pass on a store that '
                . 'never talks to anything.'
            );
        }
    }

    /**
     * NEGATIVE CONTROL: deferring the connection must not break the connection.
     *
     * A real Redis, a real SQLite file, and the real env-resolution path this
     * change actually rewrote, all driven end to end. "No I/O in the
     * constructor" is not an achievement if the handler no longer works.
     */
    public function testAHealthyBackendStillWorksAfterLazyConnection(): void
    {
        $redisHost = getenv('TINA4_TEST_REDIS_HOST') ?: '127.0.0.1';
        $redisPort = (int)(getenv('TINA4_TEST_REDIS_PORT') ?: 6379);
        $this->requireReachable('redis', $redisHost, $redisPort);

        $sessionId = 'lazyok-' . bin2hex(random_bytes(4));
        $redisHandler = new RedisSessionHandler(['host' => $redisHost, 'port' => $redisPort, 'ttl' => 60]);
        $redisHandler->write($sessionId, ['seeded' => true]);
        $this->assertSame(['seeded' => true], $redisHandler->read($sessionId), 'redis round-trip broke');
        $redisHandler->destroy($sessionId);
        $redisHandler->close();

        // Injected-adapter path: the table is created on FIRST USE, so a
        // deferred ensureTable that never runs shows up exactly here.
        $adapter = new SQLite3Adapter($this->temporaryPath('lazy'));
        $databaseHandler = new DatabaseSessionHandler(['db' => $adapter]);
        $databaseHandler->write($sessionId, ['seeded' => true]);
        $this->assertSame(
            ['seeded' => true],
            $databaseHandler->read($sessionId),
            'the database round-trip broke - the table is created on first use, so a deferred '
            . 'ensureTable that never runs shows up exactly here'
        );
        $databaseHandler->destroy($sessionId);
        $adapter->close();

        // Env-resolution path: this is the code that moved out of the
        // constructor, against a real PostgreSQL server. If the lazy resolve
        // never happens, or happens wrongly, this is where it surfaces.
        $this->requirePostgresDriver();
        $postgresUrl = getenv('TINA4_TEST_PG_URL') ?: 'postgres://127.0.0.1:55432/postgres';
        $postgresHost = getenv('TINA4_TEST_PG_HOST') ?: '127.0.0.1';
        $postgresPort = (int)(getenv('TINA4_TEST_PG_PORT') ?: 55432);
        $this->requireReachable('postgres', $postgresHost, $postgresPort);

        $this->setEnvironment('TINA4_DATABASE_URL', $postgresUrl);
        $this->setEnvironment('TINA4_DATABASE_USERNAME', getenv('TINA4_TEST_PG_USERNAME') ?: 'tina4');
        $this->setEnvironment('TINA4_DATABASE_PASSWORD', getenv('TINA4_TEST_PG_PASSWORD') ?: 'tina4');
        \Tina4\DotEnv::resetEnv();

        $envHandler = new DatabaseSessionHandler();
        $envHandler->write($sessionId, ['seeded' => 'postgres']);
        $this->assertSame(
            ['seeded' => 'postgres'],
            $envHandler->read($sessionId),
            'the env-resolved database round-trip broke - Database::fromEnv() moved out of the '
            . 'constructor into db(), and this is the path that proves it still resolves'
        );
        $envHandler->destroy($sessionId);
    }

    /**
     * Remember an env var's current value, then set it. tearDown restores it.
     */
    private function setEnvironment(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->savedEnvironment)) {
            $this->savedEnvironment[$name] = getenv($name);
        }
        putenv("{$name}={$value}");
    }

    /**
     * A temp SQLite path that tearDown removes.
     */
    private function temporaryPath(string $label): string
    {
        $path = sys_get_temp_dir() . "/tina4-session-{$label}-" . bin2hex(random_bytes(4)) . '.db';
        $this->temporaryFiles[] = $path;
        @unlink($path);
        return $path;
    }

    /**
     * PostgreSQL is a provisioned service; a missing client is not a free pass.
     */
    private function requirePostgresDriver(): void
    {
        if (function_exists('pg_connect')) {
            return;
        }

        $message = 'postgres client ext-pgsql is not installed, so the database backend is not reachable';
        if (getenv('TINA4_REQUIRE_SERVICES')) {
            $this->fail('TINA4_REQUIRE_SERVICES is set but ' . $message);
        }
        $this->markTestSkipped($message);
    }

    /**
     * Skip LOUDLY (naming service, host and port) unless services are required.
     */
    private function requireReachable(string $service, string $host, int $port): void
    {
        $errNo = 0;
        $errStr = '';
        $probe = @fsockopen($host, $port, $errNo, $errStr, 2);
        if ($probe === false) {
            $message = sprintf(
                '%s is not reachable at %s:%d (%s)',
                $service,
                $host,
                $port,
                $errStr !== '' ? $errStr : 'no error reported'
            );
            if (getenv('TINA4_REQUIRE_SERVICES')) {
                $this->fail('TINA4_REQUIRE_SERVICES is set but ' . $message);
            }
            $this->markTestSkipped($message);
        }
        fclose($probe);
    }
}
