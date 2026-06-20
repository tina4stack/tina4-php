# Tina4 PHP

Version 3.13.37 — Full Tina4 PHP framework and application scaffold. See https://tina4.com for full documentation.

## Build & Test

- PHP: >=8.2
- Install: `composer install`
- Run tests: `composer test` or `./vendor/bin/phpunit tests --verbose --color`
- Start server: `composer start` or `composer serve` (default host `0.0.0.0`, default port `7145`)
- CLI: `bin/tina4php`

## Code Principles

- **DRY** — Never duplicate logic. Centralise shared code in `Helpers/`, service classes, or Twig macros. If a pattern exists anywhere, use it everywhere
- **Separation of Concerns** — One route resource per file in `src/routes/`, one ORM model per file in `src/orm/`, services in `src/services/`, helpers in `Helpers/`
- **No inline styles** on any element — use tina4-css classes (e.g. `.form-input`, `.form-control`) or SCSS in `src/scss/`
- **No hardcoded hex colors** — always use CSS variables (`var(--text)`, `var(--border)`, `var(--primary)`, etc.) or SCSS variables
- **Shared CSS only** — Never define UI patterns in local `<style>` blocks. All shared styles go in a project SCSS file
- **Use built-in features** — Never reinvent what the framework provides (Auth, ORM, Messenger, etc.)
- **Background work** — Use `$app->background(fn, interval)` for periodic tasks (queue consumers, health checks, simulators). Never use raw threads or separate worker processes
- **Template inheritance** — Every page extends a base Twig template, reusable UI in partials
- **Migrations for all schema changes** — Never execute DDL outside migration files
- **Constants** — No magic strings or numbers in routes. Use class constants or a dedicated constants file
- **Service layer pattern** — For complex business logic, create service classes in `src/services/`. Routes should be thin wrappers
- **Parity across all frameworks** — Every new feature, fix, or optimization must be implemented with equivalent logic AND tests in all 4 Tina4 frameworks (Python, PHP, Ruby, Node.js). Never ship to one without shipping to all.
- **Routes use `$response()`** — Return via the response callable, this is the Tina4 convention
- **Error handling in routes** — Wrap route logic in `try/catch`, log with `Log::error()` / `Log::warning()` / `Log::info()` / `Log::debug()` (or alias `Debug` if you have a backwards-compat shim), return response with appropriate status
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
- **BLOB handling** — `fetch()` auto-reads BLOB resource handles into byte strings (Firebird) and unescapes bytea hex encoding (PostgreSQL). Values are raw bytes, not base64
- **No `TEXT` type** — Use `VARCHAR(n)` or `BLOB SUB_TYPE TEXT`
- **No `REAL`/`FLOAT`** — Use `DOUBLE PRECISION`
- **No `IF NOT EXISTS`** for `ALTER TABLE ADD` — framework checks `RDB$RELATION_FIELDS` automatically

## Development Mode

Set `TINA4_DEBUG=true` in `.env` to enable:

- **Dev toolbar** — A fixed toolbar at the bottom of HTML pages showing framework version, HTTP method, matched route pattern, request ID, route count, and PHP version. Includes a "Dashboard" link that opens `/__dev` in an inline panel
- **Dev dashboard** (`/__dev`) — Single-page admin UI with route inspection, message log viewer, request capture, database query runner (SQL and GraphQL), table browser, queue management, dev mailbox, broken-route detector, WebSocket monitor, AI chat tool, and connection manager
- **Twig debug extension** — `{{ dump(variable) }}` available in templates
- **Verbose logging** — Full debug output via `Log::debug()` / `info()` / `warning()` / `error()`
- **No template caching** — Templates recompile on every request
- **Error detail** — Full stack traces shown in browser (via `ErrorOverlay`)

Log level configured via `TINA4_LOG_LEVEL` env var (DEBUG / INFO / WARNING / ERROR). Each `Log::*` static method routes through the same backend with the corresponding level.

### DevReload — how hot reload works

The `tina4` Rust CLI is the sole file watcher for the Tina4 stack — PHP has no internal watcher. The flow is:

1. `tina4 serve` watches `src/`, `migrations/`, `.env`. Noise is filtered (Access/Metadata events, `__pycache__`, `.git`, `node_modules`, `vendor`, `logs`, `.log`/`.db*`/`.swp` files) and a real mtime check defeats overlayfs spurious events.
2. On a real change, the CLI POSTs `/__dev/api/reload` to the running PHP server.
3. `DevAdmin` bumps its in-memory `$reloadMtime` counter, calls `opcache_invalidate()` on the changed `.php` file, and re-discovers routes via `RouteDiscovery::rescan()` — all **in-process**, so the worker keeps the same PID (no respawn). It then **broadcasts** a JSON message `{type, file, mtime}` directly to every browser on the `/__dev_reload` WebSocket via `Server::getInstance()->broadcastWebSocket(...)`. The wire `type` is normalised to `"css"` (for `.css`/`.scss`) or `"reload"` otherwise; the broadcast is wrapped in `try/catch` so a failure (or zero clients) never breaks the endpoint. `GET /__dev/api/mtime` still returns the counter for the polling fallback, and `$pendingReload` remains a belt-and-braces idle-tick signal (cleared once the in-request broadcast runs, so the server never double-fires).
4. The PHP server's injected reload client is **WebSocket-primary**: it opens `/__dev_reload` (ws/wss by page protocol) and acts on a `{type:reload|change|css}` message instantly — CSS swaps `<link rel=stylesheet>` hrefs with a cache-bust query, everything else does a full `location.reload()`. It **stops** the `/__dev/api/mtime` poll the moment the socket connects and only **starts** it (every 3 s) when the socket drops, reconnecting after ~2 s. So there is no polling in normal operation. The poll fallback uses a null mtime sentinel (not `0`) and reloads when the polled mtime **differs** (not just when greater), so the first change after load isn't swallowed and a counter reset on restart still triggers. The `/__dev_reload` route is registered debug-only in `Server::start()` (skipped on the AI/stable port, where the reload client is also suppressed).

