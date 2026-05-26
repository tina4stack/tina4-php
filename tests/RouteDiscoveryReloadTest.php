<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\RouteDiscovery;
use Tina4\Router;

/**
 * Tests for the reload-aware route discovery — covers the developer pain
 * point where a fresh `tina4 init` + file added to src/routes/ did not
 * load until a server restart.
 */
class RouteDiscoveryReloadTest extends TestCase
{
    private string $tempDir;
    private string $originalCwd;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tina4_reload_discovery_' . uniqid();
        mkdir($this->tempDir . '/src/routes', 0755, true);
        $this->originalCwd = getcwd();
        chdir($this->tempDir);
        Router::clear();
        RouteDiscovery::reset();
    }

    protected function tearDown(): void
    {
        Router::clear();
        RouteDiscovery::reset();
        chdir($this->originalCwd);
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function writeRoute(string $relPath, string $body): string
    {
        $full = $this->tempDir . '/src/routes/' . ltrim($relPath, '/');
        $dir = dirname($full);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($full, $body);
        return $full;
    }

    public function testScanIsIdempotent(): void
    {
        $this->writeRoute('hello.php', '<?php Tina4\\Router::get("/idem-hello", function ($req, $res) { return $res->json(["ok" => true]); });');

        RouteDiscovery::scan($this->tempDir . '/src/routes');
        $first = count(Router::getRoutes());

        RouteDiscovery::scan($this->tempDir . '/src/routes');
        $second = count(Router::getRoutes());

        $this->assertSame($first, $second, 'Re-running scan must not double-register existing routes');
    }

    public function testRescanPicksUpNewFiles(): void
    {
        $this->writeRoute('first.php', '<?php Tina4\\Router::get("/first", function ($req, $res) { return $res->json(["ok" => true]); });');
        RouteDiscovery::scan($this->tempDir . '/src/routes');

        $beforeCount = count(Router::getRoutes());
        $beforePaths = array_map(static fn ($r) => $r['path'], Router::getRoutes());
        $this->assertContains('/first', $beforePaths, 'First scan must register the initial route');

        // Simulate a user dropping a new file after the server is already running.
        $this->writeRoute('second.php', '<?php Tina4\\Router::get("/second", function ($req, $res) { return $res->json(["ok" => true]); });');

        RouteDiscovery::rescan();

        $afterPaths = array_map(static fn ($r) => $r['path'], Router::getRoutes());
        $this->assertContains('/second', $afterPaths, 'rescan must register the newly-added route');
        $this->assertSame($beforeCount + 1, count(Router::getRoutes()), 'Router gets exactly one new route after the rescan');
    }

    public function testRescanIsNoopWhenScanNeverRan(): void
    {
        $new = RouteDiscovery::rescan();
        $this->assertSame([], $new, 'rescan with no prior scan() must return an empty list, not crash');
    }

    public function testBrokenRouteFileLeavesSentinel(): void
    {
        // Syntax error — PHP will raise ParseError at require time.
        $this->writeRoute('broken.php', '<?php Tina4\\Router::get("/will-not-load",');

        RouteDiscovery::scan($this->tempDir . '/src/routes');

        $brokenDir = $this->tempDir . '/data/.broken';
        $this->assertDirectoryExists($brokenDir, 'data/.broken must be created on a failed import');
        $sentinels = glob($brokenDir . '/discover_*.broken');
        $this->assertNotEmpty($sentinels, 'A .broken sentinel must be written for the failed file');

        $payload = file_get_contents($sentinels[0]);
        $this->assertStringContainsString('auto_discover_failure', $payload);
        $this->assertStringContainsString('broken.php', $payload);
    }
}
