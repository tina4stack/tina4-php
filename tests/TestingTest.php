<?php

/**
 * Tests for the inline testing framework itself.
 */

require_once __DIR__ . '/../Tina4/Testing.php';

use Tina4\Testing;

// ── Register functions under test ──────────────────────────────────

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

// ── Run and verify ─────────────────────────────────────────────────

$results = Testing::runAll(quiet: true);

$errors = [];

if ($results['passed'] !== 11) {
    $errors[] = "expected 11 passed, got {$results['passed']}";
}
if ($results['failed'] !== 0) {
    $errors[] = "expected 0 failed, got {$results['failed']}";
}
if ($results['errors'] !== 0) {
    $errors[] = "expected 0 errors, got {$results['errors']}";
}
if (count($results['details']) !== 11) {
    $errors[] = "expected 11 details, got " . count($results['details']);
}

foreach ($results['details'] as $d) {
    if ($d['status'] !== 'passed') {
        $errors[] = "expected passed, got {$d['status']} for {$d['name']}";
    }
}

// ── Test deliberate failure ────────────────────────────────────────

Testing::reset();

Testing::tests(
    [Testing::assertEqual([1, 1], 999)],
    function ($a, $b) { return $a + $b; },
    'bad_add'
);

$failResults = Testing::runAll(quiet: true);

if ($failResults['failed'] !== 1) {
    $errors[] = "expected 1 failed for bad_add, got {$failResults['failed']}";
}
if ($failResults['passed'] !== 0) {
    $errors[] = "expected 0 passed for bad_add, got {$failResults['passed']}";
}

// ── Test error reporting ───────────────────────────────────────────

Testing::reset();

Testing::tests(
    [Testing::assertEqual([1], 1)],
    function ($a) { throw new RuntimeException("boom"); },
    'will_crash'
);

$errorResults = Testing::runAll(quiet: true);

if ($errorResults['errors'] !== 1) {
    $errors[] = "expected 1 error for will_crash, got {$errorResults['errors']}";
}

// ── Report ─────────────────────────────────────────────────────────

if (empty($errors)) {
    echo "\nAll meta-tests passed.\n";
    exit(0);
} else {
    echo "\nFAILED:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
    exit(1);
}
