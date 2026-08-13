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
 * The builders are named expect* — deliberately distinct from the xUnit-style
 * assert* the PHPUnit suites use — so importing the wrong surface can never
 * silently change call semantics. expect* are DESCRIPTORS: expectEqual([args],
 * expected) records "call the function with args and check the result equals
 * expected" for runAll() to execute later.
 *
 * Option 1: Explicit registration
 *
 *     Testing::tests(
 *         [Testing::expectEqual([5, 3], 8), Testing::expectRaises('InvalidArgumentException', [null])],
 *         function ($a, $b = null) {
 *             if ($b === null) throw new \InvalidArgumentException("b required");
 *             return $a + $b;
 *         },
 *         'add'
 *     );
 *
 * Option 2: Docblock annotations (auto-discovered from an EXPLICIT tests dir)
 *
 *     /**
 *      * @tests expectEqual([5, 3], 8)
 *      * @tests expectEqual([0, 0], 0)
 *      * @tests expectRaises(InvalidArgumentException::class, [null])
 *      *\/
 *     function add(int $a, ?int $b = null): int { ... }
 *
 *     Testing::discover('tests');   // scans an EXPLICIT tests dir for @tests docblocks
 *     Testing::runAll();
 *
 * SECURITY: discover() never evaluates the docblock arguments (they are parsed
 * as LITERALS only) and only require_once's files under the resolved tests
 * directory, so scanning cannot execute arbitrary code (INLINE-DEC-02).
 */
class Testing
{
    /** @var array<int, array{name: string, fn: callable, assertions: array}> */
    private static array $registry = [];

    // ── Assertion builders ─────────────────────────────────────────

    /**
     * Expect that calling the function with $args returns $expected.
     */
    public static function expectEqual(array $args, mixed $expected): array
    {
        return ['type' => 'equal', 'args' => $args, 'expected' => $expected];
    }

    /**
     * Expect that calling the function with $args throws $exceptionClass.
     */
    public static function expectRaises(string $exceptionClass, array $args): array
    {
        return ['type' => 'raises', 'exception' => $exceptionClass, 'args' => $args];
    }

    /**
     * Expect that calling the function with $args returns a truthy value.
     */
    public static function expectTrue(array $args): array
    {
        return ['type' => 'true', 'args' => $args];
    }

