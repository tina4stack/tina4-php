# Tina4 PHP

Full Tina4 PHP framework and application scaffold. See https://tina4.com for full documentation.

## Build & Test

- PHP: >=8.0
- Install: `composer install`
- Run tests: `composer run-tests` or `./vendor/bin/phpunit tests --verbose --color`
- Start server: `composer start` (runs `tina4php webservice:run`)
- Debug server: `composer debug` (Xdebug enabled)
- Initialize project: `composer initialize`
- CLI: `composer tina4php` or `bin/tina4php`

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
- **Routes use `$response()`** — Return via the response callable, this is the Tina4 convention
- **Error handling in routes** — Wrap route logic in `try/catch`, log with `Debug::message()`, return response with appropriate status
- **All links and references** should point to https://tina4.com
- **Push to staging only** — Never push to production without explicit approval
- PSR-4 autoloading
- Namespace `Tina4\` for core, `\` for src/app/orm/routes
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

- **Twig debug extension** — `{{ dump(variable) }}` available in templates
- **Verbose logging** — Full debug output via `Debug::message()`
- **No template caching** — Templates recompile on every request
- **Error detail** — Full stack traces shown in browser

Debug levels controlled by `TINA4_LOG_*` constants passed to `Debug::message()`.

## Project Structure

```
src/                     # User application code
  app/                   # Application logic
  orm/                   # ORM model definitions (one per model)
  routes/                # Auto-discovered route files (one per resource)
  templates/             # Twig templates
  scss/                  # User SCSS
  services/              # Background services
  public/                # Static assets
  less/                  # LESS stylesheets
  Tina4/                 # Core framework classes
    Events.php           # Event/observer system
    AI.php               # AI coding assistant detection & context scaffolding
    Container.php        # Lightweight DI container
    ErrorOverlay.php     # Rich HTML error overlay for dev mode
    HtmlElement.php      # Programmatic HTML builder
    Testing.php          # Inline testing framework
    SqlTranslation.php   # SQL dialect translation layer
    Middleware/
      ResponseCache.php  # GET response caching middleware
migrations/              # Database migration SQL files
tests/                   # PHPUnit tests (Router, Response, Routing)
bin/
  tina4php               # CLI tool
dockers/                 # Docker configurations
```

## Key Method Stubs

### Route — Static route registration

```php
Route::get(string $routePath, $function): Route
Route::post(string $routePath, $function): Route
Route::put(string $routePath, $function): Route
Route::patch(string $routePath, $function): Route
Route::delete(string $routePath, $function): Route
Route::any(string $routePath, $function): Route

// Modifiers (chained)
->middleware(array $functionNames): Route
->cache(bool $default = true): Route
->noCache(bool $default = false): Route
->secure(bool $default = true): Route
```

### DataBase — Database abstraction

```php
$db = new \Tina4\DataBase(string $database, string $username = "", string $password = "", string $dateFormat = "Y-m-d", string $charset = "utf8")

$db->fetch($sql, int $noOfRecords = 10, int $offSet = 0, array $fieldMapping = []): ?DataResult
$db->exec()
$db->startTransaction()
$db->commit($transactionId = null)
$db->rollback($transactionId = null)
$db->autoCommit(bool $onState = true): void
$db->tableExists(string $tableName): bool
$db->getDatabase(): array
$db->getLastId(): string
$db->error()
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
```

### Auth — JWT authentication

```php
$auth = new \Tina4\Auth();
$auth->getToken(array $payLoad = [], int $expiresInDays = 0): string
$auth->validToken(string $token): bool
$auth->getPayLoad($token): ?array
$auth->generateSecureKeys(): bool
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
Route::get("/api/data", function($request, $response) use ($cache) {
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
$container->singleton('db', fn() => new \Tina4\DataBase(getenv('DB_URL')));
$container->register('mailer', fn() => new MailService());
$db = $container->get('db');       // same instance every time
$mailer = $container->get('mailer'); // new instance each time
```

### ErrorOverlay — Rich HTML error page for development

Renders syntax-highlighted stack traces with source context, request details, and environment info. Activated when `TINA4_DEBUG_LEVEL` is `ALL` or `DEBUG`.

```php
// Render a full HTML error overlay (dev mode)
ErrorOverlay::render(\Throwable $e, ?array $request = null): string

// Render a safe, generic error page (production)
ErrorOverlay::renderProduction(int $statusCode = 500, string $message = 'Internal Server Error'): string

// Check if current TINA4_DEBUG_LEVEL enables the overlay
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

- Routes auto-discovered from `src/routes/`
- ORM with migration support via `tina4stack/tina4php-orm`
- Twig templating with custom extensions
- SCSS/LESS compilation via `scssphp`
- JWT auth via `nowakowskir/php-jwt`
- Caching via `phpfastcache`
- Git integration via `coyl/git`
- Depends on: `tina4php-core`, `tina4php-debug`, `tina4php-shape`, `tina4php-env`, `tina4php-database`, `tina4php-orm`
- Dev database: `tina4stack/tina4php-sqlite3`
- Swoole support: `swoole-http.php`, `swoole-tcp.php`
- Event system (`Events`) for decoupled pub/sub communication
- DI container (`Container`) with factory and singleton registration
- Response cache middleware (`ResponseCache`) for in-memory GET caching with TTL
- Error overlay (`ErrorOverlay`) with syntax-highlighted stack traces in dev mode
- Programmatic HTML builder (`HtmlElement`) with tag helpers
- Inline testing (`Testing`) with assertion builders and test runner
- SQL dialect translation (`SqlTranslation`) for cross-database portability
- AI assistant detection (`AI`) with context file scaffolding for 7 tools

## Links

- Website: https://tina4.com
- GitHub: https://github.com/tina4stack/tina4-php
