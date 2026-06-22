<?php

use PHPUnit\Framework\TestCase;
use Tina4\Swagger;
use Tina4\Router;
use Tina4\ORM;
use Tina4\Database\Database;

/**
 * A handler whose docblock declares a file upload, so the request body is
 * documented as multipart/form-data with a binary property.
 *
 * @param file avatar Required - profile image
 * @param string caption Optional - image caption
 */
function swaggerV31340UploadHandler($request, $response)
{
    return $response;
}

/**
 * A test ORM model for the components.schemas / $ref mirror tests.
 */
class SwaggerWidget extends ORM
{
    public string $tableName = 'swagger_widgets';
    public int $id = 0;
    public string $name = '';
    public ?int $category_id = null;
    public array $foreignKeys = ['category_id' => 'SwaggerCategory'];
}

class SwaggerCategory extends ORM
{
    public string $tableName = 'swagger_categories';
    public int $id = 0;
    public string $title = '';
}

/**
 * v3.13.40 Swagger sweep — PHP mirror contract tests.
 */
class SwaggerV31340Test extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
        \Tina4\DotEnv::resetEnv();
        putenv('TINA4_SWAGGER_SERVERS');
        unset($_ENV['TINA4_SWAGGER_SERVERS']);
        ORM::bindDatabase(Database::create('sqlite::memory:'));
    }

    protected function tearDown(): void
    {
        Router::clear();
        putenv('TINA4_SWAGGER_SERVERS');
        unset($_ENV['TINA4_SWAGGER_SERVERS']);
    }

    // ── P1: ->noAuth() write route is documented as public ─────────

    public function testNoAuthWriteRouteHasNoSecurity(): void
    {
        Router::post('/public-hook', fn($req, $res) => $res)->noAuth();
        $spec = Swagger::generate();
        $op = $spec['paths']['/public-hook']['post'];
        $this->assertArrayNotHasKey('security', $op, '->noAuth() write route must not require auth in the spec');
        $this->assertArrayNotHasKey('401', $op['responses']);
    }

    public function testPlainWriteRouteStillRequiresAuth(): void
    {
        Router::post('/secure-thing', fn($req, $res) => $res);
        $spec = Swagger::generate();
        $op = $spec['paths']['/secure-thing']['post'];
        $this->assertArrayHasKey('security', $op, 'a write route is secure by default');
    }

    // ── P1: WebSocket routes do not leak an invalid "ws" method ─────

    public function testWebSocketRouteNotInPaths(): void
    {
        Router::websocket('/ws/chat', fn($conn) => null);
        $spec = Swagger::generate();
        if (is_array($spec['paths']) && isset($spec['paths']['/ws/chat'])) {
            $this->assertArrayNotHasKey('ws', $spec['paths']['/ws/chat'], "'ws' is not a valid OpenAPI method");
        } else {
            $this->assertTrue(true); // WS path excluded entirely — also acceptable
        }
    }

    // ── P3: operationId is unique across the document ──────────────

    public function testOperationIdsAreUnique(): void
    {
        Router::get('/users/{id}', fn($req, $res) => $res);
        Router::get('/users/id', fn($req, $res) => $res);
        $spec = Swagger::generate();
        $ids = [
            $spec['paths']['/users/{id}']['get']['operationId'],
            $spec['paths']['/users/id']['get']['operationId'],
        ];
        $this->assertCount(2, array_unique($ids), 'operationIds must be unique: ' . implode(',', $ids));
    }

    // ── Feature: ORM model -> components.schemas + $ref ────────────

    public function testModelBecomesComponentSchema(): void
    {
        Router::post('/api/swagger_widgets', fn($req, $res) => $res)
            ->swagger(['model' => SwaggerWidget::class]);
        $spec = Swagger::generate();

        $this->assertArrayHasKey('schemas', $spec['components']);
        $this->assertArrayHasKey('SwaggerWidget', $spec['components']['schemas']);
        $props = $spec['components']['schemas']['SwaggerWidget']['properties'];
        $this->assertSame('integer', $props['id']['type']);
        $this->assertTrue($props['id']['readOnly'], 'auto-increment PK is read-only');
        $this->assertSame('string', $props['name']['type']);
        // foreign-key column is an integer reference
        $this->assertSame('integer', $props['category_id']['type']);

        $bodySchema = $spec['paths']['/api/swagger_widgets']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertSame(['$ref' => '#/components/schemas/SwaggerWidget'], $bodySchema);
    }

    public function testListRouteResponseIsArrayOfRef(): void
    {
        Router::get('/api/swagger_widgets', fn($req, $res) => $res)
            ->swagger(['model' => SwaggerWidget::class, 'modelList' => true]);
        $spec = Swagger::generate();
        $schema = $spec['paths']['/api/swagger_widgets']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/SwaggerWidget']], $schema);
    }

    // ── Feature: multipart request body from @param file ───────────

    public function testMultipartRequestBody(): void
    {
        Router::post('/upload', 'swaggerV31340UploadHandler');
        $spec = Swagger::generate();
        $content = $spec['paths']['/upload']['post']['requestBody']['content'];
        $this->assertArrayHasKey('multipart/form-data', $content);
        $avatar = $content['multipart/form-data']['schema']['properties']['avatar'];
        $this->assertSame('string', $avatar['type']);
        $this->assertSame('binary', $avatar['format']);
    }

    // ── Feature: servers[] + multi-server + top-level tags[] ───────

    public function testDefaultServersBlock(): void
    {
        Router::get('/health', fn($req, $res) => $res);
        $spec = Swagger::generate();
        $this->assertArrayHasKey('servers', $spec);
        $this->assertNotEmpty($spec['servers']);
    }

    public function testMultiServerFromEnv(): void
    {
        putenv('TINA4_SWAGGER_SERVERS=https://a.test, https://b.test');
        $_ENV['TINA4_SWAGGER_SERVERS'] = 'https://a.test, https://b.test';
        Router::get('/health', fn($req, $res) => $res);
        $spec = Swagger::generate();
        $urls = array_map(fn($s) => $s['url'], $spec['servers']);
        $this->assertSame(['https://a.test', 'https://b.test'], $urls);
    }

    public function testTopLevelTagsArray(): void
    {
        Router::get('/api/swagger_widgets', fn($req, $res) => $res)
            ->swagger(['tags' => ['widgets'], 'model' => SwaggerWidget::class]);
        $spec = Swagger::generate();
        $this->assertArrayHasKey('tags', $spec);
        $names = array_map(fn($t) => $t['name'], $spec['tags']);
        $this->assertContains('widgets', $names);
    }
}
