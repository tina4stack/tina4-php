<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Feature 8 (health check endpoint) — characterisation of the WIRE contract.
 *
 * This suite carries the SAME case names as its three siblings:
 *   tina4-python/tests/test_health_characterisation.py
 *   tina4-ruby/spec/health_characterisation_spec.rb
 *   tina4-nodejs/test/healthCharacterisation.test.ts
 * so the four can be compared line by line. See
 * tina4-documentation/plan/v3/features/008-health-check.md for the measurements.
 *
 * Health is consumed by something EXTERNAL — a Kubernetes httpGet probe, a load
 * balancer, an uptime monitor — so the JSON shape, the path and the status code
 * are the contract. They are asserted here over a REAL socket against a REAL
 * `php -S` process running the real Tina4 pipeline. No mock, no in-process
 * shortcut: a health endpoint that only works when called as a PHP method is not
 * a health endpoint.
 */

use PHPUnit\Framework\TestCase;

class HealthCharacterisationTest extends TestCase
{
    /** @var resource|null */
    private $proc = null;
    /** @var array<int,resource> */
    private array $pipes = [];
    private int $port = 0;
    private string $router = '';
    private string $projectDir = '';

    /**
     * Boot a real `php -S` running a real Tina4 App, optionally with
     * TINA4_HEALTH_PATH set, and wait until it accepts connections.
     */
    private function boot(?string $healthPath = null): void
    {
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        if ($autoload === false) {
            $this->markTestSkipped('vendor/autoload.php not found');
        }

        $this->projectDir = sys_get_temp_dir() . '/tina4_health_char_' . getmypid() . '_' . uniqid();
        mkdir($this->projectDir, 0755, true);

        $healthLine = $healthPath === null
            ? ''
            : "putenv('TINA4_HEALTH_PATH=" . $healthPath . "');";

        $this->router = $this->projectDir . '/router.php';
        file_put_contents($this->router, <<<PHP
        <?php
        require '{$autoload}';
        putenv('TINA4_SUPPRESS=true');
        putenv('TINA4_DEBUG=false');
        putenv('TINA4_AUTO_MIGRATE=false');
        {$healthLine}
        \$app = new \\Tina4\\App(basePath: '{$this->projectDir}');
        \$app->handle();
        PHP);

        // A port derived from the pid keeps parallel PHPUnit runs off each other;
        // the uniqid-suffixed project dir keeps their data separate.
        $this->port = 7900 + (getmypid() % 400);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $this->proc = @proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", $this->router],
            $descriptors,
            $this->pipes
        );
        if (!is_resource($this->proc)) {
            $this->cleanup();
            $this->markTestSkipped('could not start php -S');
        }

        $up = false;
        for ($i = 0; $i < 100; $i++) {
            $c = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $e1, $e2, 0.1);
            if ($c) {
                fclose($c);
                $up = true;
                break;
            }
            usleep(50000);
        }
        if (!$up) {
            $this->cleanup();
            $this->markTestSkipped("dev server did not come up on :{$this->port}");
        }
    }

    /**
     * A real HTTP GET over a real socket.
     *
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    private function get(string $path): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);
        $body = @file_get_contents("http://127.0.0.1:{$this->port}{$path}", false, $context);
        $status = 0;
        $headers = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body === false ? '' : $body];
    }

    /** @return array<string,mixed> */
    private function json(string $path): array
    {
        $res = $this->get($path);
        $decoded = json_decode($res['body'], true);
        $this->assertIsArray($decoded, "response body at {$path} was not a JSON object: {$res['body']}");
        return $decoded;
    }

    private function cleanup(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
            $this->proc = null;
        }
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];
        if ($this->router !== '' && file_exists($this->router)) {
            @unlink($this->router);
        }
        if ($this->projectDir !== '' && is_dir($this->projectDir)) {
            $this->rmrf($this->projectDir);
        }
    }

    private function rmrf(string $dir): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            is_dir($full) ? $this->rmrf($full) : @unlink($full);
        }
        @rmdir($dir);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    // ── The contract ────────────────────────────────────────────────────

    public function testTheDefaultHealthPathServes200(): void
    {
        $this->boot();
        $this->assertSame(200, $this->get('/__health')['status']);
    }

    public function testTheLegacySlashHealthPathServes200(): void
    {
        $this->boot();
        $this->assertSame(200, $this->get('/health')['status']);
    }

    public function testTheBodyReportsStatusOkWhenHealthy(): void
    {
        $this->boot();
        $this->assertSame('ok', $this->json('/health')['status']);
    }

    public function testTheBodyReportsTheFrameworkVersion(): void
    {
        $this->boot();
        $this->assertSame(\Tina4\App::$VERSION, $this->json('/health')['version']);
    }

    public function testTheBodyNamesTheFramework(): void
    {
        $this->boot();
        $this->assertSame('tina4-php', $this->json('/health')['framework']);
    }

    public function testTheBodyReportsUptimeInSeconds(): void
    {
        $this->boot();
        $body = $this->json('/health');
        $this->assertArrayHasKey('uptime', $body);
        $this->assertIsNumeric($body['uptime']);
        $this->assertGreaterThanOrEqual(0, $body['uptime']);
    }

    public function testTheContentTypeIsApplicationJson(): void
    {
        $this->boot();
        $this->assertStringContainsString('application/json', $this->get('/health')['headers']['content-type'] ?? '');
    }

    public function testACustomHealthPathFromTheEnvironmentServes200(): void
    {
        $this->boot('/healthz');
        $this->assertSame(200, $this->get('/healthz')['status']);
    }

    public function testTheLegacySlashHealthPathSurvivesACustomHealthPath(): void
    {
        $this->boot('/healthz');
        $this->assertSame(
            200,
            $this->get('/health')['status'],
            'setting TINA4_HEALTH_PATH must never unregister /health: a probe already '
            . 'pointed at /health would start failing on upgrade.'
        );
    }

    public function testTheResponseIsNotCacheable(): void
    {
        $this->boot();
        $cacheControl = strtolower($this->get('/health')['headers']['cache-control'] ?? '');
        $this->assertStringContainsString(
            'no-store',
            $cacheControl,
            'a cached health response lets a load balancer keep routing to a dead instance'
        );
    }
}