No file-based sentinel is used — everything is in-memory. Code changes re-import in-process (no respawn). This matches the Python/Ruby/Node implementations.

**Route hot-reload (no restart).** `RouteDiscovery` is mtime-tracked: on each `rescan()` it re-reads file mtimes (`clearstatcache()` first) and re-loads a route file when it is **new** or its **mtime increased**, skipping unchanged files. Editing an existing route file therefore takes effect without a server restart — `Router` registers `(method, path)` with **replace-in-place** semantics (latest wins), so the re-loaded handler replaces the stale one instead of being shadowed by an appended duplicate. The reload path only ever touches files **under the discovered `src/routes/` directory** — framework and `vendor` files are never re-included.

Caveat — convention route files (`get.php`/`post.php`/… that `return` a closure) hot-reload cleanly. Inline files made purely of `Router::get/post/...` calls also hot-reload. But an inline file that declares **top-level functions, classes, traits, interfaces, enums, or constants** cannot be safely re-included (PHP would fatal with "cannot redeclare"); the reload path detects this, logs a warning, and leaves the old handler in place. Edit such a file and you must **restart the server** for its route changes to apply. Prefer the one-verb-per-file convention or keep inline route files declaration-free to get hot reload.

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
  Response.php           # HTTP response builder (with render() method)
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
->cache(): Router
->noCache(): Router
->secure(): Router
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

// Create from TINA4_DATABASE_URL env var (also reads TINA4_DATABASE_USERNAME, TINA4_DATABASE_PASSWORD)
$db = Database::fromEnv();
$db = Database::fromEnv('CUSTOM_DB_URL');

// Auto-commit control — defaults to ON: a standalone write commits on its own
// connection (durable + visible across a pool); explicit transactions
// (startTransaction/commit/rollback) stay atomic. Override per-connection or
// set TINA4_AUTOCOMMIT=false in .env for strict manual-commit mode:
$db = Database::create($url, autoCommit: false);

// Adapter methods (all adapters implement DatabaseAdapter)
$db->fetch(string $sql, array $params = [], int $limit = 100, int $offset = 0): DatabaseResult
$db->execute($sql, $params)        // FAIL LOUD — RAISES on SQL error (never returns false); cause on getError(). try/catch it.
$db->exec($sql, $params)           // alias for execute() — same raise-on-error contract
$db->startTransaction()
$db->commit(): void
$db->rollback(): void
$db->tableExists(string $tableName): bool
$db->getLastId(): string
$db->getNextId(string $table, string $pkColumn = 'id', ?string $generatorName = null): int
    // Race-safe ID generation using atomic sequence table (tina4_sequences).
    // SQLite/MySQL/MSSQL: uses tina4_sequences table with atomic UPDATE+SELECT.
    // PostgreSQL: auto-creates a sequence if missing, uses nextval().
    // Firebird: uses existing generator (unchanged).
$db->error()
```

**`tina4_sequences` table** — Auto-created by `getNextId()` on first use for SQLite, MySQL, and MSSQL. Stores the current sequence value per table. Do not modify this table manually.

#### Binding the ORM to a database

ORM models resolve their connection through `\Tina4\ORM::bindDatabase()`. There are three ways to provide one:

```php
use Tina4\ORM;
use Tina4\Database\Database;

// (a) .env auto-default — NO call needed.
//     Models auto-bind to TINA4_DATABASE_URL (via Database::fromEnv()).
//     Apps relying on the .env default need no change.

// (b) Override the default binding explicitly:
\Tina4\ORM::bindDatabase(Database::create('sqlite:///app.db'));

// (c) Named / secondary connections — register under a name, then point a model at it:
\Tina4\ORM::bindDatabase(
    Database::create('postgres://localhost:5432/analytics', username: 'u', password: 'p'),
    name: 'analytics'
);

class Visit extends \Tina4\ORM {
    // String selects a named connection; resolves to the 'analytics' adapter above.
    public \Tina4\Database\DatabaseAdapter|string|null $_db = 'analytics';
}
```

Resolution order per model: instance `$_db` (an adapter, or a string naming a bound connection)
-> default `\Tina4\ORM::bindDatabase()` binding -> `Database::fromEnv()`. A missing named
connection throws a clear error. `bindDatabase` is the only binder — there is no `setGlobalDb`.

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
$url = DatabaseUrl::fromEnv('TINA4_DATABASE_URL');
```

### ORM — Active Record

