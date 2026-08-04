<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * SESSION CONTRACT invariant 6: a zero-dependency transport in EVERY framework.
 *
 * ADR-0024 / session_contract.json #6: "Where three frameworks ship a
 * zero-dependency transport for a backend, the fourth does too. A backend must
 * not hard-require a third-party client in one framework and not the others."
 *
 * WHY THIS MATTERS, stated as the operator sees it: the SAME .env works in three
 * frameworks and raises in the fourth. Nothing about the configuration hints at
 * it, there is no warning to read, and the failure lands at Session construction
 * on the first request. An asymmetric broken promise is worse than a consistent
 * one, because nobody can plan around it.
 *
 * RUBY was the framework that FAILED this - its mongodb session handler did
 * `require "mongo"` and raised without the gem. PHP has shipped raw sockets the
 * whole time (fsockopen + the protocol, in every one of these handlers), so this
 * file is a PARITY LOCK-IN rather than a bug fix: it pins the property so PHP
 * cannot drift into the hole Ruby was just pulled out of.
 *
 * THE PHP-SPECIFIC PROBLEM, AND THE HONEST MEASUREMENT CHOSEN FOR IT.
 *
 *   Ruby's original makes the third-party client genuinely unavailable by
 *   pointing a real subprocess's GEM_HOME at an empty directory. PHP's optional
 *   clients are not gems, they are EXTENSIONS (ext-redis, ext-mongodb,
 *   ext-memcached), and an extension cannot be unloaded at runtime - so the
 *   Ruby technique does not transliterate.
 *
 *   MEASURED on the lab host (Ubuntu 24.04.4 LTS x86_64, PHP 8.3.6 CLI) before
 *   choosing an assertion:
 *
 *     parent process (php.ini loaded):  mongodb 2.3.3 LOADED
 *                                       redis      NOT installed
 *                                       memcached  NOT installed
 *                                       58 extensions loaded
 *     `php -n` (php.ini ignored):       mongodb / redis / memcached ALL absent
 *                                       16 extensions loaded
 *
 *   So "the extension happens not to be installed" would have been a true but
 *   ACCIDENTAL assertion for redis and memcached, and no assertion at all for
 *   mongodb, which IS loaded here. Two REAL subprocesses are used instead, and
 *   nothing in either is shimmed, stubbed, monkeypatched or faked - both are the
 *   real PHP binary with a real, smaller extension set:
 *
 *     CONSTRUCTION child: `php -n`. PHP ignores php.ini and every conf.d file,
 *       so no shared object at all is dlopen'd - no client, and no SQL driver
 *       either. This is the direct analogue of Ruby's empty GEM_HOME, and it is
 *       what proves a handler CLASS carries no client requirement of its own.
 *
 *     REQUEST-PATH child: PHP_INI_SCAN_DIR pointed at a copy of the host's own
 *       conf.d with EXACTLY the optional-client .ini files removed. The child
 *       therefore has the host's full extension set MINUS ext-mongodb,
 *       ext-redis and ext-memcached, which is precisely the counterfactual this
 *       invariant is about. `extension_loaded('mongodb')` is genuinely false
 *       because mongodb.so was never mapped, while everything the framework
 *       legitimately uses (the bundled SQL driver, ctype, mbstring) is present,
 *       so a real failure surfaces as itself instead of as a missing-function
 *       fatal.
 *
 *   Every subprocess SELF-VERIFIES the instrument and reports it, and every case
 *   asserts that FIRST: which optional client extensions it could see, and how
 *   many extensions it had in total. The count is checked EXACTLY - the
 *   request-path child must hold the parent's extension count minus the optional
 *   clients the parent actually has - so a child that quietly inherited the full
 *   set cannot pass every assertion below while measuring nothing at all. That
 *   is the same trap the counting listener in
 *   tests/SessionHandlerConstructionTest.php guards.
 *
 * TINA4_SESSION_STRICT=true IS SET IN EVERY SUBPROCESS, and it is load-bearing.
 * Session::safeRead/safeWrite catch Throwable, log, and degrade to an empty
 * session (ADR-0021). Without strict mode a genuinely dead backend would be
 * swallowed into a clean-looking [] and case 1 would go green against nothing at
 * all. Strict mode makes a real failure re-raise, so "it completed" is a fact
 * rather than a shrug.
 *
 * WHAT EACH CASE IS FOR, and why three are needed:
 *   1. the transport EXISTS for every backend and can be CONSTRUCTED and DRIVEN
 *      with no third-party client extension present. On its own this is
 *      satisfiable by a transport that accepts every call and does nothing,
 *      which is exactly why case 2 exists.
 *   2. the transport really WORKS: a session written through it is really in
 *      MongoDB, confirmed OUT OF BAND by an independent client (ext-mongodb's
 *      own driver, in this process, which shares not one line with Tina4's
 *      hand-rolled OP_MSG/BSON codec).
 *   3. NEGATIVE CONTROL: case 1's verdict is "no exception was raised", so it
 *      must be shown to DISCRIMINATE. A transport that does nothing writes no
 *      bytes, and a transport that cannot tell a dead endpoint from a live one
 *      reports success against a closed port. Both are measured here against
 *      real sockets this test owns.
 *
 * THE BOUND, STATED RATHER THAN HIDDEN: the `database` backend. Its third-party
 * client is the SQL driver. PHP bundles pdo_sqlite AND ext-sqlite3 in php-src
 * and enables them by default in a stock build (Debian merely packages them as
 * separate .so files), exactly as Ruby declares `sqlite3` a runtime dependency
 * of the gem. So case 1 proves what is actually in scope for THIS invariant in
 * two halves: DatabaseSessionHandler CONSTRUCTS in a process with no SQL driver
 * of any kind (it pulls in no client of its own, it defers to the Database
 * layer), and it round-trips for real against the BUNDLED sqlite3 driver. That
 * gap is a language fact, not this invariant.
 *
 * TWO FRAMEWORK FACTS MEASURED WHILE BUILDING THIS, reported rather than fixed
 * here (neither is a session-transport defect, both are real):
 *   * Tina4/Log.php:590 Log::coerceMessage() calls mb_check_encoding(), which
 *     is ext-mbstring. composer.json requires only ext-json, and mbstring is NOT
 *     enabled by default in a stock php-src build. On a PHP without it, ANY log
 *     write is a fatal Error - and because it fires inside Session::safeWrite's
 *     Log::error(), it converts a degradable backend failure into a fatal that
 *     MASKS the original cause. Measured exactly that way here: the real cause
 *     was invisible until mbstring was loaded.
 *   * Tina4/Database/SQLite3Adapter.php:57 resolveDatabasePath() calls
 *     ctype_alpha(), which is ext-ctype (bundled and default-on, so lower risk).
 *
 * NO MOCKS. Real php subprocesses, real sockets, real MongoDB, real Redis, real
 * Valkey, real Memcached, real SQLite. The recording listener in this file
 * speaks no protocol and pretends to be nothing - it is a socket, and the only
 * thing it reports is the bytes the kernel actually delivered to it.
 *
 * PARITY DRIFT FOUND WHILE WRITING THIS (reported, not fixed here): Ruby's
 * MongoHandler honours TINA4_SESSION_MONGO_COLLECTION; PHP's
 * MongoSessionHandler has no collection env var at all (only the 'collection'
 * config key), so the collection is always "sessions". This file isolates itself
 * with a unique DATABASE name instead.
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Session\MemcachedSessionHandler;
use Tina4\Session\MongoSessionHandler;
use Tina4\Session\RedisSessionHandler;
use Tina4\Session\ValkeySessionHandler;

