<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

use PHPUnit\Framework\TestCase;

/**
 * Tina4 xUnit-Style Test Base Class — class-based suites with assertions
 * and an HTTP test client mixed in.
 *
 * Chapter 18 of the documentation has long described an integration-style
 * test pattern that mixed HTTP calls onto the test class itself::
 *
 *     class UserApiTest extends \Tina4\Test
 *     {
 *         public function testHealth(): void
 *         {
 *             $resp = $this->get("/health");
 *             $this->assertEqual($resp->status, 200);
 *         }
 *     }
 *
 * Until 3.13.0 this class did not exist — the chapter's examples crashed
 * with "Class 'Tina4\\Test' not found". This is the PHP parity of the
 * Python `tina4_python.test.Test` mixin, shipped to make the documented
 * examples actually run.
 *
 * Inheriting from `\PHPUnit\Framework\TestCase` means PHPUnit discovers
 * every subclass automatically — no special configuration required.
 *
 * The HTTP helpers (`$this->get`, `post`, `put`, `patch`, `delete`)
 * delegate to a lazily-created `TestClient` instance. The assertion
 * helpers (`assertEqual`, `assertNotEqual`, `assertTrue`, `assertFalse`,
 * `assertNull`, `assertNotNull`, `assertRaises`) are positional and read
 * `(actual, expected, message)` for parity with the Python xUnit surface.
 */
abstract class Test extends TestCase
{
    private ?TestClient $testClient = null;

    /**
     * snake_case lifecycle hook — runs before each test method.
     * Override on the subclass; defaults to a no-op.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTest();
    }

    /**
     * snake_case lifecycle hook — runs after each test method.
     * Override on the subclass; defaults to a no-op.
     */
    protected function tearDown(): void
    {
        $this->tearDownTest();
        parent::tearDown();
    }

    /**
     * Tina4 idiom — override in subclass for per-test setup.
     */
    protected function setUpTest(): void
    {
    }

    /**
     * Tina4 idiom — override in subclass for per-test teardown.
     */
    protected function tearDownTest(): void
    {
    }

    // ── HTTP test client (lazy) ─────────────────────────────────────

    private function client(): TestClient
    {
        if ($this->testClient === null) {
            $this->testClient = new TestClient();
        }
        return $this->testClient;
    }

    public function get(string $path, ?array $headers = null): TestResponse
    {
        return $this->client()->get($path, $headers);
    }

    public function post(string $path, ?array $json = null, ?string $body = null, ?array $headers = null): TestResponse
    {
        return $this->client()->post($path, $json, $body, $headers);
    }

    public function put(string $path, ?array $json = null, ?string $body = null, ?array $headers = null): TestResponse
    {
        return $this->client()->put($path, $json, $body, $headers);
    }

    public function patch(string $path, ?array $json = null, ?string $body = null, ?array $headers = null): TestResponse
    {
        return $this->client()->patch($path, $json, $body, $headers);
    }

    public function delete(string $path, ?array $headers = null): TestResponse
    {
        return $this->client()->delete($path, $headers);
    }

    // ── Positional assertions (parity with Python xUnit surface) ─────

    /**
     * Assert two values are equal.
     */
    public function assertEqual(mixed $actual, mixed $expected, string $message = ''): void
    {
        $this->assertEquals($expected, $actual, $message ?: "Expected " . var_export($expected, true) . ", got " . var_export($actual, true));
    }

    /**
     * Assert two values are NOT equal.
     */
    public function assertNotEqual(mixed $actual, mixed $expected, string $message = ''): void
    {
        $this->assertNotEquals($expected, $actual, $message ?: "Expected values to differ, got " . var_export($actual, true));
    }

    /**
     * Assert that $value is truthy.
     */
    public function assertTrueValue(mixed $value, string $message = ''): void
    {
        $this->assertTrue((bool) $value, $message ?: "Expected truthy, got " . var_export($value, true));
    }

    /**
     * Assert that $value is falsy.
     */
    public function assertFalseValue(mixed $value, string $message = ''): void
    {
        $this->assertFalse((bool) $value, $message ?: "Expected falsy, got " . var_export($value, true));
    }

    /**
     * Assert that $value is null.
     */
    public function assertNullValue(mixed $value, string $message = ''): void
    {
        $this->assertNull($value, $message);
    }

    /**
     * Assert that $value is not null.
     */
    public function assertNotNullValue(mixed $value, string $message = ''): void
    {
        $this->assertNotNull($value, $message);
    }

    /**
     * Assert that calling $callable raises an exception of $exceptionClass.
     *
     *     $this->assertRaises(fn() => parse("bad"), \ValueError::class);
     *     $this->assertRaises(fn() => parse("bad"), \ValueError::class, "must raise");
     */
    public function assertRaises(callable $callable, string $exceptionClass, string $message = ''): void
    {
        try {
            $callable();
        } catch (\Throwable $e) {
            if ($e instanceof $exceptionClass) {
                return;
            }
            $this->fail(($message ?: "Expected {$exceptionClass}, got " . get_class($e)) . ": " . $e->getMessage());
        }
        $this->fail($message ?: "Expected {$exceptionClass} to be raised, but nothing was");
    }
}
