<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Router;

/**
 * The dispatch pipeline's COMPLEXITY gate (feature 6, group B).
 *
 * Split out of DispatchPipelineTest, which keeps the assertions that need
 * nothing but the source: the stage lists, that every listed stage exists and
 * is private, and that no stage calls another.
 *
 * These two need the tina4 Rust CLI on PATH. Metrics is measured by the NATIVE
 * engine (ADR-0002) with no in-framework fallback, so CI cannot run them - the
 * workflow excludes this file by name, matching how tina4-ruby excludes
 * `**' + '/metrics*_spec.rb` and tina4-nodejs lists METRICS_FILES. The engine is
 * tested where it lives, tina4stack/tina4 src/metrics.rs, exercised by
 * `cargo test` in its own pipeline.
 *
 * Runs locally for anyone with the CLI installed, which is where a refactor
 * that regrows a god-function gets caught.
 *
 * @group metrics
 */
class MetricsDispatchPipelineTest extends TestCase
{
    /** @return array<int, string> Every stage, all four groups. */
    private function allStages(): array
    {
        return array_merge(
            Router::PROLOGUE_STAGES,
            Router::REQUEST_STAGES,
            Router::ROUTE_STAGES,
            Router::RESPONSE_STAGES
        );
    }

    // ── The complexity gate — the thing that keeps this fixed ────

    public function testNoDispatchStageExceedsComplexityTen(): void
    {
        $report = $this->metricsFor('Tina4/Router.php');
        $listed = $this->allStages();

        $over = array_filter(
            $report['offenders'],
            static function (array $o) use ($listed): bool {
                if ($o['kind'] !== 'complexity') {
                    return false;
                }
                foreach ($listed as $stage) {
                    if (str_contains($o['detail'], ".{$stage} ")) {
                        return true;
                    }
                }
                return false;
            }
        );

        $this->assertSame(
            [],
            array_values(array_map(static fn($o) => $o['detail'], $over)),
            'dispatch stages over the complexity ceiling'
        );
    }

    public function testTheGodFunctionDoesNotComeBack(): void
    {
        // dispatchInner was 73 and dispatch 24. The extraction is only real
        // while they stay small.
        $report = $this->metricsFor('Tina4/Router.php');
        $regrown = array_filter(
            $report['offenders'],
            static fn(array $o): bool => $o['kind'] === 'complexity'
                && preg_match('/\.(dispatch|dispatchInner) /', $o['detail']) === 1
        );

        $this->assertSame(
            [],
            array_values(array_map(static fn($o) => $o['detail'], $regrown)),
            'a dispatch god-function regrew'
        );
    }

    /**
     * Shell out to the SAME `tina4 metrics` the CI gate uses, so the ceiling
     * asserted here cannot drift from the one that gates a release.
     *
     * @return array{offenders: array<int, array{kind: string, detail: string}>}
     */
    private function metricsFor(string $relativePath): array
    {
        $cwd = dirname(__DIR__);
        $output = shell_exec(
            'cd ' . escapeshellarg($cwd) . ' && tina4 metrics --json --path '
            . escapeshellarg($relativePath) . ' 2>&1'
        );

        if (!is_string($output) || !str_starts_with(ltrim($output), '{')) {
            $this->fail(
                'tina4 metrics did not return JSON - the complexity gate cannot be '
                . 'asserted. Install the tina4 CLI. Got: ' . substr((string) $output, 0, 120)
            );
        }

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}
