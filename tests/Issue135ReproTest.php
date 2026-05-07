<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Direct reproduction of Kerneels' May-7 failure report on issue
 * tina4-book#135. The earlier `Issue135Test` proved the constructor's
 * happy path, but he says he's still seeing the raw multipart bytes
 * land in `$request->body` even on 3.12.5. These tests probe the
 * variants that aren't covered above:
 *
 *  - Header value with `Content-Type` (capital) — should still match
 *    the case-insensitive content-type detection
 *  - Header value with extra params (charset=, etc.) before/after boundary
 *  - Body without a final CRLF
 *  - Boundary value containing "+" / "/" (some browsers emit these)
 *  - Realistic ~22 KB body (the size Kerneels reported)
 *  - Files passed via the `files:` constructor arg AND multipart in body
 *    (would the merge collide?)
 */

use PHPUnit\Framework\TestCase;
use Tina4\Request;

class Issue135ReproTest extends TestCase
{
    private function buildMultipart(string $boundary, int $padBytes = 0): string
    {
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"bookingTermsAndConditionsFile\"; filename=\"test.pdf\"\r\n"
            . "Content-Type: application/pdf\r\n\r\n"
            . "%PDF-1.4\r\n%fake bytes\r\n"
            . str_repeat("X", $padBytes)
            . "\r\n--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"operatingHours\"\r\n\r\n"
            . "[{\"dayName\":\"monday\"}]\r\n"
            . "--{$boundary}--\r\n";
        return $body;
    }

    public function testCapitalisedContentTypeHeaderKey(): void
    {
        // Some entry points (proxies, SAPI fallbacks) hand headers in
        // with `Content-Type` rather than the lowercase form. The
        // constructor reads $headers['content-type'] (lowercase); if
        // the caller passes the capitalised form, the detection fails
        // and the body falls through to the rawBody return.
        $boundary = "----WebKitFormBoundary123";
        $body = $this->buildMultipart($boundary);

        $req = new Request(
            method: 'POST',
            path: '/x',
            headers: ['Content-Type' => "multipart/form-data; boundary={$boundary}"],
            body: $body,
        );

        $this->assertIsArray(
            $req->body,
            "Capitalised 'Content-Type' header should still be detected as multipart"
        );
    }

    public function testContentTypeWithExtraParam(): void
    {
        // Some browsers / clients append `; charset=UTF-8` to the
        // content-type even on multipart. Make sure str_contains() in
        // parseBody and parseMultipartBody handles that.
        $boundary = "----WebKitFormBoundary456";
        $body = $this->buildMultipart($boundary);

        $req = new Request(
            method: 'POST',
            path: '/x',
            headers: ['content-type' => "multipart/form-data; charset=UTF-8; boundary={$boundary}"],
            body: $body,
        );

        $this->assertIsArray($req->body, 'multipart with extra params should still parse');
        $this->assertArrayHasKey('operatingHours', $req->body);
    }

    public function testBoundaryWithSpecialChars(): void
    {
        // RFC 2046 allows boundary characters like `+`, `/`, `_`. Some
        // browsers emit these. The parser must not mis-split.
        $boundary = "----Boundary+ab/cd_123";
        $body = $this->buildMultipart($boundary);

        $req = new Request(
            method: 'POST',
            path: '/x',
            headers: ['content-type' => "multipart/form-data; boundary={$boundary}"],
            body: $body,
        );

        $this->assertIsArray($req->body, 'boundary with + / _ should still parse');
    }

    public function testRealisticTwentyTwoKilobyteBody(): void
    {
        // Match Kerneels' reported 22,640-byte body — a real PDF would
        // be significantly larger than the headers + scalar fields.
        $boundary = "----WebKitFormBoundaryyxxYOt2leVIXQlbd";
        $body = $this->buildMultipart($boundary, padBytes: 21000);

        $this->assertGreaterThan(
            21000,
            strlen($body),
            'sanity check: padded body is ~22 KB like the bug report'
        );

        $req = new Request(
            method: 'POST',
            path: '/api/save',
            headers: ['content-type' => "multipart/form-data; boundary={$boundary}"],
            body: $body,
        );

        $this->assertIsArray(
            $req->body,
            'Body length ~22 KB with file part should still parse — Kerneels saw raw bytes here'
        );
        $this->assertArrayHasKey('operatingHours', $req->body);
        $this->assertCount(1, $req->files);
        $this->assertArrayHasKey('bookingTermsAndConditionsFile', $req->files);
    }

    public function testBoundaryQuotedInContentType(): void
    {
        // RFC permits boundary="..." (quoted). Many real clients emit
        // this. The parser must strip the quotes.
        $boundary = "WebKitFormBoundaryQuoted";
        $body = $this->buildMultipart($boundary);

        $req = new Request(
            method: 'POST',
            path: '/x',
            headers: ['content-type' => "multipart/form-data; boundary=\"{$boundary}\""],
            body: $body,
        );

        $this->assertIsArray($req->body, 'quoted boundary should be honoured');
    }

    public function testBodyWithoutTrailingCrlf(): void
    {
        // Some clients omit the trailing CRLF after the close-boundary.
        $boundary = "----WebKitFormBoundaryNoTrail";
        $body = $this->buildMultipart($boundary);
        $body = rtrim($body, "\r\n");  // strip trailing CRLF

        $req = new Request(
            method: 'POST',
            path: '/x',
            headers: ['content-type' => "multipart/form-data; boundary={$boundary}"],
            body: $body,
        );

        $this->assertIsArray(
            $req->body,
            'body missing trailing CRLF should still parse — close-boundary marks end'
        );
    }

    public function testFilesArgAndMultipartBodyDoNotConflict(): void
    {
        // If the caller passes `files:` AND the body contains multipart
        // file parts, the merge in normaliseFiles must not crash.
        $boundary = "----WebKitFormBoundaryMerge";
        $body = $this->buildMultipart($boundary);

        $req = new Request(
            method: 'POST',
            path: '/x',
            headers: ['content-type' => "multipart/form-data; boundary={$boundary}"],
            body: $body,
            files: ['extra' => ['filename' => 'extra.txt', 'content' => 'hi', 'type' => 'text/plain', 'size' => 2]],
        );

        $this->assertIsArray($req->body);
        $this->assertCount(2, $req->files, 'should have both the explicit and multipart file');
    }
}
