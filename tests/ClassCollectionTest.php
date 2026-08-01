<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;

/**
 * CI-collection guard, second half: every TestCase CLASS must be collectable.
 *
 * ConfiguredTestFilesTest guards FILES — that every `tests/*Test.php` on disk is
 * collected by phpunit.xml. That is necessary and not sufficient. PHPUnit 11
 * instantiates only the class whose name matches the file basename, so a SECOND
 * `class X extends TestCase` inside an already-collected file is silently
 * ignored: the file gates CI, the class does not, and nothing reports it.
 *
 * MEASURED, this was not hypothetical. Two classes had been buried this way:
 *
 *   - SQLiteLimitDedupTest, at tests/DevAdminTest.php:1411. `--list-tests`
 *     returned ZERO matches for it while DevAdminTest in the SAME file
 *     contributed 81 tests. Extracted and run for the first time, it FAILED —
 *     it asserted a default row cap of 10 that no framework has ever had in v3.
 *   - WebSocketRoomsTest, buried the same way in tests/WebSocketV3Test.php.
 *
 * A test that never runs is worse than a missing test: the count looks healthy
 * and the coverage is imaginary.
 *
 * THE RULE: a concrete class that is (transitively) a PHPUnit TestCase and lives
 * in tests/ must be declared in a file named after it. Abstract bases are exempt
 * — PHPUnit never instantiates them, so they are free to be shared and named
 * whatever suits.
 *
 * Fixture classes are NOT caught, by design. 69 classes under tests/ extend
 * `ORM`, `WSDL`, `Service`, `MigrationBase` and similar — ORM models and doubles
 * declared beside the test that uses them. They are not TestCase subclasses,
 * PHPUnit never tries to collect them, and requiring a file each would be
 * churn for no gate. So the parent chain is resolved TRANSITIVELY and only
 * classes that actually reach TestCase are held to the rule.
 *
 * Pure static parsing of the real files on disk — no mock, no live dependency,
 * and no autoload side effects from including test files to reflect on them.
 */