```php
class User extends \Tina4\ORM {
    public $tableName = "users";
    public $primaryKey = "id";
    public $fieldMapping = [];     // ["db_column" => "phpProperty"]
    public $hasOne = [];           // Related single records
    public $hasMany = [];          // Related collections
    public $belongsTo = [];        // Parent records
    public $foreignKeys = [];      // FK auto-wire: ['user_id' => 'User'] → $post->user + $user->posts
    public $softDelete = false;
    public $tableFilter = "";      // Default WHERE clause
}

// $foreignKeys auto-wires both sides of the relationship:
//   Simple form:  ['user_id' => 'User']
//   Extended:     ['user_id' => ['model' => 'User', 'related_name' => 'blog_posts']]
// → declaring model gets belongsTo entry (column name minus _id)
// → referenced model gets hasMany entry (declaring class lowercased + 's', or related_name)

// Constructor — the first argument is type-detected:
$user = new User();                        // empty record
$user = new User($request);                // populate from a Request
$user = new User(['name' => 'Alice']);     // array data-first (no need for the data: arg)
$user = new User('{"name": "Alice"}');     // JSON object string -> one record
$user = new User(data: ['name' => 'Alice']); // explicit named arg (still works)
$user = new User($db, ['name' => 'Alice']);  // $db adapter first (still works)
// Passing a LIST (e.g. [['a'],['b']] or a JSON array) throws InvalidArgumentException —
// a single-record constructor cannot hold many records. Map over the list to build many.

// Instance methods
$user->save(): static|false            // Returns $this on success (fluent), false on failure
$user->delete(): bool
$user->forceDelete(): bool
$user->restore(): bool
$user->load($sql, $params, $include): bool  // selectOne into $this; true if found
$user->validate(): array               // Empty = valid
$user->exists($pkValue): bool
$user->toDict($include): array         // Primary dict method (aliases: toAssoc, toObject)
$user->toAssoc($include): array        // Alias for toDict
$user->toArray(): array                // Flat indexed list of values
$user->toList(): array                 // Alias for toArray
$user->toJson($include): string        // JSON string with optional relationship include
$user->hasOne($relatedClass, $foreignKey): ?ORM
$user->hasMany($relatedClass, $foreignKey, $limit, $offset): array
$user->belongsTo($relatedClass, $foreignKey): ?ORM

// Instance methods that query (also work as User()->where(...))
$user->findById($id): self
$user->find($filter, $limit, $offset, $orderBy): array
$user->select($sql, $params, $limit, $offset): array
$user->selectOne($sql, $params, $include): ?static
$user->where($filterSql, $params, $limit, $offset): array
$user->all($limit, $offset): array
$user->count($conditions, $params): int
$user->findOrFail($id): static
$user->withTrashed($filter, $params, $limit, $offset): array
$user->scope($name, $filterSql, $params): void  // Registers reusable named method: User::active()
$user->createTable(): bool

// Static methods
User::create($data): static
User::query(): QueryBuilder
User::active($limit, $offset): array   // Example scope (after scope('active', 'active=1'))
```

NoSQL support: `toMongo()` generates MongoDB query documents from the same fluent API.

### QueryBuilder — Fluent query construction

Use `QueryBuilder` for complex queries with JOINs, aggregates, GROUP BY. Always prefer over raw `$db->fetch()`.

```php
use Tina4\QueryBuilder;

// JOINs
$orders = QueryBuilder::fromTable("orders o")
    ->select("o.*", "c.name as customer_name")
    ->join("customers c", "o.customer_id = c.id")
    ->where("o.status = ?", ["pending"])
    ->orderBy("o.created_at DESC")
    ->limit(20)
    ->get();                       // -> array

// LEFT JOIN
$products = QueryBuilder::fromTable("products p")
    ->select("p.*", "cat.name as category_name")
    ->leftJoin("categories cat", "p.category_id = cat.id")
    ->get();

// Aggregates
$total = QueryBuilder::fromTable("orders")
    ->select("coalesce(sum(total), 0) as total")
    ->where("status != ?", ["cancelled"])
    ->first()["total"];            // -> single row dict

// From ORM model
$results = (new User())->query()->where("age > ?", [18])->orderBy("name")->get();

// Methods: fromTable(), select(), where(), orWhere(), join(), leftJoin(),
//          groupBy(), having(), orderBy(), limit(), get(), first(), count(),
//          exists(), toSql(), toMongo()
```

NoSQL support: `toMongo()` generates MongoDB query documents from the same fluent API.

### File Uploads

Multipart file uploads are available via `$request->files` (array keyed by field name). Each file is an array:

```php
// $request->files["avatar"] =>
[
    "fieldName" => "avatar",
    "filename" => "photo.png",
    "type" => "image/png",
    "content" => "...",       // raw binary — NOT base64
    "size" => 102400
]
```

```php
Router::post("/api/upload", function (Request $request, Response $response) {
    $file = $request->files["avatar"] ?? null;
    if (!$file) return $response->json(["error" => "No file"], 400);
    file_put_contents("src/public/uploads/{$file['filename']}", $file["content"]);
    return $response->json(["ok" => true]);
});
```

Max upload size: `TINA4_MAX_UPLOAD_SIZE` env var (default 10MB).

### Auth

```php
// expires_in is in MINUTES (default 60). Reads SECRET from env if not passed.
Auth::getToken($payload, $secret=null, $expiresIn=60): string
Auth::validToken($token, $secret=null): ?array
Auth::getPayload($token): ?array
Auth::refreshToken($token, $expiresIn=60): ?string
Auth::hashPassword($password, $salt=null, $iterations=260000): string  // PBKDF2-SHA256, $ delimiter
Auth::checkPassword($password, $hash): bool
Auth::validateApiKey($provided, $expected=null): bool  // reads TINA4_API_KEY from env
Auth::authenticateRequest($headers, $secret=null): ?array  // Bearer JWT, falls back to API key
Auth::ensureDevSecret($cwd=null): ?string  // dev-secret bootstrap (run once at boot)
```

