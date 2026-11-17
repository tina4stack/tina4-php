<?php
/*
Copyright (c) 2026 Code Infinity
SPDX-License-Identifier: MPL-2.0
This Source Code Form is subject to the terms of the Mozilla Public
License, v. 2.0. If a copy of the MPL was not distributed with this
file, You can obtain one at https://mozilla.org/MPL/2.0/.
*/

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 *
 * The dev dashboard's memory figures are the PROCESS's memory, not PHP's heap.
 *
 * /__dev/api/system `memory_mb` and /__dev/api/status `memory_usage_mb` were
 * memory_get_usage(true), the Zend allocator's heap, which sits tens of MB
 * below the resident set the OS reports (it leaves out the binary, the loaded
 * extensions, opcache...). `peak_memory_mb` was the heap's peak, not the
 * process's. Parity with tina4-python#316 / tina4-nodejs#197: current = VmRSS,
 * peak = VmHWM where /proc exists, PHP's figures only as the labelled fallback.
 *
 * REAL dispatch, no doubles: every request goes through Router::dispatch(), the
 * framework's front controller, with the real /__dev gate (TINA4_DEBUG on +
 * loopback peer) in front of the handler. The witness is the kernel's own
 * VmRSS / VmHWM for this process, read independently from /proc/self/status,
 * while a real 150 MB string is held and then freed.
 */

require_once __DIR__ . '/../Tina4/DevAdmin.php';

