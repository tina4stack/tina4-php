<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * SESSION CONTRACT: TINA4_SESSION_TTL is honoured by EVERY backend.
 *
 * ADR-0024: swapping file for redis, valkey, mongodb, memcached or database
 * changes ONE env var and NOTHING ELSE. The configured session lifetime is part
 * of that contract. An operator who sets a 15-minute session must GET a
 * 15-minute session, whichever backend is selected.
 *
 * WHY THIS FILE EXISTS. This is a PARITY LOCK-IN, not a bug fix. The same
 * invariant was found BROKEN in Ruby on 2026-08-04 (five of six handlers
 * hard-coded 86400, and Session#save dropped the ttl on the way to the store,
 * so TINA4_SESSION_TTL=900 produced a cookie saying Max-Age=900 and a record
 * that lived 24 hours). PHP already reads getenv('TINA4_SESSION_TTL') in every
 * handler and Session passes $this->ttl on all six saveTo* paths, so these four
 * cases are expected to pass on the first run. They exist so the Ruby defect
 * cannot be introduced here later: a session that does not expire when told is
 * an AUTH outcome, not a storage one, which is why it is held to a security bar.
 *
 * NO MOCKS. Every backend here is the real service (real Redis, real Valkey,
 * real memcached, real MongoDB, a real SQLite file, real files on disk) and
 * every expiry is real wall-clock time - no clock stubbing, no doubles. A skip
 * names the service and "not reachable" so TINA4_REQUIRE_SERVICES turns it into
 * a FAILURE; a run with skips is not verification.
 *
 * THE FOUR CASES, and why each is load-bearing:
 *   1. positive    - a short TINA4_SESSION_TTL really expires the record.
 *   2. negative    - a long TINA4_SESSION_TTL really does NOT expire it. Without
 *                    this, "delete all the expiry logic" passes case 1 and ships
 *                    a store that throws every session away.
 *   3. out-of-band - the stored deadline is read back with an INDEPENDENT client
 *                    and is now+TTL, not now+86400. Cases 1 and 2 both ask the
 *                    code under test whether the record is alive; this one asks
 *                    the SERVER, so a handler that lies cannot pass it.
 *   4. Session api - Session forwards its OWN ttl to the STORE, not just into
 *                    the cookie. Cases 1-3 drive the handlers directly, so they
 *                    gate the handler defaults and nothing else.
 *
 * NOTE ON THE FILE BACKEND. PHP has no standalone FileSessionHandler class: the
 * file backend lives inside Tina4\Session itself (loadFromFile/saveToFile), so
 * `new Session('file')` IS how an operator gets it and is what these cases
 * exercise. The other five are the same handler classes Session builds.
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Session;
use Tina4\Session\DatabaseSessionHandler;
use Tina4\Session\MemcachedSessionHandler;
use Tina4\Session\MongoSessionHandler;
use Tina4\Session\RedisSessionHandler;
use Tina4\Session\ValkeySessionHandler;

class SessionTtlContractTest extends TestCase
{
    /** Every backend the invariant names. */
    private const BACKENDS = ['file', 'database', 'redis', 'valkey', 'mongodb', 'memcached'];

    /** The key prefix Redis and Valkey both write under. */
    private const RESP_KEY_PREFIX = 'tina4:session:';

    private string $tempDir = '';
    private string $dbFile = '';

    /** @var array<string, string|false> Pre-test value of every env var this file touches. */
    private array $originalEnv = [];

    /** @var array<string, object> One handler per backend, per test method. */
    private array $handlers = [];

    private string $redisHost = '127.0.0.1';
    private int $redisPort = 6379;
    private string $valkeyHost = '127.0.0.1';
    private int $valkeyPort = 6380;
    private string $memcachedHost = '127.0.0.1';
    private int $memcachedPort = 11211;
    private string $mongoUri = 'mongodb://127.0.0.1:27017';
    private string $mongoHost = '127.0.0.1';
    private int $mongoPort = 27017;
    private string $mongoDatabase = 'tina4';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tina4-ttl-contract-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
        $this->dbFile = $this->tempDir . '/ttl_contract.db';
        $this->handlers = [];

        // Resolve the service coordinates from the environment BEFORE anything
        // below overwrites it, so an operator can relocate the lab services.
        $this->redisHost = getenv('TINA4_SESSION_REDIS_HOST') ?: '127.0.0.1';
        $this->redisPort = (int)(getenv('TINA4_SESSION_REDIS_PORT') ?: 6379);

