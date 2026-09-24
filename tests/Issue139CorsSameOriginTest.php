<?php

declare(strict_types=1);

/**
 * tina4-python#139 parity: the CORS middleware treated any request carrying an
 * Origin header as cross-origin. Browsers send Origin on every same-origin
 * POST/PUT/PATCH/DELETE, so an SPA served by the same app logged "refused
 * cross-origin request" on every save - and the message advised '*', which
 * would open the API to every website.
 *
 * The contract, in all four frameworks: a request whose Origin equals the
 * request's own origin (scheme://host[:port], http:80 and https:443 being the
 * default ports) is same-origin - no CORS warning, never refused. That only
 * silences the warning; it never grants access, so a spoofed Host gains
 * nothing. A disallowed cross-origin request still warns, and no warning ever
 * advises '*' - it names the specific origin to add instead.
 *
 * Each case is a real HTTP request to Tina4's own socket server; the warnings
 * are read from the server's REAL log output (its stdout), no logger double.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Issue139CorsSameOriginTest extends TestCase
{
    private string $projectDir = '';
    private ?TestServer $server = null;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/tina4-issue139-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/src/routes', 0777, true);
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        file_put_contents(
            $this->projectDir . '/index.php',
            "<?php\nrequire {$autoload};\n"
            . "(new \\Tina4\\App(basePath: __DIR__))->run('127.0.0.1', (int) \$argv[1]);\n"
        );
        file_put_contents($this->projectDir . '/src/routes/save.php', <<<'PHP'
<?php
\Tina4\Router::use(\Tina4\Middleware\CorsMiddleware::class);
\Tina4\Router::post('/api/save', function ($request, $response) {
    return $response->json(['saved' => true]);
})->noAuth();
PHP);
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
        if ($this->projectDir !== '' && is_dir($this->projectDir)) {
            exec('rm -rf ' . escapeshellarg($this->projectDir));
        }
    }

    /** Start the app with TINA4_CORS_ORIGINS set (or unset when null). */
    private function serve(?string $corsOrigins): string
    {
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'TMPDIR' => sys_get_temp_dir(),
            'TINA4_DEBUG' => 'false',
            'TINA4_SECRET' => 'issue-139-php-secret-0123456789abcdef',
            'TINA4_OVERRIDE_CLIENT' => 'true',
            'TINA4_SUPPRESS' => 'true',
            'TINA4_NO_BROWSER' => 'true',
            'TINA4_AUTO_MIGRATE' => 'false',
            'TINA4_LOG_LEVEL' => 'DEBUG',
        ];
        if ($corsOrigins !== null) {
            $env['TINA4_CORS_ORIGINS'] = $corsOrigins;
        }
        $this->server = TestServer::startScript($this->projectDir . '/index.php', [], $env, $this->projectDir);
        return $this->server->base();
    }

    /**
     * POST /api/save with the given request headers.
     *
     * @return array{status: int, headers: array<string, string>}
     */
    private static function post(string $base, array $headers): array
    {
        $lines = ['Content-Type: application/json', 'Connection: close'];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $lines), 'content' => '{}',
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15,
        ]]);
        @file_get_contents($base . '/api/save', false, $context);
        $status = 0;
        $parsed = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $parsed[strtolower(trim($name))] = trim($value);
            }
        }
        return ['status' => $status, 'headers' => $parsed];
    }

    /** The server's CORS log lines, after giving a forked request a moment to flush. */
    private function corsLog(): string
    {
        usleep(200_000);
        $lines = array_filter(
            preg_split('/\R/', $this->server->log()) ?: [],
            static fn(string $line): bool => stripos($line, 'CORS') !== false
        );
        return implode("\n", $lines);
    }

    /** @return array<string, array{0: string|null}> */
    public static function policies(): array
    {
        return ['no policy' => [null], 'an allow-list for another site' => ['https://partner.example.com']];
    }

    #[DataProvider('policies')]
    public function testASameOriginPostWithAnOriginHeaderLogsNoWarning(?string $policy): void
    {
        $base = $this->serve($policy);

        $response = self::post($base, ['Origin' => $base]);

        $this->assertSame(200, $response['status'], 'a same-origin POST is never refused');
        $this->assertSame('', $this->corsLog(), 'a same-origin request must not log a CORS warning');
        $this->assertArrayNotHasKey('access-control-allow-origin', $response['headers'],
            'same-origin only silences the warning; it never grants CORS access');
    }

    /** http:80 and https:443 are the default ports: an Origin that omits or spells them out is the same origin. */
    public function testDefaultPortsAreTheSameOrigin(): void
    {
        $base = $this->serve(null);

        self::post($base, ['Host' => 'app.example', 'Origin' => 'http://app.example:80']);
        self::post($base, ['Host' => 'app.example:443', 'X-Forwarded-Proto' => 'https', 'Origin' => 'https://app.example']);

        $this->assertSame('', $this->corsLog());
    }

    #[DataProvider('policies')]
    public function testADisallowedCrossOriginRequestWarnsWithoutAdvisingAWildcard(?string $policy): void
    {
        $base = $this->serve($policy);

        $response = self::post($base, ['Origin' => 'https://elsewhere.example']);

        $this->assertArrayNotHasKey('access-control-allow-origin', $response['headers']);
        $log = $this->corsLog();
        $this->assertStringContainsString('https://elsewhere.example', $log, 'a disallowed cross-origin request still warns');
        $this->assertStringNotContainsString('*', $log, "the warning must never advise '*'");
        $this->assertStringContainsString('TINA4_CORS_ORIGINS', $log, 'it names the setting to add the specific origin to');
    }

    /** A spoofed Host that matches the Origin silences the warning but grants nothing. */
    public function testASpoofedHostGainsNoAccess(): void
    {
        $base = $this->serve('https://partner.example.com');

        $response = self::post($base, ['Host' => 'evil.example', 'Origin' => 'http://evil.example']);

        $this->assertArrayNotHasKey('access-control-allow-origin', $response['headers']);
    }

    public function testAnAllowedCrossOriginRequestGetsTheCorsHeaders(): void
    {
        $base = $this->serve('https://partner.example.com');

        $response = self::post($base, ['Origin' => 'https://partner.example.com']);

        $this->assertSame(200, $response['status']);
        $this->assertSame('https://partner.example.com', $response['headers']['access-control-allow-origin'] ?? null);
        $this->assertSame('', $this->corsLog());
    }
}
