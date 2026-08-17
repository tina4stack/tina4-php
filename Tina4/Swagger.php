<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Zero-dependency Swagger/OpenAPI 3.0.3 spec generator and UI server.
 *
 * Reads registered routes from Router::getRoutes() and produces a compliant
 * OpenAPI specification. Serves Swagger UI from CDN at /swagger and the
 * raw spec at /swagger/openapi.json.
 *
 * Env vars (via DotEnv):
 *   TINA4_SWAGGER_TITLE        — API title (default: "Tina4 API")
 *   TINA4_SWAGGER_VERSION      — API version (default: "1.0.0")
 *   TINA4_SWAGGER_DESCRIPTION  — API description (default: "Auto-generated from Tina4 routes")
 *   TINA4_SWAGGER_OPENAPI      — OpenAPI version: 3.0.3 (default) or 3.1 (-> emits 3.1.0)
 *   TINA4_SWAGGER_BEARER_FORMAT — bearerFormat on the built-in bearerAuth scheme (default "JWT")
 *   TINA4_SWAGGER_API_KEY_NAME  — if set, emit an apiKeyAuth scheme with this header/query name
 *   TINA4_SWAGGER_API_KEY_IN    — where the apiKey lives: header (default) | query | cookie
 *   TINA4_SWAGGER_DEFAULT_SCHEME — scheme secured routes use when no explicit security (default "bearerAuth")
 *   TINA4_SWAGGER_INCLUDE      — comma-separated raw-path prefixes to include (allow-list)
 *   TINA4_SWAGGER_EXCLUDE      — comma-separated raw-path prefixes to drop (the shared framework internals — /swagger, /__dev, /__feedback and the AI/RAG service prefixes, plus bare "/" — are always excluded)
 *
 * v3.13.42 — configurability for external/public APIs:
 *   - per-route security + scopes via swagger(['security' => ..., 'scopes' => ...]);
 *     scopes are kept valid (only oauth2/openIdConnect carry them);
 *   - configurable security schemes (bearer format, apiKey scheme, default scheme,
 *     plus Swagger::addSecurityScheme() / Swagger::resetRegistry());
 *   - path filtering via TINA4_SWAGGER_INCLUDE / _EXCLUDE;
 *   - OpenAPI 3.1 opt-in via TINA4_SWAGGER_OPENAPI;
 *   - reusable custom schemas via Swagger::addSchema() referenced by
 *     swagger(['requestSchema' => 'Name']) / swagger(['responseSchemas' => [...]]).
 */
class Swagger
{
    /**
     * Process-wide registry of programmatically declared security schemes
     * (Swagger::addSecurityScheme). Merged into components.securitySchemes,
     * and may override the built-in bearerAuth (e.g. register an oauth2 scheme
     * with scopes). Cleared by resetRegistry().
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $registeredSchemes = [];

    /**
     * Process-wide registry of reusable component schemas
     * (Swagger::addSchema). Referenced by routes via swagger['requestSchema']
     * / swagger['responseSchemas'] and emitted into components.schemas.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $registeredSchemas = [];

    /**
     * Register a named OpenAPI security scheme (e.g. an oauth2 scheme with
     * scopes, or a custom apiKey). Call at app bootstrap before generate().
     * Registered schemes win over the built-in bearerAuth.
     *
     * @param string               $name       Scheme name (key in securitySchemes)
     * @param array<string, mixed> $definition OpenAPI security-scheme object
     */
    public static function addSecurityScheme(string $name, array $definition): void
    {
        self::$registeredSchemes[$name] = $definition;
    }

    /**
     * Register a reusable component schema, referenceable via a route's
     * swagger['requestSchema'] / swagger['responseSchemas'] meta or a raw $ref.
     *
     * @param string               $name   Schema name (key in components.schemas)
     * @param array<string, mixed> $schema JSON-Schema object
     */
    public static function addSchema(string $name, array $schema): void
    {
        self::$registeredSchemas[$name] = $schema;
    }

    /**
     * Clear the security-scheme and schema registries (test helper / parity
     * with Python Swagger.reset_registry()).
     */
    public static function resetRegistry(): void
    {
        self::$registeredSchemes = [];
        self::$registeredSchemas = [];
    }

    /**
     * Resolve the OpenAPI version string. Default 3.0.3 (broad tool support);
     * TINA4_SWAGGER_OPENAPI=3.1 / 3.1.0 -> "3.1.0". An explicit full version is
     * honoured verbatim. The schemas emitted are valid in both dialects.
     */
    private static function resolveOpenApiVersion(): string
    {
        $v = trim((string) (DotEnv::getEnv('TINA4_SWAGGER_OPENAPI', '') ?? ''));
        if ($v === '') {
            return '3.0.3';
        }
        if ($v === '3.1' || $v === '3.1.0') {
            return '3.1.0';
        }
        if ($v === '3.0' || $v === '3.0.3') {
            return '3.0.3';
        }
        return $v;
    }

