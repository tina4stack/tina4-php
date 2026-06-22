<?php

use PHPUnit\Framework\TestCase;
use Tina4\Swagger;
use Tina4\Router;

/**
 * v3.13.42 Swagger configurability — PHP mirror contract tests.
 *
 * Locks the four gaps closed in 3.13.42 (parity with Python master
 * tests/test_swagger_v3_13_42.py):
 *   1. Configurable security schemes (bearerFormat, apiKey) + per-route
 *      security + scopes (oauth2 scopes kept, http/apiKey scopes dropped).
 *   2. Path include/exclude filtering (framework internals always excluded).
 *   3. OpenAPI 3.1 opt-in (default 3.0.3).
 *   4. Reusable custom component schemas via addSchema + request/response refs.
 */
class SwaggerConfigV31342Test extends TestCase
{
    /**
     * Env vars this suite toggles — cleared before and after each test so no
     * value leaks between tests (or from a developer's real environment).
     *
     * @var array<int, string>
     */
    private array $envKeys = [
        'TINA4_SWAGGER_OPENAPI',
        'TINA4_SWAGGER_BEARER_FORMAT',
        'TINA4_SWAGGER_API_KEY_NAME',
        'TINA4_SWAGGER_API_KEY_IN',
        'TINA4_SWAGGER_DEFAULT_SCHEME',
        'TINA4_SWAGGER_INCLUDE',
        'TINA4_SWAGGER_EXCLUDE',
        'TINA4_SWAGGER_SERVERS',
    ];

    protected function setUp(): void
    {
        Router::clear();
        \Tina4\DotEnv::resetEnv();
        Swagger::resetRegistry();
        $this->clearEnv();
    }

    protected function tearDown(): void
    {
        Router::clear();
        Swagger::resetRegistry();
        $this->clearEnv();
    }

    private function clearEnv(): void
    {
        foreach ($this->envKeys as $k) {
            putenv($k);
            unset($_ENV[$k]);
        }
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
    }

    // ── OpenAPI version ───────────────────────────────────────────────

    public function testDefaultOpenApiIs303(): void
    {
        Router::get('/api/v1/users', fn($req, $res) => $res);
        $spec = Swagger::generate();
        $this->assertSame('3.0.3', $spec['openapi']);
    }

    public function testOptInOpenApi31(): void
    {
        $this->setEnv('TINA4_SWAGGER_OPENAPI', '3.1');
        Router::get('/api/v1/users', fn($req, $res) => $res);
        $spec = Swagger::generate();
        $this->assertSame('3.1.0', $spec['openapi']);
    }

    // ── Configurable security schemes ─────────────────────────────────

    public function testBearerFormatConfigurable(): void
    {
        $this->setEnv('TINA4_SWAGGER_BEARER_FORMAT', 'opaque');
        Router::get('/health', fn($req, $res) => $res);
        $spec = Swagger::generate();
        $this->assertSame('opaque', $spec['components']['securitySchemes']['bearerAuth']['bearerFormat']);
    }

    public function testApiKeySchemeFromEnv(): void
    {
        $this->setEnv('TINA4_SWAGGER_API_KEY_NAME', 'X-Api-Key');
        Router::get('/health', fn($req, $res) => $res);
        $schemes = Swagger::generate()['components']['securitySchemes'];
        $this->assertSame(
            ['type' => 'apiKey', 'name' => 'X-Api-Key', 'in' => 'header'],
            $schemes['apiKeyAuth']
        );
    }

    public function testDefaultSchemeDrivesSecuredRoutes(): void
    {
        $this->setEnv('TINA4_SWAGGER_API_KEY_NAME', 'X-Api-Key');
        $this->setEnv('TINA4_SWAGGER_DEFAULT_SCHEME', 'apiKeyAuth');
        // A plain write route is secure-by-default (no @security) -> default scheme.
        Router::post('/api/v1/things', fn($req, $res) => $res);
        $op = Swagger::generate()['paths']['/api/v1/things']['post'];
        $this->assertSame([['apiKeyAuth' => []]], $op['security']);
    }

    public function testRegisteredSchemeAppears(): void
    {
        Swagger::addSecurityScheme('oauth2', [
            'type' => 'oauth2',
            'flows' => ['clientCredentials' => [
                'tokenUrl' => 'https://x/token',
                'scopes' => ['read:users' => 'Read'],
            ]],
        ]);
        Router::get('/health', fn($req, $res) => $res);
        $schemes = Swagger::generate()['components']['securitySchemes'];
        $this->assertSame('oauth2', $schemes['oauth2']['type']);
    }

    // ── Per-route security + scopes ───────────────────────────────────

    public function testExplicitSecurityOverridesDefault(): void
    {
        // A GET is public by default; explicit security makes it secured.
        Router::get('/api/v1/me', fn($req, $res) => $res)
            ->swagger(['security' => 'bearerAuth']);
        $op = Swagger::generate()['paths']['/api/v1/me']['get'];
        $this->assertSame([['bearerAuth' => []]], $op['security']);
    }

