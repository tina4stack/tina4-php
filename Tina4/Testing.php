<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Inline testing framework — attach test assertions to functions/methods
 * and run them all at once.
 *
 *     Testing::tests(
 *         [Testing::assertEqual([5, 3], 8), Testing::assertRaises('InvalidArgumentException', [null])],
 *         function ($a, $b = null) {
 *             if ($b === null) throw new \InvalidArgumentException("b required");
 *             return $a + $b;
 *         },
 *         'add'
 *     );
 *
 *     Testing::runAll();
 */
class Testing
{
    /** @var array<int, array{name: string, fn: callable, assertions: array}> */
    private static array $registry = [];

    // ── Assertion builders ─────────────────────────────────────────

    /**
     * Assert that calling the function with $args returns $expected.
     */
    public static function assertEqual(array $args, mixed $expected): array
    {
        return ['type' => 'equal', 'args' => $args, 'expected' => $expected];
    }

    /**
     * Assert that calling the function with $args throws $exceptionClass.
     */
    public static function assertRaises(string $exceptionClass, array $args): array
    {
        return ['type' => 'raises', 'exception' => $exceptionClass, 'args' => $args];
    }

    /**
     * Assert that calling the function with $args returns a truthy value.
     */
    public static function assertTrue(array $args): array
    {
        return ['type' => 'true', 'args' => $args];
    }

    /**
     * Assert that calling the function with $args returns a falsy value.
     */
    public static function assertFalse(array $args): array
    {
        return ['type' => 'false', 'args' => $args];
    }

    // ── Registration ───────────────────────────────────────────────

    /**
     * Register a callable with inline test assertions.
     *
     * @param array    $assertions  Array of assertion descriptors (from assertEqual, etc.)
     * @param callable $fn          The function under test
     * @param string   $name        Human-readable name for reporting
     */
    public static function tests(array $assertions, callable $fn, string $name = 'anonymous'): void
    {
        self::$registry[] = [
            'name' => $name,
            'fn' => $fn,
            'assertions' => $assertions,
        ];
    }

    // ── Runner ─────────────────────────────────────────────────────

    /**
     * Run all registered tests.
     *
     * @param bool $quiet     Suppress output
     * @param bool $failfast  Stop on first failure
     * @return array{passed: int, failed: int, errors: int, details: array}
     */
    public static function runAll(bool $quiet = false, bool $failfast = false): array
    {
        $results = ['passed' => 0, 'failed' => 0, 'errors' => 0, 'details' => []];

        foreach (self::$registry as $entry) {
            $fn = $entry['fn'];
            $name = $entry['name'];

            if (!$quiet) {
                echo "\n  {$name}\n";
            }

            foreach ($entry['assertions'] as $assertion) {
                $label = self::assertionLabel($assertion, $name);

                try {
                    self::runAssertion($fn, $assertion);
                    $results['passed']++;
                    $results['details'][] = ['name' => $label, 'status' => 'passed'];
                    if (!$quiet) {
                        echo "    \033[32m+\033[0m {$label}\n";
                    }
                } catch (TestAssertionFailed $e) {
                    $results['failed']++;
                    $results['details'][] = ['name' => $label, 'status' => 'failed', 'message' => $e->getMessage()];
                    if (!$quiet) {
                        echo "    \033[31mx\033[0m {$label}: {$e->getMessage()}\n";
                    }
                    if ($failfast) {
                        self::printSummary($results, $quiet);
                        return $results;
                    }
                } catch (\Throwable $e) {
                    $results['errors']++;
                    $msg = get_class($e) . ': ' . $e->getMessage();
                    $results['details'][] = ['name' => $label, 'status' => 'error', 'message' => $msg];
                    if (!$quiet) {
                        echo "    \033[33m!\033[0m {$label}: {$msg}\n";
                    }
                    if ($failfast) {
                        self::printSummary($results, $quiet);
                        return $results;
                    }
                }
            }
        }

        self::printSummary($results, $quiet);
        return $results;
    }

    /**
     * Reset the test registry (useful between test runs).
     */
    public static function reset(): void
    {
        self::$registry = [];
    }

    // ── Internals ──────────────────────────────────────────────────

    /**
     * @throws TestAssertionFailed
     */
    private static function runAssertion(callable $fn, array $assertion): void
    {
        $type = $assertion['type'];
        $args = $assertion['args'];

        switch ($type) {
            case 'equal':
                $result = call_user_func_array($fn, $args);
                $expected = $assertion['expected'];
                if ($result !== $expected) {
                    throw new TestAssertionFailed(
                        'expected ' . var_export($expected, true) . ', got ' . var_export($result, true)
                    );
                }
                break;

            case 'raises':
                $exceptionClass = $assertion['exception'];
                try {
                    call_user_func_array($fn, $args);
                } catch (\Throwable $e) {
                    if ($e instanceof $exceptionClass) {
                        return; // success
                    }
                    throw new TestAssertionFailed(
                        'expected ' . $exceptionClass . ', got ' . get_class($e) . ': ' . $e->getMessage()
                    );
                }
                throw new TestAssertionFailed('expected ' . $exceptionClass . ' to be raised');

            case 'true':
                $result = call_user_func_array($fn, $args);
                if (!$result) {
                    throw new TestAssertionFailed('expected truthy, got ' . var_export($result, true));
                }
                break;

            case 'false':
                $result = call_user_func_array($fn, $args);
                if ($result) {
                    throw new TestAssertionFailed('expected falsy, got ' . var_export($result, true));
                }
                break;

            default:
                throw new \RuntimeException("unknown assertion type: {$type}");
        }
    }

    private static function assertionLabel(array $assertion, string $fnName): string
    {
        $type = $assertion['type'];
        $args = json_encode($assertion['args']);

        return match ($type) {
            'equal' => "{$fnName}({$args}) == " . var_export($assertion['expected'], true),
            'raises' => "{$fnName}({$args}) raises {$assertion['exception']}",
            'true' => "{$fnName}({$args}) is truthy",
            'false' => "{$fnName}({$args}) is falsy",
            default => "{$fnName} [{$type}]",
        };
    }

    private static function printSummary(array $results, bool $quiet): void
    {
        if ($quiet) {
            return;
        }
        $total = $results['passed'] + $results['failed'] + $results['errors'];
        echo "\n  {$total} tests: "
            . "\033[32m{$results['passed']} passed\033[0m, "
            . "\033[31m{$results['failed']} failed\033[0m, "
            . "\033[33m{$results['errors']} errors\033[0m\n\n";
    }
}

/**
 * Custom exception for test assertion failures (distinct from runtime errors).
 */
class TestAssertionFailed extends \RuntimeException
{
}
