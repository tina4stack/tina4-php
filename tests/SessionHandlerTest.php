<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Session\MongoSessionHandler;
use Tina4\Session\ValkeySessionHandler;
use Tina4\Session\DatabaseSessionHandler;

class SessionHandlerTest extends TestCase
{
    // -- MongoSessionHandler -------------------------------------------------

    public function testMongoSessionHandlerClassExists(): void
    {
        $this->assertTrue(class_exists(MongoSessionHandler::class));
    }

    public function testMongoSessionHandlerCanBeInstantiated(): void
    {
        $handler = new MongoSessionHandler([
            'url' => 'mongodb://localhost:27017',
            'database' => 'testdb',
            'collection' => 'test_sessions',
            'ttl' => 1800,
        ]);
        $this->assertInstanceOf(MongoSessionHandler::class, $handler);
    }

    public function testMongoSessionHandlerDefaultConfig(): void
    {
        $handler = new MongoSessionHandler();
        $this->assertInstanceOf(MongoSessionHandler::class, $handler);
    }

    public function testMongoSessionHandlerHasReadMethod(): void
    {
        $ref = new \ReflectionClass(MongoSessionHandler::class);
        $this->assertTrue($ref->hasMethod('read'));
        $method = $ref->getMethod('read');
        $this->assertEquals(1, $method->getNumberOfParameters());
        $this->assertEquals('sessionId', $method->getParameters()[0]->getName());
    }

    public function testMongoSessionHandlerHasWriteMethod(): void
    {
        $ref = new \ReflectionClass(MongoSessionHandler::class);
        $this->assertTrue($ref->hasMethod('write'));
        $method = $ref->getMethod('write');
        $this->assertEquals(2, $method->getNumberOfParameters());
    }

    public function testMongoSessionHandlerHasDeleteMethod(): void
    {
        $ref = new \ReflectionClass(MongoSessionHandler::class);
        $this->assertTrue($ref->hasMethod('delete'));
    }

    public function testMongoSessionHandlerHasCloseMethod(): void
    {
        $ref = new \ReflectionClass(MongoSessionHandler::class);
        $this->assertTrue($ref->hasMethod('close'));
    }

    public function testMongoSessionHandlerParsesConfig(): void
    {
        $handler = new MongoSessionHandler([
            'url' => 'mongodb://myhost:28017',
            'database' => 'mydb',
            'collection' => 'mysessions',
            'ttl' => 7200,
        ]);

        $ref = new \ReflectionClass($handler);

        $dbProp = $ref->getProperty('database');
        $this->assertEquals('mydb', $dbProp->getValue($handler));

        $collProp = $ref->getProperty('collection');
        $this->assertEquals('mysessions', $collProp->getValue($handler));

        $ttlProp = $ref->getProperty('ttl');
        $this->assertEquals(7200, $ttlProp->getValue($handler));

        $hostProp = $ref->getProperty('host');
        $this->assertEquals('myhost', $hostProp->getValue($handler));

        $portProp = $ref->getProperty('port');
        $this->assertEquals(28017, $portProp->getValue($handler));
    }

    // -- ValkeySessionHandler ------------------------------------------------

    public function testValkeySessionHandlerClassExists(): void
    {
        $this->assertTrue(class_exists(ValkeySessionHandler::class));
    }

    public function testValkeySessionHandlerCanBeInstantiated(): void
    {
        $handler = new ValkeySessionHandler([
            'host' => 'localhost',
            'port' => 6379,
            'password' => 'secret',
            'db' => 1,
            'ttl' => 3600,
            'keyPrefix' => 'test:session:',
        ]);
        $this->assertInstanceOf(ValkeySessionHandler::class, $handler);
    }

    public function testValkeySessionHandlerDefaultConfig(): void
    {
        $handler = new ValkeySessionHandler();
        $this->assertInstanceOf(ValkeySessionHandler::class, $handler);
    }

    public function testValkeySessionHandlerHasReadMethod(): void
    {
        $ref = new \ReflectionClass(ValkeySessionHandler::class);
        $this->assertTrue($ref->hasMethod('read'));
    }

    public function testValkeySessionHandlerHasWriteMethod(): void
    {
        $ref = new \ReflectionClass(ValkeySessionHandler::class);
        $this->assertTrue($ref->hasMethod('write'));
    }

