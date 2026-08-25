<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Real tests for Tina4\ImportHelper — the last-resort autoloader that catches
 * a missing Tina4\ class and throws \Error with a "did you mean" hint.
 *
 * NO mocks. Each case boots a REAL php subprocess (proc_open) with the REAL
 * composer autoloader wired, and asserts on the actual exit code / stderr /
 * stdout that a caller would see. In-process assertions would run in a
 * process where Tina4\Router (and the rest of the framework) has already been
 * autoloaded — the miss the helper exists to catch would never fire.
 */

use PHPUnit\Framework\TestCase;

class ImportHelperTest extends TestCase
{
    /** Absolute path to the framework's autoloader (the real composer one). */
    private static string $autoloadPath;

    /**
     * Files this test creates under Tina4/ to prove the masking-gate branch.
     * Reaped in tearDown so a mid-test failure never leaves a fixture behind.
     *
     * @var list<string>
     */
    private array $createdFixtures = [];

    public static function setUpBeforeClass(): void
    {
        $vendor = realpath(__DIR__ . '/../vendor/autoload.php');
        self::assertNotFalse($vendor, 'vendor/autoload.php not found — run composer install');
        self::$autoloadPath = $vendor;
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFixtures as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->createdFixtures = [];
    }

    /**
     * Boot php with the composer autoloader, run one -r snippet, capture
     * everything the caller would see. Silences the -c/-n ini noise the host
     * emits (grpc.so etc.) — that noise is unrelated to the helper.
     *
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function runPhp(string $snippet): array
    {
        $prelude = 'require ' . var_export(self::$autoloadPath, true) . ';';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // display_errors=stderr matches production ini shape (errors go to
        // STDERR only, never mirrored on STDOUT). E_WARNING is masked so a
        // host-side "Warning: PHP Startup: ..." (grpc.so on a dev Mac) does
        // not leak into the assertions; the framework itself never raises a
        // startup warning in this code path.
        $cmd = [
            PHP_BINARY,
            '-d', 'display_errors=stderr',
            '-d', 'display_startup_errors=0',
            '-d', 'error_reporting=' . (E_ALL & ~E_WARNING),
            '-r',
            $prelude . $snippet,
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        $this->assertIsResource($process, 'failed to start php subprocess');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit];
    }

    /**
     * 1. Positive-happy — a REAL class in the Tina4 namespace still autoloads.
     *
     * ImportHelper::install() registers with prepend=false, so composer's
     * PSR-4 loader still gets first crack at every class. A regression here
     * would mean the helper is stealing the load path.
     */
    public function testPositiveHappyRealClassLoads(): void
    {
        $result = $this->runPhp(
            'try { new \Tina4\Router(); echo "OK\n"; }'
            . ' catch (\Throwable $e) { fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n"); exit(1); }'
        );
        $this->assertSame(0, $result['exit'], "stderr:\n{$result['stderr']}");
        $this->assertStringContainsString('OK', $result['stdout']);
    }

    /**
     * 2. Negative-hint — a close-typo Tina4 class throws with the real name.
     *
     * `Tina4\Route` (missing the r) must throw \Error naming `Tina4\Router`.
     * The message is the whole point of the helper — its exact text is a
     * contract the AI-agent-experience improvements rely on.
     */
    public function testNegativeHintReferencesClosestRealClass(): void
    {
        $result = $this->runPhp(
            'try { new \Tina4\Route(); echo "no throw\n"; }'
            . ' catch (\Throwable $e) { fwrite(STDERR, get_class($e) . ": " . $e->getMessage() . "\n"); exit(2); }'
        );
        $this->assertSame(2, $result['exit'], "stdout:\n{$result['stdout']}\nstderr:\n{$result['stderr']}");
        $this->assertStringContainsString('Error:', $result['stderr']);
        $this->assertStringContainsString("Class 'Tina4\\Route' not found", $result['stderr']);
        $this->assertStringContainsString("Tina4\\Router", $result['stderr']);
    }

