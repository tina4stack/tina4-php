<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Metrics — comprehensive unit tests for code metrics analysis.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Metrics;

class MetricsTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tina4-metrics-test-' . getmypid() . '-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->rmdirRecursive($this->tempDir);
        }
    }

    private function rmdirRecursive(string $dir): void
    {
        $entries = scandir($dir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rmdirRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function writePhpFile(string $filename, string $content): void
    {
        $path = $this->tempDir . '/' . $filename;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
    }

    // ── Quick Metrics ──────────────────────────────────────────────

    public function testQuickMetricsOnEmptyDirectory(): void
    {
        // No PHP files in directory — should fall back to framework scan
        $result = Metrics::quickMetrics($this->tempDir);
        // Either returns valid metrics (framework fallback) or the dir itself
        $this->assertIsArray($result);
    }

    public function testQuickMetricsReturnsExpectedKeys(): void
    {
        $this->writePhpFile('Sample.php', <<<'PHP'
<?php

class Sample
{
    public function hello(): string
    {
        return "world";
    }

    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}
PHP);

        $result = Metrics::quickMetrics($this->tempDir);
        $this->assertArrayHasKey('file_count', $result);
        $this->assertArrayHasKey('total_loc', $result);
        $this->assertArrayHasKey('total_blank', $result);
        $this->assertArrayHasKey('total_comment', $result);
        $this->assertArrayHasKey('classes', $result);
        $this->assertArrayHasKey('functions', $result);
        $this->assertArrayHasKey('breakdown', $result);
        $this->assertArrayHasKey('largest_files', $result);
    }

    public function testQuickMetricsCountsFiles(): void
    {
        $this->writePhpFile('One.php', '<?php class One {}');
        $this->writePhpFile('Two.php', '<?php class Two {}');
        $this->writePhpFile('Three.php', '<?php function three() {}');

        $result = Metrics::quickMetrics($this->tempDir);
        $this->assertEquals(3, $result['file_count']);
    }

    public function testQuickMetricsCountsClasses(): void
    {
        $this->writePhpFile('Classes.php', <<<'PHP'
<?php

class Alpha {}
class Beta {}
PHP);

        $result = Metrics::quickMetrics($this->tempDir);
        $this->assertEquals(2, $result['classes']);
    }

    public function testQuickMetricsCountsFunctions(): void
    {
        $this->writePhpFile('Funcs.php', <<<'PHP'
<?php

function one() {}
function two() {}
function three() {}
PHP);

        $result = Metrics::quickMetrics($this->tempDir);
        $this->assertEquals(3, $result['functions']);
    }

    public function testQuickMetricsCountsLoc(): void
    {
        $this->writePhpFile('Loc.php', <<<'PHP'
<?php

// This is a comment
function foo()
{
    return 1;
}

/* Block comment */
PHP);

        $result = Metrics::quickMetrics($this->tempDir);
        $this->assertGreaterThan(0, $result['total_loc']);
        $this->assertGreaterThan(0, $result['total_comment']);
    }

    public function testQuickMetricsCountsBlankLines(): void
    {
        $this->writePhpFile('Blank.php', "<?php\n\nfunction x() {\n\n    return 1;\n\n}\n");

        $result = Metrics::quickMetrics($this->tempDir);
        $this->assertGreaterThan(0, $result['total_blank']);
    }

    public function testQuickMetricsAvgFileSize(): void
    {
        $this->writePhpFile('A.php', "<?php\nfunction a() { return 1; }\n");
        $this->writePhpFile('B.php', "<?php\nfunction b() { return 2; }\n");

        $result = Metrics::quickMetrics($this->tempDir);
        $this->assertGreaterThan(0, $result['avg_file_size']);
    }

    public function testQuickMetricsLargestFiles(): void
    {
        $this->writePhpFile('Big.php', "<?php\n" . str_repeat("echo 'line';\n", 50));
        $this->writePhpFile('Small.php', "<?php\necho 1;\n");

        $result = Metrics::quickMetrics($this->tempDir);
        $this->assertNotEmpty($result['largest_files']);
        // Largest file should be first
        $this->assertGreaterThanOrEqual(
            $result['largest_files'][count($result['largest_files']) - 1]['loc'],
            $result['largest_files'][0]['loc']
        );
    }

    public function testQuickMetricsBreakdown(): void
    {
        $this->writePhpFile('App.php', '<?php class App {}');

        $result = Metrics::quickMetrics($this->tempDir);
        $this->assertArrayHasKey('php', $result['breakdown']);
        $this->assertArrayHasKey('templates', $result['breakdown']);
        $this->assertArrayHasKey('migrations', $result['breakdown']);
        $this->assertArrayHasKey('stylesheets', $result['breakdown']);
    }

    public function testQuickMetricsWithNonexistentDir(): void
    {
        // This should fall back to framework scan or return valid result
        $result = Metrics::quickMetrics('/nonexistent/path/12345');
        $this->assertIsArray($result);
    }

    // ── Full Analysis ──────────────────────────────────────────────

    public function testFullAnalysisReturnsExpectedKeys(): void
    {
        $this->writePhpFile('Full.php', <<<'PHP'
<?php

class Calculator
{
    public function compute(int $x): int
    {
        if ($x > 10) {
            return $x * 2;
        } elseif ($x > 5) {
            return $x + 1;
        }
        return $x;
    }
}
PHP);

        $result = Metrics::fullAnalysis($this->tempDir);
        $this->assertArrayHasKey('files_analyzed', $result);
        $this->assertArrayHasKey('total_functions', $result);
        $this->assertArrayHasKey('avg_complexity', $result);
        $this->assertArrayHasKey('avg_maintainability', $result);
        $this->assertArrayHasKey('most_complex_functions', $result);
        $this->assertArrayHasKey('file_metrics', $result);
        $this->assertArrayHasKey('violations', $result);
    }

    public function testFullAnalysisComplexityScoring(): void
    {
        $this->writePhpFile('Complex.php', <<<'PHP'
<?php

class Complex
{
    public function simple(): int
    {
        return 1;
    }

    public function branchy(int $x): string
    {
        if ($x > 100) {
            return "high";
        } elseif ($x > 50) {
            return "medium";
        } elseif ($x > 25) {
            return "low";
        } else {
            return "very low";
        }
    }
}
PHP);

        $result = Metrics::fullAnalysis($this->tempDir);

        $this->assertGreaterThan(0, $result['total_functions']);

        // branchy should have higher complexity than simple
        $funcs = $result['most_complex_functions'];
        $branchyFound = false;
        foreach ($funcs as $f) {
            if (str_contains($f['name'], 'branchy')) {
                $branchyFound = true;
                $this->assertGreaterThan(1, $f['complexity']);
            }
        }
        $this->assertTrue($branchyFound);
    }

    public function testFullAnalysisMaintainabilityIndex(): void
    {
        $this->writePhpFile('Maintain.php', <<<'PHP'
<?php

class Maintain
{
    public function clean(): int
    {
        return 42;
    }
}
PHP);

        $result = Metrics::fullAnalysis($this->tempDir);
        $this->assertGreaterThan(0, $result['avg_maintainability']);
    }

    public function testFullAnalysisCaching(): void
    {
        $this->writePhpFile('Cached.php', '<?php function cached() { return 1; }');

        $result1 = Metrics::fullAnalysis($this->tempDir);
        $result2 = Metrics::fullAnalysis($this->tempDir);

        // Second call should hit cache and return same data
        $this->assertEquals($result1['files_analyzed'], $result2['files_analyzed']);
    }

    // ── File Detail ────────────────────────────────────────────────

    public function testFileDetailReturnsExpectedKeys(): void
    {
        $filePath = $this->tempDir . '/Detail.php';
        file_put_contents($filePath, <<<'PHP'
<?php

class Detail
{
    public function method(): void
    {
        echo "hello";
    }
}
PHP);

        $result = Metrics::fileDetail($filePath);
        $this->assertArrayHasKey('path', $result);
        $this->assertArrayHasKey('loc', $result);
        $this->assertArrayHasKey('total_lines', $result);
        $this->assertArrayHasKey('classes', $result);
        $this->assertArrayHasKey('functions', $result);
        $this->assertArrayHasKey('imports', $result);
        $this->assertArrayHasKey('warnings', $result);
    }

    public function testFileDetailCountsClassesAndFunctions(): void
    {
        $filePath = $this->tempDir . '/Count.php';
        file_put_contents($filePath, <<<'PHP'
<?php

class CountClass
{
    public function one(): void {}
    public function two(): void {}
}

function standalone(): void {}
PHP);

        $result = Metrics::fileDetail($filePath);
        $this->assertEquals(1, $result['classes']);
        $this->assertCount(3, $result['functions']); // one, two, standalone
    }

    public function testFileDetailNonExistentFile(): void
    {
        $result = Metrics::fileDetail('/nonexistent/file/12345.php');
        $this->assertArrayHasKey('error', $result);
    }

    public function testFileDetailFunctionComplexity(): void
    {
        $filePath = $this->tempDir . '/FuncComplexity.php';
        file_put_contents($filePath, <<<'PHP'
<?php

function simple_func(): int
{
    return 1;
}

function complex_func(int $x): string
{
    if ($x > 10 && $x < 100) {
        return "mid";
    } elseif ($x >= 100 || $x < 0) {
        return "extreme";
    }
    return "low";
}
PHP);

        $result = Metrics::fileDetail($filePath);
        $this->assertNotEmpty($result['functions']);

        // Check that functions have complexity scores
        foreach ($result['functions'] as $func) {
            $this->assertArrayHasKey('complexity', $func);
            $this->assertArrayHasKey('name', $func);
            $this->assertArrayHasKey('line', $func);
            $this->assertArrayHasKey('loc', $func);
        }
    }

    public function testFileDetailDetectsImports(): void
    {
        $filePath = $this->tempDir . '/Imports.php';
        file_put_contents($filePath, <<<'PHP'
<?php

use Tina4\Router;
use Tina4\Request;

class ImportTest
{
    public function handle(): void {}
}
PHP);

        $result = Metrics::fileDetail($filePath);
        $this->assertNotEmpty($result['imports']);
    }

    public function testFileDetailWarnsOnEmptyClass(): void
    {
        $filePath = $this->tempDir . '/EmptyClass.php';
        file_put_contents($filePath, <<<'PHP'
<?php

class EmptyClass
{
}
PHP);

        $result = Metrics::fileDetail($filePath);
        $hasEmptyClassWarning = false;
        foreach ($result['warnings'] as $w) {
            if ($w['type'] === 'empty_class') {
                $hasEmptyClassWarning = true;
                break;
            }
        }
        $this->assertTrue($hasEmptyClassWarning);
    }

    // ── Cyclomatic complexity accuracy (lock-in) ───────────────────
    // Decision points are counted on CODE ONLY — never on text that merely
    // sits inside a string literal or a comment. Mirrors the intent of the
    // Python AST-based analyzer (tests/test_metrics.py).

    /** Helper: complexity of a named function in a written-out file. */
    private function complexityOf(string $source, string $needle): int
    {
        $filePath = $this->tempDir . '/CcLock_' . md5($needle . $source) . '.php';
        file_put_contents($filePath, $source);
        $result = Metrics::fileDetail($filePath);
        foreach ($result['functions'] as $fn) {
            if (str_contains($fn['name'], $needle)) {
                return $fn['complexity'];
            }
        }
        $this->fail("Function '{$needle}' was not detected in: " . json_encode(array_column($result['functions'], 'name')));
    }

    public function testComplexityIgnoresDecisionKeywordsInsideStrings(): void
    {
        // A method whose body is nothing but STRING literals full of decision
        // tokens must report complexity 1 — the strings have zero real branches.
        $source = <<<'PHP'
<?php
class StringHeavy
{
    public function noBranches(): string
    {
        $a = "if ($x && $y || $z) { return $q ? 1 : 2; } foreach while case catch ??";
        $b = 'elseif for while && || ?? ? :';
        $heredoc = <<<TXT
        if while for && || foreach case ? :
        TXT;
        return $a . $b . $heredoc;
    }
}
PHP;
        $this->assertSame(1, $this->complexityOf($source, 'noBranches'));
    }

    public function testComplexityIgnoresDecisionKeywordsInsideComments(): void
    {
        // A method whose only "branches" live in // # and /* */ comments must
        // report complexity 1.
        $source = <<<'PHP'
<?php
class CommentHeavy
{
    public function noBranches(int $value): int
    {
        // if ($value && $value) for while case catch ? :
        # elseif || && ?? ternary ? : here too
        /* if for foreach while && || ?? case ? : block comment */
        return $value;
    }
}
PHP;
        $this->assertSame(1, $this->complexityOf($source, 'noBranches'));
    }

    public function testComplexityCountsRealBranchesOnly(): void
    {
        // Mixed: real branches PLUS a string/comment full of decoy decision
        // tokens. Only the REAL branches count.
        //   base 1 + if + elseif + && + || + foreach + while + ternary(?) = 8
        $source = <<<'PHP'
<?php
class Mixed
{
    public function classify(int $x): string
    {
        // decoy: if for while && || ?? ? :
        $decoy = "if ($a && $b || $c) ? yes : no foreach while case catch ??";
        if ($x > 1 && $x < 10) {
            return "mid";
        } elseif ($x > 10 || $x < 0) {
            return "edge";
        }
        foreach ([1, 2] as $i) {
            $x += $i;
        }
        while ($x > 100) {
            $x -= 10;
        }
        return $x > 5 ? "high" : "low";
    }
}
PHP;
        $this->assertSame(8, $this->complexityOf($source, 'classify'));
    }

    public function testNullableTypeHintsDoNotInflateComplexity(): void
    {
        // Nullable type hints (?int $a, ?string $b, : ?bool) and a nullable-typed
        // closure inside the body are NOT ternaries — complexity stays 1.
        $source = <<<'PHP'
<?php
class Nullable
{
    public function typed(?int $a, ?string $b): ?bool
    {
        $closure = function (?int $z): ?int {
            return $z;
        };
        return null;
    }
}
PHP;
        $this->assertSame(1, $this->complexityOf($source, 'typed'));
    }

    public function testGenuinelyBranchyMethodStillReportsHighComplexity(): void
    {
        // No real complexity is lost — a deeply branchy method scores high.
        $source = <<<'PHP'
<?php
class Branchy
{
    public function grade(int $s): string
    {
        if ($s >= 90 && $s <= 100) {
            return "A";
        } elseif ($s >= 80 || $s === 79) {
            return "B";
        } elseif ($s >= 70) {
            return "C";
        } elseif ($s >= 60) {
            return "D";
        }
        foreach (range(0, $s) as $i) {
            if ($i % 2 === 0 && $i > 10) {
                $s++;
            }
        }
        return $s > 50 ? "pass" : "fail";
    }
}
PHP;
        $this->assertGreaterThanOrEqual(11, $this->complexityOf($source, 'grade'));
    }

    // ── Function extraction precision (lock-in) ────────────────────

    public function testStringContainingCallSyntaxIsNotExtractedAsFunction(): void
    {
        // A string that contains `something(...)` (call-shaped text) must NOT be
        // counted as a defined function. Only `function name(...)` declarations
        // are real. The file defines exactly one function: realMethod.
        $source = <<<'PHP'
<?php
class Decoy
{
    public function realMethod(): string
    {
        $sql = "select something(col) from t where flag(1)";
        $code = 'name() and if() and for() and other(stuff)';
        $this->helper->call();
        return $sql . $code;
    }
}
PHP;
        $filePath = $this->tempDir . '/Decoy.php';
        file_put_contents($filePath, $source);
        $result = Metrics::fileDetail($filePath);

        $names = array_column($result['functions'], 'name');
        $this->assertCount(1, $result['functions'], 'Only the real declaration counts: ' . json_encode($names));
        $this->assertStringContainsString('realMethod', $names[0]);

        // None of the call-shaped strings leaked in as functions.
        foreach (['something', 'flag', 'name', 'if', 'for', 'other', 'call'] as $decoy) {
            foreach ($names as $name) {
                $this->assertStringNotContainsString(".{$decoy}", $name);
                $this->assertNotSame($decoy, $name);
            }
        }
    }

    public function testReferenceReturnMethodIsStillDetected(): void
    {
        // `function &name()` is a real declaration — its branches must not be
        // lost just because of the reference-return ampersand.
        $source = <<<'PHP'
<?php
class RefReturn
{
    public function &pick(int $x): array
    {
        static $store = [];
        if ($x > 0 && $x < 10) {
            $store[] = $x;
        }
        return $store;
    }
}
PHP;
        $filePath = $this->tempDir . '/RefReturn.php';
        file_put_contents($filePath, $source);
        $result = Metrics::fileDetail($filePath);

        $names = array_column($result['functions'], 'name');
        $found = false;
        foreach ($result['functions'] as $fn) {
            if (str_contains($fn['name'], 'pick')) {
                $found = true;
                // base 1 + if + && = 3
                $this->assertSame(3, $fn['complexity']);
            }
        }
        $this->assertTrue($found, 'reference-return method not detected: ' . json_encode($names));
    }
}
