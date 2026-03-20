<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Session\MongoSessionHandler;
use Tina4\Session\ValkeySessionHandler;

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
        $dbProp->setAccessible(true);
        $this->assertEquals('mydb', $dbProp->getValue($handler));

        $collProp = $ref->getProperty('collection');
        $collProp->setAccessible(true);
        $this->assertEquals('mysessions', $collProp->getValue($handler));

        $ttlProp = $ref->getProperty('ttl');
        $ttlProp->setAccessible(true);
        $this->assertEquals(7200, $ttlProp->getValue($handler));

        $hostProp = $ref->getProperty('host');
        $hostProp->setAccessible(true);
        $this->assertEquals('myhost', $hostProp->getValue($handler));

        $portProp = $ref->getProperty('port');
        $portProp->setAccessible(true);
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
        $hostProp->setAccessible(true);
        $this->assertEquals('valkey.local', $hostProp->getValue($handler));

        $portProp = $ref->getProperty('port');
        $portProp->setAccessible(true);
        $this->assertEquals(6380, $portProp->getValue($handler));

        $passProp = $ref->getProperty('password');
        $passProp->setAccessible(true);
        $this->assertEquals('mypass', $passProp->getValue($handler));

        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $this->assertEquals(3, $dbProp->getValue($handler));

        $ttlProp = $ref->getProperty('ttl');
        $ttlProp->setAccessible(true);
        $this->assertEquals(7200, $ttlProp->getValue($handler));

        $prefixProp = $ref->getProperty('keyPrefix');
        $prefixProp->setAccessible(true);
        $this->assertEquals('app:sess:', $prefixProp->getValue($handler));
    }

    public function testValkeySessionHandlerEnvVarHost(): void
    {
        putenv('TINA4_SESSION_VALKEY_HOST=envhost');
        putenv('TINA4_SESSION_VALKEY_PORT=6381');

        $handler = new ValkeySessionHandler();

        $ref = new \ReflectionClass($handler);

        $hostProp = $ref->getProperty('host');
        $hostProp->setAccessible(true);
        $this->assertEquals('envhost', $hostProp->getValue($handler));

        $portProp = $ref->getProperty('port');
        $portProp->setAccessible(true);
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
        $passProp->setAccessible(true);
        $this->assertEquals('envpassword', $passProp->getValue($handler));

        putenv('TINA4_SESSION_VALKEY_PASSWORD');
    }

    public function testValkeySessionHandlerEnvVarDb(): void
    {
        putenv('TINA4_SESSION_VALKEY_DB=5');

        $handler = new ValkeySessionHandler();

        $ref = new \ReflectionClass($handler);
        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $this->assertEquals(5, $dbProp->getValue($handler));

        putenv('TINA4_SESSION_VALKEY_DB');
    }

    public function testValkeySessionHandlerDefaultKeyPrefix(): void
    {
        $handler = new ValkeySessionHandler();

        $ref = new \ReflectionClass($handler);
        $prefixProp = $ref->getProperty('keyPrefix');
        $prefixProp->setAccessible(true);
        $this->assertEquals('tina4:session:', $prefixProp->getValue($handler));
    }

    public function testValkeySessionHandlerNoPasswordByDefault(): void
    {
        $handler = new ValkeySessionHandler();

        $ref = new \ReflectionClass($handler);
        $passProp = $ref->getProperty('password');
        $passProp->setAccessible(true);
        $this->assertNull($passProp->getValue($handler));
    }
}
