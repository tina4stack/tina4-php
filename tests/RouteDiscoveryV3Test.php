<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\RouteDiscovery;
use Tina4\Router;

class RouteDiscoveryV3Test extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tina4_route_discovery_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        Router::reset();
    }

    protected function tearDown(): void
    {
        Router::reset();
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // --- Directory to URL Path Conversion ---

    public function testSimplePath(): void
    {
        $this->assertSame('/api/users', RouteDiscovery::directoryToUrlPath('/api/users'));
    }

    public function testDynamicParam(): void
    {
        $this->assertSame('/api/users/{id}', RouteDiscovery::directoryToUrlPath('/api/users/[id]'));
    }

    public function testCatchAllParam(): void
    {
        $this->assertSame('/docs/{slug:.*}', RouteDiscovery::directoryToUrlPath('/docs/[...slug]'));
    }

    public function testMultipleDynamicParams(): void
    {
        $this->assertSame(
            '/orgs/{orgId}/users/{userId}',
            RouteDiscovery::directoryToUrlPath('/orgs/[orgId]/users/[userId]')
        );
    }

    public function testRootPath(): void
    {
        $this->assertSame('/', RouteDiscovery::directoryToUrlPath('/'));
    }

    public function testDotPath(): void
    {
        $this->assertSame('/', RouteDiscovery::directoryToUrlPath('/.'));
    }

    // --- File Scanning ---

    public function testScanEmptyDirectory(): void
    {
        $result = RouteDiscovery::scan($this->tempDir);
        $this->assertSame([], $result);
    }

    public function testScanNonexistentDirectory(): void
    {
        $result = RouteDiscovery::scan($this->tempDir . '/nonexistent');
        $this->assertSame([], $result);
    }

    public function testScanSimpleGetRoute(): void
    {
        // Create src/routes/hello/get.php
        $routeDir = $this->tempDir . '/hello';
        mkdir($routeDir, 0755, true);
        file_put_contents($routeDir . '/get.php', '<?php return function($req, $res) { return $res->json(["msg" => "hello"]); };');

        $result = RouteDiscovery::scan($this->tempDir);

        $this->assertCount(1, $result);
        $this->assertSame('GET', $result[0]['method']);
        $this->assertSame('/hello', $result[0]['path']);
    }

    public function testScanMultipleMethods(): void
    {
        $routeDir = $this->tempDir . '/users';
        mkdir($routeDir, 0755, true);
        file_put_contents($routeDir . '/get.php', '<?php return function() {};');
        file_put_contents($routeDir . '/post.php', '<?php return function() {};');

        $result = RouteDiscovery::scan($this->tempDir);

        $this->assertCount(2, $result);
        $methods = array_column($result, 'method');
        $this->assertContains('GET', $methods);
        $this->assertContains('POST', $methods);
    }

    public function testScanDynamicParamDirectory(): void
    {
        $routeDir = $this->tempDir . '/users/[id]';
        mkdir($routeDir, 0755, true);
        file_put_contents($routeDir . '/get.php', '<?php return function() {};');

        $result = RouteDiscovery::scan($this->tempDir);

        $this->assertCount(1, $result);
        $this->assertSame('/users/{id}', $result[0]['path']);
    }

    public function testScanNestedDirectories(): void
    {
        // api/v1/users/get.php
        $routeDir = $this->tempDir . '/api/v1/users';
        mkdir($routeDir, 0755, true);
        file_put_contents($routeDir . '/get.php', '<?php return function() {};');

        $result = RouteDiscovery::scan($this->tempDir);

        $this->assertCount(1, $result);
        $this->assertSame('/api/v1/users', $result[0]['path']);
    }

    public function testScanRegistersWithRouter(): void
    {
        $routeDir = $this->tempDir . '/status';
        mkdir($routeDir, 0755, true);
        file_put_contents($routeDir . '/get.php', '<?php return function($req, $res) { return $res->json(["ok" => true]); };');

        RouteDiscovery::scan($this->tempDir);

        $this->assertNotNull(Router::match('GET', '/status'));
    }

    public function testScanCatchAllDirectory(): void
    {
        $routeDir = $this->tempDir . '/docs/[...path]';
        mkdir($routeDir, 0755, true);
        file_put_contents($routeDir . '/get.php', '<?php return function() {};');

        $result = RouteDiscovery::scan($this->tempDir);

        $this->assertCount(1, $result);
        $this->assertSame('/docs/{path:.*}', $result[0]['path']);
    }

    public function testScanAnyRoute(): void
    {
        $routeDir = $this->tempDir . '/webhook';
        mkdir($routeDir, 0755, true);
        file_put_contents($routeDir . '/any.php', '<?php return function() {};');

        $result = RouteDiscovery::scan($this->tempDir);

        $this->assertCount(1, $result);
        $this->assertSame('ANY', $result[0]['method']);
    }

    public function testScanIgnoresNonRouteFiles(): void
    {
        $routeDir = $this->tempDir . '/users';
        mkdir($routeDir, 0755, true);
        file_put_contents($routeDir . '/get.php', '<?php return function() {};');
        file_put_contents($routeDir . '/helper.php', '<?php return "not a route";');
        file_put_contents($routeDir . '/readme.txt', 'not a php file');

        $result = RouteDiscovery::scan($this->tempDir);

        $this->assertCount(1, $result);
    }
}
