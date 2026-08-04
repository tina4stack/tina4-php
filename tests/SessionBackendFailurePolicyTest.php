<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Backend-failure policy lock-in (log-loud + degrade) for the Session boundary.
 * NO DOUBLES.
 *
 * Contract (parity across all 4 frameworks): when a session backend
 * (Redis/Valkey/Mongo/Database) becomes unreachable mid-request, the framework
 * must log loudly and degrade — never silently lose data, and never let one
 * backend blip 500 every request (cascade outage):
 *
 *   read failure       -> log an error, return an empty session (request serves)
 *   write failure      -> log an error, best-effort (dirty flag kept for retry)
 *   destroy/gc failure -> log an error, swallow
 *
 * A genuinely EMPTY result (no such session yet) is NOT a failure and must never
 * be logged as one. TINA4_SESSION_STRICT=true flips all of this to re-raise.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS FILE USED TO BE — and why every line of it was untrustworthy.
 *
 * It declared two in-test SessionHandler subclasses, ThrowingSessionHandler and
 * EmptyHealthySessionHandler. It was the last surviving member of a double that
 * had been ported four ways — tina4-python's _ExplodingHandler, tina4-ruby's
 * RaisingHandler, tina4-nodejs's ExplodingHandler — each with a matching
 * "Empty*" positive control that also could not fail.
 *
 * ThrowingSessionHandler raised \RuntimeException('backend unreachable (read)').
 * The CLASS happened to be right — the real RedisSessionHandler also raises
 * \RuntimeException, and the policy catches \Throwable — so PHP escaped the
 * class-level calibration bug found in Python (whose double raised the BUILTIN
 * ConnectionError while redis-py raises redis.exceptions.ConnectionError, which
 * does not inherit from it). But the MESSAGE was pure invention: the two strict
 * -mode tests asserted expectExceptionMessage('backend unreachable (read)'),
 * a string no Tina4 code has ever produced. The real driver says
 * "Redis connection failed: [61] Connection refused". Those assertions were
 * therefore true only of the fake, and would have kept passing had the real
 * transport stopped raising entirely.
 *
 * EmptyHealthySessionHandler was the worse of the two. It asserted "an empty
 * healthy read logs ZERO errors" against a handler that CANNOT fail, so it could
 * never catch the regression it existed for: a real server's `$-1` null bulk
 * reply being misclassified by the TRANSPORT as an error. That misclassification
 * lives in RedisSessionHandler::readReply(), and the transport never ran.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT IS NOW — real drivers, a real log sink, no stand-ins.
 *
 *  (1) UNREACHABLE BACKEND: the REAL RedisSessionHandler pointed at a genuinely
 *      closed port, obtained by bind-then-release. Every operation fails with a
 *      real ECONNREFUSED through the real socket. Needs no service, so it never
 *      skips. A driver-sanity assertion proves the port really is closed first,
 *      otherwise every "unreachable" case would be vacuous.
 *  (2) EMPTY-BUT-HEALTHY: the REAL RedisSessionHandler against LIVE Redis,
 *      reading a freshly generated random key that provably never existed — a
 *      real `$-1` null bulk reply off the wire. Skips loudly naming host+port.
 *  (3) GC FAILURE: the REAL DatabaseSessionHandler on a REAL SQLite file that is
 *      made read-only, so the DELETE takes a real SQLITE_READONLY.
 *  (4) WRITE-FAILS-AFTER-A-SUCCESSFUL-START: the REAL file backend in a real
 *      temp dir. start()+save() write for real, then the session FILE is
 *      chmod 0400 so the NEXT write takes a real EACCES from the real kernel.
 *
 * LOGGING is measured at the real sink: Log::configure() points the REAL logger
 * at a real directory and the tests read the bytes it actually wrote, delta per
 * scenario. A sink self-test runs first so a logger that writes nothing cannot
 * make the "logged nothing" assertions trivially true.
 *
 * POSIX detail, measured not assumed (same finding as the Node and Python
 * conversions): chmod 0500 on the session DIRECTORY does NOT block the write.
 * An existing path needs write permission on the FILE; directory permission
 * governs create/unlink/rename.
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Log;
use Tina4\Session;
use Tina4\Session\DatabaseSessionHandler;
use Tina4\Session\RedisSessionHandler;