        // ValkeySessionHandler DEFAULTS to port 6379 - REDIS's port - and shares
        // the exact same 'tina4:session:' key prefix, so left alone the valkey
        // rows land in Redis and the two backends are indistinguishable. Point
        // it at the real Valkey so this file genuinely covers six backends.
        $this->valkeyHost = getenv('TINA4_SESSION_VALKEY_HOST') ?: '127.0.0.1';
        $this->valkeyPort = (int)(getenv('TINA4_SESSION_VALKEY_PORT') ?: 6380);

        $this->memcachedHost = getenv('TINA4_SESSION_MEMCACHED_HOST')
            ?: (getenv('TINA4_TEST_MEMCACHED_HOST') ?: '127.0.0.1');
        $this->memcachedPort = (int)(getenv('TINA4_SESSION_MEMCACHED_PORT')
            ?: (getenv('TINA4_TEST_MEMCACHED_PORT') ?: 11211));

        $this->mongoUri = getenv('TINA4_SESSION_MONGO_URI')
            ?: (getenv('TINA4_MONGO_URI') ?: 'mongodb://127.0.0.1:27017');
        $parsedMongo = parse_url($this->mongoUri);
        $this->mongoHost = $parsedMongo['host'] ?? '127.0.0.1';
        $this->mongoPort = (int)($parsedMongo['port'] ?? 27017);
        $this->mongoDatabase = getenv('TINA4_SESSION_MONGO_DB') ?: 'tina4';