/**
 * A REAL TCP server that RECORDS every byte a client sends it.
 *
 * It runs as a separate PHP process because it must accept, read and HANG UP
 * while the test is blocked inside the handler's own read. A single-threaded
 * in-process accept loop cannot do that.
 *
 * It answers NOTHING. It is not a fake MongoDB and not a fake Redis: it never
 * speaks a protocol, so no handler can be fooled into thinking it succeeded.
 * The only thing it can report is a fact the kernel decided - which bytes
 * actually left the client - and that is precisely the fact case 3 needs.
 */
final class WireRecordingTcpListener
{
    /**
     * The listener body, run by `php -r`. Kept inline so this suite is one file
     * with no fixture to drift from it.
     */
    private const LISTENER_SOURCE = <<<'LISTENER'
$wireLog = getenv('TINA4_WIRE_LOG');
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
    stream_set_timeout($client, 2);
    $captured = '';
    while (strlen($captured) < 262144) {
        $chunk = @fread($client, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $captured .= $chunk;
        $readable = [$client];
        $writable = null;
        $except = null;
        if (@stream_select($readable, $writable, $except, 0, 250000) < 1) {
            break;
        }
    }
    file_put_contents($wireLog, $captured, FILE_APPEND);
    fclose($client);   // accept, record, hang up
}
LISTENER;

    /** Long enough for the client's write to land and be read back off the wire. */
    private const SETTLE_MICROSECONDS = 600000;

    public readonly int $port;

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $wireLog;

