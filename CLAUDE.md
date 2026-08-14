# Tina4 PHP

Version 3.13.101 - Full Tina4 PHP framework and application scaffold. See https://tina4.com for full documentation.

## Build & Test

- PHP: >=8.2
- Install: `composer install`
- Run tests: `composer test` or `./vendor/bin/phpunit tests --colors=always`
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
- **NO mock testing. Mocks are not acceptable in any circumstances.** A test double (mock, stub, fake, spy, monkeypatch, or any in-test object standing in for a real collaborator, e.g. FakeMongoCollection) may never substitute for a real dependency, under any justification. There is no "supplement" exception and no "hard to reproduce" exception. Any test that touches a dependency (a DB engine, MongoDB, Redis/Valkey/Memcached, RabbitMQ/Kafka, an HTTP/SMTP service, the filesystem, a socket) must exercise the REAL service; if a failure mode is hard to trigger, reproduce it for real, never simulate it. "Verified"/"green" requires a real run; a passing mock test is not verification. CI provisions the services; use them and add any that is missing (e.g. ext-mongodb for the Mongo queue). The only tests that need no live dependency are pure functions with no dependency and no double; that is not a mock test. (The Node MongoDB queue re-delivered every completed job for two releases because its queue tests were mock-based and never ran against a real Mongo.)
- **Routes use `$response()`** — Return via the response callable, this is the Tina4 convention
- **Error handling in routes** — Wrap route logic in `try/catch`, log with `Log::error()` / `Log::warning()` / `Log::info()` / `Log::debug()` (or alias `Debug` if you have a backwards-compat shim), return response with appropriate status
- **PHPDoc** — Every public method and function carries a docblock: a present-tense one-line summary of the behaviour, `@param` for each argument, `@return`, and `@throws` for each exception the caller can hit (with its trigger condition). Describe the behaviour, never the fix or a version note (those go in the changelog); types must match the real signature (including `?`/union); no orphaned docblocks. See `CONTRIBUTING.md`
- **All links and references** should point to https://tina4.com
- **Push to staging only** — Never push to production without explicit approval
- PSR-4 autoloading — core classes live in `Tina4/` (not `src/Tina4/`)
- Namespace `Tina4\` for core, `Tina4\Database\` for adapters, `Tina4\Middleware\` for middleware, `Tina4\Queue\` for queue backends, `Tina4\Session\` for session handlers
- Namespace `\` for src/app/orm/routes
- Linting: `composer lint` runs `vendor/bin/phplint`, but phplint is NOT in
  `require-dev` (only `phpunit/phpunit` and `mongodb/mongodb` are), so on a fresh
  `composer install` it fails with "No such file or directory". The
  zero-dependency check that does work today is `php -l` over the changed files

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
  SQLTranslator.php      # SQL dialect translation layer
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
    LiteBackend.php      # File/SQLite queue backend (the default)
    KafkaBackend.php     # Kafka queue backend
    MongoBackend.php     # MongoDB queue backend
    RabbitMQBackend.php  # RabbitMQ queue backend
  Session/
    DatabaseSessionHandler.php  # SQL session handler
    MemcachedSessionHandler.php # Memcached session handler
    MongoSessionHandler.php     # MongoDB session handler
    RedisSessionHandler.php     # Redis session handler
    ValkeySessionHandler.php    # Valkey session handler
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

v3 uses `Database::create()` with standardised URL connection strings. The v2 aliases `mariadb`, `fdb` and `sqlsrv` are removed and raise.

Supported schemes, verified against the code: `sqlite`, `sqlite3`, `postgres`, `postgresql`, `pgsql`, `mysql`, `mssql`, `sqlserver`, `firebird`.

Each resolves to a canonical **engine** (`sqlite`, `postgres`, `mysql`, `mssql`, `firebird`), which is what `DatabaseUrl::$engine` holds. `sqlite3` is accepted because the driver is literally named sqlite3 in every framework, and `pgsql` because it is the PDO/Laravel/Doctrine spelling.

```php
use Tina4\Database\Database;

// Create from URL — driver://host:port/database
// SQLite slash count decides relative vs absolute (the SQLAlchemy convention,
// identical in all four frameworks). Three slashes is RELATIVE to the working
// directory; an absolute path needs FOUR.
$db = Database::create('sqlite:///app.db');            // relative: ./app.db
$db = Database::create('sqlite:////var/data/app.db');  // absolute: /var/data/app.db
$db = Database::create('sqlite:/var/data/app.db');     // absolute (one slash) too
$db = Database::create('sqlite::memory:');
// FOOTGUN: 'sqlite://' . $absolutePath yields THREE slashes, so the file is
// created UNDER the working directory and a stray ./var/data/ tree appears.
// Concatenate onto 'sqlite:///' instead.
$db = Database::create('postgres://localhost:5432/mydb', username: 'user', password: 'pass');
$db = Database::create('mysql://localhost:3306/mydb', username: 'root', password: 'secret');
$db = Database::create('mssql://localhost:1433/mydb', username: 'sa', password: 'pass');
$db = Database::create('sqlserver://localhost:1433/mydb', username: 'sa', password: 'pass');
$db = Database::create('firebird://localhost:3050/path/to/db.fdb', username: 'SYSDBA', password: 'masterkey');

// Firebird driver selection: auto-mode prefers ext-interbase, then silently
// falls back to pdo_firebird when the native extension is absent. Force a
// driver when the auto-choice is wrong — e.g. ext-interbase present but broken
// (the macOS + Firebird 5 clumplet case): set TINA4_FIREBIRD_DRIVER=pdo (or
// =interbase) app-wide, or pin one connection with a ?driver=pdo query param.
$db = Database::create('firebird://localhost:3050/db.fdb?driver=pdo', username: 'SYSDBA', password: 'masterkey');

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
$db->insert(string $table, array $data): DatabaseResult   // single row OR list-of-rows batch
$db->update(string $table, array $data, string|array $filterSql = '', array $params = []): DatabaseResult
$db->delete(string $table, string|array $filter = '', array $whereParams = []): DatabaseResult
$db->truncate(string $table): DatabaseResult      // remove every row, explicitly
$db->primaryKey(string $table): array             // introspected PK columns (cached)
    // A WRITE WITH NO FILTER IS AN ERROR, not a full-table operation (3.13.94).
    // update() with no filter takes the primary key out of $data and uses it as the
    // WHERE clause; with neither a filter nor the COMPLETE primary key in $data it
    // throws DatabaseException instead of overwriting every row. delete() with no
    // filter throws too -- truncate() is the explicit whole-table spelling.
    // primaryKey() returns a LIST: a key may span several columns, and EVERY key
    // column goes into the WHERE. A composite key keyed on only its first column
    // would match every row sharing that value.
    // Both filter forms work on update() and delete(): ['id' => 1] or 'id = ?', [1].
    // insert/update/delete return a DatabaseResult (parity with Python master + Node):
    // a truthy object carrying ->affectedRows and ->lastId (lastId set on insert only;
    // null for update/delete). `if ($db->insert(...))` still works (objects are truthy).
    // FAIL LOUD like execute()/fetch(): a bad statement RAISES — never a falsy return.
    // affectedRows is best-effort (accurate on SQLite/PG/MySQL/MSSQL/Firebird; 0 on the
    // PDO-fallback/ODBC adapters that don't report a count).
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
$url->engine;    // 'postgres' — the CANONICAL engine, not the raw scheme.
                 // There is no ->scheme property; a scheme alias such as
                 // 'postgresql' or 'pgsql' resolves to the engine 'postgres'.
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
$user->where($filterSql, $params, $limit, $offset, $include, $orderBy): array
$user->all($limit, $offset): array
$user->count($conditions, $params): int
$user->findOrFail($id): static
$user->withTrashed($filter, $params, $limit, $offset): array
$user->scope($name, $filterSql, $params): void  // Registers reusable named method: User::active()
$user->createTable(): bool

