# Tina4 PHP

Version 3.10.46 — Full Tina4 PHP framework and application scaffold. See https://tina4.com for full documentation.

## Build & Test

- PHP: >=8.2
- Install: `composer install`
- Run tests: `composer test` or `./vendor/bin/phpunit tests --verbose --color`
- Start server: `composer start` or `composer serve` (default host `0.0.0.0`, default port `7146`)
- CLI: `bin/tina4php`

## Code Principles

- **DRY** — Never duplicate logic. Centralise shared code in `Helpers/`, service classes, or Twig macros. If a pattern exists anywhere, use it everywhere
- **Separation of Concerns** — One route resource per file in `src/routes/`, one ORM model per file in `src/orm/`, services in `src/services/`, helpers in `Helpers/`
- **No inline styles** on any element — use tina4-css classes (e.g. `.form-input`, `.form-control`) or SCSS in `src/scss/`
- **No hardcoded hex colors** — always use CSS variables (`var(--text)`, `var(--border)`, `var(--primary)`, etc.) or SCSS variables
- **Shared CSS only** — Never define UI patterns in local `<style>` blocks. All shared styles go in a project SCSS file
- **Use built-in features** — Never reinvent what the framework provides (Auth, ORM, Messenger, etc.)
- **Template inheritance** — Every page extends a base Twig template, reusable UI in partials
- **Migrations for all schema changes** — Never execute DDL outside migration files
- **Constants** — No magic strings or numbers in routes. Use class constants or a dedicated constants file
- **Service layer pattern** — For complex business logic, create service classes in `src/services/`. Routes should be thin wrappers
- **Parity across all frameworks** — Every new feature, fix, or optimization must be implemented with equivalent logic AND tests in all 4 Tina4 frameworks (Python, PHP, Ruby, Node.js). Never ship to one without shipping to all.
- **Routes use `$response()`** — Return via the response callable, this is the Tina4 convention
- **Error handling in routes** — Wrap route logic in `try/catch`, log with `Debug::message()`, return response with appropriate status
- **All links and references** should point to https://tina4.com
- **Push to staging only** — Never push to production without explicit approval
- PSR-4 autoloading — core classes live in `Tina4/` (not `src/Tina4/`)
- Namespace `Tina4\` for core, `Tina4\Database\` for adapters, `Tina4\Middleware\` for middleware, `Tina4\Queue\` for queue backends, `Tina4\Session\` for session handlers
- Namespace `\` for src/app/orm/routes
- Linting: `composer lint` (phplint)

### Firebird-Specific Rules

When using Firebird as the database engine:

- **No triggers, no foreign keys** in migrations — use generators for auto-increment IDs
- **ID generation** — Use generators: `GEN_ID(GEN_FOO_ID, 1)` or `NEXT VALUE FOR GEN_FOO_ID`
- **Pagination** — Use `ROWS {skip+1} TO {skip+per_page}` syntax (not LIMIT/OFFSET)
- **BLOB handling** — `fetch()` auto-base64-encodes BLOB fields
- **No `TEXT` type** — Use `VARCHAR(n)` or `BLOB SUB_TYPE TEXT`
- **No `REAL`/`FLOAT`** — Use `DOUBLE PRECISION`
- **No `IF NOT EXISTS`** for `ALTER TABLE ADD` — framework checks `RDB$RELATION_FIELDS` automatically

## Development Mode

Set `TINA4_DEBUG=true` in `.env` to enable:

- **Dev toolbar** — A fixed toolbar at the bottom of HTML pages showing framework version, HTTP method, matched route pattern, request ID, route count, and PHP version. Includes a "Dashboard" link that opens `/__dev` in an inline panel
- **Dev dashboard** (`/__dev`) — Single-page admin UI with route inspection, message log viewer, request capture, database query runner (SQL and GraphQL), table browser, queue management, dev mailbox, broken-route detector, WebSocket monitor, AI chat tool, and connection manager
- **Twig debug extension** — `{{ dump(variable) }}` available in templates
- **Verbose logging** — Full debug output via `Debug::message()`
- **No template caching** — Templates recompile on every request
- **Error detail** — Full stack traces shown in browser (via `ErrorOverlay`)

Debug levels controlled by `TINA4_LOG_*` constants passed to `Debug::message()`.

## Project Structure

```
src/                     # User application code
  app/                   # Application logic
  orm/                   # ORM model definitions (one per model)
  routes/                # Auto-discovered route files (one per resource)
  templates/             # Twig templates (Frond engine)
  scss/                  # User SCSS
  services/              # Background services
  public/                # Static assets
  less/                  # LESS stylesheets
