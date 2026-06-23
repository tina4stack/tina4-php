<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Real behavioural tests for the session handlers. Every handler is exercised
 * against the REAL backend it talks to — no mocks, no fakes:
 *   - MongoSessionHandler  -> live MongoDB (TINA4_MONGO_URI, default localhost:27017)
 *   - ValkeySessionHandler -> live Valkey  (default localhost:6380)
 *   - RedisSessionHandler  -> live Redis   (default localhost:6379)
 *   - DatabaseSessionHandler -> real SQLite + live PostgreSQL
 *
 * Each backend test does a write -> read-back -> assert -> destroy -> assert-gone
 * round-trip. A single consolidated interface-contract test per class loops the
 * expected method set, acting as a parity / rename guard across the 4 frameworks
 * (replacing the dozens of per-method `hasMethod` smoke tests).
 *
 * Backends that cannot be reached are skipped (markTestSkipped) rather than
 * failing — the round-trip itself is the assertion when the service is up.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\SQLite3Adapter;
use Tina4\Session\MongoSessionHandler;
use Tina4\Session\ValkeySessionHandler;
use Tina4\Session\RedisSessionHandler;
use Tina4\Session\DatabaseSessionHandler;

class SessionHandlerTest extends TestCase
{
    private const MONGO_PORT = 27017;
    private const VALKEY_PORT = 6380;
    private const REDIS_PORT = 6379;
    private const PG_PORT = 55432;

