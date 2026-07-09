<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

// DevAdmin + its secondary classes live together in DevAdmin.php and PSR-4
// cannot autoload them individually, so force-include the file.
require_once __DIR__ . '/../Tina4/DevAdmin.php';

use PHPUnit\Framework\TestCase;
use Tina4\Context;
use Tina4\DevAdmin;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

/**
 * The dev reload TRIGGER (POST /__dev/api/reload) must reindex the changed
 * file into the shared Context so code_search reflects the edit — PHP parity
 * with tina4-python tests/test_context.py::TestReloadReindex. Real route
 * handler, real Context, real temp files, no mocks.
 *
 * Global-state discipline mirrors DevReloadWsTest: the reload path installs
 * App + ErrorTracker global handlers, so we warm them ONCE in setUpBeforeClass
 * (before any per-test risk snapshot; they are idempotent so re-registration
 * adds none) and clear the Router in BOTH setUp and tearDown so this class
 * never leaves a populated router or leaked handlers for later suites.
 */
class ContextReloadReindexTest extends TestCase
{
    /** @var string[] */
    private array $tempDirs = [];
    private ?string $savedCwd = null;

    public static function setUpBeforeClass(): void
    {
        // Warm the global error/exception handlers ONCE, before any per-test
        // risk snapshot, so no individual test is charged with "newly leaking"
        // them (ErrorTracker::register is idempotent). We deliberately do NOT
        // construct App here: its ctor calls Auth::ensureDevSecret(), which
        // mutates TINA4_SECRET and would poison later CSRF/JWT suites. The
        // reload handler needs only the registered routes, not a booted App.
        DevAdmin::register();
    }

    protected function setUp(): void
    {
        Context::clearShared();
        Router::clear();
        $this->savedCwd = getcwd() ?: null;
    }

    protected function tearDown(): void
    {
        if ($this->savedCwd !== null) {
            @chdir($this->savedCwd);
        }
        Router::clear();
        Context::clearShared();
        foreach ($this->tempDirs as $dir) {
            $this->rrmdir($dir);
        }
        $this->tempDirs = [];
    }

    public function testApiReloadReindexesChangedFile(): void
    {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tina4ctxreload_' . bin2hex(random_bytes(6));
        mkdir($tmp . '/src', 0777, true);
        $this->tempDirs[] = $tmp;
        file_put_contents($tmp . '/src/mod.php', "<?php\nfunction wombat()\n{\n    return 1;\n}\n");

        chdir($tmp);
        // Build the shared index the way code_search does — db at cwd/.tina4/context.db.
        $db = getcwd() . '/.tina4/context.db';
        $ctx = Context::defaultContext($tmp . '/src', $db);
        $this->assertContains('mod.php', array_map(static fn ($r) => $r['path'], $ctx->search('wombat')));

        // edit, then FIRE the reload trigger with the changed file (real handler).
        // setUp() cleared the router, so re-register the dev-admin routes here
        // (ErrorTracker::register is idempotent — no new global handler).
        file_put_contents($tmp . '/src/mod.php', "<?php\nfunction giraffe()\n{\n    return 2;\n}\n");
        DevAdmin::register();
        $callback = null;
        foreach (Router::getRoutes() as $route) {
            if (($route['pattern'] ?? '') === '/__dev/api/reload' && ($route['method'] ?? '') === 'POST') {
                $callback = $route['callback'];
                break;
            }
        }
        $this->assertNotNull($callback, 'reload route should be registered');
        $callback(
            Request::create('POST', '/__dev/api/reload', null, ['file' => 'src/mod.php', 'type' => 'reload']),
            new Response(true)
        );

        $this->assertContains('mod.php', array_map(static fn ($r) => $r['path'], $ctx->search('giraffe')), 'the reload trigger must reindex the new content');
        $this->assertNotContains('mod.php', array_map(static fn ($r) => $r['path'], $ctx->search('wombat')), 'old content must be gone after the reload reindex');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