// Static methods
User::create($data): static|false
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
// $algorithm=null resolves TINA4_JWT_ALGORITHM, then HS256.
Auth::getToken($payload, $secret=null, $expiresIn=60, $algorithm=null): string
Auth::validToken($token, $secret=null, $algorithm=null): ?array
Auth::getPayload($token): ?array
Auth::refreshToken($token, $expiresIn=60): ?string
Auth::hashPassword($password, $salt=null, $iterations=260000): string  // PBKDF2-SHA256, $ delimiter
Auth::checkPassword($password, $hash): bool
Auth::validateApiKey($provided, $expected=null): bool  // reads TINA4_API_KEY from env
Auth::authenticateRequest($headers, $secret=null, $algorithm=null): ?array  // Bearer JWT, falls back to API key
Auth::ensureDevSecret($cwd=null): ?string  // dev-secret bootstrap (run once at boot)
Auth::JWT_LEEWAY_SECONDS  // 60 — clock skew tolerated on the nbf claim
```

**`TINA4_JWT_ALGORITHM` — supported algorithms.** `HS256` (default), `HS384`,
`HS512` (HMAC) and `RS256` (RSA). **HMAC is the standard and is zero-dependency
in all four frameworks** — PHP signs it with core `hash_hmac`, Python with
`hmac`+`hashlib`, Ruby with `OpenSSL::HMAC`, Node with `node:crypto`. No
extension is needed to sign or verify a Tina4 JWT.

**RS256 is opt-in, and it works in all four.** PHP reaches it through
`ext-openssl`, which composer **suggests** rather than requires; Ruby through
stdlib `OpenSSL::PKey::RSA` (no gem); Node through builtin `node:crypto`; Python
through the `cryptography` package, which Tina4 never declares — Python raises
`RS256UnavailableError` naming `pip install cryptography` if you ask for RS256
without it. On a PHP build with no ext-openssl, `getToken()` / `validToken()`
throw `\RuntimeException` naming the extension, the install command, and the
zero-dependency HMAC alternative — checked at the point of use, never at boot.
Reach for RS256 when a **verifier must not be able to mint**: HMAC is symmetric,
so every verifier holds the signing secret.

An unsupported value throws `\InvalidArgumentException` naming the supported set
rather than silently downgrading to HS256. The header's `alg` always names the
digest that actually signed, and `validToken()` **pins** it: a token whose header
advertises a different algorithm (including `alg: "none"`) is rejected before any
signature work, which blocks algorithm substitution across services that share a
signing secret. An explicit `$algorithm` argument beats the env var.

**`nbf` (not-before) is validated.** `validToken()` refuses a post-dated token
until its `nbf` passes, tolerating `Auth::JWT_LEEWAY_SECONDS` (60s) of clock skew
so a token minted on another host is not rejected for a one-second difference. A
token with **no** `nbf` claim is unconstrained, so existing tokens are unaffected.
`getToken()` never stamps an `nbf` — a caller wanting a post-dated token puts its
own `nbf` in the payload (parity with the Python and Node masters).

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

Backends: file, redis, valkey, mongodb, memcached, database.

**An unrecognised backend name RAISES at startup**, naming the bad value and the valid ones. It used to fall through to the file backend silently, so a typo in `TINA4_SESSION_BACKEND` produced a running app writing sessions to local disk while the operator believed they were in Redis. The name is normalised (trimmed + lowercased), so ` Redis ` resolves; unset or blank still means file. Aliases: `filesystem`, `mongo`, `memcache`, `db`.

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

### DocStore — pymongo-style document store (zero-config SQLite fallback)

`Tina4\getCollection($name)` returns a Mongo-style collection. When a Mongo URI is configured (and the `ext-mongodb` driver is present) it is a real Mongo collection; otherwise it is a `Tina4\SqliteCollection` backed by a local SQLite file using JSON1. The call sites are identical either way — only the backend differs — so you develop against a zero-dependency local store and switch to MongoDB in production by setting one env var. (The DocStore module lives at `Tina4/Bootstrap/DocStore.php` and autoloads via the composer `files` map, like `Tina4/Bootstrap/MCP.php` — run `composer dump-autoload` after a fresh checkout. Anything in `Tina4/Bootstrap/` is included eagerly because it declares free functions or constants that must exist without a class reference. It sits one directory below the `Tina4\` PSR-4 root **on purpose**: a file at `Tina4/DocStore.php` is also the path PSR-4 derives for a class named `Tina4\DocStore`, so referencing that name — `class_exists('Tina4\DocStore')` — would send composer's autoloader back to the already-included file for a second `include` and fatal on the redeclare. PHP early-binds top-level functions and interfaces at compile time, so no runtime guard inside the file can prevent that; keeping the path un-derivable is the fix. Do not move these files up into `Tina4/`; `tests/LazyFeatureLoadingTest.php` fails if you do.)

```php
use function Tina4\getCollection;
use function Tina4\isServerless;
use Tina4\ObjectId;