Tina4/                   # Core framework classes (namespace Tina4\)
  App.php                # Application bootstrap & built-in server
  Router.php             # Route dispatcher
  Request.php            # HTTP request wrapper
  Response.php           # HTTP response builder (with template() method)
  Frond.php              # Built-in Twig-compatible template engine
  Events.php             # Event/observer system
  AI.php                 # AI coding assistant detection & context scaffolding
  Container.php          # Lightweight DI container
  DevAdmin.php           # Dev dashboard & toolbar (/__dev)
  DevMailbox.php         # Dev mailbox for captured emails
  ErrorOverlay.php       # Rich HTML error overlay for dev mode
  FakeData.php           # Test data generator
  GraphQL.php            # GraphQL query executor
  HtmlElement.php        # Programmatic HTML builder
  I18n.php               # Internationalisation
  Messenger.php          # Messaging abstraction
  ORM.php                # Active Record ORM
  Queue.php              # Job queue
  Session.php            # Session management
  SqlTranslation.php     # SQL dialect translation layer
  Swagger.php            # OpenAPI spec generator
  Testing.php            # Inline testing framework
  WebSocket.php          # WebSocket support
  DatabaseUrl.php        # Connection URL parser
  Database/              # Database adapters (namespace Tina4\Database\)
    DatabaseAdapter.php  # Adapter interface
    Database.php         # Factory for creating adapters from URL
    SQLite3Adapter.php   # SQLite3 (ext-sqlite3)
    PostgresAdapter.php  # PostgreSQL (ext-pdo)
    MySQLAdapter.php     # MySQL (ext-pdo)
    MSSQLAdapter.php     # SQL Server (ext-pdo)
    FirebirdAdapter.php  # Firebird (ext-interbase/ext-pdo)
  Middleware/
    ResponseCache.php    # GET response caching middleware
    CorsMiddleware.php   # CORS handling
    RateLimiter.php      # Rate limiting
  Queue/
    QueueBackend.php     # Queue backend interface
    KafkaBackend.php     # Kafka queue backend
    RabbitMQBackend.php  # RabbitMQ queue backend
  Session/
    MongoSessionHandler.php  # MongoDB session handler
    ValkeySessionHandler.php # Valkey/Redis session handler
migrations/              # Database migration SQL files
tests/                   # PHPUnit tests
bin/
  tina4php               # CLI tool
dockers/                 # Docker configurations
```

## Key Method Stubs

### Route — Static route registration

```php
Router::get(string $routePath, $function): Router
Router::post(string $routePath, $function): Router
Router::put(string $routePath, $function): Router
Router::patch(string $routePath, $function): Router
Router::delete(string $routePath, $function): Router
Router::any(string $routePath, $function): Router

// Modifiers (chained)
->middleware(array $functionNames): Router
->cache(bool $default = true): Router
->noCache(bool $default = false): Router
->secure(bool $default = true): Router
```

### Database — Database connection (v3)

v3 uses `Database::create()` with standardised URL connection strings. Old aliases (`pgsql`, `mariadb`, `fdb`, `sqlite3`, `sqlsrv`) are removed.

Supported schemes: `sqlite`, `postgres`, `postgresql`, `mysql`, `mssql`, `sqlserver`, `firebird`

```php
use Tina4\Database\Database;

// Create from URL — driver://host:port/database
$db = Database::create('sqlite:///path/to/app.db');
$db = Database::create('sqlite::memory:');
$db = Database::create('postgres://localhost:5432/mydb', username: 'user', password: 'pass');
$db = Database::create('mysql://localhost:3306/mydb', username: 'root', password: 'secret');
$db = Database::create('mssql://localhost:1433/mydb', username: 'sa', password: 'pass');
$db = Database::create('sqlserver://localhost:1433/mydb', username: 'sa', password: 'pass');
$db = Database::create('firebird://localhost:3050/path/to/db.fdb', username: 'SYSDBA', password: 'masterkey');

// Create from DATABASE_URL env var (also reads DATABASE_USERNAME, DATABASE_PASSWORD)
$db = Database::fromEnv();
$db = Database::fromEnv('CUSTOM_DB_URL');

// Auto-commit control — defaults to off (safe). Override per-connection or via env:
$db = Database::create($url, autoCommit: true);
// Or set TINA4_AUTOCOMMIT=true in .env to enable globally

