<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Regression test for tina4-book#139 — File Upload Bug.
 *
 * Reporter: Kerneels94 (Cornelius) — 2026-05-19
 *
 * Bug: WebSocket::parseHttpHeaders() called explode("\r\n", $data) on the
 * entire raw HTTP request — headers + body together — and then iterated
 * every line scanning for ':'. Multipart body parts have their own
 * `Content-Type: <mime>` headers; those lines matched the parser and
 * overwrote the real request `Content-Type: multipart/form-data; boundary=...`
 * with whatever the last body part's content type was.
 *
 * In Server::handleHttp() the multipart-routing branch tests
 * `str_contains($contentType, 'multipart/form-data')`, so when content-type
 * had been corrupted to (for example) `application/pdf`, that branch was
 * skipped, $parsedFiles was never set, and $request->files came out empty.
 * File uploads were silently lost on the stream-socket server.
 *
 * Fix: stop the parser at the first blank line (\r\n\r\n) — the
 * RFC 9112 §2.2 boundary between headers and body — before splitting
 * into lines. One-line change in Tina4/WebSocket.php.
 */

use PHPUnit\Framework\TestCase;
use Tina4\WebSocket;
use Tina4\Server;

class BookIssue139Test extends TestCase
{
    /**
     * Build a raw multipart HTTP request with one file part whose
     * body-part Content-Type would (under the buggy parser) overwrite
     * the request-level Content-Type.
     */
    private function buildMultipartRequest(string $boundary, string $partMime, string $partContent): string
    {
        $body = ""
            . "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"doc.pdf\"\r\n"
            . "Content-Type: $partMime\r\n"
            . "\r\n"
            . $partContent . "\r\n"
            . "--$boundary--\r\n";

        $headers = ""
            . "POST /api/upload HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary=$boundary\r\n"
            . "Content-Length: " . strlen($body) . "\r\n"
            . "\r\n";

        return $headers . $body;
    }

    // ── 1. Headers stop at \r\n\r\n — body-part Content-Type doesn't leak ─

    public function testParserStopsAtHeaderBodyBoundary(): void
    {
        $boundary = '----TestBoundary12345';
        $raw = $this->buildMultipartRequest($boundary, 'application/pdf', "%PDF-1.4\nfake pdf payload\n");

        $headers = WebSocket::parseHttpHeaders($raw);

        $this->assertSame('POST', $headers['_method'] ?? null);
        $this->assertSame('/api/upload', $headers['_path'] ?? null);
        $this->assertSame(
            "multipart/form-data; boundary=$boundary",
            $headers['content-type'] ?? null,
            'Request-level Content-Type must NOT be overwritten by body-part Content-Type'
        );
    }

    // ── 2. Multiple body parts — none of their headers leak through ──────

    public function testMultipleBodyPartsDoNotPolluteHeaders(): void
    {
        $boundary = '----MultiPart987';
        $body = ""
            . "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"title\"\r\n"
            . "\r\n"
            . "My document\r\n"
            . "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"a.pdf\"\r\n"
            . "Content-Type: application/pdf\r\n"
            . "Content-Transfer-Encoding: binary\r\n"
            . "\r\n"
            . "fake pdf bytes\r\n"
            . "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"thumb\"; filename=\"a.png\"\r\n"
            . "Content-Type: image/png\r\n"
            . "\r\n"
            . "\x89PNG\r\n\x1a\nfake png\r\n"
            . "--$boundary--\r\n";

        $raw = ""
            . "POST /api/upload HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary=$boundary\r\n"
            . "Content-Length: " . strlen($body) . "\r\n"
            . "X-Request-Id: abc123\r\n"
            . "\r\n"
            . $body;

        $headers = WebSocket::parseHttpHeaders($raw);

        $this->assertSame(
            "multipart/form-data; boundary=$boundary",
            $headers['content-type'] ?? null,
            'Multiple body-part Content-Type lines must not overwrite request Content-Type'
        );
        $this->assertSame('abc123', $headers['x-request-id'] ?? null, 'Real request headers must survive');
        $this->assertArrayNotHasKey('content-transfer-encoding', $headers, 'Body-part headers must not be hoisted into the headers map');
    }

    // ── 3. Plain non-multipart requests still parse normally ─────────────

    public function testNonMultipartRequestsUnchanged(): void
    {
        $raw = ""
            . "GET /api/health HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Accept: application/json\r\n"
            . "\r\n";

        $headers = WebSocket::parseHttpHeaders($raw);

        $this->assertSame('GET', $headers['_method']);
        $this->assertSame('/api/health', $headers['_path']);
        $this->assertSame('localhost', $headers['host']);
        $this->assertSame('application/json', $headers['accept']);
    }

    // ── 4. Request with no body (no \r\n\r\n) still parses ──────────────

    public function testHeadersOnlyNoBodyBoundary(): void
    {
        // Pathological — no trailing CRLFCRLF. Parser must still work.
        $raw = "GET / HTTP/1.1\r\nHost: localhost\r\nUser-Agent: tina4-test";

        $headers = WebSocket::parseHttpHeaders($raw);

        $this->assertSame('GET', $headers['_method']);
        $this->assertSame('/', $headers['_path']);
        $this->assertSame('localhost', $headers['host']);
        $this->assertSame('tina4-test', $headers['user-agent']);
    }

    // ── 5. Header value containing a colon (Host with port) ─────────────
    // Guards against an over-eager "split on first colon" rewrite that
    // truncates legitimate header values.

