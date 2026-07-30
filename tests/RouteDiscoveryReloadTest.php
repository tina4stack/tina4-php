<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Request;
use Tina4\Response;
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

    /**
     * The DevReload bug: editing an EXISTING convention route file used to keep
     * serving the stale handler because (a) the file was skipped as "seen" and
     * (b) even a re-register would have been shadowed by the first entry. After
     * the fix, a rescan re-imports the changed file and the route resolves to
     * the NEW handler — with exactly one route for that method+path.
     */
    public function testChangedConventionRouteReloadsToNewHandler(): void
    {
        $file = $this->writeRoute(
            'widgets/get.php',
            '<?php return function ($req, $res) { return $res->json(["v" => "old"]); };'
        );

        RouteDiscovery::scan($this->tempDir . '/src/routes');
        $this->assertSame('old', $this->dispatchJson('GET', '/widgets')['v'], 'First scan serves the original handler');

        // Edit the same file and bump its mtime so the same-second rewrite is
        // not silently treated as unchanged.
        file_put_contents($file, '<?php return function ($req, $res) { return $res->json(["v" => "new"]); };');
        touch($file, time() + 2);
        clearstatcache();

        RouteDiscovery::rescan();

        $this->assertSame('new', $this->dispatchJson('GET', '/widgets')['v'], 'rescan must serve the EDITED handler');
        $this->assertSame(1, $this->countRoutes('GET', '/widgets'), 'Re-loading must REPLACE the route in place, not append a duplicate');
    }

    /**
     * Router-level guarantee that backs the reload fix: registering the same
     * (method, path) twice replaces the entry rather than appending. Distinct
     * paths keep their own slots.
     */
    public function testReRegisteringSamePathReplacesInPlace(): void
    {
        Router::get('/dup', fn ($req, $res) => $res->json(['gen' => 1]));
        Router::get('/other', fn ($req, $res) => $res->json(['gen' => 0]));
        Router::get('/dup', fn ($req, $res) => $res->json(['gen' => 2]));

        $this->assertSame(1, $this->countRoutes('GET', '/dup'), 'Duplicate (method, path) must collapse to one entry');
        $this->assertSame(1, $this->countRoutes('GET', '/other'), 'Distinct path keeps its own slot');
        $this->assertSame(2, $this->dispatchJson('GET', '/dup')['gen'], 'Latest registration wins');
    }

    /**
     * Unchanged files must NOT be re-executed on a second pass. The route file
     * appends a line to a side-effect log every time it runs; after a no-change
     * rescan the log must still contain exactly one entry.
     */
    public function testUnchangedFileIsNotReExecuted(): void
    {
        $marker = $this->tempDir . '/runs.log';
        $body = '<?php file_put_contents(' . var_export($marker, true)
            . ', "x", FILE_APPEND); Tina4\\Router::get("/sticky", function ($req, $res) { return $res->json(["ok" => true]); });';
        $this->writeRoute('sticky.php', $body);

        RouteDiscovery::scan($this->tempDir . '/src/routes');
        $this->assertSame('x', file_get_contents($marker), 'File runs once on first scan');

        // No edit, no mtime bump — a rescan must skip it.
        clearstatcache();
        RouteDiscovery::rescan();

        $this->assertSame('x', file_get_contents($marker), 'Unchanged file must NOT be re-executed on rescan');
    }

    /**
     * A changed inline file made of pure Router::* calls (no top-level
     * function/class declarations) is safe to re-include, so its edited routes
     * hot-reload just like convention files.
     */
    public function testChangedSafeInlineFileReloads(): void
    {
        $file = $this->writeRoute(
            'inline.php',
            '<?php Tina4\\Router::get("/inline-route", function ($req, $res) { return $res->json(["v" => "old"]); });'
        );

        RouteDiscovery::scan($this->tempDir . '/src/routes');
        $this->assertSame('old', $this->dispatchJson('GET', '/inline-route')['v']);

        file_put_contents($file, '<?php Tina4\\Router::get("/inline-route", function ($req, $res) { return $res->json(["v" => "new"]); });');
        touch($file, time() + 2);
        clearstatcache();

        RouteDiscovery::rescan();

        $this->assertSame('new', $this->dispatchJson('GET', '/inline-route')['v'], 'Safe inline file must hot-reload edited routes');
        $this->assertSame(1, $this->countRoutes('GET', '/inline-route'), 'Re-loaded inline route must replace, not duplicate');
    }

    /**
     * An inline file that declares a top-level function cannot be re-included
     * (PHP would fatal with "cannot redeclare"). The reload path must detect
     * this and skip re-execution gracefully — no crash — even though that
     * file's routes won't hot-reload (documented caveat).
     */
    public function testChangedInlineFileWithTopLevelFunctionDoesNotCrash(): void
    {
        $file = $this->writeRoute(
            'declares.php',
            '<?php function tina4_reload_helper_fn() { return 1; } '
            . 'Tina4\\Router::get("/declares", function ($req, $res) { return $res->json(["v" => "old"]); });'
        );

        RouteDiscovery::scan($this->tempDir . '/src/routes');
        $this->assertSame('old', $this->dispatchJson('GET', '/declares')['v']);

        // Change it and rescan — re-including would redeclare the function and
        // fatal. The guard must skip it without throwing.
        file_put_contents(
            $file,
            '<?php function tina4_reload_helper_fn() { return 2; } '
            . 'Tina4\\Router::get("/declares", function ($req, $res) { return $res->json(["v" => "new"]); });'
        );
        touch($file, time() + 2);
        clearstatcache();

        RouteDiscovery::rescan(); // must not throw

        // Route stays on the original handler — honest caveat, not a regression.
        $this->assertSame('old', $this->dispatchJson('GET', '/declares')['v'], 'Unsafe inline file is not re-executed; old handler persists until restart');
    }

    /**
     * End-to-end through the actual reload entry point used by the Rust CLI:
     * RouteDiscovery::rescan() (what POST /__dev/api/reload calls) picks up an
     * edit to an existing file.
     */
    public function testRescanEndToEndPicksUpEditedFile(): void
    {
        $file = $this->writeRoute(
            'e2e/get.php',
            '<?php return function ($req, $res) { return $res->json(["stage" => "before"]); };'
        );
        RouteDiscovery::scan($this->tempDir . '/src/routes');
        $this->assertSame('before', $this->dispatchJson('GET', '/e2e')['stage']);

        file_put_contents($file, '<?php return function ($req, $res) { return $res->json(["stage" => "after"]); };');
        touch($file, time() + 2);
        clearstatcache();

        $reloaded = RouteDiscovery::rescan();

        $this->assertNotEmpty($reloaded, 'rescan reports the reloaded file');
        $this->assertSame('after', $this->dispatchJson('GET', '/e2e')['stage'], 'Edited route is live after rescan');
    }

    /**
     * Dispatch a request through the Router and return the decoded JSON body —
     * exercises the real first-match path, proving the live handler is the new
     * one (not just that a duplicate was added somewhere in the table).
     */
    private function dispatchJson(string $method, string $path): array
    {
        $request = Request::create(method: $method, path: $path);
        $response = new Response(testing: true);
        return Router::dispatch($request, $response)->getJsonBody();
    }

    private function countRoutes(string $method, string $path): int
    {
        $count = 0;
        foreach (Router::getRoutes() as $route) {
            if ($route['method'] === $method && $route['path'] === $path) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Adding route files ONE AT A TIME, each followed by a rescan, must
     * register every one of them.
     *
     * Parity with tina4-python's
     * test_every_new_route_file_is_seen_by_a_later_discover. The Python
     * framework had a real intermittent bug here: importlib served a cached
     * directory listing, so a file created after a prior discover warmed that
     * cache was invisible to the importer and its route silently never
     * registered (~50% of the time). PHP's analogue is the per-request stat
     * cache, which RouteDiscovery::scan() already defeats with
     * clearstatcache(). This test proves that holds under repetition -- a
     * single add-then-rescan would pass half the time on a broken
     * implementation and hide the bug, which is exactly how it hid in Python.
     */
    public function testEveryNewRouteFileIsSeenByALaterRescan(): void
    {
        $newFiles = 12;

        // rescan() is a documented no-op until scan() has run once, so seed the
        // first scan the way a booting server would.
        $this->writeRoute('seed.php', '<?php Tina4\\Router::get("/seed", function ($req, $res) { return $res->json(["ok" => true]); });');
        RouteDiscovery::scan($this->tempDir . '/src/routes');
        $this->assertContains('/seed', array_map(static fn ($r) => $r['path'], Router::getRoutes()));

        for ($i = 0; $i < $newFiles; $i++) {
            $this->writeRoute("route{$i}.php", '<?php Tina4\\Router::get("/added-' . $i . '", function ($req, $res) { return $res->json(["ok" => true]); });');
            RouteDiscovery::rescan();

            $paths = array_map(static fn ($r) => $r['path'], Router::getRoutes());
            $this->assertContains(
                "/added-{$i}",
                $paths,
                "iteration {$i}: the new route file was written to disk but its route never registered"
            );
        }

        // Every route from every iteration is still registered at the end.
        $paths = array_map(static fn ($r) => $r['path'], Router::getRoutes());
        for ($i = 0; $i < $newFiles; $i++) {
            $this->assertContains("/added-{$i}", $paths, "route /added-{$i} was lost by a later rescan");
        }
    }
}