// Adapter methods (all adapters implement DatabaseAdapter)
$db->fetch($sql, $limit, $offset): ?DataResult
$db->execute($sql, $params)
$db->exec($sql, $params)           // alias for execute()
$db->startTransaction()
$db->commit($transactionId = null)
$db->rollback($transactionId = null)
$db->autoCommit(bool $onState = true): void
$db->tableExists(string $tableName): bool
$db->getDatabase(): array
$db->getLastId(): string
$db->getNextId(string $table, string $pkColumn = 'id', ?string $generatorName = null): int
    // Race-safe ID generation using atomic sequence table (tina4_sequences).
    // SQLite/MySQL/MSSQL: uses tina4_sequences table with atomic UPDATE+SELECT.
    // PostgreSQL: auto-creates a sequence if missing, uses nextval().
    // Firebird: uses existing generator (unchanged).
$db->error()
```

**`tina4_sequences` table** — Auto-created by `getNextId()` on first use for SQLite, MySQL, and MSSQL. Stores the current sequence value per table. Do not modify this table manually.

#### DatabaseUrl — Connection URL parser

```php
use Tina4\DatabaseUrl;

$url = new DatabaseUrl('postgres://user:pass@host:5432/mydb');
$url->scheme;    // 'postgres'
$url->host;      // 'host'
$url->port;      // 5432
$url->database;  // 'mydb'
$url->username;  // 'user'
$url->password;  // 'pass'
$url->getDsn();  // 'host:5432/mydb'

// From env
$url = DatabaseUrl::fromEnv('DATABASE_URL');
```

### ORM — Active Record

```php
class User extends \Tina4\ORM {
    public $tableName = "users";
    public $primaryKey = "id";
    public $fieldMapping = [];     // ["db_column" => "phpProperty"]
    public $hasOne = [];           // Related single records
    public $hasMany = [];          // Related collections
    public $softDelete = false;
    public $tableFilter = "";      // Default WHERE clause
}

$user = new User($request);
$user->save();
$user->load();
$user->delete();
$user->selectOne(string $sql, array $params = [], ?array $include = null): ?static  // Raw SQL, returns first match or null
```

NoSQL support: `toMongo()` generates MongoDB query documents from the same fluent API.

### Response — Template rendering

The `Response` object supports rendering Twig templates via the built-in `Frond` engine:

```php
Router::get("/dashboard", function (Request $request, Response $response) {
    return $response->template("dashboard.twig", [
        "title" => "Dashboard",
        "user" => $currentUser,
    ]);
});

// With custom status code and template directory
$response->template("error.twig", ["code" => 404], 404, 'src/templates');
```

The `template()` method uses `Frond` (built-in Twig-compatible engine, zero dependencies) and defaults to looking in `src/templates/`.

### Auth — JWT authentication

```php
$auth = new \Tina4\Auth();
Auth::getToken(array $payload, string $secret, int $expiresIn = 3600, string $algorithm = 'HS256'): string
Auth::validToken(string $token, string $secret, string $algorithm = 'HS256'): ?array
Auth::getPayload(string $token): ?array
```

### Api — External HTTP client

```php
$api = new \Tina4\Api(?string $baseURL = "", string $authHeader = "")
$api->sendRequest(string $restService = "", string $requestType = "GET", $body = null, string $contentType = "application/json"): array
$api->addCustomHeaders($headers): void
$api->setUsernamePassword($username, $password): void
```

### Migration — Database migrations

```php
$migration = new \Tina4\Migration(string $migrationPath = "./migrations", string $delim = ";")
$migration->doMigration(): string
```

### Debug — Logging

```php
\Tina4\Debug::message(string $message, string $logLevel = TINA4_LOG_DEBUG)
// Log levels: TINA4_LOG_DEBUG, TINA4_LOG_INFO, TINA4_LOG_WARNING, TINA4_LOG_ERROR, TINA4_LOG_CRITICAL
```

### Events — Decoupled pub/sub event system

```php
// Register a listener (higher priority runs first)
Events::on(string $event, callable $callback, int $priority = 0): void

// Register a one-time listener (auto-removed after first fire)
Events::once(string $event, callable $callback, int $priority = 0): void

// Emit an event — returns list of listener return values
Events::emit(string $event, mixed ...$args): array

// Remove a specific listener, or all listeners for an event
Events::off(string $event, ?callable $callback = null): void

