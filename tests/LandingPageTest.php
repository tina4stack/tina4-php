<?php

/**
 * Regression tests for issue #39 — landing page, template auto-routing,
 * SPA index serving, and the 404 status line.
 *
 * Mirrors tina4-python's tests/test_landing_page.py. Covers:
 * - Template auto-routing only fires from src/templates/pages/; partials,
 *   layouts, base.twig, errors, and _* files never auto-serve.
 * - TINA4_TEMPLATE_ROUTING=off is a hard kill switch.
 * - src/public/index.html auto-serves at / (the SPA case).
 * - The framework landing page only renders when TINA4_DEBUG=true.
 * - The HTTP/1.1 status line uses the canonical RFC reason phrase per code
 *   (no more "HTTP/1.1 404 OK").
 */

use PHPUnit\Framework\TestCase;
use Tina4\Router;
use Tina4\Server;
use Tina4\StaticFiles;

class LandingPageTest extends TestCase
{
    private string $tempDir;
    private string $previousBase;
    private ?string $savedDebug;
    private ?string $savedRouting;

    protected function setUp(): void
    {
        // Throwaway project root with src/templates/, src/public/, etc.
        $this->tempDir = sys_get_temp_dir() . '/tina4-landing-test-' . getmypid() . '-' . uniqid();
        mkdir($this->tempDir . '/src/templates/pages', 0755, true);
        mkdir($this->tempDir . '/src/templates/partials', 0755, true);
        mkdir($this->tempDir . '/src/public', 0755, true);

        $this->previousBase = Router::$basePath;
        Router::$basePath = $this->tempDir;

        $this->savedDebug = getenv('TINA4_DEBUG') !== false ? getenv('TINA4_DEBUG') : null;
        $this->savedRouting = getenv('TINA4_TEMPLATE_ROUTING') !== false ? getenv('TINA4_TEMPLATE_ROUTING') : null;

        // Reset state so each test sees a fresh scan
        Router::resetTemplateCache();
        \Tina4\DotEnv::resetEnv();
    }