**`TINA4_SECRET` — dev secret auto-generation.** The default signing secret is
always BLANK (never a guessable built-in). `Auth::ensureDevSecret()` runs once at
boot (after env load, before auth is used). In local **dev** (`TINA4_DEBUG=true`,
no `CI` env var, `TINA4_ENV` != `production`) with a blank `TINA4_SECRET`, it
generates a cryptographically-random secret (32 bytes / 64 hex chars), sets it in
the process env for the run, and **appends it to `.env.local`** (created if
missing — gitignored) so subsequent boots reuse it. The write is guarded: if
`.env.local` can't be written it keeps the in-memory secret and warns — boot never
crashes. In **CI or production** with a blank secret it NEVER generates or
persists anything — it logs an actionable warning naming exactly what to set
(`openssl rand -hex 32`). At boot the framework loads env with strict
precedence **real-env > `.env.local` > `.env`**: it loads `.env.local` first,
then `.env`, both with `overwrite: false` (first-wins), so a variable already
set in the real process environment is never clobbered, `.env.local` then fills
local-only keys (a previously-generated dev secret still wins over `.env`), and
`.env` fills the rest. A stray gitignored `.env.local` can therefore never
override an explicitly-set real env var (e.g. a production `TINA4_SECRET`). Both
`.env.local` and the scaffolded project's `.gitignore` exclude `.env.local`.

### Session

```php
$session->start($sessionId=null): string
$session->get($key, $default=null): mixed
$session->set($key, $value): void
$session->delete($key): void
$session->has($key): bool
$session->all(): array
$session->clear(): void
$session->destroy(): void
$session->regenerate(): string
$session->flash($key, $value=null): mixed   // Dual-mode: set with value, get+remove without
$session->getFlash($key, $default=null): mixed
$session->save(): void
$session->cookieHeader($name='tina4_session'): string
$session->getSessionId(): string
$session->gc(): void
```

Backends: file, redis, valkey, mongodb, database.

**Backend-failure policy (all 4 frameworks): log-loud + degrade.** A backend (Redis/Valkey/Mongo/DB) that becomes unreachable mid-request is logged via `\Tina4\Log::error` and degraded rather than crashing the app or losing data silently: a read failure yields an empty session (the request still serves), and `save()` returns `false` (best-effort, dirty flag retained for a later retry). A genuinely empty session (no data yet) is NOT an error and is never logged. Set `TINA4_SESSION_STRICT=true` to re-throw instead. Call `regenerate()` right after a successful login or privilege change to defeat session fixation. The `DatabaseSessionHandler` binds every query (parameterised) — the `session_id` cookie value can never be SQL-injected.

### Database extras

```php
$db->execute($sql, $params): bool|DatabaseResult  // SUCCESS: true for writes, DatabaseResult for RETURNING/CALL/EXEC/SELECT.
                                                   // FAILURE: RAISES \Tina4\Database\DatabaseException — never returns false.
                                                   // The cause is still captured on getError(). Mirrors fetch()/fetchOne(),
                                                   // which also raise. Don't test the return — wrap in try/catch:
                                                   //   try { $db->execute($sql); } catch (\Throwable $e) { /* $db->getError() */ }
                                                   // Higher-level callers preserve their own contracts: ORM::save() catches
                                                   // and returns false; ORM::createTable() catches, logs, returns false; the
                                                   // migration runner rolls back + re-raises; dev-admin + MCP DB tools return
                                                   // a clean {error} payload.
$db->fetch($sql, $params, $limit, $offset): DatabaseResult  // Also RAISES on a bad statement (no swallow-to-empty-result).
$db->getLastId(): int|string
$db->getError(): ?string                           // Cause of the last execute()/fetch() error; cleared on the next success.
$db->getColumns($tableName): array
```

### Request extras

```php
$request->cookies  // Parsed from Cookie header
$request->query    // Query string params
$response->xml($content, $status): self
$response->stream(callable $source, string $contentType = 'text/event-stream'): self  // SSE/streaming
```

### Response — Auto-serializing domain objects

`$response(...)` and `$response->json(...)` auto-serialize an ORM model, an array of
models, or a `DatabaseResult` (from `$db->fetch(...)`) straight to JSON — no manual
`->toDict()` / `->toJson()` needed:

```php
Router::get("/api/users", function (Request $request, Response $response) {
    return $response((new User())->all());        // array of models -> JSON array
});

Router::get("/api/users/{id}", function (Request $request, Response $response, $id) {
    return $response((new User())->findById($id)); // single model -> JSON object
});

Router::get("/api/raw", function (Request $request, Response $response) use ($db) {
    return $response($db->fetch("select * from users")); // DatabaseResult -> JSON array
});
```

A single model becomes a JSON object; an array of models or a `DatabaseResult` becomes a
JSON array. Plain arrays and strings behave exactly as before — this is purely additive.
`Response::json()` still pretty-prints.

### Response — Template rendering

The `Response` object supports rendering Twig templates via the built-in `Frond` engine:

```php
Router::get("/dashboard", function (Request $request, Response $response) {
    return $response->render("dashboard.twig", [
        "title" => "Dashboard",
        "user" => $currentUser,
    ]);
});

// With custom status code and template directory
$response->render("error.twig", ["code" => 404], 404, 'src/templates');
```

The `render()` method uses `Frond` (built-in Twig-compatible engine, zero dependencies) and defaults to looking in `src/templates/`.

### Frond — Built-in Twig-compatible template engine

```php
use Tina4\Frond;

$frond = new Frond(string $templateDir = 'src/templates');
$frond->render(string $template, array $data = []): string
$frond->renderString(string $source, array $data = [], ?string $templateName = null): string
$frond->addFilter(string $name, callable $fn): void
$frond->addGlobal(string $name, mixed $value): void
$frond->addTest(string $name, callable $fn): void
$frond->getFilters(): array
$frond->getGlobals(): array
$frond->clearCache(): void
$frond->sandbox(?array $filters = null, ?array $tags = null, ?array $vars = null): self
$frond->unsandbox(): self
```

- **SafeString**: Custom filters can return `new SafeString($value)` to bypass auto-HTML-escaping.
- **Fragment caching**: `{% cache "key" 300 %}...{% endcache %}` — caches rendered block content for TTL seconds.
- **Raw blocks**: `{% raw %}...{% endraw %}` — output literal template syntax without parsing.
- **Sandbox mode**: Restrict template capabilities via `$frond->sandbox(filters: [...], tags: [...], vars: [...])`.

