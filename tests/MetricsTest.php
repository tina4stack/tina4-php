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
}