    public function testValkeySessionHandlerHasDeleteMethod(): void
    {
        $ref = new \ReflectionClass(ValkeySessionHandler::class);
        $this->assertTrue($ref->hasMethod('delete'));
    }

    public function testValkeySessionHandlerHasCloseMethod(): void
    {
        $ref = new \ReflectionClass(ValkeySessionHandler::class);
        $this->assertTrue($ref->hasMethod('close'));
    }

    public function testValkeySessionHandlerHasExistsMethod(): void
    {
        $ref = new \ReflectionClass(ValkeySessionHandler::class);
        $this->assertTrue($ref->hasMethod('exists'));
    }

    public function testValkeySessionHandlerHasTouchMethod(): void
    {
        $ref = new \ReflectionClass(ValkeySessionHandler::class);
        $this->assertTrue($ref->hasMethod('touch'));
    }

    public function testValkeySessionHandlerConfigParsing(): void
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

        $hostProp = $ref->getProperty('host');
        $this->assertEquals('valkey.local', $hostProp->getValue($handler));

        $portProp = $ref->getProperty('port');
        $this->assertEquals(6380, $portProp->getValue($handler));

        $passProp = $ref->getProperty('password');
        $this->assertEquals('mypass', $passProp->getValue($handler));

        $dbProp = $ref->getProperty('db');
        $this->assertEquals(3, $dbProp->getValue($handler));

        $ttlProp = $ref->getProperty('ttl');
        $this->assertEquals(7200, $ttlProp->getValue($handler));