    /**
     * 3. Negative-no-match — a made-up name lists real classes as a fallback.
     *
     * `Tina4\Zzzzz` has no close match; the hint must NAME AT LEAST THREE real
     * Tina4 classes so the operator can browse rather than guess a second time.
     */
    public function testNegativeNoMatchListsRealClasses(): void
    {
        $result = $this->runPhp(
            'try { new \Tina4\Zzzzz(); echo "no throw\n"; }'
            . ' catch (\Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(3); }'
        );
        $this->assertSame(3, $result['exit'], "stderr:\n{$result['stderr']}");
        $this->assertStringContainsString("Class 'Tina4\\Zzzzz' not found", $result['stderr']);
        $this->assertStringContainsString('real classes include:', $result['stderr']);

        // Count real Tina4\ class names in the fallback message. The message
        // shape is stable ("real classes include: Tina4\A, Tina4\B, ..."):
        // matching Tina4\<Word> handles both the current top-5 and any
        // future ordering as long as the count stays >= 3.
        preg_match_all('/Tina4\\\\[A-Z][A-Za-z0-9]*/', $result['stderr'], $matches);
        $unique = array_unique($matches[0] ?? []);
        // Filter out the missing class itself from the count.
        $realNames = array_values(array_filter($unique, static fn(string $name) => $name !== 'Tina4\\Zzzzz'));
        $this->assertGreaterThanOrEqual(
            3,
            count($realNames),
            'expected at least 3 real Tina4 class names in the fallback, got: ' . implode(', ', $realNames)
        );
    }

    /**
     * 4. Masking gate — a genuine PSR-4 miss ELSEWHERE is not masked.
     *
     * A fixture file under Tina4/ that references a non-existent NON-Tina4\
     * class must produce the ORIGINAL "Class 'NonExistent\NotAClass' not
     * found" from PHP itself. ImportHelper is scoped to Tina4\, so it
     * SILENTLY returns for the miss on NotAClass — no hint, no wrapping.
     *
     * Uses `extends \NonExistent\NotAClass` so the miss fires at file
     * inclusion time (which is exactly the AI-agent failure mode this test
     * guards against — a broken `use` inside a real Tina4\ class).
     */
    public function testMaskingGateLeavesForeignMissAlone(): void
    {
        $fixture = __DIR__ . '/../Tina4/_BrokenFixture.php';
        $this->createdFixtures[] = $fixture;
        file_put_contents(
            $fixture,
            "<?php\nnamespace Tina4;\nclass _BrokenFixture extends \\NonExistent\\NotAClass {}\n"
        );
        $this->assertFileExists($fixture, 'fixture write failed');

        $result = $this->runPhp(
            'try { new \Tina4\_BrokenFixture(); echo "no throw\n"; }'
            . ' catch (\Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(4); }'
        );
        $this->assertSame(4, $result['exit'], "stdout:\n{$result['stdout']}\nstderr:\n{$result['stderr']}");
        $this->assertStringContainsString('NonExistent\\NotAClass', $result['stderr']);
        // The critical negative — ImportHelper's hint template MUST NOT appear:
        // no "Did you mean" and no "real classes include" for the inner miss.
        $this->assertStringNotContainsString('Did you mean', $result['stderr']);
        $this->assertStringNotContainsString('real classes include', $result['stderr']);
    }

    /**
     * 5. Idempotence — install() is safe to call more than once.
     *
     * A test harness / hot reload / a mistaken second require of Constants.php
     * must NOT leave two copies of the callback on the autoload stack.
     */
    public function testInstallIsIdempotent(): void
    {
        $result = $this->runPhp(
            '\Tina4\ImportHelper::install();'
            . '\Tina4\ImportHelper::install();'
            . '\Tina4\ImportHelper::install();'
            . 'echo \Tina4\ImportHelper::registrationCount();'
        );
        $this->assertSame(0, $result['exit'], "stderr:\n{$result['stderr']}");
        $this->assertSame('1', trim($result['stdout']));
    }
}
