<?php
/**
 * Regression: the metrics offenders list must NOT be capped to the top-15
 * most-complex functions.
 *
 * Latent bug (fixed): fullAnalysis() returned most_complex_functions =
 * array_slice($allFunctions, 0, 15) and offenders() sourced its complexity
 * offenders from that capped list, so the 16th+ function over the complexity
 * threshold was silently dropped from the offenders list, the total_offenders
 * count, AND the `--fail-on` gate — a genuinely too-complex function passed the
 * build gate. offenders() now reads the full `all_functions` list;
 * most_complex_functions stays capped at 15 for the display report.
 *
 * Mirrors tina4-python tests/test_metrics_offender_cap.py (Python master fee4385).
 * No mocks: writes real .php files and runs the real analyzer over them.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Metrics;

/**
 * @group metrics
 *
 * Metrics is measured by the NATIVE engine in the tina4 Rust CLI (ADR-0002),
 * with no in-framework fallback, so every case here needs `tina4` on PATH. CI
 * excludes this group (--exclude-group metrics) because the engine is tested
 * where it lives - tina4stack/tina4 src/metrics.rs, under `cargo test` in its
 * own pipeline. Run these locally with the CLI installed.
 */
final class MetricsOffenderCapTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tina4_metrics_cap_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Reap what you create — leave no temp files behind.
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /**
     * Build a function whose cyclomatic complexity is 1 + $decisions.
     * 22 independent `if`s => CC 23 (> 20 => "error" severity).
     */
    private function highCcFunction(string $name, int $decisions = 22): string
    {
        $body = '';
        for ($j = 0; $j < $decisions; $j++) {
            $body .= "    if (\$x === {$j}) { \$x = {$j}; }\n";
        }
        return "function {$name}(\$x = 0)\n{\n{$body}    return \$x;\n}\n";
    }

    private function writeModule(int $count): void
    {
        $src = "<?php\n";
        for ($i = 0; $i < $count; $i++) {
            $src .= $this->highCcFunction("fn{$i}");
        }
        file_put_contents($this->dir . '/bigmod.php', $src);
    }

    public function testOffendersNotCappedAtTop15ComplexFunctions(): void
    {
        $n = 18; // > 15, so the old array_slice(...,0,15) would drop fn15..fn17
        $this->writeModule($n);

        $result = Metrics::offenders($this->dir, 100);
        $complexity = array_values(array_filter(
            $result['offenders'],
            static fn($o) => $o['kind'] === 'complexity'
        ));

        $this->assertCount(
            $n,
            $complexity,
            "expected {$n} complexity offenders, got " . count($complexity) . ' — top-15 cap regression'
        );
        foreach ($complexity as $o) {
            $this->assertSame('error', $o['severity'], 'CC 23 > 20 must be error severity (reaches --fail-on error)');
        }
        $this->assertGreaterThanOrEqual($n, $result['summary']['total_offenders']);
    }

    public function testDisplayReportStillCapsMostComplexAt15(): void
    {
        $this->writeModule(18);
        $analysis = Metrics::fullAnalysis($this->dir);

        $this->assertCount(15, $analysis['most_complex_functions'], 'display cap must stay at 15');

        // fullAnalysis() no longer republishes the uncapped function list. The engine
        // ranks offenders itself and its own --fail-on gate reads that same ranking,
        // so the CLI and the dashboard cannot disagree about what counts as an
        // offender. total_offenders is the honest proof nothing was lost at the cap.
        $this->assertArrayNotHasKey('all_functions', $analysis, 'the engine owns the ranking now');
        $uncapped = Metrics::offenders($this->dir, PHP_INT_MAX);
        $this->assertGreaterThanOrEqual(18, $uncapped['summary']['total_offenders']);
        $this->assertGreaterThanOrEqual(18, count($uncapped['offenders']));
    }
}