// Remove all listeners for all events
Events::clear(): void

// Get callbacks registered for an event
Events::listeners(string $event): array

// Get all event names that have listeners
Events::events(): array
```

Example:
```php
Events::on('user.created', fn($user) => sendWelcomeEmail($user), priority: 10);
Events::once('app.boot', fn() => warmCaches());
$results = Events::emit('user.created', $userData);
```

### AI — Detect AI coding assistants and scaffold context files

Supports: Claude Code, Cursor, GitHub Copilot, Windsurf, Aider, Cline, OpenAI Codex.

```php
// Detect which AI tools are present in a project
AI::detectAi(string $root = "."): array
// Returns: [['name' => 'claude-code', 'description' => '...', 'installed' => true], ...]

// Return just the names of detected tools
AI::detectAiNames(string $root = "."): string[]

// Install Tina4 context files for detected (or specified) tools
AI::installContext(string $root = ".", ?array $tools = null, bool $force = false): string[]

// Install context files for ALL known AI tools
AI::installAll(string $root = ".", bool $force = false): string[]

// Print a human-readable status report
AI::statusReport(string $root = "."): string
```

Example:
```php
// Auto-detect and install context files
$created = AI::installContext('.', null, force: true);

// Install for all supported tools
$created = AI::installAll('.', force: true);

// Check status
echo AI::statusReport('.');
```

### Response Cache — In-memory GET response caching middleware

Caches GET responses with TTL. Controlled via env vars or constructor config.

| Env Variable | Default | Description |
|---|---|---|
| `TINA4_CACHE_TTL` | 60 | Default TTL in seconds (0 = disabled) |
| `TINA4_CACHE_MAX_ENTRIES` | 1000 | Maximum cache entries |

```php
use Tina4\Middleware\ResponseCache;

$cache = new ResponseCache(['ttl' => 120, 'maxEntries' => 500, 'statusCodes' => [200]]);

// Lookup a cached response (returns null on miss)
$cache->lookup(string $method, string $url): ?array

// Store a response
$cache->store(string $method, string $url, string $body, string $contentType, int $statusCode): void

// Cache stats and maintenance
$cache->cacheStats(): array    // ['size' => int, 'keys' => string[]]
$cache->clearCache(): void
$cache->sweep(): int           // Remove expired entries, returns count removed
```

Example with route middleware:
```php
$cache = new ResponseCache(['ttl' => 300]);
Router::get("/api/data", function($request, $response) use ($cache) {
    $hit = $cache->lookup('GET', $request->url);
    if ($hit) return $response($hit['body'], $hit['statusCode']);
    $data = expensiveQuery();
    $body = json_encode($data);
    $cache->store('GET', $request->url, $body, 'application/json', 200);
    return $response($data);
});
```

### Container — Lightweight dependency injection container

```php
$container = new \Tina4\Container();

// Register a factory (new instance each call)
$container->register(string $name, callable $factory): void

// Register a singleton (created once, cached)
$container->singleton(string $name, callable $factory): void

// Resolve a service (throws RuntimeException if not registered)
$container->get(string $name): mixed

// Check if a service is registered
$container->has(string $name): bool

// Clear all registrations
$container->reset(): void
```

Example:
```php
$container = new \Tina4\Container();
$container->singleton('db', fn() => \Tina4\Database\Database::create(getenv('DB_URL')));
$container->register('mailer', fn() => new MailService());
$db = $container->get('db');       // same instance every time
$mailer = $container->get('mailer'); // new instance each time
```

### ErrorOverlay — Rich HTML error page for development

Renders syntax-highlighted stack traces with source context, request details, and environment info. Activated when `TINA4_DEBUG` is `true`.

```php
// Render a full HTML error overlay (dev mode)
ErrorOverlay::render(\Throwable $e, ?array $request = null): string

// Render a safe, generic error page (production)
ErrorOverlay::renderProduction(int $statusCode = 500, string $message = 'Internal Server Error'): string

// Check if TINA4_DEBUG is enabled
ErrorOverlay::isDebugMode(): bool
```

Example:
```php
try {
    $handler($request, $response);
} catch (\Throwable $e) {
    if (ErrorOverlay::isDebugMode()) {
        echo ErrorOverlay::render($e, $_SERVER);
    } else {
        echo ErrorOverlay::renderProduction(500);
    }
}
```

### HtmlElement — Programmatic HTML builder

Build HTML without string concatenation. Supports void tags, attribute merging, and nested children.

```php
// Constructor
$el = new HtmlElement(string $tag, array $attrs = [], array $children = []);

