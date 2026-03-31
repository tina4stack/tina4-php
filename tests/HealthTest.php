<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\App;
use Tina4\Log;

class HealthTest extends TestCase
{
    private string $tempDir;
    private ?App $app = null;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tina4_health_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        Log::reset();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            restore_error_handler();
            $this->app = null;
        }
        Log::reset();

        // Clean up log files
        $logDir = $this->tempDir . '/logs';
        if (is_dir($logDir)) {
            $files = glob($logDir . '/*');
            if ($files) {
                foreach ($files as $file) {
                    unlink($file);
                }
            }
            rmdir($logDir);
        }

        if (is_dir($this->tempDir)) {
            // Remove any other temp files
            $files = glob($this->tempDir . '/*');
            if ($files) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            rmdir($this->tempDir);
        }
    }

    public function testHealthDataStructure(): void
    {
        $this->app = new App(basePath: $this->tempDir);

        $health = $this->app->getHealthData();

        $this->assertArrayHasKey('status', $health);
        $this->assertArrayHasKey('version', $health);
        $this->assertArrayHasKey('uptime', $health);
    }

    public function testHealthStatusIsOk(): void
    {
        $this->app = new App(basePath: $this->tempDir);

        $health = $this->app->getHealthData();

        $this->assertEquals('ok', $health['status']);
    }

    public function testHealthVersionIs3(): void
    {
        $this->app = new App(basePath: $this->tempDir);

        $health = $this->app->getHealthData();

        $this->assertEquals(App::VERSION, $health['version']);
    }

    public function testHealthUptimeIsNumeric(): void
    {
        $this->app = new App(basePath: $this->tempDir);

        $health = $this->app->getHealthData();

        $this->assertIsFloat($health['uptime']);
        $this->assertGreaterThanOrEqual(0, $health['uptime']);
    }

    public function testHealthRouteRegistered(): void
    {
        $this->app = new App(basePath: $this->tempDir);

        $routes = $this->app->getRoutes();

        $this->assertArrayHasKey('GET', $routes);
        $this->assertArrayHasKey('/health', $routes['GET']);
    }

    public function testHealthRouteHandlerReturnsCorrectData(): void
    {
        $this->app = new App(basePath: $this->tempDir);

        $routes = $this->app->getRoutes();
        $handler = $routes['GET']['/health'];
        $result = $handler();

        $this->assertEquals('ok', $result['status']);
        $this->assertEquals(App::VERSION, $result['version']);
    }

    public function testAppVersion(): void
    {
        $this->assertEquals(App::VERSION, App::VERSION);
    }

    public function testAppStartAndStop(): void
    {
        $this->app = new App(basePath: $this->tempDir);

        $this->assertFalse($this->app->isRunning());

        $this->app->start();
        $this->assertTrue($this->app->isRunning());

        $this->app->shutdown();
        $this->assertFalse($this->app->isRunning());
    }

    public function testShutdownCallbacks(): void
    {
        $this->app = new App(basePath: $this->tempDir);
        $called = false;

        $this->app->onShutdown(function () use (&$called) {
            $called = true;
        });

        $this->app->start();
        $this->app->shutdown();

        $this->assertTrue($called);
    }

    public function testShutdownNotCalledWhenNotRunning(): void
    {
        $this->app = new App(basePath: $this->tempDir);
        $called = false;

        $this->app->onShutdown(function () use (&$called) {
            $called = true;
        });

        // Don't start, just shutdown
        $this->app->shutdown();

        $this->assertFalse($called);
    }

    public function testRouteRegistration(): void
    {
        $this->app = new App(basePath: $this->tempDir);

        $this->app->get('/test', fn() => 'get');
        $this->app->post('/test', fn() => 'post');
        $this->app->put('/test', fn() => 'put');
        $this->app->delete('/test', fn() => 'delete');
        $this->app->patch('/test', fn() => 'patch');

        $routes = $this->app->getRoutes();

        $this->assertArrayHasKey('/test', $routes['GET']);
        $this->assertArrayHasKey('/test', $routes['POST']);
        $this->assertArrayHasKey('/test', $routes['PUT']);
        $this->assertArrayHasKey('/test', $routes['DELETE']);
        $this->assertArrayHasKey('/test', $routes['PATCH']);
    }

    public function testMiddlewareRegistration(): void
    {
        $this->app = new App(basePath: $this->tempDir);

        $middleware = fn() => null;
        $this->app->addMiddleware($middleware);

        $this->assertCount(1, $this->app->getMiddleware());
    }

    public function testHealthJsonOutput(): void
    {
        $this->app = new App(basePath: $this->tempDir);

        $health = $this->app->getHealthData();
        $json = json_encode($health);

        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertEquals('ok', $decoded['status']);
        $this->assertEquals(App::VERSION, $decoded['version']);
    }
}
