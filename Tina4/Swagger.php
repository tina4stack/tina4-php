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
 *   SWAGGER_TITLE       — API title (default: "Tina4 PHP API")
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
    public static function generateSpec(string $title = 'Tina4 PHP API', string $version = '1.0.0'): array
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

                $operation = [
                    'tags' => [$tag],
                    'operationId' => $operationId,
                    'summary' => strtoupper($method) . ' ' . $pattern,
                    'responses' => [
                        '200' => [
                            'description' => 'Successful response',
                        ],
                    ],
                ];

                // Add path parameters
                if (!empty($pathParams)) {
                    $operation['parameters'] = $pathParams;
                }

                // Add request body hint for methods that accept a body
                if (in_array($method, ['post', 'put', 'patch'], true)) {
                    $operation['requestBody'] = [
                        'description' => 'Request payload',
                        'required' => false,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                ],
                            ],
                        ],
                    ];
                }

                // Add security requirement for secure routes
                if ($route['secure']) {
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
     * Render the Swagger UI HTML page using unpkg.com CDN assets.
     */
    private static function renderSwaggerUI(): string
    {
        $title = DotEnv::getEnv('SWAGGER_TITLE', 'Tina4 PHP API') ?? 'Tina4 PHP API';

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
