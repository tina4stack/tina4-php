<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Tests for multipart file upload parsing via the Request class.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Request;

class FileUploadTest extends TestCase
{
    /** Absolute path to the framework logo used as a real upload payload. */
    private string $logoPath;

    /** Raw SVG content read once for all tests. */
    private string $logoContent;

    protected function setUp(): void
    {
        $this->logoPath = __DIR__ . '/../src/public/images/logo.svg';
        $this->assertFileExists($this->logoPath, 'logo.svg fixture must exist');
        $this->logoContent = file_get_contents($this->logoPath);
    }

    // ── 1. Single file upload — correct filename, type, size ──────────

    public function testSingleFileUpload(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'tina4upload_');
        file_put_contents($tmpFile, $this->logoContent);

        $fakeFiles = [
            'logo' => [
                'name'     => 'logo.svg',
                'type'     => 'image/svg+xml',
                'tmp_name' => $tmpFile,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($tmpFile),
            ],
        ];

        $request = new Request(
            method: 'POST',
            path: '/upload',
            body: [],
            files: $fakeFiles,
        );

        $this->assertArrayHasKey('logo', $request->files, 'File field "logo" should be in $request->files');
        $this->assertSame('logo.svg', $request->files['logo']['filename']);
        $this->assertSame('image/svg+xml', $request->files['logo']['type']);
        $this->assertSame(filesize($tmpFile), $request->files['logo']['size']);

        @unlink($tmpFile);
    }

    // ── 2. Content is raw binary, not base64 ──────────────────────────

    public function testFileContentIsRawBinaryNotBase64(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'tina4upload_');
        file_put_contents($tmpFile, $this->logoContent);

        $fakeFiles = [
            'logo' => [
                'name'     => 'logo.svg',
                'type'     => 'image/svg+xml',
                'tmp_name' => $tmpFile,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($tmpFile),
            ],
        ];

        $request = new Request(
            method: 'POST',
            path: '/upload',
            files: $fakeFiles,
        );

        $content = $request->files['logo']['content'];

        // Raw SVG starts with '<svg' (or with an XML declaration); base64 would not.
        $this->assertSame($this->logoContent, $content, 'File content should be raw binary, not base64-encoded');

        // Double-check: base64_decode of the content should NOT round-trip
        // back to the same value (proving it was not base64 to begin with).
        $this->assertNotSame(
            $content,
            base64_encode($content),
            'Content and its base64 form must differ (raw != encoded)'
        );

        @unlink($tmpFile);
    }

    // ── 3. Non-file fields go to body, files go to files ──────────────

    public function testFormFieldsAndFilesSeparated(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'tina4upload_');
        file_put_contents($tmpFile, $this->logoContent);

        $bodyFields = ['title' => 'Company Logo', 'category' => 'branding'];

        $fakeFiles = [
            'attachment' => [
                'name'     => 'logo.svg',
                'type'     => 'image/svg+xml',
                'tmp_name' => $tmpFile,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($tmpFile),
            ],
        ];

        $request = new Request(
            method: 'POST',
            path: '/upload',
            body: $bodyFields,
            files: $fakeFiles,
        );

        // Body should contain only form fields
        $this->assertSame($bodyFields, $request->body, 'Non-file fields should stay in $request->body');

        // Files should not leak into body
        $this->assertArrayNotHasKey('attachment', $request->body ?? [], 'File data must not appear in body');

        // Files array should contain the uploaded file
        $this->assertArrayHasKey('attachment', $request->files, 'File should be in $request->files');
        $this->assertSame('logo.svg', $request->files['attachment']['filename']);

        @unlink($tmpFile);
    }

    // ── 4. Multiple files under different field names ──────────────────

    public function testMultipleFilesUnderDifferentFields(): void
    {
        $tmpLogo = tempnam(sys_get_temp_dir(), 'tina4upload_');
        file_put_contents($tmpLogo, $this->logoContent);

        // Create a second distinct file (plain text) to prove independence
        $tmpReadme = tempnam(sys_get_temp_dir(), 'tina4upload_');
        $readmeContent = 'This is a readme file for testing.';
        file_put_contents($tmpReadme, $readmeContent);

        $fakeFiles = [
            'logo' => [
                'name'     => 'logo.svg',
                'type'     => 'image/svg+xml',
                'tmp_name' => $tmpLogo,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($tmpLogo),
            ],
            'readme' => [
                'name'     => 'README.txt',
                'type'     => 'text/plain',
                'tmp_name' => $tmpReadme,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($tmpReadme),
            ],
        ];

        $request = new Request(
            method: 'POST',
            path: '/upload',
            files: $fakeFiles,
        );

        // Both fields must be present
        $this->assertCount(2, $request->files, 'Should have exactly 2 file entries');
        $this->assertArrayHasKey('logo', $request->files);
        $this->assertArrayHasKey('readme', $request->files);

        // Verify each file has the correct metadata
        $this->assertSame('logo.svg', $request->files['logo']['filename']);
        $this->assertSame('image/svg+xml', $request->files['logo']['type']);
        $this->assertSame(filesize($tmpLogo), $request->files['logo']['size']);

        $this->assertSame('README.txt', $request->files['readme']['filename']);
        $this->assertSame('text/plain', $request->files['readme']['type']);
        $this->assertSame(filesize($tmpReadme), $request->files['readme']['size']);

        // Content should match the original files
        $this->assertSame($this->logoContent, $request->files['logo']['content']);
        $this->assertSame($readmeContent, $request->files['readme']['content']);

        @unlink($tmpLogo);
        @unlink($tmpReadme);
    }

    // ── 5. Content contains valid SVG ─────────────────────────────────

    public function testUploadedContentIsValidSvg(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'tina4upload_');
        file_put_contents($tmpFile, $this->logoContent);

        $fakeFiles = [
            'image' => [
                'name'     => 'logo.svg',
                'type'     => 'image/svg+xml',
                'tmp_name' => $tmpFile,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($tmpFile),
            ],
        ];

        $request = new Request(
            method: 'POST',
            path: '/upload',
            files: $fakeFiles,
        );

        $content = $request->files['image']['content'];

        // Must start with '<svg' (the logo has no XML declaration)
        $this->assertStringStartsWith('<svg', $content, 'SVG content should start with <svg');

        // Must end with the closing tag
        $this->assertStringEndsWith('</svg>', trim($content), 'SVG content should end with </svg>');

        // Parse as XML to prove it is well-formed
        $previousErrors = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($content);
        $xmlErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        $this->assertNotFalse($doc, 'SVG content should parse as valid XML');
        $this->assertEmpty($xmlErrors, 'SVG parsing should produce no XML errors');

        // Root element should be <svg>
        $this->assertSame('svg', $doc->getName(), 'Root XML element should be "svg"');

        @unlink($tmpFile);
    }
}
