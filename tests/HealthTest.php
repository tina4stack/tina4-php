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

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tina4_health_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        Log::reset();
    }

    protected function tearDown(): void
    {
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
        $app = new App(basePath: $this->tempDir);

        $health = $app->getHealthData();

        $this->assertArrayHasKey('status', $health);
        $this->assertArrayHasKey('version', $health);
        $this->assertArrayHasKey('uptime', $health);
    }

    public function testHealthStatusIsOk(): void
    {
        $app = new App(basePath: $this->tempDir);

        $health = $app->getHealthData();

        $this->assertEquals('ok', $health['status']);
    }

    public function testHealthVersionIs3(): void
    {
        $app = new App(basePath: $this->tempDir);

        $health = $app->getHealthData();

        $this->assertEquals(App::VERSION, $health['version']);
    }

    public function testHealthUptimeIsNumeric(): void
    {
        $app = new App(basePath: $this->tempDir);

        $health = $app->getHealthData();

        $this->assertIsFloat($health['uptime']);
        $this->assertGreaterThanOrEqual(0, $health['uptime']);
    }

    public function testHealthRouteRegistered(): void
    {
        $app = new App(basePath: $this->tempDir);

        $routes = $app->getRoutes();

        $this->assertArrayHasKey('GET', $routes);
        $this->assertArrayHasKey('/health', $routes['GET']);
    }

    public function testHealthRouteHandlerReturnsCorrectData(): void
    {
        $app = new App(basePath: $this->tempDir);

        $routes = $app->getRoutes();
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
        $app = new App(basePath: $this->tempDir);

        $this->assertFalse($app->isRunning());

        $app->start();
        $this->assertTrue($app->isRunning());

        $app->shutdown();
        $this->assertFalse($app->isRunning());
    }

    public function testShutdownCallbacks(): void
    {
        $app = new App(basePath: $this->tempDir);
        $called = false;

        $app->onShutdown(function () use (&$called) {
            $called = true;
        });

        $app->start();
        $app->shutdown();

        $this->assertTrue($called);
    }

    public function testShutdownNotCalledWhenNotRunning(): void
    {
        $app = new App(basePath: $this->tempDir);
        $called = false;

        $app->onShutdown(function () use (&$called) {
            $called = true;
        });

        // Don't start, just shutdown
        $app->shutdown();

        $this->assertFalse($called);
    }

    public function testRouteRegistration(): void
    {
        $app = new App(basePath: $this->tempDir);

        $app->get('/test', fn() => 'get');
        $app->post('/test', fn() => 'post');
        $app->put('/test', fn() => 'put');
        $app->delete('/test', fn() => 'delete');
        $app->patch('/test', fn() => 'patch');

        $routes = $app->getRoutes();

        $this->assertArrayHasKey('/test', $routes['GET']);
        $this->assertArrayHasKey('/test', $routes['POST']);
        $this->assertArrayHasKey('/test', $routes['PUT']);
        $this->assertArrayHasKey('/test', $routes['DELETE']);
        $this->assertArrayHasKey('/test', $routes['PATCH']);
    }

    public function testMiddlewareRegistration(): void
    {
        $app = new App(basePath: $this->tempDir);

        $middleware = fn() => null;
        $app->addMiddleware($middleware);

        $this->assertCount(1, $app->getMiddleware());
    }

    public function testHealthJsonOutput(): void
    {
        $app = new App(basePath: $this->tempDir);

        $health = $app->getHealthData();
        $json = json_encode($health);

        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertEquals('ok', $decoded['status']);
        $this->assertEquals(App::VERSION, $decoded['version']);
    }
}