    /**
     * Resolve components.securitySchemes from defaults + env + registry.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function securitySchemes(): array
    {
        $bearerFormat = (string) (DotEnv::getEnv('TINA4_SWAGGER_BEARER_FORMAT', 'JWT') ?? 'JWT');
        $schemes = [
            'bearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => $bearerFormat,
            ],
        ];

        // Optional apiKey scheme — emitted as "apiKeyAuth" when a header/query
        // name is configured (e.g. X-Api-Key).
        $apiKeyName = (string) (DotEnv::getEnv('TINA4_SWAGGER_API_KEY_NAME', '') ?? '');
        if ($apiKeyName !== '') {
            $apiKeyIn = (string) (DotEnv::getEnv('TINA4_SWAGGER_API_KEY_IN', 'header') ?? 'header');
            if (!in_array($apiKeyIn, ['header', 'query', 'cookie'], true)) {
                $apiKeyIn = 'header';
            }
            $schemes['apiKeyAuth'] = [
                'type' => 'apiKey',
                'name' => $apiKeyName,
                'in' => $apiKeyIn,
            ];
        }

        $ssoIssuer = rtrim((string)(DotEnv::getEnv('TINA4_SSO_ISSUER', '') ?? ''), '/');
        if ($ssoIssuer !== '') {
            $schemes['oidc'] = [
                'type' => 'openIdConnect',
                'openIdConnectUrl' => $ssoIssuer . '/.well-known/openid-configuration',
            ];
            $schemes['ssoSession'] = [
                'type' => 'apiKey', 'in' => 'cookie', 'name' => 'tina4_session',
            ];
        }

        // Registered schemes win (let an app override bearerAuth or add oauth2).
        foreach (self::$registeredSchemes as $name => $def) {
            $schemes[$name] = $def;
        }
        return $schemes;
    }

    /**
     * Normalize a route's swagger['security'] meta to an OpenAPI
     * security-requirement list.
     *
     * Accepts:
     *   'bearerAuth'                         -> [['bearerAuth' => []]]
     *   ['scheme' => 'oauth2', 'scopes' => [...]] -> [['oauth2' => [...]]]
     *   ['oauth2' => ['read']]               -> [['oauth2' => ['read']]]   (single requirement dict)
     *   [['oauth2' => ['read']], ['bearerAuth' => []]] -> verbatim (OR list)
     *   'public' / 'none' / []               -> [] (explicitly no auth)
     *
     * @param mixed $security
     * @return array<int, array<string, array<int, string>>>
     */
    private static function normalizeSecurity($security): array
    {
        if ($security === 'public' || $security === 'none' || $security === null) {
            return [];
        }
        if (is_string($security)) {
            return [[$security => []]];
        }
        if (is_array($security)) {
            if ($security === []) {
                return [];
            }
            // A list of requirement dicts (OR) — list-shaped array.
            if (array_is_list($security)) {
                $out = [];
                foreach ($security as $req) {
                    if (is_array($req)) {
                        $clean = [];
                        foreach ($req as $name => $scopes) {
                            $clean[$name] = is_array($scopes) ? array_values($scopes) : [];
                        }
                        $out[] = $clean;
                    }
                }
                return $out;
            }
            // The {scheme, scopes} convenience form.
            if (isset($security['scheme'])) {
                $scopes = isset($security['scopes']) && is_array($security['scopes'])
                    ? array_values($security['scopes'])
                    : [];
                return [[(string) $security['scheme'] => $scopes]];
            }
            // A single {name: [scopes]} requirement dict (AND within one dict).
            $clean = [];
            foreach ($security as $name => $scopes) {
                $clean[$name] = is_array($scopes) ? array_values($scopes) : [];
            }
            return [$clean];
        }
        return [];
    }

    /**
     * Keep a security-requirement list spec-valid: scopes are allowed only on
     * oauth2/openIdConnect schemes; everything else gets an empty array
     * (OpenAPI requires that for http/apiKey).
     *
     * @param array<int, array<string, array<int, string>>> $reqs
     * @param array<string, array<string, mixed>>           $schemes
     * @return array<int, array<string, array<int, string>>>
     */
    private static function sanitizeSecurity(array $reqs, array $schemes): array
    {
        $scopeOk = ['oauth2', 'openIdConnect'];
        $out = [];
        foreach ($reqs as $req) {
            $clean = [];
            foreach ($req as $name => $scopes) {
                $type = $schemes[$name]['type'] ?? null;
                $clean[$name] = in_array($type, $scopeOk, true) ? array_values($scopes) : [];
            }
            $out[] = $clean;
        }
        return $out;
    }

    /**
     * Framework-internal route prefixes that are NEVER part of an application's
     * public API document. Shared across all four frameworks so the rule is one
     * list rather than a per-framework accident: the dev tools (/swagger,
     * /__dev), the feedback widget (/__feedback), and the built-in AI/RAG
     * service probes (/ai, /rag, /vision, /embed, /image) the dev dashboard
     * registers. The bare landing page "/" is excluded separately (exact match).
     *
     * @var array<int, string>
     */
    private const INTERNAL_PREFIXES = [
        '/swagger',
        '/__dev',
        '/__feedback',
        '/ai',
        '/rag',
        '/vision',
        '/embed',
        '/image',
    ];