class SessionBackendFailurePolicyTest extends TestCase
{
    private string $tempDir;

    /** Byte offset into tina4.log marking the start of the scenario under test. */
    private int $logMark = 0;

    private const REDIS_HOST_DEFAULT = '127.0.0.1';
    private const REDIS_PORT_DEFAULT = 6379;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tina4_sess_policy_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        // Pin dev (TINA4_DEBUG=true) so the log FILE is written — since v3.13.39
        // the file is dev-gated by default (prod/containers are stdout-only).
        $_ENV['TINA4_DEBUG'] = 'true';
        @putenv('TINA4_DEBUG=true');
        // loggedErrors() below parses each line as JSON. Since 2026-08-01 the
        // shipped default format is TEXT (only TINA4_LOG_FORMAT=json selects
        // JSON — the implicit production->JSON switch was deleted), so this
        // file selects the JSON writer explicitly the way an operator would.
        $_ENV['TINA4_LOG_FORMAT'] = 'json';
        @putenv('TINA4_LOG_FORMAT=json');
        Log::reset();
        Log::configure(logDir: $this->tempDir);
        putenv('TINA4_SESSION_STRICT');
        unset($_ENV['TINA4_SESSION_STRICT']);

        $this->assertLogSinkIsReal();
    }

    protected function tearDown(): void
    {
        Log::reset();
        unset($_ENV['TINA4_DEBUG'], $_ENV['TINA4_LOG_FORMAT']);
        @putenv('TINA4_DEBUG');
        @putenv('TINA4_LOG_FORMAT');
        putenv('TINA4_SESSION_STRICT');
        unset($_ENV['TINA4_SESSION_STRICT']);
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
                continue;
            }
            @chmod($path, 0600); // a 0400 fixture file must still be removable
            @unlink($path);
        }
        @rmdir($dir);
    }

    // ── the real log sink ───────────────────────────────────────────────────

    private function logFile(): string
    {
        return $this->tempDir . '/tina4.log';
    }

    /** Ignore everything logged before the scenario under test. */
    private function markLog(): void
    {
        clearstatcache(true, $this->logFile());
        $this->logMark = is_file($this->logFile()) ? (int) filesize($this->logFile()) : 0;
    }

    /**
     * The ERROR messages the REAL logger actually wrote since the last mark.
     *
     * This is not a capture double: Log::error() runs its real body — level
     * gating, output routing, JSON structuring, the file write — and we read the
     * file afterwards, exactly as an operator would.
     *
     * @return array<int, string>
     */
    private function loggedErrors(): array
    {
        clearstatcache(true, $this->logFile());
        if (!is_file($this->logFile())) {
            return [];
        }
        $handle = fopen($this->logFile(), 'r');
        fseek($handle, $this->logMark);
        $bytes = (string) stream_get_contents($handle);
        fclose($handle);

        $errors = [];
        foreach (explode("\n", $bytes) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $entry = json_decode($line, true);
            if (is_array($entry) && strtoupper((string) ($entry['level'] ?? '')) === 'ERROR') {
                $errors[] = (string) ($entry['message'] ?? '');
            }
        }

        return $errors;
    }

    /**
     * Driver sanity for the SINK itself.
     *
     * Without this, a logger that silently wrote nothing would make every
     * "was logged" assertion vacuous AND every "logged nothing" assertion
     * trivially true — the whole file would pass while measuring nothing.
     */
    private function assertLogSinkIsReal(): void
    {
        $this->markLog();
        Log::error('sink-selftest');
        $this->assertNotEmpty(
            array_filter($this->loggedErrors(), static fn (string $m) => str_contains($m, 'sink-selftest')),
            'the real logger wrote nothing to ' . $this->logFile()
            . ' — every log assertion in this file would be meaningless'
        );
    }

    // ── real, genuinely closed port ─────────────────────────────────────────

    /**
     * A port that is genuinely closed: bind it, read it, release it.
     * Not a simulation of refusal — the kernel refuses the connect for real.
     */
    private function closedPort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($probe, "could not bind a probe socket: [$errno] $errstr");
        $name = stream_socket_get_name($probe, false);
        fclose($probe);

        $port = (int) substr($name, strrpos($name, ':') + 1);

        $check = @fsockopen('127.0.0.1', $port, $e, $s, 0.5);
        if ($check !== false) {
            fclose($check);
            $this->fail("127.0.0.1:$port answered — it was supposed to be closed, so every "
                . "'unreachable backend' case here would be vacuous");
        }

        return $port;
    }

    /**
     * A Session on the 'redis' backend driven by the REAL RedisSessionHandler
     * pointed at a genuinely closed port.
     *
     * NOT a double: this is the shipped handler class talking real RESP over a
     * real socket. PHP's Session takes no handler constructor argument, so the
     * real object is injected into the real property — the equivalent of
     * Python's Session(handler=...).
     */
    private function unreachableRedisSession(): Session
    {
        $handler = new RedisSessionHandler([
            'host' => '127.0.0.1',
            'port' => $this->closedPort(),
            'ttl'  => 60,
        ]);

        return $this->sessionWithRedisHandler($handler);
    }

    private function sessionWithRedisHandler(RedisSessionHandler $handler): Session
    {
        $session = new Session('redis', ['ttl' => 600]);
        (new \ReflectionClass($session))->getProperty('redisHandler')->setValue($session, $handler);

        return $session;
    }

    // ── live Redis ──────────────────────────────────────────────────────────

    private static function redisHost(): string
    {
        return getenv('TINA4_TEST_REDIS_HOST') ?: self::REDIS_HOST_DEFAULT;
    }

    private static function redisPort(): int
    {
        return (int) (getenv('TINA4_TEST_REDIS_PORT') ?: self::REDIS_PORT_DEFAULT);
    }

    private function liveRedisHandlerOrSkip(): RedisSessionHandler
    {
        $host = self::redisHost();
        $port = self::redisPort();

        $socket = @fsockopen($host, $port, $errno, $errstr, 2);
        if ($socket === false) {
            $this->markTestSkipped(sprintf(
                'redis not reachable at %s:%d (%s) — the empty-but-healthy read needs a REAL server',
                $host,
                $port,
                $errstr
            ));
        }
        fclose($socket);

        return new RedisSessionHandler(['host' => $host, 'port' => $port, 'ttl' => 60]);
    }

    private function randomAbsentSessionId(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(12));
    }

    // ── real database-backed session on a real SQLite file ──────────────────

    private function databaseSession(string $dbFile): Session
    {
        $handler = new DatabaseSessionHandler([
            'db'  => Database::create('sqlite:///' . $dbFile),
            'ttl' => 3600,
        ]);
        $session = new Session('database', ['ttl' => 3600]);
        (new \ReflectionClass($session))->getProperty('dbHandler')->setValue($session, $handler);

        return $session;
    }

    private function dirtyFlag(Session $session): bool
    {
        return (bool) (new \ReflectionClass($session))->getProperty('dirty')->getValue($session);
    }

    // ── Invariant 1: read failure logs + degrades to an empty session ───────

    public function testReadFailureOnAReallyRefusedPortLogsAndDegradesToEmpty(): void
    {
        $session = $this->unreachableRedisSession();

        $this->markLog();
        $id = $session->start('sess-read-1'); // resuming -> triggers a real read

        $this->assertSame('sess-read-1', $id, 'the request must still get a session id');
        $this->assertTrue($session->isStarted());
        $this->assertSame([], $session->all(), 'must degrade to empty, not crash');

        $errors = $this->loggedErrors();
        $this->assertNotEmpty(
            array_filter($errors, static fn (string $m) => str_contains($m, 'Session read failed')),
            'a backend read failure must be logged, never silent. Got: ' . json_encode($errors)
        );
        $this->assertNotEmpty(
            array_filter($errors, static fn (string $m) => str_contains($m, 'RedisSessionHandler')),
            'the log must name the backend that failed'
        );
        // The cause must be the REAL driver's message, not a string this test chose.
        $this->assertNotEmpty(
            array_filter($errors, static fn (string $m) => stripos($m, 'refused') !== false),
            'the logged cause must be the real ECONNREFUSED. Got: ' . json_encode($errors)
        );
    }

    // ── Invariant 2: write failure -> false, dirty retained, logged ─────────

    public function testWriteFailureOnAReallyRefusedPortIsBestEffortAndLogged(): void
    {
        $session = $this->unreachableRedisSession();
        $session->start(); // fresh session — no read, so this succeeds

        $this->markLog();
        $session->set('user_id', 42); // set() flips dirty + calls save()

        $this->assertFalse($session->save(), 'a degraded write must report false');
        $this->assertTrue(
            $this->dirtyFlag($session),
            'dirty flag must be retained after a failed write so a later save() retries'
        );

        $errors = $this->loggedErrors();
        $this->assertNotEmpty(
            array_filter($errors, static fn (string $m) => str_contains($m, 'Session write failed')),
            'a backend write failure must be logged. Got: ' . json_encode($errors)
        );
        $this->assertNotEmpty(
            array_filter($errors, static fn (string $m) => stripos($m, 'refused') !== false),
            'the logged cause must be the real ECONNREFUSED'
        );
    }

    // ── Invariant 3: destroy / gc failures log + do not crash ───────────────

    public function testDestroyFailureOnAReallyRefusedPortLogsAndDoesNotCrash(): void
    {
        $session = $this->unreachableRedisSession();
        $session->start(); // fresh — no read

        $this->markLog();
        $session->destroy(); // must not raise

        $this->assertFalse($session->isStarted());
        $this->assertNotEmpty(
            array_filter($this->loggedErrors(), static fn (string $m) => str_contains($m, 'Session destroy failed')),
            'a backend destroy failure must be logged'
        );
    }

    /**
     * gc() only reaches a backend for the file and database backends (Redis and
     * friends expire natively), so the real failure driver here is a REAL SQLite
     * database file that is read-only: the DELETE takes a real SQLITE_READONLY
     * ("attempt to write a readonly database") from the real engine.
     *
     * POSIX detail, measured not assumed: chmod 0400 on the database file does
     * NOT affect a connection that is ALREADY OPEN — the kernel checks
     * permission at open() time, so an existing handle keeps writing happily.
     * (chmod 0500 on the DIRECTORY does not deny it either, since SQLite reuses
     * the journal path it already created.) The failure only materialises for a
     * connection opened AFTER the chmod, so the second Session below gets its
     * own fresh Database on the now-read-only file.
     */
    public function testGcFailureOnAReallyReadOnlyDatabaseLogsAndDoesNotCrash(): void
    {
        if (!$this->runsWhereFilePermissionsBite(__FUNCTION__)) {
            return;   // already asserted, in a child where permissions really bite
        }

        $dbFile = $this->tempDir . '/sessions.db';

        // A real write on a writable database first, so the schema and the row
        // genuinely exist and the file is a real, populated SQLite database.
        $seed = $this->databaseSession($dbFile);
        $seed->start('gc-victim');
        $seed->set('k', 'v');
        $this->assertTrue($seed->save(), 'precondition: the first write must genuinely succeed');

        chmod($dbFile, 0400);
        $this->assertFalse(is_writable($dbFile), 'precondition: the real database file must really be read-only');

        // A FRESH connection, opened after the chmod — this one really cannot write.
        $session = $this->databaseSession($dbFile);

        $this->markLog();
        $session->gc(); // must not raise

        $errors = $this->loggedErrors();
        chmod($dbFile, 0600); // restore before an assertion can abort the test

        $this->assertNotEmpty(
            array_filter($errors, static fn (string $m) => str_contains($m, 'Session gc failed')),
            'a backend gc failure must be logged. Got: ' . json_encode($errors)
        );
        $this->assertNotEmpty(
            array_filter($errors, static fn (string $m) => stripos($m, 'readonly') !== false),
            'the logged cause must be the real SQLITE_READONLY. Got: ' . json_encode($errors)
        );
    }

    // ── Invariant 4: empty-but-healthy is NOT a failure (the important one) ──

    /**
     * A HEALTHY server with no data for this id: a real `$-1` null bulk reply.
     *
     * The deleted EmptyHealthySessionHandler could not fail, so it could never
     * catch the regression this exists for — a real empty reply being
     * misclassified by RedisSessionHandler::readReply() as an error. The
     * transport now runs.
     */
    public function testEmptyButHealthyRedisReadIsNotABackendError(): void
    {
        $handler = $this->liveRedisHandlerOrSkip();
        $session = $this->sessionWithRedisHandler($handler);
        $neverWritten = $this->randomAbsentSessionId('absent');

        $this->markLog();
        $session->start($neverWritten);

        $this->assertSame([], $session->all(), 'an absent session reads as empty');
        $this->assertSame(
            [],
            $this->loggedErrors(),
            'an empty (but successful) read must never be logged as a failure'
        );
    }

    /**
     * NEGATIVE CONTROL for the test above.
     *
     * "Logged zero errors" is also true of a backend that silently does nothing,
     * so on the same live server we prove a real value crosses a process
     * boundary: a SECOND Session on a SECOND handler resumes the id and finds it.
     */
    public function testNegativeControlLiveRedisReallyRoundTripsAcrossTwoSessions(): void
    {
        $writerHandler = $this->liveRedisHandlerOrSkip();
        $sessionId = $this->randomAbsentSessionId('roundtrip');

        try {
            $this->markLog();
            $writer = $this->sessionWithRedisHandler($writerHandler);
            // ADR-0021: start() DISCARDS a well-formed id the store has never
            // issued and mints its own, so an attacker cannot plant an id in the
            // victim's browser (session fixation). Read back under the id start()
            // actually returned - passing our own would read an id nothing was
            // ever written to, and the round-trip would look broken when it is not.
            $sessionId = $writer->start($sessionId);
            $writer->set('user_id', 42);
            $this->assertTrue($writer->save(), 'the write must genuinely reach Redis');

            $readerHandler = $this->liveRedisHandlerOrSkip();
            $reader = $this->sessionWithRedisHandler($readerHandler);
            $reader->start($sessionId);

            $this->assertSame(42, $reader->get('user_id'), 'the value never reached Redis');
            $this->assertSame([], $this->loggedErrors(), 'a healthy round-trip must log no errors');
        } finally {
            $writerHandler->destroy($sessionId);
        }
    }

    /**
     * NEGATIVE CONTROL: an uncontended gc() on a real, writable database must be
     * silent. Without this, a change that made EVERY gc() log an error would
     * still pass the gc-failure test above.
     */
    public function testNegativeControlUncontendedGcLogsNothing(): void
    {
        $session = $this->databaseSession($this->tempDir . '/gc-ok.db');
        $session->start('gc-ok');
        $session->set('k', 'v');
        $this->assertTrue($session->save());

        $this->markLog();
        $session->gc();

        $this->assertSame([], $this->loggedErrors(), 'a successful gc must log nothing');
    }

    // ── Invariant 5: TINA4_SESSION_STRICT=true re-raises the REAL error ─────

    /**
     * Locks in the calibration hazard the double hid.
     *
     * The deleted test asserted expectExceptionMessage('backend unreachable
     * (read)') — a string the TEST invented and no Tina4 code has ever produced.
     * The real driver raises \RuntimeException('Redis connection failed: [61]
     * Connection refused'), so that assertion was true only of the fake.
     */
    public function testStrictModeReRaisesTheRealDriverErrorOnRead(): void
    {
        putenv('TINA4_SESSION_STRICT=true');
        $_ENV['TINA4_SESSION_STRICT'] = 'true';

        $session = $this->unreachableRedisSession(); // ctor reads the strict flag

        try {
            $session->start('sess-strict-r');
            $this->fail('strict mode must re-raise a real backend read failure');
        } catch (\Throwable $caught) {
            $this->assertInstanceOf(\RuntimeException::class, $caught);
            $this->assertStringContainsString('Redis connection failed', $caught->getMessage());
            $this->assertStringContainsStringIgnoringCase(
                'refused',
                $caught->getMessage(),
                'regression guard: the re-raised error must be the REAL driver error, '
                . 'not a message a test invented'
            );
        }
    }

    public function testStrictModeReRaisesTheRealDriverErrorOnWrite(): void
    {
        putenv('TINA4_SESSION_STRICT=true');
        $_ENV['TINA4_SESSION_STRICT'] = 'true';

        $session = $this->unreachableRedisSession();
        $session->start(); // fresh — no read, so this does not raise

        try {
            $session->set('k', 'v'); // set() -> save() -> real refused write
            $this->fail('strict mode must re-raise a real backend write failure');
        } catch (\Throwable $caught) {
            $this->assertInstanceOf(\RuntimeException::class, $caught);
            $this->assertStringContainsString('Redis connection failed', $caught->getMessage());
        }
    }

    /**
     * NEGATIVE CONTROL: strict must not turn a WORKING backend into an error.
     * Without this, "strict raises" would still pass for an implementation that
     * raises unconditionally.
     */
    public function testNegativeControlStrictModeIsSilentOnAHealthyBackend(): void
    {
        $handler = $this->liveRedisHandlerOrSkip();

        putenv('TINA4_SESSION_STRICT=true');
        $_ENV['TINA4_SESSION_STRICT'] = 'true';

        $sessionId = $this->randomAbsentSessionId('strict-ok');
        try {
            $this->markLog();
            $session = $this->sessionWithRedisHandler($handler);
            $session->start($sessionId); // must not raise
            $session->set('ok', 1);

            $this->assertTrue($session->save());
            $this->assertSame([], $this->loggedErrors(), 'strict mode must be silent on a healthy backend');
        } finally {
            $handler->destroy($sessionId);
        }
    }

    // ── running as root is not an excuse: make the permission bits bite ─────
    //
    // Two cases here need a REAL EACCES / SQLITE_READONLY from the kernel, which
    // they produce with chmod 0400. Both used to open with
    //
    //     if (posix_geteuid() === 0) markTestSkipped('running as root: ...')
    //
    // and the lab runs its suites as root, so both skipped green on every run --
    // permanently, invisibly, and precisely on the two cases that prove the
    // framework does not silently lose session data. A skip that fires on the
    // machine you actually test on is a deleted test.
    //
    // THE FIX: when permission bits do not currently constrain us, re-run the
    // SAME test method in a child where they do, and assert on the child. The
    // child re-enters this method, finds that permissions now bite, and runs the
    // real assertions inline -- so there is exactly ONE copy of the assertions
    // and the two paths can never drift.
    //
    // HOW THE CHILD IS BUILT, AND WHY NOT setuid(nobody).
    // The obvious move is setpriv --reuid=nobody. Measured on this lab, it does
    // not work: the repo lives under /root, which is 0700, so an unprivileged
    // child cannot even read vendor/autoload.php, and the alternative is to
    // chmod o+x /root -- a permanent, system-wide weakening of a shared box, to
    // make a test pass. Rejected.
    //
    // What root actually has that defeats chmod is not uid 0, it is
    // CAP_DAC_OVERRIDE. So the child keeps uid 0 (it can still read the repo)
    // and drops the CAPABILITY: setpriv --securebits=+noroot,+noroot_locked
    // --bounding-set=-all --inh-caps=-all. With SECBIT_NOROOT set, execve grants
    // a uid-0 process no capabilities at all, so the kernel's ordinary DAC check
    // applies and chmod 0400 denies for real. Verified on the lab: CapEff goes
    // to 0000000000000000 and an append to a 0400 file returns EACCES. Nothing
    // is simulated -- the errno comes from the same kernel path a normal user
    // hits. setpriv is util-linux, present on every mainstream Linux.
    //
    // THE GATE IS MEASURED, NOT GUESSED. posix_geteuid() === 0 was always a
    // proxy for the real question; this asks the real question directly by
    // chmod-ing a probe file and trying to write it. That is also what makes the
    // child correct: inside it euid is STILL 0, so a euid test would have sent
    // it into an infinite delegation loop.

    /** Env marker telling a child it is the delegated, capability-dropped run. */
    private const PRIVILEGE_DROP_MARKER = 'TINA4_TEST_DAC_ENFORCED_CHILD';

    /** What the delegated child prints so the parent can prove the drop happened. */
    private const PRIVILEGE_DROP_SIGNAL = 'TINA4_DAC_INSTRUMENT';

    /**
     * Does a chmod 0400 actually deny THIS process a write?
     *
     * The direct question, asked of the real kernel with a real file. True for
     * any ordinary user; false for root holding CAP_DAC_OVERRIDE.
     */
    private static function filePermissionsAreEnforced(): bool
    {
        $probe = tempnam(sys_get_temp_dir(), 'tina4-dac-');
        if ($probe === false) {
            return true;   // cannot tell; assume the ordinary case
        }

        file_put_contents($probe, 'probe');
        chmod($probe, 0400);
        $handle = @fopen($probe, 'a');
        $enforced = $handle === false;
        if ($handle !== false) {
            fclose($handle);
        }
        @chmod($probe, 0600);
        @unlink($probe);

        return $enforced;
    }

    /**
     * TRUE when the caller should run its assertions inline (permissions bite
     * here). FALSE once they have been run in a capability-dropped child, which
     * this method has already asserted on -- the caller must simply return.
     */
    private function runsWhereFilePermissionsBite(string $testMethod): bool
    {
        if (self::filePermissionsAreEnforced()) {
            if (getenv(self::PRIVILEGE_DROP_MARKER) !== false) {
                // We ARE the delegated child. Report the instrument on stderr
                // (stderr, so PHPUnit does not flag unexpected test output) and
                // let the parent prove the drop really happened.
                fwrite(STDERR, sprintf(
                    "\n%s uid=%d enforced=1 capeff=%s\n",
                    self::PRIVILEGE_DROP_SIGNAL,
                    function_exists('posix_geteuid') ? posix_geteuid() : -1,
                    self::effectiveCapabilities()
                ));
            }

            return true;
        }

        $this->delegateToCapabilityDroppedChild($testMethod);

        return false;
    }

    /** This process's CapEff bitmask from /proc, or '' where /proc is absent. */
    private static function effectiveCapabilities(): string
    {
        foreach (explode("\n", (string)@file_get_contents('/proc/self/status')) as $line) {
            if (str_starts_with($line, 'CapEff:')) {
                return trim(substr($line, strlen('CapEff:')));
            }
        }

        return '';
    }

    /**
     * Re-run $testMethod in a child that has lost CAP_DAC_OVERRIDE, and assert
     * on its outcome.
     */
    private function delegateToCapabilityDroppedChild(string $testMethod): void
    {
        $setpriv = trim((string)@shell_exec('command -v setpriv 2>/dev/null'));
        if ($setpriv === '') {
            self::fail(
                'file permissions do not constrain this process (it is root with CAP_DAC_OVERRIDE), '
                . 'and setpriv is not installed, so the real EACCES this test exists to prove cannot '
                . 'be produced. Install util-linux, or run the suite as an ordinary user. This is '
                . 'deliberately a FAILURE and not a skip: a green skip here is how both of these '
                . 'cases sat dead on the lab for months.'
            );
        }

        $repoRoot = dirname(__DIR__);
        $environment = getenv();
        $environment[self::PRIVILEGE_DROP_MARKER] = '1';

        $command = [
            $setpriv,
            '--securebits=+noroot,+noroot_locked',
            '--bounding-set=-all',
            '--inh-caps=-all',
            PHP_BINARY,
            $repoRoot . '/vendor/bin/phpunit',
            '--no-configuration',
            '--bootstrap', $repoRoot . '/tests/bootstrap.php',
            '--colors=never',
            '--filter', '/::' . preg_quote($testMethod, '/') . '$/',
            __FILE__,
        ];

        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repoRoot,
            $environment
        );
        if (!is_resource($process)) {
            self::fail('could not start the capability-dropped child for ' . $testMethod);
        }

        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        // THE INSTRUMENT, FIRST. Without this a child that silently kept
        // CAP_DAC_OVERRIDE would "pass" while asserting nothing at all: its
        // chmod 0400 would not deny, so the very failure under test could not
        // occur and the run would still exit 0.
        $this->assertStringContainsString(
            self::PRIVILEGE_DROP_SIGNAL,
            $stderr,
            "the child never reported that file permissions constrain it, so it did not run the "
            . "case at all.\nexit: {$exitCode}\nstdout:\n{$stdout}\nstderr:\n{$stderr}"
        );
        $this->assertMatchesRegularExpression(
            '/' . self::PRIVILEGE_DROP_SIGNAL . ' uid=\d+ enforced=1 capeff=0+\b/',
            $stderr,
            'the child still holds effective capabilities, so chmod 0400 cannot deny it and the '
            . "EACCES under test could never happen.\nstderr:\n{$stderr}"
        );
        $this->assertSame(
            0,
            $exitCode,
            "{$testMethod} FAILED in the capability-dropped child (this is the run that actually "
            . "exercises it).\nstdout:\n{$stdout}\nstderr:\n{$stderr}"
        );
        // Exit 0 is also what a SKIPPED child produces, and a skip here would
        // mean the delegation reported success while asserting nothing. Demand
        // evidence that one test really ran and really asserted.
        //
        // Matched on PHPUnit's own summary line rather than "OK (1 test", because
        // this case legitimately provokes a PHP warning (file_put_contents on the
        // read-only file), so PHPUnit prints "OK, but there were issues!" instead
        // of "OK (1 test, ...)" - and the earlier, stricter assertion failed on a
        // child that had in fact passed with 8 assertions.
        $this->assertMatchesRegularExpression(
            '/^Tests: 1, Assertions: [1-9]\d*/m',
            $stdout,
            "the child exited 0 without running {$testMethod} to a real, asserting pass (a skip "
            . "exits 0 too), so the delegation proved nothing.\nstdout:\n{$stdout}\nstderr:\n{$stderr}"
        );
    }

    // ── the mid-request death case, produced rather than simulated ──────────

    /**
     * KNOWN FRAMEWORK BUG — this test is EXPECTED TO FAIL until it is fixed.
     * It is deliberately left failing rather than mocked away or deleted.
     *
     * A REAL file-backend session writes successfully, then the real session FILE
     * is made read-only so the NEXT write takes a real EACCES from the real
     * kernel. The documented policy is: log an error, return false from save(),
     * and RETAIN the dirty flag so a later save() retries.
     *
     * Measured on macOS + PHP 8.5.7, all three are violated, because
     * Tina4/Session.php:743 (saveToFile) ignores the return value of
     * file_put_contents(). PHP's file_put_contents does not throw on EACCES — it
     * emits a warning and returns false — so nothing ever reaches the
     * safeWrite() catch block:
     *
     *     save() returned            true   (documented: false)
     *     dirty flag                 CLEARED (documented: retained for retry)
     *     errors logged              NONE   (documented: log-loud)
     *     bytes on disk              the PREVIOUS write; the new data is GONE
     *
     * This is silent session data loss on the DEFAULT backend. The deleted
     * ThrowingSessionHandler hid it perfectly: it THREW, so the policy wrappers
     * looked exercised, while the real file backend can never throw at all.
     */
    public function testWriteFailsAfterASuccessfulStartWithARealEacces(): void
    {
        if (!$this->runsWhereFilePermissionsBite(__FUNCTION__)) {
            return;   // already asserted, in a child where permissions really bite
        }

        $sessionDir = $this->tempDir . '/sessions';
        $session = new Session('file', ['path' => $sessionDir, 'ttl' => 3600]);

        $sessionId = $session->start('sess-eacces');
        $session->set('stage', 'one');
        $this->assertTrue($session->save(), 'the first write must genuinely succeed');

        // The on-disk name is sha256(id).json, not the raw id (ADR-0021: a session
        // id is opaque and never becomes a path component). This test predates that
        // change, so the raw name would never exist and the EACCES it is trying to
        // provoke would never be reached.
        $sessionFile = $sessionDir . '/' . hash('sha256', $sessionId) . '.json';
        $this->assertFileExists($sessionFile, 'start()+save() must have created a real file');

        // 0400 on the FILE, not the directory: an existing path needs write
        // permission on the FILE itself; directory permission governs
        // create/unlink/rename and would NOT deny this write.
        chmod($sessionFile, 0400);
        $this->assertFalse(is_writable($sessionFile), 'precondition: the real file must really be read-only');

        $this->markLog();
        $session->set('stage', 'two');
        $saved = $session->save();

        $onDisk = (string) file_get_contents($sessionFile);
        chmod($sessionFile, 0600); // restore before any assertion can abort the test

        $this->assertFalse($saved, 'a real EACCES must be reported, not swallowed');
        $this->assertTrue($this->dirtyFlag($session), 'the dirty flag must be retained for a later retry');

        $errors = $this->loggedErrors();
        $this->assertNotEmpty(
            array_filter($errors, static fn (string $m) => str_contains($m, 'Session write failed')),
            'a real EACCES must be logged. Got: ' . json_encode($errors)
        );
        $this->assertStringNotContainsString(
            '"stage": "two"',
            $onDisk,
            'sanity: the write really did not land, so save() reporting true is data loss'
        );
    }
}