$orders = getCollection('orders');
$res = $orders->insertOne(['customer_id' => 1, 'total' => 9.99, 'status' => 'new']);
$orders->findOne(['_id' => $res->getInsertedId()]);   // the driver's spelling
$orders->findOne(['_id' => $res->insertedId]);        // the uniform Tina4 spelling - same id
$orders->updateOne(['_id' => $res->insertedId], ['$set' => ['status' => 'shipped']]);
foreach ($orders->find(['total' => ['$gt' => 5]])->sort('total', -1)->limit(10) as $doc) {
    // ...
}
$docs = $orders->find(['total' => ['$gt' => 5]])->sort(['total' => -1])->toList();   // toArray() also works
$orders->countDocuments(['status' => 'shipped']);
isServerless();   // true when running on the SQLite fallback
```

Filter operators: equality, `$in`, `$nin`, `$gt`, `$gte`, `$lt`, `$lte`, `$ne`, `$exists`, `$regex`, implicit AND, `$or`, `$and`, and dotted nested keys (`addr.city`). Updates: `$set`, `$unset`, `$inc`, replace, upsert. Cursors: `sort`, `limit`, `skip`, projection, `toArray()` and `toList()`. Values round-trip (DateTime to/from ISO-8601, `ObjectId` to/from 24-hex) and stay queryable via `json_extract`. Non-goals: aggregation pipelines, `$elemMatch`, geo queries.

**One spelling, both providers (ADR-0035).** `$cursor->toList()` and the result property `$res->insertedId` are the uniform Tina4 spellings and work on the SQLite fallback AND on a real MongoDB. The driver has neither, so on the Mongo path `getCollection` returns `Tina4\DocStoreDelegator`, which adds them and forwards the entire driver surface untouched (`aggregate`, `bulkWrite`, `createIndex`, `watch`, `withOptions`, sessions and transactions all stay reachable). The driver spellings `toArray()` and `getInsertedId()` are unchanged and equally valid - this is additive, and the two always return the same value. Call `->unwrap()` for the bare driver object.

**The cursor chain is DEFERRED and works on both providers (ADR-0036).** `find()` issues no query - it returns a chainable query that accumulates `sort`/`limit`/`skip` and runs once, when you iterate it or call `toArray()`/`toList()`. On the Mongo path that is `Tina4\DocStoreQuery`, which turns the chain into the `find($filter, $options)` call PHP's driver actually wants; `MongoDB\Driver\Cursor` has no `sort`/`limit`/`skip` of its own, because by the time the driver returns a cursor the query has already executed. Before ADR-0036 the documented chain above fatalled the moment `TINA4_MONGO_URI` was set. Nothing is buffered, so a large result still streams, and iterating the same query twice re-runs it on both providers.

**`sort()` takes all three spellings, on both providers.** `sort('total', -1)`, `sort(['total' => -1])` (a Mongo sort document) and `sort([['total', -1]])` (a list of pairs) are equivalent everywhere. The map form used to raise a `TypeError` on the fallback while working on the driver.

Selection and configuration:
- `TINA4_MONGO_URI` — app-wide Mongo URI. Falls back to `TINA4_SESSION_MONGO_URI`, then the legacy `TINA4_SESSION_MONGO_URL`. When one is set and the driver is present, `getCollection` returns a real Mongo collection.
- `TINA4_DOC_STORE_PATH` — SQLite file for the fallback store (default `data/tina4_docstore.db`).

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
Auth::getToken(array $payload, string|int|null $secret = null, int $expiresIn = 60, ?string $algorithm = null): string
Auth::validToken(string $token, ?string $secret = null, ?string $algorithm = null): ?array
Auth::getPayload(string $token): ?array
```

`$algorithm = null` resolves `TINA4_JWT_ALGORITHM`, then `HS256`. Supported:
`HS256`/`HS384`/`HS512` (the zero-dependency standard, core `hash_hmac`) plus
`RS256` (opt-in, needs ext-openssl — `\RuntimeException` at the point of use when
it is absent). An unsupported value throws `\InvalidArgumentException`.
`validToken()` pins the header's `alg` to the configured algorithm and honours
the `nbf` claim (see the Auth section above).

### Api — External HTTP client

```php
$api = new \Tina4\Api(string $baseUrl = "", string $authHeader = "", int $timeout = 30, bool $ignoreSSL = false, ?string $bearerToken = null, ?string $username = null, ?string $password = null, ?array $headers = null, ?bool $verifySSL = null, int $maxRetries = 0, float $retryBackoff = 0.5, ?callable $transport = null, bool $cookies = false)
$api->get(string $path = "", array $params = []): array
$api->post(string $path = "", mixed $body = null, string $contentType = "application/json"): array
$api->put(...); $api->patch(...); $api->delete(string $path = "", mixed $body = null): array
$api->sendRequest(string $method = "GET", string $path = "", $body = null, string $contentType = "application/json"): array
$api->addHeaders(array $headers): void
$api->setBasicAuth(string $username, string $password): void
$api->setBearerToken(string $token): void
// Multipart upload — file on disk OR in-memory bytes (no temp file). NEVER throws.
$api->upload(string $path = "", ?string $filePath = null, string $fieldName = "file", array $extraFields = [], array $headers = [], ?string $fileBytes = null, ?string $filename = null): array
// Streaming download — writes the body to disk in 64KB chunks. Returns {http_code, headers, error, path}; NO body key; path null on error (no file written).
$api->download(string $path = "", ?string $destPath = null, array $params = []): array
```

**Retry/backoff (opt-in, default off):** the constructor accepts `maxRetries` (default `0`) and `retryBackoff` (default `0.5`s base, exponential). When `maxRetries > 0` a transport error or a retryable status (429/500/502/503/504) is retried; 4xx is never retried. A retried non-idempotent request may be re-sent — retries are opt-in for that reason.

**Redirect safety (Security fix, 3.13.69):** the client follows redirects, but strips the `Authorization` header **and** the cookie-jar `Cookie` header on a **cross-origin hop** (different scheme/host/port) — same-origin redirects keep them. This matches the Python master. It is implemented with a manual redirect loop (`follow_location=0` on the stream context, `Location` read per hop) — NOT `file_get_contents`'s built-in follow. **Correction of a prior claim:** PHP's http stream wrapper DOES auto-follow redirects by default and, before this fix, forwarded `Authorization`/`Cookie` to the cross-origin target (empirically verified against a real two-origin localhost server) — so PHP *was* leaking a bearer token / session cookie on a cross-origin redirect. Zero new dependency (stream wrapper only; ext-curl not required).

**Transport seam (`transport:`):** an injectable callable `fn(string $method, string $url, array $headers, ?string $body, int $timeout): array` returning `{http_code, body, headers, error}`. When set it fully REPLACES the network call, so *application* developers can unit-test code that calls an `Api`. Default `null` = the real network. Tina4's own suite NEVER injects a canned fake (no-mock rule) — its transport-seam test injects a transport that performs REAL socket I/O.

**Cookie jar (`cookies:`):** off by default. When `true`, keeps an in-memory per-client jar — parses `Set-Cookie` (leading `name=value` only, last-write-wins) and sends the accumulated `Cookie` on later requests. Not persisted.

### Migration — Database migrations

```php
$migration = new \Tina4\Migration(DatabaseAdapter $db, string $migrationsDir = "migrations", string $delimiter = ";")
$migration->migrate(): array
```

**How migrations work internally:**

- SQL/PHP files live in the migrations folder. They are discovered in
  **numeric-prefix order** (`9_` before `10_`) and split on the `;` delimiter — a
  plain lexical sort misorders unpadded prefixes (`10` < `9`). A file WITHOUT a
  numeric/timestamp prefix logs a `Log::warning` (its order relative to the
  numbered files is undefined). Both `NNNNNN_name.sql` and `YYYYMMDDHHMMSS_name.sql`
  patterns sort correctly.
