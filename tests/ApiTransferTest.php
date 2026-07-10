<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * REAL-server tests for the four Api HTTP-client transfer features ported from
 * the Python master (commit 394eb6b): multipart upload(), streaming download(),
 * the injectable transport seam, and the opt-in cookie jar — plus the redirect
 * cross-origin Authorization/Cookie strip.
 *
 * NO MOCKS. Two genuine PHP built-in servers are booted on two free ports
 * (= two distinct origins for the cross-origin redirect test), and every case
 * is a real socket round-trip against them. The transport-seam test injects a
 * transport that itself performs REAL socket I/O (it is not a canned fake) —
 * the seam is only proven by replacing the network with another REAL network
 * call, per the framework no-mock rule.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Api;

class ApiTransferTest extends TestCase
{
    /** @var array{0: mixed, 1: int} proc handle + port for origin A */
    private static array $serverA;
    /** @var array{0: mixed, 1: int} proc handle + port for origin B */
    private static array $serverB;
    private static string $baseA;
    private static string $baseB;
    private static string $tmpDir;

    public static function setUpBeforeClass(): void
    {
        $router = __DIR__ . '/fixtures/api_transfer_server.php';
        self::$serverA = self::bootServer($router);
        self::$serverB = self::bootServer($router);
        self::$baseA = 'http://127.0.0.1:' . self::$serverA[1];
        self::$baseB = 'http://127.0.0.1:' . self::$serverB[1];
        self::$tmpDir = sys_get_temp_dir() . '/tina4_api_transfer_' . bin2hex(random_bytes(4));
        mkdir(self::$tmpDir, 0777, true);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$serverA, self::$serverB] as $srv) {
            if (is_resource($srv[0])) {
                proc_terminate($srv[0]);
                proc_close($srv[0]);
            }
        }
        // best-effort temp cleanup
        foreach (glob(self::$tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir(self::$tmpDir);
    }

    /** Reserve a free localhost TCP port. */
    private static function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($sock, false);
        $port = (int)substr($name, strrpos($name, ':') + 1);
        fclose($sock);
        return $port;
    }

    /** Boot a real `php -S` server on a free port and wait until it accepts. */
    private static function bootServer(string $router): array
    {
        $port = self::freePort();
        $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router);
        $proc = proc_open(
            $cmd,
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        for ($i = 0; $i < 200; $i++) {
            $client = @stream_socket_client("tcp://127.0.0.1:$port", $e1, $e2, 0.1);
            if ($client) {
                fclose($client);
                return [$proc, $port];
            }
            usleep(25000);
        }
        throw new \RuntimeException("built-in server did not start on port $port");
    }

    // ── upload() ────────────────────────────────────────────────────────────

    public function testUploadRealFileFromDiskSendsExactBytesAndFields(): void
    {
        $bytes = random_bytes(4096) . "\n-- trailing text part --\n";
        $filePath = self::$tmpDir . '/avatar.png';
        file_put_contents($filePath, $bytes);

        $api = new Api(self::$baseB);
        $result = $api->upload('/upload', filePath: $filePath, extraFields: ['user_id' => '42', 'note' => 'hi']);

        $this->assertSame(200, $result['http_code']);
        $this->assertNull($result['error']);
        $body = $result['body'];
        $this->assertTrue($body['has_file'], 'server received a file part');
        $this->assertSame('avatar.png', $body['file_name']);
        $this->assertSame('image/png', $body['file_type'], 'part Content-Type guessed from .png');
        $this->assertSame($bytes, base64_decode($body['file_b64']), 'exact bytes round-tripped');
        $this->assertSame('42', $body['fields']['user_id']);
        $this->assertSame('hi', $body['fields']['note']);
        $this->assertStringContainsString('multipart/form-data; boundary=----Tina4Boundary', $body['content_type']);
    }

    public function testUploadInMemoryBytesVariant(): void
    {
        $payload = '{"hello":"world"}';
        $api = new Api(self::$baseB);
        $result = $api->upload('/upload', fileBytes: $payload, filename: 'note.txt', fieldName: 'file');

        $this->assertSame(200, $result['http_code']);
        $body = $result['body'];
        $this->assertTrue($body['has_file']);
        $this->assertSame('note.txt', $body['file_name']);
        $this->assertSame('text/plain', $body['file_type']);
        $this->assertSame($payload, base64_decode($body['file_b64']));
    }

    public function testUploadMissingFileReturnsCleanErrorNothingSent(): void
    {
        $api = new Api(self::$baseB);
        $result = $api->upload('/upload', filePath: self::$tmpDir . '/does-not-exist.bin');

        $this->assertNull($result['http_code'], 'NEGATIVE: nothing was sent');
        $this->assertNull($result['body']);
        $this->assertSame([], $result['headers']);
        $this->assertStringContainsString('file not found', $result['error']);
    }

    public function testUploadNoSourceReturnsCleanError(): void
    {
        $api = new Api(self::$baseB);
        $result = $api->upload('/upload');

        $this->assertNull($result['http_code']);
        $this->assertStringContainsString('requires filePath or fileBytes', $result['error']);
    }

    // ── download() ────────────────────────────────────────────────────────────

    public function testDownloadStreamsMultiMegabyteBodyToFile(): void
    {
        $size = 3 * 1024 * 1024; // 3 MB
        $dest = self::$tmpDir . '/big.bin';

        $api = new Api(self::$baseB);
        $result = $api->download('/download', $dest, ['size' => $size]);

        $this->assertSame(200, $result['http_code']);
        $this->assertNull($result['error']);
        $this->assertSame($dest, $result['path']);
        $this->assertArrayNotHasKey('body', $result, 'download NEVER returns a body key');

        $this->assertFileExists($dest);
        $this->assertSame($size, filesize($dest));

        // Rebuild the deterministic pattern the server sent and compare hashes.
        $pattern = '';
        for ($i = 0; $i < 256; $i++) {
            $pattern .= chr($i);
        }
        $expected = str_repeat($pattern, intdiv($size, 256));
        $this->assertSame(md5($expected), md5_file($dest), 'streamed bytes match the source exactly');
    }

    public function testDownloadMissingDestReturnsErrorNoFile(): void
    {
        $api = new Api(self::$baseB);
        $result = $api->download('/download', null);

        $this->assertNull($result['http_code']);
        $this->assertNull($result['path']);
        $this->assertStringContainsString('requires destPath', $result['error']);
        $this->assertArrayNotHasKey('body', $result);
    }

    public function testDownloadHttpErrorWritesNoFileAndPathNull(): void
    {
        $dest = self::$tmpDir . '/should-not-exist.bin';
        $api = new Api(self::$baseB);
        $result = $api->download('/nope-404', $dest);

        $this->assertSame(404, $result['http_code']);
        $this->assertNull($result['path'], 'no path on an error status');
        $this->assertFileDoesNotExist($dest, 'the destination is NOT written on error');
    }

    // ── redirect: cross-origin auth/cookie strip ───────────────────────────────

    public function testCrossOriginRedirectDropsAuthorizationAndCookie(): void
    {
        // Populate the cookie jar on origin A, then 302 to origin B (different port).
        $api = new Api(self::$baseA, 'Bearer SECRETTOKEN', cookies: true);
        $api->get('/set-cookie');

        $target = self::$baseB . '/echo-headers';
        $result = $api->get('/redirect', ['to' => $target, 'code' => 302]);

        $this->assertSame(200, $result['http_code']);
        $received = $result['body']; // headers origin B actually received
        $this->assertArrayNotHasKey('authorization', $received, 'Authorization stripped cross-origin');
        $this->assertArrayNotHasKey('cookie', $received, 'Cookie stripped cross-origin');
    }

    public function testSameOriginRedirectKeepsAuthorizationAndCookie(): void
    {
        $api = new Api(self::$baseA, 'Bearer SECRETTOKEN', cookies: true);
        $api->get('/set-cookie');

        $target = self::$baseA . '/echo-headers'; // same origin
        $result = $api->get('/redirect', ['to' => $target, 'code' => 302]);

        $this->assertSame(200, $result['http_code']);
        $received = $result['body'];
        $this->assertSame('Bearer SECRETTOKEN', $received['authorization'] ?? null, 'Authorization kept same-origin');
        $this->assertArrayHasKey('cookie', $received, 'Cookie kept same-origin');
        $this->assertStringContainsString('sessionid=xyz789', $received['cookie']);
        $this->assertStringContainsString('theme=dark', $received['cookie']);
    }

    // ── cookie jar ──────────────────────────────────────────────────────────

    public function testCookieJarCapturesAndResendsCookies(): void
    {
        $api = new Api(self::$baseB, cookies: true);
        $set = $api->get('/set-cookie');
        $this->assertSame(200, $set['http_code']);

        $result = $api->get('/read-cookie');
        $cookie = $result['body']['cookie'];
        $this->assertNotNull($cookie, 'Cookie header sent on the follow-up request');
        // last-write-wins: sessionid=first was overwritten by sessionid=xyz789
        $this->assertStringContainsString('sessionid=xyz789', $cookie);
        $this->assertStringNotContainsString('sessionid=first', $cookie);
        $this->assertStringContainsString('theme=dark', $cookie);
    }

    public function testCookieJarOffByDefaultSendsNoCookie(): void
    {
        $api = new Api(self::$baseB); // cookies default false
        $api->get('/set-cookie');
        $result = $api->get('/read-cookie');

        $this->assertNull($result['body']['cookie'], 'NEGATIVE: no Cookie sent when jar disabled');
    }

    // ── transport seam ────────────────────────────────────────────────────────

    public function testInjectedTransportReplacesNetworkWithRealIo(): void
    {
        // The transport does REAL socket I/O to the LIVE server B — it is not a
        // canned fake. The Api base URL points at a DEAD port, so a 200 result
        // can ONLY come from the transport having replaced the network call.
        $live = self::$baseB;
        $calls = 0;
        $transport = function (string $method, string $url, array $headers, ?string $body, int $timeout) use ($live, &$calls) {
            $calls++;
            $context = stream_context_create(['http' => ['method' => $method, 'timeout' => $timeout, 'ignore_errors' => true]]);
            $raw = @file_get_contents($live . '/json', false, $context);
            $code = null;
            foreach (($http_response_header ?? []) as $line) {
                if (preg_match('#^HTTP/[\d.]+\s+(\d{3})#', $line, $m)) {
                    $code = (int)$m[1];
                }
            }
            return [
                'http_code' => $code,
                'body' => $raw === false ? null : json_decode($raw, true),
                'headers' => ['X-Transport' => 'real-io'],
                'error' => $raw === false ? 'transport failed' : null,
            ];
        };

        $api = new Api('http://127.0.0.1:1', transport: $transport); // dead base URL
        $result = $api->get('/ignored-by-transport');

        $this->assertSame(1, $calls, 'the injected transport was invoked');
        $this->assertSame(200, $result['http_code'], 'result came from the transport, not the dead base URL');
        $this->assertSame('world', $result['body']['hello']);
        $this->assertSame('real-io', $result['headers']['X-Transport']);
        $this->assertNull($result['error']);
    }

    public function testDefaultNullTransportUsesRealNetwork(): void
    {
        // No transport injected -> the real stream-wrapper network path is used.
        $api = new Api(self::$baseB);
        $result = $api->get('/json');

        $this->assertSame(200, $result['http_code']);
        $this->assertSame('world', $result['body']['hello']);
        $this->assertSame('GET', $result['body']['method']);
    }

    public function testInjectedTransportDownloadWritesBufferedBody(): void
    {
        // A transport can't stream, so download() writes its buffered body out.
        $live = self::$baseB;
        $transport = function (string $method, string $url, array $headers, ?string $body, int $timeout) use ($live) {
            $context = stream_context_create(['http' => ['method' => $method, 'timeout' => $timeout, 'ignore_errors' => true]]);
            $raw = @file_get_contents($live . '/json', false, $context);
            return ['http_code' => 200, 'body' => $raw, 'headers' => [], 'error' => null];
        };
        $dest = self::$tmpDir . '/transport-download.json';
        $api = new Api('http://127.0.0.1:1', transport: $transport);
        $result = $api->download('/ignored', $dest);

        $this->assertSame(200, $result['http_code']);
        $this->assertSame($dest, $result['path']);
        $this->assertArrayNotHasKey('body', $result);
        $this->assertFileExists($dest);
        $decoded = json_decode(file_get_contents($dest), true);
        $this->assertSame('world', $decoded['hello']);
    }

    // ── backward-compat sanity on the real path ─────────────────────────────

    public function testStandardGetStillWorksOnRealServer(): void
    {
        $api = new Api(self::$baseB);
        $result = $api->get('/json');
        $this->assertSame(200, $result['http_code']);
        $this->assertIsArray($result['headers']);
        $this->assertArrayHasKey('body', $result);
    }
}
