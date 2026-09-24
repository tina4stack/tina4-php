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
 * Regression lock-in for tina4-python#143 and #144 across the family (ADR-0072).
 *
 * #144: a Content-Type set with $response->header() is THE Content-Type. PHP
 * stored header('Content-Type', ...) under the same key $response($data) then
 * overwrote with a detected type, so the developer's image/png went out as
 * text/plain; header('content-type', ...) used a second key, so the server
 * wrote BOTH lines.
 *
 * #143: a setting in .env applies. PHP already loads .env in App's constructor,
 * before the server reads its limits; these cases lock that in beside the other
 * three frameworks. Request's own cap, though, turned a bad value into 0 and
 * refused every request with a body.
 *
 * NO MOCKS: one real server process booted by App::run() from a project whose
 * .env carries the settings (the outer environment is scrubbed of them), real
 * sockets, and the response head read raw so a repeated header is visible.
 */

use PHPUnit\Framework\TestCase;

class DotenvSettingsAndContentTypeTest extends TestCase
{
    private const UPLOAD_LIMIT = 1000;
    private const DOTENV_HEALTH_PATH = '/healthz-from-dotenv';

    private static string $appDir = '';
    /** @var resource|null */
    private static $proc = null;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        self::$appDir = \TempPath::dir('tina4_dotenv_settings_');
        self::$port = \FreePort::get();
        mkdir(self::$appDir . '/src/routes', 0755, true);
        file_put_contents(self::$appDir . '/src/routes/content_type.php', <<<'PHP'
<?php
\Tina4\Router::post("/upload", function ($request, $response) {
    return $response("OK", 200);
})->noAuth();
\Tina4\Router::get("/content-type/header-with-bytes", function ($request, $response) {
    $response->header("Content-Type", "image/png");
    return $response(hex2bin("89504e470d0a1a0a"));
});
\Tina4\Router::get("/content-type/lowercase-header", function ($request, $response) {
    $response->header("content-type", "image/png");
    return $response(hex2bin("89504e470d0a1a0a"));
});
\Tina4\Router::get("/content-type/header-with-string", function ($request, $response) {
    $response->header("Content-Type", "text/csv");
    return $response("a,b");
});
\Tina4\Router::get("/content-type/argument-after-header", function ($request, $response) {
    $response->header("Content-Type", "image/png");
    return $response(hex2bin("89504e470d0a1a0a"), 200, "image/gif");
});
\Tina4\Router::get("/content-type/detected", function ($request, $response) {
    return $response("plain words");
});
PHP);

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $port = self::$port;
        file_put_contents(self::$appDir . '/index.php', <<<PHP
<?php
require '{$autoload}';
\$app = new \\Tina4\\App(__DIR__);
\$app->run('127.0.0.1', {$port});
PHP);

        file_put_contents(self::$appDir . '/.env',
            "TINA4_OVERRIDE_CLIENT=true\nTINA4_NO_BROWSER=true\nTINA4_DEBUG=false\n"
            . 'TINA4_MAX_UPLOAD_SIZE=' . self::UPLOAD_LIMIT . "\n"
            . 'TINA4_HEALTH_PATH=' . self::DOTENV_HEALTH_PATH . "\n"
        );

        // The settings under test must come from .env alone.
        $environment = getenv();
        foreach (['TINA4_MAX_UPLOAD_SIZE', 'TINA4_HEALTH_PATH', 'TINA4_ENV_FILE', 'TINA4_MAX_REQUEST_BODY'] as $name) {
            unset($environment[$name]);
        }
        $environment['TINA4_NO_BROWSER'] = 'true';

