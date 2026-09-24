<?php

declare(strict_types=1);

/**
 * CORS - the runner for cors_contract.json (ADR-0018, ADR-0048, ADR-0066).
 *
 * tina4-documentation/plan/v3/fixtures/cors_contract.json names these cases; the
 * Python, Ruby and Node suites carry the same names.
 *
 *  - deny by default: no policy grants nobody; an allow-list grants only its own;
 *  - a same-origin request (Origin equals the request's own scheme + host) is
 *    neither warned about nor granted anything - with or without a policy;
 *  - the warning for a refused origin names it, says how to add THAT origin
 *    and never advises '*';
 *  - refusals are remembered by REASON, never by origin (bounded diagnostics).
 *
 * PHP does not register CorsMiddleware on its own (ADR-0048's "PHP gains
 * automatic CORS" is still owed), so the app registers it the documented way,
 * Router::use(CorsMiddleware::class), exactly as a real app must today.
 *
 * NO MOCKS: every case boots a fresh Tina4 socket server (so the warn-once
 * ledger starts empty), sends real HTTP, reads the server's REAL log output and
 * reads the ledger back from a route running inside that same server.
 */

use PHPUnit\Framework\TestCase;

class CorsContractTest extends TestCase
{
    private const ALLOWED = 'https://allowed.example';
    private const OTHER = 'https://other.example';
    private const REASONS = ['unconfigured', 'denied', 'wildcard-credentials'];