### Auth — JWT authentication

```php
$auth = new \Tina4\Auth();
Auth::getToken(array $payload, string|int|null $secret = null, int $expiresIn = 60): string
Auth::validToken(string $token, ?string $secret = null): ?array
Auth::getPayload(string $token): ?array
```

### Api — External HTTP client

```php
$api = new \Tina4\Api(?string $baseURL = "", string $authHeader = "")
$api->sendRequest(string $method = "GET", string $path = "", $body = null, string $contentType = "application/json"): array
$api->addHeaders(array $headers): void
$api->setBasicAuth(string $username, string $password): void
```

**Retry/backoff (opt-in, default off):** the constructor accepts `maxRetries` (default `0`) and `retryBackoff` (default `0.5`s base, exponential). When `maxRetries > 0` a transport error or a retryable status (429/500/502/503/504) is retried; 4xx is never retried. A retried non-idempotent request may be re-sent — retries are opt-in for that reason. (PHP `file_get_contents` does not auto-follow redirects, so there is no cross-host Authorization-leak surface — the redirect auth-strip is Python-only.)

### Migration — Database migrations

```php
$migration = new \Tina4\Migration(DatabaseAdapter $db, string $migrationsDir = "src/migrations", string $delimiter = ";")
$migration->migrate(): array
```

### Queue — Job queue with pluggable backends

```php
use Tina4\Queue;

$queue = new Queue(string $backend = 'file', array $config = [], string $topic = 'default');
$queue->push(mixed $payload, int $priority = 0, int $delay = 0): string  // returns job id
$queue->pop(): ?array
$queue->popBatch(int $count): array
$queue->popById(string $id): ?array
$queue->size(string $status = 'pending'): int
$queue->clear(): int
$queue->failed(): array
$queue->retry(?string $jobId = null, int $delaySeconds = 0): bool
$queue->retryFailed(?int $maxRetries = null): int
$queue->deadLetters(?int $maxRetries = null): array
$queue->purge(string $status, ?int $maxRetries = null): int
$queue->produce(string $topic, mixed $payload, int $priority = 0, int $delaySeconds = 0): string
$queue->consume(string $topic = '', ?string $id = null, float $pollInterval = 1.0, int $iterations = 0, int $batchSize = 1): \Generator
$queue->process(callable|string $handlerOrQueue, callable|string|array $queueOrHandlerOrOptions = '', array $options = []): void
$queue->getTopic(): string

// Job methods (yielded by consume() / returned by pop() as array, or wrapped via Job class)
$job->complete(): void
$job->fail(string $reason = ''): void
$job->reject(string $reason = ''): void           // alias for fail()
$job->retry(int $delaySeconds = 0): void
$job->toArray(): array
$job->toHash(): array
$job->toJson(): string
```

Backends: `file` (default), `rabbitmq`, `kafka`, plus MongoDB. Override via `TINA4_QUEUE_BACKEND` env var.

### Seeder — Fake data generation

```php
use Tina4\FakeData;

$fake = new FakeData(?int $seed = null);
FakeData::seed(int $seed): self                  // static seeded factory

$fake->name(): string
$fake->firstName(): string
$fake->lastName(): string
$fake->email(): string
$fake->phone(): string
$fake->address(): string
$fake->city(): string
$fake->country(): string
$fake->zipCode(): string
$fake->company(): string
$fake->jobTitle(): string
$fake->sentence(int $words = 8): string
$fake->paragraph(int $sentences = 3): string
$fake->text(int $paragraphs = 3): string
$fake->word(): string
$fake->integer(int $min = 0, int $max = 1000): int
$fake->numeric(float $min = 0.0, float $max = 1000.0, int $decimals = 2): float
$fake->boolean(): bool
$fake->date(string $start = '2020-01-01', string $end = '2025-12-31'): string
$fake->datetime(int $startYear = 2020, int $endYear = 2025): string
$fake->uuid(): string
$fake->url(): string
$fake->ipAddress(): string
$fake->colorHex(): string
$fake->creditCard(): string
$fake->currency(): string
$fake->choice(array $items): mixed
$fake->forField(array $fieldDef, string $columnName = ''): mixed

// Bulk seeding — visible-but-resilient (per-row wrap; log + skip on failure,
// or strict=true to re-raise the first failure). All three return a
// SeedSummary {seeded, failed, errors} that also behaves as the seeded count
// (ArrayAccess: $summary['seeded']; count($summary) === seeded).
FakeData::seedTable($db, string $tableName, int $count = 10, array $fieldMap = [], array $overrides = [], bool $clear = false, ?int $seed = null, bool $strict = false): SeedSummary
FakeData::seedOrm(string $modelClass, int $count = 10, array $overrides = [], bool $clear = false, ?int $seed = null, bool $strict = false): SeedSummary
// Batch-seed FK-related models — topo-sorts by $foreignKeys so parents seed
// before children (clears in reverse), and points child FKs at real parent PKs.
FakeData::seedModels(array $modelClasses, int $count = 10, array $overrides = [], bool $clear = false, ?int $seed = null, bool $strict = false): array  // [ClassName => SeedSummary]
$fake->seedDir(string $seedDir = 'src/seeds'): array
$fake->run(callable $seeder, int $count = 10): array
```

- **clear** (P2 idempotency): truncate the target table(s) before seeding so re-runs don't duplicate rows / trip unique-PK violations.
- **seed** (P3 reproducibility): seeds the FakeData RNG so a run is repeatable (seedOrm/seedModels seed their own FakeData; seedTable seeds for parity — its determinism comes from the FakeData you pass through `$fieldMap`).
- **strict** (P1): re-raise on the first failed row instead of logging + skipping.
- The dev-admin seed endpoint (`POST /__dev/api/seed`) delegates to `seedTable` and accepts `seed`/`clear`/`strict`, returning `{seeded, failed, errors, table}`.