        self::$proc = proc_open(
            [PHP_BINARY, 'index.php'],
            [1 => ['file', self::$appDir . '/server.log', 'w'], 2 => ['file', self::$appDir . '/server.log', 'a']],
            $pipes,
            self::$appDir,
            $environment
        );
        if (!is_resource(self::$proc)) {
            self::fail('could not start the test server');
        }

        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            $sock = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.3);
            if ($sock) {
                fclose($sock);
                return;
            }
            usleep(150000);
        }
        self::fail('the test server never accepted on port ' . self::$port
            . ' - log: ' . @file_get_contents(self::$appDir . '/server.log'));
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$proc)) {
            $status = proc_get_status(self::$proc);
            if (($status['running'] ?? false) && function_exists('posix_kill')) {
                @posix_kill($status['pid'], SIGTERM);
            }
            proc_terminate(self::$proc, SIGTERM);
            @proc_close(self::$proc);
            self::$proc = null;
        }
    }

    /**
     * One real request over a raw socket.
     *
     * @return array{0: int, 1: list<string>} the status and EVERY Content-Type value
     */
    private function exchange(string $method, string $path, string $body = ''): array
    {
        $sock = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 5);
        $this->assertIsResource($sock, "could not connect: {$errstr}");
        stream_set_timeout($sock, 10);
        $head = "{$method} {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n";
        if ($body !== '') {
            $head .= "Content-Type: application/octet-stream\r\nContent-Length: " . strlen($body) . "\r\n";
        }
        fwrite($sock, $head . "\r\n" . $body);
        $raw = stream_get_contents($sock);
        fclose($sock);

        [$responseHead] = explode("\r\n\r\n", (string)$raw, 2);
        $lines = explode("\r\n", $responseHead);
        $status = (int)(explode(' ', $lines[0])[1] ?? 0);
        $contentTypes = [];
        foreach (array_slice($lines, 1) as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            if (strtolower(trim($name)) === 'content-type') {
                $contentTypes[] = trim($value);
            }
        }
        return [$status, $contentTypes];
    }

    // ── #144: a Content-Type set with header() is THE Content-Type ─────────

    public function testHeaderContentTypeReplacesTheDetectedType(): void
    {
        $this->assertSame([200, ['image/png']], $this->exchange('GET', '/content-type/header-with-bytes'));
    }

    public function testALowercaseContentTypeHeaderIsTheSameHeader(): void
    {
        $this->assertSame([200, ['image/png']], $this->exchange('GET', '/content-type/lowercase-header'));
    }

    public function testHeaderContentTypeSurvivesAStringBody(): void
    {
        $this->assertSame([200, ['text/csv']], $this->exchange('GET', '/content-type/header-with-string'));
    }

    public function testAnExplicitContentTypeArgumentWinsOverTheHeader(): void
    {
        $this->assertSame([200, ['image/gif']], $this->exchange('GET', '/content-type/argument-after-header'));
    }

    public function testWithoutAHeaderTheDetectedTypeIsUsed(): void
    {
        [$status, $contentTypes] = $this->exchange('GET', '/content-type/detected');
        $this->assertSame(200, $status);
        $this->assertCount(1, $contentTypes);
        $this->assertStringStartsWith('text/plain', strtolower($contentTypes[0]));
    }

    // ── #143: a setting in .env applies ────────────────────────────────────

    public function testMaxUploadSizeFromDotenvIsEnforced(): void
    {
        [$status] = $this->exchange('POST', '/upload', str_repeat('x', self::UPLOAD_LIMIT * 5));
        $this->assertSame(413, $status);
    }

    public function testABodyUnderTheDotenvLimitIsAccepted(): void
    {
        [$status] = $this->exchange('POST', '/upload', str_repeat('x', intdiv(self::UPLOAD_LIMIT, 2)));
        $this->assertSame(200, $status);
    }

    public function testHealthPathFromDotenvIsServed(): void
    {
        [$status] = $this->exchange('GET', self::DOTENV_HEALTH_PATH);
        $this->assertSame(200, $status);
    }

    // ── the limit value itself ─────────────────────────────────────────────

    /** Whether a Request declaring $contentLength body bytes is refused under $limit. */
    private function refusedUnder(string $limit, int $contentLength): bool
    {
        $previousEnv = $_ENV['TINA4_MAX_UPLOAD_SIZE'] ?? null;
        $previousProcess = getenv('TINA4_MAX_UPLOAD_SIZE');
        $_ENV['TINA4_MAX_UPLOAD_SIZE'] = $limit;
        putenv('TINA4_MAX_UPLOAD_SIZE=' . $limit);
        try {
            new \Tina4\Request(method: 'POST', path: '/upload', headers: ['content-length' => (string)$contentLength]);
            return false;
        } catch (\RuntimeException $refused) {
            $this->assertStringContainsString('TINA4_MAX_UPLOAD_SIZE', $refused->getMessage());
            return true;
        } finally {
            if ($previousEnv === null) {
                unset($_ENV['TINA4_MAX_UPLOAD_SIZE']);
            } else {
                $_ENV['TINA4_MAX_UPLOAD_SIZE'] = $previousEnv;
            }
            putenv($previousProcess === false ? 'TINA4_MAX_UPLOAD_SIZE' : 'TINA4_MAX_UPLOAD_SIZE=' . $previousProcess);
            http_response_code(200);
        }
    }

    public function testMaxUploadSizeFollowsTheEnvironment(): void
    {
        $this->assertTrue($this->refusedUnder('2048', 3000));
        $this->assertFalse($this->refusedUnder('4096', 3000));
    }

    public function testABadMaxUploadSizeFallsBackToTheDefault(): void
    {
        foreach (['ten megabytes', '-5', '0'] as $bad) {
            $this->assertFalse($this->refusedUnder($bad, 10), "a 10-byte body was refused under TINA4_MAX_UPLOAD_SIZE={$bad}");
            $this->assertTrue($this->refusedUnder($bad, 10485761), "the 10MB default did not apply under TINA4_MAX_UPLOAD_SIZE={$bad}");
        }
    }
}