- State is tracked in the `tina4_migration` table (auto-created per engine). The
  canonical column set is `id, migration_name VARCHAR(500) NOT NULL UNIQUE,
  description VARCHAR(500), batch INTEGER NOT NULL DEFAULT 1, executed_at
  VARCHAR(50) NOT NULL, passed INTEGER NOT NULL DEFAULT 1` — identical across all
  four Tina4 frameworks. A migration is **applied** when a row exists for it with
  `passed = 1` (the applied-read is `WHERE passed = 1`). `migrate()` writes **only
  `passed = 1` rows**: on a failure the file's transaction is rolled back and **no
  row is written** for it (it is NOT recorded as `passed = 0`), nothing is deleted,
  and `migrate()` collects the error and **stops** (the explicit `bin/tina4php
  migrate` CLI then exits non-zero). The public `recordMigration($name, $batch,
  $passed)` API can write a `passed = 0` row; any `passed = 0` row (including one
  carried over from a v2 table) is treated as **not applied**, so it is reported
  pending. A leftover `passed = 0` row (a prior failure, or one carried over from
  a v2 table) re-applies cleanly on the next `migrate()`: the success path routes
  through `recordMigration()`, which DELETEs any existing row for the
  `migration_name` before writing the fresh `passed = 1` row (delete-before-insert),
  so the stale `passed = 0` row is superseded instead of colliding on the unique
  `migration_name`. The table therefore holds **at most one row per
  `migration_name`** (latest state wins). The v2->v3 upgrade logs a note that any
  `passed = 0` rows will re-apply on the next migrate. Already-applied files stay
  applied — fix the bad file and re-run.
- **Each migration FILE is wrapped in its own transaction** (`startTransaction()`
  … `commit()`): on a failure the file rolls back, the error is logged, and the
  run halts at that file. Already-applied files stay applied — fix the bad file
  and re-run.
- **Atomicity caveat:** per-file transactions are truly atomic on engines with
  **transactional DDL (PostgreSQL, and SQLite)**. SQLite's DDL is transactional
  too (autocommit is off inside `startTransaction()`), so a multi-statement
  migration that fails midway on SQLite rolls back cleanly, including any
  `CREATE TABLE` that already ran earlier in the same file — proven by
  `tests/MigrationContractTest.php::testSqliteMultiStatementFailureRollsBackDdl`.
  MySQL and Firebird **auto-commit DDL**, so the same failure on those two
  engines leaves earlier statements applied — keep one logical change per file
  there. `CREATE TABLE` and `ALTER TABLE … ADD` are made idempotent on
  Firebird/MSSQL (existence-checked via `tableExists()` / `RDB$RELATION_FIELDS`)
  so a re-run with a raw DDL statement skips the already-existing object instead
  of erroring.
  SQLite/MySQL/PostgreSQL support `IF NOT EXISTS` and are left to the engine.

**Auto-run on startup (`TINA4_AUTO_MIGRATE`, default on).** When a `migrations/`
folder exists (with at least one `.sql` file), `App::start()` applies pending
migrations during boot — after the DB is bound + routes discovered, before
serving — so the schema is current with no manual `tina4 migrate` step. It is
**non-breaking**: a failed migration is logged (`Log::error`) and the service
still starts (a bad migration must never take the backend down); the hook runs
at most once per process. Set `TINA4_AUTO_MIGRATE=false` (also `0`/`no`/`off`)
to disable — e.g. multi-instance production that migrates as a separate deploy
step, where concurrent first-apply can race. The explicit `bin/tina4php migrate`
CLI is unaffected and stays **fail-fast**: any migration error prints and exits
non-zero (`exit(1)`) so CI gets a failing exit code.

### Queue — Job queue with pluggable backends

```php
use Tina4\Queue;

$queue = new Queue(string $backend = 'file', array $config = [], string $topic = 'default');
$queue->push(mixed $payload, int $priority = 0, int $delay = 0): string  // returns job id
$queue->pop(): ?Job          // a Job, not an array - see the note below
$queue->popBatch(int $count): array   // array<int, Job>
$queue->popById(string $id): ?Job
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
$queue->close(): void  // Release the backend connection. No-op on the file backend, idempotent, discard the queue afterwards.
$queue->getTopic(): string

// Job methods. pop(), popBatch() and popById() return Job objects (they used to
// return the backend's raw array, so $queue->pop()->fail('boom') was a fatal in
// PHP while the identical line worked in Python, Ruby and Node - ADR-0024).
// NON-BREAKING: Job implements ArrayAccess, so $job['id'] / $job['payload'] and
// every other existing array READ still resolves, an empty queue still returns
// null, json_encode($job) produces the toHash() shape (JsonSerializable), and
// new \Tina4\Job($queue->pop(), $queue, $topic) still works. WRITES are refused:
// $job['x'] = 1 throws \LogicException - a job is a claim on a message, not a
// bag; use complete()/fail()/retry() to change it.
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
// Output (v3.13.39): with TINA4_LOG_OUTPUT unset (default), stdout is ALWAYS on but the
// log FILE (tina4.log + error.log) is written ONLY in dev (TINA4_DEBUG truthy). Production /
// containers are stdout-only — no file to bloat the writable layer (12-factor). Explicit
// TINA4_LOG_OUTPUT=file|both, OR an explicit TINA4_LOG_FILE path, still forces a file.
```

**Format is TEXT by default (2026-08-01, all four frameworks).** Only
`TINA4_LOG_FORMAT=json` selects JSON — the implicit production->JSON switch is
DELETED (it meant four different things across the four frameworks, so one .env
produced four formats). `configure(development: ...)` now affects CONSOLE
PRESENTATION only (ANSI colour), never the format. An object/array passed as the
MESSAGE is still JSON-encoded inline inside the text line.

**Config is read on FIRST USE.** `Log::configure()` is optional: a worker, CLI
tool, cron script or test that logs without booting a server resolves the same
`TINA4_LOG_*` the server does. `configure()` remains the explicit override and
wins for the rest of the process.

**`TINA4_LOG_STRICT`** — when truthy a log-write failure RAISES
(`\RuntimeException`) instead of being swallowed. Default stays log-and-degrade:
a failing log sink must never be the reason a request dies.

**Breaking (2026-08-01): `TINA4_LOG_MAX_SIZE` and `TINA4_LOG_KEEP` are DELETED.**
They were legacy aliases, and the size alias took MEGABYTES while the name it
aliased takes BYTES. Migration: `TINA4_LOG_MAX_SIZE=10` ->
`TINA4_LOG_ROTATE_SIZE=10485760`, `TINA4_LOG_KEEP=n` -> `TINA4_LOG_ROTATE_KEEP=n`.

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
| `$app->background(callable $callback, float $interval = 1.0): \Tina4\BackgroundTask` | Register a periodic task. Callback takes no arguments. Interval is in seconds. Returns a `BackgroundTask` HANDLE — call `->stop()` to end and deregister it. Under a non-persistent SAPI (php-fpm/apache/`php -S`) it warns LOUDLY with the remedy (the tick loop lives only in the persistent `tina4 serve` socket server), never a silent drop. |
| `$handle->stop(): bool` | Stop this task and DEREGISTER it. Idempotent — `true` the first time it removes a live task, `false` thereafter. |
| `$app->stopBackground(callable $callback): bool` | Stop a registered task and DEREGISTER it by callable identity (without a handle). Only the FIRST registration of that callable is removed. Works before and after `run()` (it also stops the live tick). Idempotent; returns `false` when nothing matched. |
| `$app->backgroundTaskCount(): int` | How many background tasks are currently REGISTERED (stopped ones are already gone). |