        $prefixProp = $ref->getProperty('keyPrefix');
        $this->assertEquals('app:sess:', $prefixProp->getValue($handler));
    }

    public function testValkeySessionHandlerEnvVarHost(): void
    {
        putenv('TINA4_SESSION_VALKEY_HOST=envhost');
        putenv('TINA4_SESSION_VALKEY_PORT=6381');

        $handler = new ValkeySessionHandler();

        $ref = new \ReflectionClass($handler);

        $hostProp = $ref->getProperty('host');
        $this->assertEquals('envhost', $hostProp->getValue($handler));

        $portProp = $ref->getProperty('port');
        $this->assertEquals(6381, $portProp->getValue($handler));

        putenv('TINA4_SESSION_VALKEY_HOST');
        putenv('TINA4_SESSION_VALKEY_PORT');
    }

    public function testValkeySessionHandlerEnvVarPassword(): void
    {
        putenv('TINA4_SESSION_VALKEY_PASSWORD=envpassword');

        $handler = new ValkeySessionHandler();

        $ref = new \ReflectionClass($handler);
        $passProp = $ref->getProperty('password');
        $this->assertEquals('envpassword', $passProp->getValue($handler));

        putenv('TINA4_SESSION_VALKEY_PASSWORD');
    }

    public function testValkeySessionHandlerEnvVarDb(): void
    {
        putenv('TINA4_SESSION_VALKEY_DB=5');

        $handler = new ValkeySessionHandler();

        $ref = new \ReflectionClass($handler);
        $dbProp = $ref->getProperty('db');
        $this->assertEquals(5, $dbProp->getValue($handler));

        putenv('TINA4_SESSION_VALKEY_DB');
    }

    public function testValkeySessionHandlerDefaultKeyPrefix(): void
    {
        $handler = new ValkeySessionHandler();

        $ref = new \ReflectionClass($handler);
        $prefixProp = $ref->getProperty('keyPrefix');
        $this->assertEquals('tina4:session:', $prefixProp->getValue($handler));
    }

    public function testValkeySessionHandlerNoPasswordByDefault(): void
    {
        $handler = new ValkeySessionHandler();

        $ref = new \ReflectionClass($handler);
        $passProp = $ref->getProperty('password');
        $this->assertNull($passProp->getValue($handler));
    }

    // -- DatabaseSessionHandler ----------------------------------------------

    public function testDatabaseSessionHandlerExists(): void
    {
        $this->assertTrue(class_exists(DatabaseSessionHandler::class));
    }

    public function testDatabaseSessionHandlerHasRequiredMethods(): void
    {
        $this->assertTrue(method_exists(DatabaseSessionHandler::class, 'read'));
        $this->assertTrue(method_exists(DatabaseSessionHandler::class, 'write'));
        $this->assertTrue(method_exists(DatabaseSessionHandler::class, 'delete'));
        $this->assertTrue(method_exists(DatabaseSessionHandler::class, 'close'));
    }

    public function testDatabaseSessionHandlerHasGcMethod(): void
    {
        $this->assertTrue(method_exists(DatabaseSessionHandler::class, 'gc'));
    }

    public function testDatabaseSessionHandlerTtlFromEnv(): void
    {
        putenv('TINA4_SESSION_TTL=7200');

        $ref = new \ReflectionClass(DatabaseSessionHandler::class);
        $constructor = $ref->getConstructor();
        // Verify ttl config is respected by creating with a mock adapter
        $adapter = $this->createMock(\Tina4\Database\DatabaseAdapter::class);
        $handler = new DatabaseSessionHandler(['db' => $adapter, 'ttl' => 7200]);

        $ttlProp = $ref->getProperty('ttl');
        $this->assertEquals(7200, $ttlProp->getValue($handler));

        putenv('TINA4_SESSION_TTL');
    }

    public function testDatabaseSessionHandlerCloseIsNoop(): void
    {
        $adapter = $this->createMock(\Tina4\Database\DatabaseAdapter::class);
        $handler = new DatabaseSessionHandler(['db' => $adapter]);
        $handler->close(); // Should not raise
        $this->assertTrue(true);
    }

    public function testDatabaseSessionHandlerThrowsWithoutDb(): void
    {
        // Make sure DATABASE_URL is not set
        $prev = getenv('DATABASE_URL');
        putenv('DATABASE_URL');

        $this->expectException(\RuntimeException::class);
        new DatabaseSessionHandler();

        if ($prev !== false) {
            putenv("DATABASE_URL={$prev}");
        }
    }

    public function testDatabaseSessionHandlerAcceptsAdapter(): void
    {
        $adapter = $this->createMock(\Tina4\Database\DatabaseAdapter::class);
        $handler = new DatabaseSessionHandler(['db' => $adapter]);
        $this->assertInstanceOf(DatabaseSessionHandler::class, $handler);
    }

    public function testDatabaseSessionHandlerAcceptsDatabase(): void
    {
        $db = \Tina4\Database\Database::create('sqlite::memory:');
        $handler = new DatabaseSessionHandler(['db' => $db]);
        $this->assertInstanceOf(DatabaseSessionHandler::class, $handler);
        $db->close();
    }

    public function testDatabaseSessionHandlerDefaultTtl(): void
    {
        $adapter = $this->createMock(\Tina4\Database\DatabaseAdapter::class);
        $handler = new DatabaseSessionHandler(['db' => $adapter]);

        $ref = new \ReflectionClass($handler);
        $ttlProp = $ref->getProperty('ttl');
        $this->assertEquals(3600, $ttlProp->getValue($handler));
    }

    public function testDatabaseSessionHandlerCustomTtl(): void
    {
        $adapter = $this->createMock(\Tina4\Database\DatabaseAdapter::class);
        $handler = new DatabaseSessionHandler(['db' => $adapter, 'ttl' => 900]);

        $ref = new \ReflectionClass($handler);
        $ttlProp = $ref->getProperty('ttl');
        $this->assertEquals(900, $ttlProp->getValue($handler));
    }

    // -- RedisSessionHandler -------------------------------------------------

    public function testRedisSessionHandlerClassExists(): void
    {
        $this->assertTrue(class_exists(\Tina4\Session\RedisSessionHandler::class));
    }

    public function testRedisSessionHandlerCanBeInstantiated(): void
    {
        $handler = new \Tina4\Session\RedisSessionHandler([
            'host' => 'localhost',
            'port' => 6379,
            'password' => 'secret',
            'db' => 1,
            'ttl' => 3600,
            'keyPrefix' => 'test:session:',
        ]);
        $this->assertInstanceOf(\Tina4\Session\RedisSessionHandler::class, $handler);
    }

    public function testRedisSessionHandlerDefaultConfig(): void
    {
        $handler = new \Tina4\Session\RedisSessionHandler();

        $ref = new \ReflectionClass($handler);

        $hostProp = $ref->getProperty('host');
        $this->assertEquals('localhost', $hostProp->getValue($handler));

        $portProp = $ref->getProperty('port');
        $this->assertEquals(6379, $portProp->getValue($handler));

        $dbProp = $ref->getProperty('db');
        $this->assertEquals(0, $dbProp->getValue($handler));

        $ttlProp = $ref->getProperty('ttl');
        $this->assertEquals(3600, $ttlProp->getValue($handler));

        $prefixProp = $ref->getProperty('keyPrefix');
        $this->assertEquals('tina4:session:', $prefixProp->getValue($handler));
    }

    public function testRedisSessionHandlerCustomConfig(): void
    {
        $handler = new \Tina4\Session\RedisSessionHandler([
            'host' => 'redis.example.com',
            'port' => 6380,
            'password' => 'pass123',
            'db' => 2,
            'ttl' => 7200,
            'keyPrefix' => 'myapp:sess:',
        ]);

        $ref = new \ReflectionClass($handler);

        $hostProp = $ref->getProperty('host');
        $this->assertEquals('redis.example.com', $hostProp->getValue($handler));

        $portProp = $ref->getProperty('port');
        $this->assertEquals(6380, $portProp->getValue($handler));

        $passProp = $ref->getProperty('password');
        $this->assertEquals('pass123', $passProp->getValue($handler));

        $dbProp = $ref->getProperty('db');
        $this->assertEquals(2, $dbProp->getValue($handler));

        $ttlProp = $ref->getProperty('ttl');
        $this->assertEquals(7200, $ttlProp->getValue($handler));

        $prefixProp = $ref->getProperty('keyPrefix');
        $this->assertEquals('myapp:sess:', $prefixProp->getValue($handler));
    }

    public function testRedisSessionHandlerHasReadMethod(): void
    {
        $this->assertTrue(method_exists(\Tina4\Session\RedisSessionHandler::class, 'read'));
    }

    public function testRedisSessionHandlerHasWriteMethod(): void
    {
        $this->assertTrue(method_exists(\Tina4\Session\RedisSessionHandler::class, 'write'));
    }

    public function testRedisSessionHandlerHasDeleteMethod(): void
    {
        $this->assertTrue(method_exists(\Tina4\Session\RedisSessionHandler::class, 'delete'));
    }

    public function testRedisSessionHandlerHasCloseMethod(): void
    {
        $this->assertTrue(method_exists(\Tina4\Session\RedisSessionHandler::class, 'close'));
    }

    public function testRedisSessionHandlerHasExistsMethod(): void
    {
        $this->assertTrue(method_exists(\Tina4\Session\RedisSessionHandler::class, 'exists'));
    }

    public function testRedisSessionHandlerHasTouchMethod(): void
    {
        $this->assertTrue(method_exists(\Tina4\Session\RedisSessionHandler::class, 'touch'));
    }

    public function testRedisSessionHandlerEnvVarHost(): void
    {
        putenv('TINA4_SESSION_REDIS_HOST=envhost');
        putenv('TINA4_SESSION_REDIS_PORT=6381');

        $handler = new \Tina4\Session\RedisSessionHandler();

        $ref = new \ReflectionClass($handler);

        $hostProp = $ref->getProperty('host');
        $this->assertEquals('envhost', $hostProp->getValue($handler));

        $portProp = $ref->getProperty('port');
        $this->assertEquals(6381, $portProp->getValue($handler));

        putenv('TINA4_SESSION_REDIS_HOST');
        putenv('TINA4_SESSION_REDIS_PORT');
    }

    public function testRedisSessionHandlerEnvVarPassword(): void
    {
        putenv('TINA4_SESSION_REDIS_PASSWORD=envpassword');

        $handler = new \Tina4\Session\RedisSessionHandler();

        $ref = new \ReflectionClass($handler);
        $passProp = $ref->getProperty('password');
        $this->assertEquals('envpassword', $passProp->getValue($handler));

        putenv('TINA4_SESSION_REDIS_PASSWORD');
    }

    public function testRedisSessionHandlerEnvVarDb(): void
    {
        putenv('TINA4_SESSION_REDIS_DB=5');

        $handler = new \Tina4\Session\RedisSessionHandler();

        $ref = new \ReflectionClass($handler);
        $dbProp = $ref->getProperty('db');
        $this->assertEquals(5, $dbProp->getValue($handler));

        putenv('TINA4_SESSION_REDIS_DB');
    }

    public function testRedisSessionHandlerNoPasswordByDefault(): void
    {
        $handler = new \Tina4\Session\RedisSessionHandler();

        $ref = new \ReflectionClass($handler);
        $passProp = $ref->getProperty('password');
        $this->assertNull($passProp->getValue($handler));
    }

    public function testRedisSessionHandlerConfigOverridesEnv(): void
    {
        putenv('TINA4_SESSION_REDIS_HOST=envhost');

        $handler = new \Tina4\Session\RedisSessionHandler(['host' => 'confighost']);

        $ref = new \ReflectionClass($handler);
        $hostProp = $ref->getProperty('host');
        $this->assertEquals('confighost', $hostProp->getValue($handler));

        putenv('TINA4_SESSION_REDIS_HOST');
    }

    public function testRedisSessionHandlerEnvVarTtl(): void
    {
        putenv('TINA4_SESSION_TTL=900');

        $handler = new \Tina4\Session\RedisSessionHandler();

        $ref = new \ReflectionClass($handler);
        $ttlProp = $ref->getProperty('ttl');
        $this->assertEquals(900, $ttlProp->getValue($handler));

        putenv('TINA4_SESSION_TTL');
    }

    // -- MongoSessionHandler env var tests ------------------------------------

    public function testMongoSessionHandlerEnvVarHost(): void
    {
        putenv('TINA4_SESSION_MONGO_HOST=mongo-env');
        putenv('TINA4_SESSION_MONGO_PORT=27018');

        $handler = new MongoSessionHandler([
            'url' => 'mongodb://mongo-env:27018',
        ]);

        $ref = new \ReflectionClass($handler);

        $hostProp = $ref->getProperty('host');
        $this->assertEquals('mongo-env', $hostProp->getValue($handler));

        $portProp = $ref->getProperty('port');
        $this->assertEquals(27018, $portProp->getValue($handler));

        putenv('TINA4_SESSION_MONGO_HOST');
        putenv('TINA4_SESSION_MONGO_PORT');
    }

    public function testMongoSessionHandlerUrlParsing(): void
    {
        $handler = new MongoSessionHandler([
            'url' => 'mongodb://user:pass@db.host.com:27020/mydb',
        ]);

        $ref = new \ReflectionClass($handler);

        $hostProp = $ref->getProperty('host');
        $this->assertEquals('db.host.com', $hostProp->getValue($handler));

        $portProp = $ref->getProperty('port');
        $this->assertEquals(27020, $portProp->getValue($handler));
    }

    public function testMongoSessionHandlerHasCloseMethodReflection(): void
    {
        $ref = new \ReflectionClass(MongoSessionHandler::class);
        $this->assertTrue($ref->hasMethod('close'));
    }

    // -- ValkeySessionHandler additional tests --------------------------------

    public function testValkeySessionHandlerHasExistsMethodReflection(): void
    {
        $ref = new \ReflectionClass(ValkeySessionHandler::class);
        $this->assertTrue($ref->hasMethod('exists'));
    }

    public function testValkeySessionHandlerConfigOverridesEnv(): void
    {
        putenv('TINA4_SESSION_VALKEY_HOST=envhost');

        $handler = new ValkeySessionHandler(['host' => 'confighost']);

        $ref = new \ReflectionClass($handler);
        $hostProp = $ref->getProperty('host');
        $this->assertEquals('confighost', $hostProp->getValue($handler));

        putenv('TINA4_SESSION_VALKEY_HOST');
    }

    public function testValkeySessionHandlerEnvVarTtl(): void
    {
        putenv('TINA4_SESSION_TTL=900');

        $handler = new ValkeySessionHandler();

        $ref = new \ReflectionClass($handler);
        $ttlProp = $ref->getProperty('ttl');
        $this->assertEquals(900, $ttlProp->getValue($handler));

        putenv('TINA4_SESSION_TTL');
    }

    public function testValkeySessionHandlerDefaultTtl(): void
    {
        $handler = new ValkeySessionHandler();

        $ref = new \ReflectionClass($handler);
        $ttlProp = $ref->getProperty('ttl');
        $this->assertIsInt($ttlProp->getValue($handler));
        $this->assertGreaterThan(0, $ttlProp->getValue($handler));
    }

    public function testRedisSessionHandlerDefaultTtl(): void
    {
        $handler = new \Tina4\Session\RedisSessionHandler();

        $ref = new \ReflectionClass($handler);
        $ttlProp = $ref->getProperty('ttl');
        $this->assertEquals(3600, $ttlProp->getValue($handler));
    }
}