use PHPUnit\Framework\TestCase;
use Tina4\DevAdmin;
use Tina4\ErrorTracker;
use Tina4\McpServer;
use Tina4\Middleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class DevAdminProcessMemoryTest extends TestCase
{
    private const KEYS = ['TINA4_DEBUG', 'TINA4_LOG_LEVEL'];
    private const BLOB_BYTES = 150 * 1024 * 1024;

    /** @var array<string,string|false> */
    private array $savedEnv = [];
    private string $savedMemoryLimit = '';

    protected function setUp(): void
    {
        Router::clear();
        Middleware::reset();
        McpServer::resetDefaultServer();
        foreach (self::KEYS as $k) {
            $this->savedEnv[$k] = getenv($k);
        }
        putenv('TINA4_DEBUG=true');
        $_ENV['TINA4_DEBUG'] = 'true';
        putenv('TINA4_LOG_LEVEL=NONE');
        $_ENV['TINA4_LOG_LEVEL'] = 'NONE';
        // Room for the real 150 MB allocation on a 128M default php.ini.
        $this->savedMemoryLimit = (string)ini_get('memory_limit');
        ini_set('memory_limit', '1024M');
        DevAdmin::register();
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->savedMemoryLimit);
        Router::clear();
        Middleware::reset();
        McpServer::resetDefaultServer();
        ErrorTracker::reset();
        \Tina4\Log::reset();
        foreach (self::KEYS as $k) {
            unset($_ENV[$k]);
            $saved = $this->savedEnv[$k] ?? false;
            $saved === false ? putenv($k) : putenv("{$k}={$saved}");
        }
    }

    /** @return array{0:int,1:mixed} status and decoded JSON body */
    private function get(string $path, string $remoteIp = '127.0.0.1'): array
    {
        $request = Request::create(method: 'GET', path: $path, headers: ['host' => 'localhost'], remoteIp: $remoteIp);
        $response = Router::dispatch($request, new Response(true));
        return [$response->getStatusCode(), json_decode($response->getBody(), true)];
    }

    /** The kernel's VmRSS / VmHWM for this process in MB, or null without /proc. */
    private static function kernelMb(string $key): ?float
    {
        $status = @file_get_contents('/proc/self/status');
        if (!is_string($status) || preg_match('/^' . $key . ':\s+(\d+)\s+kB/m', $status, $m) !== 1) {
            return null;
        }
        return (int)$m[1] / 1024;
    }

    /**
     * Tight on purpose: the gap being fixed (RSS - Zend heap) is ~20-40 MB, so a
     * loose tolerance would let the old heap figure pass. The endpoint and this
     * read happen microseconds apart in the same process.
     */
    private static function assertNearKernel(float $reported, float $kernel, string $what): void
    {
        self::assertEqualsWithDelta($kernel, $reported, 4 + 0.02 * $kernel,
            "{$what}: reported {$reported} MB, kernel {$kernel} MB");
    }

    public function testSystemAndStatusAnswerThroughTheRealGateWithTheDashboardFields(): void
    {
        [$status, $system] = $this->get('/__dev/api/system');
        $this->assertSame(200, $status);
        $this->assertIsFloat($system['memory_mb'] + 0.0);
        $this->assertGreaterThan(0, $system['memory_mb']);
        $this->assertContains($system['memory_source'], ['rss', 'php_heap']);

        [$status, $dash] = $this->get('/__dev/api/status');
        $this->assertSame(200, $status);
        $this->assertGreaterThan(0, $dash['memory_usage_mb']);
        $this->assertGreaterThanOrEqual($dash['memory_usage_mb'], $dash['peak_memory_mb']);
        $this->assertSame($system['memory_source'], $dash['memory_source']);
    }

    public function testANonLoopbackPeerGetsNoMemoryFigures(): void
    {
        foreach (['/__dev/api/system', '/__dev/api/status'] as $path) {
            [$status, $body] = $this->get($path, '203.0.113.9');
            $this->assertSame(403, $status, $path);
            $this->assertFalse(is_array($body) && (isset($body['memory_mb']) || isset($body['memory_usage_mb'])), $path);
        }
    }

    public function testWithoutProcTheFallbackIsPhpsOwnFigureAndSaysSo(): void
    {
        if (self::kernelMb('VmRSS') !== null) {
            // This host HAS /proc, so the fallback must NOT be used here.
            [, $system] = $this->get('/__dev/api/system');
            $this->assertSame('rss', $system['memory_source']);
            return;
        }
        [, $system] = $this->get('/__dev/api/system');
        [, $dash] = $this->get('/__dev/api/status');
        $this->assertSame('php_heap', $system['memory_source']);
        $this->assertSame('php_heap', $dash['memory_source']);
        $this->assertEqualsWithDelta(memory_get_usage(true) / 1048576, $system['memory_mb'], 2.0);
        $this->assertEqualsWithDelta(memory_get_peak_usage(true) / 1048576, $dash['peak_memory_mb'], 2.0);
    }

    public function testCurrentMemoryIsTheProcessRssAndPeakIsTheRealHighWaterMark(): void
    {
        if (self::kernelMb('VmRSS') === null) {
            // No /proc (Windows, macOS): covered by the fallback test above. The
            // RSS comparison runs on Linux, which is where CI runs.
            $this->assertSame('php_heap', $this->get('/__dev/api/system')[1]['memory_source']);
            return;
        }

        // Baseline: the reported figure is the kernel's RSS, not the Zend heap.
        [, $base] = $this->get('/__dev/api/system');
        $this->assertSame('rss', $base['memory_source']);
        self::assertNearKernel($base['memory_mb'], self::kernelMb('VmRSS'), 'baseline memory_mb');

        // Hold a real 150 MB string; every byte written, so every page is resident.
        $blob = str_repeat('x', self::BLOB_BYTES);
        [, $heldSystem] = $this->get('/__dev/api/system');
        $heldRss = self::kernelMb('VmRSS');
        [, $heldStatus] = $this->get('/__dev/api/status');
        $this->assertSame(self::BLOB_BYTES, strlen($blob));
        $this->assertGreaterThan(100, $heldRss - $base['memory_mb'], 'precondition: the string grew RSS');
        self::assertNearKernel($heldSystem['memory_mb'], $heldRss, 'held /system memory_mb');
        self::assertNearKernel($heldStatus['memory_usage_mb'], self::kernelMb('VmRSS'), 'held /status memory_usage_mb');

        // Free it. Zend returns a huge block to the OS straight away (munmap).
        $blob = null;
        unset($blob);
        gc_collect_cycles();
        [, $freedSystem] = $this->get('/__dev/api/system');
        $nowRss = self::kernelMb('VmRSS');
        [, $freedStatus] = $this->get('/__dev/api/status');
        $peak = self::kernelMb('VmHWM');

        $this->assertGreaterThan(100, $heldRss - $nowRss, 'precondition: freeing the string lowered RSS');
        self::assertNearKernel($freedSystem['memory_mb'], $nowRss, 'freed /system memory_mb');
        self::assertNearKernel($freedStatus['memory_usage_mb'], self::kernelMb('VmRSS'), 'freed /status memory_usage_mb');
        $this->assertGreaterThan(100, $heldSystem['memory_mb'] - $freedSystem['memory_mb'], 'memory_mb fell with the free');

        // Peak is the process's real high-water mark (VmHWM), still at the held
        // size, and 100+ MB above the current figure.
        self::assertNearKernel($freedStatus['peak_memory_mb'], $peak, 'peak_memory_mb');
        $this->assertGreaterThan(100, $freedStatus['peak_memory_mb'] - $freedStatus['memory_usage_mb'], 'peak is not the current figure');
    }
}