`background()` returns a stop-handle — the ONE background surface shared with
Python/Ruby/Node (a handle with a boolean `stop()` plus a count). **Breaking
(3.13.99): it used to return `$this` (fluent); split a chained
`->background(a)->background(b)` into two calls.**

```php
$handle = $app->background(static fn() => processOrders($queue), 2.0);
// ...later, before or during run():
$handle->stop();                    // true — task ended and deregistered
$app->backgroundTaskCount();        // 0
// stopBackground($callback) still stops by identity when you have no handle.
```

Server-level access (advanced):
```php
$server->onTick(callable $callback, float $interval = 1.0): void
$server->stopTick(callable $callback): bool   // stop + deregister one tick (by callable identity)
$server->tickCallbackCount(): int             // currently-registered tick callbacks
```

### AI — Detect AI coding assistants and scaffold context files

Supports: Claude Code, Cursor, GitHub Copilot, Windsurf, Aider, Cline, OpenAI Codex.

```php
// Check if a specific tool's context file already exists in $root.
// $tool is one entry from AITools::$AI_TOOLS (has 'name', 'context_file', 'config_dir').
AITools::isInstalled(string $root, array $tool): bool

// Print the numbered tool menu (with [installed] markers) and read a
// comma-separated selection (or "all") from STDIN. Returns the raw line.
AITools::showMenu(string $root = "."): string

// Install context files for the tools listed in $selection ("1,3,5" or "all").
// Returns relative paths of created/updated files.
AITools::installSelected(string $root, string $selection): array

// Install context files for ALL known AI tools (non-interactive).
AITools::installAll(string $root = "."): array

// Generate the tool-specific Tina4 context body (used by installSelected).
AITools::generateContext(string $toolName = 'claude-code'): string
```

Example:
```php
// Interactive: show menu, read selection, install
$selection = AITools::showMenu('.');
$created = AITools::installSelected('.', $selection);

// Non-interactive: install everything
$created = AITools::installAll('.');

// Check status — re-rendering the menu shows [installed] markers per tool
AITools::showMenu('.');
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

// Check if TINA4_DEBUG is enabled
ErrorOverlay::isDebugMode(): bool
```

The overlay is dev-only (gated on `isDebugMode()`/`TINA4_DEBUG`). The production 500 is NOT rendered here — `Router::renderError` renders `errors/500.twig` with an empty `error_message` (CWE-209), so the exception detail stays in the server log only. Sensitive request fields (Authorization / Cookie / Set-Cookie headers and password-like body/param keys) are redacted even in the overlay, the frame count is capped, and the router guards the render.

Example:
```php
try {
    $handler($request, $response);
} catch (\Throwable $e) {
    if (ErrorOverlay::isDebugMode()) {
        echo ErrorOverlay::renderErrorOverlay($e, $_SERVER);   // dev only
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

// Assertion builders (DESCRIPTORS — named expect* so they never collide with the
// xUnit assert* the PHPUnit suites use)
Testing::expectEqual(array $args, mixed $expected): array
Testing::expectRaises(string $exceptionClass, array $args): array
Testing::expectTrue(array $args): array
Testing::expectFalse(array $args): array

// Run all registered tests
Testing::runAll(bool $quiet = false, bool $failfast = false): array
// Returns: ['passed' => int, 'failed' => int, 'errors' => int, 'details' => array]

// Discover @tests docblocks from an EXPLICIT tests dir (default 'tests'). Args are
// parsed as LITERALS — never eval'd — and only files under the tests dir are loaded.
Testing::discover(string $path = 'tests'): int

// Reset the test registry
Testing::reset(): void
```

Run inline @tests from the CLI (real exit code — non-zero on any failure):

```bash
bin/tina4php test     # discovers @tests in tests/, runs them, then the PHPUnit suite
```

Example:
```php
Testing::tests(
    [
        Testing::expectEqual([5, 3], 8),
        Testing::expectEqual([0, 0], 0),
        Testing::expectRaises('InvalidArgumentException', [null]),
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

### SQLTranslator — Cross-database SQL dialect translation

Translates portable SQL into dialect-specific syntax. Supports Firebird, MSSQL, MySQL, PostgreSQL, and SQLite.

```php
// Full dialect translation (applies all relevant rules)
SQLTranslator::translate(string $sql, string $dialect): string

// Individual translations
SQLTranslator::limitToRows(string $sql): string          // LIMIT/OFFSET -> ROWS X TO Y (Firebird)
SQLTranslator::limitToTop(string $sql): string           // LIMIT -> TOP N (MSSQL)
SQLTranslator::booleanToInt(string $sql): string         // TRUE/FALSE -> 1/0
SQLTranslator::ilikeToLike(string $sql): string          // ILIKE -> LOWER() LIKE LOWER()
SQLTranslator::concatPipesToFunc(string $sql): string    // || -> CONCAT()
SQLTranslator::autoIncrementSyntax(string $sql, string $dialect): string
SQLTranslator::placeholderStyle(string $sql, string $style): string  // ? -> :1,:2 or %s
SQLTranslator::namedToPositional(string $sql, array $params): array  // :name -> ?, reorders params (used by MySQL/MSSQL/Firebird/Postgres adapters; skips string literals + comments; duplicate names bind once per occurrence)
SQLTranslator::hasReturning(string $sql): bool
SQLTranslator::extractReturning(string $sql): array      // ['sql' => ..., 'columns' => [...]]

// Custom function mapping
SQLTranslator::registerFunction(string $name, callable $mapper): void
SQLTranslator::applyFunctionMappings(string $sql): string
SQLTranslator::clearFunctions(): void

// Query result caching
SQLTranslator::setCacheTtl(int $seconds): void
SQLTranslator::queryKey(string $sql, array $params = []): string
SQLTranslator::cacheGet(string $key): mixed
SQLTranslator::cacheSet(string $key, mixed $value, int $ttl = 0): void
SQLTranslator::remember(string $key, int $ttl, callable $factory): mixed
SQLTranslator::cacheSweep(): int
SQLTranslator::cacheClear(): void
SQLTranslator::cacheSize(): int
```

Example:
```php
// Translate for Firebird (LIMIT->ROWS, TRUE->1, ILIKE->LOWER LIKE)
$sql = SQLTranslator::translate(
    "SELECT * FROM users WHERE active = TRUE AND name ILIKE '%alice%' LIMIT 10 OFFSET 5",
    'firebird'
);
// => SELECT * FROM users WHERE active = 1 AND LOWER(name) LIKE LOWER('%alice%') ROWS 6 TO 15

// Register a custom function mapping
SQLTranslator::registerFunction('NOW', fn($sql) => str_ireplace('NOW()', 'CURRENT_TIMESTAMP', $sql));

