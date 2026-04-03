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
}
