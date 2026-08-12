<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * File upload contract (feature 44) - repeated field -> LIST, safe-save,
 * running per-chunk size guard.
 *
 * Shared invariants: tina4-documentation/plan/v3/fixtures/fileupload_contract.json
 * (UP-DEC-02 / UP-DEC-03, OWNER-DECISIONS Batch 4).
 *
 * NO MOCKS: the repeated-field cases parse a REAL multipart body through the real
 * parser; the safe-save cases write to a REAL temp directory and read back what
 * landed (and what did not); the per-chunk cap case starts a REAL socket server
 * and sends a body over a real TCP socket whose ACTUAL length exceeds the cap while
 * its declared Content-Length stays under TINA4_MAX_REQUEST_BODY - so only the
 * running counter (not the declared-length guard) can refuse it.
 *
 * Mutation-proved: revert parseMultipartBody to last-wins and the two-files case
 * goes RED; drop the basename strip in Request::saveUpload and the traversal case
 * goes RED (the escaped file appears); remove the maxUploadSize branch in
 * Server::enforceRequestLimits and the over-limit case is accepted (RED).
 */

use PHPUnit\Framework\TestCase;

class FileUploadContractTest extends TestCase
{
    private const BOUNDARY = '----Tina4FileUploadContract';

    /** @var array<int, array{string,string,string}> $files [name, filename, content] */
    private function multipart(array $files): string
    {
        $body = '';
        foreach ($files as [$name, $filename, $content]) {
            $body .= "--" . self::BOUNDARY . "\r\n"
                . "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n"
                . "Content-Type: application/octet-stream\r\n\r\n"
                . $content . "\r\n";
        }
        return $body . "--" . self::BOUNDARY . "--\r\n";
    }

    // ── UP-MULTIFILE-LOSS: repeated field name -> a LIST ────────────────────

    public function testTwoFilesUnderOneFieldNameArriveAsAList(): void
    {
        $body = $this->multipart([
            ['photos', 'a.txt', 'AAAA-first'],
            ['photos', 'b.txt', 'BBBB-second'],
        ]);
        $parsed = \Tina4\Server::parseMultipartBody($body, 'multipart/form-data; boundary=' . self::BOUNDARY);
        $entry = $parsed['files']['photos'];

        $this->assertIsList($entry, 'two files under one field name must arrive as a list');
        $this->assertCount(2, $entry, 'both files must survive - neither silently dropped');
        $this->assertSame('a.txt', $entry[0]['filename']);
        $this->assertSame('b.txt', $entry[1]['filename']);
        $this->assertSame('AAAA-first', $entry[0]['content']);
        $this->assertSame('BBBB-second', $entry[1]['content']);
    }

    public function testASingleFileStaysASingleDescriptor(): void
    {
        $body = $this->multipart([['avatar', 'solo.txt', 'only-one']]);
        $parsed = \Tina4\Server::parseMultipartBody($body, 'multipart/form-data; boundary=' . self::BOUNDARY);
        $entry = $parsed['files']['avatar'];

        $this->assertArrayHasKey('filename', $entry, 'a single occurrence stays a plain descriptor');
        $this->assertSame('solo.txt', $entry['filename']);
        $this->assertSame('only-one', $entry['content']);
    }

    // ── UP-FILENAME-UNTRUSTED: safe-save confines the write ─────────────────

    public function testSafeSaveWritesATraversalFilenameInsideTheTargetDir(): void
    {
        $root = \TempPath::dir('tina4_safesave_');
        $target = $root . '/uploads';
        mkdir($target, 0755, true);
        $descriptor = ['filename' => '../../evil.txt', 'content' => 'payload', 'type' => 'text/plain'];

        $saved = \Tina4\Request::saveUpload($descriptor, $target);

        // It landed INSIDE the target dir, under the stripped basename ...
        $this->assertSame(realpath($target), realpath(dirname($saved)));
        $this->assertSame('evil.txt', basename($saved));
        $this->assertSame('payload', file_get_contents($saved));
        // ... and NOT at the escaped location the raw name pointed at.
        $this->assertFileDoesNotExist($root . '/evil.txt', 'the traversal escaped the target dir');
    }

    public function testSafeSaveRefusesAnUnusableFilename(): void
    {
        $root = \TempPath::dir('tina4_safesave_neg_');
        mkdir($root . '/uploads', 0755, true);

        $refusedDotDot = false;
        try {
            \Tina4\Request::saveUpload(['filename' => '..', 'content' => 'x'], $root . '/uploads');
        } catch (\InvalidArgumentException) {
            $refusedDotDot = true;
        }
        $refusedNul = false;
        try {
            \Tina4\Request::saveUpload(['filename' => "ok\0.txt", 'content' => 'x'], $root . '/uploads');
        } catch (\InvalidArgumentException) {
            $refusedNul = true;
        }
        $this->assertTrue($refusedDotDot && $refusedNul, 'an unusable name (.. or NUL) must be refused');
    }