    public function __construct()
    {
        $this->wireLog = (string)tempnam(sys_get_temp_dir(), 'tina4-wire-');
        file_put_contents($this->wireLog, '');

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, '-d', 'error_reporting=0', '-r', self::LISTENER_SOURCE],
            $descriptors,
            $this->pipes,
            null,
            ['TINA4_WIRE_LOG' => $this->wireLog, 'TINA4_PARENT_PID' => (string)getmypid()]
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('could not start the wire-recording TCP listener');
        }
        $this->process = $process;

        $announcement = (string)fgets($this->pipes[1]);
        if (!str_starts_with($announcement, 'PORT ')) {
            $this->close();
            throw new \RuntimeException('wire-recording TCP listener did not announce a port: ' . $announcement);
        }
        $this->port = (int)trim(substr($announcement, 5));
    }

    /**
     * How many bytes have been recorded so far (the read cursor for recordedSince).
     */
    public function recordedLength(): int
    {
        clearstatcache(true, $this->wireLog);
        return (int)@filesize($this->wireLog);
    }

    /**
     * Every byte recorded after $offset - i.e. the bytes one driven handler sent.
     */
    public function recordedSince(int $offset): string
    {
        $bytes = (string)@file_get_contents($this->wireLog);
        return substr($bytes, $offset);
    }

    /**
     * Give the client's write a real chance to land before it is read.
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
        @unlink($this->wireLog);
    }
}

class SessionZeroDependencyFallbackTest extends TestCase
{
    /** Every session backend Tina4 ships, in the order the contract lists them. */
    private const BACKENDS = ['file', 'redis', 'valkey', 'mongodb', 'memcached', 'database'];

    /** The optional third-party client EXTENSIONS this invariant is about. */
    private const OPTIONAL_CLIENT_EXTENSIONS = ['mongodb', 'redis', 'memcached'];

    /** @var string[] Files and directories this test created. */
    private array $temporaryPaths = [];

    /** Mongo database this run owns end to end, dropped in tearDown. */
    private string $mongoDatabase = '';

    protected function setUp(): void
    {
        $this->mongoDatabase = 'tina4_zero_dependency_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->dropMongoDatabase();

        foreach ($this->temporaryPaths as $path) {
            if (is_dir($path)) {
                foreach ((array)glob($path . '/*') as $child) {
                    @unlink((string)$child);
                }
                @rmdir($path);
                continue;
            }
            @unlink($path);
        }
        $this->temporaryPaths = [];
    }

    // ---- 1. EVERY BACKEND HAS A ZERO-DEPENDENCY TRANSPORT --------------------

    public function testEveryBackendHasAZeroDependencyTransport(): void
    {
        $this->requireAllServices();

        // --- half A: every handler CONSTRUCTS with no optional client and no
        // SQL driver of any kind. This is the half that answers "does the class
        // itself carry a third-party client requirement", and it is the one that
        // caught Ruby: its MongoHandler could not even be built.
        $constructionReport = $this->runWithNoSharedExtensionsAtAll(
            $this->constructionCaseSource(),
            $this->serviceEnvironment($this->temporaryDirectory('sessions-construct'))
        );
        $this->assertOptionalClientExtensionsReallyAbsent($constructionReport, null);
        $this->assertFalse(
            $constructionReport['instrument']['sqlite3_extension_loaded'],
            'the construction subprocess still had a SQL driver, so it cannot answer whether the '
            . 'DatabaseSessionHandler carries a client requirement of its own'
        );

        foreach ($constructionReport['handlers'] as $handler => $outcome) {
            $this->assertTrue(
                $outcome['ok'],
                "{$handler} could not even be CONSTRUCTED in a process with no optional client "
                . 'extension and no SQL driver at all (' . ($outcome['error'] ?? '') . '), so it '
                . 'carries a third-party client requirement of its own. Three frameworks ship a '
                . 'zero-dependency transport for every session backend; the fourth must too.'
            );
        }

        // --- half B: every backend is DRIVEN end to end through Tina4\Session,
        // which is the code a REQUEST runs. A fallback that is correct on a
        // directly-constructed handler and bypassed on the request path is the
        // failure mode this file exists to catch. This child keeps the host's
        // whole extension set except the optional clients, so the bundled SQL
        // driver is there for the database backend exactly as it is in a stock
        // PHP build, and a real failure surfaces as itself.
        $sessionDirectory = $this->temporaryDirectory('sessions-drive');
        $databaseFile = $this->temporaryDirectory('database') . '/sessions.db';
        $this->temporaryPaths[] = $databaseFile;

        $environment = $this->serviceEnvironment($sessionDirectory);
        $environment['TINA4_DATABASE_URL'] = 'sqlite:///' . $databaseFile;

        $report = $this->runWithOptionalClientExtensionsRemoved($this->requestPathCaseSource(), $environment);
        $this->assertOptionalClientExtensionsReallyAbsent($report, $this->expectedChildExtensionCount());
        $this->assertTrue(
            $report['instrument']['sqlite3_extension_loaded'],
            'the bundled sqlite3 driver did not load in the subprocess, so the database backend '
            . 'was never actually exercised and its leg below would be measuring nothing'
        );

        $failures = [];
        foreach ($report['backends'] as $name => $outcome) {
            if (!$outcome['ok']) {
                $failures[] = "{$name} ({$outcome['error']})";
            }
        }
        $this->assertSame(
            [],
            $failures,
            'with NO third-party client extension available, these session backends could not be '
            . 'constructed and used at all: ' . implode('; ', $failures)
            . '. Three frameworks ship a zero-dependency transport for every one of these; the '
            . 'fourth must too, or the same .env works in three and raises in one.'
        );

        // The mongodb leg must have resolved to the ZERO-DEPENDENCY transport.
        // On this host ext-mongodb IS installed, so without this assertion the
        // case could be measuring the extension while reporting a green.
        $this->assertSame(
            MongoSessionHandler::class,
            $report['backends']['mongodb']['transport'],
            'the mongodb backend resolved to '
            . var_export($report['backends']['mongodb']['transport'], true)
            . ' in a process with no mongodb extension at all - the zero-dependency transport is '
            . 'not the one the request path actually took.'
        );
        $this->assertSame(
            RedisSessionHandler::class,
            $report['backends']['redis']['transport'],
            'the redis backend did not resolve to the raw-socket RESP handler'
        );
        $this->assertSame(
            ValkeySessionHandler::class,
            $report['backends']['valkey']['transport'],
            'the valkey backend did not resolve to the raw-socket RESP handler'
        );
        $this->assertSame(
            MemcachedSessionHandler::class,
            $report['backends']['memcached']['transport'],
            'the memcached backend did not resolve to the raw-socket text-protocol handler'
        );
    }

    // ---- 2. THE ZERO-DEPENDENCY TRANSPORT REALLY WORKS -----------------------
    //
    // Case 1 is satisfiable by a transport that accepts every call and does
    // nothing. This is the case that is not. It writes through the hand-rolled
    // OP_MSG wire protocol, reads back through a FRESH Session (therefore a
    // fresh handler and a fresh socket), and then asks MongoDB itself - through
    // an independent client that shares no code with the transport under test -
    // whether the document is really there.

    public function testTheZeroDependencyTransportReallyRoundTrips(): void
    {
        $this->requireReachable('mongodb', $this->mongoHost(), $this->mongoPort());
        $this->requireMongoDbExtension();

        $sessionId = 'zerodeproundtrip' . bin2hex(random_bytes(12));
        $payload = [
            'user' => 'andre',
            'roles' => ['admin', 'editor'],
            'visits' => 42,
            'score' => 9.5,
            'verified' => true,
            'note' => 'unicode: eee and a quote " inside',
        ];

        $report = $this->runWithOptionalClientExtensionsRemoved(
            $this->roundTripCaseSource($sessionId, $payload),
            $this->serviceEnvironment($this->temporaryDirectory('sessions-roundtrip'))
        );
        $this->assertOptionalClientExtensionsReallyAbsent($report, $this->expectedChildExtensionCount());

        $result = $report['result'];
        $this->assertTrue(
            $result['ok'],
            'the zero-dependency MongoDB transport failed outright: ' . ($result['error'] ?? '')
        );
        $this->assertSame(
            MongoSessionHandler::class,
            $result['transport'],
            'the session did not resolve to the zero-dependency transport at all (got '
            . var_export($result['transport'], true) . '), so this case proved nothing about it'
        );

        // Session::read() re-stamps _meta.last_accessed on the way out (it is
        // the Session layer's bookkeeping, not the transport's), so that one key
        // is separated before the payload is compared byte for byte.
        $this->assertIsArray(
            $result['read'],
            'the session did not come back AT ALL through a FRESH handler - Session::read returned '
            . var_export($result['read'], true) . '. A transport that accepts writes and answers '
            . 'reads with nothing looks identical to a working one until this assertion runs.'
        );
        $readBack = $result['read'];
        $this->assertArrayHasKey(
            '_meta',
            $readBack,
            'the read did not come back through Session::read at all, so this is not the request path'
        );
        unset($readBack['_meta']);

        $this->assertSame(
            $payload,
            $readBack,
            'a session written through the zero-dependency MongoDB transport did not read back '
            . 'through a FRESH handler. Got ' . var_export($readBack, true) . '. A transport '
            . 'that accepts writes and answers reads with nothing looks identical to a working one '
            . 'until this assertion runs.'
        );

        // OUT OF BAND. Tina4 is no longer involved: this is ext-mongodb's own
        // driver asking the real server what is actually stored. It shares not
        // one line with the hand-rolled OP_MSG/BSON codec under test, so it is
        // the only assertion here that a stub cannot satisfy by lying
        // consistently.
        $document = $this->findDocumentWithIndependentClient($sessionId);
        $this->assertNotNull(
            $document,
            "an INDEPENDENT MongoDB client cannot find _id={$sessionId} in "
            . "{$this->mongoDatabase}.sessions. Tina4 reported a successful round trip, so the "
            . 'zero-dependency transport is not talking to MongoDB at all.'
        );
        $this->assertEquals(
            $payload,
            $document['data'],
            'the document really in MongoDB does not carry the payload that was written: '
            . var_export($document['data'] ?? null, true)
        );
        $this->assertGreaterThan(
            microtime(true),
            (float)$document['expires_at'],
            'the stored absolute deadline is not in the future, so the wire transport wrote a '
            . 'record that is already expired'
        );
    }

    // ---- 3. NEGATIVE CONTROL: CASE 1'S VERDICT MUST DISCRIMINATE -------------
    //
    // Case 1's verdict is "nothing raised". That is satisfiable by a transport
    // that accepts every call and does nothing, and by one that cannot tell a
    // live backend from a dead one. Both are ruled out here, against real
    // sockets this test owns:
    //
    //   (a) WIRE BYTES. Point each zero-dependency handler at a real listener
    //       that records and answers nothing, drive one real write, and read
    //       back what the kernel actually delivered. It has to be the genuine
    //       protocol frame, carrying Tina4's own session key. A transport that
    //       does nothing sends zero bytes and cannot pass this.
    //
    //   (b) DEAD ENDPOINT. Point each handler at a port where nothing listens
    //       and it must RAISE. A transport that reports success against a closed
    //       port would make case 1 green against a backend that does not exist.

    public function testTheThirdPartyClientIsStillUsedWhenPresent(): void
    {
        $listener = new WireRecordingTcpListener();

        try {
            foreach ($this->wireExpectations($listener->port) as $name => $expectation) {
                $offset = $listener->recordedLength();

                try {
                    ($expectation['drive'])();
                } catch (\Throwable $expected) {
                    // The listener speaks no protocol and hangs up, so every
                    // handler fails its READ. That is the point: we are
                    // measuring the bytes that went OUT, not a conversation.
                }
                $listener->settle();
                $sent = $listener->recordedSince($offset);

                $this->assertNotSame(
                    '',
                    $sent,
                    "{$name}: the transport sent ZERO bytes to a real socket it was pointed at. A "
                    . 'transport that does nothing raises nothing either, so case 1 would report it '
                    . 'green while nothing at all reached the backend.'
                );

                foreach ($expectation['contains'] as $needle) {
                    $this->assertStringContainsString(
                        $needle,
                        $sent,
                        "{$name}: the bytes that actually reached the socket are not the protocol "
                        . 'frame this backend claims to speak (missing ' . var_export($needle, true)
                        . '). Recorded prefix: ' . var_export(substr($sent, 0, 96), true)
                    );
                }

                if (isset($expectation['opcode'])) {
                    $this->assertGreaterThanOrEqual(16, strlen($sent), "{$name}: no wire header was sent");
                    $header = unpack('VmsgLen/VrequestId/VresponseTo/Vopcode', substr($sent, 0, 16));
                    $this->assertSame(
                        $expectation['opcode'],
                        $header['opcode'],
                        "{$name}: the message on the wire is not the opcode this transport claims "
                        . "to speak (got {$header['opcode']})"
                    );
                    $this->assertSame(
                        strlen($sent),
                        $header['msgLen'],
                        "{$name}: the declared message length does not match the bytes actually "
                        . 'sent, so this is not a well-formed frame'
                    );
                }
            }
        } finally {
            $listener->close();
        }

        // (b) A port nobody is listening on. Reserved and released by the OS, so
        // the refusal is real rather than assumed.
        $deadPort = \FreePort::get();
        foreach ($this->deadEndpointHandlers($deadPort) as $name => $build) {
            $handler = $build();
            $raised = null;
            try {
                $handler->write('deadendpoint' . bin2hex(random_bytes(6)), ['probe' => true], 60);
            } catch (\Throwable $failure) {
                $raised = $failure;
            }

            $this->assertNotNull(
                $raised,
                "{$name}: writing to a port where NOTHING is listening reported success. A "
                . 'transport that cannot tell a dead backend from a live one makes case 1 vacuous: '
                . 'it would go green with every service switched off.'
            );
        }
    }

    // ---- the instrument ------------------------------------------------------

    /**
     * Run $source in a REAL php subprocess with NO shared extension at all.
     *
     * `-n` makes PHP ignore php.ini and every conf.d file, so nothing is
     * dlopen'd: no optional client, and no SQL driver either. This is the direct
     * analogue of Ruby's empty GEM_HOME. No function is patched, nothing is
     * faked, and `extension_loaded()` really answers false because the shared
     * object was never mapped.
     *
     * @param string                $source      PHP source for the child (no leading tag).
     * @param array<string, string> $environment The child's COMPLETE environment.
     * @return array<string, mixed> The decoded TINA4_REPORT payload.
     */
    private function runWithNoSharedExtensionsAtAll(string $source, array $environment): array
    {
        return $this->runChild([PHP_BINARY, '-n', '-d', 'display_startup_errors=0'], $source, $environment);
    }

    /**
     * Run $source in a REAL php subprocess holding this host's whole extension
     * set MINUS exactly the optional client extensions.
     *
     * PHP_INI_SCAN_DIR is pointed at a copy of the host's own conf.d with the
     * .ini files that load ext-mongodb / ext-redis / ext-memcached left out, so
     * those shared objects are never dlopen'd while everything the framework
     * legitimately uses stays present. That is exactly the counterfactual this
     * invariant is about, and it keeps a real failure legible instead of turning
     * it into a missing-function fatal.
     *
     * @param string                $source      PHP source for the child (no leading tag).
     * @param array<string, string> $environment The child's COMPLETE environment.
     * @return array<string, mixed> The decoded TINA4_REPORT payload.
     */
    private function runWithOptionalClientExtensionsRemoved(string $source, array $environment): array
    {
        $environment['PHP_INI_SCAN_DIR'] = $this->scanDirectoryWithoutOptionalClients();

        return $this->runChild([PHP_BINARY, '-d', 'display_startup_errors=0'], $source, $environment);
    }

    /**
     * A copy of this host's conf.d holding every .ini EXCEPT the ones that load
     * an optional client extension.
     *
     * Each file is inspected for its own `extension=` / `zend_extension=` line,
     * so the decision is made on what the file actually loads rather than on its
     * name.
     */
    private function scanDirectoryWithoutOptionalClients(): string
    {
        $target = $this->temporaryDirectory('conf.d');
        $source = PHP_CONFIG_FILE_SCAN_DIR;

        foreach ((array)glob(rtrim($source, '/') . '/*.ini') as $file) {
            $file = (string)$file;
            $body = (string)@file_get_contents($file);

            $loaded = [];
            if (preg_match_all('/^\s*(?:zend_)?extension\s*=\s*"?([^"\s;]+)/mi', $body, $matches)) {
                foreach ($matches[1] as $name) {
                    $loaded[] = strtolower(preg_replace('/\.so$/i', '', basename($name)));
                }
            }

            if (array_intersect($loaded, self::OPTIONAL_CLIENT_EXTENSIONS) !== []) {
                continue;   // this file loads an optional client - leave it out
            }
            copy($file, $target . '/' . basename($file));
        }

        return $target;
    }

    /**
     * Start a real php subprocess, run it to completion, return its report.
     *
     * @param string[]              $command     The php binary plus its flags.
     * @param string                $source      PHP source for the child (no leading tag).
     * @param array<string, string> $environment The child's COMPLETE environment.
     * @return array<string, mixed> The decoded TINA4_REPORT payload.
     */
    private function runChild(array $command, string $source, array $environment): array
    {
        $script = $this->temporaryDirectory('child') . '/case.php';
        $this->temporaryPaths[] = $script;
        file_put_contents($script, "<?php\n" . $source);
        $command[] = $script;

        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            sys_get_temp_dir(),   // never the repo, so no stray .env is read
            $environment
        );
        if (!is_resource($process)) {
            $this->fail('could not start the no-optional-client php subprocess');
        }

        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $report = null;
        foreach (explode("\n", $stdout) as $line) {
            if (str_starts_with($line, 'TINA4_REPORT ')) {
                $report = json_decode(substr($line, strlen('TINA4_REPORT ')), true);
            }
        }

        if (!is_array($report)) {
            $this->fail(
                "the no-optional-client subprocess reported nothing.\nexit: {$exitCode}\n"
                . "stdout:\n{$stdout}\nstderr:\n{$stderr}"
            );
        }

        return $report;
    }

    /**
     * Assert the subprocess really had no optional client extension.
     *
     * Every case calls this first: the whole file's meaning depends on it.
     *
     * $expectedExtensionCount is what makes it more than a formality. For the
     * request-path child it is THIS process's extension count minus the optional
     * clients this process actually has, so a child that quietly inherited the
     * full set is caught even on a host where none of the three is installed and
     * the absence assertions above would pass for free.
     *
     * @param array<string, mixed> $report                The child's TINA4_REPORT payload.
     * @param int|null             $expectedExtensionCount Exact count, or null for "fewer than here".
     */
    private function assertOptionalClientExtensionsReallyAbsent(array $report, ?int $expectedExtensionCount): void
    {
        $instrument = $report['instrument'];

        foreach (self::OPTIONAL_CLIENT_EXTENSIONS as $extension) {
            $this->assertFalse(
                $instrument["{$extension}_extension_loaded"],
                "the subprocess could still load ext-{$extension}, so it measured the EXTENSION "
                . 'path while claiming to measure the zero-dependency one. Every assertion in this '
                . 'case would be vacuous.'
            );
        }

        if ($expectedExtensionCount === null) {
            $this->assertLessThan(
                count(get_loaded_extensions()),
                $instrument['loaded_extension_count'],
                'the subprocess loaded as many extensions as this process ('
                . $instrument['loaded_extension_count'] . ' vs ' . count(get_loaded_extensions())
                . '), so `php -n` did not actually strip anything and the instrument is inert.'
            );
            return;
        }

        $this->assertSame(
            $expectedExtensionCount,
            $instrument['loaded_extension_count'],
            'the subprocess holds ' . $instrument['loaded_extension_count'] . ' extensions where '
            . "exactly {$expectedExtensionCount} were expected (this process has "
            . count(get_loaded_extensions()) . ', minus the optional clients it has). Either the '
            . 'child inherited the full conf.d - in which case every assertion below is vacuous - '
            . 'or the filtered scan dir dropped something it should not have.'
        );
    }

    /**
     * The extension count the request-path child must have: a BASELINE CHILD's
     * count, less exactly the optional client extensions that child holds.
     *
     * This used to be count(get_loaded_extensions()) in the PARENT, and that is
     * a real trap. PHPUnit's parent fixes its extension set once, at startup;
     * every child re-reads conf.d when it is spawned. Anything that changes
     * conf.d mid-run therefore breaks the arithmetic while nothing is actually
     * wrong. It happened for real on 2026-08-05: ext-interbase was installed
     * WHILE this suite was in flight, the parent had 57 extensions and the child
     * found 58, and this file failed with "57 extensions where exactly 56 were
     * expected" - a false red on a genuinely healthy tree.
     *
     * Measuring the baseline with a child too keeps both numbers on the same
     * side of that boundary. The assertion stays EXACT, which is the whole point
     * of it - loosening it to a range is what would make this file vacuous.
     */
    private function expectedChildExtensionCount(): int
    {
        $baseline = PhpChild::childExtensionBaseline(self::OPTIONAL_CLIENT_EXTENSIONS);

        return PhpChild::expectedCountWithout($baseline, self::OPTIONAL_CLIENT_EXTENSIONS);
    }

    // ---- the child programs --------------------------------------------------

    /**
     * The preamble every child runs: prove the extensions really are gone, and
     * say so in the report, BEFORE anything is measured.
     */
    private function instrumentSelfCheckSource(): string
    {
        return <<<'CHILD'
        require getenv('TINA4_AUTOLOAD');

        $instrument = [
            'mongodb_extension_loaded' => extension_loaded('mongodb'),
            'redis_extension_loaded' => extension_loaded('redis'),
            'memcached_extension_loaded' => extension_loaded('memcached'),
            'sqlite3_extension_loaded' => extension_loaded('sqlite3'),
            'loaded_extension_count' => count(get_loaded_extensions()),
        ];

        /** Which Tina4\Session\* handler object a Session actually resolved to. */
        $transportOf = static function (object $session): ?string {
            foreach ((new \ReflectionObject($session))->getProperties() as $property) {
                if (!$property->isInitialized($session)) {
                    continue;
                }
                $value = $property->getValue($session);
                if (is_object($value) && str_starts_with(get_class($value), 'Tina4\\Session\\')) {
                    return get_class($value);
                }
            }
            return null;
        };

        CHILD;
    }

    /**
     * Child program: can every handler be CONSTRUCTED with nothing installed?
     */
    private function constructionCaseSource(): string
    {
        return $this->instrumentSelfCheckSource() . <<<'CHILD'
        $handlers = [];
        $builders = [
            'Tina4\\Session\\RedisSessionHandler' => static fn() => new \Tina4\Session\RedisSessionHandler(['ttl' => 120]),
            'Tina4\\Session\\ValkeySessionHandler' => static fn() => new \Tina4\Session\ValkeySessionHandler(['ttl' => 120]),
            'Tina4\\Session\\MemcachedSessionHandler' => static fn() => new \Tina4\Session\MemcachedSessionHandler(['ttl' => 120]),
            'Tina4\\Session\\MongoSessionHandler' => static fn() => new \Tina4\Session\MongoSessionHandler(['ttl' => 120]),
            // The database handler's client is the SQL DRIVER, and this child has
            // none at all. What IS in scope for this invariant is that the handler
            // pulls in no client of its OWN, so it must construct here.
            'Tina4\\Session\\DatabaseSessionHandler' => static fn() => new \Tina4\Session\DatabaseSessionHandler(['ttl' => 120]),
            // The file backend has no handler class; Session itself is it.
            'Tina4\\Session' => static fn() => new \Tina4\Session('file'),
        ];

        foreach ($builders as $name => $build) {
            try {
                $build();
                $handlers[$name] = ['ok' => true];
            } catch (\Throwable $error) {
                $handlers[$name] = ['ok' => false, 'error' => get_class($error) . ': ' . $error->getMessage()];
            }
        }

        echo 'TINA4_REPORT ' . json_encode(['instrument' => $instrument, 'handlers' => $handlers]) . "\n";
        CHILD;
    }

    /**
     * Child program: drive EVERY backend through the request path, for real.
     */
    private function requestPathCaseSource(): string
    {
        return $this->instrumentSelfCheckSource() . <<<'CHILD'
        $backends = [];

        // Driven through Tina4\Session, NOT through a directly-constructed
        // handler, because Session's own dispatch is the code a REQUEST runs. A
        // fallback that is correct on the object and bypassed on the request
        // path is the failure mode this file exists to catch.
        foreach (['file', 'redis', 'valkey', 'mongodb', 'memcached', 'database'] as $name) {
            putenv("TINA4_SESSION_BACKEND={$name}");
            $sessionId = 'zerodep' . $name . bin2hex(random_bytes(8));
            try {
                $writer = new \Tina4\Session();
                $writer->write($sessionId, ['backend' => $name], 120);
                $transport = $transportOf($writer);

                // A FRESH Session, therefore a fresh handler and a fresh socket.
                $reader = new \Tina4\Session();
                $reader->read($sessionId);

                // gc too, so the sweep really goes over the zero-dependency wire
                // rather than shipping as the one operation nothing ever runs. It
                // removes only records whose deadline has already passed, so the
                // 120s session just written is never a candidate.
                $writer->gc(3600);

                $backends[$name] = ['ok' => true, 'transport' => $transport];
            } catch (\Throwable $error) {
                $backends[$name] = ['ok' => false, 'error' => get_class($error) . ': ' . $error->getMessage()];
            }
        }

        echo 'TINA4_REPORT ' . json_encode(['instrument' => $instrument, 'backends' => $backends]) . "\n";
        CHILD;
    }

    /**
     * Child program: one real MongoDB round trip over the zero-dependency wire.
     *
     * @param array<string, mixed> $payload
     */
    private function roundTripCaseSource(string $sessionId, array $payload): string
    {
        $constants = '$sessionId = ' . var_export($sessionId, true) . ";\n"
            . '$payload = json_decode(' . var_export(json_encode($payload), true) . ", true);\n";

        return $this->instrumentSelfCheckSource() . $constants . <<<'CHILD'
        putenv('TINA4_SESSION_BACKEND=mongodb');

        try {
            $writer = new \Tina4\Session();
            $writer->write($sessionId, $payload, 300);
            $transport = $transportOf($writer);

            // A FRESH Session, therefore a FRESH handler and a FRESH socket. A
            // transport that only remembers what it was just handed cannot pass
            // this; the value has to come back off the wire.
            $reader = new \Tina4\Session();
            $result = ['ok' => true, 'transport' => $transport, 'read' => $reader->read($sessionId)];
        } catch (\Throwable $error) {
            $result = ['ok' => false, 'error' => get_class($error) . ': ' . $error->getMessage()];
        }

        echo 'TINA4_REPORT ' . json_encode(['instrument' => $instrument, 'result' => $result]) . "\n";
        CHILD;
    }

    // ---- case 3 fixtures -----------------------------------------------------

    /**
     * Each zero-dependency transport, what drives it, and what its real protocol
     * frame must contain. The key prefix is asserted on purpose: it proves the
     * recorded bytes are Tina4's own session write rather than any traffic.
     *
     * @return array<string, array{drive: callable(): void, contains: string[], opcode?: int}>
     */
    private function wireExpectations(int $port): array
    {
        $sessionId = 'wireprobe' . bin2hex(random_bytes(6));
        $data = ['probe' => 'zero-dependency'];

        return [
            'redis' => [
                'drive' => static function () use ($port, $sessionId, $data): void {
                    (new RedisSessionHandler(['host' => '127.0.0.1', 'port' => $port, 'ttl' => 60]))
                        ->write($sessionId, $data, 60);
                },
                'contains' => ["*4\r\n", 'SETEX', 'tina4:session:' . $sessionId, 'zero-dependency'],
            ],
            'valkey' => [
                'drive' => static function () use ($port, $sessionId, $data): void {
                    (new ValkeySessionHandler(['host' => '127.0.0.1', 'port' => $port, 'ttl' => 60]))
                        ->write($sessionId, $data, 60);
                },
                'contains' => ["*4\r\n", 'SETEX', 'tina4:session:' . $sessionId, 'zero-dependency'],
            ],
            'memcached' => [
                'drive' => static function () use ($port, $sessionId, $data): void {
                    (new MemcachedSessionHandler(['host' => '127.0.0.1', 'port' => $port, 'ttl' => 60]))
                        ->write($sessionId, $data, 60);
                },
                'contains' => ['set tina4:session:' . $sessionId, 'zero-dependency'],
            ],
            'mongodb' => [
                'drive' => static function () use ($port, $sessionId, $data): void {
                    (new MongoSessionHandler(['url' => "mongodb://127.0.0.1:{$port}", 'ttl' => 60]))
                        ->write($sessionId, $data, 60);
                },
                // OP_MSG carrying the real update command for the sessions
                // collection, with this session id as the _id being upserted.
                'contains' => ['update', 'sessions', $sessionId, 'zero-dependency'],
                'opcode' => 2013,
            ],
        ];
    }

    /**
     * The same transports, pointed at a port where nothing listens.
     *
     * @return array<string, callable(): object>
     */
    private function deadEndpointHandlers(int $port): array
    {
        return [
            'redis' => static fn() => new RedisSessionHandler(['host' => '127.0.0.1', 'port' => $port, 'ttl' => 60]),
            'valkey' => static fn() => new ValkeySessionHandler(['host' => '127.0.0.1', 'port' => $port, 'ttl' => 60]),
            'memcached' => static fn() => new MemcachedSessionHandler(['host' => '127.0.0.1', 'port' => $port, 'ttl' => 60]),
            'mongodb' => static fn() => new MongoSessionHandler(['url' => "mongodb://127.0.0.1:{$port}", 'ttl' => 60]),
        ];
    }

    // ---- the independent MongoDB observer ------------------------------------

    /**
     * Read a session document with ext-mongodb's OWN driver.
     *
     * This shares not one line with Tina4's hand-rolled OP_MSG/BSON codec, which
     * is exactly why it can answer for it.
     *
     * @return array<string, mixed>|null
     */
    private function findDocumentWithIndependentClient(string $sessionId): ?array
    {
        $manager = new \MongoDB\Driver\Manager($this->mongoUri());
        $cursor = $manager->executeQuery(
            $this->mongoDatabase . '.sessions',
            new \MongoDB\Driver\Query(['_id' => $sessionId])
        );

        foreach ($cursor as $document) {
            return json_decode(json_encode($document), true);
        }
        return null;
    }

    /**
     * Never let cleanup mask a case's own result.
     */
    private function dropMongoDatabase(): void
    {
        if ($this->mongoDatabase === '' || !extension_loaded('mongodb')) {
            return;
        }
        try {
            (new \MongoDB\Driver\Manager($this->mongoUri()))->executeCommand(
                $this->mongoDatabase,
                new \MongoDB\Driver\Command(['dropDatabase' => 1])
            );
        } catch (\Throwable) {
            // cleanup only
        }
    }

    // ---- environment ---------------------------------------------------------

    private function mongoHost(): string
    {
        return getenv('TINA4_TEST_MONGO_HOST') ?: '127.0.0.1';
    }

    private function mongoPort(): int
    {
        return (int)(getenv('TINA4_TEST_MONGO_PORT') ?: 27017);
    }

    private function mongoUri(): string
    {
        return getenv('TINA4_TEST_MONGO_URI') ?: "mongodb://{$this->mongoHost()}:{$this->mongoPort()}";
    }

    /**
     * The COMPLETE environment each child runs with. Nothing is inherited, so a
     * stray TINA4_* in this process can never steer the measurement.
     *
     * @return array<string, string>
     */
    private function serviceEnvironment(string $sessionDirectory): array
    {
        return [
            'PATH' => (string)(getenv('PATH') ?: '/usr/bin:/bin'),
            'TINA4_AUTOLOAD' => dirname(__DIR__) . '/vendor/autoload.php',
            // LOAD-BEARING: without strict mode a dead backend degrades into a
            // clean-looking empty session and every case below goes green
            // against nothing at all.
            'TINA4_SESSION_STRICT' => 'true',
            'TINA4_DEBUG' => 'false',
            'TINA4_LOG_OUTPUT' => 'stdout',
            'TINA4_SESSION_PATH' => $sessionDirectory,
            'TINA4_SESSION_TTL' => '300',
            'TINA4_SESSION_REDIS_HOST' => getenv('TINA4_TEST_REDIS_HOST') ?: '127.0.0.1',
            'TINA4_SESSION_REDIS_PORT' => (string)((int)(getenv('TINA4_TEST_REDIS_PORT') ?: 6379)),
            'TINA4_SESSION_VALKEY_HOST' => getenv('TINA4_TEST_VALKEY_HOST') ?: '127.0.0.1',
            'TINA4_SESSION_VALKEY_PORT' => (string)((int)(getenv('TINA4_TEST_VALKEY_PORT') ?: 6380)),
            'TINA4_SESSION_MEMCACHED_HOST' => getenv('TINA4_TEST_MEMCACHED_HOST') ?: '127.0.0.1',
            'TINA4_SESSION_MEMCACHED_PORT' => (string)((int)(getenv('TINA4_TEST_MEMCACHED_PORT') ?: 11211)),
            'TINA4_SESSION_MONGO_URI' => $this->mongoUri(),
            'TINA4_SESSION_MONGO_DB' => $this->mongoDatabase,
        ];
    }

    /**
     * A temp directory tearDown removes.
     */
    private function temporaryDirectory(string $label): string
    {
        $path = sys_get_temp_dir() . "/tina4-zero-dep-{$label}-" . bin2hex(random_bytes(4));
        mkdir($path, 0777, true);
        $this->temporaryPaths[] = $path;
        return $path;
    }

    // ---- service gates -------------------------------------------------------

    private function requireAllServices(): void
    {
        $this->requireReachable('redis', getenv('TINA4_TEST_REDIS_HOST') ?: '127.0.0.1', (int)(getenv('TINA4_TEST_REDIS_PORT') ?: 6379));
        $this->requireReachable('valkey', getenv('TINA4_TEST_VALKEY_HOST') ?: '127.0.0.1', (int)(getenv('TINA4_TEST_VALKEY_PORT') ?: 6380));
        $this->requireReachable('memcached', getenv('TINA4_TEST_MEMCACHED_HOST') ?: '127.0.0.1', (int)(getenv('TINA4_TEST_MEMCACHED_PORT') ?: 11211));
        $this->requireReachable('mongodb', $this->mongoHost(), $this->mongoPort());
    }

    /**
     * ext-mongodb is the INDEPENDENT observer case 2 checks MongoDB with. It is a
     * provisioned client on any machine that runs these services, so a missing
     * one is not a free pass.
     */
    private function requireMongoDbExtension(): void
    {
        if (extension_loaded('mongodb')) {
            return;
        }

        $message = 'the mongodb client extension is not installed, so no independent MongoDB '
            . 'client is available to verify the write out of band';
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
