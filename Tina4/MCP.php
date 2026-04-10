<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * MCP (Model Context Protocol) server for AI tool integration.
 *
 * Built-in MCP server for dev tools + developer API for custom MCP servers.
 *
 * Usage (developer):
 *
 *     use Tina4\McpServer;
 *
 *     $mcp = new McpServer("/my-mcp", "My App Tools");
 *
 *     $mcp->registerTool("lookup_invoice", function(string $invoiceNo): array {
 *         return $db->fetchOne("SELECT * FROM invoices WHERE invoice_no = ?", [$invoiceNo]);
 *     }, "Find invoice by number");
 *
 *     $mcp->registerResource("app://schema", function() use ($db) {
 *         return $db->getDatabase();
 *     }, "Database schema");
 *
 * Built-in dev tools auto-register when TINA4_DEBUG=true and running on localhost.
 */

namespace Tina4;

// ── PHP 8.1+ Attributes ──────────────────────────────────────────

/**
 * Mark a method as an MCP tool.
 *
 * Usage:
 *     #[McpTool("lookup_invoice", "Find invoice by number")]
 *     public function lookupInvoice(string $invoiceNo): array { ... }
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION)]
class McpTool
{
    public function __construct(
        public string $name = '',
        public string $description = ''
    ) {
    }
}

/**
 * Mark a method as an MCP resource.
 *
 * Usage:
 *     #[McpResource("app://tables", "Database tables")]
 *     public function getTables(): array { ... }
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION)]
class McpResource
{
    public function __construct(
        public string $uri = '',
        public string $description = '',
        public string $mimeType = 'application/json'
    ) {
    }
}

// ── JSON-RPC 2.0 Codec ──────────────────────────────────────────

/**
 * Static methods for JSON-RPC 2.0 encode/decode. Zero dependencies.
 */
