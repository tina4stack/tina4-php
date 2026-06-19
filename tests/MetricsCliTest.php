<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * MetricsCli — lock-in tests for the precise test-detection fix, the
 * offenders() ranking, and the `tina4php metrics` CLI command.
 *
 * These are CONTRACT tests: each offender kind surfaces, ranking holds,
 * clean → none; the precise has_tests fix (no-dedicated-test file reports
 * UNTESTED, an imported file reports tested); the --json shape; and the
 * --fail-on exit codes (incl. a warn/info-only fixture).
 *
 * Mirrors the verified Python-master design (commits 1bc393c + a9ac81c).
 */

use PHPUnit\Framework\TestCase;
use Tina4\Metrics;

class MetricsCliTest extends TestCase
{
    private string $tempDir;

    /** Distinctive, repo-absent class names so the real tina4-php tests/ dir
     *  (always searched via CWD) can never accidentally match a fixture. */
    private const ZZ = 'ZzqOffender'; // prefix for fixture class names

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tina4-metrics-cli-' . getmypid() . '-' . uniqid();
        mkdir($this->tempDir . '/src', 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->rmdirRecursive($this->tempDir);
        }
    }

    private function rmdirRecursive(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function write(string $relPath, string $content): void
    {
        $path = $this->tempDir . '/' . $relPath;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
    }

    /** A function with cyclomatic complexity well above the error threshold (>20). */
    private function highComplexityFunction(string $name): string
    {
        $branches = '';
        for ($i = 0; $i < 25; $i++) {
            $branches .= "        if (\$x == {$i} && \$x != " . ($i + 100) . ") { return {$i}; }\n";
        }
        return "<?php\n\nclass {$name}\n{\n    public function compute(int \$x): int\n    {\n{$branches}        return -1;\n    }\n}\n";
    }

    // ── offenders(): each kind surfaces ────────────────────────────

    public function testOffendersComplexityKindSurfaces(): void
    {
        $this->write('src/' . self::ZZ . 'Complex.php', $this->highComplexityFunction(self::ZZ . 'Complex'));

        $result = Metrics::offenders($this->tempDir . '/src');
        $kinds = array_column($result['offenders'], 'kind');
        $this->assertContains('complexity', $kinds);

        // complexity > 20 must be severity error, score == complexity.
        $complexity = array_values(array_filter(
            $result['offenders'],
            fn($o) => $o['kind'] === 'complexity'
        ))[0];
        $this->assertSame('error', $complexity['severity']);
        $this->assertGreaterThan(20, $complexity['score']);
    }

    public function testOffendersLargeFileKindSurfaces(): void
    {
        // > 500 LOC of trivial statements (low complexity, just bulk).
        $body = str_repeat("\$" . "v = 1; echo \$v;\n", 600);
        $this->write('src/' . self::ZZ . 'Big.php', "<?php\n" . $body);

        $result = Metrics::offenders($this->tempDir . '/src');
        $kinds = array_column($result['offenders'], 'kind');
        $this->assertContains('large_file', $kinds);

        $large = array_values(array_filter(
            $result['offenders'],
            fn($o) => $o['kind'] === 'large_file'
        ))[0];
        $this->assertSame('warn', $large['severity']);
        $this->assertSame(1, $large['line']);
    }

    public function testOffendersTooManyFunctionsKindSurfaces(): void
    {
        $methods = '';
        for ($i = 0; $i < 25; $i++) {
            $methods .= "    public function m{$i}(): int { return {$i}; }\n";
        }
        $this->write('src/' . self::ZZ . 'Many.php', "<?php\n\nclass " . self::ZZ . "Many\n{\n{$methods}}\n");

        $result = Metrics::offenders($this->tempDir . '/src');
        $kinds = array_column($result['offenders'], 'kind');
        $this->assertContains('too_many_functions', $kinds);

        $tmf = array_values(array_filter(
            $result['offenders'],
            fn($o) => $o['kind'] === 'too_many_functions'
        ))[0];
        $this->assertSame('warn', $tmf['severity']);
    }

    public function testOffendersUntestedKindSurfaces(): void
    {
        // A small, simple, distinctive class with NO referencing test anywhere.
        $this->write('src/' . self::ZZ . 'Lonely.php',
            "<?php\n\nclass " . self::ZZ . "Lonely\n{\n    public function ping(): string { return 'pong'; }\n}\n");

        $result = Metrics::offenders($this->tempDir . '/src');
        $untested = array_values(array_filter(
            $result['offenders'],
            fn($o) => $o['kind'] === 'untested'
        ));
        $this->assertNotEmpty($untested, 'a file with no referencing test must surface as untested');
        $this->assertSame('info', $untested[0]['severity']);
        $this->assertSame('no referencing test', $untested[0]['detail']);
    }

    public function testOffendersLowMaintainabilityKindSurfaces(): void
    {
        // Big + branchy → maintainability index sinks below 40 (likely < 20 → error).
        $body = '';
        for ($i = 0; $i < 60; $i++) {
            $body .= "    public function f{$i}(int \$x): int\n    {\n";
            for ($j = 0; $j < 8; $j++) {
                $body .= "        if (\$x > {$j} && \$x < " . ($j + 50) . ") { \$x = \$x + {$j} * {$i}; }\n";
            }
            $body .= "        return \$x;\n    }\n";
        }
        $this->write('src/' . self::ZZ . 'Tangle.php', "<?php\n\nclass " . self::ZZ . "Tangle\n{\n{$body}}\n");

        $result = Metrics::offenders($this->tempDir . '/src', 200);
        $kinds = array_column($result['offenders'], 'kind');
        $this->assertContains('low_maintainability', $kinds);
    }

    // ── offenders(): ranking ───────────────────────────────────────

    public function testOffendersRankingErrorsBeforeWarnsBeforeInfo(): void
    {
        // One file with a high-complexity (error) method + an untested simple file (info).
        $this->write('src/' . self::ZZ . 'Hot.php', $this->highComplexityFunction(self::ZZ . 'Hot'));
        $this->write('src/' . self::ZZ . 'Cold.php',
            "<?php\n\nclass " . self::ZZ . "Cold\n{\n    public function ok(): int { return 1; }\n}\n");

        $result = Metrics::offenders($this->tempDir . '/src', 200);
        $rank = ['error' => 2, 'warn' => 1, 'info' => 0];

        $prev = PHP_INT_MAX;
        $prevScore = PHP_FLOAT_MAX;
        foreach ($result['offenders'] as $o) {
            $r = $rank[$o['severity']];
            $this->assertLessThanOrEqual($prev, $r, 'severity rank must be non-increasing');
            if ($r === $prev) {
                $this->assertLessThanOrEqual($prevScore, $o['score'], 'within a severity, score must be non-increasing');
            } else {
                $prevScore = PHP_FLOAT_MAX;
            }
            $prev = $r;
            $prevScore = $o['score'];
        }

        // The very first offender must be the error-severity complexity one.
        $this->assertSame('error', $result['offenders'][0]['severity']);
    }

    public function testOffendersCleanDirReturnsNone(): void
    {
        // A tiny, simple, well-named class that HAS a dedicated test file →
        // no offenders at all.
        $this->write('src/' . self::ZZ . 'Clean.php',
            "<?php\n\nclass " . self::ZZ . "Clean\n{\n    public function go(): int { return 1; }\n}\n");
        $this->write('tests/' . self::ZZ . 'CleanTest.php',
            "<?php\nclass " . self::ZZ . "CleanTest {}\n");

        $result = Metrics::offenders($this->tempDir . '/src');
        $this->assertSame([], $result['offenders']);
        $this->assertSame(0, $result['summary']['total_offenders']);
    }

    // ── offenders(): summary shape ─────────────────────────────────

    public function testOffendersSummaryHasExpectedKeys(): void
    {
        $this->write('src/' . self::ZZ . 'Sum.php',
            "<?php\n\nclass " . self::ZZ . "Sum\n{\n    public function a(): int { return 1; }\n}\n");

        $summary = Metrics::offenders($this->tempDir . '/src')['summary'];
        foreach ([
            'files_analyzed', 'total_functions', 'avg_complexity',
            'avg_maintainability', 'scan_mode', 'scan_root', 'total_offenders',
        ] as $key) {
            $this->assertArrayHasKey($key, $summary);
        }
    }

    public function testOffendersTopTruncates(): void
    {
        // Several high-complexity files; top=2 must return at most 2.
        for ($i = 0; $i < 5; $i++) {
            $this->write("src/" . self::ZZ . "Top{$i}.php", $this->highComplexityFunction(self::ZZ . "Top{$i}"));
        }
        $result = Metrics::offenders($this->tempDir . '/src', 2);
        $this->assertCount(2, $result['offenders']);
        // ...but the summary still counts the FULL set.
        $this->assertGreaterThan(2, $result['summary']['total_offenders']);
    }

    // ── PART A: precise test detection (the fix) ───────────────────

    /**
     * THE detection fix — negative side: a file with NO dedicated test file and
     * NO test that imports/references it reports UNTESTED, even though its
     * module-name word may appear in a test as plain prose.
     */
    public function testNoDedicatedTestFileReportsUntested(): void
    {
        // A "default adapter" style file. Module name is "ZzqOffenderpg" — its
        // module WORD appears in a test as prose, but the file's defined CLASS
        // token is never referenced and there is no dedicated/importing test.
        $module = self::ZZ . 'pg';
        $this->write('src/Database/' . $module . '.php',
            "<?php\n\nnamespace App\\Database;\n\nclass " . self::ZZ . "PgClass\n{\n    public function connect(): bool { return true; }\n}\n");

        // (a) A parent-directory blanket test (the OLD heuristic wrongly used
        //     this to cover everything under Database/), and
        // (b) a test that only MENTIONS the module name word as plain prose.
        // Neither names the file's namespaced class nor its defined-class token,
        // so under the PRECISE rule neither counts.
        $this->write('tests/DatabaseTest.php',
            "<?php\nclass DatabaseTest {\n"
            . "    // covers the Database directory generally; the {$module} module is mentioned only as a bare word here\n"
            . "    public function testStuff() { /* no import, no class reference */ }\n"
            . "}\n");

        $analysis = Metrics::fullAnalysis($this->tempDir . '/src');
        $byPath = [];
        foreach ($analysis['file_metrics'] as $fm) {
            $byPath[$fm['path']] = $fm['has_tests'];
        }
        $this->assertArrayHasKey('Database/' . $module . '.php', $byPath);
        $this->assertFalse(
            $byPath['Database/' . $module . '.php'],
            'a no-dedicated-test file with only a bare-word/parent-dir mention must be UNTESTED'
        );
    }

    /**
     * THE detection fix — positive side: a file a test really IMPORTS (use of
     * its namespaced class) reports tested.
     */
    public function testImportedFileReportsTested(): void
    {
        $this->write('src/Database/' . self::ZZ . 'Pg.php',
            "<?php\n\nnamespace App\\Database;\n\nclass " . self::ZZ . "Pg\n{\n    public function connect(): bool { return true; }\n}\n");

        // A test that genuinely imports the namespaced class.
        $this->write('tests/SomethingElseTest.php',
            "<?php\nuse App\\Database\\" . self::ZZ . "Pg;\n\nclass SomethingElseTest {\n    public function testIt() { \$a = new " . self::ZZ . "Pg(); }\n}\n");

        $analysis = Metrics::fullAnalysis($this->tempDir . '/src');
        $byPath = [];
        foreach ($analysis['file_metrics'] as $fm) {
            $byPath[$fm['path']] = $fm['has_tests'];
        }
        $this->assertTrue(
            $byPath['Database/' . self::ZZ . 'Pg.php'],
            'a file a test actually imports must report tested'
        );
    }

    /** A dedicated test file named for THIS module counts (filename rule). */
    public function testDedicatedTestFileReportsTested(): void
    {
        $this->write('src/' . self::ZZ . 'Widget.php',
            "<?php\n\nclass " . self::ZZ . "Widget\n{\n    public function go(): int { return 1; }\n}\n");
        $this->write('tests/' . self::ZZ . 'WidgetTest.php',
            "<?php\nclass " . self::ZZ . "WidgetTest {}\n");

        $analysis = Metrics::fullAnalysis($this->tempDir . '/src');
        $byPath = [];
        foreach ($analysis['file_metrics'] as $fm) {
            $byPath[$fm['path']] = $fm['has_tests'];
        }
        $this->assertTrue($byPath[self::ZZ . 'Widget.php']);
    }

    /**
     * Parent-directory blanket test must NOT mark siblings tested (the precise
     * rule drops the old parent-dir match): one FooTest.php under tests/ that
     * names the parent dir must not cover an unrelated sibling file.
     */
    public function testParentDirTestDoesNotCoverSibling(): void
    {
        // Two files under the same dir; only ONE has a dedicated/importing test.
        $this->write('src/Service/' . self::ZZ . 'Covered.php',
            "<?php\n\nnamespace App\\Service;\n\nclass " . self::ZZ . "Covered\n{\n    public function a(): int { return 1; }\n}\n");
        $this->write('src/Service/' . self::ZZ . 'Orphan.php',
            "<?php\n\nnamespace App\\Service;\n\nclass " . self::ZZ . "Orphan\n{\n    public function b(): int { return 2; }\n}\n");

        // Dedicated test for Covered only.
        $this->write('tests/' . self::ZZ . 'CoveredTest.php',
            "<?php\nuse App\\Service\\" . self::ZZ . "Covered;\nclass " . self::ZZ . "CoveredTest { public function t(){ new " . self::ZZ . "Covered(); } }\n");

        $analysis = Metrics::fullAnalysis($this->tempDir . '/src');
        $byPath = [];
        foreach ($analysis['file_metrics'] as $fm) {
            $byPath[$fm['path']] = $fm['has_tests'];
        }
        $this->assertTrue($byPath['Service/' . self::ZZ . 'Covered.php']);
        $this->assertFalse(
            $byPath['Service/' . self::ZZ . 'Orphan.php'],
            'a sibling with no dedicated/importing test must NOT be marked tested by a parent-dir match'
        );
    }

    // ── PART B: the `metrics` CLI command ──────────────────────────

    private string $cliPath;

    private function runCli(array $args): array
    {
        $this->cliPath = realpath(__DIR__ . '/../bin/tina4php');
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->cliPath) . ' metrics';
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $cmd .= ' 2>&1';
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        return ['out' => implode("\n", $output), 'code' => $exitCode];
    }

    public function testCliJsonShape(): void
    {
        $this->write('src/' . self::ZZ . 'CliJson.php', $this->highComplexityFunction(self::ZZ . 'CliJson'));

        $res = $this->runCli(['--json', '--path', $this->tempDir . '/src']);
        $this->assertSame(0, $res['code'], $res['out']);

        $data = json_decode($res['out'], true);
        $this->assertIsArray($data, "expected pure JSON, got:\n" . $res['out']);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('offenders', $data);

        // Summary keys.
        foreach ([
            'files_analyzed', 'total_functions', 'avg_complexity',
            'avg_maintainability', 'scan_mode', 'scan_root', 'total_offenders',
        ] as $key) {
            $this->assertArrayHasKey($key, $data['summary']);
        }

        // Each offender carries the full contract shape.
        $this->assertNotEmpty($data['offenders']);
        foreach ($data['offenders'] as $o) {
            foreach (['file', 'line', 'kind', 'severity', 'score', 'detail'] as $key) {
                $this->assertArrayHasKey($key, $o);
            }
        }
    }

    public function testCliHumanOutputAndCleanLine(): void
    {
        // Clean fixture: simple class + dedicated test → friendly clean line.
        $this->write('src/' . self::ZZ . 'CliClean.php',
            "<?php\n\nclass " . self::ZZ . "CliClean\n{\n    public function go(): int { return 1; }\n}\n");
        $this->write('tests/' . self::ZZ . 'CliCleanTest.php',
            "<?php\nclass " . self::ZZ . "CliCleanTest {}\n");

        $res = $this->runCli(['--path', $this->tempDir . '/src']);
        $this->assertSame(0, $res['code'], $res['out']);
        $this->assertStringContainsString('Tina4 Metrics', $res['out']);
        $this->assertStringContainsString('no offenders', $res['out']);
    }

    public function testCliHumanTableHasRankedRows(): void
    {
        $this->write('src/' . self::ZZ . 'CliTable.php', $this->highComplexityFunction(self::ZZ . 'CliTable'));

        $res = $this->runCli(['--path', $this->tempDir . '/src', '--top', '5']);
        $this->assertSame(0, $res['code'], $res['out']);
        $this->assertStringContainsString('SEVERITY', $res['out']);
        $this->assertStringContainsString('KIND', $res['out']);
        $this->assertStringContainsString('complexity', $res['out']);
    }

    public function testCliFailOnErrorExitsOneWhenErrorPresent(): void
    {
        $this->write('src/' . self::ZZ . 'CliErr.php', $this->highComplexityFunction(self::ZZ . 'CliErr'));

        $res = $this->runCli(['--json', '--fail-on', 'error', '--path', $this->tempDir . '/src']);
        $this->assertSame(1, $res['code'], $res['out']);
    }

    public function testCliFailOnWarnExitsOneWhenWarnPresent(): void
    {
        $this->write('src/' . self::ZZ . 'CliWarn.php', $this->highComplexityFunction(self::ZZ . 'CliWarn'));

        $res = $this->runCli(['--json', '--fail-on', 'warn', '--path', $this->tempDir . '/src']);
        $this->assertSame(1, $res['code'], $res['out']);
    }

    public function testCliNoFailOnAlwaysExitsZero(): void
    {
        $this->write('src/' . self::ZZ . 'CliPlain.php', $this->highComplexityFunction(self::ZZ . 'CliPlain'));

        $res = $this->runCli(['--json', '--path', $this->tempDir . '/src']);
        $this->assertSame(0, $res['code'], $res['out']);
    }

    public function testCliInvalidFailOnExitsTwo(): void
    {
        $this->write('src/' . self::ZZ . 'CliBad.php',
            "<?php\n\nclass " . self::ZZ . "CliBad\n{\n    public function go(): int { return 1; }\n}\n");

        $res = $this->runCli(['--fail-on', 'bogus', '--path', $this->tempDir . '/src']);
        $this->assertSame(2, $res['code'], $res['out']);
        $this->assertStringContainsString('invalid --fail-on', $res['out']);
    }

    /**
     * Warn/info-only fixture: NO error-severity offenders. --fail-on error must
     * exit 0 (nothing at/above error), while --fail-on warn must exit 1.
     */
    public function testCliFailOnDistinguishesWarnFromError(): void
    {
        // A class with >20 trivial one-line methods = a WARN too_many_functions
        // (each method is simple → maintainability stays high, no error), plus
        // an untested (info) signal. No error-severity offender.
        $methods = '';
        for ($i = 0; $i < 25; $i++) {
            $methods .= "    public function m{$i}(): int { return {$i}; }\n";
        }
        $this->write('src/' . self::ZZ . 'WarnOnly.php',
            "<?php\n\nclass " . self::ZZ . "WarnOnly\n{\n{$methods}}\n");

        // Sanity: confirm no error-severity offender in this fixture.
        $all = Metrics::offenders($this->tempDir . '/src', 500)['offenders'];
        $severities = array_column($all, 'severity');
        $this->assertNotContains('error', $severities, 'fixture must be warn/info-only');
        $this->assertContains('warn', $severities);

        $errRun = $this->runCli(['--json', '--fail-on', 'error', '--path', $this->tempDir . '/src']);
        $this->assertSame(0, $errRun['code'], 'no error-severity offender → --fail-on error exits 0');

        $warnRun = $this->runCli(['--json', '--fail-on', 'warn', '--path', $this->tempDir . '/src']);
        $this->assertSame(1, $warnRun['code'], 'a warn offender → --fail-on warn exits 1');
    }

    public function testCliTopDefaultsToTwenty(): void
    {
        // 25 distinct high-complexity files; default top must cap printed rows at 20.
        for ($i = 0; $i < 25; $i++) {
            $this->write("src/" . self::ZZ . "Cap{$i}.php", $this->highComplexityFunction(self::ZZ . "Cap{$i}"));
        }
        $res = $this->runCli(['--json', '--path', $this->tempDir . '/src']);
        $data = json_decode($res['out'], true);
        $this->assertLessThanOrEqual(20, count($data['offenders']));
        $this->assertGreaterThan(20, $data['summary']['total_offenders']);
    }
}
