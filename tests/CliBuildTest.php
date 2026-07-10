<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Real tests for the corrected `build` CLI command (Phase 3, PHP mirror of the
 * Python master's tests/test_cli_build.py).
 *
 * `build` produces the deployable Docker image (the artifact a Tina4 app ships),
 * not a library package. NO mocks — these drive the REAL `bin/tina4php` as a
 * subprocess:
 *
 *   * the fail-loud guards run against a real filesystem and a real, genuinely
 *     empty PATH (so the CLI's `which docker` really finds nothing);
 *   * when a real Docker daemon is available, a real `docker build` of a
 *     network-free `FROM scratch` image is run and the resulting image is
 *     inspected in the daemon, then cleaned up. Skipped (loudly) only when no
 *     docker daemon is reachable.
 */

use PHPUnit\Framework\TestCase;

class CliBuildTest extends TestCase
{
    private static string $bin;
    private string $dir; // temp project dir (the subprocess cwd)

    public static function setUpBeforeClass(): void
    {
        self::$bin = realpath(__DIR__ . '/../bin/tina4php');
        self::assertNotFalse(self::$bin, 'bin/tina4php not found');
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tina4-clibuild-' . getmypid() . '-' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->dir), ['.', '..']) as $entry) {
            @unlink($this->dir . '/' . $entry);
        }
        @rmdir($this->dir);
    }

    /** True only when a real docker daemon is reachable. */
    private static function dockerReady(): bool
    {
        $which = trim((string)@shell_exec('command -v docker 2>/dev/null'));
        if ($which === '') {
            return false;
        }
        @exec('docker info >/dev/null 2>&1', $_, $code);
        return $code === 0;
    }

    /**
     * Run the REAL build command in the temp project dir. $emptyPath makes the
     * subprocess PATH contain only the dir holding the php binary (so `docker`
     * is genuinely absent) — no mock of `which`.
     */
    private function runBuild(array $args, bool $emptyPath = false): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::$bin) . ' build';
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $env = getenv();
        if ($emptyPath) {
            // Keep only the php binary's own dir on PATH: docker is truly gone,
            // but the interpreter still launches (invoked by absolute path anyway).
            $env['PATH'] = dirname(PHP_BINARY);
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, $this->dir, $env);
        self::assertIsResource($process, 'failed to start bin/tina4php build');

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$out, $err, $exit];
    }

    // ── fail-loud guards ───────────────────────────────────────────────────

    public function testNoDockerfileFailsLoud(): void
    {
        [$out, , $exit] = $this->runBuild([]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No Dockerfile', $out);
        $this->assertStringContainsString('tina4 deploy docker', $out); // actionable guidance
    }

    public function testDockerfilePresentButDockerAbsentFailsLoud(): void
    {
        file_put_contents($this->dir . '/Dockerfile', "FROM scratch\n");
        // Real 'docker missing': an emptied PATH makes the CLI's `which docker`
        // return nothing. No mock — the environment really has no docker on PATH.
        [$out, , $exit] = $this->runBuild([], emptyPath: true);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('docker', strtolower($out));
    }

    public function testCustomFileFlagMissingFailsLoud(): void
    {
        [$out, , $exit] = $this->runBuild(['--file', 'docker/prod/Dockerfile']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('docker/prod/Dockerfile', $out);
    }

    // ── real image build ─────────────────────────────────────────────────

    public function testBuildsScratchImageForReal(): void
    {
        if (!self::dockerReady()) {
            $this->markTestSkipped('docker daemon not available (skipping REAL build; guards still covered)');
        }

        // FROM scratch needs no registry pull -> a real, offline docker build.
        file_put_contents($this->dir . '/Dockerfile', "FROM scratch\n");
        $tag = 'tina4-php-build-test:latest';
        try {
            [$out, , $exit] = $this->runBuild(['--tag', $tag]);
            $this->assertSame(0, $exit, "build failed:\n{$out}");
            $this->assertStringContainsString("Built image {$tag}", $out);
            $this->assertStringContainsString('docker run -p 7145:7145', $out); // PHP dev-server port

            // The real artifact exists in the docker daemon.
            @exec('docker image inspect ' . escapeshellarg($tag) . ' >/dev/null 2>&1', $_, $code);
            $this->assertSame(0, $code, 'built image not found in the docker daemon');
        } finally {
            @exec('docker image rm -f ' . escapeshellarg($tag) . ' >/dev/null 2>&1');
        }
    }
}