### Log — Logging

```php
\Tina4\Log::debug(string $message, array $context = []): void
\Tina4\Log::info(string $message, array $context = []): void
\Tina4\Log::warning(string $message, array $context = []): void
\Tina4\Log::error(string $message, array $context = []): void
\Tina4\Log::critical(string $message, array $context = []): void  // Highest severity (debug<info<warning<error<critical). ALWAYS emits like every level; mirrored into error.log (4 >= warning 2); renders magenta. No toggle.
\Tina4\Log::isEnabled(string $level): bool  // True if $level passes the min CONSOLE level (case-insensitive); reuses the stdout gate so it never disagrees with what prints. File sink records every level regardless. 'critical' is ordinary threshold logic (it outranks error).
// Level filtering via TINA4_LOG_LEVEL env var (DEBUG | INFO | WARNING | ERROR | CRITICAL)
// TINA4_LOG_CRITICAL env toggle is RETIRED (v3.13.39) — critical is first-class and always logs.
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

### Background Tasks — Periodic background work in the server event loop

Register callbacks that run periodically in the server's `stream_select` event loop. No threads, no separate processes — tasks run cooperatively between HTTP requests.

```php
$app = new \Tina4\App();

// Process queue jobs every 2 seconds
$app->background(function () use ($queue) {
    processOrders($queue);
}, 2.0);

// Health check every 30 seconds
$app->background(function () {
    $api = new \Tina4\Api("https://api.example.com");
    $result = $api->sendRequest("/health");
    if ($result['http_code'] !== 200) {
        \Tina4\Log::warning("Health check failed");
    }
}, 30.0);

$app->run();
```

**Never use raw threads or separate processes for periodic work.** Use `$app->background()` instead — it integrates with the server lifecycle, handles errors gracefully, and shuts down cleanly.

| Method | Description |
|--------|-------------|
| `$app->background(callable $callback, float $interval = 1.0): self` | Register a periodic task. Callback takes no arguments. Interval is in seconds. Returns `$app` for chaining. |

Server-level access (advanced):
```php
$server->onTick(callable $callback, float $interval = 1.0): void
```

### AI — Detect AI coding assistants and scaffold context files

Supports: Claude Code, Cursor, GitHub Copilot, Windsurf, Aider, Cline, OpenAI Codex.

```php
// Check if a specific tool's context file already exists in $root.
// $tool is one entry from AI::$AI_TOOLS (has 'name', 'context_file', 'config_dir').
AI::isInstalled(string $root, array $tool): bool

// Print the numbered tool menu (with [installed] markers) and read a
// comma-separated selection (or "all") from STDIN. Returns the raw line.
AI::showMenu(string $root = "."): string

// Install context files for the tools listed in $selection ("1,3,5" or "all").
// Returns relative paths of created/updated files.
AI::installSelected(string $root, string $selection): array

// Install context files for ALL known AI tools (non-interactive).
AI::installAll(string $root = "."): array

// Generate the tool-specific Tina4 context body (used by installSelected).
AI::generateContext(string $toolName = 'claude-code'): string
```

Example:
```php
// Interactive: show menu, read selection, install
$selection = AI::showMenu('.');
$created = AI::installSelected('.', $selection);

// Non-interactive: install everything
$created = AI::installAll('.');

// Check status — re-rendering the menu shows [installed] markers per tool
AI::showMenu('.');
```

### Response Cache — GET response caching middleware

Caches GET responses with TTL. Controlled via env vars or constructor config.
Public surface mirrors Python's `tina4_python.cache`: middleware-only, plus
module-level `cacheStats()` / `clearCache()`. Internal lookup/store of GET
responses is performed by the middleware hooks and is NOT exposed publicly.

| Env Variable | Default | Description |
|---|---|---|
| `TINA4_CACHE_BACKEND` | memory | Backend: `memory` \| `file` \| `redis` \| `valkey` \| `memcached` \| `mongodb` \| `database` |
| `TINA4_CACHE_URL` | — | Connection for redis/valkey/memcached/mongodb, OR a SQL URL for `database` (falls back to `TINA4_DATABASE_URL`) |
| `TINA4_CACHE_USERNAME` / `TINA4_CACHE_PASSWORD` | — | Credentials (mirrors `TINA4_DATABASE_USERNAME`/`_PASSWORD`); may also be embedded in `TINA4_CACHE_URL` (`redis://user:pass@host`, `redis://:pass@host`, `mongodb://user:pass@host`). memcached is unauthenticated |
| `TINA4_CACHE_TTL` | 60 | Default TTL in seconds (0 = disabled) |
| `TINA4_CACHE_MAX_ENTRIES` | 1000 | Maximum cache entries |
| `TINA4_CACHE_DIR` | data/cache | Directory for the `file` backend |

The response/KV cache supports seven backends, selected by `TINA4_CACHE_BACKEND`. **Graceful fallback**: if a configured backend's driver is missing or the service/credentials are unreachable or wrong, the cache logs a warning and falls back to the **file** backend — a real persistent cache, never a silent no-op.

