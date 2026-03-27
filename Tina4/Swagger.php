<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Zero-dependency Swagger/OpenAPI 3.0.3 spec generator and UI server.
 *
 * Reads registered routes from Router::list() and produces a compliant
 * OpenAPI specification. Serves Swagger UI from CDN at /swagger and the
 * raw spec at /swagger/openapi.json.
 *
 * Env vars (via DotEnv):
 *   SWAGGER_TITLE       — API title (default: "Tina4 API")
 *   SWAGGER_VERSION     — API version (default: "1.0.0")
 *   SWAGGER_DESCRIPTION — API description (default: "Auto-generated from Tina4 routes")
 */
class Swagger
{
    /**
     * Generate an OpenAPI 3.0.3 spec from registered routes.
     *
     * @param string $title   API title
     * @param string $version API version string
     * @return array<string, mixed> OpenAPI spec as a nested associative array
     */
    public static function generateSpec(string $title = 'Tina4 API', string $version = '1.0.0'): array
    {
        $title = DotEnv::getEnv('SWAGGER_TITLE', $title) ?? $title;
        $version = DotEnv::getEnv('SWAGGER_VERSION', $version) ?? $version;
        $description = DotEnv::getEnv('SWAGGER_DESCRIPTION', 'Auto-generated from Tina4 routes') ?? 'Auto-generated from Tina4 routes';

        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => $title,
                'version' => $version,
                'description' => $description,
            ],
            'paths' => [],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                    ],
                ],
            ],
        ];

        $routes = Router::list();

        // Group routes by path pattern
        $grouped = [];
        foreach ($routes as $route) {
            $pattern = $route['pattern'];
            $method = strtolower($route['method']);

            // Skip the swagger routes themselves
            if ($pattern === '/swagger' || $pattern === '/swagger/openapi.json') {
                continue;
            }

            if (!isset($grouped[$pattern])) {
                $grouped[$pattern] = [];
            }
            $grouped[$pattern][$method] = $route;
        }

        foreach ($grouped as $pattern => $methods) {
            $openApiPath = self::convertPath($pattern);
            $pathParams = self::extractPathParameters($pattern);

            foreach ($methods as $method => $route) {
                $tag = self::inferTag($pattern);
                $operationId = $method . str_replace(['/', '{', '}', '-'], ['_', '', '', '_'], $pattern);

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

                // Add path parameters
                if (!empty($pathParams)) {
                    $operation['parameters'] = $pathParams;
                }

                // Build parameters and requestBody properties from @param annotations
                $bodyProperties = [];
                $bodyRequired = [];
                $queryParams = [];
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
                            $bodyProperties[$paramDef['name']] = [
                                'type' => self::mapParamType($paramDef['type']),
                                'description' => $paramDef['description'],
                            ];
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

                // Add request body hint for methods that accept a body
                if (in_array($method, ['post', 'put', 'patch'], true)) {
                    $schema = ['type' => 'object'];
                    if (!empty($bodyProperties)) {
                        $schema['properties'] = $bodyProperties;
                        if (!empty($bodyRequired)) {
                            $schema['required'] = $bodyRequired;
                        }
                    }

                    $jsonContent = ['schema' => $schema];

                    // Add request body example from @example
                    if (!empty($docMeta['examples'])) {
                        $jsonContent['example'] = $docMeta['examples'][0];
                    }

                    $operation['requestBody'] = [
                        'description' => 'Request payload',
                        'required' => !empty($bodyRequired),
                        'content' => [
                            'application/json' => $jsonContent,
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

                // Add security requirement: write routes are secure by default,
                // GET/HEAD/OPTIONS are secure only when explicitly marked.
                $isWriteMethod = in_array($method, ['post', 'put', 'patch', 'delete'], true);
                $routeRequiresAuth = $isWriteMethod
                    ? empty($route['noAuth'])
                    : !empty($route['secure']);

                if ($routeRequiresAuth) {
                    $operation['security'] = [
                        ['bearerAuth' => []],
                    ];
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

        // If no paths were found, set to empty object so JSON encodes as {}
        if (empty($spec['paths'])) {
            $spec['paths'] = new \stdClass();
        }

        return $spec;
    }

    /**
     * Register /swagger and /swagger/openapi.json routes.
     */
    public static function register(): void
    {
        Router::get('/swagger/openapi.json', function (Request $request, Response $response) {
            $spec = self::generateSpec();
            return $response->json($spec);
        });

        Router::get('/swagger', function (Request $request, Response $response) {
            return $response->html(self::renderSwaggerUI());
        });
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
        // Convert catch-all {name:.*} to {name}
        return preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*):\.\*\}#', '{$1}', $pattern);
    }

    /**
     * Extract path parameters from a route pattern.
     *
     * @return array<int, array<string, mixed>> OpenAPI parameter objects
     */
    private static function extractPathParameters(string $pattern): array
    {
        $params = [];

        // Match both {name} and {name:.*}
        if (preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::\.?\*)?\}#', $pattern, $matches)) {
            foreach ($matches[1] as $name) {
                $params[] = [
                    'name' => $name,
                    'in' => 'path',
                    'required' => true,
                    'schema' => [
                        'type' => 'string',
                    ],
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
     * Render the Swagger UI HTML page using unpkg.com CDN assets.
     */
    private static function renderSwaggerUI(): string
    {
        $title = DotEnv::getEnv('SWAGGER_TITLE', 'Tina4 API') ?? 'Tina4 API';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title} — Swagger UI</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
    <style>
        html { box-sizing: border-box; overflow-y: scroll; }
        *, *:before, *:after { box-sizing: inherit; }
        body { margin: 0; background: #fafafa; }
        .topbar { display: none; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
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