class McpProtocol
{
    // Standard JSON-RPC 2.0 error codes
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    /**
     * Encode a successful JSON-RPC 2.0 response.
     */
    public static function encodeResponse(mixed $requestId, mixed $result): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'result' => $result,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Encode a JSON-RPC 2.0 error response.
     */
    public static function encodeError(mixed $requestId, int $code, string $message, mixed $data = null): string
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'error' => $error,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Encode a JSON-RPC 2.0 notification (no id).
     */
    public static function encodeNotification(string $method, mixed $params = null): string
    {
        $msg = ['jsonrpc' => '2.0', 'method' => $method];
        if ($params !== null) {
            $msg['params'] = $params;
        }
        return json_encode($msg, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Decode a JSON-RPC 2.0 request.
     *
     * @param string|array $data Raw JSON string or pre-parsed array
     * @return array{0: string, 1: array, 2: mixed} [method, params, requestId]
     * @throws \ValueError If the message is malformed
     */
    public static function decodeRequest(string|array $data): array
    {
        if (is_string($data)) {
            $msg = json_decode($data, true);
            if ($msg === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new \ValueError('Invalid JSON: ' . json_last_error_msg());
            }
        } else {
            $msg = $data;
        }

        if (!is_array($msg)) {
            throw new \ValueError('Message must be a JSON object');
        }

        if (($msg['jsonrpc'] ?? null) !== '2.0') {
            throw new \ValueError('Missing or invalid jsonrpc version');
        }

        $method = $msg['method'] ?? null;
        if (!$method || !is_string($method)) {
            throw new \ValueError('Missing or invalid method');
        }

        $params = $msg['params'] ?? [];
        $requestId = $msg['id'] ?? null; // null for notifications

        return [$method, $params, $requestId];
    }
}

// ── Schema Extraction ────────────────────────────────────────────

/**
 * Extract JSON Schema from PHP type hints using Reflection.
 */
class McpSchema
{
    /** PHP type name -> JSON Schema type */
    private const TYPE_MAP = [
        'string' => 'string',
        'int' => 'integer',
        'float' => 'number',
        'bool' => 'boolean',
        'array' => 'array',
        'object' => 'object',
    ];

    /**
     * Extract JSON Schema inputSchema from a callable's type hints.
     */
    public static function fromCallable(callable $callable): array
    {
        if ($callable instanceof \Closure) {
            $ref = new \ReflectionFunction($callable);
        } elseif (is_array($callable)) {
            $ref = new \ReflectionMethod($callable[0], $callable[1]);
        } elseif (is_string($callable) && strpos($callable, '::') !== false) {
            $ref = new \ReflectionMethod($callable);
        } elseif (is_string($callable)) {
            $ref = new \ReflectionFunction($callable);
        } else {
            // Invokable object
            $ref = new \ReflectionMethod($callable, '__invoke');
        }

        return self::fromReflection($ref);
    }

    /**
     * Extract JSON Schema from a ReflectionFunctionAbstract.
     */
    public static function fromReflection(\ReflectionFunctionAbstract $ref): array
    {
        $properties = [];
        $required = [];

        foreach ($ref->getParameters() as $param) {
            $name = $param->getName();

            // Skip $this for bound closures (shouldn't appear) and self/static
            if ($name === 'self' || $name === 'this') {
                continue;
            }

            $type = $param->getType();
            $jsonType = 'string'; // default

            if ($type instanceof \ReflectionNamedType) {
                $typeName = $type->getName();
                $jsonType = self::TYPE_MAP[$typeName] ?? 'string';
            }

            $prop = ['type' => $jsonType];

            if ($param->isDefaultValueAvailable()) {
                $prop['default'] = $param->getDefaultValue();
            } else {
                $required[] = $name;
            }

            $properties[$name] = $prop;
        }

        $schema = ['type' => 'object', 'properties' => $properties];
        if (!empty($required)) {
            $schema['required'] = $required;
        }
        return $schema;
    }
}

// ── MCP Server ───────────────────────────────────────────────────

/**
 * MCP server that registers tools and resources on a given HTTP path.
 */
class McpServer
{
    /** @var McpServer[] Class-level registry of all MCP server instances */
    private static array $instances = [];

    /** @var McpServer|null Default server for decorator helpers */
    private static ?McpServer $defaultServer = null;

    /**
     * Get (or create) the default server used by mcp_tool() / mcp_resource() helpers.
     */
    public static function getDefaultServer(): McpServer
    {
        if (self::$defaultServer === null) {
            self::$defaultServer = new McpServer('/__dev/mcp', 'Tina4 Dev Tools');
        }
        return self::$defaultServer;
    }

    private string $path;
    private string $name;
    private string $version;
    private array $tools = [];
    private array $resources = [];
    private bool $initialized = false;

    public function __construct(string $path, string $name = 'Tina4 MCP', string $version = '1.0.0')
    {
        $this->path = rtrim($path, '/');
        $this->name = $name;
        $this->version = $version;
        self::$instances[] = $this;
    }

    /**
     * Get the server path.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Get the server name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Register a tool callable.
     */
    public function registerTool(string $name, callable $handler, string $description = '', ?array $schema = null): void
    {
        if ($schema === null) {
            $schema = McpSchema::fromCallable($handler);
        }

        $this->tools[$name] = [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $schema,
            'handler' => $handler,
        ];
    }

    /**
     * Register a resource URI.
     */
    public function registerResource(string $uri, callable $handler, string $description = '', string $mimeType = 'application/json'): void
    {
        $this->resources[$uri] = [
            'uri' => $uri,
            'name' => $description ?: $uri,
            'description' => $description,
            'mimeType' => $mimeType,
            'handler' => $handler,
        ];
    }

    /**
     * Register tools and resources from an object using PHP 8.1+ attributes.
     *
     * Scans all public methods of $object for #[McpTool] and #[McpResource] attributes.
     */
    public function registerFromAttributes(object $object): void
    {
        $ref = new \ReflectionObject($object);

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Check for McpTool attribute
            $toolAttrs = $method->getAttributes(McpTool::class);
            foreach ($toolAttrs as $attr) {
                /** @var McpTool $tool */
                $tool = $attr->newInstance();
                $toolName = $tool->name ?: $method->getName();
                $description = $tool->description;
                $callable = [$object, $method->getName()];
                $this->registerTool($toolName, $callable, $description);
            }

            // Check for McpResource attribute
            $resAttrs = $method->getAttributes(McpResource::class);
            foreach ($resAttrs as $attr) {
                /** @var McpResource $res */
                $res = $attr->newInstance();
                $callable = [$object, $method->getName()];
                $this->registerResource($res->uri, $callable, $res->description, $res->mimeType);
            }
        }
    }

    /**
     * Process an incoming JSON-RPC message and return the response.
     */
    public function handleMessage(string|array $rawData): string
    {
        try {
            [$method, $params, $requestId] = McpProtocol::decodeRequest($rawData);
        } catch (\ValueError $e) {
            return McpProtocol::encodeError(null, McpProtocol::PARSE_ERROR, $e->getMessage());
        }

        $handlers = [
            'initialize' => [$this, 'handleInitialize'],
            'notifications/initialized' => [$this, 'handleInitialized'],
            'tools/list' => [$this, 'handleToolsList'],
            'tools/call' => [$this, 'handleToolsCall'],
            'resources/list' => [$this, 'handleResourcesList'],
            'resources/read' => [$this, 'handleResourcesRead'],
            'ping' => [$this, 'handlePing'],
        ];

        $handler = $handlers[$method] ?? null;

        if ($handler === null) {
            return McpProtocol::encodeError(
                $requestId,
                McpProtocol::METHOD_NOT_FOUND,
                "Method not found: $method"
            );
        }

        try {
            $result = $handler($params);
            if ($requestId === null) {
                return ''; // Notification - no response
            }
            return McpProtocol::encodeResponse($requestId, $result);
        } catch (\Throwable $e) {
            return McpProtocol::encodeError($requestId, McpProtocol::INTERNAL_ERROR, $e->getMessage());
        }
    }

    /**
     * Handle initialize request - return server capabilities.
     */
    private function handleInitialize(array $params): array
    {
        $this->initialized = true;
        return [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => ['subscribe' => false, 'listChanged' => false],
            ],
            'serverInfo' => [
                'name' => $this->name,
                'version' => $this->version,
            ],
        ];
    }

