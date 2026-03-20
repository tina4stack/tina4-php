# Tina4 PHP

Full Tina4 PHP framework and application scaffold. See https://tina4.com for full documentation.

## Build & Test

- PHP: >=8.0
- Install: `composer install`
- Run tests: `composer run-tests` or `./vendor/bin/phpunit tests --verbose --color`
- Start server: `composer start` (runs `tina4 webservice:run`)
- Debug server: `composer debug` (Xdebug enabled)
- Initialize project: `composer initialize`
- CLI: `composer tina4` or `bin/tina4`

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
migrations/              # Database migration SQL files
tests/                   # PHPUnit tests (Router, Response, Routing)
bin/
  tina4                  # CLI tool
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

## Links

- Website: https://tina4.com
- GitHub: https://github.com/tina4stack/tina4-php
