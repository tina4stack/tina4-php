<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * SWAGGER CONTRACT SUITE (3.13.96).
 *
 * The ten invariants of the shared fixture
 *   tina4-documentation/plan/v3/fixtures/swagger_contract.json
 * gated on the SHIPPED behaviour, proved the way the four-way audit measured it:
 * one real Tina4 application per framework, served by a REAL server, with the
 * document fetched over a REAL socket from /swagger/openapi.json. No mocks, no
 * generate() shortcut — the front controller (tests/fixtures/swagger_contract_app.php)
 * is booted three times with different environments and this suite talks to it
 * over HTTP exactly as a spec-compliant client or gateway would.
 *
 * PHP was one of the two frameworks that shipped a STRUCTURALLY INVALID document
 * (the {id:int} type token leaked into the path key with no parameter declared;
 * fixed in 5a4f838d). Invariant #1 runs the document through a REAL OpenAPI
 * validator (cebe/php-openapi, a TEST-ONLY require-dev — the framework stays
 * zero-dependency) and would have caught that on its own.
 *
 * One method per invariant (the shared auditor matches the normalized method
 * name — lowercased, non-alphanumerics stripped, a leading "test" removed — so
 * each name CONTAINS the fixture's stem). Positive AND negative controls live
 * inside the method the invariant belongs to.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SwaggerContractTest extends TestCase
{
    /** Enabled via TINA4_DEBUG, no TINA4_SWAGGER_* set — pure defaults. */
    private static ?TestServer $defaultsServer = null;

    /** Enabled + TINA4_SWAGGER_CONTACT_TEAM/_URL/_EMAIL set — env surface. */
    private static ?TestServer $configuredServer = null;

    /** TINA4_SWAGGER_ENABLED=false — nothing swagger is served. */
    private static ?TestServer $disabledServer = null;

    /** The document fetched once from the defaults server. */
    private static array $defaultsDoc = [];

    /** The document fetched once from the configured (contact env) server. */
    private static array $configuredDoc = [];

    /**
     * A reaped temp working directory for every server, so the framework's
     * dev-mode log file and per-request session files land there (and are swept
     * by the bootstrap TempPath reaper) instead of in tests/fixtures. The
     * bundled Swagger UI assets resolve via an absolute framework path, so they
     * still serve regardless of the working directory.
     */
    private static string $workDir = '';

    private const CONTACT_TEAM  = 'Contract Team';
    private const CONTACT_URL   = 'https://contract.example.test/support';
    private const CONTACT_EMAIL = 'contract@example.test';

    public static function setUpBeforeClass(): void
    {
        $app = dirname(__DIR__) . '/tests/fixtures/swagger_contract_app.php';
        self::$workDir = \TempPath::dir('tina4_swagger_contract_', 0777);

        // Defaults: swagger enabled through TINA4_DEBUG (NOT a TINA4_SWAGGER_*
        // var), so the "no TINA4_SWAGGER_* set" premise of the info/servers rule
        // holds while the endpoint is still reachable.
        $defaultsEnv = self::baseEnv();
        $defaultsEnv['TINA4_DEBUG'] = 'true';
        self::$defaultsServer = TestServer::start($app, $defaultsEnv, self::$workDir);
        self::$defaultsDoc = self::fetchJson(self::$defaultsServer->port, '/swagger/openapi.json');

        // Configured: the contact env surface PHP alone used to drop.
        $configuredEnv = self::baseEnv();
        $configuredEnv['TINA4_DEBUG'] = 'true';
        $configuredEnv['TINA4_SWAGGER_CONTACT_TEAM'] = self::CONTACT_TEAM;
        $configuredEnv['TINA4_SWAGGER_CONTACT_URL'] = self::CONTACT_URL;
        $configuredEnv['TINA4_SWAGGER_CONTACT_EMAIL'] = self::CONTACT_EMAIL;
        self::$configuredServer = TestServer::start($app, $configuredEnv, self::$workDir);
        self::$configuredDoc = self::fetchJson(self::$configuredServer->port, '/swagger/openapi.json');

        // Disabled: explicit false must win even with debug on.
        $disabledEnv = self::baseEnv();
        $disabledEnv['TINA4_DEBUG'] = 'true';
        $disabledEnv['TINA4_SWAGGER_ENABLED'] = 'false';
        self::$disabledServer = TestServer::start($app, $disabledEnv, self::$workDir);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$defaultsServer, self::$configuredServer, self::$disabledServer] as $server) {
            $server?->stop();
        }
        self::$defaultsServer = self::$configuredServer = self::$disabledServer = null;
    }

    // ── invariant 1: the-document-validates ──────────────────────────────

    /**
     * The fetched document passes a REAL OpenAPI validator for the version it
     * declares, for an app that has routes, a path param, an ORM model and a
     * secured route. Negative controls prove the validator is a genuine gate:
     * a document missing info.version is REJECTED, and a dangling $ref is caught.
     */
    public function testDocumentValidates(): void
    {
        $doc = self::$defaultsDoc;

        // The app really carries the four ingredients the rule names — so a pass
        // is not vacuous over an empty document.
        $this->assertSame('3.0.3', $doc['openapi'] ?? null, 'the declared OpenAPI version');
        $this->assertArrayHasKey('/health', $doc['paths'], 'an ordinary route is documented');
        $this->assertArrayHasKey('/api/kind/int/{value}', $doc['paths'], 'a path-param route is documented');
        $this->assertArrayHasKey('ContractWidget', $doc['components']['schemas'] ?? [], 'the ORM model schema is present');
        $this->assertArrayHasKey('security', $doc['paths']['/api/widgets']['post'], 'a secured route is present');

        [$valid, $errors] = self::validateOpenApi($doc);
        $this->assertTrue($valid, "the document must pass OpenAPI validation:\n  - " . implode("\n  - ", $errors));
        $this->assertSame([], self::danglingRefs($doc), 'every $ref must resolve inside the document');

        // NEGATIVE: the validator must REJECT an invalid document, else #1 is a
        // ghost gate that would pass over anything.
        $missingVersion = $doc;
        unset($missingVersion['info']['version']);
        [$stillValid] = self::validateOpenApi($missingVersion);
        $this->assertFalse($stillValid, 'a document missing info.version must fail validation');

        $danglingRef = $doc;
        $danglingRef['paths']['/api/widgets']['post']['requestBody']['content']['application/json']['schema']
            = ['$ref' => '#/components/schemas/DoesNotExist'];
        $this->assertNotSame([], self::danglingRefs($danglingRef), 'a dangling $ref must be detected');
    }

    // ── invariant 2: every-template-expression-has-a-parameter ───────────

    /**
     * Every {name} in a path key has a matching in:path required:true parameter
     * in every operation on that path, and no path key carries a framework type
     * token (PHP previously leaked '/api/typed/{id:int}').
     */
    public function testEveryPathTemplateHasAParameter(): void
    {
        $paths = self::$defaultsDoc['paths'];
        $this->assertNotEmpty($paths, 'the document must document at least one path');

        $unresolved = [];
        foreach ($paths as $key => $operations) {
            $this->assertStringNotContainsString(':', (string) $key, "a path key still carries a type token: {$key}");

            preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', (string) $key, $templateMatches);
            foreach ($operations as $verb => $operation) {
                if (!is_array($operation) || !isset($operation['operationId'])) {
                    continue; // path-level keys such as "parameters" / "summary"
                }
                $declared = array_map(
                    static fn($p) => $p['name'] ?? '',
                    array_filter(
                        $operation['parameters'] ?? [],
                        static fn($p) => ($p['in'] ?? '') === 'path' && ($p['required'] ?? false) === true
                    )
                );
                foreach ($templateMatches[1] as $wanted) {
                    if (!in_array($wanted, $declared, true)) {
                        $unresolved[] = strtoupper((string) $verb) . " {$key} -> {{$wanted}}";
                    }
                }
            }
        }

        $this->assertSame([], $unresolved, "template expressions with no in:path required parameter:\n  " . implode("\n  ", $unresolved));
    }

    // ── invariant 3: security-mirrors-the-runtime-gate ───────────────────

    /**
     * An operation's security is derived from the same flag dispatch enforces.
     * POSITIVE: a secure-by-default write the server answers 401 for is
     * documented as requiring auth. NEGATIVE: an explicitly-public write the
     * server does NOT gate is documented with no security.
     */
    public function testSecurityMirrorsTheRuntimeGate(): void
    {
        $port = self::$defaultsServer->port;

        // POSITIVE — documented secured, and the runtime really 401s it.
        $securedOp = self::$defaultsDoc['paths']['/api/widgets']['post'];
        $expectedSecurity = isset(self::$defaultsDoc['components']['securitySchemes']['ssoSession'])
            ? [['bearerAuth' => []], ['ssoSession' => []]]
            : [['bearerAuth' => []]];
        $this->assertSame($expectedSecurity, $securedOp['security'] ?? null, 'the secure-by-default write must document every configured runtime gate');
        $this->assertArrayHasKey('401', $securedOp['responses'], 'a secured operation documents a 401 response');
        $this->assertSame(
            401,
            self::httpStatus($port, '/api/widgets', 'POST'),
            'the server must 401 an unauthenticated POST to the secured write (the runtime gate)'
        );

        // NEGATIVE — documented public, and the runtime does NOT gate it.
        $publicOp = self::$defaultsDoc['paths']['/api/public-signup']['post'];
        $this->assertArrayNotHasKey('security', $publicOp, 'an explicitly-public write must document no security');
        $publicStatus = self::httpStatus($port, '/api/public-signup', 'POST');
        $this->assertNotSame(401, $publicStatus, 'the server must NOT gate the ->noAuth() write');
        $this->assertSame(200, $publicStatus, 'the public write is reachable without a token');
    }

    // ── invariant 4: path-param-types-map-identically ────────────────────

    /**
     * The same typed route token maps to the same JSON Schema fragment:
     * int/integer -> integer, float/number -> number, uuid -> string+format
     * uuid, slug/alpha/alnum -> string+pattern, bare -> string, and a token the
     * router accepts but the map does not (".*") degrades to string rather than
     * dropping the parameter.
     */
    public function testTypedPathTokenMapsIdentically(): void
    {
        $paths = self::$defaultsDoc['paths'];

        $schemaFor = function (string $key) use ($paths): array {
            $params = $paths[$key]['get']['parameters'] ?? [];
            $path = array_values(array_filter($params, static fn($p) => ($p['in'] ?? '') === 'path'));
            $this->assertCount(1, $path, "exactly one in:path parameter expected on {$key}");
            $this->assertTrue($path[0]['required'] ?? false, "the path parameter on {$key} must be required");
            return $path[0]['schema'] ?? [];
        };

        // integer / number map to an exact fragment (no format, no pattern).
        $this->assertSame(['type' => 'integer'], $schemaFor('/api/kind/int/{value}'));
        $this->assertSame(['type' => 'integer'], $schemaFor('/api/kind/integer/{value}'));
        $this->assertSame(['type' => 'number'], $schemaFor('/api/kind/float/{value}'));
        $this->assertSame(['type' => 'number'], $schemaFor('/api/kind/number/{value}'));

        // uuid -> string + format uuid.
        $uuid = $schemaFor('/api/kind/uuid/{value}');
        $this->assertSame('string', $uuid['type'] ?? null);
        $this->assertSame('uuid', $uuid['format'] ?? null);

        // slug / alpha / alnum -> string + a (non-empty) pattern.
        foreach (['slug', 'alpha', 'alnum'] as $token) {
            $schema = $schemaFor("/api/kind/{$token}/{value}");
            $this->assertSame('string', $schema['type'] ?? null, "{$token} is a string");
            $this->assertArrayHasKey('pattern', $schema, "{$token} carries a validation pattern");
            $this->assertNotSame('', $schema['pattern'], "{$token} pattern is non-empty");
        }

        // bare -> string; unknown/catch-all token -> string (degrade, not drop).
        $this->assertSame(['type' => 'string'], $schemaFor('/api/kind/bare/{value}'));
        $this->assertSame(['type' => 'string'], $schemaFor('/api/kind/catchall/{value}'), 'an unmapped token degrades to string, parameter retained');
    }

    // ── invariant 5: model-schemas-are-referenced-not-inlined ────────────

    /**
     * An ORM model becomes ONE components.schemas entry keyed by the model CLASS
     * name (not the table name), with `required` derived from nullability, and
     * the write route references it by $ref rather than inlining it.
     */
    public function testModelSchemaKeyedByClassName(): void
    {
        $schemas = self::$defaultsDoc['components']['schemas'] ?? [];

        $this->assertArrayHasKey('ContractWidget', $schemas, 'the schema is keyed by the model CLASS name');
        $this->assertArrayNotHasKey('contract_widgets', $schemas, 'the schema is NOT keyed by the table name');

        $schema = $schemas['ContractWidget'];
        // required is derived from column nullability: name is NOT NULL; id is an
        // auto-increment PK (readOnly, never required); description is nullable.
        $this->assertSame(['name'], $schema['required'] ?? null, 'required lists only the NOT NULL, non-auto column');
        $this->assertNotContains('id', $schema['required'], 'an auto-increment PK is never required');
        $this->assertNotContains('description', $schema['required'], 'a nullable column is never required');
        $this->assertTrue($schema['properties']['id']['readOnly'] ?? false, 'the auto-increment PK is readOnly');

        // Referenced by $ref, not inlined.
        $bodySchema = self::$defaultsDoc['paths']['/api/widgets']['post']['requestBody']['content']['application/json']['schema'] ?? [];
        $this->assertSame(['$ref' => '#/components/schemas/ContractWidget'], $bodySchema, 'the write route references the model by $ref');
    }

    // ── invariant 6: info-and-servers-defaults-are-one-value ─────────────

    /**
     * With no TINA4_SWAGGER_* set, info.version is 1.0.0, info.description is ""
     * and servers[0].url is "/" (correct under any port/proxy). PHP previously
     * hard-coded http://localhost:7145.
     */
    public function testInfoAndServersDefaultsIdentical(): void
    {
        $info = self::$defaultsDoc['info'];
        $servers = self::$defaultsDoc['servers'];

        $this->assertSame('1.0.0', $info['version'], 'info.version defaults to 1.0.0');
        $this->assertSame('', $info['description'], 'info.description defaults to an empty string');
        $this->assertSame('/', $servers[0]['url'] ?? null, 'servers[0].url defaults to "/"');

        // NEGATIVE: the old hard-coded host:port must be gone.
        $this->assertStringNotContainsString('7145', $servers[0]['url'], 'the hard-coded dev port must not be advertised');
        $this->assertStringNotContainsString('localhost', $servers[0]['url'], 'a fixed host must not be advertised');
    }

    // ── invariant 7: env-surface-is-identical ────────────────────────────

    /**
     * The documented TINA4_SWAGGER_* variables are read — including
     * TINA4_SWAGGER_CONTACT_TEAM and _CONTACT_URL, which PHP alone used to drop
     * (its info.contact carried only the email). They map to
     * info.contact.name / info.contact.url.
     */
    public function testSwaggerEnvSurfaceIdentical(): void
    {
        $contact = self::$configuredDoc['info']['contact'] ?? [];

        $this->assertSame(self::CONTACT_TEAM, $contact['name'] ?? null, 'TINA4_SWAGGER_CONTACT_TEAM -> info.contact.name');
        $this->assertSame(self::CONTACT_URL, $contact['url'] ?? null, 'TINA4_SWAGGER_CONTACT_URL -> info.contact.url');
        $this->assertSame(self::CONTACT_EMAIL, $contact['email'] ?? null, 'TINA4_SWAGGER_CONTACT_EMAIL -> info.contact.email');

        // NEGATIVE: the defaults server set none of these, so contact must not
        // appear at all — the team/url are not fabricated.
        $defaultContact = self::$defaultsDoc['info']['contact'] ?? null;
        $this->assertNull($defaultContact, 'with no contact env set, info.contact is absent');
    }

    // ── invariant 8: disabled-means-nothing-is-served ────────────────────

    /**
     * With TINA4_SWAGGER_ENABLED=false the UI path, the trailing-slash form, the
     * spec endpoint AND the bundled UI assets all 404. A positive control on the
     * enabled server proves those same paths CAN serve, so the 404s are real.
     */
    public function testDisabledServesNothing(): void
    {
        $gated = [
            '/swagger',
            '/swagger/',
            '/swagger/openapi.json',
            '/swagger/index.html',
            '/swagger/oauth2-redirect.html',
        ];

        // POSITIVE control (enabled): the spec endpoint and a bundled UI asset
        // serve — otherwise the negative below would pass vacuously.
        $this->assertSame(200, self::httpStatus(self::$defaultsServer->port, '/swagger/openapi.json'), 'enabled: the spec endpoint serves');
        $this->assertSame(200, self::httpStatus(self::$defaultsServer->port, '/swagger/index.html'), 'enabled: the bundled UI asset serves');

        // NEGATIVE (disabled): every swagger path 404s.
        foreach ($gated as $path) {
            $this->assertSame(
                404,
                self::httpStatus(self::$disabledServer->port, $path),
                "disabled: {$path} must 404"
            );
        }

        // Control: an ordinary application route still serves when disabled — the
        // gate is surgical, it does not take the whole app down.
        $this->assertSame(200, self::httpStatus(self::$disabledServer->port, '/health'), 'disabled: ordinary routes still serve');
    }

    // ── invariant 9: only-the-application-is-documented ──────────────────

    /**
     * Framework-internal routes are excluded from the published document by one
     * shared rule. PHP previously published /, /ai, /embed, /image, /rag,
     * /vision and /__feedback/*.
     */
    public function testOnlyTheApplicationIsDocumented(): void
    {
        $paths = self::$defaultsDoc['paths'];

        // The application IS documented.
        $this->assertArrayHasKey('/health', $paths, 'an application route must be documented');
        $this->assertArrayHasKey('/api/widgets', $paths, 'the application write route must be documented');

        // Framework internals are NOT — including the swagger routes themselves.
        $internal = [
            '/', '/ai', '/ai/api/chat', '/rag', '/vision', '/embed',
            '/image', '/image/v1/images/generations',
            '/__feedback/api/turn', '/__feedback/widget.js',
            '/swagger', '/swagger/openapi.json',
        ];
        foreach ($internal as $path) {
            $this->assertArrayNotHasKey($path, $paths, "framework-internal {$path} must not be in the public document");
        }
    }

    // ── invariant 10: operation-ids-are-stable-and-distinct ──────────────

    /**
     * Two distinct paths never collapse to the same operationId. /__health and
     * /health stay distinct (get___health vs get_health) rather than colliding
     * and being suffixed.
     */
    public function testOperationIdsStableAndDistinct(): void
    {
        $paths = self::$defaultsDoc['paths'];

        $operationIds = [];
        foreach ($paths as $operations) {
            foreach ($operations as $operation) {
                if (is_array($operation) && isset($operation['operationId'])) {
                    $operationIds[] = $operation['operationId'];
                }
            }
        }

        $this->assertSame(
            count($operationIds),
            count(array_unique($operationIds)),
            'operationIds must be unique across the document; duplicates: '
            . implode(', ', array_keys(array_filter(array_count_values($operationIds), static fn($n) => $n > 1)))
        );

        // The two health probes keep their distinct leading-underscore shapes.
        $this->assertSame('get_health', $paths['/health']['get']['operationId']);
        $this->assertSame('get___health', $paths['/__health']['get']['operationId']);
        $this->assertNotSame(
            $paths['/health']['get']['operationId'],
            $paths['/__health']['get']['operationId'],
            '/__health and /health must not collapse to one operationId'
        );
    }

    // ── invariant 11: spec-reflects-a-route-added-after-boot ─────────────

    /**
     * The document reflects a route registered AFTER an earlier fetch to the
     * SAME running server. `php -S` re-executes the whole front-controller
     * script on EVERY request, so a marker file the fixture app checks each
     * time simulates a route a hot-reload adds mid-run (the real mechanism
     * Node uses). Node's generator closed over a BOOT-TIME
     * `router.getRoutes()` snapshot (SWAG-NODE-BOOT-SNAPSHOT); PHP's
     * Swagger::generate() always re-reads Router::getRoutes() fresh, and this
     * pins that so all four frameworks carry the same named regression.
     */
    public function testSpecReflectsARouteAddedAfterBoot(): void
    {
        $app = dirname(__DIR__) . '/tests/fixtures/swagger_contract_app.php';
        $marker = tempnam(sys_get_temp_dir(), 'tina4_late_route_marker_');
        $this->assertNotFalse($marker, 'could not reserve a marker file path');
        unlink($marker); // start ABSENT — the late route must not exist yet

        $env = self::baseEnv();
        $env['TINA4_DEBUG'] = 'true';
        $env['TINA4_TEST_LATE_ROUTE_MARKER'] = $marker;

        $server = TestServer::start($app, $env, self::$workDir);
        try {
            $before = self::fetchJson($server->port, '/swagger/openapi.json');
            $this->assertArrayNotHasKey(
                '/contract/late-added',
                $before['paths'],
                'premise broken: the late route must not exist before the marker is created'
            );
            $this->assertArrayHasKey('/health', $before['paths'], 'an ordinary route is documented before the late add');

            // Simulate a hot-reload registering a NEW route mid-run.
            file_put_contents($marker, '1');

            $after = self::fetchJson($server->port, '/swagger/openapi.json');
            $this->assertArrayHasKey(
                '/contract/late-added',
                $after['paths'],
                'the document must reflect a route registered after an earlier fetch, not a frozen boot snapshot'
            );
            $this->assertArrayHasKey('/health', $after['paths'], 'the earlier route must still be documented too');
        } finally {
            $server->stop();
            @unlink($marker);
        }
    }

    // ── invariant 12: internal-feedback-route-is-never-in-the-spec ───────

    /**
     * /__feedback/* is excluded by the SAME shared internal-prefix rule as
     * /swagger and /__dev, regardless of how or when it was registered.
     * Node's /__feedback/* was kept out of the spec only by BOOT ORDERING
     * (swagger snapshotted routes before DevAdmin registered the feedback
     * routes), not by its exclusion list — a reorder would have published
     * `POST /__feedback/api/turn` as a secured route (SWAG-NODE-FEEDBACK-LEAK).
     * PHP's INTERNAL_PREFIXES already carries /__feedback (testOnlyThe...
     * exercises it too); this is its own named case so the shared auditor can
     * match it by name across all four frameworks.
     */
    public function testInternalFeedbackRouteIsNeverInTheSpec(): void
    {
        $paths = self::$defaultsDoc['paths'];

        $this->assertArrayHasKey('/health', $paths, 'the application route must still be documented');
        $this->assertArrayNotHasKey('/__feedback/api/turn', $paths, '/__feedback must be excluded');
        $this->assertArrayNotHasKey('/__feedback/widget.js', $paths, '/__feedback must be excluded');
        foreach (array_keys($paths) as $pathKey) {
            $this->assertFalse(
                str_starts_with((string) $pathKey, '/__feedback'),
                "framework-internal {$pathKey} must not be in the public document"
            );
        }
    }

    // ── invariant 13: secured-op-per-op-shape-is-identical ───────────────

    /**
     * A secured operation's security + 401 + summary/tags shape is
     * byte-identical across all four frameworks (SWAG-401-SHAPE +
     * SWAG-SHAPE-DRIFT). PHP already emitted all four on an undecorated
     * route; Python used to emit none of them and Ruby always added a
     * `description: ""`. This pins the CONVERGED shape so a future drift is
     * caught, and the negative control proves summary/tags populate
     * regardless of security while no description is fabricated either way.
     */
    public function testSecuredOpPerOpShapeIsIdentical(): void
    {
        $paths = self::$defaultsDoc['paths'];

        $secured = $paths['/contract/shape-item']['post'];
        $expectedSecurity = isset(self::$defaultsDoc['components']['securitySchemes']['ssoSession'])
            ? [['bearerAuth' => []], ['ssoSession' => []]]
            : [['bearerAuth' => []]];
        $this->assertSame($expectedSecurity, $secured['security'] ?? null, 'a secured op documents every configured runtime gate');
        $this->assertSame(['description' => 'Unauthorized'], $secured['responses']['401'] ?? null, 'a secured op documents a 401');
        $this->assertSame('POST /contract/shape-item', $secured['summary'] ?? null, 'summary defaults to METHOD + path');
        $this->assertSame(['contract'], $secured['tags'] ?? null, 'tags default to the first path segment');
        $this->assertArrayNotHasKey('description', $secured, 'an undecorated operation must not fabricate a description');

        $public = $paths['/contract/shape-public']['post'];
        $this->assertArrayNotHasKey('security', $public, 'an explicitly-public op documents no security');
        $this->assertArrayNotHasKey('401', $public['responses'], 'an explicitly-public op documents no 401');
        $this->assertSame('POST /contract/shape-public', $public['summary'] ?? null, 'summary populates regardless of security');
        $this->assertSame(['contract'], $public['tags'] ?? null, 'tags populate regardless of security');
        $this->assertArrayNotHasKey('description', $public);
    }

    // ── helpers (not tests) ──────────────────────────────────────────────

    /**
     * A child environment with every TINA4_SWAGGER_* / SWAGGER_* key removed, so
     * an ambient value in CI cannot pollute a "defaults" or "disabled" server.
     *
     * @return array<string, string>
     */
    private static function baseEnv(): array
    {
        $env = getenv();
        foreach (array_keys($env) as $key) {
            if (str_starts_with($key, 'TINA4_SWAGGER_') || str_starts_with($key, 'SWAGGER_')) {
                unset($env[$key]);
            }
        }
        return $env;
    }

    /**
     * GET a path and decode its JSON body. Throws (failing setUpBeforeClass loud)
     * when the server does not answer or the body is not JSON.
     *
     * @return array<string, mixed>
     */
    private static function fetchJson(int $port, string $path): array
    {
        $context = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 15]]);
        $body = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
        if ($body === false) {
            throw new \RuntimeException("no response from {$path} on port {$port}");
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("non-JSON from {$path}: " . substr($body, 0, 200));
        }
        return $decoded;
    }

    /**
     * The HTTP status code of a real request. follow_location is off so the
     * status is the one the requested URL itself returned.
     *
     * @param array<int, string> $headers
     */
    private static function httpStatus(int $port, string $path, string $method = 'GET', array $headers = [], ?string $requestBody = null): int
    {
        $options = ['method' => $method, 'ignore_errors' => true, 'timeout' => 15, 'follow_location' => 0];
        if ($headers !== []) {
            $options['header'] = implode("\r\n", $headers);
        }
        if ($requestBody !== null) {
            $options['content'] = $requestBody;
        }
        $context = stream_context_create(['http' => $options]);
        $responseHeaderLines = [];
        $result = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
        // $http_response_header is populated in the calling scope by the wrapper.
        $responseHeaderLines = $http_response_header ?? [];
        if ($result === false && $responseHeaderLines === []) {
            throw new \RuntimeException("no response for {$method} {$path} on port {$port}");
        }
        $status = 0;
        foreach ($responseHeaderLines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }
        return $status;
    }

    /**
     * Validate a document with the real OpenAPI library (cebe/php-openapi). The
     * library emits PHP 8.5 "implicitly nullable" deprecations from its own
     * signatures when its spec classes load; swallow ONLY those (matched by the
     * cebe vendor path) so the suite stays deprecation-clean, and let every
     * other error reach PHPUnit's handler untouched.
     *
     * @param array<string, mixed> $document
     * @return array{0: bool, 1: array<int, string>}
     */
    private static function validateOpenApi(array $document): array
    {
        $json = json_encode($document, JSON_UNESCAPED_SLASHES);
        set_error_handler(
            static fn(int $errno, string $msg, string $file = ''): bool =>
                $errno === E_DEPRECATED && str_contains($file, 'cebe/php-openapi'),
            E_DEPRECATED
        );
        try {
            $openapi = \cebe\openapi\Reader::readFromJson((string) $json);
            $valid = $openapi->validate();
            $errors = $openapi->getErrors();
        } finally {
            restore_error_handler();
        }
        return [$valid, $errors];
    }

    /**
     * Every internal $ref in the document that does not resolve to an existing
     * node. cebe's structural validate() does not resolve refs, so this catches
     * a mis-keyed model schema (e.g. keyed by table name but referenced by class
     * name) that would otherwise slip through.
     *
     * @param array<string, mixed> $document
     * @return array<int, string> the dangling refs (empty when all resolve)
     */
    private static function danglingRefs(array $document): array
    {
        $refs = [];
        $collect = static function ($node) use (&$collect, &$refs): void {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if ($key === '$ref' && is_string($value)) {
                    $refs[] = $value;
                } else {
                    $collect($value);
                }
            }
        };
        $collect($document);

        $dangling = [];
        foreach ($refs as $ref) {
            if (!str_starts_with($ref, '#/')) {
                $dangling[] = $ref; // only internal refs are expected
                continue;
            }
            $cursor = $document;
            $resolved = true;
            foreach (explode('/', substr($ref, 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                    $resolved = false;
                    break;
                }
                $cursor = $cursor[$segment];
            }
            if (!$resolved) {
                $dangling[] = $ref;
            }
        }
        return $dangling;
    }
}
