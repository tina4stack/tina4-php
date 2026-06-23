<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Service;
use Tina4\ServiceRunner;

/**
 * Concrete Service subclass used as a fixture.
 */
class _ParityServiceFixture extends Service
{
    public int $iterations = 0;

    public function run(): void
    {
        while (!$this->shouldStop()) {
            $this->iterations++;
            if ($this->iterations >= 3) {
                $this->stop();
            }
        }
    }
}

/**
 * Verify the Tina4\Service base class + ServiceRunner::registerService()
 * helper shipped in 3.13.1.
 *
 * Chapter 27 of the PHP docs has long taught `class FooWorker extends Service`
 * with `run()` / `stop()`. Until 3.13.1 this base class didn't exist.
 */
final class ParityServiceClassTest extends TestCase
{
    /**
     * Consolidated parity-rename guard: the class-based Service surface the
     * docs (chapter 27) teach must exist by these exact names, and `run()`
     * must stay abstract so subclasses are forced to implement the work loop.
     * One reflective contract test replaces the per-method existence smoke
     * tests; real behaviour is exercised by the tests below.
     */
    public function testServiceContractIsAbstractWithExpectedMethods(): void
    {
        $reflection = new \ReflectionClass(Service::class);
        $this->assertTrue($reflection->isAbstract(), 'Service must be abstract');
        $this->assertTrue(
            $reflection->getMethod('run')->isAbstract(),
            'run() must stay abstract so subclasses implement the work loop'
        );
        foreach (['run', 'stop', 'shouldStop', 'asCallable'] as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "Service::{$method}() is part of the documented base-class contract"
            );
        }
    }

    public function testShouldStopReturnsTrueAfterStop(): void
    {
        $svc = new _ParityServiceFixture();
        $this->assertFalse($svc->shouldStop());
        $svc->stop();
        $this->assertTrue($svc->shouldStop());
    }

    public function testRunLoopTerminatesViaShouldStop(): void
    {
        $svc = new _ParityServiceFixture();
        $svc->run();
        $this->assertSame(3, $svc->iterations);
    }

    public function testAsCallableActuallyInvokesRun(): void
    {
        $svc = new _ParityServiceFixture();
        $callable = $svc->asCallable();
        $this->assertIsCallable($callable);

        // Invoking the returned callable must run the real work loop — the
        // self-terminating fixture stops itself after 3 iterations.
        $this->assertSame(0, $svc->iterations);
        $callable();
        $this->assertSame(3, $svc->iterations, 'asCallable() must invoke run()');
        $this->assertTrue($svc->shouldStop(), 'run() reached its stop() condition');
    }

    public function testRegisterServiceUsesServiceInstance(): void
    {
        $svc = new _ParityServiceFixture();
        ServiceRunner::registerService('parity-test-service', $svc);

        $list = ServiceRunner::list();
        $names = array_column($list, 'name');
        $this->assertContains('parity-test-service', $names);
    }
}