```php
use Tina4\Middleware\ResponseCache;

// Use as route middleware — cache hooks run before/after the handler
Router::get("/api/data", $handler)->middleware([ResponseCache::class]);

// Module-level stats and management (parity with Python cache_stats() / clear_cache())
ResponseCache::cacheStats(): array     // ['hits' => int, 'misses' => int, 'size' => int, 'backend' => string, 'keys' => string[]]
ResponseCache::clearCache(): void      // Flush all cached entries

// Namespace-level KV cache helpers (parity with Python cache_get/cache_set/cache_delete)
\Tina4\Middleware\cache_get(string $key): mixed
\Tina4\Middleware\cache_set(string $key, mixed $value, int $ttl = 0): void
\Tina4\Middleware\cache_delete(string $key): bool
\Tina4\Middleware\cache_clear(): void
\Tina4\Middleware\cache_stats(): array
```

The `\Tina4\Middleware\cache_*` helpers autoload on a plain `require` of the
package — previously they fataled with "undefined function" until the
`ResponseCache` class had been referenced first.

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
ErrorOverlay::renderErrorOverlay(\Throwable $e, ?array $request = null): string

// Render a safe, generic error page (production)
ErrorOverlay::renderProductionError(int $statusCode = 500, string $message = 'Internal Server Error'): string

// Check if TINA4_DEBUG is enabled
ErrorOverlay::isDebugMode(): bool
```

Example:
```php
try {
    $handler($request, $response);
} catch (\Throwable $e) {
    if (ErrorOverlay::isDebugMode()) {
        echo ErrorOverlay::renderErrorOverlay($e, $_SERVER);
    } else {
        echo ErrorOverlay::renderProductionError(500);
    }
}
```

### HtmlElement — Programmatic HTML builder

Build HTML without string concatenation. Supports void tags, attribute merging, and nested children.

**XSS-safe by default.** String/scalar children are HTML-escaped on render
(via `htmlspecialchars(..., ENT_QUOTES)`), so untrusted text can never inject a
live tag. Nested `HtmlElement` children render themselves (no double-escape),
and attribute values stay escaped. To emit *trusted, pre-sanitised* markup
verbatim, wrap it in `Tina4\Raw` (or the equivalent `Tina4\SafeString`, the
Frond escape-hatch class — both are detected and rendered unescaped).

```php
use Tina4\HtmlElement;
use Tina4\Raw;

// Constructor
$el = new HtmlElement(string $tag, array $attrs = [], array $children = []);

// Render to HTML
(string) $el;  // __toString()

// Escaping behaviour
echo new HtmlElement("div", [], ["<script>alert(1)</script>"]);
// => <div>&lt;script&gt;alert(1)&lt;/script&gt;</div>   (escaped — no live tag)

