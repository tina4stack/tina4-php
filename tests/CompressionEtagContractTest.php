<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Shared contract suite for feature 40 -- HTTP compression + ETag.
 *
 * Fixture: tina4-documentation/plan/v3/fixtures/compression_etag_contract.json
 * Decisions: CE-DEC-01 (parity -- gzip + dynamic ETag + conditional-GET are a
 * real four-language feature, ported from Python to PHP/Ruby/Node) + CE-DEC-02
 * (one pinned weak static ETag format `W/"<size>-<mtime>"` across the four;
 * Python's 304 now preserves ETag/Last-Modified; If-None-Match matching
 * unified on RFC-7232 weak comparison -- PHP's was already correct and is
 * reused, not reimplemented, by the new dynamic conditional-GET path).
 *
 * NO MOCKS. A REAL `php -S` server (TestServer, the same helper
 * AutocrudContractTest/SwaggerContractTest use) serves a REAL Tina4 app,
 * driven with real sockets (curl) and real Accept-Encoding / If-None-Match /
 * If-Modified-Since request headers. Response headers are captured with
 * CURLOPT_HEADERFUNCTION (no header/body splitting heuristics) and a gzip
 * body is decoded for real with gzdecode().
 */

use PHPUnit\Framework\TestCase;

final class CompressionEtagContractTest extends TestCase
{
    private static TestServer $server;
    private static string $staticDir;
    private static string $staticPath = '/asset.css';
    private static int $staticSize;
    private static int $staticMtime = 1700000000; // a round epoch second -- avoids any rounding ambiguity

