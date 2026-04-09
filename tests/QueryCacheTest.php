<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\QueryCache;

class QueryCacheTest extends TestCase
{
    public function testSetAndGet(): void
    {
        $c = new QueryCache();
        $c->set('k', 'v');
        $this->assertEquals('v', $c->get('k'));
    }

    public function testGetMissingReturnsNull(): void
    {
        $c = new QueryCache();
        $this->assertNull($c->get('missing'));
    }

    public function testHas(): void
    {
        $c = new QueryCache();
        $c->set('k', 1);
        $this->assertTrue($c->has('k'));
        $this->assertFalse($c->has('nope'));
    }

    public function testDelete(): void
    {
        $c = new QueryCache();
        $c->set('k', 1);
        $this->assertTrue($c->delete('k'));
        $this->assertFalse($c->delete('k'));
        $this->assertNull($c->get('k'));
    }

    public function testClearAndSize(): void
    {
        $c = new QueryCache();
        $c->set('a', 1);
        $c->set('b', 2);
        $this->assertEquals(2, $c->size());
        $c->clear();
        $this->assertEquals(0, $c->size());
    }

    public function testFifoEviction(): void
    {
        $c = new QueryCache(60, 3);
        $c->set('a', 1);
        $c->set('b', 2);
        $c->set('c', 3);
        $c->set('d', 4); // evicts 'a'
        $this->assertNull($c->get('a'));
        $this->assertEquals(4, $c->get('d'));
    }

    public function testExpiryAndSweep(): void
    {
        $c = new QueryCache();
        $c->set('short', 'v', 1);
        $c->set('long', 'v', 300);
        sleep(2);
        $removed = $c->sweep();
        $this->assertEquals(1, $removed);
        $this->assertEquals('v', $c->get('long'));
    }

    public function testRemember(): void
    {
        $c = new QueryCache();
        $calls = 0;
        $factory = function () use (&$calls) {
            $calls++;
            return 'computed';
        };
        $this->assertEquals('computed', $c->remember('key', 60, $factory));
        $this->assertEquals('computed', $c->remember('key', 60, $factory));
        $this->assertEquals(1, $calls);
    }

    public function testClearTag(): void
    {
        $c = new QueryCache();
        $c->set('a', 1, 0, ['users', 'list']);
        $c->set('b', 2, 0, ['users']);
        $c->set('c', 3, 0, ['products']);
        $removed = $c->clearTag('users');
        $this->assertEquals(2, $removed);
        $this->assertNull($c->get('a'));
        $this->assertNull($c->get('b'));
        $this->assertEquals(3, $c->get('c'));
    }

    public function testQueryKeyStable(): void
    {
        $k1 = QueryCache::queryKey('SELECT * FROM users', [1]);
        $k2 = QueryCache::queryKey('SELECT * FROM users', [1]);
        $k3 = QueryCache::queryKey('SELECT * FROM users', [2]);
        $this->assertEquals($k1, $k2);
        $this->assertNotEquals($k1, $k3);
        $this->assertStringStartsWith('query:', $k1);
    }
}
