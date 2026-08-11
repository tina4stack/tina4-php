<?php

/**
 * Feature 132 — inline testing conformance (INLINE-DEC-01 / INLINE-DEC-02).
 *
 * Shared contract: tina4-documentation/plan/v3/fixtures/inlinetesting_contract.json.
 *
 * Every case runs with NO MOCKS: it builds a real throwaway project, SPAWNS the
 * real `bin/tina4php test` CLI as a child process (cwd = the project), and
 * asserts the child's REAL exit code and the REAL filesystem side effects.
 *
 * Invariants proven here:
 *   A inline-cli-real-exit-code            — the CLI runs a decorated inline @tests
 *                                            function and exits 0 on pass / non-zero on fail.
 *   B inline-discovery-no-arbitrary-code   — discovery is confined to the tests dir and never
 *                                            eval's args, so a scanned source file's side
 *                                            effect never runs during discovery.
 *   C inline-assert-surfaces-do-not-collide — the descriptor surface exposes expect* and NOT
 *                                            the xUnit assert*; the names are distinct.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Testing;

class InlineTestingContractTest extends TestCase
{
    private string $bin;

    protected function setUp(): void
    {
        $this->bin = realpath(__DIR__ . '/../bin/tina4php');
        $this->assertNotFalse($this->bin, 'bin/tina4php must exist');
    }

    private function makeProject(string $inlineBody, ?string $sideEffectBody = null): string
    {
        $dir = sys_get_temp_dir() . '/tina4_inline_' . bin2hex(random_bytes(6));
        mkdir($dir . '/tests', 0777, true);
        mkdir($dir . '/src', 0777, true);
        file_put_contents($dir . '/tests/inline_math.php', $inlineBody);
        if ($sideEffectBody !== null) {
            file_put_contents($dir . '/src/side_effect.php', $sideEffectBody);
        }
        return $dir;
    }

    /** Spawn the REAL `php bin/tina4php test` in $dir; return [exitCode, output]. */
    private function runCli(string $dir): array
    {
        $cmd = sprintf(
            'cd %s && php %s test 2>&1',
            escapeshellarg($dir),
            escapeshellarg($this->bin)
        );
        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);
        return [$exit, implode("\n", $output)];
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private const PASSING_INLINE = <<<'PHP'
    <?php
    /**
     * @tests expectEqual([5, 3], 8)
     * @tests expectEqual([0, 0], 0)
     */
    function add(int $a, int $b): int { return $a + $b; }
    PHP;

    private const FAILING_INLINE = <<<'PHP'
    <?php
    /**
     * @tests expectEqual([5, 3], 999)
     */
    function add(int $a, int $b): int { return $a + $b; }
    PHP;

    // case: tina4 test exits zero when the inline test passes
    public function testTina4TestExitsZeroWhenTheInlineTestPasses(): void
    {
        $dir = $this->makeProject(self::PASSING_INLINE);
        try {
            [$exit, $out] = $this->runCli($dir);
            $this->assertSame(0, $exit, "tina4php test must exit 0 on a passing inline @tests; output:\n{$out}");
            $this->assertStringContainsString('add', $out, "the inline test never ran; output:\n{$out}");
        } finally {
            $this->rmrf($dir);
        }
    }

    // case: tina4 test exits non zero when the inline test fails
    public function testTina4TestExitsNonZeroWhenTheInlineTestFails(): void
    {
        $dir = $this->makeProject(self::FAILING_INLINE);
        try {
            [$exit, $out] = $this->runCli($dir);
            $this->assertNotSame(0, $exit, "tina4php test must exit non-zero on a failing inline @tests; output:\n{$out}");
        } finally {
            $this->rmrf($dir);
        }
    }

    // case: inline discovery does not run a non test file side effect
    public function testInlineDiscoveryDoesNotRunANonTestFileSideEffect(): void
    {
        // (a) A src/ file that LOOKS like an inline-test file (it carries a @tests
        //     docblock) but lives OUTSIDE the tests dir must NOT be require_once'd
        //     during discovery — the old blanket scan of src/ would run its
        //     top-level side effect; the fixed discovery (tests dir only) never does.
        $sentinelName = 'side_effect_ran_' . bin2hex(random_bytes(4)) . '.txt';
        $sideEffect = "<?php\n"
            . "file_put_contents(__DIR__ . '/../{$sentinelName}', 'ran');\n"
            . "/**\n * @tests expectEqual([1], 1)\n */\n"
            . "function src_side_effect_fn(\$x) { return \$x; }\n";
        $dir = $this->makeProject(self::PASSING_INLINE, $sideEffect);
        try {
            [$exit, $out] = $this->runCli($dir);
            $this->assertSame(0, $exit, "passing project should exit 0; output:\n{$out}");
            $this->assertFileDoesNotExist(
                $dir . '/' . $sentinelName,
                'a src/ file was require_once\'d during discovery — discovery must be confined to the tests dir'
            );
        } finally {
            $this->rmrf($dir);
        }

        // (b) The docblock arguments are parsed as LITERALS — a function-call arg is
        //     never eval'd, so its side effect never runs during discovery.
        $fixtures = sys_get_temp_dir() . '/tina4_inline_eval_' . bin2hex(random_bytes(6));
        mkdir($fixtures, 0777, true);
        $evalSentinel = $fixtures . '/eval_ran.txt';
        $body = "<?php\n"
            . "function tina4_inline_side_effect_marker() { file_put_contents('" . addslashes($evalSentinel) . "', 'ran'); return 0; }\n"
            . "/**\n * @tests expectEqual([tina4_inline_side_effect_marker()], 0)\n */\n"
            . "function tina4_inline_noop(\$x) { return \$x; }\n";
        file_put_contents($fixtures . '/eval_fixture.php', $body);
        try {
            Testing::reset();
            Testing::discover($fixtures);   // parse-only: the function-call arg is not a literal
            $this->assertFileDoesNotExist(
                $evalSentinel,
                'discovery eval\'d a docblock argument — args must be parsed as literals only'
            );
        } finally {
            $this->rmrf($fixtures);
        }
    }

    // case: the descriptor expect builders and xunit assert are distinct
    public function testTheDescriptorExpectBuildersAndXunitAssertAreDistinct(): void
    {
        // The descriptor surface builds a spec; it does not assert.
        $spec = Testing::expectEqual([1], 1);
        $this->assertIsArray($spec);
        $this->assertSame('equal', $spec['type']);

        // The colliding name is GONE from the descriptor surface — the point of the
        // rename. Re-adding an assertEqual descriptor here would fail this.
        $this->assertTrue(method_exists(Testing::class, 'expectEqual'));
        $this->assertFalse(
            method_exists(Testing::class, 'assertEqual'),
            'the descriptor surface must not expose assertEqual — it collides with the xUnit assertEquals'
        );

        // The xUnit immediate surface (PHPUnit) asserts directly — a different surface.
        $this->assertEquals(1, 1);   // immediate, throws on inequality
    }
}
