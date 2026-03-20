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
}
