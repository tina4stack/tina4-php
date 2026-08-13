<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Default landing page — dev-only welcome page, 404-in-prod info-leak guard.
 *
 * Feature 46. See LAND-DEC-01/LAND-DEC-02 and
 * tina4-documentation/plan/v3/fixtures/landing_page_contract.json.
 *
 * Driven through the REAL bootstrap path (Tina4\App::start() - the ACTUAL
 * mechanism PHP uses: it REGISTERS the landing page as a bootstrap route,
 * unlike Python/Ruby/Node's request-time fallback - LAND-MECHANISM-DIVERGE)
 * plus the real front controller (Tina4\TestClient -> Router::dispatch). NO
 * mocks.
 *
 * Cases (shared names, all four):
 *   1. dev_mode_serves_the_branded_landing_page - TINA4_DEBUG on, no user /
 *      route -> 200 + the branded banner.
 *   2. production_returns_404_and_leaks_nothing - TINA4_DEBUG off -> 404, and
 *      the body carries NO framework version, NO /__dev link, NO gallery (the
 *      SECURITY case - LAND-PROD-DECIDED).
 *   3. a_user_root_route_always_wins - a registered GET / handler wins in
 *      BOTH dev and prod.
 *   4. a_pages_index_template_suppresses_the_landing - a
 *      src/templates/pages/index.* is served at / instead of the landing.
 *
 * Same case names in all four:
 *   tina4-python/tests/test_landing_page_contract.py
 *   tina4-ruby/spec/landing_page_contract_spec.rb
 *   tina4-nodejs/test/landingPageContract.test.ts
 */

use PHPUnit\Framework\TestCase;
use Tina4\App;
use Tina4\DotEnv;
use Tina4\Middleware;
use Tina4\Router;
use Tina4\TestClient;
use Tina4\TestResponse;

class LandingPageContractTest extends TestCase
{
    /** @var string[] */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        Router::clear();
        Middleware::reset();
        $this->setAppStatic('autoMigrated', false);
        $this->setAppStatic('database', null);
        $this->clearDebugEnv();
        DotEnv::resetEnv();
        Router::resetTemplateCache();
    }

    protected function tearDown(): void
    {
        Router::clear();
        Middleware::reset();
        $this->setAppStatic('autoMigrated', false);
        $this->setAppStatic('database', null);
        $this->clearDebugEnv();
        DotEnv::resetEnv();
        Router::resetTemplateCache();
        foreach ($this->tempDirs as $dir) {
            $this->rmdirRecursive($dir);
        }
        $this->tempDirs = [];
    }

    private function clearDebugEnv(): void
    {
        putenv('TINA4_DEBUG');
        unset($_ENV['TINA4_DEBUG'], $_SERVER['TINA4_DEBUG']);
    }

    private function setAppStatic(string $name, mixed $value): void
    {
        $prop = new \ReflectionProperty(App::class, $name);
        $prop->setValue(null, $value);
    }

    private function makeProjectDir(): string
    {
        $dir = sys_get_temp_dir() . '/tina4_landing_contract_' . getmypid() . '_' . uniqid();
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Boot a REAL Tina4\App (registerLandingPage runs INSIDE start() - this is
     * the actual bootstrap path, not a stand-in) rooted at a throwaway project
     * dir, optionally registering a user "/" route BEFORE start() (exactly as
     * real src/routes/ auto-discovery, which also runs before
     * registerLandingPage, would), then dispatch a real GET / through the
     * real front controller.
     *
     * chdir is required (not just basePath): Response::render()'s Frond
     * engine resolves "src/templates/..." relative to getcwd(), not
     * Router::$basePath - a real app's cwd IS its project root when serving.
     */
    private function bootAndGet(string $projectDir, bool $dev, ?callable $beforeStart = null): TestResponse
    {
        Router::clear();
        Middleware::reset();
        putenv('TINA4_DEBUG=' . ($dev ? 'true' : 'false'));
        $_ENV['TINA4_DEBUG'] = $dev ? 'true' : 'false';
        DotEnv::resetEnv();
        Router::resetTemplateCache();

        $originalCwd = getcwd();
        chdir($projectDir);
        try {
            if ($beforeStart !== null) {
                $beforeStart();
            }
            $app = new App(basePath: $projectDir);
            $app->start();
            AppTestSupport::releaseHandlers($app);

            return (new TestClient())->get('/');
        } finally {
            chdir($originalCwd);
        }
    }

    // ------------------------------------------------------- 1. dev shows

    public function testDevModeServesTheBrandedLandingPage(): void
    {
        $r = $this->bootAndGet($this->makeProjectDir(), dev: true);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Tina4Php', $r->body);
    }

    // ----------------------------------------------- 2. prod 404s, no leak

    public function testProductionReturns404AndLeaksNothing(): void
    {
        $r = $this->bootAndGet($this->makeProjectDir(), dev: false);
        $this->assertSame(404, $r->status);
        $this->assertStringNotContainsString('Tina4Php', $r->body);
        $this->assertStringNotContainsString(App::$VERSION, $r->body);
        $this->assertStringNotContainsString('/__dev', $r->body);
        $this->assertStringNotContainsString('id="gallery"', $r->body);
    }

    // ------------------------------------------- 3. user route always wins

    public function testAUserRootRouteAlwaysWins(): void
    {
        $registerUserRoute = static function (): void {
            Router::get('/', function ($request, $response) {
                return $response->html('USER-ROOT-MARKER-PHP');
            });
        };

        foreach ([true, false] as $dev) {
            $r = $this->bootAndGet($this->makeProjectDir(), dev: $dev, beforeStart: $registerUserRoute);
            $this->assertSame(200, $r->status, 'dev=' . ($dev ? 'true' : 'false'));
            $this->assertStringContainsString('USER-ROOT-MARKER-PHP', $r->body);
            $this->assertStringNotContainsString('Tina4Php', $r->body);
        }
    }

    // ----------------------------------------- 4. pages/index beats landing

    public function testAPagesIndexTemplateSuppressesTheLanding(): void
    {
        $dir = $this->makeProjectDir();
        mkdir($dir . '/src/templates/pages', 0755, true);
        file_put_contents($dir . '/src/templates/pages/index.twig', 'PAGES-INDEX-MARKER-PHP');

        $r = $this->bootAndGet($dir, dev: true);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('PAGES-INDEX-MARKER-PHP', $r->body);
        $this->assertStringNotContainsString('Tina4Php', $r->body);
    }
}