// Render to HTML
(string) $el;  // __toString()

// Builder pattern — append children or merge attrs via __invoke
$el = (new HtmlElement("div"))(new HtmlElement("p"))("Hello");

// Get helper closures for all HTML tags ($_div, $_p, $_span, etc.)
$helpers = HtmlElement::helpers(): array
```

Example:
```php
// Direct construction
$card = new HtmlElement("div", ["class" => "card"], [
    new HtmlElement("h2", [], ["Title"]),
    new HtmlElement("p", [], ["Content"]),
]);
echo $card;

// Using helpers
extract(HtmlElement::helpers());
echo $_div(["class" => "card"],
    $_h2("Title"),
    $_p("Content")
);
```

### Testing — Inline test assertions

Attach test assertions directly to functions and run them all at once.

```php
// Register a function with test assertions
Testing::tests(array $assertions, callable $fn, string $name = 'anonymous'): void

// Assertion builders
Testing::assertEqual(array $args, mixed $expected): array
Testing::assertRaises(string $exceptionClass, array $args): array
Testing::assertTrue(array $args): array
Testing::assertFalse(array $args): array

// Run all registered tests
Testing::runAll(bool $quiet = false, bool $failfast = false): array
// Returns: ['passed' => int, 'failed' => int, 'errors' => int, 'details' => array]

// Reset the test registry
Testing::reset(): void
```

Example:
```php
Testing::tests(
    [
        Testing::assertEqual([5, 3], 8),
        Testing::assertEqual([0, 0], 0),
        Testing::assertRaises('InvalidArgumentException', [null]),
    ],
    function ($a, $b = null) {
        if ($b === null) throw new \InvalidArgumentException("b required");
        return $a + $b;
    },
    'add'
);

$results = Testing::runAll();
// Output:  add
//   + add([5,3]) == 8
//   + add([0,0]) == 0
//   + add([null]) raises InvalidArgumentException
```

### SqlTranslation — Cross-database SQL dialect translation

Translates portable SQL into dialect-specific syntax. Supports Firebird, MSSQL, MySQL, PostgreSQL, and SQLite.

```php
// Full dialect translation (applies all relevant rules)
SqlTranslation::translate(string $sql, string $dialect): string

// Individual translations
SqlTranslation::limitToRows(string $sql): string          // LIMIT/OFFSET -> ROWS X TO Y (Firebird)
SqlTranslation::limitToTop(string $sql): string           // LIMIT -> TOP N (MSSQL)
SqlTranslation::booleanToInt(string $sql): string         // TRUE/FALSE -> 1/0
SqlTranslation::ilikeToLike(string $sql): string          // ILIKE -> LOWER() LIKE LOWER()
SqlTranslation::concatPipesToFunc(string $sql): string    // || -> CONCAT()
SqlTranslation::autoIncrementSyntax(string $sql, string $dialect): string
SqlTranslation::placeholderStyle(string $sql, string $style): string  // ? -> :1,:2 or %s
SqlTranslation::hasReturning(string $sql): bool
SqlTranslation::extractReturning(string $sql): array      // ['sql' => ..., 'columns' => [...]]

// Custom function mapping
SqlTranslation::registerFunction(string $name, callable $mapper): void
SqlTranslation::applyFunctionMappings(string $sql): string
SqlTranslation::clearFunctions(): void

// Query result caching
SqlTranslation::setCacheTtl(int $seconds): void
SqlTranslation::queryKey(string $sql, array $params = []): string
SqlTranslation::cacheGet(string $key): mixed
SqlTranslation::cacheSet(string $key, mixed $value, int $ttl = 0): void
SqlTranslation::remember(string $key, int $ttl, callable $factory): mixed
SqlTranslation::cacheSweep(): int
SqlTranslation::cacheClear(): void
SqlTranslation::cacheSize(): int
```

Example:
```php
// Translate for Firebird (LIMIT->ROWS, TRUE->1, ILIKE->LOWER LIKE)
$sql = SqlTranslation::translate(
    "SELECT * FROM users WHERE active = TRUE AND name ILIKE '%alice%' LIMIT 10 OFFSET 5",
    'firebird'
);
// => SELECT * FROM users WHERE active = 1 AND LOWER(name) LIKE LOWER('%alice%') ROWS 6 TO 15

