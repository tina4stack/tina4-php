<?php

/**
 * Tina4 PHP — v3.13.14 request logging.
 *
 * The dev server printed the startup banner then went silent because
 * requests were never logged to stdout. v3.13.14 logs every request
 * through Tina4\Log (→ stdout) — on by default in dev, opt-in in prod
 * via TINA4_LOG_REQUESTS. RequestLogger's line now includes the status
 * code for parity with Python/Ruby/Node.
 *
 * Log::writeStdout uses fwrite(STDOUT) which PHPUnit can't capture, so
 * we assert via the log FILE (Log writes there too) and via reflection
 * on the gate.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Log;
use Tina4\Request;
use Tina4\Response;
use Tina4\Middleware\RequestLogger;
use Tina4\Router;

class RequestLoggingTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tina4_reqlog_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        // Pin dev (TINA4_DEBUG=true) so the log FILE is written and we can
        // assert the emitted line. Since v3.13.39 the file is dev-gated by
        // default (prod/containers are stdout-only); `development: true`
        // only selects human-readable format, not file output. The gate
        // tests below set TINA4_DEBUG to their own values per-case.
        $_ENV['TINA4_DEBUG'] = 'true';
        @putenv('TINA4_DEBUG=true');
        Log::reset();
        Log::configure(logDir: $this->tempDir, development: true);
        RequestLogger::reset();
    }

    protected function tearDown(): void
    {
        Log::reset();
        RequestLogger::reset();
        foreach (glob($this->tempDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tempDir);
        unset($_ENV['TINA4_LOG_REQUESTS'], $_ENV['TINA4_DEBUG']);
        @putenv('TINA4_LOG_REQUESTS');
        @putenv('TINA4_DEBUG');
    }

    public function testRequestLoggerLineIncludesStatusAndElapsed(): void
    {
        $request = new Request(method: 'GET', path: '/api/widgets');
        $response = new Response(testing: true);
        $response->status(200);

        RequestLogger::beforeLog($request, $response);
        RequestLogger::afterLog($request, $response);

        $content = file_get_contents($this->tempDir . '/tina4.log');
        // v3.13.14 format: METHOD PATH -> STATUS (Nms)
        $this->assertStringContainsString('GET /api/widgets -> 200 (', $content);
        $this->assertStringContainsString('ms)', $content);
    }

    public function testRequestLoggerStatusReflectsResponse(): void
    {
        $request = new Request(method: 'POST', path: '/api/orders');
        $response = new Response(testing: true);
        $response->status(201);

        RequestLogger::beforeLog($request, $response);
        RequestLogger::afterLog($request, $response);

        $content = file_get_contents($this->tempDir . '/tina4.log');
        $this->assertStringContainsString('POST /api/orders -> 201 (', $content);
    }

    // ── The TINA4_LOG_REQUESTS gate (Router::requestLoggingEnabled) ──────

    private function gateEnabled(): bool
    {
        $ref = new ReflectionMethod(Router::class, 'requestLoggingEnabled');
        return $ref->invoke(null);
    }

    public function testGateUnsetFollowsDebugOn(): void
    {
        @putenv('TINA4_LOG_REQUESTS');
        unset($_ENV['TINA4_LOG_REQUESTS']);
        $_ENV['TINA4_DEBUG'] = 'true';
        @putenv('TINA4_DEBUG=true');
        $this->assertTrue($this->gateEnabled());
    }

    public function testGateUnsetFollowsDebugOff(): void
    {
        @putenv('TINA4_LOG_REQUESTS');
        unset($_ENV['TINA4_LOG_REQUESTS']);
        $_ENV['TINA4_DEBUG'] = 'false';
        @putenv('TINA4_DEBUG=false');
        $this->assertFalse($this->gateEnabled());
    }

    public function testGateExplicitTrueOverridesProd(): void
    {
        $_ENV['TINA4_LOG_REQUESTS'] = 'true';
        @putenv('TINA4_LOG_REQUESTS=true');
        $_ENV['TINA4_DEBUG'] = 'false';
        @putenv('TINA4_DEBUG=false');
        $this->assertTrue($this->gateEnabled());
    }

    public function testGateExplicitFalseOverridesDev(): void
    {
        $_ENV['TINA4_LOG_REQUESTS'] = 'false';
        @putenv('TINA4_LOG_REQUESTS=false');
        $_ENV['TINA4_DEBUG'] = 'true';
        @putenv('TINA4_DEBUG=true');
        $this->assertFalse($this->gateEnabled());
    }
}
