<?php

/**
 * Tests for Tina4\Events — observer pattern for decoupled communication.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Events;

class EventsTest extends TestCase
{
    protected function setUp(): void
    {
        Events::clear();
    }

    // ── on / emit ──────────────────────────────────────

    public function testOnRegistersListener(): void
    {
        $called = false;
        Events::on('test.event', function () use (&$called) {
            $called = true;
        });

        Events::emit('test.event');
        $this->assertTrue($called);
    }

    public function testEmitPassesArguments(): void
    {
        $received = null;
        Events::on('user.created', function ($user) use (&$received) {
            $received = $user;
        });

        Events::emit('user.created', ['name' => 'Alice']);
        $this->assertEquals(['name' => 'Alice'], $received);
    }

    public function testEmitMultipleArguments(): void
    {
        $a = null;
        $b = null;
        Events::on('multi', function ($x, $y) use (&$a, &$b) {
            $a = $x;
            $b = $y;
        });

        Events::emit('multi', 'hello', 42);
        $this->assertEquals('hello', $a);
        $this->assertEquals(42, $b);
    }

    public function testEmitReturnsListenerResults(): void
    {
        Events::on('calc', fn($x) => $x * 2);
        Events::on('calc', fn($x) => $x * 3);

        $results = Events::emit('calc', 5);
        $this->assertEquals([10, 15], $results);
    }

    public function testEmitUnknownEventReturnsEmpty(): void
    {
        $results = Events::emit('nonexistent');
        $this->assertEmpty($results);
    }

    public function testMultipleListenersOnSameEvent(): void
    {
        $order = [];
        Events::on('build', function () use (&$order) { $order[] = 'A'; });
        Events::on('build', function () use (&$order) { $order[] = 'B'; });
        Events::on('build', function () use (&$order) { $order[] = 'C'; });

        Events::emit('build');
        $this->assertEquals(['A', 'B', 'C'], $order);
    }

    // ── priority ───────────────────────────────────────

    public function testPriorityHigherRunsFirst(): void
    {
        $order = [];
        Events::on('pipeline', function () use (&$order) { $order[] = 'low'; }, 1);
        Events::on('pipeline', function () use (&$order) { $order[] = 'high'; }, 10);
        Events::on('pipeline', function () use (&$order) { $order[] = 'mid'; }, 5);

        Events::emit('pipeline');
        $this->assertEquals(['high', 'mid', 'low'], $order);
    }

    public function testDefaultPriorityIsZero(): void
    {
        $order = [];
        Events::on('p', function () use (&$order) { $order[] = 'first'; });
        Events::on('p', function () use (&$order) { $order[] = 'second'; }, 1);

        Events::emit('p');
        $this->assertEquals(['second', 'first'], $order);
    }

    // ── once ───────────────────────────────────────────

    public function testOnceFiresOnlyOnce(): void
    {
        $count = 0;
        Events::once('startup', function () use (&$count) { $count++; });

        Events::emit('startup');
        Events::emit('startup');
        Events::emit('startup');

        $this->assertEquals(1, $count);
    }

    public function testOnceWithPriority(): void
    {
        $order = [];
        Events::on('mix', function () use (&$order) { $order[] = 'always'; }, 1);
        Events::once('mix', function () use (&$order) { $order[] = 'once'; }, 10);

        Events::emit('mix');
        $this->assertEquals(['once', 'always'], $order);

        $order = [];
        Events::emit('mix');
        $this->assertEquals(['always'], $order);
    }

    public function testOnceReturnsValue(): void
    {
        Events::once('init', fn() => 'ready');

        $results = Events::emit('init');
        $this->assertEquals(['ready'], $results);

        $results = Events::emit('init');
        $this->assertEmpty($results);
    }

    // ── off ────────────────────────────────────────────

    public function testOffRemovesSpecificListener(): void
    {
        $fn1 = function () { return 'A'; };
        $fn2 = function () { return 'B'; };

        Events::on('test', $fn1);
        Events::on('test', $fn2);

        Events::off('test', $fn1);

        $results = Events::emit('test');
        $this->assertEquals(['B'], $results);
    }

    public function testOffRemovesAllListenersForEvent(): void
    {
        Events::on('clean', fn() => 1);
        Events::on('clean', fn() => 2);

        Events::off('clean');

        $results = Events::emit('clean');
        $this->assertEmpty($results);
    }

    public function testOffNonexistentEventNoError(): void
    {
        Events::off('ghost');
        Events::off('ghost', fn() => null);
        $this->assertTrue(true); // no exception
    }

    // ── listeners ──────────────────────────────────────

    public function testListenersReturnsCallbacksInOrder(): void
    {
        $fn1 = function () { return 'low'; };
        $fn2 = function () { return 'high'; };

        Events::on('check', $fn1, 1);
        Events::on('check', $fn2, 10);

        $list = Events::listeners('check');
        $this->assertCount(2, $list);
        $this->assertSame($fn2, $list[0]); // high priority first
        $this->assertSame($fn1, $list[1]);
    }

    public function testListenersEmptyForUnknownEvent(): void
    {
        $this->assertEmpty(Events::listeners('unknown'));
    }

    // ── events ─────────────────────────────────────────

    public function testEventsReturnsRegisteredNames(): void
    {
        Events::on('alpha', fn() => null);
        Events::on('beta', fn() => null);
        Events::on('gamma', fn() => null);

        $names = Events::events();
        $this->assertContains('alpha', $names);
        $this->assertContains('beta', $names);
        $this->assertContains('gamma', $names);
        $this->assertCount(3, $names);
    }

    public function testEventsEmptyWhenCleared(): void
    {
        Events::on('temp', fn() => null);
        Events::clear();

        $this->assertEmpty(Events::events());
    }

    // ── clear ──────────────────────────────────────────

    public function testClearRemovesEverything(): void
    {
        Events::on('a', fn() => 1);
        Events::on('b', fn() => 2);
        Events::once('c', fn() => 3);

        Events::clear();

        $this->assertEmpty(Events::events());
        $this->assertEmpty(Events::emit('a'));
        $this->assertEmpty(Events::emit('b'));
        $this->assertEmpty(Events::emit('c'));
    }

    // ── isolation ──────────────────────────────────────

    public function testDifferentEventsAreIsolated(): void
    {
        $a = 0;
        $b = 0;
        Events::on('count.a', function () use (&$a) { $a++; });
        Events::on('count.b', function () use (&$b) { $b++; });

        Events::emit('count.a');
        Events::emit('count.a');
        Events::emit('count.b');

        $this->assertEquals(2, $a);
        $this->assertEquals(1, $b);
    }

    public function testOffOneEventDoesNotAffectOthers(): void
    {
        Events::on('keep', fn() => 'kept');
        Events::on('remove', fn() => 'gone');

        Events::off('remove');

        $this->assertEquals(['kept'], Events::emit('keep'));
        $this->assertEmpty(Events::emit('remove'));
    }
}
