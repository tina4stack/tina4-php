<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Feature 130 — dynamic framework version (single resolver + version
 * User-Agent). See tina4-documentation/plan/v3/features/130-dynamic-version.md
 * and OWNER-DECISIONS.md (Batch 5, VERSION-DEC-01/02/03). Shared fixture:
 * tina4-documentation/plan/v3/fixtures/version_contract.json.
 *
 * PHP was the outlier the audit found: THREE independent version sources
 * that drifted in a plain checkout — App::$VERSION (correct), the CLI's
 * tina4FrameworkVersion() (read composer.json's `version` key, absent by
 * design since Packagist derives it from git tags, then
 * vendor/composer/installed.json, no self-entry since in THIS repo Tina4 IS
 * the project — so it fell to '0.0.0'), and the MCP serverInfo (the
 * constructor's generic '1.0.0' default, never overridden at the one
 * default-server call site). VERSION-DEC-01 fixed both: tina4FrameworkVersion()
 * now reads \Tina4\App::$VERSION directly (an autoloaded class DEFINITION,
 * no App::start() side effects — still boot-free); the stale docblock
 * claiming it mirrors a deleted \Tina4\App::resolveVersion() is gone.
 * Tina4\Bootstrap\MCP.php's getDefaultServer() now passes App::$VERSION as
 * the third constructor argument.
 *
 * Case names (shared with Python/Ruby/Node):
 *   - runtime_version_equals_the_package_manifest
 *   - every_reporting_surface_agrees
 *   - no_surface_reports_a_placeholder_version
 *   - the_outbound_http_client_sends_a_tina4_version_user_agent
 *
 * NO MOCKS: a real `php dual_port_server.php <port>` child process (the same
 * App::run() -> Server::start() path a real `tina4php serve` takes — reused
 * unchanged from DualPortContractTest's fixture app), real TCP sockets for
 * health/dashboard/MCP, a real bin/tina4php subprocess for the CLI manifest,
 * and a real local TCP capture server the framework's own Api client makes a
 * real outbound request against.
 */

use PHPUnit\Framework\TestCase;
use Tina4\App;
use Tina4\Api;

class VersionContractTest extends TestCase
{
    private const PLACEHOLDER_VERSIONS = ['0.0.0', '1.0.0'];

    private static string $bin;

    /** @var resource|null */
    private static $serverProcess = null;
    private static string $serverLogFile = '';
    private static int $serverPort = 0;

    public static function setUpBeforeClass(): void
    {
        self::$bin = realpath(__DIR__ . '/../bin/tina4php');
        self::assertNotFalse(self::$bin, 'bin/tina4php not found');
        self::bootServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            @proc_terminate(self::$serverProcess, 9);
            @proc_close(self::$serverProcess);
        }
        if (self::$serverLogFile !== '') {
            @unlink(self::$serverLogFile);
        }
    }

    // ── runtime_version_equals_the_package_manifest ─────────────────────

    public function testRuntimeVersionEqualsThePackageManifest(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', App::$VERSION);

        $composerPath = __DIR__ . '/../composer.json';
        $composer = json_decode((string)file_get_contents($composerPath), true);
        if (array_key_exists('version', $composer) && $composer['version'] !== null && $composer['version'] !== '') {
            // If composer.json ever gains a version key, it must not drift
            // from the declared single source of truth.
            $this->assertSame(App::$VERSION, $composer['version']);
        } else {
            // By design (see App::$VERSION's own docblock): composer.json
            // carries NO version key — Packagist derives it from git tags, and
            // a Packagist install ships the tagged source, so the declared
            // constant in that copy already equals its own tag by
            // construction. There is no separate manifest file to diverge
            // from — App::$VERSION itself IS the manifest.
            $this->assertArrayNotHasKey('version', $composer);
        }
    }

    // ── every_reporting_surface_agrees / no_surface_reports_a_placeholder_version ──

    public function testEveryReportingSurfaceAgrees(): void
    {
        $resolved = App::$VERSION;

        $health = $this->httpGet('127.0.0.1', self::$serverPort, '/health');
        $this->assertStringStartsWith('200', $health['status'], "GET /health: {$health['status']}");
        $healthVersion = json_decode($health['body'], true)['version'] ?? null;

        $dashboard = $this->httpGet('127.0.0.1', self::$serverPort, '/__dev/api/status');
        $this->assertStringStartsWith('200', $dashboard['status'], "GET /__dev/api/status: {$dashboard['status']}");
        $dashboardVersion = json_decode($dashboard['body'], true)['framework_version'] ?? null;

        $mcpVersion = $this->mcpInitializeVersion('127.0.0.1', self::$serverPort);
        $cliVersion = $this->cliManifestVersion();

        $log = (string)@file_get_contents(self::$serverLogFile);
        $expectedBanner = 'Tina4 PHP v' . $resolved;
        $this->assertStringContainsString($expectedBanner, $log, "boot banner missing {$expectedBanner}; log: {$log}");

        $this->assertSame($resolved, $healthVersion, "health {$healthVersion} != runtime {$resolved}");
        $this->assertSame($resolved, $dashboardVersion, "dashboard {$dashboardVersion} != runtime {$resolved}");
        $this->assertSame($resolved, $mcpVersion, "MCP serverInfo {$mcpVersion} != runtime {$resolved}");
        $this->assertSame($resolved, $cliVersion, "CLI manifest {$cliVersion} != runtime {$resolved}");
    }

    public function testNoSurfaceReportsAPlaceholderVersion(): void
    {
        $health = $this->httpGet('127.0.0.1', self::$serverPort, '/health');
        $healthVersion = json_decode($health['body'], true)['version'] ?? null;

        $dashboard = $this->httpGet('127.0.0.1', self::$serverPort, '/__dev/api/status');
        $dashboardVersion = json_decode($dashboard['body'], true)['framework_version'] ?? null;

        $mcpVersion = $this->mcpInitializeVersion('127.0.0.1', self::$serverPort);
        $cliVersion = $this->cliManifestVersion();

        foreach (['health' => $healthVersion, 'dashboard' => $dashboardVersion, 'mcp' => $mcpVersion, 'cli' => $cliVersion] as $name => $value) {
            $this->assertNotContains($value, self::PLACEHOLDER_VERSIONS, "{$name} reported a placeholder version: {$value}");
        }
    }

    // ── parser regression: stdout pollution must not swallow the version ─

    public function testParseCommandsManifestSurvivesLeadingStdoutNoise(): void
    {
        // The failure mode this hardens against: a busted CLI php.ini with
        // display_errors=stdout printed a "PHP Warning: Cannot load module 'grpc'"
        // line to stdout BEFORE the JSON payload, so a naive json_decode
        // returned null and testEveryReportingSurfaceAgrees blew up with the
        // useless message "null is identical to '3.13.115'".
        $polluted = "PHP Warning:  PHP Startup: Unable to load dynamic library 'grpc.so'\nPHP Warning:  Something else\n"
                  . '{"framework":"php","version":"3.13.115","commands":[]}';

        // parseCommandsManifest is private; PHP 8.1+ allows reflection to invoke
        // it without setAccessible(), and setAccessible() is a deprecated no-op in 8.5.
        $ref = new \ReflectionMethod(self::class, 'parseCommandsManifest');
        $manifest = $ref->invoke(null, $polluted, 'test-fixture');

        $this->assertSame('3.13.115', $manifest['version'], 'parser must skip leading noise and extract version');
        $this->assertSame('php', $manifest['framework']);

        // And a clean payload still works (regression against over-aggressive stripping).
        $clean = '{"framework":"php","version":"3.13.115","commands":[]}';
        $m2 = $ref->invoke(null, $clean, 'clean-fixture');
        $this->assertSame('3.13.115', $m2['version']);
    }

    public function testParseCommandsManifestFailsLoudlyWhenNoJsonPresent(): void
    {
        // parseCommandsManifest is private; PHP 8.1+ allows reflection to invoke
        // it without setAccessible(), and setAccessible() is a deprecated no-op in 8.5.
        $ref = new \ReflectionMethod(self::class, 'parseCommandsManifest');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no.*json|manifest/i');
        $ref->invoke(null, "PHP Fatal error:  something exploded, no JSON\n", 'test-negative');
    }

    // ── the_outbound_http_client_sends_a_tina4_version_user_agent ───────

    public function testTheOutboundHttpClientSendsATina4VersionUserAgent(): void
    {
        $capture = $this->bootCaptureServer();
        try {
            $baseUrl = "http://127.0.0.1:{$capture['port']}";

            // Default: no caller-supplied User-Agent.
            $api = new Api($baseUrl);
            $result = $api->get('/probe');
            $this->assertNull($result['error'], 'request failed: ' . json_encode($result));
            $received = $this->readCapturedUserAgent($capture['file']);
            $expected = 'Tina4/' . App::$VERSION;
            $this->assertSame($expected, $received, "default User-Agent was {$received}, expected {$expected}");

            // Caller-supplied User-Agent must be preserved, not clobbered.
            @unlink($capture['file']);
            $apiCustom = new Api($baseUrl, headers: ['User-Agent' => 'MyApp/9.9']);
            $result2 = $apiCustom->get('/probe');
            $this->assertNull($result2['error'], 'request failed: ' . json_encode($result2));
            $received2 = $this->readCapturedUserAgent($capture['file']);
            $this->assertSame('MyApp/9.9', $received2, "caller-supplied User-Agent was clobbered: {$received2}");
        } finally {
            $this->stopCaptureServer($capture);
        }
    }

    // ── real server plumbing (spawnServer, httpGet, readUntilClose mirror
    //    DualPortContractTest's own helpers) ──────────────────────────────

    private static function bootServer(): void
    {
        $port = FreePort::get();
        $logFile = sys_get_temp_dir() . '/tina4-versioncontract-' . $port . '-' . bin2hex(random_bytes(4)) . '.log';

        $environment = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
            'TINA4_OVERRIDE_CLIENT' => 'true',
            'TINA4_AUTO_MIGRATE' => 'false',
            'TINA4_DEBUG' => 'true',
            'TINA4_NO_AI_PORT' => 'true',
            // TINA4_SUPPRESS deliberately NOT set (defaults to 'false') — this
            // suite needs the REAL boot banner to prove every_reporting_surface_agrees.
        ];

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/dual_port_server.php', (string)$port],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $logFile, 'a'],
                2 => ['file', $logFile, 'a'],
            ],
            $pipes,
            dirname(__DIR__),
            $environment
        );
        if (!is_resource($process)) {
            self::fail('the dual_port_server.php process must start');
        }
        self::$serverProcess = $process;
        self::$serverLogFile = $logFile;
        self::$serverPort = $port;

        for ($attempt = 0; $attempt < 200; $attempt++) {
            $response = self::rawHttpGet('127.0.0.1', $port, '/health');
            if ($response !== null && $response['status'] !== '') {
                return;
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                self::fail('dual_port_server.php exited during startup; log: ' . (@file_get_contents($logFile) ?: '(no log)'));
            }
            usleep(25_000);
        }
        self::fail("dual_port_server.php never bound port {$port}; log: " . (@file_get_contents($logFile) ?: '(no log)'));
    }

    private static function rawHttpGet(string $host, int $port, string $path): ?array
    {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5.0);
        if ($socket === false) {
            return null;
        }
        fwrite($socket, "GET {$path} HTTP/1.1\r\nHost: {$host}:{$port}\r\nConnection: close\r\n\r\n");
        $raw = self::readSocketUntilClose($socket, 5.0);
        fclose($socket);
        [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $statusLine = strtok($head, "\r\n") ?: '';
        $status = trim((string)preg_replace('#^HTTP/\d\.\d\s+#', '', $statusLine));
        return ['status' => $status, 'body' => $body];
    }

    private static function readSocketUntilClose($socket, float $timeoutSeconds): string
    {
        stream_set_blocking($socket, false);
        $deadline = microtime(true) + $timeoutSeconds;
        $buffer = '';
        while (microtime(true) < $deadline) {
            $chunk = @fread($socket, 65536);
            if ($chunk === false) {
                break;
            }
            if ($chunk !== '') {
                $buffer .= $chunk;
                continue;
            }
            if (feof($socket)) {
                break;
            }
            usleep(10_000);
        }
        return $buffer;
    }

    private function httpGet(string $host, int $port, string $path): array
    {
        $response = self::rawHttpGet($host, $port, $path);
        $this->assertNotNull($response, "could not connect to {$host}:{$port}{$path}");
        return $response;
    }

    /** Real JSON-RPC 'initialize' POST to the mounted MCP endpoint. */
    private function mcpInitializeVersion(string $host, int $port): ?string
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'version-contract-test', 'version' => '1.0'],
            ],
        ]);

        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5.0);
        $this->assertIsResource($socket, "could not connect to {$host}:{$port}: {$errstr} ({$errno})");
        $request = "POST /__dev/mcp HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $body;
        fwrite($socket, $request);
        $raw = self::readSocketUntilClose($socket, 5.0);
        fclose($socket);

        [$head, $respBody] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $statusLine = strtok($head, "\r\n") ?: '';
        $this->assertStringContainsString('200', $statusLine, "MCP initialize -> {$statusLine}: {$respBody}");
        $decoded = json_decode($respBody, true);
        return $decoded['result']['serverInfo']['version'] ?? null;
    }

    /** Run the REAL CLI entrypoint as a subprocess and return the manifest's version. */
    private function cliManifestVersion(): ?string
    {
        // -d display_errors=stderr keeps a startup warning (e.g. the CLI php.ini
        // references a missing extension like grpc.so) off stdout, so it can
        // never pollute the JSON payload we're about to parse. Belt-and-braces
        // -d error_reporting=E_ALL routes every level to the same stderr sink.
        $cmd = escapeshellarg(PHP_BINARY)
             . ' -d display_errors=stderr'
             . ' -d error_reporting=E_ALL'
             . ' ' . escapeshellarg(self::$bin) . ' commands --json';
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__), getenv() ?: []);
        $this->assertIsResource($process, 'failed to start bin/tina4php');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $this->assertSame(0, $exit, "commands --json exited non-zero; stderr:\n{$stderr}");
        $manifest = self::parseCommandsManifest($stdout, 'cliManifestVersion');
        return $manifest['version'] ?? null;
    }

    /**
     * Parse a `commands --json` manifest, tolerating leading stdout noise
     * (e.g. PHP startup warnings that a busted php.ini prints BEFORE the JSON).
     *
     * The bare `json_decode($stdout, true)` this replaced silently returned
     * `null`, which produced the useless failure `null is identical to 'X.Y.Z'`
     * when a warning polluted stdout. This helper finds the first `{` — the
     * `commands --json` payload is always an object — decodes from that offset
     * with JSON_THROW_ON_ERROR, and throws a diagnostic RuntimeException
     * (including a 400-char stdout slice) on failure.
     *
     * @throws \RuntimeException when no JSON object is present, or the payload
     *                           will not parse.
     */
    private static function parseCommandsManifest(string $stdout, string $context = ''): array
    {
        $offset = strpos($stdout, '{');
        if ($offset === false) {
            $slice = substr($stdout, 0, 400);
            throw new \RuntimeException(
                "parseCommandsManifest ({$context}): no JSON object present in stdout. "
                . "First 400 chars of stdout:\n{$slice}"
            );
        }

        try {
            $decoded = json_decode(substr($stdout, $offset), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $slice = substr($stdout, 0, 400);
            throw new \RuntimeException(
                "parseCommandsManifest ({$context}): failed to decode manifest JSON: "
                . $e->getMessage() . ". First 400 chars of stdout:\n{$slice}",
                0,
                $e
            );
        }

        if (!is_array($decoded)) {
            $slice = substr($stdout, 0, 400);
            throw new \RuntimeException(
                "parseCommandsManifest ({$context}): decoded manifest was not an object. "
                . "First 400 chars of stdout:\n{$slice}"
            );
        }

        return $decoded;
    }

    // ── DEC-03: real local TCP capture server ────────────────────────────

    /** @return array{socket: resource, port: int, file: string} */
    private function bootCaptureServer(): array
    {
        $port = FreePort::get();
        $captureFile = sys_get_temp_dir() . '/tina4-ua-capture-' . $port . '-' . bin2hex(random_bytes(4)) . '.txt';
        // A tiny standalone raw-socket capture server, run as its own PHP
        // subprocess so accepting a connection never blocks THIS test process.
        $script = <<<'PHP'
<?php
$port = (int)$argv[1];
$file = $argv[2];
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if ($server === false) { fwrite(STDERR, "bind failed: {$errstr}\n"); exit(1); }
while (true) {
    $conn = @stream_socket_accept($server, 10.0);
    if ($conn === false) { continue; }
    $raw = '';
    stream_set_timeout($conn, 2);
    while (!feof($conn)) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || $chunk === '') { break; }
        $raw .= $chunk;
        if (str_contains($raw, "\r\n\r\n")) { break; }
    }
    $userAgent = null;
    foreach (explode("\r\n", $raw) as $line) {
        if (stripos($line, 'user-agent:') === 0) {
            $userAgent = trim(substr($line, strlen('user-agent:')));
        }
    }
    file_put_contents($file, (string)$userAgent);
    fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
    fclose($conn);
}
PHP;
        $scriptFile = sys_get_temp_dir() . '/tina4-ua-capture-server-' . $port . '.php';
        file_put_contents($scriptFile, $script);

        $process = proc_open(
            [PHP_BINARY, $scriptFile, (string)$port, $captureFile],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process, 'capture server must start');

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.2);
            if ($probe !== false) {
                fclose($probe);
                break;
            }
            usleep(20_000);
        }

        return ['process' => $process, 'port' => $port, 'file' => $captureFile, 'script' => $scriptFile];
    }

    private function stopCaptureServer(array $capture): void
    {
        if (is_resource($capture['process'])) {
            @proc_terminate($capture['process'], 9);
            @proc_close($capture['process']);
        }
        @unlink($capture['file']);
        @unlink($capture['script']);
    }

    private function readCapturedUserAgent(string $file): ?string
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if (is_file($file)) {
                $value = file_get_contents($file);
                if ($value !== false && $value !== '') {
                    return $value;
                }
            }
            usleep(20_000);
        }
        return null;
    }
}
