<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Session\MemcachedSessionHandler;

/**
 * Memcached session backend — against a REAL memcached server.
 *
 * Memcached was one of the seven CACHE backends in all four frameworks but was
 * not a session backend in any of them, even though it is the classic PHP
 * session store. This is the parity feature that closes that gap.
 *
 * NO MOCKS. Every assertion drives a live memcached over TCP. If the server is
 * not reachable the class skips, unless TINA4_REQUIRE_SERVICES is set — then a
 * missing service is a FAILURE, because a suite that silently skips its only
 * real verification is not verification.
 */
class SessionMemcachedTest extends TestCase
{
    private const SESSION = [
        '_created' => 1,
        '_accessed' => 2,
        'user_id' => 7,
        'nested' => ['a' => [1, 2, 3], 'flag' => true],
    ];

    private static string $host;
    private static int $port;

    private MemcachedSessionHandler $handler;

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('TINA4_TEST_MEMCACHED_HOST') ?: '127.0.0.1';
        self::$port = (int)(getenv('TINA4_TEST_MEMCACHED_PORT') ?: 11211);
    }

    protected function setUp(): void
    {
        $errNo = 0;
        $errStr = '';
        $probe = @fsockopen(self::$host, self::$port, $errNo, $errStr, 2);
        if ($probe === false) {
            if (getenv('TINA4_REQUIRE_SERVICES')) {
                $this->fail(sprintf(
                    'TINA4_REQUIRE_SERVICES is set but memcached is not reachable at %s:%d',
                    self::$host,
                    self::$port
                ));
            }
            $this->markTestSkipped(sprintf('memcached not reachable at %s:%d', self::$host, self::$port));
        }
        fclose($probe);

        $this->handler = new MemcachedSessionHandler([
            'host' => self::$host,
            'port' => self::$port,
            'prefix' => 'tina4:test:session:',
        ]);
    }

    public function testWriteThenReadReturnsTheSameSession(): void
    {
        $this->handler->write('sid-basic', self::SESSION, 60);
        $this->assertSame(self::SESSION, $this->handler->read('sid-basic'));
        $this->handler->destroy('sid-basic');
    }

    /**
     * A miss and a failure must be different outcomes. Collapsing them is how a
     * dead cache silently logs every user out instead of surfacing an outage.
     */
    public function testAMissReturnsAnEmptyArrayNotAnError(): void
    {
        $this->assertSame([], $this->handler->read('sid-definitely-not-present'));
    }

    public function testDestroyRemovesTheSession(): void
    {
        $this->handler->write('sid-destroy', self::SESSION, 60);
        $this->assertNotSame([], $this->handler->read('sid-destroy'));
        $this->handler->destroy('sid-destroy');
        $this->assertSame([], $this->handler->read('sid-destroy'));
    }

    /** Idempotent destroy — logging out twice must not raise. */
    public function testDestroyingAnAbsentSessionIsNotAnError(): void
    {
        $this->handler->destroy('sid-never-existed');
        $this->assertTrue(true);
    }

    public function testAWriteOverwritesThePreviousValue(): void
    {
        $this->handler->write('sid-over', ['v' => 1], 60);
        $this->handler->write('sid-over', ['v' => 2], 60);
        $this->assertSame(['v' => 2], $this->handler->read('sid-over'));
        $this->handler->destroy('sid-over');
    }

    /** Expiry is memcached's own, which is why gc() is a no-op here. */
    public function testTheTtlActuallyExpiresTheSession(): void
    {
        $this->handler->write('sid-ttl', self::SESSION, 1);
        $this->assertNotSame([], $this->handler->read('sid-ttl'));
        sleep(3);
        $this->assertSame([], $this->handler->read('sid-ttl'));
    }

    /**
     * Memcached rejects keys over 250 bytes. Truncating would let two different
     * sessions collide on one key — one user reading another's session.
     */
    public function testALongSessionIdIsHashedRatherThanTruncated(): void
    {
        $a = str_repeat('x', 400);
        $b = str_repeat('x', 399) . 'y';
        $this->handler->write($a, ['who' => 'a'], 60);
        $this->handler->write($b, ['who' => 'b'], 60);
        $this->assertSame(['who' => 'a'], $this->handler->read($a));
        $this->assertSame(['who' => 'b'], $this->handler->read($b));
        $this->handler->destroy($a);
        $this->handler->destroy($b);
    }

    /** A space is illegal in a memcached key and would be a protocol error. */
    public function testASessionIdContainingASpaceIsStillUsable(): void
    {
        $sid = 'has a space';
        $this->handler->write($sid, ['ok' => true], 60);
        $this->assertSame(['ok' => true], $this->handler->read($sid));
        $this->handler->destroy($sid);
    }

    public function testExistsReflectsWhetherTheSessionIsPresent(): void
    {
        $this->handler->write('sid-exists', self::SESSION, 60);
        $this->assertTrue($this->handler->exists('sid-exists'));
        $this->handler->destroy('sid-exists');
        $this->assertFalse($this->handler->exists('sid-exists'));
    }

    /**
     * The whole point of the miss/failure split: an unreachable backend must
     * THROW so the Session layer logs loud and degrades. Returning [] would be
     * indistinguishable from "no session yet", silently logging every user out
     * during an outage.
     */
    public function testNegativeAnUnreachableServerThrowsRatherThanReadingEmpty(): void
    {
        $dead = new MemcachedSessionHandler(['host' => '127.0.0.1', 'port' => 59999, 'timeout' => 1]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Memcached session backend/');
        $dead->read('sid-any');
    }

    public function testNegativeAnUnreachableServerThrowsOnWriteToo(): void
    {
        $dead = new MemcachedSessionHandler(['host' => '127.0.0.1', 'port' => 59999, 'timeout' => 1]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Memcached session backend/');
        $dead->write('sid-any', ['a' => 1], 60);
    }

    public function testGcIsANoOpBecauseMemcachedExpiresItsOwnKeys(): void
    {
        $this->handler->gc(3600);
        $this->assertTrue(true);
    }

    /**
     * TINA4_SESSION_BACKEND=memcached must resolve to this handler. A handler
     * nothing can select is not a backend.
     */
    public function testTheSessionBackendEnvVarSelectsMemcached(): void
    {
        $rc = new ReflectionClass(\Tina4\Session::class);
        $method = $rc->getMethod('handlerLabel');
        $method->setAccessible(true);
        $backend = $rc->getProperty('backend');
        $backend->setAccessible(true);

        foreach (['memcached', 'memcache'] as $spelling) {
            $session = $rc->newInstanceWithoutConstructor();
            $backend->setValue($session, $spelling);
            $this->assertSame(
                'MemcachedSessionHandler',
                $method->invoke($session),
                "backend '{$spelling}' must resolve to the memcached handler"
            );
        }
    }
}