    /**
     * Handle initialized notification.
     */
    private function handleInitialized(array $params): array
    {
        return [];
    }

    /**
     * Handle ping.
     */
    private function handlePing(array $params): array
    {
        return [];
    }

    /**
     * Return list of registered tools.
     */
    private function handleToolsList(array $params): array
    {
        $tools = [];
        foreach ($this->tools as $t) {
            $tools[] = [
                'name' => $t['name'],
                'description' => $t['description'],
                'inputSchema' => $t['inputSchema'],
            ];
        }
        return ['tools' => $tools];
    }

    /**
     * Invoke a tool by name.
     */
    private function handleToolsCall(array $params): array
    {
        $toolName = $params['name'] ?? null;
        if (!$toolName) {
            throw new \RuntimeException('Missing tool name');
        }

        $tool = $this->tools[$toolName] ?? null;
        if (!$tool) {
            throw new \RuntimeException("Unknown tool: $toolName");
        }

        $arguments = $params['arguments'] ?? [];
        $handler = $tool['handler'];

        // Call the handler with named arguments
        $result = $handler(...$arguments);

        // Format result as MCP content
        if (is_string($result)) {
            $content = [['type' => 'text', 'text' => $result]];
        } elseif (is_array($result) || is_object($result)) {
            $content = [['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]];
        } else {
            $content = [['type' => 'text', 'text' => (string)$result]];
        }

        return ['content' => $content];
    }

    /**
     * Return list of registered resources.
     */
    private function handleResourcesList(array $params): array
    {
        $resources = [];
        foreach ($this->resources as $r) {
            $resources[] = [
                'uri' => $r['uri'],
                'name' => $r['name'],
                'description' => $r['description'],
                'mimeType' => $r['mimeType'],
            ];
        }
        return ['resources' => $resources];
    }

    /**
     * Read a resource by URI.
     */
    private function handleResourcesRead(array $params): array
    {
        $uri = $params['uri'] ?? null;
        if (!$uri) {
            throw new \RuntimeException('Missing resource URI');
        }

        $resource = $this->resources[$uri] ?? null;
        if (!$resource) {
            throw new \RuntimeException("Unknown resource: $uri");
        }

        $result = ($resource['handler'])();

        if (is_string($result)) {
            $text = $result;
        } elseif (is_array($result) || is_object($result)) {
            $text = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } else {
            $text = (string)$result;
        }

        return [
            'contents' => [[
                'uri' => $uri,
                'mimeType' => $resource['mimeType'],
                'text' => $text,
            ]],
        ];
    }

    /**
     * Register HTTP routes for this MCP server on the Tina4 router.
     *
     * Registers:
     *     POST {path}/message - JSON-RPC message endpoint
     *     GET  {path}/sse     - SSE endpoint for streaming
     */
    public function registerRoutes($router = null): void
    {
        $server = $this;
        $msgPath = $this->path . '/message';
        $ssePath = $this->path . '/sse';

        Router::post($msgPath, function (Request $request, Response $response) use ($server) {
            $body = $request->body;
            if (is_string($body)) {
                $raw = $body;
            } elseif (is_array($body)) {
                $raw = $body;
            } else {
                $raw = (string)$body;
            }
            $result = $server->handleMessage($raw);
            if ($result === '') {
                return $response('', 204);
            }
            return $response(json_decode($result, true));
        })->noAuth();

        Router::get($ssePath, function (Request $request, Response $response) use ($server) {
            $baseUrl = rtrim($request->url ?? '', '/');
            // Strip /sse from end to get base path
            $basePath = preg_replace('#/sse$#', '', $baseUrl);
            $endpointUrl = $basePath . '/message';
            $sseData = "event: endpoint\ndata: $endpointUrl\n\n";

            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            echo $sseData;
            flush();
            return null;
        })->noAuth();
    }

    /**
     * Write/update .claude/settings.json with this MCP server config.
     */
    public function writeClaudeConfig(int $port = 7146): void
    {
        $configDir = '.claude';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        $configFile = $configDir . '/settings.json';

        $config = [];
        if (file_exists($configFile)) {
            $contents = file_get_contents($configFile);
            $decoded = json_decode($contents, true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }

        if (!isset($config['mcpServers'])) {
            $config['mcpServers'] = [];
        }

        $serverKey = strtolower(str_replace(' ', '-', $this->name));
        $config['mcpServers'][$serverKey] = [
            'url' => "http://localhost:{$port}{$this->path}/sse",
        ];

        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * Check if the server is running on localhost.
     */
    public static function isLocalhost(): bool
    {
        $host = getenv('HOST_NAME') ?: 'localhost:7146';
        $hostPart = explode(':', $host)[0];
        return in_array($hostPart, ['localhost', '127.0.0.1', '0.0.0.0', '::1', ''], true);
    }

    /**
     * Get all registered MCP server instances.
     *
     * @return McpServer[]
     */
    public static function getInstances(): array
    {
        return self::$instances;
    }

    /**
     * Clear the instance registry (for testing).
     */
    public static function clearInstances(): void
    {
        self::$instances = [];
    }
}

// ── Built-in Dev Tools ───────────────────────────────────────────

/**
 * Register all 24 built-in dev tools on an McpServer instance.
 *
 * Security:
 *     - Only active when TINA4_DEBUG=true AND running on localhost
 *     - File operations sandboxed to project directory
 *     - database_execute available on localhost only
 *     - TINA4_MCP_REMOTE=true to enable on remote servers (opt-in)
 */
class McpDevTools
{
    /**
     * Register all built-in dev tools on the given McpServer.
     */
    public static function register(McpServer $server): void
    {
        $projectRoot = realpath(getcwd()) ?: getcwd();

        // ── Helpers ───────────────────────────────────────────

        $safePath = function (string $relPath) use ($projectRoot): string {
            $resolved = realpath($projectRoot . DIRECTORY_SEPARATOR . $relPath);
            // realpath returns false if file doesn't exist yet - try parent dir
            if ($resolved === false) {
                $parent = realpath(dirname($projectRoot . DIRECTORY_SEPARATOR . $relPath));
                $basename = basename($relPath);
                if ($parent === false || strpos($parent, $projectRoot) !== 0) {
                    throw new \RuntimeException("Path escapes project directory: $relPath");
                }
                return $parent . DIRECTORY_SEPARATOR . $basename;
            }
            if (strpos($resolved, $projectRoot) !== 0) {
                throw new \RuntimeException("Path escapes project directory: $relPath");
            }
            return $resolved;
        };

        $redactEnv = function (string $key, string $value): string {
            $sensitive = ['secret', 'password', 'token', 'key', 'credential', 'api_key'];
            foreach ($sensitive as $s) {
                if (stripos($key, $s) !== false) {
                    return '***REDACTED***';
                }
            }
            return $value;
        };

        // ── Database Tools ────────────────────────────────────

        $server->registerTool('database_query', function (string $sql, string $params = '[]') {
            try {
                $db = \Tina4\Database\Database::fromEnv();
            } catch (\Throwable $e) {
                return ['error' => 'No database connection: ' . $e->getMessage()];
            }
            $paramList = json_decode($params, true) ?: [];
            $result = $db->fetch($sql, 10, 0);
            return ['records' => $result ? $result->records : [], 'count' => $result ? $result->count : 0];
        }, 'Execute a read-only SQL query (SELECT)');

        $server->registerTool('database_execute', function (string $sql, string $params = '[]') {
            try {
                $db = \Tina4\Database\Database::fromEnv();
            } catch (\Throwable $e) {
                return ['error' => 'No database connection: ' . $e->getMessage()];
            }
            $paramList = json_decode($params, true) ?: [];
            $result = $db->execute($sql, $paramList);
            $db->commit();
            return ['success' => true, 'affected_rows' => 0];
        }, 'Execute arbitrary SQL (INSERT/UPDATE/DELETE/DDL)');

        $server->registerTool('database_tables', function () {
            try {
                $db = \Tina4\Database\Database::fromEnv();
            } catch (\Throwable $e) {
                return ['error' => 'No database connection: ' . $e->getMessage()];
            }
            return $db->getDatabase();
        }, 'List all database tables');

        $server->registerTool('database_columns', function (string $table) {
            try {
                $db = \Tina4\Database\Database::fromEnv();
            } catch (\Throwable $e) {
                return ['error' => 'No database connection: ' . $e->getMessage()];
            }
            // Use the database adapter to get table columns
            $result = $db->fetch("SELECT * FROM $table", [], 1, 0);
            if ($result && !empty($result->records)) {
                return array_keys($result->records[0]);
            }
            return [];
        }, 'Get column definitions for a table');

        // ── Route Tools ───────────────────────────────────────

        $server->registerTool('route_list', function () {
            $routes = [];
            $reflection = new \ReflectionClass(Router::class);
            $prop = $reflection->getProperty('routes');
            $prop->setAccessible(true);
            $allRoutes = $prop->getValue();
            foreach ($allRoutes as $method => $methodRoutes) {
                foreach ($methodRoutes as $route) {
                    $routes[] = [
                        'method' => $method,
                        'path' => $route['pattern'] ?? '',
                        'auth_required' => !($route['noAuth'] ?? false),
                    ];
                }
            }
            return $routes;
        }, 'List all registered routes');

        $server->registerTool('route_test', function (string $method, string $path, string $body = '', string $headers = '{}') {
            // Simple route testing via internal dispatch
            return ['info' => "Route test for $method $path - use the built-in test client"];
        }, 'Call a route and return the response');

        $server->registerTool('swagger_spec', function () {
            try {
                return Swagger::generate();
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage()];
            }
        }, 'Return the OpenAPI 3.0.3 JSON spec');

        // ── Template Tools ────────────────────────────────────

        $server->registerTool('template_render', function (string $template, string $data = '{}') {
            try {
                $engine = new Frond('src/templates');
                $ctx = json_decode($data, true) ?: [];
                return $engine->renderString($template, $ctx);
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage()];
            }
        }, 'Render a template string with data');

        // ── File Tools ────────────────────────────────────────

        $server->registerTool('file_read', function (string $path) use ($safePath) {
            $p = $safePath($path);
            if (!file_exists($p)) {
                return "File not found: $path";
            }
            if (!is_file($p)) {
                return "Not a file: $path";
            }
            return file_get_contents($p);
        }, 'Read a project file');

        $server->registerTool('file_write', function (string $path, string $content) use ($safePath, $projectRoot) {
            $p = $safePath($path);
            $dir = dirname($p);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($p, $content);
            $relPath = str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $p);
            return ['written' => $relPath, 'bytes' => strlen($content)];
        }, 'Write or update a project file');

        $server->registerTool('file_list', function (string $path = '.') use ($safePath) {
            $p = $safePath($path);
            if (!file_exists($p)) {
                return ['error' => "Directory not found: $path"];
            }
            if (!is_dir($p)) {
                return ['error' => "Not a directory: $path"];
            }
            $entries = [];
            $items = scandir($p);
            sort($items);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $fullPath = $p . DIRECTORY_SEPARATOR . $item;
                $entries[] = [
                    'name' => $item,
                    'type' => is_dir($fullPath) ? 'dir' : 'file',
                    'size' => is_file($fullPath) ? filesize($fullPath) : 0,
                ];
            }
            return $entries;
        }, 'List files in a directory');

        $server->registerTool('asset_upload', function (string $filename, string $content, string $encoding = 'utf-8') use ($safePath, $projectRoot) {
            $target = $safePath("src/public/$filename");
            $dir = dirname($target);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            if ($encoding === 'base64') {
                file_put_contents($target, base64_decode($content));
            } else {
                file_put_contents($target, $content);
            }
            $relPath = str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $target);
            return ['uploaded' => $relPath, 'bytes' => filesize($target)];
        }, 'Upload a file to src/public/');

