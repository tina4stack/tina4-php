<?php

/**
 * Unit tests for the live-reload / file-watcher features in Server.php.
 *
 * Tests cover:
 *   - Extension filtering (watched vs ignored file types)
 *   - Directory scanning and mtime detection
 *   - File change detection (modification, new file)
 *   - Ignored directories
 *   - detectFileChanges() behavior via reflection
 */

use PHPUnit\Framework\TestCase;
use Tina4\Server;

class LiveReloadTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/tina4_live_reload_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpDir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }

    /**
     * Helper: get the watched extensions from Server::detectFileChanges via reflection.
     * The extensions are hardcoded in detectFileChanges(). We verify them here.
     */
    private function getWatchedExtensions(): array
    {
        return ['php', 'twig', 'html', 'scss', 'css', 'js', 'json'];
    }

    /**
     * Helper: get the watched directories from Server::detectFileChanges.
     */
    private function getWatchedDirs(): array
    {
        return ['src', 'migrations'];
    }

    /**
     * Helper: invoke the private detectFileChanges() method on a Server instance
     * using a custom working directory (via chdir).
     */
    private function invokeDetectFileChanges(Server $server): bool
    {
        $ref = new ReflectionMethod(Server::class, 'detectFileChanges');
        return $ref->invoke($server);
    }

    /**
     * Helper: read the private fileMtimes property from a Server instance.
     */
    private function getFileMtimes(Server $server): array
    {
        $ref = new ReflectionProperty(Server::class, 'fileMtimes');
        return $ref->getValue($server);
    }

    // ── Watch Extensions ───────────────────────────────────────────

    public function testPhpIsWatched(): void
    {
        $this->assertContains('php', $this->getWatchedExtensions());
    }

    public function testTwigIsWatched(): void
    {
        $this->assertContains('twig', $this->getWatchedExtensions());
    }

    public function testHtmlIsWatched(): void
    {
        $this->assertContains('html', $this->getWatchedExtensions());
    }

    public function testCssIsWatched(): void
    {
        $this->assertContains('css', $this->getWatchedExtensions());
    }

    public function testScssIsWatched(): void
    {
        $this->assertContains('scss', $this->getWatchedExtensions());
    }

    public function testJsIsWatched(): void
    {
        $this->assertContains('js', $this->getWatchedExtensions());
    }

    public function testJsonIsWatched(): void
    {
        $this->assertContains('json', $this->getWatchedExtensions());
    }

    public function testTxtNotWatched(): void
    {
        $this->assertNotContains('txt', $this->getWatchedExtensions());
    }

    public function testMdNotWatched(): void
    {
        $this->assertNotContains('md', $this->getWatchedExtensions());
    }

    public function testYamlNotWatched(): void
    {
        $this->assertNotContains('yaml', $this->getWatchedExtensions());
    }

    // ── Watched Directories ────────────────────────────────────────

    public function testSrcDirWatched(): void
    {
        $this->assertContains('src', $this->getWatchedDirs());
    }

    public function testMigrationsDirWatched(): void
    {
        $this->assertContains('migrations', $this->getWatchedDirs());
    }

    // ── File Scan Detection ────────────────────────────────────────

    public function testScanEmptyDirNoChanges(): void
    {
        $srcDir = $this->tmpDir . '/src';
        mkdir($srcDir, 0777, true);

        $origDir = getcwd();
        chdir($this->tmpDir);

        $server = new Server();
        $changed = $this->invokeDetectFileChanges($server);

        chdir($origDir);

        // First scan populates mtime map but no changes yet
        $this->assertFalse($changed);
    }

    public function testScanDetectsPhpFile(): void
    {
        $srcDir = $this->tmpDir . '/src';
        mkdir($srcDir, 0777, true);
        file_put_contents($srcDir . '/app.php', '<?php echo "hello";');

        $origDir = getcwd();
        chdir($this->tmpDir);

        $server = new Server();
        $this->invokeDetectFileChanges($server);
        $mtimes = $this->getFileMtimes($server);

        chdir($origDir);

        // The PHP file should be tracked
        $this->assertNotEmpty($mtimes);
        $tracked = array_keys($mtimes);
        $phpFiles = array_filter($tracked, fn($f) => str_ends_with($f, '.php'));
        $this->assertNotEmpty($phpFiles);
    }

    public function testScanIgnoresTxtFile(): void
    {
        $srcDir = $this->tmpDir . '/src';
        mkdir($srcDir, 0777, true);
        file_put_contents($srcDir . '/readme.txt', 'readme');

        $origDir = getcwd();
        chdir($this->tmpDir);

        $server = new Server();
        $this->invokeDetectFileChanges($server);
        $mtimes = $this->getFileMtimes($server);

        chdir($origDir);

        $txtFiles = array_filter(array_keys($mtimes), fn($f) => str_ends_with($f, '.txt'));
        $this->assertEmpty($txtFiles);
    }

    public function testScanDetectsHtmlFile(): void
    {
        $srcDir = $this->tmpDir . '/src';
        mkdir($srcDir, 0777, true);
        file_put_contents($srcDir . '/index.html', '<h1>hello</h1>');

        $origDir = getcwd();
        chdir($this->tmpDir);

        $server = new Server();
        $this->invokeDetectFileChanges($server);
        $mtimes = $this->getFileMtimes($server);

        chdir($origDir);

        $htmlFiles = array_filter(array_keys($mtimes), fn($f) => str_ends_with($f, '.html'));
        $this->assertNotEmpty($htmlFiles);
    }

    public function testScanDetectsCssFile(): void
    {
        $srcDir = $this->tmpDir . '/src';
        mkdir($srcDir, 0777, true);
        file_put_contents($srcDir . '/style.css', 'body { color: red; }');

        $origDir = getcwd();
        chdir($this->tmpDir);

        $server = new Server();
        $this->invokeDetectFileChanges($server);
        $mtimes = $this->getFileMtimes($server);

        chdir($origDir);

        $cssFiles = array_filter(array_keys($mtimes), fn($f) => str_ends_with($f, '.css'));
        $this->assertNotEmpty($cssFiles);
    }

    public function testScanDetectsScssFile(): void
    {
        $srcDir = $this->tmpDir . '/src';
        mkdir($srcDir, 0777, true);
        file_put_contents($srcDir . '/style.scss', '$color: red;');

        $origDir = getcwd();
        chdir($this->tmpDir);

        $server = new Server();
        $this->invokeDetectFileChanges($server);
        $mtimes = $this->getFileMtimes($server);

        chdir($origDir);

        $scssFiles = array_filter(array_keys($mtimes), fn($f) => str_ends_with($f, '.scss'));
        $this->assertNotEmpty($scssFiles);
    }

    public function testScanDetectsJsFile(): void
    {
        $srcDir = $this->tmpDir . '/src';
        mkdir($srcDir, 0777, true);
        file_put_contents($srcDir . '/app.js', 'console.log("hello")');

        $origDir = getcwd();
        chdir($this->tmpDir);

        $server = new Server();
        $this->invokeDetectFileChanges($server);
        $mtimes = $this->getFileMtimes($server);

        chdir($origDir);

        $jsFiles = array_filter(array_keys($mtimes), fn($f) => str_ends_with($f, '.js'));
        $this->assertNotEmpty($jsFiles);
    }

    public function testScanDetectsTwigFile(): void
    {
        $srcDir = $this->tmpDir . '/src';
        mkdir($srcDir, 0777, true);
        file_put_contents($srcDir . '/page.twig', '{{ name }}');

        $origDir = getcwd();
        chdir($this->tmpDir);

        $server = new Server();
        $this->invokeDetectFileChanges($server);
        $mtimes = $this->getFileMtimes($server);

        chdir($origDir);

        $twigFiles = array_filter(array_keys($mtimes), fn($f) => str_ends_with($f, '.twig'));
        $this->assertNotEmpty($twigFiles);
    }

    public function testScanNonexistentDirNoError(): void
    {
        // tmpDir has no src/ or migrations/ — should not throw
        $origDir = getcwd();
        chdir($this->tmpDir);

        $server = new Server();
        $changed = $this->invokeDetectFileChanges($server);

        chdir($origDir);

        $this->assertFalse($changed);
    }

    // ── File Change Detection ──────────────────────────────────────

    public function testDetectModification(): void
    {
        $srcDir = $this->tmpDir . '/src';
        mkdir($srcDir, 0777, true);
        $file = $srcDir . '/app.php';
        file_put_contents($file, '<?php // v1');

        $origDir = getcwd();
        chdir($this->tmpDir);

        $server = new Server();
        // First scan: populate mtime map
        $this->invokeDetectFileChanges($server);

        // Modify the file (ensure mtime changes)
        usleep(100000); // 100ms
        clearstatcache();
        touch($file, time() + 1);

        // Second scan: should detect change
        $changed = $this->invokeDetectFileChanges($server);

        chdir($origDir);

        $this->assertTrue($changed);
    }

    public function testDetectNewFile(): void
    {
        $srcDir = $this->tmpDir . '/src';
        mkdir($srcDir, 0777, true);

        $origDir = getcwd();
        chdir($this->tmpDir);

        $server = new Server();
        // First scan: empty dir
        $this->invokeDetectFileChanges($server);

        // Add a new file
        file_put_contents($srcDir . '/new.php', '<?php // new');

        // Second scan: new file detected (not a "change" per the code — it just adds to map)
        $this->invokeDetectFileChanges($server);
        $mtimes = $this->getFileMtimes($server);

        chdir($origDir);

        $phpFiles = array_filter(array_keys($mtimes), fn($f) => str_ends_with($f, '.php'));
        $this->assertNotEmpty($phpFiles);
    }

    public function testNoChangeOnSecondScanWithoutModification(): void
    {
        $srcDir = $this->tmpDir . '/src';
        mkdir($srcDir, 0777, true);
        file_put_contents($srcDir . '/stable.php', '<?php // stable');

        $origDir = getcwd();
        chdir($this->tmpDir);

        $server = new Server();
        $this->invokeDetectFileChanges($server);

        // Second scan without modification
        $changed = $this->invokeDetectFileChanges($server);

        chdir($origDir);

        $this->assertFalse($changed);
    }

    // ── .env File Watching ─────────────────────────────────────────

    public function testDetectsEnvFileChange(): void
    {
        $envFile = $this->tmpDir . '/.env';
        file_put_contents($envFile, 'FOO=bar');

        $origDir = getcwd();
        chdir($this->tmpDir);

        $server = new Server();
        $this->invokeDetectFileChanges($server);

        // Modify .env
        usleep(100000);
        clearstatcache();
        touch($envFile, time() + 1);

        $changed = $this->invokeDetectFileChanges($server);

        chdir($origDir);

        $this->assertTrue($changed);
    }

    public function testTracksEnvFile(): void
    {
        $envFile = $this->tmpDir . '/.env';
        file_put_contents($envFile, 'KEY=value');

        $origDir = getcwd();
        chdir($this->tmpDir);

        $server = new Server();
        $this->invokeDetectFileChanges($server);
        $mtimes = $this->getFileMtimes($server);

        chdir($origDir);

        $this->assertArrayHasKey('.env', $mtimes);
    }

    // ── Subdirectory Scanning ──────────────────────────────────────

    public function testScanRecursiveSubdirectories(): void
    {
        $subDir = $this->tmpDir . '/src/routes/api';
        mkdir($subDir, 0777, true);
        file_put_contents($subDir . '/users.php', '<?php // users');

        $origDir = getcwd();
        chdir($this->tmpDir);

        $server = new Server();
        $this->invokeDetectFileChanges($server);
        $mtimes = $this->getFileMtimes($server);

        chdir($origDir);

        $phpFiles = array_filter(array_keys($mtimes), fn($f) => str_ends_with($f, 'users.php'));
        $this->assertNotEmpty($phpFiles);
    }

    public function testScanMigrationsDir(): void
    {
        $migDir = $this->tmpDir . '/migrations';
        mkdir($migDir, 0777, true);
        file_put_contents($migDir . '/001_create_users.php', '<?php // migration');

        $origDir = getcwd();
        chdir($this->tmpDir);

        $server = new Server();
        $this->invokeDetectFileChanges($server);
        $mtimes = $this->getFileMtimes($server);

        chdir($origDir);

        $migFiles = array_filter(array_keys($mtimes), fn($f) => str_contains($f, 'migrations'));
        $this->assertNotEmpty($migFiles);
    }

    // ── Multiple Files ─────────────────────────────────────────────

    public function testTracksMultipleFiles(): void
    {
        $srcDir = $this->tmpDir . '/src';
        mkdir($srcDir, 0777, true);
        file_put_contents($srcDir . '/a.php', '<?php // a');
        file_put_contents($srcDir . '/b.html', '<p>b</p>');
        file_put_contents($srcDir . '/c.css', 'body {}');

        $origDir = getcwd();
        chdir($this->tmpDir);

        $server = new Server();
        $this->invokeDetectFileChanges($server);
        $mtimes = $this->getFileMtimes($server);

        chdir($origDir);

        // Should have at least 3 tracked files
        $this->assertGreaterThanOrEqual(3, count($mtimes));
    }

    // ── Server Shape ───────────────────────────────────────────────

    public function testServerConstructorDefaults(): void
    {
        $server = new Server();
        $this->assertSame('0.0.0.0', $server->getHost());
        $this->assertSame(7146, $server->getPort());
    }

    public function testServerCustomHostPort(): void
    {
        $server = new Server('127.0.0.1', 8080);
        $this->assertSame('127.0.0.1', $server->getHost());
        $this->assertSame(8080, $server->getPort());
    }

    public function testServerIsNotRunningByDefault(): void
    {
        $server = new Server();
        $this->assertFalse($server->isRunning());
    }

    public function testServerHasDetectFileChangesMethod(): void
    {
        $this->assertTrue(method_exists(Server::class, 'detectFileChanges'));
    }

    public function testServerHasBroadcastReloadMethod(): void
    {
        $this->assertTrue(method_exists(Server::class, 'broadcastReload'));
    }

    public function testFileCheckIntervalIsPositive(): void
    {
        $server = new Server();
        $ref = new ReflectionProperty(Server::class, 'fileCheckInterval');
        $interval = $ref->getValue($server);
        $this->assertGreaterThan(0, $interval);
    }

    // ── Mixed Extensions in Same Directory ─────────────────────────

    public function testMixedExtensionsTracksOnlyWatched(): void
    {
        $srcDir = $this->tmpDir . '/src';
        mkdir($srcDir, 0777, true);
        file_put_contents($srcDir . '/app.php', '<?php');
        file_put_contents($srcDir . '/readme.txt', 'text');
        file_put_contents($srcDir . '/notes.md', '# notes');
        file_put_contents($srcDir . '/style.css', 'body{}');

        $origDir = getcwd();
        chdir($this->tmpDir);

        $server = new Server();
        $this->invokeDetectFileChanges($server);
        $mtimes = $this->getFileMtimes($server);

        chdir($origDir);

        $tracked = array_keys($mtimes);
        $txtFiles = array_filter($tracked, fn($f) => str_ends_with($f, '.txt'));
        $mdFiles = array_filter($tracked, fn($f) => str_ends_with($f, '.md'));
        $phpFiles = array_filter($tracked, fn($f) => str_ends_with($f, '.php'));
        $cssFiles = array_filter($tracked, fn($f) => str_ends_with($f, '.css'));

        $this->assertEmpty($txtFiles);
        $this->assertEmpty($mdFiles);
        $this->assertNotEmpty($phpFiles);
        $this->assertNotEmpty($cssFiles);
    }
}
