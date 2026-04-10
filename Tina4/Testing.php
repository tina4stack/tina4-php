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
 * Option 1: Explicit registration
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
 * Option 2: Docblock annotations (auto-discovered)
 *
 *     /**
 *      * @tests assertEqual([5, 3], 8)
 *      * @tests assertEqual([0, 0], 0)
 *      * @tests assertRaises(InvalidArgumentException::class, [null])
 *      *\/
 *     function add(int $a, ?int $b = null): int { ... }
 *
 *     Testing::discover('src/');   // scans for @tests docblocks
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

    // ── Docblock discovery ────────────────────────────────────────

    /**
     * Scan PHP files for functions/methods with @tests docblock annotations
     * and auto-register them.
     *
     * Supports standalone functions and static class methods.
     *
     *     /** @tests assertEqual([5, 3], 8) *\/
     *     function add(int $a, int $b): int { return $a + $b; }
     *
     * @param string $path  Directory to scan recursively
     * @return int Number of functions discovered
     */
    public static function discover(string $path): int
    {
        $count = 0;
        $files = self::globRecursive($path, '*.php');

        foreach ($files as $file) {
            $source = file_get_contents($file);
            if ($source === false || strpos($source, '@tests') === false) {
                continue;
            }

            // Include the file so functions/classes are available via reflection
            require_once $file;

            // Find all docblocks containing @tests followed by a function declaration
            $count += self::discoverFunctions($source, $file);
            $count += self::discoverMethods($source, $file);
        }

        return $count;
    }

    /**
     * Discover standalone functions with @tests docblocks.
     */
    private static function discoverFunctions(string $source, string $file): int
    {
        $count = 0;
        // Match: docblock → function name(
        $pattern = '/\/\*\*(.*?)\*\/\s*function\s+(\w+)\s*\(/s';

        if (!preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
            return 0;
        }

        // Detect namespace
        $namespace = '';
        if (preg_match('/namespace\s+([\w\\\\]+)\s*;/', $source, $nsMatch)) {
            $namespace = $nsMatch[1] . '\\';
        }

        foreach ($matches as $match) {
            $docblock = $match[1];
            $funcName = $match[2];

            if (strpos($docblock, '@tests') === false) {
                continue;
            }

            $fqn = $namespace . $funcName;

            $assertions = self::parseDocblockAssertions($docblock);
            if (empty($assertions)) {
                continue;
            }

            if (!function_exists($fqn)) {
                continue;
            }

            self::tests($assertions, $fqn, $funcName);
            $count++;
        }

        return $count;
    }

    /**
     * Discover static class methods with @tests docblocks.
     */
    private static function discoverMethods(string $source, string $file): int
    {
        $count = 0;
        // Match: docblock → public static function name(
        $pattern = '/\/\*\*(.*?)\*\/\s*public\s+static\s+function\s+(\w+)\s*\(/s';

        if (!preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return 0;
        }

        // Detect namespace
        $namespace = '';
        if (preg_match('/namespace\s+([\w\\\\]+)\s*;/', $source, $nsMatch)) {
            $namespace = $nsMatch[1] . '\\';
        }

        // Find all class declarations and their positions
        preg_match_all('/class\s+(\w+)/', $source, $classMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $docblock = $match[1][0];
            $methodName = $match[2][0];
            $position = $match[0][1];

            if (strpos($docblock, '@tests') === false) {
                continue;
            }

            $assertions = self::parseDocblockAssertions($docblock);
            if (empty($assertions)) {
                continue;
            }

            // Find the class this method belongs to (nearest class declaration before it)
            $className = null;
            foreach ($classMatches as $cm) {
                if ($cm[0][1] < $position) {
                    $className = $cm[1][0];
                }
            }

            if ($className === null) {
                continue;
            }

            $fqn = $namespace . $className;
            if (!class_exists($fqn) || !method_exists($fqn, $methodName)) {
                continue;
            }

            self::tests($assertions, [$fqn, $methodName], "{$className}::{$methodName}");
            $count++;
        }

        return $count;
    }

    /**
     * Parse @tests lines from a docblock into assertion arrays.
     *
     * Each @tests line should contain an assertion call, e.g.:
     *   @tests assertEqual([5, 3], 8)
     *   @tests assertRaises(InvalidArgumentException::class, [null])
     *   @tests assertTrue([1, 1])
     *   @tests assertFalse([0, 0])
     */
    private static function parseDocblockAssertions(string $docblock): array
    {
        $assertions = [];

        // Extract @tests lines
        if (!preg_match_all('/@tests\s+(.+)/', $docblock, $lines)) {
            return [];
        }

        foreach ($lines[1] as $line) {
            $line = trim($line);
            // Remove trailing */ or * if present
            $line = rtrim($line, ' */');

            $assertion = self::parseAssertionLine($line);
            if ($assertion !== null) {
                $assertions[] = $assertion;
            }
        }

        return $assertions;
    }

    /**
     * Parse a single assertion expression like "assertEqual([5, 3], 8)".
     */
    private static function parseAssertionLine(string $line): ?array
    {
        // Match: methodName(...)
        if (!preg_match('/^(assertEqual|assertRaises|assertTrue|assertFalse)\s*\((.+)\)$/s', $line, $m)) {
            return null;
        }

        $method = $m[1];
        $argsStr = trim($m[2]);

        // Evaluate the arguments safely using PHP's eval
        // We wrap in an array context so the expression becomes valid PHP
        try {
            $evaluated = eval("return [{$argsStr}];");
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($evaluated)) {
            return null;
        }

        return match ($method) {
            'assertEqual' => count($evaluated) >= 2
                ? self::assertEqual((array)$evaluated[0], $evaluated[1])
                : null,
            'assertRaises' => count($evaluated) >= 2
                ? self::assertRaises((string)$evaluated[0], (array)$evaluated[1])
                : null,
            'assertTrue' => count($evaluated) >= 1
                ? self::assertTrue((array)$evaluated[0])
                : null,
            'assertFalse' => count($evaluated) >= 1
                ? self::assertFalse((array)$evaluated[0])
                : null,
            default => null,
        };
    }

    /**
     * Recursively glob for files matching a pattern.
     */
    private static function globRecursive(string $dir, string $pattern): array
    {
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob("{$dir}/{$pattern}") ?: [];
        $subdirs = glob("{$dir}/*", GLOB_ONLYDIR) ?: [];

        foreach ($subdirs as $subdir) {
            $files = array_merge($files, self::globRecursive($subdir, $pattern));
        }

        return $files;
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
