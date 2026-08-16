<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\FirebirdAdapter;
use Tina4\Database\MySQLAdapter;
use Tina4\Database\PostgresAdapter;

/**
 * TINA4_DATABASE_CONNECT_TIMEOUT — the connect must be bounded.
 *
 * A database connect that can block forever hangs the application with no log,
 * no error and no signal: the process sits at 0.0% CPU looking healthy while
 * everything behind it queues. MEASURED before the fix on this box (Ubuntu
 * 24.04.4 LTS x86_64, PHP 8.3.6), against the same real socket these tests use,
 * SEVEN of the eight drivers blocked past 25s with nothing armed — ext-pgsql,
 * pdo_pgsql, mysqli, pdo_mysql, pdo_dblib, ext-interbase and PDO_Firebird. Only
 * ext-mongodb stopped on its own, at 10.01s.
 *
 * NO MOCKS. Two real failure modes, no doubles anywhere:
 *
 *  - ACCEPTS AND NEVER REPLIES — {@see blackHolePort()} opens a real listening
 *    socket and never accept()s it. The kernel still completes the TCP
 *    handshake from the listen backlog, so the driver connects instantly and
 *    then waits forever for a reply. The peer is the OS TCP stack. This is NOT
 *    a closed port: a closed port gives instant ECONNREFUSED and would not
 *    exercise a timeout at all.
 *  - SYN DROPPED — {@see DROPPING_HOST} is RFC 5737 TEST-NET-1, routed nowhere,
 *    so connect() itself blocks. MEASURED on this box at the full 8s of an 8s
 *    probe. This is the case the Firebird pre-flight covers.
 *
 * Each black-hole connect runs in its OWN process (tests/fixtures/connectTimeoutProbe.php)
 * because the defect is an unbounded wait: in-process, a regression would hang
 * the suite instead of failing it. The parent caps every probe and reaps it.
 */
final class DatabaseConnectTimeoutTest extends TestCase
{
    private const VAR = 'TINA4_DATABASE_CONNECT_TIMEOUT';

    /** RFC 5737 TEST-NET-1 — routed nowhere, so the SYN is dropped and connect() blocks. */
    private const DROPPING_HOST = '192.0.2.1';

    /** Seconds a probe is given before the parent calls it unbounded and kills it. */
    private const PROBE_CAP = 20.0;

    /** @var resource|null Real listening socket that is never accept()ed. */
    private $blackHoleServer = null;

    /** @var array<int, array{proc: resource, pipes: array}> Children to reap. */
    private array $children = [];

