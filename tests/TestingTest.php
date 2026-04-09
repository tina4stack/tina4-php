<?php

/**
 * Tests for the inline testing framework itself.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Testing;

class TestingTest extends TestCase
{
    public function testPassingAssertions(): void
    {
        Testing::reset();

        Testing::tests(
            [
                Testing::assertEqual([5, 3], 8),
                Testing::assertEqual([0, 0], 0),
                Testing::assertEqual([-1, 1], 0),
            ],
            function (int $a, int $b): int {
                return $a + $b;
            },
            'add'
        );

        Testing::tests(
            [
                Testing::assertEqual(['hello'], 'HELLO'),
                Testing::assertEqual(['World'], 'WORLD'),
            ],
            function (string $s): string {
                return strtoupper($s);
            },
            'upper'
        );

        Testing::tests(
            [
                Testing::assertRaises('InvalidArgumentException', [null]),
                Testing::assertEqual([5, 3], 8),
            ],
            function ($a, $b = null) {
                if ($b === null) {
                    throw new InvalidArgumentException("b is required");
                }
                return $a + $b;
            },
            'add_safe'
        );

        Testing::tests(
            [
                Testing::assertTrue([10]),
                Testing::assertTrue([1]),
                Testing::assertFalse([0]),
                Testing::assertFalse(['']),
            ],
            function ($value) {
                return (bool) $value;
            },
            'is_truthy'
        );

        $results = Testing::runAll(quiet: true);

        $this->assertSame(11, $results['passed'], "expected 11 passed");
        $this->assertSame(0, $results['failed'], "expected 0 failed");
        $this->assertSame(0, $results['errors'], "expected 0 errors");
        $this->assertCount(11, $results['details'], "expected 11 details");

        foreach ($results['details'] as $d) {
            $this->assertSame('passed', $d['status'], "expected passed for {$d['name']}");
        }
    }

    public function testDeliberateFailure(): void
    {
        Testing::reset();

        Testing::tests(
            [Testing::assertEqual([1, 1], 999)],
            function ($a, $b) { return $a + $b; },
            'bad_add'
        );

        $results = Testing::runAll(quiet: true);

        $this->assertSame(1, $results['failed'], "expected 1 failed for bad_add");
        $this->assertSame(0, $results['passed'], "expected 0 passed for bad_add");
    }

    public function testErrorReporting(): void
    {
        Testing::reset();

        Testing::tests(
            [Testing::assertEqual([1], 1)],
            function ($a) { throw new RuntimeException("boom"); },
            'will_crash'
        );

        $results = Testing::runAll(quiet: true);

        $this->assertSame(1, $results['errors'], "expected 1 error for will_crash");
    }
}
