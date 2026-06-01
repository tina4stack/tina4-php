<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Test;

/**
 * A concrete subclass of \Tina4\Test used as a fixture for exercising
 * the documented assertion + HTTP-client surface. Named (not anonymous)
 * so PHPUnit's TestCase constructor — which requires a method name —
 * accepts construction.
 */
class _ParityTestFixture extends Test
{
    public function testNoop(): void
    {
        $this->assertTrue(true);
    }
}

/**
 * Verify the new \Tina4\Test base class shipped in 3.13.0.
 *
 * The chapter-18 disaster was that this class didn't exist — every
 * `class FooTest extends Test` failed at autoload time. These tests pin
 * the contract so it can never regress.
 */
final class ParityTestClassTest extends TestCase
{
    private _ParityTestFixture $suite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->suite = new _ParityTestFixture('testNoop');
    }

    public function testTestClassExists(): void
    {
        $this->assertTrue(class_exists(Test::class), "Tina4\\Test class must exist");
    }

    public function testTestClassExtendsPHPUnitTestCase(): void
    {
        $this->assertInstanceOf(TestCase::class, $this->suite);
    }

    public function testHttpMethodsAreBound(): void
    {
        $this->assertTrue(method_exists($this->suite, 'get'));
        $this->assertTrue(method_exists($this->suite, 'post'));
        $this->assertTrue(method_exists($this->suite, 'put'));
        $this->assertTrue(method_exists($this->suite, 'patch'));
        $this->assertTrue(method_exists($this->suite, 'delete'));
    }

    public function testAssertEqualPasses(): void
    {
        $this->suite->assertEqual(2 + 2, 4, "Basic addition should work");
        $this->addToAssertionCount(1);
    }

    public function testAssertEqualFails(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->suite->assertEqual(2 + 2, 5);
    }

    public function testAssertNotEqual(): void
    {
        $this->suite->assertNotEqual("hello", "world");
        $this->addToAssertionCount(1);
    }

    public function testAssertTrueValue(): void
    {
        $this->suite->assertTrueValue(1);
        $this->suite->assertTrueValue("non-empty");
        $this->addToAssertionCount(2);
    }

    public function testAssertFalseValue(): void
    {
        $this->suite->assertFalseValue(0);
        $this->suite->assertFalseValue("");
        $this->addToAssertionCount(2);
    }

    public function testAssertNullValue(): void
    {
        $this->suite->assertNullValue(null);
        $this->addToAssertionCount(1);
    }

    public function testAssertNotNullValue(): void
    {
        $this->suite->assertNotNullValue(0);
        $this->suite->assertNotNullValue("");
        $this->suite->assertNotNullValue(false);
        $this->addToAssertionCount(3);
    }

    public function testAssertRaisesPasses(): void
    {
        $this->suite->assertRaises(fn() => throw new \ValueError("boom"), \ValueError::class);
        $this->addToAssertionCount(1);
    }

    public function testAssertRaisesFailsOnWrongException(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->suite->assertRaises(fn() => throw new \TypeError("boom"), \ValueError::class);
    }

    public function testAssertRaisesFailsWhenNoException(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->suite->assertRaises(fn() => 42, \ValueError::class);
    }
}
