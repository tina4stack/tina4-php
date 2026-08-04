<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\SQLite3Adapter;
use Tina4\Session\DatabaseSessionHandler;

class DatabaseSessionHandlerTest extends TestCase
{
    // -- Class exists -----------------------------------------------------------

    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(DatabaseSessionHandler::class));
    }

    // -- Required methods -------------------------------------------------------

    public function testHasReadMethod(): void
    {
        $ref = new \ReflectionClass(DatabaseSessionHandler::class);
        $this->assertTrue($ref->hasMethod('read'));

        $method = $ref->getMethod('read');
        $this->assertEquals(1, $method->getNumberOfParameters());
        $this->assertEquals('sessionId', $method->getParameters()[0]->getName());
    }

    public function testHasWriteMethod(): void
    {
        $ref = new \ReflectionClass(DatabaseSessionHandler::class);
        $this->assertTrue($ref->hasMethod('write'));

        $method = $ref->getMethod('write');
        $this->assertEquals(3, $method->getNumberOfParameters());
        $this->assertEquals('sessionId', $method->getParameters()[0]->getName());
        $this->assertEquals('data', $method->getParameters()[1]->getName());
        $this->assertEquals('ttl', $method->getParameters()[2]->getName());
    }

    public function testHasDeleteMethod(): void
    {
        $ref = new \ReflectionClass(DatabaseSessionHandler::class);
        $this->assertTrue($ref->hasMethod('delete'));

        $method = $ref->getMethod('delete');
        $this->assertEquals(1, $method->getNumberOfParameters());
        $this->assertEquals('sessionId', $method->getParameters()[0]->getName());
    }

    public function testHasCloseMethod(): void
    {
        $ref = new \ReflectionClass(DatabaseSessionHandler::class);
        $this->assertTrue($ref->hasMethod('close'));

        $method = $ref->getMethod('close');
        $this->assertEquals(0, $method->getNumberOfRequiredParameters());
    }

    // -- Constructor accepts DatabaseAdapter ------------------------------------

    public function testConstructorAcceptsDatabaseAdapter(): void
    {
        $adapter = new SQLite3Adapter(':memory:');
        $handler = new DatabaseSessionHandler(['db' => $adapter, 'ttl' => 1800]);

        $this->assertInstanceOf(DatabaseSessionHandler::class, $handler);
        $adapter->close();
    }

    public function testConstructorAcceptsDatabaseWrapper(): void
    {
        $db = Database::create(':memory:');
        $handler = new DatabaseSessionHandler(['db' => $db, 'ttl' => 3600]);

        $this->assertInstanceOf(DatabaseSessionHandler::class, $handler);
        $db->close();
    }

    // -- TTL config -------------------------------------------------------------

    public function testTtlConfigOverride(): void
    {
        $adapter = new SQLite3Adapter(':memory:');
        $handler = new DatabaseSessionHandler(['db' => $adapter, 'ttl' => 7200]);

        $ref = new \ReflectionClass($handler);
        $ttlProp = $ref->getProperty('ttl');


        $this->assertSame(7200, $ttlProp->getValue($handler));
        $adapter->close();
    }

    public function testTtlDefaultValue(): void
    {
        $adapter = new SQLite3Adapter(':memory:');
        $handler = new DatabaseSessionHandler(['db' => $adapter]);

        $ref = new \ReflectionClass($handler);
        $ttlProp = $ref->getProperty('ttl');


        $this->assertSame(3600, $ttlProp->getValue($handler));
        $adapter->close();
    }

    public function testTtlFromEnvVar(): void
    {
        putenv('TINA4_SESSION_TTL=900');

        $adapter = new SQLite3Adapter(':memory:');
        $handler = new DatabaseSessionHandler(['db' => $adapter]);

        $ref = new \ReflectionClass($handler);
        $ttlProp = $ref->getProperty('ttl');


        $this->assertSame(900, $ttlProp->getValue($handler));

        putenv('TINA4_SESSION_TTL');
        $adapter->close();
    }

    // -- Close is no-op ---------------------------------------------------------

    public function testCloseDoesNotThrow(): void
    {
        $adapter = new SQLite3Adapter(':memory:');
        $handler = new DatabaseSessionHandler(['db' => $adapter]);

        $handler->close();

        // Should not throw
        $this->assertTrue(true);
        $adapter->close();
    }

    // -- Missing database surfaces on FIRST USE, not at construction -------------

    /**
     * A missing database must be reported by the first OPERATION, never by the
     * constructor (session contract #4, ADR-0021). The constructor sits outside
     * the log-loud-and-degrade policy, so a throw there cannot be logged,
     * degraded, or re-raised by TINA4_SESSION_STRICT; thrown from read() it is
     * caught by the Session boundary and handled like any other backend outage.
     */
    public function testMissingDatabaseThrowsOnFirstUseNotAtConstruction(): void
    {
        // Ensure no TINA4_DATABASE_URL env var is set
        putenv('TINA4_DATABASE_URL');
        unset($_ENV['TINA4_DATABASE_URL'], $_SERVER['TINA4_DATABASE_URL']);
        \Tina4\DotEnv::resetEnv();

        // Constructing resolves nothing and must not throw.
        $handler = new DatabaseSessionHandler();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No database connection available');

        $handler->read('any-session-id');
    }

    // -- Table creation ---------------------------------------------------------

    public function testEnsureTableCreatesSessionTable(): void
    {
        $adapter = new SQLite3Adapter(':memory:');

        // The handler calls commit() internally, so we start a transaction first
        $adapter->startTransaction();

        $handler = new DatabaseSessionHandler(['db' => $adapter]);

        // Trigger table creation by calling read (which calls ensureTable internally)
        $handler->read('nonexistent-session-id');

        $this->assertTrue($adapter->tableExists('tina4_session'));
        $adapter->close();
    }

    // -- Write method signature -------------------------------------------------

    public function testWriteMethodSignature(): void
    {
        $ref = new \ReflectionClass(DatabaseSessionHandler::class);
        $method = $ref->getMethod('write');

        $params = $method->getParameters();
        $this->assertSame('sessionId', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());

        $this->assertSame('data', $params[1]->getName());
        $this->assertSame('array', $params[1]->getType()->getName());
    }

    // -- Read method return type ------------------------------------------------

    public function testReadReturnType(): void
    {
        $ref = new \ReflectionClass(DatabaseSessionHandler::class);
        $method = $ref->getMethod('read');
        $returnType = $method->getReturnType();

        $this->assertTrue($returnType->allowsNull());
    }

    // -- Delete method return type ----------------------------------------------

    public function testDeleteReturnType(): void
    {
        $ref = new \ReflectionClass(DatabaseSessionHandler::class);
        $method = $ref->getMethod('delete');
        $returnType = $method->getReturnType();

        $this->assertSame('void', $returnType->getName());
    }

    // -- Read returns null for nonexistent session ------------------------------

    public function testReadReturnsNullForNonexistentSession(): void
    {
        $adapter = new SQLite3Adapter(':memory:');

        // The handler calls commit() internally, so we start a transaction first
        $adapter->startTransaction();

        $handler = new DatabaseSessionHandler(['db' => $adapter]);

        $result = $handler->read('does-not-exist');

        $this->assertNull($result);
        $adapter->close();
    }
}