    /**
     * Expect that calling the function with $args returns a falsy value.
     */
    public static function expectFalse(array $args): array
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
     * Scan an EXPLICIT tests directory for functions/methods with @tests docblock
     * annotations and auto-register them.
     *
     * Supports standalone functions and static class methods.
     *
     *     /** @tests expectEqual([5, 3], 8) *\/
     *     function add(int $a, int $b): int { return $a + $b; }
     *
     * SECURITY (INLINE-DEC-02): discovery is confined to the resolved tests
     * directory — a scanned file whose realpath escapes it (e.g. via a symlink)
     * is skipped and never require_once'd, so pointing discovery at a project can
     * never execute a source file outside the tests dir. The docblock arguments
     * are parsed as LITERALS (see parseAssertionLine) and never eval'd.
     *
     * @param string $path  Explicit tests directory to scan (default 'tests')
     * @return int Number of functions discovered
     */
    public static function discover(string $path = 'tests'): int
    {
        $root = realpath($path);
        if ($root === false || !is_dir($root)) {
            return 0;
        }

        $count = 0;
        $files = self::globRecursive($root, '*.php');

        foreach ($files as $file) {
            // Confine every required file under the resolved tests dir — a
            // symlink pointing outside it is refused, so discovery can never
            // require_once (and thus execute) a file outside the tests tree.
            $real = realpath($file);
            if ($real === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
                continue;
            }

            $source = file_get_contents($real);
            if ($source === false || strpos($source, '@tests') === false) {
                continue;
            }

            // Include the file so functions/classes are available via reflection.
            // The tests dir is the developer's own trusted code (PHPUnit requires
            // its files the same way); source files outside it are never touched.
            require_once $real;

            // Find all docblocks containing @tests followed by a function declaration
            $count += self::discoverFunctions($source, $real);
            $count += self::discoverMethods($source, $real);
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

    /** Sentinel returned by parseLiteral() when a token is not a plain literal. */
    private const NOT_A_LITERAL = "\0__tina4_not_a_literal__\0";

    /**
     * Parse a single assertion expression like "expectEqual([5, 3], 8)".
     *
     * SECURITY (INLINE-DEC-02): the arguments are parsed as LITERALS ONLY —
     * there is NO eval(). A non-literal argument (a function call, a variable,
     * an operator) makes the assertion unparseable, so it is skipped rather than
     * executed. This closes the arbitrary-code-execution hole the old eval() had.
     */
    private static function parseAssertionLine(string $line): ?array
    {
        // Match: expectMethod(...)
        if (!preg_match('/^(expectEqual|expectRaises|expectTrue|expectFalse)\s*\((.*)\)$/s', $line, $m)) {
            return null;
        }

        $method = $m[1];
        $argsStr = trim($m[2]);

        $values = [];
        foreach (self::splitTopLevel($argsStr) as $part) {
            $lit = self::parseLiteral(trim($part));
            if ($lit === self::NOT_A_LITERAL) {
                return null;   // refuse anything that is not a plain literal
            }
            $values[] = $lit;
        }

        return match ($method) {
            'expectEqual' => count($values) >= 2
                ? self::expectEqual((array)$values[0], $values[1])
                : null,
            'expectRaises' => count($values) >= 2
                ? self::expectRaises((string)$values[0], (array)$values[1])
                : null,
            'expectTrue' => count($values) >= 1
                ? self::expectTrue((array)$values[0])
                : null,
            'expectFalse' => count($values) >= 1
                ? self::expectFalse((array)$values[0])
                : null,
            default => null,
        };
    }

    /**
     * Split a comma-separated argument list at the TOP level only — commas
     * inside [] brackets or '…'/"…" strings are not split points.
     *
     * @return array<int, string>
     */
    private static function splitTopLevel(string $s): array
    {
        $parts = [];
        $depth = 0;
        $buf = '';
        $quote = null;
        $len = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];

            if ($quote !== null) {
                $buf .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $buf .= $s[++$i];   // keep an escaped char with its backslash
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $buf .= $ch;
            } elseif ($ch === '[') {
                $depth++;
                $buf .= $ch;
            } elseif ($ch === ']') {
                $depth--;
                $buf .= $ch;
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = $buf;
                $buf = '';
            } else {
                $buf .= $ch;
            }
        }

        if (trim($buf) !== '' || $parts !== []) {
            $parts[] = $buf;
        }
        return $parts;
    }

    /**
     * Parse ONE literal token — an int, float, bool, null, quoted string, an
     * array of literals ([a, b, …]), or a class reference (Foo::class / a bare
     * class name → the class-name string). Anything else (a function call, a
     * variable, an arithmetic expression) returns the NOT_A_LITERAL sentinel and
     * is refused — the eval-free, injection-safe replacement for the old eval().
     *
     * @return mixed the literal value, or self::NOT_A_LITERAL
     */
    private static function parseLiteral(string $tok): mixed
    {
        $tok = trim($tok);
        if ($tok === '') {
            return self::NOT_A_LITERAL;
        }

        // Array literal: [ elem, elem, … ] of literals.
        if ($tok[0] === '[' && substr($tok, -1) === ']') {
            $inner = trim(substr($tok, 1, -1));
            if ($inner === '') {
                return [];
            }
            $out = [];
            foreach (self::splitTopLevel($inner) as $elem) {
                $val = self::parseLiteral(trim($elem));
                if ($val === self::NOT_A_LITERAL) {
                    return self::NOT_A_LITERAL;
                }
                $out[] = $val;
            }
            return $out;
        }

        // Quoted string.
        $q = $tok[0];
        if (($q === "'" || $q === '"') && substr($tok, -1) === $q && strlen($tok) >= 2) {
            $body = substr($tok, 1, -1);
            return $q === "'"
                ? str_replace(["\\'", "\\\\"], ["'", "\\"], $body)
                : stripcslashes($body);
        }

        // Class reference: Foo::class → the class-name string.
        if (str_ends_with($tok, '::class')) {
            return ltrim(substr($tok, 0, -strlen('::class')), '\\');
        }

        $lower = strtolower($tok);
        if ($lower === 'true')  { return true; }
        if ($lower === 'false') { return false; }
        if ($lower === 'null')  { return null; }

        if (preg_match('/^-?\d+$/', $tok)) {
            return (int) $tok;
        }
        if (preg_match('/^-?\d+\.\d+$/', $tok)) {
            return (float) $tok;
        }

        // A bare class-name reference (expectRaises without ::class).
        if (preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*$/', $tok)) {
            return ltrim($tok, '\\');
        }

        return self::NOT_A_LITERAL;
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
