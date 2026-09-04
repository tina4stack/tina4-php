<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\ModelDiscovery;

/**
 * ORM model auto-discovery — the src/orm/ half of convention-over-configuration.
 *
 * Routes (src/routes/), seeds (src/seeds/) and services (src/services/) were all
 * discovered by convention; models were the one exception, so a plain
 * src/orm/Goose.php extending \Tina4\ORM gave class_exists('Goose', true) ===
 * false at request time. The scaffolded composer.json maps psr-4 "" to src/app/
 * only, which is why a generated CRUD route — it does `new Goose()` with no
 * require_once — answered 500 "Class Goose not found" until the developer
 * hand-edited composer.json. Python (the master) imports every module under
 * src/ at boot (tina4_python/core/server.py::_discover), so this is a parity fix.
 *
 * No mocks: the REAL generator writes the model, a real scaffolded app on the
 * real filesystem, the real built-in server booted as a real process, real HTTP
 * over a real socket, and a real SQLite roundtrip through the discovered model.
 */
class ModelDiscoveryTest extends TestCase
{
    private static string $appDir = '';

    /** @var resource|null Built-in `tina4php serve` process. */
    private static $process = null;
    private static int $port = 0;
    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        self::$appDir = sys_get_temp_dir() . '/tina4_model_discovery_' . bin2hex(random_bytes(6));
        @mkdir(self::$appDir . '/src/routes', 0777, true);
        @mkdir(self::$appDir . '/src/orm/inventory', 0777, true);
        @mkdir(self::$appDir . '/data', 0777, true);

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';

        file_put_contents(self::$appDir . '/index.php', <<<PHP
        <?php
        require_once '{$autoload}';
        \\Tina4\\ORM::bindDatabase(new \\Tina4\\Database\\SQLite3Adapter(__DIR__ . '/data/app.db'));
        \$app = new \\Tina4\\App(basePath: __DIR__);
        \$app->handle();
        PHP);

        // The REAL generator writes src/orm/Goose.php. Nothing then requires it
        // and nothing lists it in composer.json — discovery is the only thing
        // that can make the scaffolded model exist at request time.
        self::runCli(['generate', 'model', 'Goose', '--fields', 'name:string']);
        if (!file_exists(self::$appDir . '/src/orm/Goose.php')) {
            throw new RuntimeException('`tina4php generate model Goose` produced no model file');
        }

        // A model in a SUB-directory — discovery is recursive (Python rglobs).
        file_put_contents(self::$appDir . '/src/orm/inventory/Duck.php', <<<'PHP'
        <?php

        class Duck extends \Tina4\ORM
        {
            public string $tableName = 'duck';
            public string $primaryKey = 'id';
            public ?int $id = null;
            public string $name = '';
        }
        PHP);

        // Underscore-prefixed: a private/partial file, skipped (Python parity).
        file_put_contents(self::$appDir . '/src/orm/_Draft.php', <<<'PHP'
        <?php

        class DraftModel extends \Tina4\ORM
        {
            public string $tableName = 'draft';
        }
        PHP);

        // A model that a route ALSO requires explicitly (the generated-test and
        // composer `autoload.files` shape). Discovery must not double-declare it.
        file_put_contents(self::$appDir . '/src/orm/Swan.php', <<<'PHP'
        <?php

        class Swan extends \Tina4\ORM
        {
            public string $tableName = 'swan';
            public string $primaryKey = 'id';
            public ?int $id = null;
            public string $name = '';
        }
        PHP);

        // A model file that RAISES at include time must not stop the others
        // from loading, and must not take the server down.
        file_put_contents(self::$appDir . '/src/orm/Broken.php', <<<'PHP'
        <?php

        throw new \RuntimeException('this model file is broken on purpose');
        PHP);

        // Generated-CRUD shape: `new Goose()` with NO require_once. Also
        // reports what the class resolver sees at request time.
        file_put_contents(self::$appDir . '/src/routes/geese.php', <<<'PHP'
        <?php

        \Tina4\Router::get('/audit/classes', function (\Tina4\Request $request, \Tina4\Response $response) {
            return $response->json([
                'goose_autoload' => class_exists('Goose', true),
                'goose_declared' => class_exists('Goose', false),
                'duck_autoload' => class_exists('Duck', true),
                'draft_declared' => class_exists('DraftModel', false),
            ]);
        });

        \Tina4\Router::get('/audit/geese', function (\Tina4\Request $request, \Tina4\Response $response) {
            try {
                $goose = new Goose();
                $goose->createTable();
                $fresh = new Goose(['name' => 'Graylag']);
                $fresh->save();
                return $response->json([
                    'records' => array_map(fn(\Tina4\ORM $m) => $m->toDict(), $goose->all()->toArray()),
                    'count' => $goose->count(),
                ]);
            } catch (\Throwable $e) {
                return $response->json(['error' => get_class($e) . ': ' . $e->getMessage()], 500);
            }
        });

        // The generated-test / composer "files" shape: this model file is ALSO
        // required explicitly. Discovery must not fatal on a redeclare. Goose
        // is deliberately left alone so it depends on discovery only.
        require_once __DIR__ . '/../orm/Swan.php';

        \Tina4\Router::get('/audit/swans', function (\Tina4\Request $request, \Tina4\Response $response) {
            try {
                $swan = new Swan();
                $swan->createTable();
                return $response->json(['count' => $swan->count()]);
            } catch (\Throwable $e) {
                return $response->json(['error' => get_class($e) . ': ' . $e->getMessage()], 500);
            }
        });

        \Tina4\Router::get('/audit/ducks', function (\Tina4\Request $request, \Tina4\Response $response) {
            try {
                $duck = new Duck();
                $duck->createTable();
                return $response->json(['count' => $duck->count()]);
            } catch (\Throwable $e) {
                return $response->json(['error' => get_class($e) . ': ' . $e->getMessage()], 500);
            }
        });
        PHP);