    public function testPublicMarksNoSecurity(): void
    {
        // A write route is secure-by-default, but security 'public' wins.
        Router::post('/api/v1/webhook', fn($req, $res) => $res)
            ->swagger(['security' => 'public']);
        $op = Swagger::generate()['paths']['/api/v1/webhook']['post'];
        $this->assertSame([], $op['security']);
        $this->assertArrayNotHasKey('401', $op['responses']);
    }

    public function testOauth2ScopesPreserved(): void
    {
        Swagger::addSecurityScheme('oauth2', [
            'type' => 'oauth2',
            'flows' => ['clientCredentials' => [
                'tokenUrl' => 'https://x/token',
                'scopes' => ['read:users' => 'Read', 'write:users' => 'Write'],
            ]],
        ]);
        Router::get('/api/v1/users', fn($req, $res) => $res)
            ->swagger(['security' => 'oauth2', 'scopes' => ['read:users', 'write:users']]);
        $op = Swagger::generate()['paths']['/api/v1/users']['get'];
        $this->assertSame([['oauth2' => ['read:users', 'write:users']]], $op['security']);
    }

    public function testScopesDroppedOnNonOauth2Scheme(): void
    {
        // Scopes on an http/bearer scheme are invalid OpenAPI -> sanitized to [].
        Router::get('/api/v1/users', fn($req, $res) => $res)
            ->swagger(['security' => 'bearerAuth', 'scopes' => ['read:users']]);
        $op = Swagger::generate()['paths']['/api/v1/users']['get'];
        $this->assertSame([['bearerAuth' => []]], $op['security']);
    }

    public function testOrRequirements(): void
    {
        Swagger::addSecurityScheme('oauth2', ['type' => 'oauth2', 'flows' => new \stdClass()]);
        Router::get('/api/v1/x', fn($req, $res) => $res)
            ->swagger(['security' => [['oauth2' => ['read']], ['bearerAuth' => []]]]);
        $op = Swagger::generate()['paths']['/api/v1/x']['get'];
        $this->assertSame([['oauth2' => ['read']], ['bearerAuth' => []]], $op['security']);
    }

    // ── Path filtering ────────────────────────────────────────────────

    private function registerFilterRoutes(): void
    {
        Router::get('/api/v1/users', fn($req, $res) => $res);
        Router::get('/dashboard', fn($req, $res) => $res);
        Router::get('/__dev/api/reload', fn($req, $res) => $res);
        // /swagger registers the UI elsewhere; use a representative internal path.
        Router::get('/swagger', fn($req, $res) => $res);
    }

    public function testFrameworkInternalsAlwaysExcluded(): void
    {
        $this->registerFilterRoutes();
        $paths = Swagger::generate()['paths'];
        $this->assertArrayNotHasKey('/__dev/api/reload', $paths);
        $this->assertArrayNotHasKey('/swagger', $paths);
        $this->assertArrayHasKey('/api/v1/users', $paths);
    }

    public function testIncludeAllowList(): void
    {
        $this->setEnv('TINA4_SWAGGER_INCLUDE', '/api/v1');
        $this->registerFilterRoutes();
        $paths = Swagger::generate()['paths'];
        $this->assertSame(['/api/v1/users'], array_keys($paths));
    }

    public function testExcludePrefix(): void
    {
        $this->setEnv('TINA4_SWAGGER_EXCLUDE', '/dashboard');
        $this->registerFilterRoutes();
        $paths = Swagger::generate()['paths'];
        $this->assertArrayNotHasKey('/dashboard', $paths);
        $this->assertArrayHasKey('/api/v1/users', $paths);
    }

    // ── Reusable custom schemas ───────────────────────────────────────

    public function testRequestSchemaRef(): void
    {
        Swagger::addSchema('CreateUser', [
            'type' => 'object',
            'properties' => ['email' => ['type' => 'string'], 'name' => ['type' => 'string']],
            'required' => ['email'],
        ]);
        Router::post('/api/v1/users', fn($req, $res) => $res)
            ->swagger(['requestSchema' => 'CreateUser']);
        $spec = Swagger::generate();
        $bodySchema = $spec['paths']['/api/v1/users']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertSame(['$ref' => '#/components/schemas/CreateUser'], $bodySchema);
        $this->assertArrayHasKey('CreateUser', $spec['components']['schemas']);
    }

    public function testResponseSchemaRefAndList(): void
    {
        Swagger::addSchema('User', ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]);
        Router::get('/api/v1/users', fn($req, $res) => $res)
            ->swagger(['responseSchemas' => [
                200 => 'User',
                201 => ['User', true],
            ]]);
        $resps = Swagger::generate()['paths']['/api/v1/users']['get']['responses'];
        $this->assertSame(
            ['$ref' => '#/components/schemas/User'],
            $resps['200']['content']['application/json']['schema']
        );
        $this->assertSame(
            ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/User']],
            $resps['201']['content']['application/json']['schema']
        );
        $this->assertArrayHasKey('User', Swagger::generate()['components']['schemas']);
    }
}
