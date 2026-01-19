<?php

namespace Tina4;

/**
 * Tina4 Swagger – FINAL 2025 VERSION
 * - @secure annotation = Bearer token required
 * - @example, @example_response, @example_request all work
 * - servers URL perfect
 * - only documented routes shown
 * - 100% OpenAPI 3.0.3 valid
 */
final class Swagger implements \JsonSerializable
{
    private array $swagger = [];

    public function __construct(
        string $title = "Tina4 API",
        string $description = "API Documentation",
        string $version = "1.0.0",
        string $basePath = "/"
    ) {
        $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443 ? "https" : "http";
        $host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base       = rtrim($basePath, '/');
        $serverUrl  = $protocol . "://" . $host . ($base !== '' ? $base : '');

        $this->swagger = [
            "openapi" => "3.0.3",
            "info" => [
                "title"       => $title,
                "description" => $description,
                "version"     => $version
            ],
            "servers" => [
                ["url" => $serverUrl]
            ],
            "paths" => [],
            "components" => [
                "securitySchemes" => [
                    "bearerAuth" => [
                        "type" => "http",
                        "scheme" => "bearer",
                        "bearerFormat" => "JWT"
                    ]
                ]
            ],
            "tags" => []
        ];

        $this->buildFromRoutes();
    }

    private function buildFromRoutes(): void
    {
        global $arrRoutes;
        if (empty($arrRoutes)) return;

        $allTags = [];

        foreach ($arrRoutes as $route) {
            $method = strtolower($route["method"]);
            $path   = "/" . ltrim(str_replace("//", "/", $route["routePath"]), "/");

            $reflection = $this->getReflection($route);
            if (!$reflection) continue;

            // Attributes
            $summary = $description = "";
            $tags = [];

            foreach ($reflection->getAttributes() as $attr) {
                $obj = $attr->newInstance();
                if ($obj instanceof \Description) {
                    $description = trim($obj->value);
                    $summary = $summary ?: $description;
                }
                if ($obj instanceof \Tags) {
                    $tags = array_merge($tags, $obj->tags);
                }
            }

            // Docblock annotations
            $doc = $reflection->getDocComment() ?: "";
            $annotations = $this->parseDocBlock($doc);

            if (!empty($annotations["summary"][0]))     $summary     = $annotations["summary"][0];
            if (!empty($annotations["description"][0])) $description = $annotations["description"][0];
            if (!empty($annotations["tags"][0])) {
                $tags = array_merge($tags, array_map('trim', explode(',', $annotations["tags"][0])));
            }

            // Skip undocumented routes
            if (empty($description) && empty($summary)) continue;

            // @secure = requires auth
            $isSecure = !empty($annotations["secure"]);

            // Examples
            $requestExample  = $this->makeExample($annotations["example_request"][0] ?? $annotations["example"][0] ?? null);
            $responseExample = $this->makeExample($annotations["example_response"][0] ?? null)
                ?? $this->makeExample($annotations["example"][0] ?? null);

            // Parameters
            $params = array_merge($this->pathParams($path), $this->queryParams($annotations));

            // Responses
            $responses = $this->responses($responseExample);

            // Build operation
            $operation = [
                "summary"     => $summary ?: "No summary",
                "description" => $description,
                "tags"        => array_unique($tags),
                "parameters"  => $params,
                "responses"   => $responses
            ];

            // Request body
            if (in_array($method, ["post", "put", "patch"]) && $requestExample) {
                $operation["requestBody"] = [
                    "required" => true,
                    "content" => [
                        "application/json" => [
                            "schema"  => $this->schema($requestExample),
                            "example" => $requestExample->data ?? $requestExample
                        ]
                    ]
                ];
            }

            // ONLY add security when @secure is present
            if ($isSecure) {
                $operation["security"] = [["bearerAuth" => []]];
            }

            // Register path
            if ($method === "any") {
                foreach (["get","post","put","patch","delete","options","head"] as $m) {
                    $this->swagger["paths"][$path][$m] = $operation;
                }
            } else {
                $this->swagger["paths"][$path][$method] = $operation;
            }

            $allTags = array_merge($allTags, $tags);
        }

        $this->swagger["tags"] = array_values(array_map(fn($t) => ["name" => $t], array_unique($allTags)));
    }

