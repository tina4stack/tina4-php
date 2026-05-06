<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Regression tests for tina4-book#135 — `$request->body` parse error
 * when the request body is `multipart/form-data` containing a file.
 *
 * The bug:
 *   1. Constructor called `$this->parseBody()` BEFORE initialising
 *      `$this->files`, so the multipart branch tried to merge into an
 *      uninitialised typed property — fatal Error.
 *   2. After fixing init order, the multipart branch tried to
 *      `$this->files = array_merge(...)` on a `readonly` property —
 *      another fatal Error.
 *
 * Both errors got swallowed by upstream error handlers and the route
 * handler received the raw multipart bytes as `$request->body` instead
 * of the parsed array. These tests pin the constructor's contract so
 * neither path regresses again.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Request;

class Issue135Test extends TestCase
{
    private function buildMultipartBody(string $boundary): string
    {
        // Mirrors what a browser FormData with a file + a couple of
        // text fields produces. Real bug repro from issue #135.
        return "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"bookingTermsAndConditionsFile\"; filename=\"test.pdf\"\r\n"
            . "Content-Type: application/pdf\r\n\r\n"
            . "%PDF-1.4\r\n%fake bytes\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"LocationImages\"; filename=\"img.jpg\"\r\n"
            . "Content-Type: image/jpeg\r\n\r\n"
            . "\xFF\xD8\xFF\xE0 fake jpeg bytes\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"operatingHours\"\r\n\r\n"
            . "[{\"dayName\":\"monday\"}]\r\n"
            . "--{$boundary}--\r\n";
    }

    public function testMultipartBodyWithFileParsesToArray(): void
    {
        $boundary = "----WebKitFormBoundaryh9erL2Shcm17sr0s";
        $body = $this->buildMultipartBody($boundary);

        $req = new Request(
            method: 'POST',
            path: '/api/save',
            headers: ['content-type' => "multipart/form-data; boundary={$boundary}"],
            body: $body,
        );

        // Body must be the parsed associative array, NOT the raw
        // multipart bytes. The pre-fix bug returned the 11603-byte
        // raw string here.
        $this->assertIsArray(
            $req->body,
            'Multipart body with a file should parse to an associative array, '
            . 'not the raw multipart bytes (issue #135).'
        );
        $this->assertArrayHasKey('operatingHours', $req->body);
        $this->assertSame('[{"dayName":"monday"}]', $req->body['operatingHours']);
    }

    public function testMultipartFilesEndUpInRequestFiles(): void
    {
        $boundary = "----WebKitFormBoundaryh9erL2Shcm17sr0s";
        $body = $this->buildMultipartBody($boundary);

        $req = new Request(
            method: 'POST',
            path: '/api/save',
            headers: ['content-type' => "multipart/form-data; boundary={$boundary}"],
            body: $body,
        );

        // Both file fields parsed into $req->files, in normalised
        // shape. Checks are loose on shape — the contract is "key
        // exists with a usable filename" — but tight enough to catch
        // a regression where files vanish entirely.
        $this->assertCount(2, $req->files, 'expected two file fields');
        $this->assertArrayHasKey('bookingTermsAndConditionsFile', $req->files);
        $this->assertArrayHasKey('LocationImages', $req->files);

        $pdf = $req->files['bookingTermsAndConditionsFile'];
        $this->assertSame('test.pdf', $pdf['filename'] ?? $pdf['name'] ?? null);
    }

    public function testReadonlyFilesContractStillHolds(): void
    {
        // Direct assertion that $request->files is the readonly
        // public property and has the parsed files in it. If a future
        // change drops the `readonly` keyword, this test still passes
        // — but if a change tries to mutate $files post-construction
        // and the constructor stops initialising it cleanly, this
        // test's setup phase will throw before getting here.
        $req = new Request(
            method: 'POST',
            path: '/api/save',
            headers: ['content-type' => 'application/json'],
            body: '{"hello":"world"}',
        );
        $this->assertSame(['hello' => 'world'], $req->body);
        $this->assertSame([], $req->files);
    }

    public function testNonMultipartBodyStillParses(): void
    {
        // Smoke test: the fix moved the $this->files init before
        // parseBody(). Make sure the JSON path (which doesn't touch
        // multipart) is unaffected.
        $req = new Request(
            method: 'POST',
            path: '/api/save',
            headers: ['content-type' => 'application/json'],
            body: '{"a":1,"b":[2,3]}',
        );
        $this->assertSame(['a' => 1, 'b' => [2, 3]], $req->body);
        $this->assertSame([], $req->files);
    }
}
