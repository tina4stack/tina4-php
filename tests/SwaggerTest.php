<?php

use PHPUnit\Framework\TestCase;
use Tina4\Swagger;
use Tina4\Router;

class SwaggerTest extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
    }

    protected function tearDown(): void
    {
        Router::clear();
    }

    // ── Spec Structure ──────────────────────────────────────────

    public function testGenerateSpecReturnsArray(): void
    {
        $spec = Swagger::generateSpec();
        $this->assertIsArray($spec);
    }

    public function testSpecHasOpenApiVersion(): void
    {
        $spec = Swagger::generateSpec();
        $this->assertSame('3.0.3', $spec['openapi']);
    }

    public function testSpecHasInfoBlock(): void
    {
        $spec = Swagger::generateSpec();
        $this->assertArrayHasKey('info', $spec);
        $this->assertArrayHasKey('title', $spec['info']);
        $this->assertArrayHasKey('version', $spec['info']);
        $this->assertArrayHasKey('description', $spec['info']);
    }

    public function testSpecHasComponentsAndSecuritySchemes(): void
    {
        $spec = Swagger::generateSpec();
        $this->assertArrayHasKey('components', $spec);
        $this->assertArrayHasKey('securitySchemes', $spec['components']);
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
    }

    public function testBearerAuthSchemeIsJwt(): void
    {
        $spec = Swagger::generateSpec();
        $scheme = $spec['components']['securitySchemes']['bearerAuth'];
        $this->assertSame('http', $scheme['type']);
        $this->assertSame('bearer', $scheme['scheme']);
        $this->assertSame('JWT', $scheme['bearerFormat']);
    }

    // ── Info Fields ─────────────────────────────────────────────

    public function testDefaultTitle(): void
    {
        $spec = Swagger::generateSpec();
        $this->assertSame('Tina4 API', $spec['info']['title']);
    }

    public function testDefaultVersion(): void
    {
        $spec = Swagger::generateSpec();
        $this->assertSame('1.0.0', $spec['info']['version']);
    }

    public function testCustomTitle(): void
    {
        $spec = Swagger::generateSpec('My API');
        $this->assertSame('My API', $spec['info']['title']);
    }

    public function testCustomVersion(): void
    {
        $spec = Swagger::generateSpec('API', '2.5.0');
        $this->assertSame('2.5.0', $spec['info']['version']);
    }

    public function testDefaultDescription(): void
    {
        $spec = Swagger::generateSpec();
        $this->assertSame('Auto-generated from Tina4 routes', $spec['info']['description']);
    }

    // ── Empty Paths ─────────────────────────────────────────────

    public function testEmptyPathsWhenNoRoutes(): void
    {
        $spec = Swagger::generateSpec();
        // Empty paths should be stdClass for JSON {} encoding
        $this->assertInstanceOf(\stdClass::class, $spec['paths']);
    }

    // ── Path Registration ───────────────────────────────────────

    public function testSingleGetRoute(): void
    {
        Router::get('/health', fn($req, $res) => $res->json(['ok' => true]));
        $spec = Swagger::generateSpec();
        $this->assertArrayHasKey('/health', $spec['paths']);
        $this->assertArrayHasKey('get', $spec['paths']['/health']);
    }

    public function testSinglePostRoute(): void
    {
        Router::post('/users', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $this->assertArrayHasKey('/users', $spec['paths']);
        $this->assertArrayHasKey('post', $spec['paths']['/users']);
    }

    public function testPutRoute(): void
    {
        Router::put('/users/{id}', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $this->assertArrayHasKey('/users/{id}', $spec['paths']);
        $this->assertArrayHasKey('put', $spec['paths']['/users/{id}']);
    }

    public function testPatchRoute(): void
    {
        Router::patch('/users/{id}', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $this->assertArrayHasKey('patch', $spec['paths']['/users/{id}']);
    }

    public function testDeleteRoute(): void
    {
        Router::delete('/users/{id}', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $this->assertArrayHasKey('delete', $spec['paths']['/users/{id}']);
    }

    // ── Multiple Methods on Same Path ───────────────────────────

    public function testMultipleMethodsSamePath(): void
    {
        Router::get('/items', fn($req, $res) => $res->json([]));
        Router::post('/items', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $this->assertArrayHasKey('get', $spec['paths']['/items']);
        $this->assertArrayHasKey('post', $spec['paths']['/items']);
    }

    // ── Path Parameters ─────────────────────────────────────────

    public function testPathParamExtracted(): void
    {
        Router::get('/users/{id}', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $op = $spec['paths']['/users/{id}']['get'];
        $this->assertNotEmpty($op['parameters']);
        $param = $op['parameters'][0];
        $this->assertSame('id', $param['name']);
        $this->assertSame('path', $param['in']);
        $this->assertTrue($param['required']);
    }

    public function testPathParamSchemaIsString(): void
    {
        Router::get('/users/{id}', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $param = $spec['paths']['/users/{id}']['get']['parameters'][0];
        $this->assertSame('string', $param['schema']['type']);
    }

    public function testMultiplePathParams(): void
    {
        Router::get('/orgs/{orgId}/users/{userId}', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $params = $spec['paths']['/orgs/{orgId}/users/{userId}']['get']['parameters'];
        $this->assertCount(2, $params);
        $names = array_column($params, 'name');
        $this->assertContains('orgId', $names);
        $this->assertContains('userId', $names);
    }

    public function testCatchAllParamNormalised(): void
    {
        Router::get('/files/{path:.*}', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        // Catch-all should be normalized to {path}
        $this->assertArrayHasKey('/files/{path}', $spec['paths']);
    }

    public function testNoParamsOnSimplePath(): void
    {
        Router::get('/health', fn($req, $res) => $res->json(['ok' => true]));
        $spec = Swagger::generateSpec();
        $op = $spec['paths']['/health']['get'];
        $this->assertArrayNotHasKey('parameters', $op);
    }

    // ── Request Bodies ──────────────────────────────────────────

    public function testPostRouteHasRequestBody(): void
    {
        Router::post('/users', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $op = $spec['paths']['/users']['post'];
        $this->assertArrayHasKey('requestBody', $op);
    }

    public function testPutRouteHasRequestBody(): void
    {
        Router::put('/users/{id}', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $this->assertArrayHasKey('requestBody', $spec['paths']['/users/{id}']['put']);
    }

    public function testPatchRouteHasRequestBody(): void
    {
        Router::patch('/users/{id}', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $this->assertArrayHasKey('requestBody', $spec['paths']['/users/{id}']['patch']);
    }

    public function testGetRouteHasNoRequestBody(): void
    {
        Router::get('/users', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $this->assertArrayNotHasKey('requestBody', $spec['paths']['/users']['get']);
    }

    public function testDeleteRouteHasNoRequestBody(): void
    {
        Router::delete('/users/{id}', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $this->assertArrayNotHasKey('requestBody', $spec['paths']['/users/{id}']['delete']);
    }

    public function testRequestBodyContentTypeIsJson(): void
    {
        Router::post('/users', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $rb = $spec['paths']['/users']['post']['requestBody'];
        $this->assertArrayHasKey('application/json', $rb['content']);
    }

    public function testRequestBodySchemaIsObject(): void
    {
        Router::post('/users', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $schema = $spec['paths']['/users']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertSame('object', $schema['type']);
    }

    // ── Responses ───────────────────────────────────────────────

    public function testDefaultResponseIs200(): void
    {
        Router::get('/health', fn($req, $res) => $res->json(['ok' => true]));
        $spec = Swagger::generateSpec();
        $responses = $spec['paths']['/health']['get']['responses'];
        $this->assertArrayHasKey('200', $responses);
        $this->assertSame('Successful response', $responses['200']['description']);
    }

    // ── Tags ────────────────────────────────────────────────────

    public function testTagInferredFromFirstSegment(): void
    {
        Router::get('/users', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $tags = $spec['paths']['/users']['get']['tags'];
        $this->assertSame(['users'], $tags);
    }

    public function testTagSkipsVersionPrefix(): void
    {
        Router::get('/v1/users', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $tags = $spec['paths']['/v1/users']['get']['tags'];
        $this->assertSame(['users'], $tags);
    }

    public function testTagDefaultForRootPath(): void
    {
        Router::get('/', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $tags = $spec['paths']['/']['get']['tags'];
        $this->assertSame(['default'], $tags);
    }

    public function testTagDefaultForParamOnlyPath(): void
    {
        Router::get('/{id}', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $tags = $spec['paths']['/{id}']['get']['tags'];
        $this->assertSame(['default'], $tags);
    }

    // ── Operation ID ────────────────────────────────────────────

    public function testOperationIdGenerated(): void
    {
        Router::get('/users/{id}', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $opId = $spec['paths']['/users/{id}']['get']['operationId'];
        $this->assertNotEmpty($opId);
        $this->assertStringStartsWith('get', $opId);
    }

    // ── Summary ─────────────────────────────────────────────────

    public function testSummaryIncludesMethodAndPath(): void
    {
        Router::get('/health', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $summary = $spec['paths']['/health']['get']['summary'];
        $this->assertSame('GET /health', $summary);
    }

    // ── Secure Routes ───────────────────────────────────────────

    public function testSecureRouteHasSecurityRequirement(): void
    {
        Router::get('/admin/users', fn($req, $res) => $res->json([]))->secure();
        $spec = Swagger::generateSpec();
        $op = $spec['paths']['/admin/users']['get'];
        $this->assertArrayHasKey('security', $op);
        $this->assertSame([['bearerAuth' => []]], $op['security']);
    }

    public function testSecureRouteHas401Response(): void
    {
        Router::get('/admin/users', fn($req, $res) => $res->json([]))->secure();
        $spec = Swagger::generateSpec();
        $responses = $spec['paths']['/admin/users']['get']['responses'];
        $this->assertArrayHasKey('401', $responses);
        $this->assertSame('Unauthorized', $responses['401']['description']);
    }

    public function testNonSecureRouteHasNoSecurityBlock(): void
    {
        Router::get('/public', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $op = $spec['paths']['/public']['get'];
        $this->assertArrayNotHasKey('security', $op);
    }

    // ── Swagger Routes Excluded ─────────────────────────────────

    public function testSwaggerRouteExcluded(): void
    {
        Swagger::register();
        $spec = Swagger::generateSpec();
        if (is_array($spec['paths'])) {
            $this->assertArrayNotHasKey('/swagger', $spec['paths']);
            $this->assertArrayNotHasKey('/swagger/openapi.json', $spec['paths']);
        } else {
            // stdClass means empty
            $this->assertInstanceOf(\stdClass::class, $spec['paths']);
        }
    }

    // ── JSON Output ─────────────────────────────────────────────

    public function testSpecEncodesToValidJson(): void
    {
        Router::get('/test', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $json = json_encode($spec);
        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
    }

    public function testEmptySpecEncodesToJsonWithEmptyPaths(): void
    {
        $spec = Swagger::generateSpec();
        $json = json_encode($spec);
        $decoded = json_decode($json, true);
        // paths should encode as {} (empty object)
        $this->assertEmpty($decoded['paths']);
    }

    // ── Register ────────────────────────────────────────────────

    public function testRegisterAddsSwaggerRoutes(): void
    {
        Swagger::register();
        $routes = Router::getRoutes();
        $patterns = array_column($routes, 'pattern');
        $this->assertContains('/swagger', $patterns);
        $this->assertContains('/swagger/openapi.json', $patterns);
    }

    // ── Multiple Routes ─────────────────────────────────────────

    public function testMultipleRoutesAllAppear(): void
    {
        Router::get('/a', fn($req, $res) => $res->json([]));
        Router::get('/b', fn($req, $res) => $res->json([]));
        Router::post('/c', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $this->assertArrayHasKey('/a', $spec['paths']);
        $this->assertArrayHasKey('/b', $spec['paths']);
        $this->assertArrayHasKey('/c', $spec['paths']);
    }

    public function testRouteCountMatchesSpec(): void
    {
        Router::get('/x', fn($req, $res) => $res->json([]));
        Router::post('/y', fn($req, $res) => $res->json([]));
        Router::delete('/z', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $totalOps = 0;
        foreach ($spec['paths'] as $path => $methods) {
            $totalOps += count($methods);
        }
        $this->assertSame(3, $totalOps);
    }

    // ── Grouped Routes ──────────────────────────────────────────

    public function testGroupedRoutesIncludePrefix(): void
    {
        Router::group('/api/v1', function () {
            Router::get('/users', fn($req, $res) => $res->json([]));
        });
        $spec = Swagger::generateSpec();
        $this->assertArrayHasKey('/api/v1/users', $spec['paths']);
    }

    public function testV2VersionPrefixTagSkip(): void
    {
        Router::get('/v2/orders', fn($req, $res) => $res->json([]));
        $spec = Swagger::generateSpec();
        $tags = $spec['paths']['/v2/orders']['get']['tags'];
        $this->assertSame(['orders'], $tags);
    }
}
