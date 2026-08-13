<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * CACHE CONTRACT - an explicit provider is honoured, and an unreachable
 * backend degrades VISIBLY.
 *
 * Pins two invariants from
 * tina4-documentation/plan/v3/fixtures/cache_contract.json (ADR-0024):
 *
 *   an-explicit-provider-is-honoured
 *     A provider named explicitly by the caller is the provider that is used.
 *
 *   an-unreachable-backend-degrades-visibly
 *     A configured backend that cannot be reached falls back to the FILE
 *     backend - a real persistent cache - and says so in the log.
 *
 * MEASURED in Node: a module-level memoised backend meant the SECOND middleware
 * to name a provider was silently handed the first one's backend. PHP builds a
 * fresh backend per ResponseCache instance and CacheFactory raises on an
 * unrecognised name, so PHP is correct on both; these are PARITY LOCK-IN tests,
 * and every case is mutation-proven so they are real gates rather than
 * decoration.
 *
 * THE OUTAGE IS REAL, NEVER SIMULATED
 *     An unreachable service is produced by binding port 0, reading the port
 *     the kernel assigned, and closing the listener. That port is then genuinely
 *     closed - a real connection refused, not a stubbed failure.
 *
 * WHY THE WARNING IS READ FROM A FILE
 *     Tina4's Log writes with fwrite() to the STDOUT stream, which PHP's output
 *     buffering does NOT capture - measured: ob_get_clean() returns an empty
 *     string while the line is plainly on the console. An assertion built on
 *     ob_start() would therefore be testing the wrong thing. The log FILE is a
 *     real sink on the real filesystem, so that is what is asserted.
 *
 * SERVICE ADDRESSES
 *     TINA4_TEST_REDIS_URL      (default redis://127.0.0.1:6379)
 *     TINA4_TEST_VALKEY_URL     (default valkey://127.0.0.1:6380)
 *     TINA4_TEST_MEMCACHED_URL  (default memcached://127.0.0.1:11211)
 */

use PHPUnit\Framework\TestCase;
use Tina4\Cache\CacheFactory;
use Tina4\Middleware\ResponseCache;

class CacheProviderSelectionTest extends TestCase
{
    /** @var array<int, string> directories created by a test */
    private array $temporaryDirs = [];

    private function redisUrl(): string
    {
        return getenv('TINA4_TEST_REDIS_URL') ?: 'redis://127.0.0.1:6379';
    }

    private function valkeyUrl(): string
    {
        return getenv('TINA4_TEST_VALKEY_URL') ?: 'valkey://127.0.0.1:6380';
    }

    private function memcachedUrl(): string
    {
        return getenv('TINA4_TEST_MEMCACHED_URL') ?: 'memcached://127.0.0.1:11211';
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    private function clearEnv(string $key): void
    {
        unset($_ENV[$key]);
        putenv($key);
    }

    /** @return array<int, string> */
    private function managedEnvKeys(): array
    {
        return [
            'TINA4_CACHE_BACKEND', 'TINA4_CACHE_URL', 'TINA4_CACHE_DIR',
            'TINA4_CACHE_TTL', 'TINA4_CACHE_MAX_ENTRIES',
            'TINA4_LOG_DIR', 'TINA4_LOG_OUTPUT',
        ];
    }

    protected function setUp(): void
    {
        \Tina4\DotEnv::resetEnv();
        foreach ($this->managedEnvKeys() as $key) {
            $this->clearEnv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->managedEnvKeys() as $key) {
            $this->clearEnv($key);
        }
        \Tina4\DotEnv::resetEnv();
        // Put the process-global logger back the way the rest of the suite
        // expects it; testAnUnreachableBackendLogsAWarning repoints it. The
        // old positional (dir, bool, level) signature is gone since the
        // logger_contract rewrite (2026-08-13) -- named args only.
        \Tina4\Log::configure(logDir: 'logs', level: 'info');

        foreach ($this->temporaryDirs as $dir) {
            foreach ((glob($dir . '/*') ?: []) as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        $this->temporaryDirs = [];
    }

    private function tempDir(string $name): string
    {
        $dir = sys_get_temp_dir() . '/tina4-provider-' . bin2hex(random_bytes(6)) . "-{$name}";
        @mkdir($dir, 0777, true);
        $this->temporaryDirs[] = $dir;
        return $dir;
    }

    /**
     * A port that is genuinely closed.
     *
     * Bind port 0, let the kernel choose, read the number back, then close the
     * listener. Connecting to it is a real ECONNREFUSED - no outage is
     * simulated anywhere in this file.
     */
    private function closedPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, 'could not bind an ephemeral port');
        $name = stream_socket_get_name($server, false);
        fclose($server);
        return (int)substr($name, strrpos($name, ':') + 1);
    }

    private function backendNameOf(ResponseCache $cache): string
    {
        return $this->backendOf($cache)->name();
    }

    private function backendOf(ResponseCache $cache): \Tina4\Cache\CacheBackend
    {
        return (new ReflectionObject($cache))->getProperty('backendImpl')->getValue($cache);
    }

    private function requireService(string $url, int $defaultPort, string $service): void
    {
        $parts = parse_url(str_contains($url, '://') ? $url : '//' . $url);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = (int)($parts['port'] ?? $defaultPort);
        $sock = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$sock) {
            $this->markTestSkipped("{$service} service not reachable at {$host}:{$port}");
        }
        fclose($sock);
    }

    // -- an explicit provider is honoured ------------------------------------

    /**
     * A named backend wins over the env AND over anything already built.
     *
     * Named "explicitly named ... is used" rather than "explicit provider is
     * honoured" ON PURPOSE: the contract auditor matches a case name as a
     * SUBSTRING of the suite file, so a name that is a PREFIX of another case
     * would still be "found" after it was deleted. Do not shorten it back.
     */
    public function testAnExplicitlyNamedProviderIsUsed(): void
    {
        $this->setEnv('TINA4_CACHE_BACKEND', 'memory');
        $this->setEnv('TINA4_CACHE_DIR', $this->tempDir('explicit'));

        // Build one first, so a memoised module-level backend would already exist.
        $ambient = new ResponseCache();
        $explicit = new ResponseCache(['backend' => 'file']);

        $this->assertSame(
            'file',
            $this->backendNameOf($explicit),
            "asked for the 'file' provider and got '" . $this->backendNameOf($explicit)
            . "' - the explicit request was overridden by ambient state"
        );
        $this->assertSame(
            'memory',
            $this->backendNameOf($ambient),
            'building an explicit instance changed the ambient one'
        );
    }

    /**
     * The measured Node defect, stated directly.
     *
     * Order matters: this is exactly the sequence that broke - some middleware
     * is constructed, THEN a second one names a provider and is silently
     * ignored.
     */
    public function testAnExplicitProviderIsHonouredAfterAnotherInstanceExists(): void
    {
        $this->setEnv('TINA4_CACHE_BACKEND', 'memory');
        $this->setEnv('TINA4_CACHE_DIR', $this->tempDir('second'));

        $first = new ResponseCache();
        $this->assertSame(
            'memory',
            $this->backendNameOf($first),
            'precondition: the ambient provider is memory'
        );

        $second = new ResponseCache(['backend' => 'file']);

        $this->assertSame(
            'file',
            $this->backendNameOf($second),
            "the second middleware asked for 'file' and was handed the first "
            . "instance's memoised backend instead"
        );
    }

    /**
     * NEGATIVE: honouring the request must mean a DIFFERENT store, not a label.
     *
     * A fix that records the requested name but still hands back the memoised
     * object would pass a name assertion and change nothing observable.
     */
    public function testTwoExplicitProvidersDoNotShareABackend(): void
    {
        $this->setEnv('TINA4_CACHE_DIR', $this->tempDir('shared-dir'));

        $memoryCache = new ResponseCache(['backend' => 'memory']);
        $fileCache = new ResponseCache(['backend' => 'file']);

        $this->backendOf($memoryCache)->set('only-in-memory', ['v' => 1], 300);

        $this->assertNull(
            $this->backendOf($fileCache)->get('only-in-memory'),
            'the two explicitly-named providers are the same object - the provider '
            . 'name was honoured but the store was not'
        );
    }

    /**
     * NEGATIVE: a typo must fail loudly, not fall through to memory.
     *
     * Falling through turned TINA4_CACHE_BACKEND=redsi into a running app with
     * a per-process cache while the operator believed it was in Redis.
     */
    public function testAnUnrecognisedProviderRaises(): void
    {
        try {
            CacheFactory::create('redsi');
            $this->fail('an unrecognised backend name did not raise - a typo silently '
                . 'became a per-process cache');
        } catch (\InvalidArgumentException $caught) {
            $this->assertStringContainsString(
                'redsi',
                $caught->getMessage(),
                'the error does not name the bad value'
            );
            $this->assertStringContainsString(
                'redis',
                $caught->getMessage(),
                'the error does not list the valid backends'
            );
        }
    }

    // -- an unreachable backend degrades visibly -----------------------------

    /**
     * A REAL closed port, on every network provider.
     *
     * The fallback must be the FILE backend - a real persistent cache - and
     * never memory (which silently loses cross-process sharing) and never a
     * no-op.
     */
    public function testAnUnreachableBackendFallsBackToTheFileBackend(): void
    {
        $this->setEnv('TINA4_CACHE_DIR', $this->tempDir('fallback'));
        $port = $this->closedPort();

        $providers = [
            'redis' => "redis://127.0.0.1:{$port}",
            'valkey' => "valkey://127.0.0.1:{$port}",
            'memcached' => "memcached://127.0.0.1:{$port}",
            'mongodb' => "mongodb://127.0.0.1:{$port}/tina4_cache_contract",
        ];
        foreach ($providers as $backend => $url) {
            $resolved = CacheFactory::create($backend, $url);
            $this->assertSame(
                'file',
                $resolved->name(),
                "an unreachable '{$backend}' resolved to '{$resolved->name()}', not "
                . "'file' - the fallback is not a real persistent cache"
            );
        }
    }

    /**
     * NEGATIVE: degrading must not mean a silent no-op.
     *
     * A no-op backend passes a name check and every write, and looks identical
     * to a working cache until the load arrives. So the fallback is exercised:
     * store, read it back, and confirm it reached the real filesystem.
     */
    public function testTheFallbackBackendActuallyCaches(): void
    {
        $cacheDir = $this->tempDir('reallyworks');
        $this->setEnv('TINA4_CACHE_DIR', $cacheDir);

        $resolved = CacheFactory::create('redis', 'redis://127.0.0.1:' . $this->closedPort());

        $resolved->set('degraded', ['v' => 'still cached'], 300);

        $this->assertSame(
            ['v' => 'still cached'],
            $resolved->get('degraded'),
            'the fallback backend accepted a write and lost it - the cache silently '
            . 'stopped caching'
        );
        $this->assertNotEmpty(
            glob($cacheDir . '/*.json') ?: [],
            "nothing reached the filesystem, so the 'file' fallback is a no-op "
            . "wearing the file backend's name"
        );
    }

    /**
     * The degradation must be VISIBLE, naming the provider that went away.
     *
     * Read from the real log FILE, not from output buffering: Tina4's Log
     * writes with fwrite() to the STDOUT stream, which ob_start() does not
     * capture (measured - ob_get_clean() comes back empty while the line is
     * plainly on the console), so an assertion built on it would pass or fail
     * for the wrong reason.
     */
    public function testAnUnreachableBackendLogsAWarning(): void
    {
        $logDir = $this->tempDir('warned');
        $this->setEnv('TINA4_CACHE_DIR', $this->tempDir('warned-cache'));
        $this->setEnv('TINA4_LOG_DIR', $logDir);
        $this->setEnv('TINA4_LOG_OUTPUT', 'both');
        \Tina4\Log::configure(logDir: $logDir, level: 'debug', output: 'both');

        CacheFactory::create('redis', 'redis://127.0.0.1:' . $this->closedPort());

        $logged = '';
        foreach ((glob($logDir . '/*.log') ?: []) as $file) {
            $logged .= strtolower((string)file_get_contents($file));
        }
        foreach (['redis', 'file', 'unavailable'] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $logged,
                'the fallback was silent, or the warning does not say WHICH backend '
                . "went away and what replaced it (missing '{$needle}')"
            );
        }
    }

    /**
     * NEGATIVE: the fallback must not fire when the service is fine.
     *
     * A probe that fails open would send every deployment to the file backend
     * and report a working cache - the same invisible degradation, from the
     * other direction.
     */
    public function testAReachableBackendIsNotReplaced(): void
    {
        $this->requireService($this->redisUrl(), 6379, 'redis');
        $this->requireService($this->valkeyUrl(), 6379, 'valkey');
        $this->requireService($this->memcachedUrl(), 11211, 'memcached');
        $this->setEnv('TINA4_CACHE_DIR', $this->tempDir('notreplaced'));

        $providers = [
            'redis' => $this->redisUrl(),
            'valkey' => $this->valkeyUrl(),
            'memcached' => $this->memcachedUrl(),
        ];
        foreach ($providers as $backend => $url) {
            $resolved = CacheFactory::create($backend, $url);
            $this->assertSame(
                $backend,
                $resolved->name(),
                "a REACHABLE '{$backend}' was replaced by '{$resolved->name()}' - the "
                . 'availability probe fails open, so every deployment quietly runs on '
                . 'the file backend while reporting a working cache'
            );
        }
    }
}
