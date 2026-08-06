<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * TINA4_PORT MUST BEAT BARE PORT, ON THE PATH THAT BINDS THE SOCKET.
 *
 * The CLI documents CLI flag > TINA4_PORT > PORT > default and labels bare PORT
 * "Legacy bare server port (prefer TINA4_PORT)". PHP was the worst of the four:
 * run() read NO port environment variable at all. $port was a plain parameter
 * defaulting to 7145, so TINA4_PORT was ignored on the one path that binds.
 * Setting it did nothing, and said nothing.
 *
 * The signature moved from `int $port = 7145` to `?int $port = null` for the
 * reason ADR-0041 gives: a default in the ARGUMENT slot makes "not passed"
 * indistinguishable from "passed the default", so the environment never gets a
 * look in. That is the same defect, in the same shape, as the logger's.
 *
 * Bare PORT is DEPRECATED, not removed: still honoured so nothing breaks, and
 * warned so the migration happens. Removal is 3.14.
 *
 * Identical case names in all four frameworks:
 *   tina4-python/tests/test_bind_port_precedence.py
 *   tina4-ruby/spec/bind_port_precedence_spec.rb
 *   tina4-nodejs/test/bindPortPrecedence.test.ts
 */

use PHPUnit\Framework\TestCase;

class BindPortPrecedenceTest extends TestCase
{
    private function resolve(int $default = 7145): int
    {
        $m = new ReflectionMethod(\Tina4\App::class, 'resolveBindPort');
        return $m->invoke(null, $default);
    }

    protected function setUp(): void
    {
        foreach (['TINA4_PORT', 'PORT'] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $p = new ReflectionProperty(\Tina4\App::class, 'portDeprecationWarned');
        $p->setValue(null, false);
    }

    protected function tearDown(): void
    {
        foreach (['TINA4_PORT', 'PORT'] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    public function testTina4PortWinsOverBarePort(): void
    {
        putenv('TINA4_PORT=45001');
        putenv('PORT=9999');
        $this->assertSame(45001, $this->resolve(), 'bare PORT outranked TINA4_PORT');
    }

    public function testBarePortIsStillHonoured(): void
    {
        putenv('PORT=9999');
        $this->assertSame(9999, $this->resolve(), 'deprecated is not removed');
    }

    public function testTheDefaultAppliesWhenNothingIsSet(): void
    {
        $this->assertSame(7145, $this->resolve());
    }

    public function testANonNumericValueFallsThrough(): void
    {
        putenv('TINA4_PORT=not-a-port');
        putenv('PORT=9999');
        $this->assertSame(9999, $this->resolve(), 'a typo must not bind port 0');
    }

    /**
     * The signature change, asserted directly.
     *
     * `int $port = 7145` made "not passed" and "passed 7145" the same thing, so
     * no environment variable could ever be consulted. If this reverts, the
     * whole fix reverts with it and every other test here still passes.
     */
    public function testThePortParameterIsNullableSoTheEnvironmentCanBeConsulted(): void
    {
        $param = (new ReflectionMethod(\Tina4\App::class, 'run'))->getParameters()[1];
        $this->assertSame('port', $param->getName());
        $this->assertTrue(
            $param->getType()->allowsNull(),
            'run($host, $port) must take ?int so "not passed" differs from "passed the default"'
        );
        $this->assertNull(
            $param->getDefaultValue(),
            'a non-null default in the argument slot stops the environment being read at all'
        );
    }
}
