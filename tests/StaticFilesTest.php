<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * StaticFiles — comprehensive unit tests for static file serving.
 */

use PHPUnit\Framework\TestCase;
use Tina4\StaticFiles;
use Tina4\Response;

class StaticFilesTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tina4-static-test-' . getmypid() . '-' . uniqid();
        mkdir($this->tempDir . '/src/public/css', 0755, true);
        mkdir($this->tempDir . '/src/public/js', 0755, true);
        mkdir($this->tempDir . '/src/public/images', 0755, true);
        mkdir($this->tempDir . '/src/public/fonts', 0755, true);
        mkdir($this->tempDir . '/src/public/subdir', 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->rmdirRecursive($this->tempDir);
        }
    }

    private function rmdirRecursive(string $dir): void
    {
        $entries = scandir($dir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rmdirRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    // ── Serve file ─────────────────────────────────────────────────

    public function testServeHtmlFile(): void
    {
        file_put_contents($this->tempDir . '/src/public/index.html', '<html>Hello</html>');
        $response = StaticFiles::tryServe('/index.html', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('<html>Hello</html>', $response->getBody());
        $this->assertStringContainsString('text/html', $response->getHeader('Content-Type'));
    }

    public function testServeCssFile(): void
    {
        file_put_contents($this->tempDir . '/src/public/css/style.css', 'body { color: red; }');
        $response = StaticFiles::tryServe('/css/style.css', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertStringContainsString('text/css', $response->getHeader('Content-Type'));
    }

    public function testServeJsFile(): void
    {
        file_put_contents($this->tempDir . '/src/public/js/app.js', 'console.log("hi");');
        $response = StaticFiles::tryServe('/js/app.js', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertStringContainsString('javascript', $response->getHeader('Content-Type'));
    }

    public function testServeJsonFile(): void
    {
        file_put_contents($this->tempDir . '/src/public/data.json', '{"key":"value"}');
        $response = StaticFiles::tryServe('/data.json', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertStringContainsString('application/json', $response->getHeader('Content-Type'));
    }

    public function testServeTxtFile(): void
    {
        file_put_contents($this->tempDir . '/src/public/readme.txt', 'Hello world');
        $response = StaticFiles::tryServe('/readme.txt', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertStringContainsString('text/plain', $response->getHeader('Content-Type'));
    }

    public function testServeSvgFile(): void
    {
        file_put_contents($this->tempDir . '/src/public/images/icon.svg', '<svg></svg>');
        $response = StaticFiles::tryServe('/images/icon.svg', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertStringContainsString('image/svg+xml', $response->getHeader('Content-Type'));
    }

    // ── 404 scenarios ──────────────────────────────────────────────

    public function testNonExistentFileReturnsNull(): void
    {
        $response = StaticFiles::tryServe('/nonexistent.html', $this->tempDir);
        $this->assertNull($response);
    }

    public function testNonExistentDirectoryReturnsNull(): void
    {
        $response = StaticFiles::tryServe('/nope/file.html', $this->tempDir);
        $this->assertNull($response);
    }

    public function testEmptyDirectoryReturnsNull(): void
    {
        $response = StaticFiles::tryServe('/subdir/', $this->tempDir);
        $this->assertNull($response);
    }

    // ── Directory index ────────────────────────────────────────────

    public function testDirectoryServesIndexHtml(): void
    {
        file_put_contents($this->tempDir . '/src/public/subdir/index.html', '<html>Index</html>');
        $response = StaticFiles::tryServe('/subdir', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertEquals('<html>Index</html>', $response->getBody());
    }

    // ── MIME type detection ────────────────────────────────────────

    public function testMimeTypePng(): void
    {
        // Create a minimal valid PNG
        file_put_contents($this->tempDir . '/src/public/images/test.png', 'PNG');
        $response = StaticFiles::tryServe('/images/test.png', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertEquals('image/png', $response->getHeader('Content-Type'));
    }

    public function testMimeTypeJpeg(): void
    {
        file_put_contents($this->tempDir . '/src/public/images/photo.jpg', 'JPEG');
        $response = StaticFiles::tryServe('/images/photo.jpg', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertEquals('image/jpeg', $response->getHeader('Content-Type'));
    }

    public function testMimeTypeJpegAlternateExtension(): void
    {
        file_put_contents($this->tempDir . '/src/public/images/photo.jpeg', 'JPEG');
        $response = StaticFiles::tryServe('/images/photo.jpeg', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertEquals('image/jpeg', $response->getHeader('Content-Type'));
    }

    public function testMimeTypeGif(): void
    {
        file_put_contents($this->tempDir . '/src/public/images/anim.gif', 'GIF');
        $response = StaticFiles::tryServe('/images/anim.gif', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertEquals('image/gif', $response->getHeader('Content-Type'));
    }

    public function testMimeTypeWebp(): void
    {
        file_put_contents($this->tempDir . '/src/public/images/img.webp', 'WEBP');
        $response = StaticFiles::tryServe('/images/img.webp', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertEquals('image/webp', $response->getHeader('Content-Type'));
    }

    public function testMimeTypeIco(): void
    {
        file_put_contents($this->tempDir . '/src/public/favicon.ico', 'ICO');
        $response = StaticFiles::tryServe('/favicon.ico', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertEquals('image/x-icon', $response->getHeader('Content-Type'));
    }

    public function testMimeTypePdf(): void
    {
        file_put_contents($this->tempDir . '/src/public/doc.pdf', 'PDF');
        $response = StaticFiles::tryServe('/doc.pdf', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertEquals('application/pdf', $response->getHeader('Content-Type'));
    }

    public function testMimeTypeZip(): void
    {
        file_put_contents($this->tempDir . '/src/public/archive.zip', 'ZIP');
        $response = StaticFiles::tryServe('/archive.zip', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertEquals('application/zip', $response->getHeader('Content-Type'));
    }

    public function testMimeTypeWoff2(): void
    {
        file_put_contents($this->tempDir . '/src/public/fonts/font.woff2', 'WOFF2');
        $response = StaticFiles::tryServe('/fonts/font.woff2', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertEquals('font/woff2', $response->getHeader('Content-Type'));
    }

    public function testMimeTypeCsv(): void
    {
        file_put_contents($this->tempDir . '/src/public/data.csv', 'a,b,c');
        $response = StaticFiles::tryServe('/data.csv', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertStringContainsString('text/csv', $response->getHeader('Content-Type'));
    }

    public function testMimeTypeXml(): void
    {
        file_put_contents($this->tempDir . '/src/public/feed.xml', '<xml></xml>');
        $response = StaticFiles::tryServe('/feed.xml', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertStringContainsString('application/xml', $response->getHeader('Content-Type'));
    }

    public function testUnknownExtensionGetsOctetStream(): void
    {
        file_put_contents($this->tempDir . '/src/public/file.xyz', 'data');
        $response = StaticFiles::tryServe('/file.xyz', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertEquals('application/octet-stream', $response->getHeader('Content-Type'));
    }

    // ── Directory traversal blocked ────────────────────────────────

    public function testDirectoryTraversalBlocked(): void
    {
        $response = StaticFiles::tryServe('/../../../etc/passwd', $this->tempDir);
        $this->assertNull($response);
    }

    public function testDirectoryTraversalWithDoubleDots(): void
    {
        $response = StaticFiles::tryServe('/css/../../secret.txt', $this->tempDir);
        $this->assertNull($response);
    }

    public function testDirectoryTraversalMiddlePath(): void
    {
        $response = StaticFiles::tryServe('/images/../../../etc/hosts', $this->tempDir);
        $this->assertNull($response);
    }

    // ── PHP files not served ───────────────────────────────────────

    public function testPhpFilesNotServed(): void
    {
        file_put_contents($this->tempDir . '/src/public/evil.php', '<?php echo "hack";');
        $response = StaticFiles::tryServe('/evil.php', $this->tempDir);
        $this->assertNull($response);
    }

    public function testPhpFilesNotServedCaseInsensitive(): void
    {
        file_put_contents($this->tempDir . '/src/public/evil.PHP', '<?php echo "hack";');
        $response = StaticFiles::tryServe('/evil.PHP', $this->tempDir);
        $this->assertNull($response);
    }

    // ── Content-Length header ───────────────────────────────────────

    public function testContentLengthHeader(): void
    {
        $content = 'Hello, world!';
        file_put_contents($this->tempDir . '/src/public/hello.txt', $content);
        $response = StaticFiles::tryServe('/hello.txt', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertEquals((string)strlen($content), $response->getHeader('Content-Length'));
    }

    // ── Root path ──────────────────────────────────────────────────

    public function testRootPathServesFile(): void
    {
        file_put_contents($this->tempDir . '/src/public/robots.txt', 'User-agent: *');
        $response = StaticFiles::tryServe('/robots.txt', $this->tempDir);
        $this->assertNotNull($response);
        $this->assertEquals('User-agent: *', $response->getBody());
    }

    // ── Cache-Control + validators (ETag / Last-Modified) ──────────

    public function testServedAssetCarriesCacheControlAndValidators(): void
    {
        file_put_contents($this->tempDir . '/src/public/app.js', 'console.log(1);');
        $response = StaticFiles::tryServe('/app.js', $this->tempDir);

        $this->assertNotNull($response);
        $this->assertEquals(200, $response->getStatusCode());
        // Browser may cache but must revalidate before use.
        $this->assertEquals('no-cache, must-revalidate', $response->getHeader('Cache-Control'));
        // Both validators present and non-empty.
        $eTag = $response->getHeader('ETag');
        $this->assertNotNull($eTag);
        $this->assertNotSame('', $eTag);
        $lastModified = $response->getHeader('Last-Modified');
        $this->assertNotNull($lastModified);
        $this->assertNotSame('', $lastModified);
        // Weak validator derived from mtime + size, RFC-shaped Last-Modified.
        $this->assertMatchesRegularExpression('#^W/"\d+-\d+"$#', $eTag);
        $this->assertStringEndsWith(' GMT', $lastModified);
    }

    public function testMatchingIfNoneMatchYields304NotModifiedWithNoBody(): void
    {
        file_put_contents($this->tempDir . '/src/public/style.css', 'body{color:red}');

        // First load — grab the validator the server handed out.
        $first = StaticFiles::tryServe('/style.css', $this->tempDir);
        $this->assertNotNull($first);
        $eTag = $first->getHeader('ETag');
        $this->assertNotNull($eTag);

        // Revalidate with the exact same ETag → cheap 304, empty body.
        $second = StaticFiles::tryServe('/style.css', $this->tempDir, $eTag);
        $this->assertNotNull($second);
        $this->assertEquals(304, $second->getStatusCode());
        $this->assertSame('', $second->getBody());
        // 304 still carries the validators + revalidation directive.
        $this->assertEquals('no-cache, must-revalidate', $second->getHeader('Cache-Control'));
        $this->assertEquals($eTag, $second->getHeader('ETag'));
        $this->assertNotNull($second->getHeader('Last-Modified'));
    }

    public function testNonMatchingIfNoneMatchYields200WithBody(): void
    {
        $body = 'body{color:blue}';
        file_put_contents($this->tempDir . '/src/public/other.css', $body);

        $response = StaticFiles::tryServe('/other.css', $this->tempDir, 'W/"0-0"');
        $this->assertNotNull($response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertSame($body, $response->getBody());
    }

    public function testIfNoneMatchWeakComparisonIgnoresWeakPrefix(): void
    {
        file_put_contents($this->tempDir . '/src/public/weak.js', 'x=1;');
        $first = StaticFiles::tryServe('/weak.js', $this->tempDir);
        $this->assertNotNull($first);
        $eTag = $first->getHeader('ETag');           // e.g. W/"1700000000-4"
        $this->assertStringStartsWith('W/', $eTag);

        // Client echoes the STRONG form (no W/ prefix) — weak comparison still matches.
        $strongForm = substr($eTag, 2);
        $second = StaticFiles::tryServe('/weak.js', $this->tempDir, $strongForm);
        $this->assertNotNull($second);
        $this->assertEquals(304, $second->getStatusCode());
    }

    public function testWildcardIfNoneMatchYields304(): void
    {
        file_put_contents($this->tempDir . '/src/public/wild.txt', 'anything');
        $response = StaticFiles::tryServe('/wild.txt', $this->tempDir, '*');
        $this->assertNotNull($response);
        $this->assertEquals(304, $response->getStatusCode());
        $this->assertSame('', $response->getBody());
    }

    public function testIfModifiedSinceAtOrAfterFileYields304(): void
    {
        $file = $this->tempDir . '/src/public/dated.txt';
        file_put_contents($file, 'timed');
        // Pin the modification time so the comparison is deterministic.
        $fixedTime = 1700000000;
        touch($file, $fixedTime);

        $sameInstant = gmdate('D, d M Y H:i:s', $fixedTime) . ' GMT';
        $response = StaticFiles::tryServe('/dated.txt', $this->tempDir, null, $sameInstant);
        $this->assertNotNull($response);
        $this->assertEquals(304, $response->getStatusCode());
        $this->assertSame('', $response->getBody());

        // A later If-Modified-Since is also current → 304.
        $later = gmdate('D, d M Y H:i:s', $fixedTime + 86400) . ' GMT';
        $responseLater = StaticFiles::tryServe('/dated.txt', $this->tempDir, null, $later);
        $this->assertNotNull($responseLater);
        $this->assertEquals(304, $responseLater->getStatusCode());
    }

    public function testStaleIfModifiedSinceYields200WithBody(): void
    {
        $file = $this->tempDir . '/src/public/fresh.txt';
        file_put_contents($file, 'fresh-bytes');
        $fixedTime = 1700000000;
        touch($file, $fixedTime);

        // Client's copy predates the file → it must re-download.
        $earlier = gmdate('D, d M Y H:i:s', $fixedTime - 86400) . ' GMT';
        $response = StaticFiles::tryServe('/fresh.txt', $this->tempDir, null, $earlier);
        $this->assertNotNull($response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertSame('fresh-bytes', $response->getBody());
    }

    public function testIfNoneMatchTakesPrecedenceOverIfModifiedSince(): void
    {
        $file = $this->tempDir . '/src/public/precedence.txt';
        file_put_contents($file, 'precedence-bytes');
        $fixedTime = 1700000000;
        touch($file, $fixedTime);

        // If-None-Match does NOT match; a current If-Modified-Since is ignored
        // (RFC 7232 §6) → the asset is re-sent with 200.
        $current = gmdate('D, d M Y H:i:s', $fixedTime) . ' GMT';
        $response = StaticFiles::tryServe('/precedence.txt', $this->tempDir, 'W/"0-0"', $current);
        $this->assertNotNull($response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertSame('precedence-bytes', $response->getBody());
    }
}