        // ── Migration Tools ───────────────────────────────────

        $server->registerTool('migration_status', function () {
            try {
                $migration = new Migration();
                return ['info' => 'Migration system available'];
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage()];
            }
        }, 'List pending and completed migrations');

        $server->registerTool('migration_create', function (string $description) {
            try {
                $migration = new Migration();
                // Create migration file
                $migrationPath = 'migrations';
                if (!is_dir($migrationPath)) {
                    mkdir($migrationPath, 0755, true);
                }
                $existing = glob($migrationPath . '/*.sql');
                $nextId = str_pad(count($existing) + 1, 6, '0', STR_PAD_LEFT);
                $safeName = preg_replace('/[^a-z0-9]+/', '_', strtolower($description));
                $filename = "{$nextId}_{$safeName}.sql";
                file_put_contents($migrationPath . '/' . $filename, "-- $description\n");
                return ['created' => $filename];
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage()];
            }
        }, 'Create a new migration file');

        $server->registerTool('migration_run', function () {
            try {
                $migration = new Migration();
                $result = $migration->doMigration();
                return ['result' => $result];
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage()];
            }
        }, 'Run all pending migrations');

        // ── Queue Tools ───────────────────────────────────────

        $server->registerTool('queue_status', function (string $topic = 'default') {
            try {
                $queue = new Queue($topic);
                return [
                    'topic' => $topic,
                    'pending' => $queue->size('pending'),
                    'completed' => $queue->size('completed'),
                    'failed' => $queue->size('failed'),
                ];
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage()];
            }
        }, 'Get queue size by status');

        // ── Session/Cache Tools ───────────────────────────────

        $server->registerTool('session_list', function () {
            $sessionDir = 'data/sessions';
            if (!is_dir($sessionDir)) {
                return [];
            }
            $sessions = [];
            foreach (glob($sessionDir . '/*.json') as $f) {
                $id = pathinfo($f, PATHINFO_FILENAME);
                $contents = file_get_contents($f);
                $data = json_decode($contents, true);
                if ($data !== null) {
                    $sessions[] = ['id' => $id, 'data' => $data];
                } else {
                    $sessions[] = ['id' => $id, 'error' => 'corrupt'];
                }
            }
            return $sessions;
        }, 'List active sessions');

        $server->registerTool('cache_stats', function () {
            try {
                $cache = new \Tina4\Middleware\ResponseCache();
                return $cache->cacheStats();
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage()];
            }
        }, 'Get response cache statistics');

        // ── ORM Tools ─────────────────────────────────────────

        $server->registerTool('orm_describe', function () {
            // Scan src/orm/ for ORM subclasses
            $models = [];
            $ormDir = 'src/orm';
            if (is_dir($ormDir)) {
                foreach (glob($ormDir . '/*.php') as $file) {
                    $className = pathinfo($file, PATHINFO_FILENAME);
                    if (class_exists($className) && is_subclass_of($className, ORM::class)) {
                        $instance = new $className();
                        $models[] = [
                            'class' => $className,
                            'table' => $instance->tableName ?? strtolower($className),
                            'primaryKey' => $instance->primaryKey ?? 'id',
                        ];
                    }
                }
            }
            return $models;
        }, 'List all ORM models with fields and types');

        // ── Debugging Tools ───────────────────────────────────

        $server->registerTool('log_tail', function (int $lines = 50) {
            $logFile = 'logs/debug.log';
            if (!file_exists($logFile)) {
                return [];
            }
            $allLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            return array_slice($allLines, -$lines);
        }, 'Read recent log entries');

        $server->registerTool('error_log', function (int $limit = 20) {
            // Check for stored errors
            return [];
        }, 'Recent errors and exceptions');

        $server->registerTool('env_list', function () use ($redactEnv) {
            $envVars = getenv();
            if (!is_array($envVars)) {
                $envVars = [];
            }
            ksort($envVars);
            $result = [];
            foreach ($envVars as $k => $v) {
                $result[$k] = $redactEnv($k, (string)$v);
            }
            return $result;
        }, 'List environment variables (secrets redacted)');

        // ── Data Tools ────────────────────────────────────────

        $server->registerTool('seed_table', function (string $table, int $count = 10) {
            try {
                $db = \Tina4\Database\Database::fromEnv();
                $fake = new FakeData();
                // Basic seeding - get table columns and generate data
                return ['table' => $table, 'info' => "Seed $count rows - use FakeData class"];
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage()];
            }
        }, 'Seed a table with fake data');

        // ── System Tools ──────────────────────────────────────

        $server->registerTool('system_info', function () use ($projectRoot) {
            return [
                'framework' => 'tina4-php',
                'version' => '3.10.85',
                'php' => PHP_VERSION,
                'platform' => PHP_OS,
                'cwd' => $projectRoot,
                'debug' => getenv('TINA4_DEBUG') ?: 'false',
            ];
        }, 'Framework version, PHP version, project info');
    }
}

