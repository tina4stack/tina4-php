<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * SESSION CONTRACT: the MongoDB session COLLECTION is configurable by env var.
 *
 * ADR-0024: the same .env must produce the same observable outcome in all four
 * frameworks. Measured 2026-08-06 by reading each handler's constructor:
 *
 *     TINA4_SESSION_MONGO_URI          python YES  php YES  ruby YES  node YES
 *     TINA4_SESSION_MONGO_DB           python YES  php YES  ruby YES  node YES
 *     TINA4_SESSION_MONGO_COLLECTION   python YES  php NO   ruby YES  node YES
 *
 * PHP was the only one that read nothing. It is also the worst place for that
 * gap: Session::getMongoHandler() constructs `new MongoSessionHandler()` with NO
 * config at all, so with TINA4_SESSION_BACKEND=mongodb the collection name was
 * literally unreachable - an operator who set TINA4_SESSION_MONGO_COLLECTION in
 * a shared .env got the collection they asked for on three frameworks and the
 * hard-coded `sessions` on PHP.
 *
 * And it is SILENT. Writing to the wrong collection is not an error, so the
 * log-loud-and-degrade backend policy can never fire: reads simply miss, which
 * is indistinguishable from a session that has expired. Two apps sharing one
 * MongoDB, each told to namespace its sessions, collide on PHP and nowhere else.
 *
 * NO MOCKS. The service is a real MongoDB and the handler speaks its real wire
 * protocol. Every claim about WHICH COLLECTION holds the document is checked
 * through ext-mongodb's own driver - a completely independent client, not the
 * handler's hand-rolled BSON codec - so a handler that is wrong in a
 * self-consistent way cannot pass this. Asking the handler where it thinks it
 * wrote would prove nothing.
 *
 * THE THREE CASES, and why each is load-bearing:
 *   1. positive   - the env var really names the collection ON THE SERVER, and
 *                   the default `sessions` is ABSENT (a name that is appended to
 *                   rather than used would still collide).
 *   2. precedence - an explicit 'collection' option still beats the env var.
 *                   Without it, "just read the env var" passes case 1 while
 *                   breaking every caller that passes the option by hand. That
 *                   inversion has landed twice in two days in this codebase
 *                   (PHP Queue::resolveMongoConfig, Node MongoSessionHandler
 *                   .target()), so it gets its own gate here.
 *   3. negative   - with nothing set the collection is still `sessions`.
 *                   Without it, "always use the variable, empty or not" passes
 *                   cases 1 and 2 and silently renames the collection in every
 *                   deployment that never asked for one, which on a session
 *                   store logs everybody out at once.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Session\MongoSessionHandler;

class SessionMongoCollectionEnvTest extends TestCase
{
    private const ENV_VAR = 'TINA4_SESSION_MONGO_COLLECTION';
    private const DEFAULT_COLLECTION = 'sessions';

    /** Throwaway, framework-namespaced database - never application data. */
    private const DB_NAME = 'tina4_test_php_session_collection';

    private string $mongoUri = '';
    private ?\MongoDB\Driver\Manager $observer = null;

    /** @var string|false */
    private $originalEnv = false;

    protected function setUp(): void
    {
        $this->originalEnv = getenv(self::ENV_VAR);

        $this->mongoUri = getenv('TINA4_SESSION_MONGO_URI')
            ?: (getenv('TINA4_MONGO_URI') ?: 'mongodb://127.0.0.1:27017');

        // The observation client is ext-mongodb's raw driver (no composer
        // library needed). Its absence is reported in wording RequireServicesGate
        // recognises, so under TINA4_REQUIRE_SERVICES it is a hard failure rather
        // than a green skip.
        if (!extension_loaded('mongodb') || !class_exists('MongoDB\\Driver\\Manager')) {
            $this->markTestSkipped('mongo driver (ext-mongodb) not installed - cannot observe the collection out of band');
        }

        $this->requireMongo();
        $this->observer = new \MongoDB\Driver\Manager($this->mongoUri);
        $this->dropTestDatabase();
    }

    protected function tearDown(): void
    {
        $this->originalEnv === false
            ? putenv(self::ENV_VAR)
            : putenv(self::ENV_VAR . '=' . $this->originalEnv);

        $this->dropTestDatabase();
        $this->observer = null;
    }

