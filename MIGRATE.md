# Migrating from Tina4 PHP v2 to v3

## Why Migrate

v3 is a ground-up rewrite. You get:

- **Custom stream_select server** — no more `php -S`. WebSocket routes work out of the box.
- **38 built-in features** with zero external Composer dependencies (no Twig, no php-jwt, no phpfastcache, no scssphp, no psr/log)
- **5 database drivers** — SQLite3, PostgreSQL, MySQL, MSSQL, Firebird
- **ORM relationships** — `hasOne`, `hasMany`, `belongsTo` with eager loading
- **Built-in template engine** — Frond replaces Twig (same syntax, zero deps)
- **Event system**, response caching, DI container, GraphQL, WSDL/SOAP, error overlay
- **WebSocket routes** — `Router::ws('/chat', $handler)` alongside HTTP routes

v2 required 12+ Composer packages. v3 requires only `ext-openssl` and `ext-json`.

---

## Namespace Changes

v2 split code across `Tina4/` subdirectories with separate PSR-4 namespaces registered for each:

```
v2: Tina4/Routing/, Tina4/Security/, Tina4/Database/, Tina4/Api/, Tina4/Config/, Tina4/Twig/, ...
```

v3 flattens the structure. All classes live under `Tina4\`:

```
v3: Tina4/Router.php, Tina4/Auth.php, Tina4/ORM.php, Tina4/Database/, Tina4/Frond.php, ...
```

### composer.json autoload

```json
// v2
"psr-4": {
    "Tina4\\": ["Tina4/", "Tina4/Api/", "Tina4/Config/", "Tina4/Database/",
                "Tina4/Routing/", "Tina4/Security/", "Tina4/Twig/", ...]
}

// v3
"psr-4": {
    "Tina4\\": "Tina4/",
    "Tina4\\Database\\": "Tina4/Database/",
    "Tina4\\Middleware\\": "Tina4/Middleware/",
    "Tina4\\Queue\\": "Tina4/Queue/",
    "Tina4\\Session\\": "Tina4/Session/"
}
```

---

## Routing

### v2 — Class-Based Static Methods

v2 used separate classes per HTTP method. Each class extended `Route`:

```php
// v2
use Tina4\Get;
use Tina4\Post;

Get::add("/api/users", function ($response, $request) {
    return $response(["users" => []], HTTP_OK);
});

Post::add("/api/users", function ($response, $request) {
    return $response($request->data, HTTP_CREATED);
});
```

### v3 — Static Router Methods

```php
// v3
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;

Router::get("/api/users", function (Request $request, Response $response) {
    return $response->json(["users" => []]);
});

Router::post("/api/users", function (Request $request, Response $response) {
    return $response->json($request->body, 201);
});
```

### Routing Changes Summary

| v2 | v3 |
|----|-----|
| `Get::add($path, $fn)` | `Router::get($path, $fn)` |
| `Post::add($path, $fn)` | `Router::post($path, $fn)` |
| `Put::add($path, $fn)` | `Router::put($path, $fn)` |
| `Patch::add($path, $fn)` | `Router::patch($path, $fn)` |
| `Delete::add($path, $fn)` | `Router::delete($path, $fn)` |
| `Any::add($path, $fn)` | `Router::any($path, $fn)` |
| — | `Router::ws($path, $fn)` (new) |
| — | `Router::group($prefix, $fn)` (new) |

### Callback Signature Change

```php
// v2 — $response first, $request second
function ($response, $request) { ... }

// v3 — $request first, $response second (matches standard conventions)
function (Request $request, Response $response) { ... }
```

### Chaining (New in v3)

```php
Router::get("/api/users", $handler)->secure()->cached(300);
```

---

## Database

### v2 — Separate Packages

v2 required separate Composer packages for each database:

- `tina4stack/tina4php-database` (core)
- `tina4stack/tina4php-sqlite3`
- `tina4stack/tina4php-mysql` (etc.)

Database classes used short aliases: `DataMySQL`, `DataFirebird`, `DataSQLite3`, `DataPostgresql`, `DataMSSQL`.

```php
// v2
$db = new \Tina4\DataSQLite3("database.db");
$db = new \Tina4\DataMySQL("localhost/3306:mydb", "user", "password");
$db = new \Tina4\DataFirebird("localhost/3050:/path/to/db.fdb", "SYSDBA", "masterkey");
```

### v3 — DatabaseFactory with URL Strings

v3 uses a single factory method with standard URL connection strings:

```php
// v3
use Tina4\Database\DatabaseFactory;