    private string $projectDir = '';
    private ?TestServer $server = null;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/tina4-cors-contract-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/src/routes', 0777, true);
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        file_put_contents(
            $this->projectDir . '/index.php',
            "<?php\nrequire {$autoload};\n"
            . "(new \\Tina4\\App(basePath: __DIR__))->run('127.0.0.1', (int) \$argv[1]);\n"
        );
        file_put_contents($this->projectDir . '/src/routes/cors_contract.php', <<<'PHP'
<?php
\Tina4\Router::use(\Tina4\Middleware\CorsMiddleware::class);
\Tina4\Router::post('/cors-contract/save', function ($request, $response) {
    return $response->json(['saved' => true]);
})->noAuth();
\Tina4\Router::get('/cors-contract/ledger', function ($request, $response) {
    return $response->json(['keys' => \Tina4\Middleware\CorsMiddleware::warnedReasons()]);
});
PHP);
    }

    protected function tearDown(): void
    {
        $this->stop();
        if ($this->projectDir !== '' && is_dir($this->projectDir)) {
            exec('rm -rf ' . escapeshellarg($this->projectDir));
        }
    }

    private function stop(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    /** Start a fresh server with TINA4_CORS_ORIGINS set (or unset when null). */
    private function serve(?string $origins, array $extra = []): string
    {
        $this->stop();
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'TMPDIR' => sys_get_temp_dir(),
            'TINA4_DEBUG' => 'false',
            'TINA4_SECRET' => 'cors-contract-php-secret-0123456789abcdef',
            'TINA4_OVERRIDE_CLIENT' => 'true',
            'TINA4_SUPPRESS' => 'true',
            'TINA4_NO_BROWSER' => 'true',
            'TINA4_AUTO_MIGRATE' => 'false',
            'TINA4_LOG_LEVEL' => 'DEBUG',
            'TINA4_RATE_LIMIT' => '100000',
        ];
        if ($origins !== null) {
            $env['TINA4_CORS_ORIGINS'] = $origins;
        }
        $this->server = TestServer::startScript($this->projectDir . '/index.php', [], array_merge($env, $extra), $this->projectDir);
        return $this->server->base();
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private static function request(string $url, string $method, array $headers = []): array
    {
        $lines = ['Content-Type: application/json', 'Connection: close'];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $lines), 'content' => $method === 'POST' ? '{}' : '',
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15,
        ]]);
        $body = (string) @file_get_contents($url, false, $context);
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
        return ['status' => $status, 'headers' => $parsed, 'body' => $body];
    }

    private static function post(string $base, string $origin): array
    {
        return self::request($base . '/cors-contract/save', 'POST', ['Origin' => $origin]);
    }

    /** @return list<string> the server's CORS warning lines (every one starts "CORS:") */
    private function corsWarnings(): array
    {
        usleep(200_000);
        return array_values(array_filter(
            preg_split('/\R/', $this->server->log()) ?: [],
            static fn(string $line): bool => str_contains($line, 'CORS:')
        ));
    }

    /** @return list<string> */
    private static function accessControl(array $reply): array
    {
        return array_values(array_filter(array_keys($reply['headers']), static fn(string $name): bool => str_starts_with($name, 'access-control-')));
    }

    public function testACrossOriginRequestIsDeniedByDefault(): void
    {
        $reply = self::post($this->serve(null), self::OTHER);
        $this->assertSame(200, $reply['status'], 'CORS never refuses on the server - the browser enforces it');
        $this->assertArrayNotHasKey('access-control-allow-origin', $reply['headers']);
    }

    public function testOnlyAListedOriginIsGranted(): void
    {
        $base = $this->serve(self::ALLOWED);
        $this->assertSame(self::ALLOWED, self::post($base, self::ALLOWED)['headers']['access-control-allow-origin'] ?? null);
        $this->assertArrayNotHasKey('access-control-allow-origin', self::post($base, self::OTHER)['headers']);
    }

    public function testASameOriginRequestIsNeitherWarnedAboutNorGranted(): void
    {
        foreach ([null, self::ALLOWED] as $policy) {
            $base = $this->serve($policy);
            $reply = self::post($base, $base);
            $this->assertSame(200, $reply['status']);
            $this->assertSame([], self::accessControl($reply), "policy={$policy}: a same-origin request was granted CORS headers");
            $this->assertSame([], $this->corsWarnings(), "policy={$policy}: a same-origin request was warned about");
        }
    }

    public function testADifferentPortOrSchemeIsCrossOrigin(): void
    {
        $base = $this->serve(null);
        $port = (int) parse_url($base, PHP_URL_PORT);
        self::post($base, 'http://127.0.0.1:' . ($port + 1));
        $this->assertCount(1, $this->corsWarnings(), 'an Origin on another port is cross-origin');

        $base = $this->serve(null);
        $port = (int) parse_url($base, PHP_URL_PORT);
        self::post($base, "https://127.0.0.1:{$port}");
        $this->assertCount(1, $this->corsWarnings(), 'an Origin with another scheme is cross-origin');
    }

    public function testARefusedOriginWarningNamesTheOriginAndNeverAdvisesAWildcard(): void
    {
        foreach ([null, self::ALLOWED] as $policy) {
            self::post($this->serve($policy), self::OTHER);
            $warnings = $this->corsWarnings();
            $this->assertCount(1, $warnings, "policy={$policy}");
            $this->assertStringContainsString(self::OTHER, $warnings[0]);
            $this->assertStringContainsString('TINA4_CORS_ORIGINS', $warnings[0]);
            $this->assertStringNotContainsString('*', $warnings[0], "the warning advises a wildcard: {$warnings[0]}");
        }
    }

    /**
     * tina4: "once per process" is literal. Tina4's own socket server forks a
     * child per request by default, and a child starts with an empty ledger, so
     * in that mode every refused request logs its line (the ledger itself still
     * dies with the child and never grows). The property is proven here in ONE
     * serving process (TINA4_SERVE_FORK=false, as a pool worker or PHP-FPM
     * worker serves); remembering reasons across forked children is owed.
     */
    public function testManyRefusedOriginsProduceOneWarningPerReasonAndNoPerOriginLedger(): void
    {
        foreach ([null, self::ALLOWED] as $policy) {
            $base = $this->serve($policy, ['TINA4_SERVE_FORK' => 'false']);
            for ($number = 0; $number < 30; $number++) {
                self::post($base, "https://probe{$number}.attacker.example");
            }
            $warnings = $this->corsWarnings();
            $keys = json_decode(self::request($base . '/cors-contract/ledger', 'GET')['body'], true)['keys'] ?? null;
            $this->assertCount(1, $warnings, "policy={$policy}: 30 refused origins logged " . count($warnings) . ' warnings');
            $this->assertIsArray($keys, 'the ledger route did not answer');
            $this->assertSame([], array_values(array_diff($keys, self::REASONS)), "policy={$policy}: the ledger holds more than reasons");
        }
    }
}