    // ── UP-CHUNKED-BYPASS: running per-chunk size guard (413) ────────────────

    /** Body bytes accepted before the running counter answers 413. */
    private const MAX_UPLOAD = 2048;

    /** @var resource|null */
    private $proc = null;
    private int $port = 0;
    private string $appDir = '';

    private function startServer(): void
    {
        $this->appDir = \TempPath::dir('tina4_uploadcap_');
        $this->port = \FreePort::get();
        mkdir($this->appDir . '/src/routes', 0755, true);
        file_put_contents($this->appDir . '/src/routes/upload.php', <<<'PHP'
<?php
// Public upload endpoint: the oversized case never reaches it (the body is
// refused at the socket layer first), but the under-limit control does.
\Tina4\Router::post("/upload", function ($request, $response) {
    return $response("ok", 200);
})->noAuth();
PHP);

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $port = $this->port;
        file_put_contents($this->appDir . '/index.php', <<<PHP
<?php
require '{$autoload}';
\$app = new \\Tina4\\App(__DIR__);
\$app->run('127.0.0.1', {$port});
PHP);

        // TINA4_MAX_UPLOAD_SIZE small drives the RUNNING per-chunk counter;
        // TINA4_MAX_REQUEST_BODY left at its 10MB default so the DECLARED-length
        // guard does NOT fire - the running counter is the only thing that can.
        file_put_contents($this->appDir . '/.env',
            "TINA4_OVERRIDE_CLIENT=true\nTINA4_NO_BROWSER=true\nTINA4_DEBUG=false\n"
            . 'TINA4_MAX_UPLOAD_SIZE=' . self::MAX_UPLOAD . "\n"
        );

        $this->proc = proc_open(
            [PHP_BINARY, 'index.php'],
            [1 => ['file', $this->appDir . '/server.log', 'w'], 2 => ['file', $this->appDir . '/server.log', 'a']],
            $pipes,
            $this->appDir
        );
        $this->assertIsResource($this->proc, 'could not start the test server');

        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            $sock = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.3);
            if ($sock) {
                fclose($sock);
                return;
            }
            usleep(150000);
        }
        $this->fail('the test server never accepted on port ' . $this->port
            . ' - log: ' . @file_get_contents($this->appDir . '/server.log'));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->proc)) {
            $status = proc_get_status($this->proc);
            if (($status['running'] ?? false) && function_exists('posix_kill')) {
                @posix_kill($status['pid'], SIGTERM);
            }
            proc_terminate($this->proc, SIGTERM);
            @proc_close($this->proc);
            $this->proc = null;
        }
    }

    public function testAnOverLimitUploadIsRefusedWith413(): void
    {
        $this->startServer();
        $sock = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $errstr, 5);
        $this->assertIsResource($sock, "could not connect: {$errstr}");

        // ACTUAL body 8x the cap, but the DECLARED Content-Length is honest and
        // well under TINA4_MAX_REQUEST_BODY (10MB), so the declared-length guard
        // passes and only the running per-chunk counter can refuse it.
        $payload = str_repeat('a', self::MAX_UPLOAD * 8);
        $request = "POST /upload HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . 'Content-Length: ' . strlen($payload) . "\r\n"
            . "\r\n" . $payload;
        @fwrite($sock, $request);

        stream_set_timeout($sock, 8);
        $read = stream_get_contents($sock);
        fclose($sock);

        $this->assertNotSame('', (string)$read, 'the server never answered an over-limit body');
        $this->assertMatchesRegularExpression(
            '/ 413 /',
            (string)$read,
            'an over-limit body must be refused 413 by the running counter, got: ' . substr((string)$read, 0, 160)
        );
    }

    public function testABodyUnderTheLimitIsAccepted(): void
    {
        $this->startServer();
        $sock = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $errstr, 5);
        $this->assertIsResource($sock, "could not connect: {$errstr}");

        $body = str_repeat('a', 512);
        $this->assertLessThan(self::MAX_UPLOAD, strlen($body), 'this control must stay under the cap');
        $request = "POST /upload HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . "\r\n" . $body;
        @fwrite($sock, $request);

        stream_set_timeout($sock, 8);
        $read = stream_get_contents($sock);
        fclose($sock);

        $this->assertNotSame('', (string)$read, 'the server never answered a legal body');
        $this->assertDoesNotMatchRegularExpression(
            '/ 413 /',
            (string)$read,
            'a body under the cap must not be refused, got: ' . substr((string)$read, 0, 160)
        );
        $this->assertStringContainsString('200', (string)$read, 'the under-limit upload should be served 200');
    }
}