    /**
     * Path-filter a raw route pattern. Framework internals (INTERNAL_PREFIXES
     * plus the bare "/") are ALWAYS excluded; then TINA4_SWAGGER_INCLUDE
     * (allow-list) and TINA4_SWAGGER_EXCLUDE (deny-list) prefixes apply.
     *
     * @param string        $rawPath
     * @param array<string> $include
     * @param array<string> $exclude
     */
    private static function isIncluded(string $rawPath, array $include, array $exclude): bool
    {
        // The framework's own landing page — exact match only, so a prefix test
        // does not swallow every route.
        if ($rawPath === '/') {
            return false;
        }
        foreach (self::INTERNAL_PREFIXES as $internal) {
            if ($rawPath === $internal || str_starts_with($rawPath, $internal . '/')) {
                return false;
            }
        }
        if (!empty($include)) {
            $matched = false;
            foreach ($include as $p) {
                if ($rawPath === $p || str_starts_with($rawPath, $p)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }
        foreach ($exclude as $p) {
            if ($rawPath === $p || str_starts_with($rawPath, $p)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Split a comma-separated env value into a clean list of prefixes.
     *
     * @return array<int, string>
     */
    private static function csvEnv(string $name): array
    {
        $raw = (string) (DotEnv::getEnv($name, '') ?? '');
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($p) => $p !== ''));
    }

    /**
     * Generate an OpenAPI spec from registered routes.
     *
     * @param array  $routes  Optional route definitions (reads from Router if empty)
     * @return array<string, mixed> OpenAPI spec as a nested associative array
     */
    public static function generate(array $routes = []): array
    {
        $title = DotEnv::getEnv('TINA4_SWAGGER_TITLE', 'Tina4 API') ?? 'Tina4 API';
        $version = DotEnv::getEnv('TINA4_SWAGGER_VERSION', '1.0.0') ?? '1.0.0';
        $description = DotEnv::getEnv('TINA4_SWAGGER_DESCRIPTION', '') ?? '';

        $info = [
            'title' => $title,
            'version' => $version,
            'description' => $description,
        ];

        // Optional contact + license blocks — only present when env is set.
        // info.contact carries name / url / email, each emitted only when
        // configured. TINA4_SWAGGER_CONTACT_TEAM/_URL are read with the legacy
        // SWAGGER_CONTACT_TEAM/_URL as a fallback — parity with the Python and
        // Ruby masters (PHP alone used to emit only the email).
        $contact = [];
        $contactName = self::firstNonEmpty(
            DotEnv::getEnv('TINA4_SWAGGER_CONTACT_TEAM'),
            DotEnv::getEnv('SWAGGER_CONTACT_TEAM')
        );
        if ($contactName !== '') {
            $contact['name'] = $contactName;
        }
        $contactUrl = self::firstNonEmpty(
            DotEnv::getEnv('TINA4_SWAGGER_CONTACT_URL'),
            DotEnv::getEnv('SWAGGER_CONTACT_URL')
        );
        if ($contactUrl !== '') {
            $contact['url'] = $contactUrl;
        }
        $contactEmail = self::firstNonEmpty(DotEnv::getEnv('TINA4_SWAGGER_CONTACT_EMAIL'));
        if ($contactEmail !== '') {
            $contact['email'] = $contactEmail;
        }
        if (!empty($contact)) {
            $info['contact'] = $contact;
        }
        $license = DotEnv::getEnv('TINA4_SWAGGER_LICENSE');
        if ($license !== null && $license !== '') {
            $info['license'] = ['name' => $license];
        }

        // Resolved security schemes (defaults + env + registry) and the default
        // scheme secured routes use when no explicit @security is declared.
        $schemes = self::securitySchemes();
        $defaultScheme = (string) (DotEnv::getEnv('TINA4_SWAGGER_DEFAULT_SCHEME', 'bearerAuth') ?? 'bearerAuth');

        // Path-filter prefixes.
        $includePrefixes = self::csvEnv('TINA4_SWAGGER_INCLUDE');
        $excludePrefixes = self::csvEnv('TINA4_SWAGGER_EXCLUDE');

        // Registered component schemas referenced by routes (-> components.schemas).
        $refSchemas = [];

        $spec = [
            'openapi' => self::resolveOpenApiVersion(),
            'info' => $info,
            'servers' => self::servers(),
            'paths' => [],
            'components' => [
                'securitySchemes' => $schemes,
            ],
        ];

        $routes = Router::getRoutes();

        // Valid OpenAPI path-item methods. WebSocket routes carry method 'WS'
        // (and any future non-HTTP verb) which is NOT a valid path-item key —
        // emitting it makes the whole document spec-invalid, so skip them.
        $httpMethods = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

        // Group routes by path pattern
        $grouped = [];
        foreach ($routes as $route) {
            $pattern = $route['pattern'];
            $method = strtolower($route['method']);

            // Path filtering — framework internals (/swagger, /__dev) are always
            // excluded; then TINA4_SWAGGER_INCLUDE / _EXCLUDE prefixes apply.
            if (!self::isIncluded($pattern, $includePrefixes, $excludePrefixes)) {
                continue;
            }

            // Skip non-HTTP methods (e.g. WebSocket 'ws')
            if (!in_array($method, $httpMethods, true)) {
                continue;
            }

            if (!isset($grouped[$pattern])) {
                $grouped[$pattern] = [];
            }
            $grouped[$pattern][$method] = $route;
        }

        // Accumulators: ORM models referenced (-> components.schemas), tags
        // actually used (-> top-level tags[]), and seen operationIds (de-dup).
        $models = [];
        $usedTags = [];
        $seenIds = [];

        foreach ($grouped as $pattern => $methods) {
            $openApiPath = self::convertPath($pattern);
            $pathParams = self::extractPathParameters($pattern);

            foreach ($methods as $method => $route) {
                $tag = self::inferTag($pattern);
                $baseId = $method . str_replace(['/', '{', '}', '-', '*'], ['_', '', '', '_', 'wildcard'], $pattern);
                // De-duplicate: OpenAPI requires operationId unique across the
                // document (str_replace collapses distinct paths to the same id).
                $operationId = $baseId;
                $dupN = 2;
                while (in_array($operationId, $seenIds, true)) {
                    $operationId = $baseId . '_' . $dupN;
                    $dupN++;
                }
                $seenIds[] = $operationId;

                // Parse docblock annotations from the route callback
                $docMeta = isset($route['callback']) ? self::parseDocBlock($route['callback']) : [
                    'description' => null,
                    'summary' => null,
                    'tags' => [],
                    'examples' => [],
                    'responses' => [],
                    'params' => [],
                    'deprecated' => false,
                    'noAuth' => false,
                    'secured' => false,
                ];

                // Apply @noauth / @secured annotations to route flags
                if ($docMeta['noAuth']) {
                    $route['noAuth'] = true;
                }
                if ($docMeta['secured']) {
                    $route['secure'] = true;
                }

                // Merge stored swagger metadata (from Router::swagger() or AutoCrud)
                $swaggerMeta = $route['swagger'] ?? [];
                if (!empty($swaggerMeta)) {
                    if (isset($swaggerMeta['summary']) && $docMeta['summary'] === null) {
                        $docMeta['summary'] = $swaggerMeta['summary'];
                    }
                    if (isset($swaggerMeta['description']) && $docMeta['description'] === null) {
                        $docMeta['description'] = $swaggerMeta['description'];
                    }
                    if (isset($swaggerMeta['tags']) && empty($docMeta['tags'])) {
                        $docMeta['tags'] = $swaggerMeta['tags'];
                    }
                    if (isset($swaggerMeta['example']) && empty($docMeta['examples'])) {
                        $docMeta['examples'][] = $swaggerMeta['example'];
                    }
                    if (isset($swaggerMeta['deprecated']) && !$docMeta['deprecated']) {
                        $docMeta['deprecated'] = $swaggerMeta['deprecated'];
                    }
                }

                $operation = [
                    'tags' => !empty($docMeta['tags']) ? $docMeta['tags'] : [$tag],
                    'operationId' => $operationId,
                    'summary' => $docMeta['summary'] ?? strtoupper($method) . ' ' . $pattern,
                    'responses' => [
                        '200' => [
                            'description' => 'Successful response',
                        ],
                    ],
                ];

                // Add description from docblock
                if ($docMeta['description'] !== null) {
                    $operation['description'] = $docMeta['description'];
                }

                // Mark as deprecated
                if ($docMeta['deprecated']) {
                    $operation['deprecated'] = true;
                }

                // Collect tags for the top-level tags[] array.
                foreach ($operation['tags'] as $t) {
                    if (!in_array($t, $usedTags, true)) {
                        $usedTags[] = $t;
                    }
                }

                // ORM model -> components.schemas + $ref. AutoCrud tags routes
                // with swagger['model']; build the schema once and reference it.
                $ref = null;
                $modelClass = $swaggerMeta['model'] ?? null;
                if ($modelClass !== null && class_exists($modelClass)) {
                    $schemaName = (new \ReflectionClass($modelClass))->getShortName();
                    if (!isset($models[$schemaName])) {
                        $models[$schemaName] = $modelClass;
                    }
                    $ref = '#/components/schemas/' . $schemaName;
                }
                $isModelList = !empty($swaggerMeta['modelList']);

                // Add path parameters
                if (!empty($pathParams)) {
                    $operation['parameters'] = $pathParams;
                }

                // Build parameters and requestBody properties from @param annotations
                $bodyProperties = [];
                $bodyRequired = [];
                $queryParams = [];
                $multipart = false;   // flipped by a @param of type file/binary
                foreach ($docMeta['params'] as $paramDef) {
                    // If this param matches a path parameter, update its type/description
                    $isPathParam = false;
                    if (isset($operation['parameters'])) {
                        foreach ($operation['parameters'] as &$existingParam) {
                            if ($existingParam['name'] === $paramDef['name'] && $existingParam['in'] === 'path') {
                                $existingParam['schema']['type'] = self::mapParamType($paramDef['type']);
                                $existingParam['description'] = $paramDef['description'];
                                $isPathParam = true;
                                break;
                            }
                        }
                        unset($existingParam);
                    }

                    if (!$isPathParam) {
                        // For POST/PUT/PATCH, add to requestBody properties
                        if (in_array($method, ['post', 'put', 'patch'], true)) {
                            // A @param of type file/binary makes the body multipart
                            // and the property a binary string (file upload).
                            if (in_array($paramDef['type'], ['file', 'binary'], true)) {
                                $multipart = true;
                                $bodyProperties[$paramDef['name']] = [
                                    'type' => 'string',
                                    'format' => 'binary',
                                    'description' => $paramDef['description'],
                                ];
                            } else {
                                $bodyProperties[$paramDef['name']] = [
                                    'type' => self::mapParamType($paramDef['type']),
                                    'description' => $paramDef['description'],
                                ];
                            }
                            if ($paramDef['required']) {
                                $bodyRequired[] = $paramDef['name'];
                            }
                        } else {
                            // For GET/DELETE, add as query parameters
                            $queryParams[] = [
                                'name' => $paramDef['name'],
                                'in' => 'query',
                                'required' => $paramDef['required'],
                                'description' => $paramDef['description'],
                                'schema' => [
                                    'type' => self::mapParamType($paramDef['type']),
                                ],
                            ];
                        }
                    }
                }

                // Merge query parameters
                if (!empty($queryParams)) {
                    if (!isset($operation['parameters'])) {
                        $operation['parameters'] = [];
                    }
                    $operation['parameters'] = array_merge($operation['parameters'], $queryParams);
                }

                // A registered custom request schema referenced by name
                // (swagger['requestSchema'] => 'CreateUser'). Takes precedence
                // over the inferred / ORM-model body schema.
                $requestSchemaName = $swaggerMeta['requestSchema'] ?? null;

                // Add request body hint for methods that accept a body
                if (in_array($method, ['post', 'put', 'patch'], true)) {
                    // Prefer an explicit registered schema $ref; then an ORM
                    // model $ref; else build an object schema from @param
                    // properties (or a bare object).
                    if ($requestSchemaName !== null) {
                        $refSchemas[$requestSchemaName] = true;
                        $schema = ['$ref' => '#/components/schemas/' . $requestSchemaName];
                    } elseif ($ref !== null) {
                        $schema = ['$ref' => $ref];
                    } else {
                        $schema = ['type' => 'object'];
                        if (!empty($bodyProperties)) {
                            $schema['properties'] = $bodyProperties;
                            if (!empty($bodyRequired)) {
                                $schema['required'] = $bodyRequired;
                            }
                        }
                    }

                    $mediaContent = ['schema' => $schema];

                    // Add request body example from @example
                    if (!empty($docMeta['examples'])) {
                        $mediaContent['example'] = $docMeta['examples'][0];
                    }

                    // multipart/form-data when a @param file/binary was declared,
                    // else application/json.
                    $contentType = $multipart ? 'multipart/form-data' : 'application/json';

                    $operation['requestBody'] = [
                        'description' => 'Request payload',
                        'required' => !empty($bodyRequired),
                        'content' => [
                            $contentType => $mediaContent,
                        ],
                    ];
                }

                // ORM model response: single -> $ref, list -> array of $ref.
                // (An explicit @example_response below still overrides per-status.)
                if ($ref !== null) {
                    $respSchema = $isModelList
                        ? ['type' => 'array', 'items' => ['$ref' => $ref]]
                        : ['$ref' => $ref];
                    $operation['responses']['200'] = [
                        'description' => 'Successful response',
                        'content' => [
                            'application/json' => ['schema' => $respSchema],
                        ],
                    ];
                }

                // Add example responses from @example_response
                foreach ($docMeta['responses'] as $status => $example) {
                    $operation['responses'][(string) $status] = [
                        'description' => self::statusDescription((int) $status),
                        'content' => [
                            'application/json' => [
                                'example' => $example,
                            ],
                        ],
                    ];
                }

                // Registered response schemas ($ref) — explicit and authoritative.
                // swagger['responseSchemas'] => [200 => 'User', 201 => ['User', true]]
                // (status => name, or status => [name, isList]).
                $responseSchemaMeta = $swaggerMeta['responseSchemas'] ?? [];
                foreach ($responseSchemaMeta as $status => $def) {
                    if (is_array($def)) {
                        $sname = (string) ($def[0] ?? '');
                        $isList = !empty($def[1]);
                    } else {
                        $sname = (string) $def;
                        $isList = false;
                    }
                    if ($sname === '') {
                        continue;
                    }
                    $refSchemas[$sname] = true;
                    $sref = '#/components/schemas/' . $sname;
                    $respSchema = $isList
                        ? ['type' => 'array', 'items' => ['$ref' => $sref]]
                        : ['$ref' => $sref];
                    $operation['responses'][(string) $status] = [
                        'description' => self::statusDescription((int) $status),
                        'content' => [
                            'application/json' => ['schema' => $respSchema],
                        ],
                    ];
                }

                // Security requirement resolution:
                //   1. An explicit swagger['security'] meta wins. A normalized
                //      empty list (e.g. 'public') emits security: [] (explicitly
                //      open), overriding auth_required.
                //   2. Otherwise a secured route (write-by-default, ->secure(),
                //      or @secured GET) gets the default scheme.
                $isWriteMethod = in_array($method, ['post', 'put', 'patch', 'delete'], true);
                $routeRequiresAuth = $isWriteMethod
                    ? empty($route['noAuth'])
                    : !empty($route['secure']);

                if (array_key_exists('security', $swaggerMeta)) {
                    // A scalar scheme name + a sibling 'scopes' key is the common
                    // ergonomic form, parity with Python @security("oauth2", scopes=[...]).
                    $securitySpec = $swaggerMeta['security'];
                    if (is_string($securitySpec) && !empty($swaggerMeta['scopes']) && is_array($swaggerMeta['scopes'])) {
                        $securitySpec = ['scheme' => $securitySpec, 'scopes' => $swaggerMeta['scopes']];
                    }
                    $reqs = self::normalizeSecurity($securitySpec);
                    $operation['security'] = empty($reqs)
                        ? []
                        : self::sanitizeSecurity($reqs, $schemes);
                    if (!empty($operation['security'])) {
                        $operation['responses']['401'] = [
                            'description' => 'Unauthorized',
                        ];
                    }
                } elseif ($routeRequiresAuth) {
                    $requirements = [[$defaultScheme => []]];
                    if ($defaultScheme === 'bearerAuth' && isset($schemes['ssoSession'])) {
                        $requirements[] = ['ssoSession' => []];
                    }
                    $operation['security'] = self::sanitizeSecurity(
                        $requirements,
                        $schemes
                    );
                    $operation['responses']['401'] = [
                        'description' => 'Unauthorized',
                    ];
                }

                if (!isset($spec['paths'][$openApiPath])) {
                    $spec['paths'][$openApiPath] = [];
                }

                $spec['paths'][$openApiPath][$method] = $operation;
            }
        }

        // Build components.schemas from any ORM models referenced by routes.
        if (!empty($models)) {
            if (!isset($spec['components']['schemas'])) {
                $spec['components']['schemas'] = [];
            }
            foreach ($models as $schemaName => $modelClass) {
                $spec['components']['schemas'][$schemaName] = self::modelSchema($modelClass);
            }
        }

        // Registered component schemas referenced via requestSchema /
        // responseSchemas meta (beyond the ORM-model auto-schemas).
        if (!empty($refSchemas)) {
            if (!isset($spec['components']['schemas'])) {
                $spec['components']['schemas'] = [];
            }
            foreach (array_keys($refSchemas) as $schemaName) {
                if (isset(self::$registeredSchemas[$schemaName]) && !isset($spec['components']['schemas'][$schemaName])) {
                    $spec['components']['schemas'][$schemaName] = self::$registeredSchemas[$schemaName];
                }
            }
        }

        // Top-level tags[] array (name-only is valid OpenAPI).
        if (!empty($usedTags)) {
            $spec['tags'] = array_map(fn($t) => ['name' => $t], $usedTags);
        }

        // If no paths were found, set to empty object so JSON encodes as {}
        if (empty($spec['paths'])) {
            $spec['paths'] = new \stdClass();
        }

        return $spec;
    }

    /**
     * Resolve the servers[] block. TINA4_SWAGGER_SERVERS (comma-separated URLs)
     * wins and yields a multi-server list; else a single dev-server entry from
     * SWAGGER_DEV_URL (default http://localhost:7145).
     *
     * @return array<int, array{url: string}>
     */
    private static function servers(): array
    {
        $raw = (string) (DotEnv::getEnv('TINA4_SWAGGER_SERVERS', '') ?? '');
        $urls = array_values(array_filter(array_map('trim', explode(',', $raw)), fn($u) => $u !== ''));
        if (!empty($urls)) {
            return array_map(fn($u) => ['url' => $u], $urls);
        }
        // Default to "/" — correct under any port, host or reverse proxy.
        // A hard-coded host:port is measurably wrong off that port (a server
        // bound to 7146 would advertise 7145, so "Try it out" posts to the
        // wrong place). SWAGGER_DEV_URL still overrides for a fixed dev URL.
        $dev = DotEnv::getEnv('SWAGGER_DEV_URL', '/') ?? '/';
        return [['url' => $dev]];
    }

    /**
     * The first argument that is neither null nor an empty string, or "" when
     * none qualifies. Mirrors the Python master's `A or B` env fallback so an
     * empty TINA4_SWAGGER_CONTACT_* value falls through to its legacy alias.
     */
    private static function firstNonEmpty(?string ...$values): string
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * Build a components.schemas object from an ORM model's field definitions.
     * PHP's ORM field model carries type/PK/auto-increment/FK/nullability, so
     * the schema emits type + format + readOnly + FK, and a `required` array
     * derived from column nullability (a NOT NULL, non-auto-increment column is
     * required — parity with the Python master).
     *
     * @param class-string<ORM> $modelClass
     * @return array<string, mixed>
     */
    private static function modelSchema(string $modelClass): array
    {
        $props = [];
        try {
            $instance = new $modelClass();
            $defs = $instance->getFieldDefinitions();
        } catch (\Throwable $e) {
            return ['type' => 'object', 'properties' => new \stdClass()];
        }

        $required = [];
        foreach ($defs as $name => $def) {
            $schema = self::mapFieldType($def['type'] ?? 'string');
            // A foreign-key column is an integer reference.
            if (!empty($def['foreign_key'])) {
                $schema = ['type' => 'integer'];
            }
            // An auto-increment primary key is database-generated (read-only).
            if (!empty($def['auto_increment'])) {
                $schema['readOnly'] = true;
            }
            $props[$name] = $schema;

            // required is derived from nullability: a NOT NULL column the client
            // must supply. A database-generated (auto-increment) column is
            // readOnly and never required in a request body. Parity with the
            // Python master, whose model schema lists required from field
            // nullability — `required` is what makes the schema worth having.
            if (empty($def['nullable']) && empty($def['auto_increment'])) {
                $required[] = $name;
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => empty($props) ? new \stdClass() : $props,
        ];
        if (!empty($required)) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    /**
     * Map an ORM logical field type to a JSON Schema fragment.
     *
     * @return array<string, mixed>
     */
    private static function mapFieldType(string $type): array
    {
        return match ($type) {
            'int'      => ['type' => 'integer'],
            'float'    => ['type' => 'number'],
            'bool'     => ['type' => 'boolean'],
            'datetime' => ['type' => 'string', 'format' => 'date-time'],
            default    => ['type' => 'string'],
        };
    }

    /**
     * Register /swagger and /swagger/openapi.json routes.
     *
     * Honors TINA4_SWAGGER_ENABLED — when explicitly false, no routes are
     * registered. When unset, defaults to TINA4_DEBUG (Swagger UI is a dev
     * tool by convention; turn it on for prod by setting the env var).
     */
    public static function register(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        Router::get('/swagger/openapi.json', function (Request $request, Response $response) {
            $spec = self::generate();
            return $response->json($spec);
        });

        Router::get('/swagger', function (Request $request, Response $response) {
            return $response->html(self::renderSwaggerUI());
        });
    }

    /**
     * Whether the Swagger UI is enabled. Reads TINA4_SWAGGER_ENABLED first;
     * falls back to TINA4_DEBUG. Public so callers can predicate their own
     * docs/SDK exposure on the same flag.
     */
    public static function isEnabled(): bool
    {
        $explicit = DotEnv::getEnv('TINA4_SWAGGER_ENABLED');
        if ($explicit !== null && $explicit !== '') {
            return DotEnv::isTruthy($explicit);
        }
        return DotEnv::isTruthy(DotEnv::getEnv('TINA4_DEBUG', 'false'));
    }

    /**
     * Parse docblock annotations from a route callback.
     *
     * Supported annotations:
     *   @description <text>        — operation description
     *   @summary <text>            — operation summary
     *   @tags Tag1, Tag2           — operation tags
     *   @example <json>            — request body example
     *   @example_response <status> <json> — response example
     *   @param <type> <name> <Required|Optional> - <description> — parameter
     *   @deprecated                — marks operation as deprecated
     *   @noauth                    — opts out of secure-by-default auth on write routes
     *   @secured                   — requires auth on GET routes
     *
     * @param callable $callback The route handler
     * @return array{description: string|null, summary: string|null, tags: array, examples: array, responses: array, params: array, deprecated: bool, noAuth: bool, secured: bool}
     */
    private static function parseDocBlock(callable $callback): array
    {
        $result = [
            'description' => null,
            'summary' => null,
            'tags' => [],
            'examples' => [],
            'responses' => [],
            'params' => [],
            'deprecated' => false,
            'noAuth' => false,
            'secured' => false,
        ];

        try {
            if (is_array($callback)) {
                $ref = new \ReflectionMethod($callback[0], $callback[1]);
            } else {
                $ref = new \ReflectionFunction($callback);
            }
        } catch (\ReflectionException $e) {
            return $result;
        }

        $docComment = $ref->getDocComment();
        if ($docComment === false || $docComment === '') {
            return $result;
        }

        // Strip the leading /** and trailing */, then process line by line
        $lines = preg_split('/\r?\n/', $docComment);
        $cleanLines = [];
        foreach ($lines as $line) {
            // Remove leading whitespace, *, and the opening/closing markers
            $cleaned = preg_replace('#^\s*/?\*+/?\s?#', '', $line);
            $cleanLines[] = $cleaned;
        }

        foreach ($cleanLines as $line) {
            $trimmed = trim($line);

            // @description <text>
            if (preg_match('/^@description\s+(.+)$/s', $trimmed, $m)) {
                $result['description'] = trim($m[1]);
                continue;
            }

            // @summary <text>
            if (preg_match('/^@summary\s+(.+)$/s', $trimmed, $m)) {
                $result['summary'] = trim($m[1]);
                continue;
            }

            // @tags Tag1, Tag2
            if (preg_match('/^@tags\s+(.+)$/s', $trimmed, $m)) {
                $result['tags'] = array_map('trim', explode(',', $m[1]));
                continue;
            }

            // @example <json>
            if (preg_match('/^@example\s+(\{.+\}|\[.+\])$/s', $trimmed, $m)) {
                $decoded = json_decode($m[1], true);
                if ($decoded !== null) {
                    $result['examples'][] = $decoded;
                }
                continue;
            }

            // @example_response <status> <json>
            if (preg_match('/^@example_response\s+(\d{3})\s+(\{.+\}|\[.+\])$/s', $trimmed, $m)) {
                $status = $m[1];
                $decoded = json_decode($m[2], true);
                if ($decoded !== null) {
                    $result['responses'][$status] = $decoded;
                }
                continue;
            }

            // @param <type> <name> <Required|Optional> - <description>
            if (preg_match('/^@param\s+(\S+)\s+(\S+)\s+(Required|Optional)\s*-\s*(.+)$/i', $trimmed, $m)) {
                $result['params'][] = [
                    'type' => strtolower($m[1]),
                    'name' => $m[2],
                    'required' => strtolower($m[3]) === 'required',
                    'description' => trim($m[4]),
                ];
                continue;
            }

            // @deprecated
            if ($trimmed === '@deprecated') {
                $result['deprecated'] = true;
                continue;
            }

            // @noauth — opt out of secure-by-default auth on write routes
            if ($trimmed === '@noauth') {
                $result['noAuth'] = true;
                continue;
            }

            // @secured — require auth on GET routes
            if ($trimmed === '@secured') {
                $result['secured'] = true;
                continue;
            }
        }

        // If no summary but description exists, use first line of description as summary
        if ($result['summary'] === null && $result['description'] !== null) {
            $firstLine = strtok($result['description'], "\n");
            $result['summary'] = $firstLine !== false ? $firstLine : $result['description'];
        }

        return $result;
    }

    /**
     * Convert a Tina4 route pattern to OpenAPI path format.
     *
     * Tina4 uses {param} which is already OpenAPI-compatible.
     * Catch-all patterns like {path:.*} are normalised to {path}.
     */
    private static function convertPath(string $pattern): string
    {
        // Strip ANY type token, not just the catch-all form.
        //
        // The router accepts {id:int}, {slug:slug}, {u:uuid} and the rest of
        // Router::compilePath's table. This handled only {name:.*}, so an
        // ordinary typed route kept its token in the path KEY - the template
        // name became `id:int`, nothing declared a parameter for it, and the
        // document failed OpenAPI validation. Measured: the route served
        // GET /api/typed/42 -> 200 while its own spec was invalid.
        return preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*):[^}]*\}#', '{$1}', $pattern);
    }

    /**
     * Route param type token -> JSON Schema fragment.
     *
     * Mirrors the Python master's _PARAM_TYPE_SCHEMA, over exactly the token
     * set Router::compilePath accepts. An unknown token degrades to string
     * rather than being dropped: a parameter documented loosely still makes the
     * document valid, whereas no parameter at all does not.
     */
    private const PARAM_TYPE_SCHEMA = [
        'int'     => ['type' => 'integer'],
        'integer' => ['type' => 'integer'],
        'float'   => ['type' => 'number'],
        'number'  => ['type' => 'number'],
        'uuid'    => ['type' => 'string', 'format' => 'uuid'],
        'slug'    => ['type' => 'string', 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$'],
        'alpha'   => ['type' => 'string', 'pattern' => '^[A-Za-z]+$'],
        'alnum'   => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]+$'],
        'path'    => ['type' => 'string'],
        'string'  => ['type' => 'string'],
    ];

    /**
     * Extract path parameters from a route pattern.
     *
     * @return array<int, array<string, mixed>> OpenAPI parameter objects
     */
    private static function extractPathParameters(string $pattern): array
    {
        $params = [];

        // {name} and {name:anything} - the token is captured so it can be
        // mapped, rather than being part of the name or excluding the match.
        if (preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]*))?\}#', $pattern, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $type = $match[2] ?? '';
                $params[] = [
                    'name' => $match[1],
                    'in' => 'path',
                    'required' => true,
                    'schema' => self::PARAM_TYPE_SCHEMA[$type] ?? ['type' => 'string'],
                ];
            }
        }

        return $params;
    }

    /**
     * Infer a tag from the first meaningful path segment.
     */
    private static function inferTag(string $pattern): string
    {
        $segments = array_values(array_filter(explode('/', $pattern), fn($s) => $s !== ''));

        if (empty($segments)) {
            return 'default';
        }

        $first = $segments[0];

        // Skip version prefixes like v1, v2 — use the next segment
        if (preg_match('#^v\d+$#', $first) && isset($segments[1])) {
            $first = $segments[1];
        }

        // Skip if it is a parameter
        if (str_starts_with($first, '{')) {
            return 'default';
        }

        return $first;
    }

    /**
     * Map PHP type names to OpenAPI schema types.
     */
    private static function mapParamType(string $phpType): string
    {
        return match ($phpType) {
            'int', 'integer' => 'integer',
            'float', 'double', 'number' => 'number',
            'bool', 'boolean' => 'boolean',
            'array' => 'array',
            default => 'string',
        };
    }

    /**
     * Return a human-readable description for an HTTP status code.
     */
    private static function statusDescription(int $code): string
    {
        return match ($code) {
            200 => 'Successful response',
            201 => 'Created',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => 'Response',
        };
    }

    /**
     * Render the Swagger UI HTML page using jsdelivr.net CDN assets.
     */
    private static function renderSwaggerUI(): string
    {
        $title = DotEnv::getEnv('TINA4_SWAGGER_TITLE', 'Tina4 API') ?? 'Tina4 API';
        // The Swagger UI assets load from a CDN by default (keeps the framework
        // zero-dependency / small — we don't vendor ~1.4MB of swagger-ui-dist).
        // jsdelivr (SWAG-CDN-NO-SRI, ADR-0004) — the SAME default as the Python
        // and Ruby masters, so all four frameworks pull the UI bundle from one
        // CDN rather than splitting jsdelivr/unpkg. Air-gapped deployments point
        // TINA4_SWAGGER_UI_CDN at a self-hosted mirror (a base URL serving
        // swagger-ui.css + swagger-ui-bundle.js).
        $cdn = rtrim((string) (DotEnv::getEnv('TINA4_SWAGGER_UI_CDN', 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@5') ?? 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@5'), '/');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title} — Swagger UI</title>
    <link rel="stylesheet" href="{$cdn}/swagger-ui.css">
    <style>
        html { box-sizing: border-box; overflow-y: scroll; }
        *, *:before, *:after { box-sizing: inherit; }
        body { margin: 0; background: #fafafa; }
        .topbar { display: none; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="{$cdn}/swagger-ui-bundle.js"></script>
    <script>
        window.onload = function() {
            SwaggerUIBundle({
                url: '/swagger/openapi.json',
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIBundle.SwaggerUIStandalonePreset
                ],
                layout: 'BaseLayout'
            });
        };
    </script>
</body>
</html>
HTML;
    }
}