// Cache expensive query results
$result = SQLTranslator::remember(
    SQLTranslator::queryKey("SELECT * FROM stats", []),
    300,
    fn() => $db->fetch("SELECT * FROM stats")
);
```

### Swagger / OpenAPI

`Tina4\Swagger::generate()` produces an OpenAPI 3.0.3 spec from the route table;
`Swagger::register()` serves the UI at `/swagger` and the spec at
`/swagger/openapi.json`. ORM models registered via `AutoCrud` become reusable
`components.schemas` referenced by `$ref`, and secured routes (write routes,
`->secure()`, or `@secured`) emit a `bearerAuth` security requirement; a
`->noAuth()` write route is documented as public.

| Env var | Purpose |
|---|---|
| `TINA4_SWAGGER_ENABLED` | On/off for the `/swagger` endpoints. Explicit `true`/`false` wins; unset falls back to `TINA4_DEBUG`. Set `false` to DISABLE swagger in any environment; `true` to expose it in production. |
| `TINA4_SWAGGER_SERVERS` | Comma-separated server URLs for the OpenAPI `servers[]` block; falls back to `SWAGGER_DEV_URL`. |
| `TINA4_SWAGGER_UI_CDN` | Base URL for the Swagger UI assets (default `https://cdn.jsdelivr.net/npm/swagger-ui-dist@5`, matching python/ruby/node); point at a self-hosted mirror for air-gapped use. |
| `TINA4_SWAGGER_TITLE` / `_VERSION` / `_DESCRIPTION` | `info` block title, version, description. |
| `TINA4_SWAGGER_CONTACT_EMAIL` / `_LICENSE` | Optional `info.contact.email` and `info.license`. |
| `TINA4_SWAGGER_OPENAPI` | OpenAPI version: `3.0.3` (default) or `3.1` (emits `3.1.0`). |
| `TINA4_SWAGGER_BEARER_FORMAT` | `bearerFormat` on the built-in `bearerAuth` scheme (default `JWT`; e.g. `opaque` for `sk_live_` keys). |
| `TINA4_SWAGGER_API_KEY_NAME` / `_IN` | When the name is set, emit an `apiKeyAuth` scheme with that header/query name; `_IN` is `header` (default) / `query` / `cookie`. |
| `TINA4_SWAGGER_DEFAULT_SCHEME` | Scheme a secured route uses when its `swagger` meta declares no `security` (default `bearerAuth`). |
| `TINA4_SWAGGER_INCLUDE` / `_EXCLUDE` | Comma-separated path-prefix allow-list / deny-list (`/swagger` + `/__dev` are always excluded). |

**Per-route security + reusable schemas (v3.13.42).** Declare per route via the
`swagger` route meta: `security` (a scheme name, a `{name: [scopes]}` map, a list
of maps for OR, or `'public'` to force no auth) and a sibling `scopes` array;
`requestSchema` / `responseSchemas` reference schemas registered with
`Swagger::addSchema(name, schema)`. Register arbitrary schemes (incl. `oauth2`
with scopes) via `Swagger::addSecurityScheme(name, definition)`. Scopes are kept
valid: only `oauth2`/`openIdConnect` schemes carry them; `http`/`apiKey` get `[]`.

### MCP (Model Context Protocol)

The built-in dev MCP server (`Tina4\MCP`) is a two-layer gate: `isEnabled()` is
the capability gate (`TINA4_MCP` explicit, else `TINA4_DEBUG`), and each request
is authorised on the raw socket peer.

| Env var | Purpose |
|---|---|
| `TINA4_MCP` / `TINA4_DEBUG` | Whether MCP is enabled at all (capability gate). |
| `TINA4_MCP_REMOTE` | Set `true` to allow non-loopback MCP callers (still requires a valid token). |
| `TINA4_MCP_TOKEN` | Bearer token authorising a remote MCP request (fallback `TINA4_API_KEY`); accepted as `Authorization: Bearer`, `X-MCP-Token`, or `X-Api-Key`. With no token configured a remote caller is always denied; loopback never needs it. |

## Key Architecture