echo new HtmlElement("div", [], [new Raw("<b>x</b>")]);
// => <div><b>x</b></div>                                 (raw — opt-in)

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
SqlTranslation::namedToPositional(string $sql, array $params): array  // :name -> ?, reorders params (used by MySQL/MSSQL/Firebird/Postgres adapters; skips string literals + comments; duplicate names bind once per occurrence)
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
- **Default server** — Binds to `0.0.0.0:7145` by default
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
- Background tasks via `$app->background()` — cooperative periodic callbacks in the event loop (no threads)
- AI assistant detection (`AI`) with context file scaffolding for 7 tools
- Queue system with Kafka, RabbitMQ, and MongoDB backends
- Session handlers for MongoDB and Valkey/Redis. `TINA4_SESSION_SAMESITE` env var (default: Lax)
- GraphQL query execution. **Depth guard**: selection-set nesting is bounded by `TINA4_GRAPHQL_MAX_DEPTH` (default `50`; set `<= 0` to disable) — an over-deep query or a circular fragment fails with a structured `"Query exceeds maximum depth of N"` error (counted per selection level AND per fragment spread) instead of overflowing the stack. Resolver exceptions are captured as GraphQL errors — the message is the real cause only in debug mode (`ErrorOverlay::isDebugMode()` / `TINA4_DEBUG`); in production it is a generic `"Internal server error"` (the real cause is logged via `Log::error`, `path` preserved) so a resolver exception never leaks internal state
- SOAP 1.1 / WSDL (`WSDL`, `#[WSDLOperation]`). **DOCTYPE rejected**: a SOAP request containing a `<!DOCTYPE>` (DTD) is rejected with a `Client` fault **before** parsing — SOAP 1.1 §3 forbids DTDs, and this closes the XML entity-expansion (billion-laughs) + external-entity (XXE) attack surface for every parser. **Error masking is debug-gated**: an operation that raises returns a `Server` fault whose `<faultstring>` is the real cause only in debug mode (`ErrorOverlay::isDebugMode()` / `TINA4_DEBUG`); in production it is a generic `"Internal server error"` and the real cause is logged via `Log::error`, so a resolver exception never leaks internal state (DB creds, file paths) to a SOAP client
- WebSocket support. WebSocket backplane for scaling broadcast across instances via Redis/NATS pub/sub (`TINA4_WS_BACKPLANE`, `TINA4_WS_BACKPLANE_URL` env vars) — **wired into the live broadcast path**: every `broadcastWebSocket()`/`broadcastToRoom()` delivers to LOCAL connections first then publishes an envelope `{src,kind,exclude,room,path,+text|b64}` to the shared `tina4:ws` channel (identical shape across all 4 frameworks); sibling instances drain it on the event-loop idle tick and relay to their own LOCAL connections only (origin guard drops our own echo by stable per-process id — no double-delivery, no cluster loop). Backplane failure logs + degrades to local-only, never crashes a broadcast. Broadcasts are resilient: a dead/slow client is pruned and never aborts delivery to the rest. **Security**: optional origin allow-list via `TINA4_WS_ALLOWED_ORIGINS` (comma-separated; empty/unset = allow all — non-breaking; set = reject mismatched/missing Origin with 403 on every upgrade path). **Idle reaper**: `TINA4_WS_IDLE_TIMEOUT` (seconds; 0/unset = disabled) closes connections idle past the timeout. RFC 6455 fragmented messages (`OP_CONTINUATION`) are reassembled before dispatch. Rooms API: `$ws->joinRoom($clientId, $room)`, `$ws->leaveRoom($clientId, $room)`, `$ws->broadcastToRoom($room, $msg, $excludeIds?)`, `$ws->getRoomConnections($room)`, `$ws->roomCount($room)`
- Swagger/OpenAPI spec generation
- Internationalisation (`I18n`)
- Messenger (.env driven SMTP/IMAP). IMAP reads **fail loud**: `inbox()`/`read()`/`unread()`/`search()`/`folders()` LOG and RAISE `Tina4\MessengerConnectionError` (extends `\RuntimeException`) on a connection/auth/protocol failure instead of swallowing it into an empty result — a *successful* fetch from a genuinely empty mailbox still returns empty (`[]`/`null`/`0`) normally. `send()` is unchanged (returns `{success, message, id}`)
- CLI scaffolding: `composer tina4 generate model/route/migration/middleware`
- Production server: `composer start --production` (OPcache auto-config)
- Frond pre-compilation for 2.8x template render improvement
- DB query caching: request-scoped auto cache **off by default — opt-in via `TINA4_AUTO_CACHING=true`** (TTL `TINA4_AUTO_CACHING_TTL=5`s) dedupes identical reads within a request and flushes on writes. It is OFF by default because an on-by-default request cache is a footgun: a `SELECT MAX(id)` (or generator read) right before an INSERT in the same request returns a cached pre-write value → duplicate primary keys, and any read-after-write in one request shows stale state — so turn it on only for read-heavy endpoints. Persistent cross-request cache is also opt-in via `TINA4_DB_CACHE=true` (TTL `TINA4_DB_CACHE_TTL=30`s) routed through the unified backend set via `TINA4_DB_CACHE_BACKEND` (memory/file/redis/valkey/memcached/mongodb/database) + `TINA4_DB_CACHE_URL` so instances share one cache with global write-invalidation; `cacheStats()` reports `mode` (request/persistent/off) and `backend`, `cacheClear()`
- ORM relationships: `hasMany`, `hasOne`, `belongsTo` with eager loading (`include:`)
- Queue backends: file (default), RabbitMQ, Kafka, MongoDB
- Cache backends (`Tina4\Cache`): unified set across response/KV and persistent DB cache — `memory` (default), `file`, `redis`, `valkey`, `memcached`, `mongodb`, `database` — selected via `TINA4_CACHE_BACKEND` (+ `TINA4_CACHE_URL`/credentials); falls back to the file backend if a backend is unreachable
- Session handlers: file, Redis/Valkey, MongoDB, database. `TINA4_SESSION_SAMESITE` env var controls SameSite attribute (default: Lax)
- QueryBuilder with NoSQL/MongoDB support (`toMongo()`)
- WebSocket backplane (Redis/NATS pub/sub) for horizontal scaling — wired into the live broadcast path with an origin guard + local-first delivery (see the WebSocket bullet above)
- SameSite=Lax default on session cookies (`TINA4_SESSION_SAMESITE`)
- `tina4 deploy docker` generates Dockerfile and .dockerignore
- Gallery: 7 interactive examples with Try It deploy at `/__dev/`
- Race-safe `getNextId()` with atomic sequence table (`tina4_sequences`) for SQLite/MySQL/MSSQL; PostgreSQL auto-creates sequences
- Frond template engine optimizations: pre-compiled regexes, lazy loop context (copy-on-write), filter chain caching, path split caching, inline common filters (11-15% speedup)
- SSE/Streaming via `$response->stream()` — Server-Sent Events support for real-time data push. Pass a generator callable; framework handles chunked transfer encoding, `text/event-stream` content type, and connection keep-alive. Hardened: the stream stops cleanly on client disconnect (`connection_aborted()`) and a generator that raises mid-stream is logged via `Log::error` and ends cleanly — the request worker never crashes
- Tests: 3,184 passing

## Links

- Website: https://tina4.com
- GitHub: https://github.com/tina4stack/tina4-php

## Tina4 Maintainer Skill
Always read and follow the instructions in .claude/skills/tina4-maintainer/SKILL.md when working on this codebase. Read its referenced files in .claude/skills/tina4-maintainer/references/ as needed for specific subsystems.

## Tina4 Developer Skill
Always read and follow the instructions in .claude/skills/tina4-developer/SKILL.md when building applications with this framework. Read its referenced files in .claude/skills/tina4-developer/references/ as needed.

## Tina4-js Frontend Skill
Always read and follow the instructions in .claude/skills/tina4-js/SKILL.md when working with tina4-js frontend code. Read its referenced files in .claude/skills/tina4-js/references/ as needed.

## First Principle: Documentation Matches Code Reality

**This rule overrides everything else in this file.**

Every command, env var, method, class, or feature mentioned in any
documentation file (`*.md` in this repo, or any tina4-book chapter,
or `tina4-documentation/docs/`) MUST exist in code. No exceptions.
No "we'll build it later" entries. No Laravel/Rails-style commands
that look right but don't exist. No env vars that the framework
doesn't actually read.

When you add a doc reference, add the implementation in the same PR.
When you remove a feature, remove every doc reference in the same PR.
When you find drift, fix it both ways: build the real thing OR delete
the doc.

The `tina4-documentation/scripts/audit-truth.py` script is the source
of truth. It runs as a CI gate (`audit-truth.yml`) on every PR — the
build fails on CLI drift. Run it locally before pushing if you've
touched docs:

```bash
cd /path/to/tina4-documentation
python3 scripts/audit-truth.py --strict
```

If you're unsure whether something exists, run `tina4 <command> --help`
or grep the framework source. Don't guess.