// Register a custom function mapping
SqlTranslation::registerFunction('NOW', fn($sql) => str_ireplace('NOW()', 'CURRENT_TIMESTAMP', $sql));

// Cache expensive query results
$result = SqlTranslation::remember(
    SqlTranslation::queryKey("SELECT * FROM stats", []),
    300,
    fn() => $db->fetch("SELECT * FROM stats")
);
```

## Key Architecture

- **Zero external dependencies** — v3 has no Composer runtime dependencies (only `ext-openssl` and `ext-json`). Database extensions are optional and suggested
- **Unified framework** — Everything lives in the `tina4stack/tina4php` package. No separate `tina4php-core`, `tina4php-database`, `tina4php-orm` packages
- **Default server** — Binds to `0.0.0.0:7146` by default
- Routes auto-discovered from `src/routes/`
- ORM with migration support built in
- Twig-compatible templating via built-in `Frond` engine (zero deps)
- SCSS/LESS compilation via built-in `ScssCompiler`
- JWT auth built in
- Event system (`Events`) for decoupled pub/sub communication
- DI container (`Container`) with factory and singleton registration
- Response cache middleware (`ResponseCache`) for in-memory GET caching with TTL
- CORS middleware (`CorsMiddleware`) and rate limiter (`RateLimiter`)
- Error overlay (`ErrorOverlay`) with syntax-highlighted stack traces in dev mode
- Dev dashboard (`DevAdmin`) with route/request/query/queue/mailbox/WebSocket inspection
- Programmatic HTML builder (`HtmlElement`) with tag helpers
- Inline testing (`Testing`) with assertion builders and test runner
- SQL dialect translation (`SqlTranslation`) for cross-database portability
- AI assistant detection (`AI`) with context file scaffolding for 7 tools
- Queue system with Kafka, RabbitMQ, and MongoDB backends
- Session handlers for MongoDB and Valkey/Redis. `TINA4_SESSION_SAMESITE` env var (default: Lax)
- GraphQL query execution
- WebSocket support. WebSocket backplane for scaling broadcast across instances via Redis pub/sub (`TINA4_WS_BACKPLANE`, `TINA4_WS_BACKPLANE_URL` env vars)
- Swagger/OpenAPI spec generation
- Internationalisation (`I18n`)
- Messenger (.env driven SMTP/IMAP)
- CLI scaffolding: `composer tina4 generate model/route/migration/middleware`
- Production server: `composer start --production` (OPcache auto-config)
- Frond pre-compilation for 2.8x template render improvement
- DB query caching: `TINA4_DB_CACHE=true` env var, `cache_stats()`, `cache_clear()`
- ORM relationships: `hasMany`, `hasOne`, `belongsTo` with eager loading (`include:`)
- Queue backends: file (default), RabbitMQ, Kafka, MongoDB
- Cache backends: memory (default), Redis, file
- Session handlers: file, Redis/Valkey, MongoDB, database. `TINA4_SESSION_SAMESITE` env var controls SameSite attribute (default: Lax)
- QueryBuilder with NoSQL/MongoDB support (`toMongo()`)
- WebSocket backplane (Redis pub/sub) for horizontal scaling
- SameSite=Lax default on session cookies (`TINA4_SESSION_SAMESITE`)
- `tina4 init` generates Dockerfile and .dockerignore
- Gallery: 7 interactive examples with Try It deploy at `/__dev/`
- Race-safe `getNextId()` with atomic sequence table (`tina4_sequences`) for SQLite/MySQL/MSSQL; PostgreSQL auto-creates sequences
- Frond template engine optimizations: pre-compiled regexes, lazy loop context (copy-on-write), filter chain caching, path split caching, inline common filters (11-15% speedup)
- Tests: 1,427 passing

## Links

- Website: https://tina4.com
- GitHub: https://github.com/tina4stack/tina4-php

## Tina4 Maintainer Skill
Always read and follow the instructions in .claude/skills/tina4-maintainer/SKILL.md when working on this codebase. Read its referenced files in .claude/skills/tina4-maintainer/references/ as needed for specific subsystems.

## Tina4 Developer Skill
Always read and follow the instructions in .claude/skills/tina4-developer/SKILL.md when building applications with this framework. Read its referenced files in .claude/skills/tina4-developer/references/ as needed.

## Tina4-js Frontend Skill
Always read and follow the instructions in .claude/skills/tina4-js/SKILL.md when working with tina4-js frontend code. Read its referenced files in .claude/skills/tina4-js/references/ as needed.
