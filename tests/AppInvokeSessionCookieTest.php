<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
 declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 *
 * App::__invoke() must hand the session cookie back ON THE RESPONSE it returns.
 *
 * Swoole, RoadRunner and every PSR-7 bridge run Tina4 inside a long-lived php
 * CLI worker: they call $app($request) and send what comes back - status,
 * getHeaders(), body. Router::emitSessionCookie() used PHP's native
 * setcookie() whenever headers_sent() was false, which in a CLI worker it
 * always is. setcookie() there reaches nobody, so a first visit never got a
 * session cookie and no session could ever resume.
 *
 * Each case is a real php CLI worker process (the shape Swoole / RoadRunner
 * run) that serves two requests: a first visit, then a second carrying the
 * cookie the first response handed back. The Swoole leg builds a genuine
 * Swoole\Http\Request with the extension's own create() + parse() (needs
 * openswoole loaded, e.g. PHP_INI_SCAN_DIR=/tmp/confd_nogrpc on the lab); the
 * PSR-7 leg hands __invoke() a Psr7ServerRequest - an input value object with
 * PSR-7 semantics, the shape a RoadRunner or FrankenPHP bridge passes (Tina4
 * declares no PSR dependency and probes PSR-7 structurally).
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AppInvokeSessionCookieTest extends TestCase
{
    private string $projectDir = '';

    protected function setUp(): void
    {
        $this->projectDir = \TempPath::dir('tina4_invoke_cookie_');
        mkdir($this->projectDir . '/src/routes', 0755, true);
        file_put_contents($this->projectDir . '/src/routes/visit.php', <<<'PHP'
<?php
\Tina4\Router::get('/visit', function ($request, $response) {
    $visits = (int) ($request->session->get('visits') ?? 0) + 1;
    $request->session->set('visits', $visits);
    return $response->json(['visits' => $visits]);
});
PHP);
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        $psr7 = var_export(__DIR__ . '/Psr7ServerRequest.php', true);
        file_put_contents($this->projectDir . '/worker.php', "<?php\nrequire {$autoload};\nrequire {$psr7};\n" . <<<'PHP'
$app = new \Tina4\App(basePath: __DIR__);

function tina4TestRequest(string $kind, string $cookie): object
{
    if ($kind === 'swoole') {
        $request = \Swoole\Http\Request::create();
        $request->parse("GET /visit HTTP/1.1\r\nHost: localhost\r\n"
            . ($cookie === '' ? '' : "Cookie: {$cookie}\r\n") . "\r\n");
        return $request;
    }
    $cookieParams = [];
    if ($cookie !== '') {
        [$cookieName, $cookieValue] = explode('=', $cookie, 2);
        $cookieParams[$cookieName] = $cookieValue;
    }
    return new \Psr7ServerRequest(
        'GET',
        'http://localhost/visit',
        array_merge(['Host' => 'localhost'], $cookie === '' ? [] : ['Cookie' => $cookie]),
        '',
        ['REMOTE_ADDR' => '127.0.0.1', 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/visit'],
        $cookieParams
    );
}

$cookie = '';
$visits = [];
$sessionCookies = [];
for ($i = 0; $i < 2; $i++) {
    // What a Swoole / RoadRunner / PSR-7 bridge sends: the response's headers.
    $response = $app(tina4TestRequest($argv[1], $cookie));
    $visits[] = json_decode($response->getBody(), true)['visits'] ?? null;
    $setCookie = [];
    foreach ($response->getHeaders() as $name => $value) {
        if (strcasecmp($name, 'Set-Cookie') === 0) {
            $setCookie = array_merge($setCookie, (array) $value);
        }
    }
    $setCookie = array_merge($setCookie, $response->cookieHeaderLines());
    foreach ($setCookie as $line) {
        if (str_starts_with($line, 'tina4_session=')) {
            $cookie = explode(';', $line, 2)[0];
            $sessionCookies[] = $line;
        }
    }
}
echo "\n" . json_encode(['visits' => $visits, 'session_cookies' => $sessionCookies]);
PHP);
    }

    protected function tearDown(): void
    {
        if ($this->projectDir !== '' && is_dir($this->projectDir)) {
            exec('rm -rf ' . escapeshellarg($this->projectDir));
        }
    }

    /** @return array<string, array{0: string}> */
    public static function bridges(): array
    {
        return ['Swoole\Http\Request' => ['swoole'], 'PSR-7 ServerRequest' => ['psr7']];
    }

    /** @return array{visits: list<int|null>, session_cookies: list<string>} */
    private function runWorker(string $kind): array
    {
        $process = proc_open(
            [PHP_BINARY, $this->projectDir . '/worker.php', $kind],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->projectDir,
            [
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                'TMPDIR' => sys_get_temp_dir(),
                'PHP_INI_SCAN_DIR' => (string) getenv('PHP_INI_SCAN_DIR'),
                'TINA4_DEBUG' => 'false',
                'TINA4_SECRET' => 'invoke-cookie-php-secret-0123456789abcdef',
                'TINA4_AUTO_MIGRATE' => 'false',
            ]
        );
        $this->assertIsResource($process);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $lines = preg_split('/\R/', trim((string) $out)) ?: [];
        $result = json_decode((string) end($lines), true);
        $this->assertIsArray($result, "worker produced no result: {$out} {$err}");
        return $result;
    }

    #[DataProvider('bridges')]
    public function testAFirstVisitGetsItsSessionCookieOnTheReturnedResponse(string $kind): void
    {
        if ($kind === 'swoole' && !extension_loaded('openswoole') && !extension_loaded('swoole')) {
            $this->markTestSkipped('[needs:swoole] swoole/openswoole is not loaded — run with PHP_INI_SCAN_DIR=/tmp/confd_nogrpc on the lab');
        }

        $result = $this->runWorker($kind);

        $this->assertCount(1, $result['session_cookies'],
            "{$kind}: the first visit's response must carry exactly one tina4_session Set-Cookie (and the resumed visit none)");
        $this->assertStringContainsString('HttpOnly', $result['session_cookies'][0]);
        $this->assertSame([1, 2], $result['visits'],
            "{$kind}: the second request, carrying that cookie, must resume the same session");
    }
}