    public function testHeaderValueWithEmbeddedColonPreserved(): void
    {
        $raw = ""
            . "GET /api/data HTTP/1.1\r\n"
            . "Host: localhost:8080\r\n"
            . "X-Forwarded-For: 10.0.0.1, 10.0.0.2\r\n"
            . "Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.foo.bar\r\n"
            . "\r\n";

        $headers = WebSocket::parseHttpHeaders($raw);

        $this->assertSame('localhost:8080', $headers['host'] ?? null, 'Port must survive the parse');
        $this->assertSame('10.0.0.1, 10.0.0.2', $headers['x-forwarded-for'] ?? null);
        $this->assertSame('Bearer eyJhbGciOiJIUzI1NiJ9.foo.bar', $headers['authorization'] ?? null);
    }

    // ── 6. Binary body containing \r\n\r\n must not move the boundary ────
    // If a future change uses strrpos() instead of strpos(), or scans the
    // whole request for \r\n\r\n, body bytes that happen to contain
    // \r\n\r\n will steal the split. The boundary is the FIRST \r\n\r\n.

    public function testBinaryBodyContainingCRLFCRLFDoesNotMoveBoundary(): void
    {
        $boundary = '----BinaryBodyTest';
        // Body content deliberately contains \r\n\r\n in the middle of the
        // file bytes. The parser must still cut headers at the FIRST blank
        // line — the one that ends the request headers, not one inside the
        // file content.
        $fileBytes = "first chunk\r\n\r\nsecond chunk after blank line\r\n";
        $body = ""
            . "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"a.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . "\r\n"
            . $fileBytes . "\r\n"
            . "--$boundary--\r\n";

        $raw = ""
            . "POST /upload HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary=$boundary\r\n"
            . "Content-Length: " . strlen($body) . "\r\n"
            . "\r\n"
            . $body;

        $headers = WebSocket::parseHttpHeaders($raw);

        $this->assertSame(
            "multipart/form-data; boundary=$boundary",
            $headers['content-type'] ?? null,
            'Parser must cut on the first \r\n\r\n, not one buried in the body'
        );
        $this->assertSame('localhost', $headers['host'] ?? null);

        // And the multipart extractor must still pull the file out intact
        $headerEnd = strpos($raw, "\r\n\r\n");
        $extractedBody = substr($raw, $headerEnd + 4);
        $parsed = Server::parseMultipartBody($extractedBody, $headers['content-type']);

        $this->assertArrayHasKey('file', $parsed['files']);
        $this->assertSame($fileBytes, $parsed['files']['file']['content'], 'Binary content with embedded \r\n\r\n must round-trip exactly');
    }

    // ── 7. Empty-body request (Content-Length: 0, body present as \r\n\r\n) ─

    public function testEmptyBodyRequestHeadersIntact(): void
    {
        $raw = ""
            . "POST /api/ping HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: 0\r\n"
            . "\r\n";

        $headers = WebSocket::parseHttpHeaders($raw);

        $this->assertSame('POST', $headers['_method']);
        $this->assertSame('/api/ping', $headers['_path']);
        $this->assertSame('application/json', $headers['content-type'] ?? null);
        $this->assertSame('0', $headers['content-length'] ?? null);
    }

    // ── 8. Repro of the EXACT failure mode reported in tina4-book#139 ────
    // Before the fix: the body-part `Content-Type: application/pdf` line
    // would overwrite the request's `Content-Type: multipart/form-data; ...`
    // and Server::handleHttp's str_contains() multipart check would fail.
    // After the fix: the body-part header is unreachable.

    public function testIssue139ExactReproContentTypeNotOverwrittenByBodyPart(): void
    {
        $boundary = '----XYZ';
        $raw = ""
            . "POST /api/upload HTTP/1.1\r\n"
            . "Content-Type: multipart/form-data; boundary=$boundary\r\n"
            . "\r\n"
            . "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"doc.pdf\"\r\n"
            . "Content-Type: application/pdf\r\n"
            . "\r\n"
            . "fake-binary"
            . "\r\n--$boundary--\r\n";

        $headers = WebSocket::parseHttpHeaders($raw);

        // The exact regression bar: the str_contains() multipart check
        // in Server::handleHttp must see the real Content-Type.
        $this->assertTrue(
            str_contains($headers['content-type'] ?? '', 'multipart/form-data'),
            'Server::handleHttp str_contains(multipart) check must pass — '
            . 'this is the exact line that broke file uploads before the fix'
        );
        $this->assertStringNotContainsString(
            'application/pdf',
            $headers['content-type'] ?? '',
            'Body-part application/pdf must never appear as the request Content-Type'
        );
    }

    // ── 9. End-to-end: multipart body → parseMultipartBody picks up files ─

    public function testEndToEndMultipartFileExtraction(): void
    {
        $boundary = '----TestE2E789';
        $pdfBytes = "%PDF-1.4\nfake pdf body\n";
        $raw = $this->buildMultipartRequest($boundary, 'application/pdf', $pdfBytes);

        // Simulate what Server::handleHttp does
        $headers = WebSocket::parseHttpHeaders($raw);
        $contentType = $headers['content-type'] ?? '';

        $this->assertStringContainsString('multipart/form-data', $contentType, 'Content-Type must reach the multipart branch');

        // Body extraction (same logic as Server.php line 465-466)
        $headerEnd = strpos($raw, "\r\n\r\n");
        $body = $headerEnd !== false ? substr($raw, $headerEnd + 4) : '';

        $parsed = Server::parseMultipartBody($body, $contentType);

        $this->assertArrayHasKey('file', $parsed['files'], 'File field must be extracted');
        $this->assertSame('doc.pdf', $parsed['files']['file']['filename']);
        $this->assertSame('application/pdf', $parsed['files']['file']['type']);
        $this->assertSame($pdfBytes, $parsed['files']['file']['content'], 'File content must be exact raw bytes');
        $this->assertSame(strlen($pdfBytes), $parsed['files']['file']['size']);
    }
}
