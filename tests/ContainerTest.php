<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Container;

class ContainerTest extends TestCase
{
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
        $container->register('svc', fn() => new \stdClass());
        $result = $container->get('svc');
        $this->assertInstanceOf(\stdClass::class, $result);
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
        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue($container->has("svc-{$i}"));
        }
    }
}
