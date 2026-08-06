<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * 3.13.96 Swagger parity lock-in (authority:
 * tina4-documentation/plan/v3/parity-3.13.96-decisions.md). Python is the
 * reference; each rule is measured, each test has a positive AND a negative
 * half so the mutation that reintroduces the old behaviour goes red.
 *
 *   S2  components.schemas.<Model>.required is derived from column nullability
 *   S3  servers[0].url defaults to "/", info.description defaults to ""
 *   S4  info.contact carries name + url + email (CONTACT_TEAM / _URL / _EMAIL)
 *   S7  framework-internal routes (/, /ai, /rag, /vision, /embed, /image,
 *       /__feedback, /swagger, /__dev) are never in the public document
 */

use PHPUnit\Framework\TestCase;
use Tina4\Swagger;
use Tina4\Router;
use Tina4\Database\Database;
use Tina4\ORM;

/**
 * Model for the S2 required-from-nullability contract:
 *   id           int, auto-increment PK  -> readOnly, NEVER required
 *   name         string (NOT NULL)        -> required
 *   description  ?string (nullable)       -> optional
 */
class SwaggerRequiredItem extends ORM
{
    public string $tableName = 'swagger_required_items';
    public int $id = 0;
    public string $name = '';
    public ?string $description = null;
}