        file_put_contents(
            self::$appDir . '/.env',
            "TINA4_DEBUG=false\nTINA4_OVERRIDE_CLIENT=true\nTINA4_NO_BROWSER=true\nTINA4_AUTO_MIGRATE=false\n"
        );

        self::$log = self::$appDir . '/server.log';
        self::$port = self::freePort();
        self::boot();
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            // SIGKILL the server process itself — never the process group, which
            // under proc_open's array form is the test runner's own group.
            proc_terminate(self::$process, SIGKILL);
            proc_close(self::$process);
        }
        self::$process = null;

        if (self::$appDir !== '' && is_dir(self::$appDir)) {
            self::removeTree(self::$appDir);
        }
    }

    // ── real server helpers ─────────────────────────────────────────────

    /** Reserve a free localhost TCP port by binding :0 and reading it back. */
    private static function freePort(): int
    {
        return \FreePort::get();
    }

    /**
     * Run the real tina4php CLI inside the scaffolded app.
     *
     * @param string[] $arguments
     */
    private static function runCli(array $arguments): void
    {
        $process = proc_open(
            array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/tina4php'], $arguments),
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            self::$appDir,
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => getenv('HOME') ?: sys_get_temp_dir()]
        );
        if (is_resource($process)) {
            proc_close($process);
        }
    }

    /** Boot the real built-in server and wait until it answers a real request. */
    private static function boot(): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/tina4php', 'serve', '--port', (string)self::$port, '--no-browser'],
            [1 => ['file', self::$log, 'w'], 2 => ['file', self::$log, 'a']],
            $pipes,
            self::$appDir,
            [
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
                // Boot the socket server without the Rust CLI supervising it.
                'TINA4_OVERRIDE_CLIENT' => 'true',
                'TINA4_DEBUG' => 'false',
                'TINA4_AUTO_MIGRATE' => 'false',
                'TINA4_NO_BROWSER' => 'true',
            ]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('could not start the built-in server');
        }
        // Publish the handle BEFORE waiting: if the readiness loop gives up,
        // tearDownAfterClass still has something to kill and the server is not
        // orphaned into the background.
        self::$process = $process;

        for ($attempt = 0; $attempt < 200; $attempt++) {
            if (self::get('/audit/classes')['status'] !== 0) {
                return;
            }
            usleep(50000);
        }

        throw new RuntimeException('server never came up: ' . @file_get_contents(self::$log));
    }

    /**
     * Real GET over a real socket.
     *
     * @return array{status: int, body: string, json: array<string, mixed>}
     */
    private static function get(string $path): array
    {
        $client = @stream_socket_client('tcp://127.0.0.1:' . self::$port, $errno, $errstr, 3.0);
        if (!is_resource($client)) {
            return ['status' => 0, 'body' => '', 'json' => []];
        }
        fwrite($client, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1:" . self::$port . "\r\nConnection: close\r\n\r\n");
        stream_set_timeout($client, 5);

        $raw = '';
        while (!feof($client)) {
            $chunk = fread($client, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        fclose($client);

        $split = strpos($raw, "\r\n\r\n");
        $body = $split === false ? '' : substr($raw, $split + 4);
        preg_match('#^HTTP/1\.\d (\d{3})#', $raw, $match);
        $decoded = json_decode(trim($body), true);

        return [
            'status' => (int)($match[1] ?? 0),
            'body' => $body,
            'json' => is_array($decoded) ? $decoded : [],
        ];
    }

    private static function removeTree(string $dir): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    // ── POSITIVE: a dropped-in model resolves at request time ───────────

    /**
     * THE lock-in. Pre-fix, class_exists('Goose', true) is false at request
     * time because nothing scans or autoloads src/orm/.
     */
    public function testDroppedInModelResolvesAtRequestTime(): void
    {
        $response = self::get('/audit/classes');

        $this->assertSame(200, $response['status'], 'raw: ' . substr($response['body'], 0, 300));
        $this->assertTrue($response['json']['goose_autoload'] ?? false, 'src/orm/Goose.php must resolve with autoload');
        $this->assertTrue($response['json']['goose_declared'] ?? false, 'the model must be loaded eagerly, like Python imports it');
    }

    /** Discovery is recursive: src/orm/inventory/Duck.php counts too. */
    public function testModelInSubDirectoryIsDiscovered(): void
    {
        $response = self::get('/audit/classes');

        $this->assertTrue($response['json']['duck_autoload'] ?? false, 'a model in a src/orm/ sub-directory must be discovered');
    }

    /**
     * Does-it-run: a generated-CRUD-shaped route (`new Goose()`, no
     * require_once) writes and reads a real row through the discovered model.
     * Pre-fix this route answers 500 "Class Goose not found".
     */
    public function testGeneratedCrudShapedRouteWorksWithNoRequireOnce(): void
    {
        $response = self::get('/audit/geese');

        $this->assertSame(
            200,
            $response['status'],
            'a route doing `new Goose()` must work with no require_once; got: ' . substr($response['body'], 0, 300)
        );
        $this->assertGreaterThanOrEqual(1, $response['json']['count'] ?? 0, 'the ORM must have persisted a real row');
        $names = array_column($response['json']['records'] ?? [], 'name');
        $this->assertContains('Graylag', $names, 'the row written through the discovered model must read back');
    }

    /** The sub-directory model is usable, not merely declared. */
    public function testSubDirectoryModelIsUsableByTheOrm(): void
    {
        $response = self::get('/audit/ducks');

        $this->assertSame(200, $response['status'], 'raw: ' . substr($response['body'], 0, 300));
        $this->assertSame(0, $response['json']['count'] ?? -1);
    }

    // ── NEGATIVE ────────────────────────────────────────────────────────

    /** An underscore-prefixed file is private scaffolding — never loaded. */
    public function testUnderscorePrefixedFileIsNotLoaded(): void
    {
        $response = self::get('/audit/classes');

        $this->assertFalse(
            $response['json']['draft_declared'] ?? true,
            'src/orm/_Draft.php must be skipped, so DraftModel stays undeclared'
        );
    }

    /**
     * A model file that raises at include time is logged LOUD and skipped: the
     * other models still load and the server still serves. Silent swallowing
     * (or a dead boot) would both be wrong.
     */
    public function testBrokenModelFileIsLoggedAndDoesNotStopDiscovery(): void
    {
        $log = (string)@file_get_contents(self::$log);

        $this->assertStringContainsString('Broken.php', $log, 'the failing model file must be named in the log');
        $this->assertStringContainsString('this model file is broken on purpose', $log, 'the real cause must be logged');
        $this->assertSame(200, self::get('/audit/classes')['status'], 'the server must still serve');
    }

    /**
     * A model that is ALSO required explicitly (the generated-test and composer
     * `autoload.files` shape) must not fatal on a redeclare — the route file
     * requires src/orm/Swan.php after discovery already loaded it.
     */
    public function testExplicitRequireOnceOfADiscoveredModelDoesNotFatal(): void
    {
        $log = (string)@file_get_contents(self::$log);

        $this->assertStringNotContainsString('Cannot declare class', $log);
        $this->assertStringNotContainsString('Cannot redeclare', $log);
        $this->assertSame(200, self::get('/audit/swans')['status'], 'the double-loaded model must still serve');
    }

    // ── unit surface (real filesystem, no app boot) ──────────────────────

    public function testScanReturnsDiscoveredModelClasses(): void
    {
        $dir = sys_get_temp_dir() . '/tina4_model_scan_' . bin2hex(random_bytes(6));
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/Widget.php', "<?php\nclass ScanWidget extends \\Tina4\\ORM { public string \$tableName = 'widget'; }\n");
        file_put_contents($dir . '/notes.txt', 'not php');
        file_put_contents($dir . '/helper.php', "<?php\nfunction tina4_scan_helper() { return 1; }\n");

        ModelDiscovery::reset();
        try {
            $discovered = ModelDiscovery::scan($dir);

            $this->assertSame(['ScanWidget'], array_column($discovered, 'class'), 'only ORM subclasses are reported');
            $this->assertTrue(class_exists('ScanWidget', false));
            $this->assertTrue(function_exists('tina4_scan_helper'), 'a non-model php file in src/orm/ is still loaded');

            // Idempotent: a second scan of unchanged files reports nothing new
            // and must never re-include (which would fatal on the redeclare).
            $this->assertSame([], ModelDiscovery::scan($dir), 'a rescan of unchanged files is a no-op');
        } finally {
            ModelDiscovery::reset();
            self::removeTree($dir);
        }
    }

    public function testScanOfAMissingDirectoryIsAnEmptyNoOp(): void
    {
        ModelDiscovery::reset();
        $this->assertSame([], ModelDiscovery::scan(sys_get_temp_dir() . '/tina4_no_such_orm_dir_' . bin2hex(random_bytes(4))));
        ModelDiscovery::reset();
    }
}