    /** Skip only when Mongo is genuinely absent; under the service gate that is a failure. */
    private function requireMongo(): void
    {
        $host = '127.0.0.1';
        $port = 27017;
        if (preg_match('#^mongodb(\+srv)?://([^:/?@]+)(?::(\d+))?#', $this->mongoUri, $m) === 1) {
            $host = $m[2] !== '' ? $m[2] : $host;
            $port = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : $port;
        }

        $socket = @fsockopen($host, $port, $errNo, $errStr, 2);
        if ($socket === false) {
            $this->markTestSkipped("mongo not reachable at {$host}:{$port}");
        }
        fclose($socket);
    }

    private function dropTestDatabase(): void
    {
        if ($this->observer === null) {
            return;
        }
        try {
            $this->observer->executeCommand(
                self::DB_NAME,
                new \MongoDB\Driver\Command(['dropDatabase' => 1])
            );
        } catch (\Throwable $e) {
            // Best-effort cleanup; a teardown failure must not mask a real result.
        }
    }

    /**
     * Does THIS collection hold the session document? Asked through ext-mongodb's
     * own driver, which shares no code with the handler under test.
     */
    private function serverHasSession(string $collection, string $sessionId): bool
    {
        $cursor = $this->observer->executeQuery(
            self::DB_NAME . '.' . $collection,
            new \MongoDB\Driver\Query(['_id' => $sessionId])
        );

        return $cursor->toArray() !== [];
    }

    /**
     * A handler pointed at the throwaway database. $collection === null means
     * "give it nothing", which is exactly what Session::getMongoHandler() does.
     */
    private function makeHandler(?string $collection = null): MongoSessionHandler
    {
        $config = [
            'url' => $this->mongoUri,
            'database' => self::DB_NAME,
            'ttl' => 60,
        ];
        if ($collection !== null) {
            $config['collection'] = $collection;
        }

        return new MongoSessionHandler($config);
    }

    public function testSessionMongoCollectionEnvVarNamesTheCollectionOnTheServer(): void
    {
        $configured = 'itest' . bin2hex(random_bytes(4));
        putenv(self::ENV_VAR . '=' . $configured);

        $handler = $this->makeHandler();
        $sessionId = 'coll-' . bin2hex(random_bytes(4));

        try {
            $handler->write($sessionId, ['seeded' => true]);

            $this->assertTrue(
                $this->serverHasSession($configured, $sessionId),
                self::ENV_VAR . "={$configured} was ignored - nothing at "
                . self::DB_NAME . ".{$configured} on the server"
            );
            // The DEFAULT collection must be ABSENT, or the variable was
            // appended to rather than used and two deployments still collide.
            $this->assertFalse(
                $this->serverHasSession(self::DEFAULT_COLLECTION, $sessionId),
                'the session was ALSO written to the default `' . self::DEFAULT_COLLECTION . '` collection'
            );
        } finally {
            $handler->close();
        }
    }

    public function testSessionMongoCollectionOptionWinsOverTheEnvVar(): void
    {
        $fromEnv = 'fromenv' . bin2hex(random_bytes(4));
        putenv(self::ENV_VAR . '=' . $fromEnv);
        $explicit = 'explicit' . bin2hex(random_bytes(4));

        $handler = $this->makeHandler($explicit);
        $sessionId = 'coll-' . bin2hex(random_bytes(4));

        try {
            $handler->write($sessionId, ['seeded' => true]);

            $this->assertTrue(
                $this->serverHasSession($explicit, $sessionId),
                "an explicit 'collection' option lost to " . self::ENV_VAR
            );
            $this->assertFalse(
                $this->serverHasSession($fromEnv, $sessionId),
                'the env var was used even though an explicit collection was given'
            );
        } finally {
            $handler->close();
        }
    }

    public function testSessionMongoCollectionDefaultsWhenNothingIsSet(): void
    {
        putenv(self::ENV_VAR);

        $handler = $this->makeHandler();
        $reflection = new \ReflectionClass($handler);
        $this->assertSame(
            self::DEFAULT_COLLECTION,
            $reflection->getProperty('collection')->getValue($handler),
            'the documented default collection was lost'
        );

        $sessionId = 'coll-' . bin2hex(random_bytes(4));
        try {
            $handler->write($sessionId, ['seeded' => true]);

            $this->assertTrue(
                $this->serverHasSession(self::DEFAULT_COLLECTION, $sessionId),
                'nothing at the default `' . self::DEFAULT_COLLECTION . '` collection on the server'
            );
        } finally {
            $handler->close();
        }
    }
}
