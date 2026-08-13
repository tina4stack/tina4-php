<?php

/**
 * Behavioural tests for the live-reload / file-watcher in Server.php.
 *
 * Every test exercises the REAL private detectFileChanges() scanner on a real
 * temporary working directory: it creates files on disk, runs the scanner, and
 * asserts the OBSERVABLE outcome — whether the scanner reports a change and
 * which files end up in the tracked mtime map. There are no hardcoded copies of
 * the watched-extension / watched-directory lists: the source itself decides
 * what is watched, and the assertions read that decision back through behaviour.
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
     * Run the private detectFileChanges() against the temp working directory.
     * Returns the bool the scanner reports (true = a tracked file's mtime moved).
     */
    private function detect(Server $server): bool
    {
        $ref = new ReflectionMethod(Server::class, 'detectFileChanges');
        $origDir = getcwd();
        chdir($this->tmpDir);
        try {
            return $ref->invoke($server);
        } finally {
            chdir($origDir);
        }
    }

    /** Read back the private fileMtimes tracking map after a scan. */
    private function trackedFiles(Server $server): array
    {
        $ref = new ReflectionProperty(Server::class, 'fileMtimes');
        return array_keys($ref->getValue($server));
    }

    /** True if any tracked path ends with the given suffix. */
    private function tracksSuffix(Server $server, string $suffix): bool
    {
        foreach ($this->trackedFiles($server) as $path) {
            if (str_ends_with($path, $suffix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Helper: write a file under src/ in the temp dir (creating dirs as needed).
     */
    private function writeSrc(string $relative, string $contents): string
    {
        $path = $this->tmpDir . '/src/' . $relative;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $contents);
        return $path;
    }

    // ── Watched Extensions — REAL behaviour ────────────────────────
    //
    // Each test drops a file of one extension into a watched dir, scans, and
    // asserts the scanner ACTUALLY tracked it. This is driven by the real
    // extension allow-list inside detectFileChanges(); if a maintainer drops an
    // extension from the source, the matching test goes red.

    public function testTracksPhpFile(): void
    {
        $this->writeSrc('app.php', '<?php echo "hi";');
        $server = new Server();
        $this->detect($server);
        $this->assertTrue($this->tracksSuffix($server, '.php'), 'php file should be watched');
    }

    public function testTracksTwigFile(): void
    {
        $this->writeSrc('page.twig', '{{ name }}');
        $server = new Server();
        $this->detect($server);
        $this->assertTrue($this->tracksSuffix($server, '.twig'), 'twig file should be watched');
    }

    public function testTracksHtmlFile(): void
    {
        $this->writeSrc('index.html', '<h1>hi</h1>');
        $server = new Server();
        $this->detect($server);
        $this->assertTrue($this->tracksSuffix($server, '.html'), 'html file should be watched');
    }

    public function testTracksScssFile(): void
    {
        $this->writeSrc('style.scss', '$c: red;');
        $server = new Server();
        $this->detect($server);
        $this->assertTrue($this->tracksSuffix($server, '.scss'), 'scss file should be watched');
    }

    public function testTracksCssFile(): void
    {
        $this->writeSrc('style.css', 'body{color:red}');
        $server = new Server();
        $this->detect($server);
        $this->assertTrue($this->tracksSuffix($server, '.css'), 'css file should be watched');
    }

    public function testTracksJsFile(): void
    {
        $this->writeSrc('app.js', 'console.log(1)');
        $server = new Server();
        $this->detect($server);
        $this->assertTrue($this->tracksSuffix($server, '.js'), 'js file should be watched');
    }

    public function testTracksJsonFile(): void
    {
        $this->writeSrc('data.json', '{}');
        $server = new Server();
        $this->detect($server);
        $this->assertTrue($this->tracksSuffix($server, '.json'), 'json file should be watched');
    }

    public function testDoesNotTrackTxtFile(): void
    {
        $this->writeSrc('readme.txt', 'text');
        $server = new Server();
        $this->detect($server);
        $this->assertFalse($this->tracksSuffix($server, '.txt'), 'txt file should be ignored');
    }

    public function testDoesNotTrackMdFile(): void
    {
        $this->writeSrc('notes.md', '# notes');
        $server = new Server();
        $this->detect($server);
        $this->assertFalse($this->tracksSuffix($server, '.md'), 'md file should be ignored');
    }

    public function testDoesNotTrackYamlFile(): void
    {
        $this->writeSrc('config.yaml', 'key: value');
        $server = new Server();
        $this->detect($server);
        $this->assertFalse($this->tracksSuffix($server, '.yaml'), 'yaml file should be ignored');
    }

    // ── Watched Directories — REAL behaviour ───────────────────────

    public function testWatchesSrcDir(): void
    {
        $this->writeSrc('handler.php', '<?php');
        $server = new Server();
        $this->detect($server);
        $this->assertTrue(
            $this->tracksSuffix($server, 'src/handler.php'),
            'files under src/ should be watched'
        );
    }

    public function testWatchesMigrationsDir(): void
    {
        $migDir = $this->tmpDir . '/migrations';
        mkdir($migDir, 0777, true);
        file_put_contents($migDir . '/001_create_users.php', '<?php // migration');
        $server = new Server();
        $this->detect($server);
        $this->assertTrue(
            $this->tracksSuffix($server, 'migrations/001_create_users.php'),
            'files under migrations/ should be watched'
        );
    }

    public function testDoesNotWatchUntrackedTopLevelDir(): void
    {
        // A file in a dir that is NOT in the watch set (e.g. docs/) is ignored.
        $docsDir = $this->tmpDir . '/docs';
        mkdir($docsDir, 0777, true);
        file_put_contents($docsDir . '/guide.php', '<?php');
        $server = new Server();
        $this->detect($server);
        $this->assertFalse(
            $this->tracksSuffix($server, 'docs/guide.php'),
            'files outside src/ and migrations/ should NOT be watched'
        );
    }

    // ── File Scan — empty / nonexistent ────────────────────────────

    public function testEmptySrcDirNoChange(): void
    {
        mkdir($this->tmpDir . '/src', 0777, true);
        $server = new Server();
        // First scan of an empty dir reports no change and tracks nothing.
        $this->assertFalse($this->detect($server));
        $this->assertEmpty($this->trackedFiles($server));
    }

    public function testNonexistentWatchedDirsNoError(): void
    {
        // tmpDir has no src/ or migrations/ — scanner must not throw and reports no change.
        $server = new Server();
        $this->assertFalse($this->detect($server));
    }

    // ── File Change Detection — the core behaviour ─────────────────

    public function testDetectsModification(): void
    {
        $file = $this->writeSrc('app.php', '<?php // v1');
        $server = new Server();

        // First scan: baseline the mtime map, reports no change.
        $this->assertFalse($this->detect($server));

        // Bump the mtime to simulate an edit.
        clearstatcache();
        touch($file, time() + 5);

        // Second scan: the modification is reported as a change.
        $this->assertTrue($this->detect($server), 'modifying a watched file must report a change');
    }

    public function testPhpModificationFlagsPhpChange(): void
    {
        // A .php edit must set phpChangeDetected (drives the "restart needed" path);
        // a CSS edit must NOT.
        $phpFile = $this->writeSrc('app.php', '<?php // v1');
        $server = new Server();
        $this->detect($server);

        clearstatcache();
        touch($phpFile, time() + 5);
        $this->detect($server);

        $flag = new ReflectionProperty(Server::class, 'phpChangeDetected');
        $this->assertTrue($flag->getValue($server), '.php change must set phpChangeDetected');
    }

    public function testCssModificationDoesNotFlagPhpChange(): void
    {
        $cssFile = $this->writeSrc('style.css', 'body{}');
        $server = new Server();
        $this->detect($server);

        clearstatcache();
        touch($cssFile, time() + 5);
        $changed = $this->detect($server);

        $flag = new ReflectionProperty(Server::class, 'phpChangeDetected');
        $this->assertTrue($changed, 'css change must still report a reload');
        $this->assertFalse($flag->getValue($server), 'a non-php change must NOT set phpChangeDetected');
    }

    public function testNewFileIsTrackedAfterBaseline(): void
    {
        mkdir($this->tmpDir . '/src', 0777, true);
        $server = new Server();
        // Baseline: empty.
        $this->detect($server);
        $this->assertEmpty($this->trackedFiles($server));

        // Add a new file, then rescan: it is now tracked.
        $this->writeSrc('new.php', '<?php // new');
        $this->detect($server);
        $this->assertTrue($this->tracksSuffix($server, 'new.php'), 'a newly created file must be picked up');
    }

    public function testNoChangeOnSecondScanWithoutModification(): void
    {
        $this->writeSrc('stable.php', '<?php // stable');
        $server = new Server();
        $this->assertFalse($this->detect($server), 'first scan baselines, no change');
        $this->assertFalse($this->detect($server), 'second scan with no edit must report no change');
    }

    // ── .env File Watching ─────────────────────────────────────────

    public function testTracksEnvFile(): void
    {
        file_put_contents($this->tmpDir . '/.env', 'KEY=value');
        $server = new Server();
        $this->detect($server);
        $this->assertContains('.env', $this->trackedFiles($server), '.env must be tracked');
    }

    public function testDetectsEnvFileChange(): void
    {
        $envFile = $this->tmpDir . '/.env';
        file_put_contents($envFile, 'FOO=bar');
        $server = new Server();
        $this->assertFalse($this->detect($server), 'first scan baselines .env');

        clearstatcache();
        touch($envFile, time() + 5);
        $this->assertTrue($this->detect($server), 'editing .env must report a change');
    }

    // ── Recursive / multi-file scanning ────────────────────────────

    public function testScansRecursiveSubdirectories(): void
    {
        $this->writeSrc('routes/api/users.php', '<?php // users');
        $server = new Server();
        $this->detect($server);
        $this->assertTrue(
            $this->tracksSuffix($server, 'routes/api/users.php'),
            'nested files under src/ must be scanned recursively'
        );
    }

    public function testTracksMultipleFiles(): void
    {
        $this->writeSrc('a.php', '<?php // a');
        $this->writeSrc('b.html', '<p>b</p>');
        $this->writeSrc('c.css', 'body {}');
        $server = new Server();
        $this->detect($server);
        $this->assertGreaterThanOrEqual(3, count($this->trackedFiles($server)));
    }

    public function testMixedExtensionsTracksOnlyWatched(): void
    {
        $this->writeSrc('app.php', '<?php');
        $this->writeSrc('readme.txt', 'text');
        $this->writeSrc('notes.md', '# notes');
        $this->writeSrc('style.css', 'body{}');
        $server = new Server();
        $this->detect($server);

        $this->assertTrue($this->tracksSuffix($server, '.php'));
        $this->assertTrue($this->tracksSuffix($server, '.css'));
        $this->assertFalse($this->tracksSuffix($server, '.txt'));
        $this->assertFalse($this->tracksSuffix($server, '.md'));
    }

    // ── Server shape — real construction state ─────────────────────

    public function testServerConstructorDefaults(): void
    {
        $server = new Server();
        $this->assertSame('0.0.0.0', $server->getHost());
        // 7145, matching bin/tina4php + App::resolveBindPort (DUALPORT-DEC-02,
        // DUALPORT-DEFAULT-SMELL) -- was 7146, a value nothing else agreed with.
        $this->assertSame(7145, $server->getPort());
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

    public function testFileCheckIntervalDefaultIsOneSecond(): void
    {
        // Assert the ACTUAL default value, not merely that it is positive.
        $server = new Server();
        $ref = new ReflectionProperty(Server::class, 'fileCheckInterval');
        $this->assertSame(1.0, $ref->getValue($server));
    }

    // ── Interface-contract guard (parity rename guard) ─────────────
    //
    // Consolidated single contract test replacing the per-method method_exists
    // smoke tests: the hot-reload surface must keep these method names so a rename
    // on one framework can't silently drift from the others. Real behaviour for
    // detectFileChanges() is proven by the scan/modification tests above.

    public function testServerExposesHotReloadContract(): void
    {
        $expected = [
            'detectFileChanges',
            'broadcastReload',
            'onFilesChanged',
            'addReloadSubscriber',
            'removeReloadSubscriber',
            'getHost',
            'getPort',
            'isRunning',
        ];
        foreach ($expected as $method) {
            $this->assertTrue(
                method_exists(Server::class, $method),
                "Server::{$method}() is part of the hot-reload contract and must not be renamed without parity"
            );
        }
    }
}
