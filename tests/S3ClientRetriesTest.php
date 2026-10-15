<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\Realtime\S3Client;
use Tina4\Realtime\S3Exception;
use Tina4\Realtime\S3ConfigException;

/**
 * #229 parity: the S3 client fails loud on missing credentials, retries a
 * transient failure (429 / any 5xx / a dropped connection) twice at most
 * honouring Retry-After, does NOT retry a final 4xx, and keeps an endpoint's
 * path prefix in front of every request. Proven against a REAL forked socket
 * server that records what the client sends and answers with chosen statuses -
 * nothing in the client is replaced (no mocks). Mirrors the Python master.
 */
final class S3ClientRetriesTest extends TestCase
{
    /**
     * Fork a real recording server that answers each request with the next
     * status (an int, or 'drop' = close mid-request with no answer), runs $fn
     * with the port, and returns the request lines the server saw.
     *
     * @param array<int, int|string> $statuses
     * @return list<string> the "METHOD /path" lines the server recorded
     */
    private function withServer(array $statuses, callable $fn): array
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required for the real forked server');
        }
        $port = FreePort::get();
        $log = tempnam(sys_get_temp_dir(), 's3seen');
        $pid = pcntl_fork();
        if ($pid === 0) {
            $srv = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
            if ($srv === false) {
                exit(1);
            }
            $i = 0;
            while (true) {
                $conn = @stream_socket_accept($srv, 30);
                if ($conn === false) {
                    continue;
                }
                $reqLine = fgets($conn);
                if ($reqLine === false || trim($reqLine) === '') {
                    fclose($conn); // a readiness probe, not a request - don't record
                    continue;
                }
                $bits = explode(' ', trim($reqLine));
                $len = 0;
                while (($h = fgets($conn)) !== false && trim($h) !== '') {
                    if (stripos($h, 'content-length:') === 0) {
                        $len = (int)trim(explode(':', $h, 2)[1]);
                    }
                }
                if ($len > 0) {
                    fread($conn, $len);
                }
                file_put_contents($log, ($bits[0] ?? '') . ' ' . ($bits[1] ?? '') . "\n", FILE_APPEND);
                $next = $statuses[$i] ?? 200;
                $i++;
                if ($next === 'drop') {
                    fclose($conn); // dropped connection: no answer
                    continue;
                }
                $body = ($next >= 200 && $next < 300) ? 'ok' : 'err';
                $resp = "HTTP/1.1 {$next} X\r\nContent-Length: " . strlen($body) . "\r\n";
                if ($next === 429 || $next === 503) {
                    $resp .= "Retry-After: 0\r\n";
                }
                $resp .= "Connection: close\r\n\r\n{$body}";
                fwrite($conn, $resp);
                fclose($conn);
            }
            exit(0);
        }

        // Parent: wait until the server is accepting, run $fn, then reap.
        for ($t = 0; $t < 100; $t++) {
            $probe = @fsockopen('127.0.0.1', $port, $e, $s, 0.1);
            if ($probe) {
                fclose($probe);
                break;
            }
            usleep(20000);
        }
        try {
            $fn($port);
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
        $seen = array_values(array_filter(explode("\n", (string)file_get_contents($log)), 'strlen'));
        @unlink($log);
        return $seen;
    }

    private function client(int $port, ?string $endpoint = null): S3Client
    {
        return new S3Client($endpoint ?? "http://127.0.0.1:{$port}", 'AKID', 'SECRET', 'us-east-1', 5);
    }

    public function testMissingCredentialsRaiseAndSendNothing(): void
    {
        $seen = $this->withServer([], function (int $port): void {
            $c = new S3Client("http://127.0.0.1:{$port}", '', '');
            try {
                $c->putObject('b', 'k', 'x');
                $this->fail('expected S3ConfigException');
            } catch (S3ConfigException $e) {
                $this->assertStringContainsString('TINA4_STORAGE_KEY and TINA4_STORAGE_SECRET', $e->getMessage());
            }
        });
        $this->assertSame([], $seen, 'nothing should reach the server');
    }

    public function testHalfACredentialIsRefusedToo(): void
    {
        foreach ([['', 'S'], ['A', '']] as [$ak, $sk]) {
            $this->expectException(S3ConfigException::class);
            (new S3Client('http://127.0.0.1:1', $ak, $sk))->putObject('b', 'k', 'x');
        }
    }

    /** @return list<array{0:int|string}> */
    public static function transientProvider(): array
    {
        return [[429], [500], [502], [503], [504], ['drop']];
    }

    #[DataProvider('transientProvider')]
    public function testTransientFailureIsRetriedThenSucceeds(int|string $transient): void
    {
        $seen = $this->withServer([$transient, 200], function (int $port): void {
            $this->client($port)->putObject('bucket', 'key', 'x');
        });
        $this->assertCount(2, $seen, "a transient {$transient} should be retried once then succeed");
    }

    public function testGivesUpAfterTwoRetries(): void
    {
        $caught = null;
        $seen = $this->withServer([503, 503, 503, 503], function (int $port) use (&$caught): void {
            try {
                $this->client($port)->putObject('bucket', 'key', 'x');
            } catch (S3Exception $e) {
                $caught = $e;
            }
        });
        $this->assertNotNull($caught);
        $this->assertSame(503, $caught->status);
        $this->assertCount(3, $seen, 'two retries == three attempts');
    }

    /** @return list<array{0:int}> */
    public static function finalProvider(): array
    {
        return [[400], [403], [404]];
    }

    #[DataProvider('finalProvider')]
    public function testFinalAnswerIsNotRetried(int $code): void
    {
        $caught = null;
        $seen = $this->withServer([$code, 200], function (int $port) use (&$caught, $code): void {
            try {
                $this->client($port)->getObject('bucket', 'key');
            } catch (S3Exception $e) {
                $caught = $e;
            }
        });
        $this->assertNotNull($caught);
        $this->assertSame($code, $caught->status);
        $this->assertCount(1, $seen, "a final {$code} must not be retried");
    }

    public function testEndpointPathPrefixIsKept(): void
    {
        $seen = $this->withServer([200], function (int $port): void {
            $this->client($port, "http://127.0.0.1:{$port}/s3pfx")->putObject('bucket', 'obj.png', 'x');
        });
        $this->assertSame('PUT /s3pfx/bucket/obj.png', $seen[0] ?? '');
    }

    public function testPresignedUrlCarriesTheEndpointPathPrefix(): void
    {
        $url = (new S3Client('http://127.0.0.1:9100/s3pfx', 'AKID', 'SECRET'))->presignedGetUrl('bucket', 'obj.png');
        $this->assertStringContainsString(':9100/s3pfx/bucket/obj.png?', $url);
    }
}