    protected function setUp(): void
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorString);
        if ($server === false) {
            $this->fail("could not open the black-hole listener: {$errorString}");
        }
        $this->blackHoleServer = $server;

        $this->clearTimeoutVar();
    }

    protected function tearDown(): void
    {
        // Reap what we spawned — a probe left running holds the port forever.
        foreach ($this->children as $child) {
            $this->reap($child);
        }
        $this->children = [];

        if (is_resource($this->blackHoleServer)) {
            fclose($this->blackHoleServer);
        }
        $this->blackHoleServer = null;

        $this->clearTimeoutVar();
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    private function clearTimeoutVar(): void
    {
        putenv(self::VAR);
        unset($_ENV[self::VAR], $_SERVER[self::VAR]);
    }

    private function setTimeoutVar(string $value): void
    {
        $_ENV[self::VAR] = $value;
        putenv(self::VAR . '=' . $value);
    }

    /** The port of the real socket that accepts and never replies. */
    private function blackHolePort(): int
    {
        $name = stream_socket_get_name($this->blackHoleServer, false);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    /**
     * Start a probe child. Returns a handle to poll with {@see settle()}.
     *
     * @param string      $adapter      Probe adapter key (pgsql/pdopgsql/mysql/firebird/pdofirebird).
     * @param string      $host         Target host.
     * @param int         $port         Target port.
     * @param string|null $timeoutValue Value for the variable; null leaves it UNSET.
     * @return array{proc: resource, pipes: array, started: float, label: string}
     */
    private function startProbe(string $adapter, string $host, int $port, ?string $timeoutValue): array
    {
        $environment = getenv();
        if ($timeoutValue === null) {
            unset($environment[self::VAR]);
        } else {
            $environment[self::VAR] = $timeoutValue;
        }

        $command = [
            PHP_BINARY,
            __DIR__ . '/fixtures/connectTimeoutProbe.php',
            $adapter,
            $host,
            (string) $port,
        ];

        $pipes = [];
        $proc = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
            $environment
        );
        if (!is_resource($proc)) {
            $this->fail("could not start the {$adapter} probe");
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $child = [
            'proc' => $proc,
            'pipes' => $pipes,
            'started' => microtime(true),
            'label' => $adapter . ' -> ' . $host . ':' . $port
                . ' with ' . self::VAR . '=' . ($timeoutValue ?? '(unset)'),
        ];
        $this->children[] = $child;

        return $child;
    }

    /**
     * Wait for probes to finish, up to {@see PROBE_CAP}.
     *
     * @param array<string, array> $probes Keyed handles from {@see startProbe()}.
     * @return array<string, array{finished: bool, elapsed: float, output: string, label: string}>
     */
    private function settle(array $probes): array
    {
        $results = [];
        $deadline = microtime(true) + self::PROBE_CAP;
        $outstanding = $probes;

        while ($outstanding !== [] && microtime(true) < $deadline) {
            foreach ($outstanding as $key => $child) {
                $results[$key]['output'] = ($results[$key]['output'] ?? '')
                    . (string) stream_get_contents($child['pipes'][1])
                    . (string) stream_get_contents($child['pipes'][2]);

                $status = proc_get_status($child['proc']);
                if ($status['running'] === false) {
                    $results[$key]['finished'] = true;
                    $results[$key]['elapsed'] = microtime(true) - $child['started'];
                    $results[$key]['label'] = $child['label'];
                    unset($outstanding[$key]);
                }
            }
            usleep(50_000);
        }

        foreach ($outstanding as $key => $child) {
            $results[$key]['finished'] = false;
            $results[$key]['elapsed'] = microtime(true) - $child['started'];
            $results[$key]['label'] = $child['label'];
        }

        foreach ($probes as $key => $child) {
            $results[$key]['output'] = ($results[$key]['output'] ?? '')
                . (string) stream_get_contents($child['pipes'][1])
                . (string) stream_get_contents($child['pipes'][2]);
        }

        return $results;
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
            $status = proc_get_status($child['proc']);
            if ($status['running'] === true) {
                proc_terminate($child['proc'], SIGKILL);
            }
            proc_close($child['proc']);
        }
    }

    /**
     * Assert one probe result carries the whole contract message.
     *
     * @param array{finished: bool, elapsed: float, output: string, label: string} $result
     */
    private function assertBoundedAt(array $result, int $expectedSeconds, string $target): void
    {
        $this->assertTrue(
            $result['finished'],
            "{$result['label']} never finished — still blocked after {$result['elapsed']}s, "
            . 'so the connect is UNBOUNDED'
        );
        $this->assertStringContainsString(
            'timed out',
            $result['output'],
            "{$result['label']} finished but did not report a timeout. Got: {$result['output']}"
        );
        // The contract: name the host, the port, the elapsed seconds, and the
        // variable that tunes it.
        $this->assertStringContainsString($target, $result['output'], 'must name the host and the port');
        $this->assertMatchesRegularExpression(
            '/timed out after \d+\.\d+ seconds/',
            $result['output'],
            'must name the elapsed seconds'
        );
        $this->assertStringContainsString(
            self::VAR . '=' . $expectedSeconds,
            $result['output'],
            'must name the variable that tunes it, and the value in force'
        );
        // The translator must KEEP the driver's own diagnosis. Without it the
        // operator gets only one of the two halves — either the raw driver text
        // that names no variable, or our text that loses the specific cause.
        $this->assertMatchesRegularExpression(
            '/the driver said: \S/',
            $result['output'],
            "the driver's own diagnosis must survive the translation. Got: {$result['output']}"
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // POSITIVE — a connect that would block forever is bounded
    // ─────────────────────────────────────────────────────────────────

    public function testDriverTimeoutWordingWinsAtTheClockBoundary(): void
    {
        $translator = new class {
            use \Tina4\Database\ConnectTimeoutTrait;

            public function translate(string $cause): void
            {
                $this->connectTimeoutArmed = 2;
                $this->connectStartedAt = microtime(true) - 1.999;
                $this->throwIfConnectTimedOut('PostgresAdapter', '127.0.0.1:5432', $cause);
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('timed out after');
        $this->expectExceptionMessage('the driver said: connection failed: timeout expired');
        $translator->translate('connection failed: timeout expired');
    }

    /**
     * The four drivers with a working bound, against a socket that accepts and
     * never replies. Run together so the suite pays one wait, not four.
     */
    public function testConnectIsBoundedAgainstASocketThatAcceptsAndNeverReplies(): void
    {
        $port = $this->blackHolePort();
        $target = '127.0.0.1:' . $port;

        $probes = [];
        if (function_exists('pg_connect')) {
            $probes['ext-pgsql'] = $this->startProbe('pgsql', '127.0.0.1', $port, '2');
        }
        if (in_array('pgsql', \PDO::getAvailableDrivers(), true)) {
            $probes['pdo_pgsql'] = $this->startProbe('pdopgsql', '127.0.0.1', $port, '2');
        }
        if (class_exists('mysqli')) {
            $probes['mysqli'] = $this->startProbe('mysql', '127.0.0.1', $port, '2');
        }
        if ($probes === []) {
            $this->markTestSkipped('no bounded driver present to exercise (ext-pgsql / pdo_pgsql / ext-mysqli)');
        }

        foreach ($this->settle($probes) as $driver => $result) {
            $this->assertBoundedAt($result, 2, $target);
            $this->assertLessThan(
                15.0,
                $result['elapsed'],
                "{$driver} took {$result['elapsed']}s for a 2s bound"
            );
        }
    }

    /**
     * The other real failure mode: a host whose SYN is dropped, where connect()
     * itself blocks.
     *
     * Firebird reaches this bound through a pre-flight TCP connect — it has NO
     * driver-level connect timeout in either extension (MEASURED: without the
     * pre-flight it waits out libfbclient's own 180s ceiling). MSSQL reaches it
     * through FreeTDS's own login timeout, which DOES fire for a dropped SYN —
     * MEASURED by deleting a pre-flight from that adapter and watching this
     * test stay green, which is why MSSQL does not carry one.
     */
    public function testConnectIsBoundedAgainstAHostThatDropsTheSyn(): void
    {
        $probes = [];
        if (function_exists('ibase_connect') || function_exists('fbird_connect')) {
            $probes['ext-interbase'] = $this->startProbe('firebird', self::DROPPING_HOST, 3050, '2');
        }
        if (in_array('firebird', \PDO::getAvailableDrivers(), true)) {
            $probes['pdo_firebird'] = $this->startProbe('pdofirebird', self::DROPPING_HOST, 3050, '2');
        }
        // MSSQL is here and NOT in the accepts-and-never-replies test above,
        // because FreeTDS's login timeout bounds a dropped SYN but does NOT
        // fire against a peer that accepts and then goes silent (MEASURED,
        // still blocked at 45s with ATTR_TIMEOUT and DBLIB_ATTR_CONNECTION_TIMEOUT
        // both set to 2). That gap is reported, not papered over.
        if (in_array('dblib', \PDO::getAvailableDrivers(), true) || function_exists('sqlsrv_connect')) {
            $probes['mssql'] = $this->startProbe('mssql', self::DROPPING_HOST, 1433, '2');
        }
        if ($probes === []) {
            $this->markTestSkipped('no driver present for this case (ext-interbase / pdo_firebird / pdo_dblib)');
        }

        foreach ($this->settle($probes) as $driver => $result) {
            $port = $driver === 'mssql' ? 1433 : 3050;
            $this->assertBoundedAt($result, 2, self::DROPPING_HOST . ':' . $port);
            $this->assertLessThan(
                15.0,
                $result['elapsed'],
                "{$driver} took {$result['elapsed']}s for a 2s bound"
            );
        }
    }

    /**
     * MongoDB is the one driver that stops on its own (MEASURED 10.01s, its
     * own connectTimeoutMS default) — so "it failed" proves nothing here. What
     * must be proved is that the failure carries the TINA4 message naming the
     * host, the port and the variable, rather than the driver's own
     * "No suitable servers found", which names no variable to tune.
     */
    public function testAnExpiringMongoConnectReportsTheTina4Message(): void
    {
        if (!extension_loaded('mongodb') || !class_exists('\MongoDB\Client')) {
            $this->markTestSkipped('needs ext-mongodb and the mongodb/mongodb library');
        }

        $port = $this->blackHolePort();
        $probes = ['mongodb' => $this->startProbe('mongodb', '127.0.0.1', $port, '2')];

        $result = $this->settle($probes)['mongodb'];

        $this->assertBoundedAt($result, 2, '127.0.0.1:' . $port);
        $this->assertLessThan(15.0, $result['elapsed'], "mongodb took {$result['elapsed']}s for a 2s bound");
    }

    /**
     * Unset uses the documented default of 10 seconds, and garbage warns and
     * falls back to the same 10 — both proved by the bound that actually fires,
     * not by reading the constant back.
     */
    public function testUnsetUsesTenSecondsAndGarbageWarnsAndUsesTenSeconds(): void
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('needs ext-pgsql to exercise a real bounded connect');
        }

        $port = $this->blackHolePort();
        $target = '127.0.0.1:' . $port;

        $probes = [
            'unset' => $this->startProbe('pgsql', '127.0.0.1', $port, null),
            'garbage' => $this->startProbe('pgsql', '127.0.0.1', $port, 'ten-ish'),
        ];

        $results = $this->settle($probes);

        $this->assertBoundedAt($results['unset'], 10, $target);
        $this->assertGreaterThanOrEqual(
            10.0,
            $results['unset']['elapsed'],
            'the default must be 10 seconds, not something shorter'
        );

        $this->assertBoundedAt($results['garbage'], 10, $target);
        $this->assertStringContainsString(
            'TINA4_DATABASE_CONNECT_TIMEOUT must be a whole number of seconds',
            $results['garbage']['output'],
            'garbage must WARN, not fall back silently'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // NEGATIVE — the bound must not fire when it should not
    // ─────────────────────────────────────────────────────────────────

    /**
     * Zero really does restore the old unbounded behaviour.
     *
     * Proved by a probe that is STILL BLOCKED long after a 2s bound would have
     * fired — the parent kills it, which is the only way to assert "waits
     * indefinitely" in finite time.
     */
    public function testZeroDisablesTheBoundAndWaitsIndefinitely(): void
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('needs ext-pgsql to exercise a real unbounded connect');
        }

        $port = $this->blackHolePort();
        $probes = ['disabled' => $this->startProbe('pgsql', '127.0.0.1', $port, '0')];

        // Give it well past the 10s default and the 2s used above.
        $deadline = microtime(true) + 14.0;
        while (microtime(true) < $deadline) {
            if (proc_get_status($probes['disabled']['proc'])['running'] === false) {
                $output = (string) stream_get_contents($probes['disabled']['pipes'][1]);
                $this->fail(
                    'TINA4_DATABASE_CONNECT_TIMEOUT=0 must disable the bound, but the connect '
                    . "ended on its own. Got: {$output}"
                );
            }
            usleep(200_000);
        }

        $this->assertTrue(
            proc_get_status($probes['disabled']['proc'])['running'],
            'a disabled bound must still be waiting after 14s'
        );
    }

    /**
     * The critical negative control: a bound that fires too eagerly breaks
     * every slow-but-healthy connect. A REAL PostgreSQL connect, with the bound
     * armed tight, must still succeed and still serve a query.
     */
    public function testAHealthyPostgresConnectStillSucceedsWithTheBoundArmed(): void
    {
        $url = getenv('TINA4_TEST_PG_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped('PostgreSQL test URL not set (TINA4_TEST_PG_URL)');
        }
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('needs ext-pgsql');
        }

        $this->setTimeoutVar('2');

        $adapter = new PostgresAdapter($url);
        $rows = $adapter->query('SELECT 1 AS alive');
        $adapter->close();

        $this->assertSame(1, (int) $rows[0]['alive'], 'a healthy connect must survive the bound');
    }

    /** Same negative control on MySQL, whose bound also arms a READ timeout. */
    public function testAHealthyMySQLConnectStillSucceedsWithTheBoundArmed(): void
    {
        $url = getenv('TINA4_TEST_MYSQL_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped('MySQL test URL not set (TINA4_TEST_MYSQL_URL)');
        }
        if (!class_exists('mysqli')) {
            $this->markTestSkipped('needs ext-mysqli');
        }

        $this->setTimeoutVar('2');

        $adapter = new MySQLAdapter($url);
        $rows = $adapter->query('SELECT 1 AS alive');
        $adapter->close();

        $this->assertSame(1, (int) $rows[0]['alive'], 'a healthy connect must survive the bound');
    }

    /**
     * Same negative control on Firebird — the one whose bound is a pre-flight
     * TCP connect, so this is what proves the pre-flight does not reject a
     * server that is actually there.
     */
    public function testAHealthyFirebirdConnectStillSucceedsWithTheBoundArmed(): void
    {
        $url = getenv('TINA4_TEST_FIREBIRD_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped('Firebird test URL not set (TINA4_TEST_FIREBIRD_URL)');
        }
        if (!function_exists('ibase_connect') && !function_exists('fbird_connect')) {
            $this->markTestSkipped('needs ext-interbase');
        }

        // Run in a CHILD process. ext-interbase shares ONE physical link across
        // connections opened with identical arguments, so an in-process holder
        // here is a holder of whatever link the rest of the suite is using — a
        // connect test has no business reaching into that. Opening one from
        // inside this suite used to leave TWELVE unrelated live-Firebird tests
        // erroring with "invalid database handle (no active connection)"; that
        // was FirebirdAdapter suppressing its own native close, fixed with its
        // regression tests in tests/FirebirdSharedLinkTest.php. The isolation
        // stays regardless: a child cannot perturb the suite's connections, it
        // bounds a connect that could otherwise hang the run, and the connect
        // it proves is just as real.
        $probes = ['firebird' => $this->startProbe('firebirdlive', '', 0, '2')];
        $result = $this->settle($probes)['firebird'];

        $this->assertTrue($result['finished'], 'the healthy Firebird connect never finished');
        $this->assertStringContainsString(
            'OUTCOME=CONNECTED',
            $result['output'],
            "a healthy connect must survive the pre-flight bound. Got: {$result['output']}"
        );
    }

    /**
     * Negative control for the Mongo change specifically: open() now pings, so
     * a REAL MongoDB must still open cleanly with the bound armed tight.
     */
    public function testAHealthyMongoConnectStillSucceedsWithTheBoundArmed(): void
    {
        $uri = getenv('TINA4_TEST_MONGO_URI');
        if ($uri === false || $uri === '') {
            $this->markTestSkipped('MongoDB test URI not set (TINA4_TEST_MONGO_URI)');
        }
        if (!extension_loaded('mongodb') || !class_exists('\MongoDB\Client')) {
            $this->markTestSkipped('needs ext-mongodb and the mongodb/mongodb library');
        }

        $this->setTimeoutVar('2');

        $adapter = new \Tina4\Database\MongoDBAdapter($uri);
        $tables = $adapter->getTables();
        $adapter->close();

        $this->assertIsArray($tables, 'a healthy Mongo connect must survive the bound and stay usable');
    }

    /**
     * Negative control for the MSSQL pre-flight: a REAL SQL Server must still
     * open cleanly with the bound armed tight.
     */
    public function testAHealthyMSSQLConnectStillSucceedsWithTheBoundArmed(): void
    {
        $url = getenv('TINA4_TEST_MSSQL_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped('SQL Server test URL not set (TINA4_TEST_MSSQL_URL)');
        }
        if (!in_array('dblib', \PDO::getAvailableDrivers(), true) && !function_exists('sqlsrv_connect')) {
            $this->markTestSkipped('needs pdo_dblib or ext-sqlsrv');
        }

        $this->setTimeoutVar('2');

        $adapter = new \Tina4\Database\MSSQLAdapter($url);
        $rows = $adapter->query('SELECT 1 AS alive');
        $adapter->close();

        $this->assertSame(1, (int) $rows[0]['alive'], 'a healthy connect must survive the pre-flight bound');
    }

    /**
     * A connect that fails for a REAL reason must still report that reason —
     * the bound must not swallow it and relabel everything a timeout. A closed
     * port refuses instantly, well inside the bound.
     */
    public function testAnInstantRefusalIsNotReportedAsATimeout(): void
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('needs ext-pgsql');
        }

        $this->setTimeoutVar('10');

        // Port 1 on loopback: nothing listens, so this is ECONNREFUSED at once.
        try {
            new PostgresAdapter('postgres://probe:probe@127.0.0.1:1/probe');
            $this->fail('connecting to a closed port must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString(
                'timed out',
                $e->getMessage(),
                'an instant refusal is not a timeout — the real cause must survive'
            );
            $this->assertStringContainsString('Failed to connect to PostgreSQL', $e->getMessage());
        }
    }
}