    /** Return a unique session id so concurrent / repeated runs never collide. */
    private function sid(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(8));
    }

    /** Skip the current test when a TCP service is not reachable. */
    private function requireService(string $host, int $port, string $name): void
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$sock) {
            $this->markTestSkipped("{$name} not reachable at {$host}:{$port} ({$errstr})");
        }
        fclose($sock);
    }

    private function mongoHost(): string
    {
        $uri = getenv('TINA4_MONGO_URI') ?: 'mongodb://localhost:27017';
        return parse_url($uri, PHP_URL_HOST) ?: 'localhost';
    }

    // =====================================================================
    //  Interface-contract guards (one per class — replaces the per-method
    //  existence smoke tests). These lock the public method set so a rename
    //  in one framework can't silently drift the others out of parity.
    // =====================================================================

    public function testMongoSessionHandlerContract(): void
    {
        $expected = ['read', 'write', 'destroy', 'close'];
        $ref = new \ReflectionClass(MongoSessionHandler::class);
        foreach ($expected as $method) {
            $this->assertTrue($ref->hasMethod($method), "MongoSessionHandler is missing {$method}()");
        }
        // read(sessionId) signature is part of the cross-framework contract.
        $read = $ref->getMethod('read');
        $this->assertSame(1, $read->getNumberOfParameters());
        $this->assertSame('sessionId', $read->getParameters()[0]->getName());
        $this->assertSame(2, $ref->getMethod('write')->getNumberOfParameters());
    }

    public function testValkeySessionHandlerContract(): void
    {
        $expected = ['read', 'write', 'destroy', 'close', 'exists', 'touch'];
        $ref = new \ReflectionClass(ValkeySessionHandler::class);
        foreach ($expected as $method) {
            $this->assertTrue($ref->hasMethod($method), "ValkeySessionHandler is missing {$method}()");
        }
        $read = $ref->getMethod('read');
        $this->assertSame('sessionId', $read->getParameters()[0]->getName());
    }

    public function testRedisSessionHandlerContract(): void
    {
        $expected = ['read', 'write', 'destroy', 'close', 'exists', 'touch'];
        $ref = new \ReflectionClass(RedisSessionHandler::class);
        foreach ($expected as $method) {
            $this->assertTrue($ref->hasMethod($method), "RedisSessionHandler is missing {$method}()");
        }
    }

    public function testDatabaseSessionHandlerContract(): void
    {
        $expected = ['read', 'write', 'destroy', 'delete', 'gc', 'close'];
        $ref = new \ReflectionClass(DatabaseSessionHandler::class);
        foreach ($expected as $method) {
            $this->assertTrue($ref->hasMethod($method), "DatabaseSessionHandler is missing {$method}()");
        }
        $read = $ref->getMethod('read');
        $this->assertSame('sessionId', $read->getParameters()[0]->getName());
        $write = $ref->getMethod('write');
        $this->assertSame(2, $write->getNumberOfParameters());
        $this->assertSame('data', $write->getParameters()[1]->getName());
    }

    // =====================================================================
    //  MongoSessionHandler — real MongoDB round-trips
    // =====================================================================

    private function mongoHandler(int $ttl = 1800, string $collection = 'php_test_sessions'): MongoSessionHandler
    {
        return new MongoSessionHandler([
            'url' => getenv('TINA4_MONGO_URI') ?: 'mongodb://localhost:27017',
            'database' => 'tina4_session_test',
            'collection' => $collection,
            'ttl' => $ttl,
        ]);
    }

    public function testMongoWriteReadDestroyCycle(): void
    {
        $this->requireService($this->mongoHost(), self::MONGO_PORT, 'MongoDB');

        $handler = $this->mongoHandler();
        $id = $this->sid('mongo-cycle');

        $handler->write($id, ['user_id' => 42, 'role' => 'admin']);

        $read = $handler->read($id);
        $this->assertSame(['user_id' => 42, 'role' => 'admin'], $read);

        $handler->destroy($id);
        $this->assertSame([], $handler->read($id), 'session must be gone after destroy()');

        $handler->close();
    }

    public function testMongoReadMissingSessionReturnsEmpty(): void
    {
        $this->requireService($this->mongoHost(), self::MONGO_PORT, 'MongoDB');

        $handler = $this->mongoHandler();
        $this->assertSame([], $handler->read($this->sid('mongo-missing')));
        $handler->close();
    }

    public function testMongoWriteOverwritesExistingSession(): void
    {
        $this->requireService($this->mongoHost(), self::MONGO_PORT, 'MongoDB');

        $handler = $this->mongoHandler();
        $id = $this->sid('mongo-overwrite');

        $handler->write($id, ['count' => 1]);
        $handler->write($id, ['count' => 2, 'extra' => 'added']);

        $this->assertSame(['count' => 2, 'extra' => 'added'], $handler->read($id));

        $handler->destroy($id);
        $handler->close();
    }

    public function testMongoRoundTripsNestedDataAndScalars(): void
    {
        $this->requireService($this->mongoHost(), self::MONGO_PORT, 'MongoDB');

        $handler = $this->mongoHandler(1800, 'php_test_sessions_nested');
        $id = $this->sid('mongo-nested');

        $data = [
            'user' => ['id' => 7, 'name' => 'Bob', 'tags' => ['a', 'b', 'c']],
            'count' => 3,
            'active' => true,
            'ratio' => 1.5,
        ];
        $handler->write($id, $data);

        // The handler ships a zero-dependency BSON codec; this asserts ints,
        // bools, floats, lists and nested documents all survive the round-trip.
        $this->assertEquals($data, $handler->read($id));

        $handler->destroy($id);
        $handler->close();
    }

    public function testMongoExpiredSessionIsPurgedOnRead(): void
    {
        $this->requireService($this->mongoHost(), self::MONGO_PORT, 'MongoDB');

        // ttl=1s: write, wait past expiry, read must return empty AND remove the row.
        $writer = $this->mongoHandler(1, 'php_test_sessions_ttl');
        $id = $this->sid('mongo-expire');
        $writer->write($id, ['x' => 1]);
        $this->assertSame(['x' => 1], $writer->read($id), 'fresh session must read back');

        sleep(2);
        $this->assertSame([], $writer->read($id), 'expired session must read empty');
        $writer->close();

        // A fresh long-ttl handler proves the expiring read also destroyed the doc.
        $reader = $this->mongoHandler(9999, 'php_test_sessions_ttl');
        $this->assertSame([], $reader->read($id), 'expired session must have been purged');
        $reader->close();
    }

    /** Config parsing is real constructor behaviour — assert the parsed values. */
    public function testMongoParsesUrlIntoHostPortDbCollectionTtl(): void
    {
        $handler = new MongoSessionHandler([
            'url' => 'mongodb://user:pass@db.host.com:27020/mydb',
            'database' => 'mydb',
            'collection' => 'mysessions',
            'ttl' => 7200,
        ]);

        $ref = new \ReflectionClass($handler);
        $this->assertSame('db.host.com', $ref->getProperty('host')->getValue($handler));
        $this->assertSame(27020, $ref->getProperty('port')->getValue($handler));
        $this->assertSame('mydb', $ref->getProperty('database')->getValue($handler));
        $this->assertSame('mysessions', $ref->getProperty('collection')->getValue($handler));
        $this->assertSame(7200, $ref->getProperty('ttl')->getValue($handler));
    }

    public function testMongoDefaultsAndTtlEnvVar(): void
    {
        putenv('TINA4_SESSION_TTL=4321');
        try {
            $handler = new MongoSessionHandler();
            $ref = new \ReflectionClass($handler);
            $this->assertSame('localhost', $ref->getProperty('host')->getValue($handler));
            $this->assertSame(27017, $ref->getProperty('port')->getValue($handler));
            $this->assertSame('tina4', $ref->getProperty('database')->getValue($handler));
            $this->assertSame('sessions', $ref->getProperty('collection')->getValue($handler));
            $this->assertSame(4321, $ref->getProperty('ttl')->getValue($handler));
        } finally {
            putenv('TINA4_SESSION_TTL');
        }
    }

    // =====================================================================
    //  ValkeySessionHandler — real Valkey round-trips
    // =====================================================================

    private function valkeyHandler(int $ttl = 60, string $prefix = 'tina4test:valkey:'): ValkeySessionHandler
    {
        return new ValkeySessionHandler([
            'host' => 'localhost',
            'port' => self::VALKEY_PORT,
            'ttl' => $ttl,
            'keyPrefix' => $prefix,
        ]);
    }

    public function testValkeyWriteReadDestroyCycle(): void
    {
        $this->requireService('localhost', self::VALKEY_PORT, 'Valkey');

        $handler = $this->valkeyHandler();
        $id = $this->sid('valkey-cycle');

        $handler->write($id, ['theme' => 'dark', 'lang' => 'en']);
        $this->assertSame(['theme' => 'dark', 'lang' => 'en'], $handler->read($id));
        $this->assertTrue($handler->exists($id));

        $handler->destroy($id);
        $this->assertSame([], $handler->read($id));
        $this->assertFalse($handler->exists($id), 'exists() must be false after destroy()');

        $handler->close();
    }

    public function testValkeyReadMissingSessionReturnsEmpty(): void
    {
        $this->requireService('localhost', self::VALKEY_PORT, 'Valkey');

        $handler = $this->valkeyHandler();
        $id = $this->sid('valkey-missing');
        $this->assertSame([], $handler->read($id));
        $this->assertFalse($handler->exists($id));
        $handler->close();
    }

    public function testValkeyTouchKeepsSessionAlive(): void
    {
        $this->requireService('localhost', self::VALKEY_PORT, 'Valkey');

        $handler = $this->valkeyHandler(ttl: 1000);
        $id = $this->sid('valkey-touch');
        $handler->write($id, ['a' => 1]);

        $handler->touch($id); // refresh TTL — must not raise and key must remain
        $this->assertTrue($handler->exists($id));
        $this->assertSame(['a' => 1], $handler->read($id));

        $handler->destroy($id);
        $handler->close();
    }

    public function testValkeyWriteOverwritesExistingSession(): void
    {
        $this->requireService('localhost', self::VALKEY_PORT, 'Valkey');

        $handler = $this->valkeyHandler();
        $id = $this->sid('valkey-overwrite');
        $handler->write($id, ['v' => 1]);
        $handler->write($id, ['v' => 2, 'b' => 3]);
        $this->assertSame(['v' => 2, 'b' => 3], $handler->read($id));
        $handler->destroy($id);
        $handler->close();
    }

    /** Config parsing is real constructor behaviour — assert parsed values. */
    public function testValkeyConfigParsing(): void
    {
        $handler = new ValkeySessionHandler([
            'host' => 'valkey.local',
            'port' => 6380,
            'password' => 'mypass',
            'db' => 3,
            'ttl' => 7200,
            'keyPrefix' => 'app:sess:',
        ]);
        $ref = new \ReflectionClass($handler);
        $this->assertSame('valkey.local', $ref->getProperty('host')->getValue($handler));
        $this->assertSame(6380, $ref->getProperty('port')->getValue($handler));
        $this->assertSame('mypass', $ref->getProperty('password')->getValue($handler));
        $this->assertSame(3, $ref->getProperty('db')->getValue($handler));
        $this->assertSame(7200, $ref->getProperty('ttl')->getValue($handler));
        $this->assertSame('app:sess:', $ref->getProperty('keyPrefix')->getValue($handler));
    }

    public function testValkeyDefaultsAndEnvOverrides(): void
    {
        // Defaults
        $default = new ValkeySessionHandler();
        $ref = new \ReflectionClass($default);
        $this->assertSame('tina4:session:', $ref->getProperty('keyPrefix')->getValue($default));
        $this->assertNull($ref->getProperty('password')->getValue($default), 'no password by default');
        $this->assertSame(3600, $ref->getProperty('ttl')->getValue($default));

        // Env vars feed the constructor; explicit config must still win.
        putenv('TINA4_SESSION_VALKEY_HOST=envhost');
        putenv('TINA4_SESSION_VALKEY_PORT=6381');
        putenv('TINA4_SESSION_VALKEY_PASSWORD=envpassword');
        putenv('TINA4_SESSION_VALKEY_DB=5');
        putenv('TINA4_SESSION_TTL=900');
        try {
            $fromEnv = new ValkeySessionHandler();
            $r = new \ReflectionClass($fromEnv);
            $this->assertSame('envhost', $r->getProperty('host')->getValue($fromEnv));
            $this->assertSame(6381, $r->getProperty('port')->getValue($fromEnv));
            $this->assertSame('envpassword', $r->getProperty('password')->getValue($fromEnv));
            $this->assertSame(5, $r->getProperty('db')->getValue($fromEnv));
            $this->assertSame(900, $r->getProperty('ttl')->getValue($fromEnv));

            $override = new ValkeySessionHandler(['host' => 'confighost']);
            $this->assertSame(
                'confighost',
                (new \ReflectionClass($override))->getProperty('host')->getValue($override),
                'explicit config must override env var'
            );
        } finally {
            putenv('TINA4_SESSION_VALKEY_HOST');
            putenv('TINA4_SESSION_VALKEY_PORT');
            putenv('TINA4_SESSION_VALKEY_PASSWORD');
            putenv('TINA4_SESSION_VALKEY_DB');
            putenv('TINA4_SESSION_TTL');
        }
    }

    // =====================================================================
    //  RedisSessionHandler — real Redis round-trips
    // =====================================================================

    private function redisHandler(int $ttl = 60, string $prefix = 'tina4test:redis:'): RedisSessionHandler
    {
        return new RedisSessionHandler([
            'host' => 'localhost',
            'port' => self::REDIS_PORT,
            'ttl' => $ttl,
            'keyPrefix' => $prefix,
        ]);
    }

    public function testRedisWriteReadDestroyCycle(): void
    {
        $this->requireService('localhost', self::REDIS_PORT, 'Redis');

        $handler = $this->redisHandler();
        $id = $this->sid('redis-cycle');

        $handler->write($id, ['lang' => 'en', 'user_id' => 99]);
        $this->assertSame(['lang' => 'en', 'user_id' => 99], $handler->read($id));
        $this->assertTrue($handler->exists($id));

        $handler->destroy($id);
        $this->assertSame([], $handler->read($id));
        $this->assertFalse($handler->exists($id));

        $handler->close();
    }

    public function testRedisReadMissingSessionReturnsEmpty(): void
    {
        $this->requireService('localhost', self::REDIS_PORT, 'Redis');

        $handler = $this->redisHandler();
        $this->assertSame([], $handler->read($this->sid('redis-missing')));
        $handler->close();
    }

    public function testRedisTouchKeepsSessionAlive(): void
    {
        $this->requireService('localhost', self::REDIS_PORT, 'Redis');

        $handler = $this->redisHandler(ttl: 1000);
        $id = $this->sid('redis-touch');
        $handler->write($id, ['k' => 'v']);
        $handler->touch($id);
        $this->assertTrue($handler->exists($id));
        $this->assertSame(['k' => 'v'], $handler->read($id));
        $handler->destroy($id);
        $handler->close();
    }

    public function testRedisConfigParsing(): void
    {
        $handler = new RedisSessionHandler([
            'host' => 'redis.example.com',
            'port' => 6380,
            'password' => 'pass123',
            'db' => 2,
            'ttl' => 7200,
            'keyPrefix' => 'myapp:sess:',
        ]);
        $ref = new \ReflectionClass($handler);
        $this->assertSame('redis.example.com', $ref->getProperty('host')->getValue($handler));
        $this->assertSame(6380, $ref->getProperty('port')->getValue($handler));
        $this->assertSame('pass123', $ref->getProperty('password')->getValue($handler));
        $this->assertSame(2, $ref->getProperty('db')->getValue($handler));
        $this->assertSame(7200, $ref->getProperty('ttl')->getValue($handler));
        $this->assertSame('myapp:sess:', $ref->getProperty('keyPrefix')->getValue($handler));
    }

    public function testRedisDefaultsAndEnvOverrides(): void
    {
        $default = new RedisSessionHandler();
        $ref = new \ReflectionClass($default);
        $this->assertSame('localhost', $ref->getProperty('host')->getValue($default));
        $this->assertSame(6379, $ref->getProperty('port')->getValue($default));
        $this->assertSame(0, $ref->getProperty('db')->getValue($default));
        $this->assertSame(3600, $ref->getProperty('ttl')->getValue($default));
        $this->assertSame('tina4:session:', $ref->getProperty('keyPrefix')->getValue($default));
        $this->assertNull($ref->getProperty('password')->getValue($default));

        putenv('TINA4_SESSION_REDIS_HOST=envhost');
        putenv('TINA4_SESSION_REDIS_PORT=6381');
        putenv('TINA4_SESSION_REDIS_PASSWORD=envpassword');
        putenv('TINA4_SESSION_REDIS_DB=5');
        putenv('TINA4_SESSION_TTL=900');
        try {
            $fromEnv = new RedisSessionHandler();
            $r = new \ReflectionClass($fromEnv);
            $this->assertSame('envhost', $r->getProperty('host')->getValue($fromEnv));
            $this->assertSame(6381, $r->getProperty('port')->getValue($fromEnv));
            $this->assertSame('envpassword', $r->getProperty('password')->getValue($fromEnv));
            $this->assertSame(5, $r->getProperty('db')->getValue($fromEnv));
            $this->assertSame(900, $r->getProperty('ttl')->getValue($fromEnv));

            $override = new RedisSessionHandler(['host' => 'confighost']);
            $this->assertSame(
                'confighost',
                (new \ReflectionClass($override))->getProperty('host')->getValue($override)
            );
        } finally {
            putenv('TINA4_SESSION_REDIS_HOST');
            putenv('TINA4_SESSION_REDIS_PORT');
            putenv('TINA4_SESSION_REDIS_PASSWORD');
            putenv('TINA4_SESSION_REDIS_DB');
            putenv('TINA4_SESSION_TTL');
        }
    }

    // =====================================================================
    //  DatabaseSessionHandler — real SQLite + live PostgreSQL round-trips
    //  (no mock adapter; the dedicated SQLite behaviour suite lives in
    //   DatabaseSessionHandlerTest.php — here we add a PostgreSQL round-trip
    //   and a TTL-config assertion against a real adapter.)
    // =====================================================================

    public function testDatabaseWriteReadDestroyCycleSqlite(): void
    {
        $adapter = new SQLite3Adapter(':memory:');
        $handler = new DatabaseSessionHandler(['db' => $adapter, 'ttl' => 1800]);
        $id = $this->sid('db-sqlite');

        $handler->write($id, ['user_id' => 1, 'name' => 'Alice']);
        $this->assertSame(['user_id' => 1, 'name' => 'Alice'], $handler->read($id));

        $handler->destroy($id);
        $this->assertNull($handler->read($id), 'session must be gone after destroy()');

        $adapter->close();
    }

    public function testDatabaseExpiredSessionIsPurgedOnRead(): void
    {
        $adapter = new SQLite3Adapter(':memory:');
        $handler = new DatabaseSessionHandler(['db' => $adapter, 'ttl' => 1]);
        $id = $this->sid('db-expire');

        $handler->write($id, ['x' => 1]);
        $this->assertSame(['x' => 1], $handler->read($id));

        sleep(2);
        $this->assertNull($handler->read($id), 'expired session must read null and be purged');

        $adapter->close();
    }

    public function testDatabaseGcRemovesExpiredKeepsValid(): void
    {
        $adapter = new SQLite3Adapter(':memory:');
        // Short default TTL so the two we write now are already expired after sleep.
        $expiring = new DatabaseSessionHandler(['db' => $adapter, 'ttl' => 1]);
        $expiring->write($this->sid('gc-old'), ['a' => 1]);
        $oldId = $this->sid('gc-old2');
        $expiring->write($oldId, ['b' => 2]);

        $longLived = new DatabaseSessionHandler(['db' => $adapter, 'ttl' => 3600]);
        $validId = $this->sid('gc-valid');
        $longLived->write($validId, ['c' => 3]);

        sleep(2);
        $longLived->gc(0);

        $this->assertSame(['c' => 3], $longLived->read($validId), 'valid session must survive gc()');
        $this->assertNull($longLived->read($oldId), 'expired session must be gc-removed');

        $adapter->close();
    }

    public function testDatabaseWriteReadDestroyCyclePostgres(): void
    {
        $this->requireService('localhost', self::PG_PORT, 'PostgreSQL');

        $db = Database::create('postgres://localhost:' . self::PG_PORT . '/tina4_php', username: 'tina4', password: 'tina4');
        $this->assertNotNull($db, 'PostgreSQL connection must be available');

        $handler = new DatabaseSessionHandler(['db' => $db, 'ttl' => 1800]);
        $id = $this->sid('db-pg');

        try {
            $handler->write($id, ['user_id' => 7, 'role' => 'editor']);
            $this->assertSame(['user_id' => 7, 'role' => 'editor'], $handler->read($id));

            // The session_id cookie value is bound as a parameter — a quote in it
            // must never break the query (SQL-injection guard).
            $injectId = $this->sid("db-pg-inject'\";--");
            $handler->write($injectId, ['safe' => true]);
            $this->assertSame(['safe' => true], $handler->read($injectId));
            $handler->destroy($injectId);
            $this->assertNull($handler->read($injectId));

            $handler->destroy($id);
            $this->assertNull($handler->read($id), 'session must be gone after destroy()');
        } finally {
            $handler->destroy($id);
            $db->close();
        }
    }

    public function testDatabaseReadMissingSessionReturnsNull(): void
    {
        $adapter = new SQLite3Adapter(':memory:');
        $handler = new DatabaseSessionHandler(['db' => $adapter]);
        $this->assertNull($handler->read($this->sid('db-missing')));
        $adapter->close();
    }

    /** TTL config is real constructor behaviour — assert it against a REAL adapter. */
    public function testDatabaseTtlConfigAndDefault(): void
    {
        $adapter = new SQLite3Adapter(':memory:');

        $custom = new DatabaseSessionHandler(['db' => $adapter, 'ttl' => 900]);
        $this->assertSame(
            900,
            (new \ReflectionClass($custom))->getProperty('ttl')->getValue($custom)
        );

        $default = new DatabaseSessionHandler(['db' => $adapter]);
        $this->assertSame(
            3600,
            (new \ReflectionClass($default))->getProperty('ttl')->getValue($default)
        );

        putenv('TINA4_SESSION_TTL=7200');
        try {
            $fromEnv = new DatabaseSessionHandler(['db' => $adapter]);
            $this->assertSame(
                7200,
                (new \ReflectionClass($fromEnv))->getProperty('ttl')->getValue($fromEnv)
            );
        } finally {
            putenv('TINA4_SESSION_TTL');
        }

        $adapter->close();
    }

    public function testDatabaseCloseIsNoopAndDoesNotBreakAdapter(): void
    {
        $adapter = new SQLite3Adapter(':memory:');
        $handler = new DatabaseSessionHandler(['db' => $adapter]);

        $id = $this->sid('db-close');
        $handler->write($id, ['v' => 1]);
        $handler->close(); // no-op for DB handler — adapter owns its connection

        // Adapter is still usable after the handler's close().
        $this->assertSame(['v' => 1], $handler->read($id));
        $adapter->close();
    }

    public function testDatabaseThrowsWithoutDbConfiguration(): void
    {
        $prev = getenv('TINA4_DATABASE_URL');
        putenv('TINA4_DATABASE_URL');
        unset($_ENV['TINA4_DATABASE_URL'], $_SERVER['TINA4_DATABASE_URL']);
        \Tina4\DotEnv::resetEnv();

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('No database connection available');
            new DatabaseSessionHandler();
        } finally {
            if ($prev !== false) {
                putenv("TINA4_DATABASE_URL={$prev}");
            }
        }
    }
}