    // ———————————————————————— Helpers ————————————————————————

    private function parseDocBlock(string $doc): array
    {
        $result = [];
        if (preg_match_all('/@(\w+)\s+([^\n\r*]+)/', $doc, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $result[strtolower($match[1])][] = trim($match[2]);
            }
        }
        return $result;
    }

    /**
     * Makes an example entry
     * @param string|null $value
     * @return object|null
     */
    private function makeExample(?string $value): ?object
    {
        if (!$value) return null;
        $value = trim($value);

        // Remove common markdown/code fences
        $value = preg_replace('#^(```json\s*|\s*```)$#im', '', $value);
        $value = trim($value);

        // Try as JSON first (most common case)
        if (str_starts_with($value, '{') || str_starts_with($value, '[')) {
            $data = json_decode($value, false);
            if (json_last_error() === JSON_ERROR_NONE) {
                return (object) ['data' => $data];
            }
        }

        // Try as class name
        if (class_exists($value)) {
            try {
                $obj = new $value();

                // Popular patterns in Tina4 / PHP frameworks
                if (method_exists($obj, 'toArray')) {
                    $data = $obj->toArray();
                } elseif (method_exists($obj, 'asArray')) {
                    $data = $obj->asArray();
                } elseif (method_exists($obj, 'jsonSerialize')) {
                    $data = $obj->jsonSerialize();
                } else {
                    $data = json_decode(json_encode($obj), false);
                }

                $props = null;
                if (method_exists($obj, 'getFieldDefinitions')) {
                    $defs = $obj->getFieldDefinitions();
                    $props = [];
                    foreach ($defs as $f) {
                        $props[$f->fieldName ?? $f['name'] ?? ''] = ['type' => $f->dataType ?? 'string'];
                    }
                    $props = (object) array_filter($props);
                }

                return (object) [
                    'data' => $data,
                    'properties' => $props ?: null
                ];
            } catch (\Throwable $e) {
                error_log("Swagger makeExample instantiation failed for $value: " . $e->getMessage());
                return null;
            }
        }

        return null;
    }

    private function pathParams(string $path): array
    {
        $p = [];
        preg_match_all('/\{([^}]+)}/', $path, $m);
        foreach ($m[1] as $param) {
            $type = str_ends_with($param, ":int") ? "integer" : "string";
            $name = str_replace(":int", "", $param);
            $p[] = ["name" => $name, "in" => "path", "required" => true, "schema" => ["type" => $type]];
        }
        return $p;
    }

    private function queryParams(array $a): array
    {
        $p = [];
        foreach (["params", "queryparams"] as $k) {
            if (!empty($a[$k][0])) {
                foreach (explode(',', $a[$k][0]) as $param) {
                    $param = trim($param);
                    if ($param) $p[] = ["name" => $param, "in" => "query", "schema" => ["type" => "string"]];
                }
            }
        }
        return $p;
    }

    private function responses(?object $ex): array
    {
        $r = [
            "200" => ["description" => "Success", "content" => ["application/json" => ["schema" => ["type" => "object"]]]],
            "400" => ["description" => "Bad Request"],
            "401" => ["description" => "Unauthorized"],
            "404" => ["description" => "Not Found"],
            "500" => ["description" => "Server Error"]
        ];

        if ($ex) {
            $r["200"]["content"]["application/json"] = [
                "schema"  => $this->schema($ex),
                "example" => $ex->data ?? $ex
            ];
        }
        return $r;
    }

    private function schema(object $ex): array
    {
        return isset($ex->properties)
            ? ["type" => "object", "properties" => $ex->properties]
            : ["type" => "object"];
    }

    private function getReflection(array $r): ?\ReflectionFunctionAbstract
    {
        try {
            return !empty($r["class"])
                ? (new \ReflectionClass($r["class"]))->getMethod($r["function"])
                : new \ReflectionFunction($r["function"]);
        } catch (\Throwable) {
            return null;
        }
    }

    public function __toString(): string
    {
        header("Content-Type: application/json; charset=utf-8");
        return json_encode($this->swagger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function jsonSerialize(): array
    {
        return $this->swagger;
    }
}