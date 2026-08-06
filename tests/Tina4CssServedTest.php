<?php declare(strict_types=1);

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;

/**
 * tina4css_contract.json :: tina4css-is-served-at-one-url-in-all-four
 *
 * tina4css is ONE artefact shipped by four packages. Every framework serves it
 * at /css/tina4.css from its own built-in public directory, and the bytes are
 * identical in all four.
 *
 * MEASURED 2026-08-06 on real servers over real sockets - including a PHP
 * project built by `composer require` whose own src/public/css was empty - all
 * four answered 200 with 35962 bytes for tina4.css and 28472 for
 * tina4.min.css.
 *
 * PHP is the one worth pinning hardest. Python, Ruby and Node keep their copy
 * INSIDE the framework directory (tina4_python/public, lib/tina4/public,
 * packages/core/public); PHP keeps its under src/public. That still ships - a
 * consumer gets vendor/tina4stack/tina4php/src/public/css and PHP serves it
 * identically, measured - but it is the copy a packaging change is most likely
 * to drop.
 *
 * The companion half - that the committed CSS is a current compile of its
 * .scss source, and that the four sources have not drifted apart - is checked
 * by tina4-documentation/scripts/build-tina4css.py --check. It caught a real
 * one: the shipped tina4.min.css was 15 bytes adrift from the current
 * toolchain because its producer, the per-framework SCSS compiler, had been
 * deleted.
 *
 * No mocks: a real child server over a real loopback socket.
 */
class Tina4CssServedTest extends TestCase
{
    private string $appDir = '';
    /** @var resource|null */
    private $proc = null;
    private int $port = 0;

    private function shippedDir(): string
    {
        return dirname(__DIR__) . '/src/public/css';
    }

    protected function setUp(): void
    {
        $this->appDir = \TempPath::dir('tina4_css_');
        @mkdir($this->appDir . '/src/routes', 0777, true);

        // The framework's own index.php starts a server; the scaffolded one is
        // a front controller. Use the repo's, pointed at a scratch app dir.
        copy(dirname(__DIR__) . '/index.php', $this->appDir . '/index.php');
        file_put_contents(
            $this->appDir . '/.env',
            "TINA4_OVERRIDE_CLIENT=true\nTINA4_NO_BROWSER=true\nTINA4_DEBUG=false\n"
        );

        $this->port = $this->freePort();
        putenv('TINA4_PORT=' . $this->port);
        $this->proc = proc_open(
            [PHP_BINARY, 'index.php'],
            [1 => ['file', $this->appDir . '/server.log', 'w'], 2 => ['file', $this->appDir . '/server.log', 'a']],
            $pipes,
            dirname(__DIR__),
            ['TINA4_PORT' => (string)$this->port, 'TINA4_OVERRIDE_CLIENT' => 'true',
             'TINA4_NO_BROWSER' => 'true', 'TINA4_DEBUG' => 'false', 'PATH' => getenv('PATH')]
        );

        $deadline = microtime(true) + 45;
        while (microtime(true) < $deadline) {
            $sock = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $e, $s, 1);
            if (is_resource($sock)) {
                fclose($sock);
                return;
            }
            usleep(200000);
        }
        $this->fail('server never bound the port: ' . @file_get_contents($this->appDir . '/server.log'));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->proc)) {
            $status = proc_get_status($this->proc);
            if ($status['running'] ?? false) {
                proc_terminate($this->proc, SIGTERM);
            }
            proc_close($this->proc);
        }
    }

    private function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        return (int)substr($name, strrpos($name, ':') + 1);
    }

    /** @return array{0:int,1:string,2:string} status, content-type, body */
    private function get(string $path): array
    {
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
        $body = @file_get_contents("http://127.0.0.1:{$this->port}{$path}", false, $ctx);
        $status = 0;
        $ctype = '';
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int)$m[1];
            }
            if (stripos($h, 'content-type:') === 0) {
                $ctype = trim(substr($h, 13));
            }
        }
        return [$status, $ctype, (string)$body];
    }

    public function testTina4cssIsServedAtTheCanonicalUrl(): void
    {
        [$status, $ctype, $body] = $this->get('/css/tina4.css');
        $this->assertSame(200, $status, 'expected 200 for /css/tina4.css');
        $this->assertStringContainsString('text/css', $ctype, "expected text/css, got '{$ctype}'");
        // A real stylesheet, not an error page that happened to return 200.
        $this->assertStringContainsString('.container', $body, 'served CSS has no .container rule');
    }

    public function testTheMinifiedBuildIsServedAtTheCanonicalUrl(): void
    {
        [$status, $ctype, $minified] = $this->get('/css/tina4.min.css');
        $this->assertSame(200, $status, 'expected 200 for /css/tina4.min.css');
        $this->assertStringContainsString('text/css', $ctype, "expected text/css, got '{$ctype}'");
        [, , $full] = $this->get('/css/tina4.css');
        $this->assertLessThan(
            strlen($full),
            strlen($minified),
            'the minified build (' . strlen($minified) . 'B) is not smaller than the full one ('
            . strlen($full) . 'B) - it is probably a copy'
        );
    }

    public function testTheServedBytesAreTheShippedFileByteForByte(): void
    {
        [, , $served] = $this->get('/css/tina4.css');
        $onDisk = (string)file_get_contents($this->shippedDir() . '/tina4.css');
        // Byte equality, not a size check: a truncated or half-written asset
        // still has a plausible length.
        $this->assertSame(
            $onDisk,
            $served,
            'served ' . strlen($served) . ' bytes but the shipped file holds ' . strlen($onDisk)
        );
    }
}
