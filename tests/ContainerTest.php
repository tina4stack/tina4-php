<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Container;

class ContainerTest extends TestCase
{
    // -- interface contract (cross-framework rename guard) -------------------

    /**
     * ONE consolidated contract test: the public DI surface must stay stable.
     *
     * Cheap guard against a silent rename drifting Container out of parity with
     * the Python / Ruby / Node containers. The BEHAVIOUR of each method is
     * asserted by the dedicated tests below; this only locks the method *set*.
     */
    public function testPublicMethodSet(): void
    {
        $container = new Container();
        foreach (['register', 'singleton', 'get', 'has', 'reset'] as $method) {
            $this->assertTrue(
                is_callable([$container, $method]),
                "Container::{$method} missing or not callable"
            );
        }
    }

    // -- register + get (transient) ------------------------------------------

    public function testRegisterAndGetReturnsNewInstanceEachTime(): void
    {
        $container = new Container();
        $container->register('counter', function () {
            return new \stdClass();
        });

        $a = $container->get('counter');
        $b = $container->get('counter');

        $this->assertInstanceOf(\stdClass::class, $a);
        $this->assertInstanceOf(\stdClass::class, $b);
        $this->assertNotSame($a, $b);
    }

    // -- singleton + get -----------------------------------------------------

    public function testSingletonReturnsSameInstanceEachTime(): void
    {
        $container = new Container();
        $container->singleton('db', function () {
            return new \stdClass();
        });

        $a = $container->get('db');
        $b = $container->get('db');

        $this->assertSame($a, $b);
    }

    public function testSingletonFactoryCalledOnce(): void
    {
        $container = new Container();
        $callCount = 0;

        $container->singleton('service', function () use (&$callCount) {
            $callCount++;
            return 'result';
        });

        $container->get('service');
        $container->get('service');
        $container->get('service');

        $this->assertEquals(1, $callCount);
    }

    // -- has -----------------------------------------------------------------

    public function testHasReturnsTrueForRegistered(): void
    {
        $container = new Container();
        $container->register('foo', fn() => 'bar');
        $this->assertTrue($container->has('foo'));
    }

    public function testHasReturnsFalseForUnregistered(): void
    {
        $container = new Container();
        $this->assertFalse($container->has('nonexistent'));
    }

    // -- get throws on unregistered ------------------------------------------

    public function testGetThrowsOnUnregistered(): void
    {
        $container = new Container();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Service not registered: unknown');
        $container->get('unknown');
    }

    // -- reset ---------------------------------------------------------------

    public function testResetClearsEverything(): void
    {
        $container = new Container();
        $container->register('a', fn() => 1);
        $container->singleton('b', fn() => 2);

        $this->assertTrue($container->has('a'));
        $this->assertTrue($container->has('b'));

        $container->reset();

        $this->assertFalse($container->has('a'));
        $this->assertFalse($container->has('b'));
    }

    // -- overwriting a registration ------------------------------------------

    public function testOverwriteRegistration(): void
    {
        $container = new Container();
        $container->register('service', fn() => 'original');
        $this->assertEquals('original', $container->get('service'));

        $container->register('service', fn() => 'replaced');
        $this->assertEquals('replaced', $container->get('service'));
    }

    public function testOverwriteTransientWithSingleton(): void
    {
        $container = new Container();
        $container->register('service', fn() => new \stdClass());

        $a = $container->get('service');
        $b = $container->get('service');
        $this->assertNotSame($a, $b);

        $container->singleton('service', fn() => new \stdClass());

        $c = $container->get('service');
        $d = $container->get('service');
        $this->assertSame($c, $d);
    }

    // -- factory receives correct value --------------------------------------

    public function testFactoryReturnsCorrectValue(): void
    {
        $container = new Container();
        $container->register('greeting', fn() => 'Hello, World!');
        $this->assertEquals('Hello, World!', $container->get('greeting'));
    }

    // -- singleton is lazy (not called until first get) ----------------------

    public function testSingletonIsLazy(): void
    {
        $container = new Container();
        $callCount = 0;
        $container->singleton('lazy', function () use (&$callCount) {
            $callCount++;
            return 'created';
        });

        $this->assertEquals(0, $callCount);
        $container->get('lazy');
        $this->assertEquals(1, $callCount);
        $container->get('lazy');
        $this->assertEquals(1, $callCount); // not called again
    }

    // -- has returns true for singleton ----------------------------------------

    public function testHasReturnsTrueForSingleton(): void
    {
        $container = new Container();
        $container->singleton('s', fn() => 'x');
        $this->assertTrue($container->has('s'));
    }

    // -- get raises after reset -----------------------------------------------