class SwaggerParity31396Test extends TestCase
{
    /** @var array<int, string> Env keys saved/cleared around each test. */
    private array $envKeys = [
        'TINA4_SWAGGER_DESCRIPTION',
        'TINA4_SWAGGER_SERVERS',
        'SWAGGER_DEV_URL',
        'TINA4_SWAGGER_CONTACT_EMAIL',
        'TINA4_SWAGGER_CONTACT_TEAM',
        'TINA4_SWAGGER_CONTACT_URL',
        'SWAGGER_CONTACT_TEAM',
        'SWAGGER_CONTACT_URL',
    ];

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        Router::clear();
        \Tina4\DotEnv::resetEnv();
        foreach ($this->envKeys as $key) {
            $this->saved[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key]);
        }
        ORM::bindDatabase(Database::create('sqlite::memory:'));
    }

    protected function tearDown(): void
    {
        Router::clear();
        foreach ($this->envKeys as $key) {
            if (($this->saved[$key] ?? false) !== false) {
                putenv("{$key}={$this->saved[$key]}");
            } else {
                putenv($key);
                unset($_ENV[$key]);
            }
        }
    }

    // ── S3: servers[0].url defaults to "/", description defaults to "" ──

    public function testServersDefaultsToSlash(): void
    {
        Router::get('/health', fn($req, $res) => $res);
        $spec = Swagger::generate();
        $this->assertSame('/', $spec['servers'][0]['url'], 'servers[0].url must default to "/" (correct under any port/proxy)');
    }

    public function testServersDefaultIsNotAHardCodedPort(): void
    {
        // The negative: the old default 7145 is wrong off that port.
        Router::get('/health', fn($req, $res) => $res);
        $spec = Swagger::generate();
        $this->assertStringNotContainsString('7145', $spec['servers'][0]['url']);
        $this->assertStringNotContainsString('localhost', $spec['servers'][0]['url']);
    }

    public function testSwaggerServersEnvStillOverridesTheSlashDefault(): void
    {
        putenv('TINA4_SWAGGER_SERVERS=https://api.example.test');
        $_ENV['TINA4_SWAGGER_SERVERS'] = 'https://api.example.test';
        Router::get('/health', fn($req, $res) => $res);
        $spec = Swagger::generate();
        $this->assertSame('https://api.example.test', $spec['servers'][0]['url']);
    }

    public function testDescriptionDefaultsToEmptyString(): void
    {
        $spec = Swagger::generate();
        $this->assertSame('', $spec['info']['description'], 'info.description must default to "" (parity across all four)');
    }

    // ── S4: info.contact carries name + url + email ──

    public function testContactEmitsNameUrlAndEmail(): void
    {
        putenv('TINA4_SWAGGER_CONTACT_TEAM=API Team');
        putenv('TINA4_SWAGGER_CONTACT_URL=https://support.example.test');
        putenv('TINA4_SWAGGER_CONTACT_EMAIL=api@example.test');
        $spec = Swagger::generate();
        $contact = $spec['info']['contact'] ?? [];
        $this->assertSame('API Team', $contact['name'] ?? null, 'CONTACT_TEAM -> info.contact.name');
        $this->assertSame('https://support.example.test', $contact['url'] ?? null, 'CONTACT_URL -> info.contact.url');
        $this->assertSame('api@example.test', $contact['email'] ?? null);
    }

    public function testContactTeamAndUrlAreNotSilentlyDropped(): void
    {
        // The negative: PHP alone used to emit ONLY the email.
        putenv('TINA4_SWAGGER_CONTACT_TEAM=API Team');
        putenv('TINA4_SWAGGER_CONTACT_URL=https://support.example.test');
        $spec = Swagger::generate();
        $contact = $spec['info']['contact'] ?? [];
        $this->assertArrayHasKey('name', $contact, 'contact.name was dropped');
        $this->assertArrayHasKey('url', $contact, 'contact.url was dropped');
    }

    public function testLegacyContactTeamAndUrlAreHonouredAsFallback(): void
    {
        putenv('SWAGGER_CONTACT_TEAM=Legacy Team');
        putenv('SWAGGER_CONTACT_URL=https://legacy.example.test');
        $spec = Swagger::generate();
        $contact = $spec['info']['contact'] ?? [];
        $this->assertSame('Legacy Team', $contact['name'] ?? null);
        $this->assertSame('https://legacy.example.test', $contact['url'] ?? null);
    }

    // ── S2: components.schemas.<Model>.required from nullability ──

    public function testModelSchemaRequiredIsDerivedFromNullability(): void
    {
        Router::post('/api/req_items', fn($req, $res) => $res)
            ->swagger(['model' => SwaggerRequiredItem::class]);
        $spec = Swagger::generate();

        $schema = $spec['components']['schemas']['SwaggerRequiredItem'] ?? [];
        $this->assertArrayHasKey('required', $schema, 'a schema with a NOT NULL column must carry required');
        $this->assertSame(['name'], $schema['required'], 'only the NOT NULL, non-auto column is required');
    }

    public function testAutoIncrementPkAndNullableColumnAreNotRequired(): void
    {
        // The negative: id (auto PK, readOnly) and description (?string) must
        // NOT appear in required, or a generated client demands DB-generated
        // and optional fields.
        Router::post('/api/req_items', fn($req, $res) => $res)
            ->swagger(['model' => SwaggerRequiredItem::class]);
        $spec = Swagger::generate();
        $schema = $spec['components']['schemas']['SwaggerRequiredItem'];

        $this->assertNotContains('id', $schema['required']);
        $this->assertNotContains('description', $schema['required']);
        $this->assertTrue($schema['properties']['id']['readOnly'] ?? false, 'auto-increment PK stays readOnly');
    }

    // ── S7: only the application is documented ──

    public function testFrameworkInternalRoutesAreExcluded(): void
    {
        $internal = [
            '/', '/ai', '/ai/api/chat', '/rag', '/vision', '/embed',
            '/image', '/image/v1/images/generations',
            '/__feedback/api/turn', '/__feedback/widget.js',
            '/swagger/openapi.json', '/__dev/api/routes',
        ];
        foreach ($internal as $path) {
            Router::get($path, fn($req, $res) => $res);
        }
        Router::get('/api/items', fn($req, $res) => $res); // the application

        $spec = Swagger::generate();
        $paths = $spec['paths'];
        $paths = $paths instanceof \stdClass ? [] : $paths;

        $this->assertArrayHasKey('/api/items', $paths, 'the application route must be documented');
        foreach ($internal as $path) {
            $this->assertArrayNotHasKey($path, $paths, "framework-internal {$path} must not be in the public document");
        }
    }

    public function testAppRoutesUnderNonReservedPrefixesSurvive(): void
    {
        // The exclusion is boundary-anchored: /airports and /images (plural)
        // are NOT under the reserved /ai and /image prefixes and must survive.
        Router::get('/airports', fn($req, $res) => $res);
        Router::get('/images/list', fn($req, $res) => $res);
        $spec = Swagger::generate();
        $paths = $spec['paths'] instanceof \stdClass ? [] : $spec['paths'];
        $this->assertArrayHasKey('/airports', $paths, '/airports is not under the /ai service prefix');
        $this->assertArrayHasKey('/images/list', $paths, '/images is not under the /image service prefix');
    }
}