final class ClassCollectionTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/..';

    /**
     * Every `class X extends Y` declared under tests/, keyed by class name.
     *
     * @return array<string, array{file: string, line: int, parent: string, abstract: bool}>
     */
    private function declaredClasses(): array
    {
        $declared = [];
        foreach (glob(self::REPO_ROOT . '/tests/*.php') ?: [] as $path) {
            $src = file_get_contents($path);
            if ($src === false) {
                continue;
            }
            preg_match_all(
                '/^[ \t]*(final\s+|abstract\s+)?class\s+(\w+)\s+extends\s+([\w\\\\]+)/m',
                $src,
                $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            );
            foreach ($matches as $m) {
                $modifier = trim($m[1][0]);
                $class = $m[2][0];
                $parent = $m[3][0];
                // Namespace-qualified parents resolve on their last segment:
                // `\PHPUnit\Framework\TestCase` and `TestCase` are the same base.
                // strrpos returns FALSE (not -1) for an unqualified name, and
                // (int) false is 0 — casting it would silently chop the first
                // character, turning `TestCase` into `estCase` and matching
                // nothing. Test the sentinel explicitly.
                $sep = strrpos($parent, '\\');
                $parentShort = $sep === false ? $parent : substr($parent, $sep + 1);
                $declared[$class] = [
                    'file' => basename($path),
                    'line' => substr_count(substr($src, 0, (int) $m[0][1]), "\n") + 1,
                    'parent' => $parentShort,
                    'abstract' => $modifier === 'abstract',
                ];
            }
        }

        return $declared;
    }

    /**
     * Walk the parent chain to decide whether $class is ultimately a TestCase.
     *
     * A class whose parent is not declared under tests/ terminates the walk: it
     * is a TestCase only if that parent IS literally TestCase (the framework
     * class). This is what keeps ORM/WSDL/Service fixtures out of the rule.
     *
     * @param string $class The class to classify.
     * @param array<string, array{file: string, line: int, parent: string, abstract: bool}> $declared All declarations.
     * @param array<string, true> $seen Guards against a cyclic `extends` chain.
     */
    private function isTestCase(string $class, array $declared, array $seen = []): bool
    {
        if (isset($seen[$class])) {
            return false;   // cycle — cannot reach TestCase
        }
        $seen[$class] = true;

        if (!isset($declared[$class])) {
            return $class === 'TestCase';
        }

        return $this->isTestCase($declared[$class]['parent'], $declared, $seen);
    }

    /**
     * THE guard: no concrete TestCase subclass may hide in a file named after a
     * different class, because PHPUnit will never instantiate it.
     */
    public function testEveryTestCaseClassLivesInAFileNamedAfterIt(): void
    {
        $declared = $this->declaredClasses();
        $this->assertNotEmpty($declared, 'the parser must find classes under tests/ — an empty result would pass vacuously');

        $buried = [];
        foreach ($declared as $class => $info) {
            if ($info['abstract'] || !$this->isTestCase($class, $declared)) {
                continue;
            }
            $expected = pathinfo($info['file'], PATHINFO_FILENAME);
            if ($class !== $expected) {
                $buried[] = "tests/{$info['file']}:{$info['line']} declares `class {$class} extends {$info['parent']}` "
                    . "but PHPUnit collects only `{$expected}` from that file — move it to tests/{$class}.php";
            }
        }

        $this->assertSame(
            [],
            $buried,
            "These TestCase classes are NEVER EXECUTED. PHPUnit 11 instantiates only the class whose "
            . "name matches the file basename, so a second TestCase class in one file is silently "
            . "skipped while the file itself still gates CI (SQLiteLimitDedupTest and WebSocketRoomsTest "
            . "were both buried this way, and the first one FAILED the moment it was finally run):\n  - "
            . implode("\n  - ", $buried)
        );
    }

    /**
     * Sanity: the parser must actually see the TestCase classes it is guarding.
     *
     * Without this, a regex that silently stopped matching would make the guard
     * above pass with an empty candidate set — green, and guarding nothing.
     */
    public function testParserFindsTheKnownTestCaseClasses(): void
    {
        $declared = $this->declaredClasses();

        $cases = array_filter(
            array_keys($declared),
            fn(string $c): bool => $this->isTestCase($c, $declared)
        );

        $this->assertGreaterThan(
            100,
            count($cases),
            'the parser should see hundreds of TestCase classes; a much smaller number means the regex broke'
        );
        $this->assertContains(ClassCollectionTest::class, $cases, 'the parser must find this very class');
        $this->assertContains('SQLiteLimitDedupTest', $cases, 'the rescued class must be seen as a TestCase');

        // And it must NOT sweep in the ORM/WSDL fixture classes that legitimately
        // share a file with the test using them.
        $this->assertArrayHasKey('CrudItem', $declared, 'CrudItem is a real fixture declared in AutoCrudV3Test.php');
        $this->assertNotContains('CrudItem', $cases, 'an ORM fixture must not be treated as a TestCase');
    }

    /**
     * Negative case — proves the guard has TEETH.
     *
     * Feeds the classifier a synthetic declaration set shaped exactly like the
     * real bug (two TestCase classes in one file) and asserts the second one is
     * detected as buried, while an ORM fixture in the same file is not. A broken
     * classifier that returned "nothing is buried" would let the positive test
     * above pass vacuously.
     */
    public function testClassifierDetectsASecondTestCaseClassInOneFile(): void
    {
        $synthetic = [
            'DevAdminTest' => ['file' => 'DevAdminTest.php', 'line' => 15, 'parent' => 'TestCase', 'abstract' => false],
            // the real shape of the bug: collected file, uncollected class
            'SQLiteLimitDedupTest' => ['file' => 'DevAdminTest.php', 'line' => 1411, 'parent' => 'TestCase', 'abstract' => false],
            // a fixture in the same file, which must NOT be flagged
            'CrudItem' => ['file' => 'DevAdminTest.php', 'line' => 21, 'parent' => 'ORM', 'abstract' => false],
            // an abstract base, exempt because PHPUnit never instantiates it
            'SharedBase' => ['file' => 'Support.php', 'line' => 5, 'parent' => 'TestCase', 'abstract' => true],
        ];

        $buried = [];
        foreach ($synthetic as $class => $info) {
            if ($info['abstract'] || !$this->isTestCase($class, $synthetic)) {
                continue;
            }
            if ($class !== pathinfo($info['file'], PATHINFO_FILENAME)) {
                $buried[] = $class;
            }
        }

        $this->assertSame(
            ['SQLiteLimitDedupTest'],
            $buried,
            'the classifier must flag the buried TestCase class, and only it — '
            . 'not the ORM fixture and not the abstract base'
        );
    }
}