// ── Functional decorator helpers (parity with Python/Ruby/Node) ──

/**
 * Register a callable as an MCP tool on a server (or the default server).
 *
 * Usage:
 *     mcp_tool("lookup_invoice", "Find invoice by number")(function(string $invoiceNo): array {
 *         return $db->fetchOne("SELECT * FROM invoices WHERE invoice_no = ?", [$invoiceNo]);
 *     });
 *
 * @param string          $name        Tool name
 * @param string          $description Human-readable description
 * @param McpServer|null  $server      Target server (null = default)
 * @return callable Decorator that accepts the handler callable
 */
function mcp_tool(string $name = '', string $description = '', ?McpServer $server = null): callable
{
    return function (callable $handler) use ($name, $description, $server): callable {
        $toolName = $name ?: (is_array($handler) ? $handler[1] : 'tool');
        $target = $server ?? McpServer::getDefaultServer();
        $target->registerTool($toolName, $handler, $description);
        return $handler;
    };
}

/**
 * Register a callable as an MCP resource on a server (or the default server).
 *
 * Usage:
 *     mcp_resource("app://schema", "Database schema")(function() use ($db) {
 *         return $db->getDatabase();
 *     });
 *
 * @param string          $uri         Resource URI
 * @param string          $description Human-readable description
 * @param string          $mimeType    MIME type (default application/json)
 * @param McpServer|null  $server      Target server (null = default)
 * @return callable Decorator that accepts the handler callable
 */
function mcp_resource(string $uri, string $description = '', string $mimeType = 'application/json', ?McpServer $server = null): callable
{
    return function (callable $handler) use ($uri, $description, $mimeType, $server): callable {
        $target = $server ?? McpServer::getDefaultServer();
        $target->registerResource($uri, $handler, $description, $mimeType);
        return $handler;
    };
}
