<?php

require_once __DIR__ . '/../Tina4/DevAdmin.php';

use PHPUnit\Framework\TestCase;
use Tina4\DevAdmin;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class GalleryTest extends TestCase
{
    private static string $galleryDir;

    /** @var string[] */
    private static array $expectedExamples = [
        'auth',
        'database',
        'error-overlay',
        'orm',
        'queue',
        'rest-api',
        'templates',
    ];

    public static function setUpBeforeClass(): void
    {
        self::$galleryDir = str_replace('\\', '/', dirname(__DIR__) . '/Tina4/gallery');
    }

    protected function setUp(): void
    {
        Router::clear();
    }

    protected function tearDown(): void
    {
        Router::clear();
        \Tina4\ErrorTracker::reset();
    }

    // ── Helper ───────────────────────────────────────────────────

    private function findRouteCallback(string $method, string $pattern): ?callable
    {
        foreach (Router::getRoutes() as $route) {
            if ($route['pattern'] === $pattern && $route['method'] === $method) {
                return $route['callback'];
            }
        }
        return null;
    }

    // ── Directory structure ──────────────────────────────────────

    public function testGalleryDirectoryExists(): void
    {
        $this->assertDirectoryExists(self::$galleryDir);
    }

    public function testGalleryHasSubdirectories(): void
    {
        $dirs = array_filter(scandir(self::$galleryDir), function ($e) {
            return $e !== '.' && $e !== '..' && is_dir(self::$galleryDir . '/' . $e);
        });
        $this->assertNotEmpty($dirs);
    }

    public function testAllExpectedExamplesPresent(): void
    {
        $dirs = array_values(array_filter(scandir(self::$galleryDir), function ($e) {
            return $e !== '.' && $e !== '..' && is_dir(self::$galleryDir . '/' . $e);
        }));
        foreach (self::$expectedExamples as $name) {
            $this->assertContains($name, $dirs, "Missing gallery example: {$name}");
        }
    }

    // ── Metadata ─────────────────────────────────────────────────

    public function testEveryExampleHasMetaJson(): void
    {
        foreach (self::$expectedExamples as $name) {
            $metaFile = self::$galleryDir . '/' . $name . '/meta.json';
            $this->assertFileExists($metaFile, "Missing meta.json in gallery/{$name}");
        }
    }

    public function testMetaJsonIsValidJson(): void
    {
        foreach (self::$expectedExamples as $name) {
            $metaFile = self::$galleryDir . '/' . $name . '/meta.json';
            $content = file_get_contents($metaFile);
            $parsed = json_decode($content, true);
            $this->assertIsArray($parsed, "meta.json in {$name} is not valid JSON");
        }
    }

    public function testMetaJsonHasNameField(): void
    {
        foreach (self::$expectedExamples as $name) {
            $parsed = json_decode(file_get_contents(self::$galleryDir . '/' . $name . '/meta.json'), true);
            $this->assertArrayHasKey('name', $parsed, "meta.json in {$name} missing 'name'");
        }
    }

    public function testMetaJsonHasDescriptionField(): void
    {
        foreach (self::$expectedExamples as $name) {
            $parsed = json_decode(file_get_contents(self::$galleryDir . '/' . $name . '/meta.json'), true);
            $this->assertArrayHasKey('description', $parsed, "meta.json in {$name} missing 'description'");
        }
    }

    public function testMetaJsonNameIsNonEmptyString(): void
    {
        foreach (self::$expectedExamples as $name) {
            $parsed = json_decode(file_get_contents(self::$galleryDir . '/' . $name . '/meta.json'), true);
            $this->assertIsString($parsed['name']);
            $this->assertNotEmpty($parsed['name']);
        }
    }

    public function testMetaJsonDescriptionIsNonEmptyString(): void
    {
        foreach (self::$expectedExamples as $name) {
            $parsed = json_decode(file_get_contents(self::$galleryDir . '/' . $name . '/meta.json'), true);
            $this->assertIsString($parsed['description']);
            $this->assertNotEmpty($parsed['description']);
        }
    }

    // ── Example structure ────────────────────────────────────────

    public function testEachExampleHasSrcDirectory(): void
    {
        foreach (self::$expectedExamples as $name) {
            $srcDir = self::$galleryDir . '/' . $name . '/src';
            $this->assertDirectoryExists($srcDir, "Missing src/ in gallery/{$name}");
        }
    }

    public function testEachExampleHasPhpFiles(): void
    {
        foreach (self::$expectedExamples as $name) {
            $srcDir = self::$galleryDir . '/' . $name . '/src';
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            $phpFiles = [];
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $phpFiles[] = $file->getPathname();
                }
            }
            $this->assertNotEmpty($phpFiles, "No .php files in gallery/{$name}/src");
        }
    }

    public function testRestApiHasRouteFile(): void
    {
        $routesDir = self::$galleryDir . '/rest-api/src/routes';
        $this->assertDirectoryExists($routesDir);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($routesDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $phpFiles = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $phpFiles[] = $file->getPathname();
            }
        }
        $this->assertNotEmpty($phpFiles, 'rest-api example should have at least one route PHP file');
    }

    public function testTemplatesHasTwigFile(): void
    {
        $tplDir = self::$galleryDir . '/templates/src/templates';
        $this->assertDirectoryExists($tplDir);
        $twigFiles = glob($tplDir . '/*.twig') ?: [];
        $this->assertNotEmpty($twigFiles, 'templates example should have at least one .twig file');
    }

    // ── Route registration ───────────────────────────────────────

    public function testDevAdminRegistersGalleryListRoute(): void
    {
        DevAdmin::register();
        $patterns = array_column(Router::getRoutes(), 'pattern');
        $this->assertContains('/__dev/api/gallery', $patterns);
    }

    public function testDevAdminRegistersGalleryDeployRoute(): void
    {
        DevAdmin::register();
        $patterns = array_column(Router::getRoutes(), 'pattern');
        $this->assertContains('/__dev/api/gallery/deploy', $patterns);
    }

    // ── Gallery list API handler ─────────────────────────────────

    public function testGalleryListReturnsAllExamples(): void
    {
        DevAdmin::register();
        $callback = $this->findRouteCallback('GET', '/__dev/api/gallery');
        $this->assertNotNull($callback, 'Gallery list route not found');

        $request = Request::create('GET', '/__dev/api/gallery');
        $response = new Response(true);

        /** @var Response $result */
        $result = $callback($request, $response);
        $data = json_decode($result->getBody(), true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('gallery', $data);
        $this->assertArrayHasKey('count', $data);
        $this->assertCount(count(self::$expectedExamples), $data['gallery']);
        $this->assertEquals(count(self::$expectedExamples), $data['count']);
    }

    public function testGalleryListItemsHaveRequiredFields(): void
    {
        DevAdmin::register();
        $callback = $this->findRouteCallback('GET', '/__dev/api/gallery');
        $this->assertNotNull($callback);

        $request = Request::create('GET', '/__dev/api/gallery');
        $response = new Response(true);

        /** @var Response $result */
        $result = $callback($request, $response);
        $data = json_decode($result->getBody(), true);

        foreach ($data['gallery'] as $item) {
            $this->assertArrayHasKey('id', $item, 'Gallery item missing id');
            $this->assertArrayHasKey('name', $item, 'Gallery item missing name');
            $this->assertArrayHasKey('description', $item, 'Gallery item missing description');
            $this->assertArrayHasKey('files', $item, 'Gallery item missing files list');
        }
    }

    public function testGalleryListItemsHaveFiles(): void
    {
        DevAdmin::register();
        $callback = $this->findRouteCallback('GET', '/__dev/api/gallery');
        $this->assertNotNull($callback);

        $request = Request::create('GET', '/__dev/api/gallery');
        $response = new Response(true);

        /** @var Response $result */
        $result = $callback($request, $response);
        $data = json_decode($result->getBody(), true);

        foreach ($data['gallery'] as $item) {
            $this->assertIsArray($item['files']);
            $this->assertNotEmpty($item['files'], "Gallery item '{$item['id']}' has no files");
        }
    }

    public function testGalleryListItemsSorted(): void
    {
        DevAdmin::register();
        $callback = $this->findRouteCallback('GET', '/__dev/api/gallery');
        $this->assertNotNull($callback);

        $request = Request::create('GET', '/__dev/api/gallery');
        $response = new Response(true);

        /** @var Response $result */
        $result = $callback($request, $response);
        $data = json_decode($result->getBody(), true);

        $ids = array_column($data['gallery'], 'id');
        $sorted = $ids;
        sort($sorted);
        $this->assertEquals($sorted, $ids, 'Gallery items should be sorted alphabetically');
    }
}