- **Zero external dependencies** — v3 has no Composer runtime dependencies (only `ext-json`). `ext-openssl` is **suggested**, not required: it is needed only for opt-in RS256 JWT, `mqtts://`, and outbound HTTPS from `Tina4\Api` (PHP's `https://` stream wrapper is registered by that extension). Database extensions are optional and suggested
- **Unified framework** — Everything lives in the `tina4stack/tina4php` package. No separate `tina4php-core`, `tina4php-database`, `tina4php-orm` packages
- **Default server** — Binds to `0.0.0.0:7145` by default
- Routes auto-discovered from `src/routes/`
- ORM with migration support built in
- Twig-compatible templating via built-in `Frond` engine (zero deps)
- SCSS compilation is owned by the `tina4` Rust CLI (grass), not the framework
- JWT auth built in
- Event system (`Events`) for decoupled pub/sub communication
- DI container (`Container`) with factory and singleton registration
- Response cache middleware (`ResponseCache`) for in-memory GET caching with TTL
- CORS middleware (`CorsMiddleware`) and rate limiter (`RateLimiter`)
- Error overlay (`ErrorOverlay`) with syntax-highlighted stack traces in dev mode
- Dev dashboard (`DevAdmin`) with route/request/query/queue/mailbox/WebSocket inspection
- Programmatic HTML builder (`HtmlElement`) with tag helpers
- Inline testing (`Testing`) with assertion builders and test runner
- SQL dialect translation (`SQLTranslator`) for cross-database portability
- Background tasks via `$app->background()` — cooperative periodic callbacks in the event loop (no threads)
- AI assistant detection (`AI`) with context file scaffolding for 7 tools
- Queue system with Kafka, RabbitMQ, and MongoDB backends
- Session handlers for MongoDB, Redis, Valkey, Memcached and SQL databases. `TINA4_SESSION_SAMESITE` env var (default: Lax)
- GraphQL query execution. **Depth guard**: selection-set nesting is bounded by `TINA4_GRAPHQL_MAX_DEPTH` (default `50`; set `<= 0` to disable) — an over-deep query or a circular fragment fails with a structured `"Query exceeds maximum depth of N"` error (counted per selection level AND per fragment spread) instead of overflowing the stack. Resolver exceptions are captured as GraphQL errors — the message is the real cause only in debug mode (`ErrorOverlay::isDebugMode()` / `TINA4_DEBUG`); in production it is a generic `"Internal server error"` (the real cause is logged via `Log::error`, `path` preserved) so a resolver exception never leaks internal state
- SOAP 1.1 / WSDL (`WSDL`, `#[WSDLOperation]`). **DOCTYPE rejected**: a SOAP request containing a `<!DOCTYPE>` (DTD) is rejected with a `Client` fault **before** parsing — SOAP 1.1 §3 forbids DTDs, and this closes the XML entity-expansion (billion-laughs) + external-entity (XXE) attack surface for every parser. **Error masking is debug-gated**: an operation that raises returns a `Server` fault whose `<faultstring>` is the real cause only in debug mode (`ErrorOverlay::isDebugMode()` / `TINA4_DEBUG`); in production it is a generic `"Internal server error"` and the real cause is logged via `Log::error`, so a resolver exception never leaks internal state (DB creds, file paths) to a SOAP client
- WebSocket support. WebSocket backplane for scaling broadcast across instances via Redis/NATS pub/sub (`TINA4_WS_BACKPLANE`, `TINA4_WS_BACKPLANE_URL` env vars) — **wired into the live broadcast path**: every `broadcastWebSocket()`/`broadcastToRoom()` delivers to LOCAL connections first then publishes an envelope `{src,kind,exclude,room,path,+text|b64}` to the shared `tina4:ws` channel (identical shape across all 4 frameworks); sibling instances drain it on the event-loop idle tick and relay to their own LOCAL connections only (origin guard drops our own echo by stable per-process id — no double-delivery, no cluster loop). Backplane failure logs + degrades to local-only, never crashes a broadcast. Broadcasts are resilient: a dead/slow client is pruned and never aborts delivery to the rest. **Security**: optional origin allow-list via `TINA4_WS_ALLOWED_ORIGINS` (comma-separated; empty/unset = allow all — non-breaking; set = reject mismatched/missing Origin with 403 on every upgrade path). **Per-route auth**: a WS route is PUBLIC by default (mirrors GET); mark it secured via an `@secured` handler docblock OR imperatively `Router::websocket($path, $handler, secure: true)` (both set `auth_required`). On EVERY upgrade entry point (`Server::handleWebSocketUpgrade` integrated + `WebSocket::handleNewConnection` standalone), AFTER the origin allow-list and BEFORE accepting the handshake, a secured route extracts and validates a JWT via `Auth::validToken()` — missing/invalid rejects the upgrade (401, never accepted); public routes always pass. Three token transports (checked in order, see `WebSocket::wsToken()`): `Authorization: Bearer <jwt>` header (server/CLI/mobile), the `Sec-WebSocket-Protocol: bearer, <jwt>` subprotocol (browser `new WebSocket(url, ['bearer', token])` — echoed back as the accepted subprotocol), and `?token=<jwt>` query param. The verified payload is exposed as `$connection->auth` (null on public routes). Helpers: `WebSocket::wsToken()` / `WebSocket::wsAuthorized()` (mirror Python's `ws_token` / `ws_authorized`). **Idle reaper**: `TINA4_WS_IDLE_TIMEOUT` (seconds; 0/unset = disabled) closes connections idle past the timeout. RFC 6455 fragmented messages (`OP_CONTINUATION`) are reassembled before dispatch. Rooms API: `$ws->joinRoom($clientId, $room)`, `$ws->leaveRoom($clientId, $room)`, `$ws->broadcastToRoom($room, $msg, $excludeIds?)`, `$ws->getRoomConnections($room)`, `$ws->roomCount($room)`
- Swagger/OpenAPI spec generation
- Internationalisation (`I18n`)
- Messenger (.env driven SMTP/IMAP). IMAP reads **fail loud**: `inbox()`/`read()`/`unread()`/`search()`/`folders()` LOG and RAISE `Tina4\MessengerConnectionError` (extends `\RuntimeException`) on a connection/auth/protocol failure instead of swallowing it into an empty result — a *successful* fetch from a genuinely empty mailbox still returns empty (`[]`/`null`/`0`) normally. `send()` is unchanged (returns `{success, message, id}`). **Cross-framework contract:** `inbox(folder, limit, offset)` takes the folder FIRST (same order in all four); the `uid` field is a **STRING** everywhere (the Python master emits `str(uid)`), so a strict `=== 1` comparison must become `=== '1'` — `read()`/`markRead()` still accept `string|int`; and `read()` of a non-existent UID returns a **falsy** value (`null` here, `{}` in Python, `nil` in Ruby, `null` in Node) rather than raising, so `if (!$message)` is the portable missing-message check. Real SMTP + IMAP round-trips are covered against a live GreenMail in `tests/MessengerImapGreenMailTest.php` (ports 3025/3143; `TINA4_TEST_SMTP_*` / `TINA4_TEST_IMAP_*` to relocate)
- CLI scaffolding: `bin/tina4php generate model/route/migration/middleware`. There
  is no `composer tina4` script - composer defines exactly four (`serve`, `start`,
  `test`, `lint`) and `composer tina4 ...` errors out
- Production server: `bin/tina4php serve --production` (OPcache auto-config)
- Frond pre-compilation for 2.8x template render improvement
- DB query caching: request-scoped auto cache **off by default — opt-in via `TINA4_AUTO_CACHING=true`** (TTL `TINA4_AUTO_CACHING_TTL=5`s) dedupes identical reads within a request and flushes on writes. It is OFF by default because an on-by-default request cache is a footgun: a `SELECT MAX(id)` (or generator read) right before an INSERT in the same request returns a cached pre-write value → duplicate primary keys, and any read-after-write in one request shows stale state — so turn it on only for read-heavy endpoints. Persistent cross-request cache is also opt-in via `TINA4_DB_CACHE=true` (TTL `TINA4_DB_CACHE_TTL=30`s) routed through the unified backend set via `TINA4_DB_CACHE_BACKEND` (memory/file/redis/valkey/memcached/mongodb/database) + `TINA4_DB_CACHE_URL` so instances share one cache with global write-invalidation; `cacheStats()` reports `mode` (request/persistent/off) and `backend`, `cacheClear()`
- ORM relationships: `hasMany`, `hasOne`, `belongsTo` with eager loading (`include:`)
- Queue backends: file (default), RabbitMQ, Kafka, MongoDB. **Reservation/visibility timeout** (file + MongoDB): a popped job is reserved for `TINA4_QUEUE_VISIBILITY_TIMEOUT` seconds (default 300; `visibilityTimeout` constructor option; `<= 0` disables) — if the consumer dies before `acknowledge()`/`failJob()`, the next `dequeue()` reclaims it (incrementing `attempts`, dead-lettering past `maxRetries`), so a crashed/evicted consumer never strands a job. RabbitMQ/Kafka delegate redelivery to the broker.
- Cache backends (`Tina4\Cache`): unified set across response/KV and persistent DB cache — `memory` (default), `file`, `redis`, `valkey`, `memcached`, `mongodb`, `database` — selected via `TINA4_CACHE_BACKEND` (+ `TINA4_CACHE_URL`/credentials); falls back to the file backend if a backend is unreachable
- Session handlers: file, Redis, Valkey, MongoDB, Memcached, database. `TINA4_SESSION_SAMESITE` env var controls SameSite attribute (default: Lax). The MongoDB handler reads `TINA4_SESSION_MONGO_URI` (legacy alias `_URL`), `TINA4_SESSION_MONGO_DB` (default `tina4`) and `TINA4_SESSION_MONGO_COLLECTION` (default `sessions`); an explicit constructor option beats the environment
- QueryBuilder with NoSQL/MongoDB support (`toMongo()`)
- WebSocket backplane (Redis/NATS pub/sub) for horizontal scaling — wired into the live broadcast path with an origin guard + local-first delivery (see the WebSocket bullet above)
- SameSite=Lax default on session cookies (`TINA4_SESSION_SAMESITE`)
- `tina4 deploy docker` generates Dockerfile and .dockerignore
- Gallery: 7 interactive examples with Try It deploy at `/__dev/`
- Race-safe `getNextId()` with atomic sequence table (`tina4_sequences`) for SQLite/MySQL/MSSQL; PostgreSQL auto-creates sequences
- Frond template engine optimizations: pre-compiled regexes, lazy loop context (copy-on-write), filter chain caching, path split caching, inline common filters (11-15% speedup)
- SSE/Streaming via `$response->stream()` — Server-Sent Events support for real-time data push. Pass a generator callable; framework handles chunked transfer encoding, `text/event-stream` content type, and connection keep-alive. Hardened: the stream stops cleanly on client disconnect (`connection_aborted()`) and a generator that raises mid-stream is logged via `Log::error` and ends cleanly — the request worker never crashes
- Tests: **5,008 executed, 16,428 assertions, 0 failures, 0 skipped** - measured
  2026-08-06 on Ubuntu 24.04.4 LTS x86_64, PHP 8.3.6, against live services with
  `TINA4_REQUIRE_SERVICES=1`, exit code 0. That is the ORDINARY single-pass run:
  the nine skips that used to need two extra passes now run inside it. (The run
  also reports 21 PHPUnit Deprecations, which are pre-existing and come from
  tests untouched by that work.)

  **Firebird IS covered** - live Firebird 5.0.4 on port 3050, reached through
  native ext-interbase and pdo_firebird. An earlier note here said "Firebird
  excluded by design", which was false: the server had been up the whole time.

  The nine skips that stood here until 2026-08-06 are gone, and none was closed
  by relaxing an assertion. Each newly-running case was proved able to FAIL by
  mutating what it covers:

  * **Four "throws when ext-X is missing" cases** (ext-pgsql, ext-mysqli,
    ext-interbase, ext-mongodb) skipped whenever the extension WAS loaded, so
    the better the machine the less they tested. PHP cannot unload an extension
    in-process, so `tests/PhpChild.php` CREATES the absence: a real php
    subprocess with `PHP_INI_SCAN_DIR` and `-c` pointed at filtered copies of
    this host's conf.d AND php.ini, so the shared object is never dlopen'd.
    Every case asserts the instrument first - including an EXACT extension count
    taken from a baseline child - and a negative control proves a child that
    KEPT the extension does not report it missing.
  * **Two "a write really fails" cases** need a real EACCES / SQLITE_READONLY,
    which root does not get from `chmod 0400` because of CAP_DAC_OVERRIDE. They
    re-run themselves under `setpriv --securebits=+noroot,+noroot_locked
    --bounding-set=-all --inh-caps=-all`, dropping the CAPABILITY while staying
    uid 0 so the repo under `/root` stays readable - dropping to an unprivileged
    uid instead would deny directory TRAVERSAL and pass for the wrong reason.
    The parent proves CapEff really reached 0 and that the child ran one
    genuinely asserting test, since a skipped child also exits 0.
  * **Three Firebird migration cases had never executed anywhere.**
    `MigrationFootgunsLiveEngineTest` was the only file resolving its own
    `TINA4_TEST_FIREBIRD_HOST/_PORT/_PATH`, and it decided the database existed
    with `file_exists()` on a SERVER-side path - so with Firebird in a container
    the file was never visible to the client and the guard fired every time. It
    now reads the canonical `TINA4_TEST_FIREBIRD_URL` like the other thirteen
    live Firebird tests and lets the CONNECTION be the existence check.

  `RequireServicesGate` gates Firebird **per run** rather than by a flat keyword
  (`CONDITIONAL_SERVICE_KEYWORDS`, armed by `TINA4_TEST_FIREBIRD_URL`). The
  gated environments genuinely disagree: the lab and the CI `firebird:` job run
  a live server, while the main CI `test:` job runs this same suite with the
  gate armed and deliberately provides none. A flat include would fail that job
  for a service it never claimed to provide; a flat exclude lets real Firebird
  skips pass green where a server does exist.

  On a PHP build where an extension is compiled STATICALLY (measured: macOS
  Homebrew PHP 8.5.7 has pgsql and mysqli built in, no `extension=` line), its
  absence cannot be created and that one case skips with a reason saying so.
  Every Debian/Ubuntu build - the lab and CI included - ships them as shared
  objects, so all four run there.

## Links

- Website: https://tina4.com
- GitHub: https://github.com/tina4stack/tina4-php

## Tina4 Maintainer Skill
Always read and follow the instructions in .claude/skills/tina4-maintainer/SKILL.md when working on this codebase. Read its referenced files in .claude/skills/tina4-maintainer/references/ as needed for specific subsystems.

## Tina4 Developer Skill
Always read and follow the instructions in .claude/skills/tina4-developer-php/SKILL.md when building applications with this framework. Read its referenced files in .claude/skills/tina4-developer-php/references/ as needed.

## Tina4-js Frontend Skill
Always read and follow the instructions in .claude/skills/tina4-js/SKILL.md when working with tina4-js frontend code. Read its referenced files in .claude/skills/tina4-js/references/ as needed.

## The Uniform Plan (cross-framework audit + consolidations)

Tina4 is one framework in four languages, so the feature-by-feature audit and the
contract-fixture consolidations live in ONE framework-agnostic place, not per
repo: **`tina4-documentation/plan/v3/`** (the `tina4-documentation` repo, `main`
branch). Cite plan docs by repo-prefixed path.

- `plan/v3/98-feature-audit.md` - the master audit tracker: audit every feature,
  pick the best implementation (ADR-0004 "best implementation prevails"), park a
  plan. Planning first; implementation follows per feature.
- `plan/v3/features/NNN-*.md` - one parked plan per feature: the chosen pattern,
  the methodology, and the tests to write.
- `plan/v3/fixtures/*_contract.json` + `plan/v3/CONTRACT-MAP.md` - the executable
  consolidations. The SAME bytes drive the contract runners in all four
  frameworks, mapped feature -> fixture -> ADR -> proven/owed.
- `plan/v3/DECISIONS.md` + `plan/v3/decisions/` - the ADR log. Consult it before
  changing any cross-framework contract, and supersede an ADR explicitly rather
  than silently. `MASTER-SPEC.md` is the feature source-of-truth, but its NUMBERS
  are stale; the fixtures, `CONTRACT-MAP.md`, and
  `scripts/audit-contract-fixtures.py` carry current truth.

This repo's own `plan/` holds LANGUAGE-SPECIFIC task plans (`PARITY.md`,
`SCAFFOLDING.md`, `TESTS.md`, `AI-CONTEXT.md`, and per-task files). The
CROSS-framework audit and consolidation work is the central plan above.

**To advance a feature (the update loop):** pick the next feature from the parity
backlog -> MEASURE all four side by side (assume no parity; no mocks, real
services on the lab) -> DECIDE the best implementation, writing or superseding an
ADR when the contract changes -> write the executable
`fixtures/<feature>_contract.json` and fix the divergences in ALL FOUR with named
positive and negative regressions -> flip owed->proven, run
`scripts/audit-contract-fixtures.py`, and update the `CONTRACT-MAP.md` row from
those counts -> verify yourself at HEAD on the lab -> ship `feature/release<ver>`
-> `v3` -> tag. Pushing plan edits into tina4-documentation is a MERGE, never a
rebase (the subtree graft + the PDF-sync bot).

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
