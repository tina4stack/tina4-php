<?php

/**
 * Lock-in: the shipped entry point must SERVE a request under a web SAPI.
 *
 * Regression this pins down (issue #180)
 * --------------------------------------
 * The repo ships `nginx.conf.example` (an nginx + php-fpm recipe) alongside a
 * root `index.php` that calls `$app->run()`. `run()` is the standalone-server
 * entry: it calls findAvailablePort() and `new Server(...)`. Under php-fpm --
 * where nothing defines TINA4_CLI_SERVE -- every request therefore tried to bind
 * a NEW socket instead of answering, so the documented deployment simply hung.
 * The SAPI-aware per-request entry is `$app->handle()`.
 *
 * App::run() now delegates to handle() whenever php_sapi_name() is not 'cli',
 * which fixes existing projects without anyone editing their index.php.
 *
 * This has to be an INTEGRATION test: the branch is chosen by php_sapi_name(),
 * which cannot be changed inside a running process. So it spawns a REAL
 * `php -S` (SAPI 'cli-server', a web SAPI) against a REAL generated project and
 * makes a REAL HTTP request. No doubles.
 */

declare(strict_types=1);

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;

class WebSapiEntryTest extends TestCase
{
    private string $projectDir = '';

    /** @var resource|null */
    private $process = null;

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if ($status['running'] ?? false) {
                // Kill the whole group: `php -S` is the child we spawned.
                @proc_terminate($this->process, 9);
            }
            @proc_close($this->process);
            $this->process = null;
        }
        if ($this->projectDir !== '' && is_dir($this->projectDir)) {
            $this->removeTree($this->projectDir);
        }
    }

    private function removeTree(string $dir): void
    {
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** Pick a port nothing is listening on. */
    private function freePort(): int
    {
        for ($port = 7960; $port < 8010; $port++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
            if ($probe === false) {
                return $port;
            }
            fclose($probe);
        }
        $this->markTestSkipped('no free port in 7960-8010 for the php -S probe');
    }

    /**
     * Generate a minimal REAL Tina4 project whose index.php calls run(),
     * exactly like the shipped scaffold does.
     */
    private function makeProject(): string
    {
        $base = sys_get_temp_dir() . '/tina4-websapi-' . bin2hex(random_bytes(6));
        mkdir($base . '/src/routes', 0777, true);

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        file_put_contents(
            $base . '/index.php',
            "<?php\nrequire " . var_export($autoload, true) . ";\n"
            . "\$app = new \\Tina4\\App(basePath: __DIR__);\n"
            . "\$app->run();\n"
        );

        // A route that reports the SAPI back, so the assertion proves the
        // request was handled under a WEB SAPI (not the CLI server path).
        file_put_contents(
            $base . '/src/routes/probe.php',
            "<?php\n"
            . "\\Tina4\\Router::get('/fpm-probe', function (\$request, \$response) {\n"
            . "    return \$response->json(['ok' => true, 'sapi' => php_sapi_name()]);\n"
            . "});\n"
        );

        file_put_contents($base . '/.env', "TINA4_DEBUG=false\nTINA4_OVERRIDE_CLIENT=true\n");

        return $base;
    }

    public function testShippedEntryServesARequestUnderAWebSapi(): void
    {
        $php = PHP_BINARY;
        if (!is_executable($php)) {
            $this->markTestSkipped('PHP_BINARY not executable; cannot spawn php -S');
        }

        $this->projectDir = $this->makeProject();
        $port = $this->freePort();

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $this->process = proc_open(
            [$php, '-S', "127.0.0.1:{$port}", 'index.php'],
            $descriptors,
            $pipes,
            $this->projectDir,
            ['TINA4_OVERRIDE_CLIENT' => 'true', 'TINA4_NO_BROWSER' => 'true', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin']
        );
        $this->assertIsResource($this->process, 'failed to spawn php -S');

        // Wait for the built-in server to bind (it is quick, but not instant).
        $body = false;
        for ($attempt = 0; $attempt < 40; $attempt++) {
            usleep(250_000);
            $body = @file_get_contents("http://127.0.0.1:{$port}/fpm-probe");
            if ($body !== false && $body !== '') {
                break;
            }
        }

        $this->assertNotFalse(
            $body,
            "the shipped index.php did not answer under php -S. Before the SAPI guard, "
            . "run() tried to bind its own socket per request and the deployment hung "
            . "(issue #180)."
        );

        $decoded = json_decode((string) $body, true);
        $this->assertIsArray($decoded, "expected JSON from the probe route, got: " . substr((string) $body, 0, 200));
        $this->assertTrue($decoded['ok'] ?? false, 'probe route did not report ok');

        // The load-bearing assertion: this really was a WEB SAPI, so run() took
        // the handle() branch rather than the standalone-server branch.
        $this->assertNotSame(
            'cli',
            $decoded['sapi'] ?? 'cli',
            'the request must have been served under a web SAPI, not the CLI path'
        );
    }
}