$db = DatabaseFactory::create("sqlite:///database.db");
$db = DatabaseFactory::create("mysql://user:password@localhost:3306/mydb");
$db = DatabaseFactory::create("postgresql://user:password@localhost:5432/mydb");
$db = DatabaseFactory::create("firebird://SYSDBA:masterkey@localhost:3050//path/to/db.fdb");
$db = DatabaseFactory::create("mssql://sa:password@localhost:1433/mydb");
```

Or read from environment:

```php
// v3 — reads DATABASE_URL from .env
$url = \Tina4\DotEnv::getEnv('DATABASE_URL');
$db = DatabaseFactory::create($url);
```

### Connection String Mapping

| v2 Class | v2 Format | v3 URL Scheme |
|----------|-----------|---------------|
| `DataSQLite3("file.db")` | Bare filename | `sqlite:///file.db` |
| `DataMySQL("host/port:db", ...)` | Colon-separated | `mysql://user:pass@host:port/db` |
| `DataPostgresql("host/port:db", ...)` | Colon-separated | `postgresql://user:pass@host:port/db` |
| `DataFirebird("host/port:path", ...)` | Colon-separated | `firebird://user:pass@host:port/path` |
| `DataMSSQL("host/port:db", ...)` | Colon-separated | `mssql://user:pass@host:port/db` |

### Removed Aliases

The short class names (`DataMySQL`, `DataSQLite3`, `DataFirebird`, `DataPostgresql`, `DataMSSQL`) are gone. Use `DatabaseFactory::create()` for all databases.

### Adapter Classes (v3)

If you need the adapter directly:

| v2 Class | v3 Adapter Class |
|----------|-----------------|
| `Tina4\DataSQLite3` | `Tina4\Database\SQLite3Adapter` |
| `Tina4\DataMySQL` | `Tina4\Database\MySQLAdapter` |
| `Tina4\DataPostgresql` | `Tina4\Database\PostgresAdapter` |
| `Tina4\DataMSSQL` | `Tina4\Database\MSSQLAdapter` |
| `Tina4\DataFirebird` | `Tina4\Database\FirebirdAdapter` |

---

## ORM

### v2

v2 ORM used the `tina4stack/tina4php-orm` package. Models extended `\Tina4\ORM` and used `$fieldMapping`, `$tableName`, etc.

### v3

v3 ORM is built-in. The base class is the same (`Tina4\ORM`) but now lives in the main package.

### Key Changes

| Feature | v2 | v3 |
|---------|-----|-----|
| Constructor | `new User()` (uses global DB) | `new User($db)` (explicit adapter) |
| Serialization | `$user->asArray()` / `$user->jsonSerialize()` | `$user->toArray()` / `$user->toDict()` |
| Relationships | Not built-in | `hasOne`, `hasMany`, `belongsTo` properties |
| Eager loading | Not available | `$user->toArray(include: ['posts'])` |

### Relationship Definitions (New in v3)

```php
class User extends \Tina4\ORM {
    public string $tableName = 'users';
    public string $primaryKey = 'id';

    public array $hasMany = [
        'posts' => 'Post.user_id',
    ];
}

class Post extends \Tina4\ORM {
    public string $tableName = 'posts';
    public string $primaryKey = 'id';

    public array $belongsTo = [
        'author' => 'User.id',
    ];
}
```

---

## Template Engine

### v2 — Twig (External Dependency)

v2 required `twig/twig` (^3.3.8) as a Composer dependency. Templates used Twig syntax.

```php
// v2 — Twig was initialized internally by Tina4Php
// Templates rendered via the routing response
return $response(\Tina4\renderTemplate("pages/home.twig", ["title" => "Home"]));
```

### v3 — Frond (Built-in)

v3 includes Frond, a zero-dependency template engine that uses the same Twig syntax. No Composer package needed.

```php
// v3
$frond = new \Tina4\Frond('src/templates');
$html = $frond->render('pages/home.twig', ['title' => 'Home']);

// Or via response in route handler
return $response->html($frond->render('pages/home.twig', $data));
```

### Custom Filters and Globals

```php
// v2 — via Twig's addFilter/addGlobal
// Usually done through TwigUtility or Config

// v3 — direct Frond methods
$frond = new \Tina4\Frond();
$frond->addFilter('money', fn($v) => number_format((float)$v, 2));
$frond->addGlobal('APP_NAME', 'My App');
$frond->addTest('positive', fn($x) => $x > 0);
```

Your existing `.twig` template files work without changes. The syntax is the same.

---

## Auth

v2 used `nowakowskir/php-jwt` (RSA-based, RS256) and required OpenSSL key generation.
v3 uses built-in HMAC-SHA256 (HS256) with a shared secret. No RSA keys needed.

### Method Changes

