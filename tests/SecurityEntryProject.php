<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


declare(strict_types=1);

// Global namespace, like FreePort and TestServer: required directly by the
// tests that use it (tina4-python #134 / #137 parity).

/**
 * A REAL throwaway Tina4 project, served through each of the framework's entry
 * points, for the security-header and CSRF parity tests.
 *
 * The project has one HTML route (/page), a /sapi probe naming the SAPI that
 * served it, a route whose middleware refuses with a fresh 403 (/gated), a
 * route that sets its own X-Frame-Options (/framed), one write route secured
 * by default (POST /api/transfer), and an
 * SPA front door at src/public/index.html with its script src/public/app.js. Its
 * index.php picks the entry from how it was launched:
 *
 *   - `php index.php <port>`   -> App::run() in the CLI: Tina4's own socket server
 *   - `php -S ... index.php`   -> App::run() under a web SAPI, which delegates to
 *                                 handle() - the PHP-FPM / Apache / php -S path
 *   - `php invoke.php <json>`  -> App::__invoke(array) - the entry Swoole,
 *                                 RoadRunner and PSR-7 adapters call
 *
 * Nothing here stands in for anything: every response is produced by the
 * framework in a separate php process, over a real socket where the entry has
 * one. This is a fixture builder, not a double.
 */
final class SecurityEntryProject
{
    /** The three headers tina4-python #134 / #137 found missing. */
    public const HEADERS = ['content-security-policy', 'x-content-type-options', 'x-frame-options'];

    public readonly string $dir;

    /** @var list<TestServer> */
    private array $servers = [];