    protected function tearDown(): void
    {
        Router::$basePath = $this->previousBase;
        Router::resetTemplateCache();

        if ($this->savedDebug === null) {
            putenv('TINA4_DEBUG');
            unset($_ENV['TINA4_DEBUG']);
        } else {
            putenv("TINA4_DEBUG={$this->savedDebug}");
        }
        if ($this->savedRouting === null) {
            putenv('TINA4_TEMPLATE_ROUTING');
            unset($_ENV['TINA4_TEMPLATE_ROUTING']);
        } else {
            putenv("TINA4_TEMPLATE_ROUTING={$this->savedRouting}");
        }
        \Tina4\DotEnv::resetEnv();

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

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key]);
        } else {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
        \Tina4\DotEnv::resetEnv();
        Router::resetTemplateCache();
    }

    // ── 1. Template auto-routing scope ────────────────────────────

    public function testPagesIndexServesAtRoot(): void
    {
        $this->setEnv('TINA4_DEBUG', 'true');
        file_put_contents($this->tempDir . '/src/templates/pages/index.twig', 'ok');
        $this->assertEquals('pages/index.twig', Router::resolveTemplate('/'));
    }

    public function testPagesAboutServesAtAbout(): void
    {
        $this->setEnv('TINA4_DEBUG', 'true');
        file_put_contents($this->tempDir . '/src/templates/pages/about.twig', 'ok');
        $this->assertEquals('pages/about.twig', Router::resolveTemplate('/about'));
    }

    public function testPagesNestedServesAtNestedUrl(): void
    {
        $this->setEnv('TINA4_DEBUG', 'true');
        mkdir($this->tempDir . '/src/templates/pages/products');
        file_put_contents($this->tempDir . '/src/templates/pages/products/list.twig', 'ok');
        $this->assertEquals('pages/products/list.twig', Router::resolveTemplate('/products/list'));
    }

    public function testPartialsNeverServe(): void
    {
        // Files outside pages/ are NEVER URL-routable, even if URL matches.
        $this->setEnv('TINA4_DEBUG', 'true');
        file_put_contents($this->tempDir . '/src/templates/partials/nav.twig', 'partial');
        $this->assertNull(Router::resolveTemplate('/partials/nav'));
    }

    public function testLayoutsNeverServe(): void
    {
        $this->setEnv('TINA4_DEBUG', 'true');
        mkdir($this->tempDir . '/src/templates/layouts');
        file_put_contents($this->tempDir . '/src/templates/layouts/main.twig', 'layout');
        $this->assertNull(Router::resolveTemplate('/layouts/main'));
    }

    public function testBaseTwigAtRootNeverServes(): void
    {
        // src/templates/base.twig used to be reachable as /base — no longer.
        $this->setEnv('TINA4_DEBUG', 'true');
        file_put_contents($this->tempDir . '/src/templates/base.twig', 'base');
        $this->assertNull(Router::resolveTemplate('/base'));
    }

    public function testErrorsNeverServe(): void
    {
        $this->setEnv('TINA4_DEBUG', 'true');
        mkdir($this->tempDir . '/src/templates/errors');
        file_put_contents($this->tempDir . '/src/templates/errors/404.twig', 'err');
        $this->assertNull(Router::resolveTemplate('/errors/404'));
    }

    public function testUnderscorePrefixedInPagesSkipped(): void
    {
        // Even inside pages/, _-prefixed files are private.
        $this->setEnv('TINA4_DEBUG', 'true');
        file_put_contents($this->tempDir . '/src/templates/pages/_helper.twig', 'private');
        $this->assertNull(Router::resolveTemplate('/_helper'));
    }

    // ── Production cache ──────────────────────────────────────────

    public function testCacheOnlyIndexesPages(): void
    {
        $this->setEnv('TINA4_DEBUG', null);
        file_put_contents($this->tempDir . '/src/templates/pages/about.twig', 'page');
        file_put_contents($this->tempDir . '/src/templates/partials/nav.twig', 'partial');
        file_put_contents($this->tempDir . '/src/templates/base.twig', 'base');

        Router::resetTemplateCache();
        Router::buildTemplateCache();

        $this->assertEquals('pages/about.twig', Router::resolveTemplate('/about'));
        $this->assertNull(Router::resolveTemplate('/partials/nav'));
        $this->assertNull(Router::resolveTemplate('/base'));
    }

    public function testCacheSkipsUnderscorePrefixedInPages(): void
    {
        $this->setEnv('TINA4_DEBUG', null);
        file_put_contents($this->tempDir . '/src/templates/pages/_private.twig', 'private');
        file_put_contents($this->tempDir . '/src/templates/pages/visible.twig', 'visible');

        Router::resetTemplateCache();
        Router::buildTemplateCache();

        $this->assertEquals('pages/visible.twig', Router::resolveTemplate('/visible'));
        $this->assertNull(Router::resolveTemplate('/_private'));
    }

    // ── 2. TINA4_TEMPLATE_ROUTING kill switch ─────────────────────

    #[\PHPUnit\Framework\Attributes\DataProvider('killSwitchValues')]
    public function testKillSwitchDisablesAutoRouting(string $value): void
    {
        $this->setEnv('TINA4_DEBUG', 'true');
        $this->setEnv('TINA4_TEMPLATE_ROUTING', $value);
        file_put_contents($this->tempDir . '/src/templates/pages/about.twig', 'ok');
        $this->assertNull(Router::resolveTemplate('/about'));
        $this->assertFalse(Router::isTemplateAutoRoutingEnabled());
    }

    public static function killSwitchValues(): array
    {
        return [
            ['off'], ['OFF'], ['false'], ['FALSE'], ['0'], ['no'], ['disabled'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('truthyValues')]
    public function testKillSwitchTruthyKeepsRouting(?string $value): void
    {
        $this->setEnv('TINA4_DEBUG', 'true');
        $this->setEnv('TINA4_TEMPLATE_ROUTING', $value);
        file_put_contents($this->tempDir . '/src/templates/pages/about.twig', 'ok');
        $this->assertEquals('pages/about.twig', Router::resolveTemplate('/about'));
        $this->assertTrue(Router::isTemplateAutoRoutingEnabled());
    }

    public static function truthyValues(): array
    {
        return [
            ['on'], ['true'], ['1'], ['yes'], [null],
        ];
    }

    // ── 3. src/public/index.html SPA auto-serve ───────────────────

    public function testRootServesPublicIndexHtml(): void
    {
        // A Vite/SPA build with src/public/index.html should serve at /.
        file_put_contents($this->tempDir . '/src/public/index.html', '<!doctype html><h1>SPA</h1>');
        $resp = StaticFiles::tryServe('/', $this->tempDir);
        $this->assertNotNull($resp, "root '/' did not pick up src/public/index.html");
        $this->assertStringContainsString('SPA', $resp->getBody());
    }

    public function testSubdirectoryIndexHtml(): void
    {
        // /admin/ should serve src/public/admin/index.html
        mkdir($this->tempDir . '/src/public/admin');
        file_put_contents($this->tempDir . '/src/public/admin/index.html', 'admin');
        $resp = StaticFiles::tryServe('/admin/', $this->tempDir);
        $this->assertNotNull($resp);
        $this->assertEquals('admin', $resp->getBody());
    }

    public function testNoIndexHtmlReturnsNull(): void
    {
        // If no index.html, fall through to the next handler.
        $resp = StaticFiles::tryServe('/', $this->tempDir);
        $this->assertNull($resp);
    }

    // ── 4. Landing page hidden in production ──────────────────────

    #[\PHPUnit\Framework\Attributes\DataProvider('truthyDebugValues')]
    public function testDevModeTruthyMeansLandingVisible(string $value): void
    {
        $this->setEnv('TINA4_DEBUG', $value);
        $this->assertTrue(\Tina4\DotEnv::isTruthy(\Tina4\DotEnv::getEnv('TINA4_DEBUG', 'false')));
    }

    public static function truthyDebugValues(): array
    {
        return [['true'], ['1'], ['yes'], ['on']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('falsyDebugValues')]
    public function testDevModeFalsyMeansLandingHidden(?string $value): void
    {
        $this->setEnv('TINA4_DEBUG', $value);
        $this->assertFalse(\Tina4\DotEnv::isTruthy(\Tina4\DotEnv::getEnv('TINA4_DEBUG', 'false')));
    }

    public static function falsyDebugValues(): array
    {
        return [['false'], ['0'], ['no'], [''], [null]];
    }

    // ── 5. HTTP/1.1 status reason phrases ─────────────────────────

    public function test200Ok(): void
    {
        $this->assertEquals('OK', Server::httpReason(200));
    }

    public function test404NotFound(): void
    {
        $this->assertEquals('Not Found', Server::httpReason(404));
    }

    public function test500InternalServerError(): void
    {
        $this->assertEquals('Internal Server Error', Server::httpReason(500));
    }

    public function test302Found(): void
    {
        $this->assertEquals('Found', Server::httpReason(302));
    }

    public function test401Unauthorized(): void
    {
        $this->assertEquals('Unauthorized', Server::httpReason(401));
    }

    public function test403Forbidden(): void
    {
        $this->assertEquals('Forbidden', Server::httpReason(403));
    }

    public function test429TooManyRequests(): void
    {
        $this->assertEquals('Too Many Requests', Server::httpReason(429));
    }

    public function testUnknown2xxFallsBackToOk(): void
    {
        $this->assertEquals('OK', Server::httpReason(299));
    }

    public function testUnknown5xxFallsBackToError(): void
    {
        $this->assertEquals('Error', Server::httpReason(599));
    }

    public function testNoReasonPhraseIsEverEmpty(): void
    {
        for ($code = 200; $code < 600; $code++) {
            $this->assertNotEmpty(
                Server::httpReason($code),
                "empty phrase for {$code}"
            );
        }
    }
}