| v2 | v3 | Notes |
|----|-----|-------|
| `$auth->getToken($payload)` | `Auth::getToken($payload, $secret)` | Now static, requires secret |
| `$auth->validToken($token)` | `Auth::validToken($token, $secret)` | Returns `?array` (payload or null) |
| — | `Auth::validateToken($token, $secret)` | Alias for `validToken` |
| — | `Auth::createToken($payload, $secret)` | Alias for `getToken` |
| — | `Auth::getPayload($token)` | Decode without validation |
| — | `Auth::hashPassword($password)` | PBKDF2 password hashing |
| — | `Auth::checkPassword($password, $hash)` | Verify password |
| — | `Auth::refreshToken($token, $secret)` | Refresh an existing token |

### Usage Change

```php
// v2 — instance method, RSA keys
$auth = new \Tina4\Auth();
$token = $auth->getToken(["user_id" => 1]);
$valid = $auth->validToken($token);

// v3 — static methods, shared secret
$secret = getenv('SECRET') ?: 'my-secret';
$token = \Tina4\Auth::createToken(["user_id" => 1], $secret);
$payload = \Tina4\Auth::validateToken($token, $secret);  // array or null
```

### Key Migration

v2 used RSA key pairs in `secrets/private.key` and `secrets/public.pub`. v3 uses a `SECRET` environment variable. Set it in `.env`:

```bash
SECRET=your-random-secret-here
```

---

## Environment Variables

| v2 | v3 | Notes |
|----|-----|-------|
| `TINA4_DEBUG_LEVEL` (array) | `TINA4_DEBUG` (boolean) | `true`/`false` instead of log level array |
| `TINA4_AUTO_COMMIT` | `TINA4_AUTOCOMMIT` | No underscore between AUTO and COMMIT |
| `DATABASE_PATH` | `DATABASE_URL` | Standard URL format |

### Debug Settings

```bash
# v2
TINA4_DEBUG_LEVEL=[TINA4_LOG_DEBUG]

# v3
TINA4_DEBUG=true
```

---

## Removed Dependencies

| v2 Dependency | v3 Replacement |
|---------------|----------------|
| `twig/twig` (^3.3.8) | Built-in Frond template engine |
| `nowakowskir/php-jwt` (^2.0.1) | Built-in HMAC-SHA256 JWT (`Tina4\Auth`) |
| `phpfastcache/phpfastcache` (^8.0.5) | Built-in response caching |
| `scssphp/scssphp` (^2.0.1) | Built-in SCSS compiler (`Tina4\ScssCompiler`) |
| `psr/log` (^1.1.4) | Built-in `Tina4\Log` |
| `coyl/git` (^0.1.7) | Removed (not needed) |
| `tina4stack/tina4php-debug` (^2.0) | Built-in `Tina4\Log` |
| `tina4stack/tina4php-shape` (^2.0) | Removed |
| `tina4stack/tina4php-env` (^2.0) | Built-in `Tina4\DotEnv` |
| `tina4stack/tina4php-database` (^2.0) | Built-in `Tina4\Database\DatabaseFactory` |
| `tina4stack/tina4php-orm` (^2.0) | Built-in `Tina4\ORM` |
| `tina4stack/tina4php-core` (^2.0) | All core code is in the main package |

---

## Static Assets

### v2

v2 bundled Bootstrap CSS/JS, jQuery, and various helper scripts.

### v3

v3 ships its own lightweight assets:

| v2 Asset | v3 Replacement |
|----------|----------------|
| Bootstrap CSS | `tina4.min.css` (~24KB, Bootstrap-compatible class names) |
| jQuery | Removed — not needed |
| `tina4helper.js` | `tina4.min.js` (utility functions) |
| — | `frond.min.js` (AJAX, form handling, WebSocket reconnection) |

Update your template `<link>` and `<script>` tags:

```html
<!-- v2 -->
<link rel="stylesheet" href="/css/bootstrap.min.css">
<script src="/js/jquery.min.js"></script>
<script src="/js/tina4helper.js"></script>

<!-- v3 -->
<link rel="stylesheet" href="/css/tina4.min.css">
<script src="/js/tina4.min.js"></script>
<script src="/js/frond.min.js"></script>
```

---

## Server

### v2 — PHP Built-in Server

```bash
# v2
php -S localhost:7145 index.php
# or
composer start
```

v2 used PHP's built-in development server (`php -S`). For production, you needed Apache or Nginx.

### v3 — Custom stream_select Server

```bash
# v3
php bin/tina4php serve
# or
composer serve
```

v3 includes a custom non-blocking server built on `stream_socket_server` + `stream_select`. It handles HTTP and WebSocket connections in the same process. No Apache or Nginx needed for development or production.