    public function testGetThrowsAfterReset(): void
    {
        $container = new Container();
        $container->register('temp', fn() => 1);
        $container->reset();

        $this->expectException(\RuntimeException::class);
        $container->get('temp');
    }

    // -- reset allows re-registration -----------------------------------------

    public function testResetAllowsReRegistration(): void
    {
        $container = new Container();
        $container->register('x', fn() => 'old');
        $container->reset();
        $container->register('x', fn() => 'new');
        $this->assertEquals('new', $container->get('x'));
    }

    // -- overwrite singleton with transient ------------------------------------

    public function testOverwriteSingletonWithTransient(): void
    {
        $container = new Container();
        $container->singleton('svc', fn() => new \stdClass());

        $singletonVal = $container->get('svc');
        $this->assertSame($singletonVal, $container->get('svc'));

        $container->register('svc', fn() => new \stdClass());

        $a = $container->get('svc');
        $b = $container->get('svc');
        $this->assertNotSame($a, $b); // now transient
    }

    // -- register with class factory ------------------------------------------

    public function testRegisterWithClassFactory(): void
    {
        $container = new Container();

        // A factory that constructs a real object with init state — the test
        // proves the factory actually RAN (not just "an instance came back").
        $container->register('svc', fn() => new class {
            public string $value = 'ready';
        });

        $first = $container->get('svc');
        $second = $container->get('svc');

        // The factory really ran: the constructed object carries its init state.
        $this->assertSame('ready', $first->value);
        // Transient: a class factory yields a brand-new instance each get().
        $this->assertNotSame($first, $second);
    }

    // -- factory called with zero args ----------------------------------------

    public function testFactoryCalledWithZeroArgs(): void
    {
        $container = new Container();
        $receivedArgs = [];
        $container->register('check', function () use (&$receivedArgs) {
            $receivedArgs[] = func_get_args();
            return 'ok';
        });
        $container->get('check');
        $this->assertCount(1, $receivedArgs);
        $this->assertSame([], $receivedArgs[0]);
    }

    // -- non-callable factory is rejected (type contract) ---------------------

    public function testRegisterRejectsNonCallable(): void
    {
        $container = new Container();
        // register() is typed `callable $factory`; a non-callable is a hard
        // contract violation — the engine raises a TypeError, mirroring
        // Python's explicit "must be callable" guard. Assert the real refusal.
        $this->expectException(\TypeError::class);
        // @phpstan-ignore-next-line — intentionally passing the wrong type.
        $container->register('bad', 'not a callable');
    }

    public function testSingletonRejectsNonCallable(): void
    {
        $container = new Container();
        $this->expectException(\TypeError::class);
        // @phpstan-ignore-next-line — intentionally passing the wrong type.
        $container->singleton('bad', 42);
    }

    // -- multiple independent containers --------------------------------------

    public function testIndependentRegistrations(): void
    {
        $c1 = new Container();
        $c2 = new Container();

        $c1->register('name', fn() => 'alice');
        $c2->register('name', fn() => 'bob');

        $this->assertEquals('alice', $c1->get('name'));
        $this->assertEquals('bob', $c2->get('name'));
    }

    public function testResetOneDoesNotAffectOther(): void
    {
        $c1 = new Container();
        $c2 = new Container();

        $c1->register('x', fn() => 1);
        $c2->register('x', fn() => 2);

        $c1->reset();
        $this->assertFalse($c1->has('x'));
        $this->assertTrue($c2->has('x'));
        $this->assertEquals(2, $c2->get('x'));
    }

    public function testSingletonInstancesAreIndependent(): void
    {
        $c1 = new Container();
        $c2 = new Container();

        $c1->singleton('obj', fn() => new \stdClass());
        $c2->singleton('obj', fn() => new \stdClass());

        $this->assertNotSame($c1->get('obj'), $c2->get('obj'));
    }

    // -- variable in multiple places ------------------------------------------

    public function testVariableUsedInMultiplePlaces(): void
    {
        $container = new Container();
        $container->register('a', fn() => 'shared');
        $container->register('b', fn() => 'shared');
        $this->assertEquals($container->get('a'), $container->get('b'));
    }

    // -- register many keys ---------------------------------------------------

    public function testRegisterManyKeys(): void
    {
        $container = new Container();
        for ($i = 0; $i < 20; $i++) {
            $container->register("svc-{$i}", fn() => $i);
        }
        // Every key must be present AND resolve to its own bound value — no
        // cross-talk between the 20 closures sharing one container.
        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue($container->has("svc-{$i}"));
            $this->assertSame($i, $container->get("svc-{$i}"));
        }
    }
}