        // Pin every coordinate explicitly. The handler and the out-of-band probe
        // must be unable to disagree about which server they are talking to -
        // the same lesson SessionDatabaseEngineTest learned when a hardcoded DSN
        // read one database while the session wrote to another.
        $this->setEnv('TINA4_SESSION_PATH', $this->tempDir);
        $this->setEnv('TINA4_SESSION_REDIS_HOST', $this->redisHost);
        $this->setEnv('TINA4_SESSION_REDIS_PORT', (string)$this->redisPort);
        $this->setEnv('TINA4_SESSION_VALKEY_HOST', $this->valkeyHost);
        $this->setEnv('TINA4_SESSION_VALKEY_PORT', (string)$this->valkeyPort);
        $this->setEnv('TINA4_SESSION_MEMCACHED_HOST', $this->memcachedHost);
        $this->setEnv('TINA4_SESSION_MEMCACHED_PORT', (string)$this->memcachedPort);
        $this->setEnv('TINA4_SESSION_MONGO_URI', $this->mongoUri);
        $this->setEnv('TINA4_SESSION_MONGO_DB', $this->mongoDatabase);
    }

    protected function tearDown(): void
    {
        // Redis/Valkey/Mongo handlers hold an open TCP socket for the life of
        // the object. Leaking them into later tests is not theoretical - an
        // unclosed pool is what made an unrelated connection-count gate fail in
        // the Ruby port, order-dependently.
        foreach ($this->handlers as $handler) {
            try {
                if (method_exists($handler, 'close')) {
                    $handler->close();
                }
            } catch (\Throwable) {
                // Closing a handler we are throwing away must never fail a test.
            }
        }
        $this->handlers = [];

        foreach ($this->originalEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
                continue;
            }
            putenv($name . '=' . $value);
        }
        $this->originalEnv = [];

        $this->removeDirectory($this->tempDir);
    }

    // ---------------------------------------------------------------------
    // 1. POSITIVE: a short TINA4_SESSION_TTL really expires the record
    //
    // One shared real sleep for all six backends keeps the wall-clock cost at
    // ~4s instead of 24s, and a single sleep cannot accidentally give one
    // backend more grace than another.
    // ---------------------------------------------------------------------
    public function testSessionTtlEnvVarExpiresTheRecordOnEveryBackend(): void
    {
        $this->requireEveryService();
        $this->setEnv('TINA4_SESSION_TTL', '2');

        $ids = [];
        foreach (self::BACKENDS as $backend) {
            $ids[$backend] = $this->sessionId('ttlshort', $backend);
            // NO explicit ttl argument: the handler default is what must come
            // from TINA4_SESSION_TTL. Passing a ttl here would test the
            // argument, not the env var, and the argument already works.
            $this->handlerFor($backend)->write($ids[$backend], ['seeded' => true]);

            $this->assertTrue(
                $this->isStored($this->handlerFor($backend)->read($ids[$backend])),
                "{$backend}: the record was not stored at all"
            );
        }

        sleep(4); // REAL wall clock, past the configured 2s. No clock mocking.

        $stillAlive = [];
        foreach ($ids as $backend => $sessionId) {
            if ($this->isStored($this->handlerFor($backend)->read($sessionId))) {
                $stillAlive[] = $backend;
            }
        }

        $this->assertSame(
            [],
            $stillAlive,
            'TINA4_SESSION_TTL=2 was ignored by: ' . implode(', ', $stillAlive)
            . ' (the record survived 4 real seconds)'
        );
    }

    // ---------------------------------------------------------------------
    // 2. NEGATIVE CONTROL: a long TINA4_SESSION_TTL must NOT expire
    //
    // Without this, deleting all expiry logic passes case 1 and ships a session
    // store that throws every session away.
    // ---------------------------------------------------------------------
    public function testSessionTtlEnvVarKeepsALongLivedRecordOnEveryBackend(): void
    {
        $this->requireEveryService();
        $this->setEnv('TINA4_SESSION_TTL', '3600');

        $ids = [];
        foreach (self::BACKENDS as $backend) {
            $ids[$backend] = $this->sessionId('ttllong', $backend);
            $this->handlerFor($backend)->write($ids[$backend], ['seeded' => true]);
        }

        sleep(4); // the SAME real sleep that reaped everything in case 1

        $died = [];
        foreach ($ids as $backend => $sessionId) {
            if (!$this->isStored($this->handlerFor($backend)->read($sessionId))) {
                $died[] = $backend;
            }
        }

        $this->assertSame(
            [],
            $died,
            'TINA4_SESSION_TTL=3600 still expired the record on: ' . implode(', ', $died)
        );
    }

    // ---------------------------------------------------------------------
    // 3. OUT OF BAND: the stored deadline is now+TTL, read by another client
    //
    // Cases 1 and 2 both ask the code under test whether the record is alive.
    // This one asks the SERVER, through a client the handler does not own, so a
    // handler that lies about expiry cannot pass it. memcached is absent BY
    // DESIGN: its text protocol exposes no per-key TTL to read back, so it is
    // proven behaviourally by cases 1 and 2 instead of being asserted through
    // the very code under test.
    // ---------------------------------------------------------------------
    public function testSessionTtlEnvVarReachesTheStoredDeadlineOutOfBand(): void
    {
        $this->requireEveryService();

        $configured = 1800;
        $this->setEnv('TINA4_SESSION_TTL', (string)$configured);
        $now = microtime(true);
        $observed = [];

        // file - read the JSON straight off disk. The filename is the SHA-256 of
        // the session id (Session::getFilePath), and the deadline lives in
        // _meta.expires_at as an absolute epoch second.
        $fileId = $this->sessionId('ttloob', 'file');
        $this->handlerFor('file')->write($fileId, ['seeded' => true]);
        $filePath = $this->tempDir . '/' . hash('sha256', $fileId) . '.json';
        $this->assertFileExists($filePath, 'file: no session file was written at all');
        $fileRecord = json_decode((string)file_get_contents($filePath), true);
        $observed['file'] = (float)($fileRecord['_meta']['expires_at'] ?? 0) - $now;

        // database - read the column with raw PDO on a SEPARATE connection, so
        // the assertion never routes through the adapter under test.
        $databaseId = $this->sessionId('ttloob', 'database');
        $this->handlerFor('database')->write($databaseId, ['seeded' => true]);
        $probe = new \PDO('sqlite:' . $this->dbFile, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $statement = $probe->prepare('SELECT expires_at FROM tina4_session WHERE session_id = ?');
        $statement->execute([$databaseId]);
        $storedDeadline = $statement->fetchColumn();
        $this->assertNotFalse($storedDeadline, "database: no row was written for {$databaseId}");
        $observed['database'] = (float)$storedDeadline - $now;

        // redis / valkey - ask the SERVER for the key's remaining TTL over our
        // own socket. The handler issued SETEX; this issues TTL.
        $respTargets = [
            'redis' => [$this->redisHost, $this->redisPort],
            'valkey' => [$this->valkeyHost, $this->valkeyPort],
        ];
        foreach ($respTargets as $backend => [$host, $port]) {
            $respId = $this->sessionId('ttloob', $backend);
            $this->handlerFor($backend)->write($respId, ['seeded' => true]);
            $observed[$backend] = (float)$this->remainingTtlFromServer(
                $backend,
                $host,
                $port,
                self::RESP_KEY_PREFIX . $respId
            );
        }

        // mongodb - read the document with the ext-mongodb C driver, which
        // shares no code with the hand-rolled BSON/OP_MSG socket client under
        // test. expires_at specifically: an ABSOLUTE epoch deadline, 0 meaning
        // never, the shape Python (the master), PHP and Node all store.
        if (!class_exists('\MongoDB\Driver\Manager')) {
            $this->skipOrFail('the ext-mongodb driver is not installed, so the mongodb deadline cannot be read out of band');
        }
        $mongoId = $this->sessionId('ttloob', 'mongodb');
        $this->handlerFor('mongodb')->write($mongoId, ['seeded' => true]);
        $manager = new \MongoDB\Driver\Manager($this->mongoUri);
        $cursor = $manager->executeQuery(
            $this->mongoDatabase . '.sessions',
            new \MongoDB\Driver\Query(['_id' => $mongoId], ['limit' => 1])
        );
        $documents = $cursor->toArray();
        $this->assertNotEmpty($documents, "mongodb: no document was written for {$mongoId}");
        $observed['mongodb'] = (float)($documents[0]->expires_at ?? 0) - $now;

        $wrong = [];
        foreach ($observed as $backend => $delta) {
            if (abs($delta - $configured) >= 60) {
                $wrong[] = $backend . ' stored now+' . round($delta) . 's';
            }
        }

        $this->assertSame(
            [],
            $wrong,
            "the stored deadline did not come from TINA4_SESSION_TTL={$configured}: "
            . implode(', ', $wrong)
        );
    }

    // ---------------------------------------------------------------------
    // 4. THE PUBLIC Session API forwards its OWN ttl to the store
    //
    // Cases 1-3 drive the handlers directly, so they gate the handler defaults
    // and nothing else. Ruby's Session#save called safe_write(id, data) with NO
    // ttl, which meant an explicit Session.new(ttl:) never reached the backend
    // at all - the cookie carried it and the stored record did not. Without this
    // case that forwarding is an INERT mutation: remove it and cases 1-3 stay
    // green. The env says one hour; the Session says two seconds; the Session
    // must win, in the STORE, not just in the cookie.
    // ---------------------------------------------------------------------
    public function testSessionTtlOptionOnTheSessionReachesTheStoredRecord(): void
    {
        $this->setEnv('TINA4_SESSION_TTL', '3600');

        $session = new Session('file', ['ttl' => 2]);
        $sessionId = $session->start();
        $session->set('seeded', true);
        $this->assertTrue($session->save(), 'the session did not save at all');

        $resumedImmediately = new Session('file', ['ttl' => 2]);
        $resumedImmediately->start($sessionId);
        $this->assertTrue(
            $resumedImmediately->get('seeded'),
            'the session was not stored at all'
        );

        sleep(4); // REAL wall clock, past the Session's own 2s ttl

        $resumed = new Session('file', ['ttl' => 2]);
        $resumed->start($sessionId);
        $this->assertNull(
            $resumed->get('seeded'),
            'new Session(ttl: 2) never reached the store - the record outlived '
            . 'its own ttl because save() dropped the ttl on the way down'
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Build the handler the way Tina4\Session builds it, so these cases
     * exercise the SAME construction path an operator gets from
     * TINA4_SESSION_BACKEND rather than a hand-configured object that could
     * diverge from it. Every one of them reads TINA4_SESSION_TTL in its
     * constructor, so a handler must never be built before the ttl is set.
     */
    private function handlerFor(string $backend): object
    {
        if (isset($this->handlers[$backend])) {
            return $this->handlers[$backend];
        }

        $handler = match ($backend) {
            // No standalone file handler exists in PHP - Session IS the file
            // backend. Its path comes from TINA4_SESSION_PATH, set in setUp.
            'file' => new Session('file'),
            // The adapter is passed explicitly (as SessionDatabaseEngineTest
            // does) so the raw-PDO probe in case 3 reads the same real SQLite
            // file the handler wrote. The ttl still comes from the env.
            'database' => new DatabaseSessionHandler(['db' => Database::create('sqlite:///' . $this->dbFile)]),
            'redis' => new RedisSessionHandler(),
            'valkey' => new ValkeySessionHandler(),
            'mongodb' => new MongoSessionHandler(),
            'memcached' => new MemcachedSessionHandler(),
            default => throw new \InvalidArgumentException("unknown backend {$backend}"),
        };

        $this->handlers[$backend] = $handler;

        return $handler;
    }

    /**
     * Whether a backend still holds the seeded record.
     *
     * The six read paths disagree on how they spell a miss: Session (file) and
     * DatabaseSessionHandler return null, the other four return []. An empty
     * array is FALSY in PHP but a bare truthiness check on a null-vs-[] mix is
     * the kind of thing that quietly reports one backend as alive on every
     * miss, so the payload itself is asserted instead.
     */
    private function isStored(mixed $value): bool
    {
        return is_array($value) && ($value['seeded'] ?? null) === true;
    }

    /**
     * A unique, well-formed session id per run, so concurrent runs of this file
     * (or of the same suite on another host against the shared lab services)
     * cannot collide on a key. Session::SESSION_ID_PATTERN allows [A-Za-z0-9_-].
     */
    private function sessionId(string $prefix, string $backend): string
    {
        return $prefix . '-' . $backend . '-' . bin2hex(random_bytes(8));
    }

    /**
     * Ask a RESP server for a key's remaining TTL over a socket this test owns.
     *
     * This is deliberately NOT RedisSessionHandler: it is the independent
     * client case 3 needs. TTL replies -2 when the key is gone and -1 when it
     * carries no expiry; both fail the assertion loudly rather than silently
     * reading as "fine".
     */
    private function remainingTtlFromServer(string $service, string $host, int $port, string $key): int
    {
        $errNo = 0;
        $errStr = '';
        $socket = @fsockopen($host, $port, $errNo, $errStr, 5);
        if ($socket === false) {
            $this->fail(sprintf(
                '%s is not reachable at %s:%d for the out-of-band TTL read (%s)',
                $service,
                $host,
                $port,
                $errStr !== '' ? $errStr : 'no error reported'
            ));
        }

        stream_set_timeout($socket, 5);
        fwrite($socket, "*2\r\n\$3\r\nTTL\r\n\$" . strlen($key) . "\r\n" . $key . "\r\n");
        $reply = fgets($socket);
        fclose($socket);

        if (!is_string($reply) || $reply === '' || $reply[0] !== ':') {
            $this->fail(sprintf(
                '%s answered the out-of-band TTL with %s, which is not a RESP integer',
                $service,
                var_export($reply, true)
            ));
        }

        return (int)substr(trim($reply), 1);
    }

    /**
     * Every network service this file needs must be up. Under
     * TINA4_REQUIRE_SERVICES a missing one is a hard FAILURE - a suite that
     * silently skips its only real verification is not verification.
     */
    private function requireEveryService(): void
    {
        $this->requireReachable('redis', $this->redisHost, $this->redisPort);
        $this->requireReachable('valkey', $this->valkeyHost, $this->valkeyPort);
        $this->requireReachable('memcached', $this->memcachedHost, $this->memcachedPort);
        $this->requireReachable('mongodb', $this->mongoHost, $this->mongoPort);
    }

    /** Skip LOUDLY (naming host and port) unless services are required. */
    private function requireReachable(string $service, string $host, int $port): void
    {
        $errNo = 0;
        $errStr = '';
        $probe = @fsockopen($host, $port, $errNo, $errStr, 2);
        if ($probe === false) {
            $this->skipOrFail(sprintf(
                '%s is not reachable at %s:%d (%s)',
                $service,
                $host,
                $port,
                $errStr !== '' ? $errStr : 'no error reported'
            ));
        }
        fclose($probe);
    }

    /**
     * One place that decides skip-vs-fail, so every message carries a service
     * keyword and an unavailability hint and the RequireServicesGate can see it.
     */
    private function skipOrFail(string $message): void
    {
        if (getenv('TINA4_REQUIRE_SERVICES')) {
            $this->fail('TINA4_REQUIRE_SERVICES is set but ' . $message);
        }
        $this->markTestSkipped($message);
    }

    /** Record a variable's pre-test value once, then set it for this test. */
    private function setEnv(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->originalEnv)) {
            $this->originalEnv[$name] = getenv($name);
        }
        putenv($name . '=' . $value);
    }

    /** Remove the temp directory and everything this test wrote into it. */
    private function removeDirectory(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