### WebSocket Routes (New in v3)

```php
Router::ws('/chat', function ($connection, $message) {
    $connection->send("Echo: " . $message);
});
```

---

## Entry Point

### v2

```php
// index.php
require_once "vendor/autoload.php";
$config = new \Tina4\Config();
echo new \Tina4\Tina4Php($config);
```

### v3

```php
// index.php
require_once "vendor/autoload.php";

$app = new \Tina4\App(basePath: __DIR__);
$app->run();
```

Or use the CLI:

```bash
php bin/tina4php serve
```

---

## New Features (v3 Only)

These did not exist in v2:

- **WebSocket routes** — `Router::ws()` for real-time communication
- **Route groups** — `Router::group('/api/v1', fn() => ...)` with shared middleware
- **Event system** — `Events::on()`, `Events::emit()` for decoupled communication
- **DI container** — `Container` for dependency injection
- **GraphQL** — zero-dep engine with ORM auto-generation
- **WSDL/SOAP** — built-in SOAP 1.1 server
- **i18n** — JSON-based translations
- **Queue system** — database-backed job queue with Producer/Consumer
- **Error overlay** — rich HTML error page in dev mode
- **FakeData** — built-in fake data generator for seeding
- **Testing** — built-in assertion framework
- **AI context** — auto-detect and install context files for AI coding tools
- **ORM relationships** — `hasOne`, `hasMany`, `belongsTo` with eager loading
- **Session backends** — file, database, custom handlers
- **Messenger** — SMTP/IMAP email integration
- **HtmlElement** — programmatic HTML builder
- **AutoCrud** — auto-generate REST endpoints from ORM models
- **DevAdmin** — built-in admin panel at `/__dev/`

---

## Step-by-Step Migration Checklist

1. **Update composer.json.** Replace all `tina4stack/*` dependencies with a single `tina4stack/tina4php` requirement. Remove `twig/twig`, `nowakowskir/php-jwt`, `phpfastcache/phpfastcache`, `scssphp/scssphp`, `psr/log`, and `coyl/git`. Run `composer update`.

2. **Update the entry point.** Replace `new \Tina4\Tina4Php($config)` with `(new \Tina4\App(basePath: __DIR__))->run()`. Or use `php bin/tina4php serve` from the CLI.

3. **Convert routes.** Replace `Get::add($path, $fn)` with `Router::get($path, $fn)`. Do the same for `Post`, `Put`, `Patch`, `Delete`, and `Any`. Swap the callback signature from `($response, $request)` to `(Request $request, Response $response)`.

4. **Update database connections.** Replace `new DataSQLite3("file.db")` with `DatabaseFactory::create("sqlite:///file.db")`. Replace all `Data*` class instantiations with `DatabaseFactory::create()` using URL format. Set `DATABASE_URL` in `.env`.

5. **Update ORM models.** Pass the database adapter to constructors: `new User($db)`. Replace `asArray()` with `toArray()`. Add relationship properties (`hasMany`, `belongsTo`, `hasOne`) where needed.

6. **Replace Twig with Frond.** Remove `twig/twig` from `composer.json`. Instantiate `new \Tina4\Frond('src/templates')` instead. Your `.twig` files work without changes. Move custom Twig filters/globals to `Frond->addFilter()` and `Frond->addGlobal()`.

7. **Update Auth usage.** Remove `nowakowskir/php-jwt`. Replace `$auth->getToken($payload)` with `Auth::createToken($payload, $secret)`. Replace `$auth->validToken($token)` with `Auth::validateToken($token, $secret)`. Add `SECRET=your-secret` to `.env`. The RSA keys in `secrets/` are no longer needed.

8. **Update environment variables.** Replace `TINA4_DEBUG_LEVEL` with `TINA4_DEBUG=true`. Replace `TINA4_AUTO_COMMIT` with `TINA4_AUTOCOMMIT`.

9. **Update static assets.** Replace Bootstrap CSS/JS and jQuery references with `tina4.min.css`, `tina4.min.js`, and `frond.min.js`. Update all `<link>` and `<script>` tags in your templates.

10. **Update the server.** Stop using `php -S localhost:7145 index.php`. Use `php bin/tina4php serve` or `composer serve`. Update your Dockerfile accordingly.

11. **Update namespace imports.** Replace `use Tina4\Routing\Get` with `use Tina4\Router`. Replace `use Tina4\Security\Auth` with `use Tina4\Auth`. Replace `use Tina4\Config\Config` with `use Tina4\App`. Remove references to `Tina4\Twig\TwigUtility`.

12. **Test your routes.** Start the server. Hit each route. Check the error overlay for remaining v2 references. Run `composer test` to validate.