    public static function setUpBeforeClass(): void
    {
        $tmp = sys_get_temp_dir() . '/tina4_ce_contract_' . bin2hex(random_bytes(4));
        mkdir($tmp, 0777, true);
        self::$staticDir = $tmp;
        $staticFile = $tmp . '/asset.css';
        file_put_contents(
            $staticFile,
            ".contract-etag-fixture { color: red; }\n" . str_repeat("/* pad */\n", 80)
        );
        touch($staticFile, self::$staticMtime);
        self::$staticSize = filesize($staticFile);

        $app = __DIR__ . '/fixtures/compression_etag_contract_app.php';
        self::$server = TestServer::start($app, [
            'TINA4_PUBLIC_DIR' => self::$staticDir,
            'TINA4_DEBUG' => 'false',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
        @unlink(self::$staticDir . '/asset.css');
        @rmdir(self::$staticDir);
    }

    /**
     * A REAL HTTP round trip over curl -- no in-process shortcut. Headers are
     * captured verbatim (lower-cased names) via CURLOPT_HEADERFUNCTION; the
     * body is returned RAW (curl never auto-decompresses -- CURLOPT_ENCODING
     * is deliberately never set) so a gzip body can be decoded for real.
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function request(string $path, array $headers = []): array
    {
        $responseHeaders = [];
        $ch = curl_init(self::$server->base() . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $line) use (&$responseHeaders) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        });
        $body = curl_exec($ch);
        if ($body === false) {
            $this->fail('curl error: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$status, $responseHeaders, (string) $body];
    }

    // ── 1. compressible_body_over_1kb_gzips_with_vary ──────────────────────

    public function testCompressibleBodyOver1kbGzipsWithVary(): void
    {
        [$status, $headers, $body] = $this->request('/big', ['Accept-Encoding: gzip']);
        $this->assertSame(200, $status);
        $this->assertSame('gzip', $headers['content-encoding'] ?? null);
        $this->assertSame('Accept-Encoding', $headers['vary'] ?? null);
        $decoded = gzdecode($body);
        $this->assertSame(['data' => str_repeat('x', 2000)], json_decode($decoded, true));

        // Negative: WITHOUT the header -> identity.
        [$status2, $headers2, $body2] = $this->request('/big');
        $this->assertSame(200, $status2);
        $this->assertArrayNotHasKey('content-encoding', $headers2);
        $this->assertSame(['data' => str_repeat('x', 2000)], json_decode($body2, true));
    }

    // ── 2. small_or_incompressible_body_not_gzipped ─────────────────────────

    public function testSmallOrIncompressibleBodyNotGzipped(): void
    {
        [$status, $headers, $body] = $this->request('/small', ['Accept-Encoding: gzip']);
        $this->assertSame(200, $status);
        $this->assertArrayNotHasKey('content-encoding', $headers);
        $this->assertSame(['ok' => true], json_decode($body, true));

        [$status2, $headers2, $body2] = $this->request('/binary', ['Accept-Encoding: gzip']);
        $this->assertSame(200, $status2);
        $this->assertArrayNotHasKey('content-encoding', $headers2);
        $this->assertSame(str_repeat('x', 2000), $body2);
    }

    // ── 3. cacheable_response_carries_an_etag ───────────────────────────────

    public function testCacheableResponseCarriesAnEtag(): void
    {
        [$status, $headers] = $this->request('/small');
        $this->assertSame(200, $status);
        $this->assertNotEmpty($headers['etag'] ?? null, 'a 200-with-content must carry an ETag');
    }

    // ── 4. matching_if_none_match_returns_304_preserving_validators ────────

    public function testMatchingIfNoneMatchReturns304PreservingValidators(): void
    {
        // Dynamic response: strong ETag only.
        [, $headers] = $this->request('/small');
        $etag = $headers['etag'];
        [$status2, $headers2, $body2] = $this->request('/small', ["If-None-Match: {$etag}"]);
        $this->assertSame(304, $status2);
        $this->assertSame('', $body2);
        $this->assertSame($etag, $headers2['etag'] ?? null, 'a 304 must echo the ETag');

        // Static response: weak ETag AND Last-Modified -- the 304 must preserve BOTH.
        [, $sheaders] = $this->request(self::$staticPath);
        $setag = $sheaders['etag'];
        $slastModified = $sheaders['last-modified'];
        [$status3, $headers3, $body3] = $this->request(self::$staticPath, ["If-None-Match: {$setag}"]);
        $this->assertSame(304, $status3);
        $this->assertSame('', $body3);
        $this->assertSame($setag, $headers3['etag'] ?? null);
        $this->assertSame($slastModified, $headers3['last-modified'] ?? null, 'a static 304 must echo Last-Modified too');
    }

    // ── 5. rfc7232_weak_list_star_inm_matches ───────────────────────────────

    public function testRfc7232WeakListStarInmMatches(): void
    {
        [, $headers] = $this->request('/small');
        $etag = $headers['etag']; // a STRONG tag, e.g. "a1b2c3d4e5f60718"
        $weakForm = 'W/' . $etag;

        [$statusW] = $this->request('/small', ["If-None-Match: {$weakForm}"]);
        $this->assertSame(304, $statusW, 'a W/-prefixed If-None-Match must weak-match the real ETag');

        [$statusList] = $this->request('/small', ["If-None-Match: \"not-it\", {$weakForm}"]);
        $this->assertSame(304, $statusList, 'a comma-list If-None-Match must match on any candidate');

        [$statusStar] = $this->request('/small', ['If-None-Match: *']);
        $this->assertSame(304, $statusStar, 'If-None-Match: * must always match');

        [$statusMiss] = $this->request('/small', ['If-None-Match: "totally-different"']);
        $this->assertSame(200, $statusMiss, 'a non-matching If-None-Match must serve the body, not 304');
    }

    // ── 6. static_etag_format_identical_across_the_four ─────────────────────

    public function testStaticEtagFormatIdenticalAcrossTheFour(): void
    {
        [$status, $headers] = $this->request(self::$staticPath);
        $this->assertSame(200, $status);
        $expected = sprintf('W/"%d-%d"', self::$staticSize, self::$staticMtime);
        $this->assertSame(
            $expected,
            $headers['etag'] ?? null,
            'the pinned cross-language static ETag format is weak W/"<size>-<mtime>" (decimal, integer-second mtime)'
        );
    }
}