    /**
     * Write the project to a fresh temp directory.
     */
    public function __construct()
    {
        $this->dir = sys_get_temp_dir() . '/tina4-security-entry-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/src/routes', 0777, true);
        mkdir($this->dir . '/src/public', 0777, true);

        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        file_put_contents(
            $this->dir . '/index.php',
            "<?php\nrequire {$autoload};\n"
            . "\$app = new \\Tina4\\App(basePath: __DIR__);\n"
            . "if (PHP_SAPI === 'cli' && isset(\$argv[1])) {\n"
            . "    \$app->run('127.0.0.1', (int) \$argv[1]);\n"
            . "} else {\n"
            . "    \$app->run();\n"
            . "}\n"
        );
        file_put_contents(
            $this->dir . '/invoke.php',
            "<?php\nrequire {$autoload};\n"
            . "\$app = new \\Tina4\\App(basePath: __DIR__);\n"
            . "\$response = \$app(json_decode(\$argv[1], true));\n"
            . "echo json_encode(['status' => \$response->getStatusCode(), 'headers' => array_change_key_case(\$response->getHeaders()), 'body' => \$response->getBody()]);\n"
        );
        file_put_contents(
            $this->dir . '/src/routes/app.php',
            "<?php\n"
            . "\\Tina4\\Router::get('/page', function (\$request, \$response) {\n"
            . "    return \$response->html('<!doctype html><title>route</title><p>hello</p>');\n"
            . "});\n"
            . "\\Tina4\\Router::get('/sapi', function (\$request, \$response) {\n"
            . "    return \$response->json(['sapi' => PHP_SAPI]);\n"
            . "});\n"
            . "class SecurityEntryGate\n{\n"
            . "    public static function beforeGate(\$request, \$response)\n    {\n"
            . "        return (new \\Tina4\\Response())->json(['error' => 'GATED'], 403);\n"
            . "    }\n}\n"
            . "\\Tina4\\Router::get('/gated', function (\$request, \$response) {\n"
            . "    return \$response->json(['reached' => true]);\n"
            . "})->middleware([SecurityEntryGate::class]);\n"
            . "\\Tina4\\Router::get('/framed', function (\$request, \$response) {\n"
            . "    return \$response->header('X-Frame-Options', 'DENY')->html('<p>framed</p>');\n"
            . "});\n"
            . "\\Tina4\\Router::post('/api/transfer', function (\$request, \$response) {\n"
            . "    return \$response->json(['moved' => true]);\n"
            . "});\n"
        );
        file_put_contents(
            $this->dir . '/src/public/index.html',
            "<!doctype html><title>spa</title><div id=\"app\"></div>\n"
        );
        file_put_contents($this->dir . '/src/public/app.js', "document.title = 'spa';\n");
    }

    /**
     * The environment every entry runs with: production mode, a real signing
     * secret, and CSRF switched on or off.
     *
     * @param bool $csrf Whether TINA4_CSRF=true is set
     * @param array<string, string> $extra Further variables (e.g. TINA4_SSO_*)
     * @return array<string, string>
     */
    public function env(bool $csrf, array $extra = []): array
    {
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'TMPDIR' => sys_get_temp_dir(),
            'TINA4_DEBUG' => 'false',
            'TINA4_SECRET' => 'issue-134-php-secret-0123456789abcdef',
            'TINA4_OVERRIDE_CLIENT' => 'true',
            'TINA4_SUPPRESS' => 'true',
            'TINA4_NO_BROWSER' => 'true',
            'TINA4_AUTO_MIGRATE' => 'false',
            'TINA4_CSP' => "default-src 'self'",
        ];
        if ($csrf) {
            $env['TINA4_CSRF'] = 'true';
        }
        return array_merge($env, $extra);
    }

    /**
     * Start the project under a socket entry and return its base URL.
     *
     * @param string $entry 'socket' (App::run in the CLI) or 'web-sapi' (php -S)
     * @param bool $csrf Whether TINA4_CSRF=true is set
     * @param array<string, string> $extra Further environment variables
     * @return string Base URL such as http://127.0.0.1:54321
     */
    public function serve(string $entry, bool $csrf, array $extra = []): string
    {
        $server = $entry === 'socket'
            ? TestServer::startScript($this->dir . '/index.php', [], $this->env($csrf, $extra), $this->dir)
            : TestServer::start($this->dir . '/index.php', $this->env($csrf, $extra), $this->dir);
        $this->servers[] = $server;
        return $server->base();
    }

    /**
     * Send one request over a real socket.
     *
     * @param string $url Absolute URL
     * @param string $method HTTP method
     * @param string $body Request body
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function request(string $url, string $method = 'GET', string $body = ''): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => "Content-Type: application/json\r\nConnection: close\r\n",
            'content' => $body,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 15,
        ]]);
        $responseBody = @file_get_contents($url, false, $context);
        $rawHeaders = $http_response_header ?? [];
        $status = 0;
        $headers = [];
        foreach ($rawHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                $headers = [];
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
            }
        }
        return ['status' => $status, 'headers' => $headers, 'body' => (string) $responseBody];
    }

    /**
     * Dispatch one request through App::__invoke() in a fresh php process.
     *
     * @param array<string, mixed> $request The array form __invoke() accepts
     * @param bool $csrf Whether TINA4_CSRF=true is set
     * @param array<string, string> $extra Further environment variables
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function invoke(array $request, bool $csrf, array $extra = []): array
    {
        $process = proc_open(
            [PHP_BINARY, $this->dir . '/invoke.php', json_encode($request)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
            $this->env($csrf, $extra)
        );
        if (!is_resource($process)) {
            throw new RuntimeException('could not spawn the __invoke() child');
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        // Boot logs to stdout too, so the response is the LAST line.
        $lines = preg_split('/\R/', trim((string) $out)) ?: [];
        $decoded = json_decode((string) end($lines), true);
        if (!is_array($decoded)) {
            throw new RuntimeException("__invoke() child produced no JSON: {$out} {$err}");
        }
        return [
            'status' => (int) $decoded['status'],
            'headers' => array_map('strval', $decoded['headers']),
            'body' => (string) $decoded['body'],
        ];
    }

    /**
     * Stop every server this project started and delete the project.
     */
    public function destroy(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];
        self::removeTree($this->dir);
    }

    private static function removeTree(string $dir): void
    {
        foreach (@scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) && !is_link($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
