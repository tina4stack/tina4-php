<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Default-CSP warn-once — the visible half of the secure-by-default CSP.
 *
 * Issue tina4-nodejs#61. `default-src 'self'` stays the secure default, but when
 * TINA4_CSP is unset the framework says so ONCE per process, so a cross-origin app
 * (runtime inline styles, CDN fonts/scripts, a separate API/LiveKit WebSocket) is
 * not silently broken with the failure only visible in the browser at runtime.
 *
 * Driven through the REAL dispatcher (Router::dispatch) with real Request objects,
 * capturing the REAL log via the file sink (TINA4_LOG_OUTPUT=file). NO MOCKS.
 *
 * Three rules:
 *  1. TINA4_CSP unset -> the warning is emitted exactly ONCE across many requests.
 *  2. TINA4_CSP set   -> NO warning (the app opted in).
 *  3. Behaviour is UNCHANGED: the CSP header is still `default-src 'self'` when
 *     unset (the fix adds a log line, it does not weaken or drop the header).
 *
 * Mutation-proved: drop the warnCspDefaultOnce() call and rule 1 goes RED; warn on
 * every request (remove the ledger guard) and "exactly once" goes RED.
 *
 * Same case names in all four:
 *   tina4-python/tests/test_csp_default_warning.py
 *   tina4-ruby/spec/csp_default_warning_spec.rb
 *   tina4-nodejs/test/cspDefaultWarning.test.ts
 */

use PHPUnit\Framework\TestCase;
use Tina4\Log;
use Tina4\Middleware;
use Tina4\Middleware\SecurityHeadersMiddleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class CspDefaultWarningTest extends TestCase
{
    private const MARK = 'TINA4_CSP is not set';

    private string $tempDir;
    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = getcwd();
        $this->tempDir = sys_get_temp_dir() . '/tina4_cspwarn_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        chdir($this->tempDir);
        Router::clear();
        Middleware::reset();
        $this->clearEnv();
        Log::reset();
        putenv('TINA4_LOG_OUTPUT=file');
        putenv('TINA4_LOG_DIR=' . $this->tempDir);
        Log::configure(level: 'warning');
        $this->resetLedger();
    }

    protected function tearDown(): void
    {
        Router::clear();
        Middleware::reset();
        Log::reset();
        chdir($this->cwd);
        $this->clearEnv();
        $this->resetLedger();
    }

    private function clearEnv(): void
    {
        foreach (['TINA4_CSP', 'TINA4_LOG_OUTPUT', 'TINA4_LOG_DIR', 'TINA4_LOG_FORMAT',
                  'TINA4_LOG_LEVEL', 'TINA4_DEBUG'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    /** Reset the private per-process warn-once ledger — real state, no mock. */
    private function resetLedger(): void
    {
        $prop = new \ReflectionProperty(SecurityHeadersMiddleware::class, 'cspDefaultWarned');
        $prop->setAccessible(true);
        $prop->setValue(null, false);
    }

    private function logLines(): array
    {
        $path = $this->tempDir . '/tina4.log';
        if (!is_file($path)) {
            return [];
        }
        return array_values(array_filter(explode("\n", file_get_contents($path))));
    }

    private function markCount(): int
    {
        $n = 0;
        foreach ($this->logLines() as $line) {
            if (str_contains($line, self::MARK)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Dispatch a REAL request through the REAL router with the middleware attached
     * exactly the way App::start attaches it. Returns lower-cased headers.
     *
     * @return array<string, string>
     */
    private function dispatch(): array
    {
        Router::clear();
        Middleware::reset();
        SecurityHeadersMiddleware::attach();
        Router::get('/csp-probe', fn($request, $res) => $res->json(['ok' => true]));
        $result = Router::dispatch(
            Request::create(method: 'GET', path: '/csp-probe'),
            new Response(testing: true)
        );
        $lowered = [];
        foreach ($result->getHeaders() as $name => $value) {
            $lowered[strtolower($name)] = $value;
        }
        return $lowered;
    }

    public function testDefaultCspWarnsExactlyOnce(): void
    {
        $h1 = $this->dispatch();
        $this->dispatch();
        $this->dispatch();
        $this->assertSame(1, $this->markCount(), 'the default-CSP warning must fire exactly once');
        // Behaviour unchanged: the header is still the secure default.
        $this->assertSame("default-src 'self'", $h1['content-security-policy']);
    }

    public function testSetCspDoesNotWarn(): void
    {
        putenv("TINA4_CSP=default-src 'self' https://api.example");
        $_ENV['TINA4_CSP'] = "default-src 'self' https://api.example";
        $this->resetLedger();
        $h = $this->dispatch();
        $this->assertSame(0, $this->markCount(), 'setting TINA4_CSP is an opt-in and must not warn');
        $this->assertSame("default-src 'self' https://api.example", $h['content-security-policy']);
    }
}
