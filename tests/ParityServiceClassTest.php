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
    public function testServiceBaseClassExists(): void
    {
        $this->assertTrue(class_exists(Service::class));
    }

    public function testRunMethodIsAbstract(): void
    {
        $reflection = new \ReflectionClass(Service::class);
        $this->assertTrue($reflection->isAbstract());
        $runMethod = $reflection->getMethod('run');
        $this->assertTrue($runMethod->isAbstract());
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

    public function testAsCallableReturnsRunMethodBinding(): void
    {
        $svc = new _ParityServiceFixture();
        $callable = $svc->asCallable();
        $this->assertIsCallable($callable);
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
