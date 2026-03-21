<p align="center">
  <img src="https://tina4.com/logo.svg" alt="Tina4" width="200">
</p>

<h1 align="center">Tina4 PHP</h1>
<h3 align="center">This is not a framework</h3>

<p align="center">
  Laravel joy. PHP speed. 10x less code. Zero third-party dependencies.
</p>

<p align="center">
  <a href="https://tina4.com">Documentation</a> &bull;
  <a href="#getting-started">Getting Started</a> &bull;
  <a href="#features">Features</a> &bull;
  <a href="#cli-reference">CLI Reference</a> &bull;
  <a href="https://tina4.com">tina4.com</a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/tests-1166%20passing-brightgreen" alt="Tests">
  <img src="https://img.shields.io/badge/carbonah-A%2B%20rated-00cc44" alt="Carbonah A+">
  <img src="https://img.shields.io/badge/zero--dep-core-blue" alt="Zero Dependencies">
  <img src="https://img.shields.io/badge/php-8.2%2B-blue" alt="PHP 8.2+">
  <img src="https://img.shields.io/badge/license-MIT-lightgrey" alt="MIT License">
</p>

---

## Quickstart

```bash
composer require tina4stack/tina4php
composer exec tina4 initialize:run
composer start
# -> http://localhost:7146
```

That's it. Zero configuration, zero classes, zero boilerplate.

---

## What's Included

Every feature is built from scratch -- no bloated vendor trees, no third-party runtime dependencies in core.

| Category | Features |
|----------|----------|
| **HTTP** | Built-in PHP server, static route registration, path params (`{id}`, `{name}`), middleware pipeline, CORS, rate limiting |
| **Templates** | Frond engine (Twig-compatible), inheritance, partials, filters, macros, fragment caching, sandboxing |
| **ORM** | Active Record, typed properties, soft delete, relationships (`hasOne`/`hasMany`), field mapping, table filters, multi-database |
| **Database** | SQLite3, PostgreSQL, MySQL, MSSQL, Firebird -- unified adapter interface, `DatabaseFactory::create()` |
| **Auth** | Zero-dep JWT (RS256/HS256), sessions, secure key generation, form tokens |
| **API** | Swagger/OpenAPI auto-generation, GraphQL with ORM auto-schema and GraphiQL IDE |
| **Background** | Service runner for long-running processes, async triggers and events |
| **Real-time** | Swoole HTTP and TCP support |
| **Frontend** | tina4-css, frond.js helper, SCSS compiler, live reload |
| **DX** | Dev admin dashboard, error overlay, request inspector, annotation-driven testing |
| **Data** | Migrations with rollback, ORM seeders, fake data generators |
| **Other** | REST client, localization (i18n), in-memory cache (TTL), event system, configurable error pages, HTML element builder, health check |

For full documentation visit **[tina4.com](https://tina4.com)**.

---

## Install

```bash
composer require tina4stack/tina4php
```

### Built-in database adapters

v3 ships with adapters for SQLite3, MySQL, PostgreSQL, MSSQL (sqlserver), and Firebird -- no extra packages needed. Just ensure the required PHP extension is installed (e.g. `ext-sqlite3`, `ext-pdo_mysql`).

---

## Getting Started

### 1. Create a project

```bash
composer require tina4stack/tina4php
composer exec tina4 initialize:run
```

This creates:

```
my-app/
├── index.php              # Entry point
├── .env                   # Configuration
├── src/
│   ├── routes/            # API + page routes (auto-discovered)
│   ├── orm/               # Database models
│   ├── app/               # Service classes and shared helpers
│   ├── templates/         # Frond/Twig templates
│   ├── services/          # Background service processes
│   ├── scss/              # SCSS (auto-compiled to public/css/)
│   └── public/            # Static assets served at /
├── migrations/            # SQL migration files
└── tests/                 # PHPUnit tests
```

### 2. Create a route

Create `src/routes/hello.php`:

```php
use Tina4\Get;
use Tina4\Response;

Get::add("/api/hello", function (Response $response) {
    return $response(["message" => "Hello from Tina4!"], HTTP_OK);
});

Get::add("/api/hello/{name}", function ($name, Response $response) {
    return $response(["message" => "Hello, {$name}!"], HTTP_OK);
});
```

Visit `http://localhost:7146/api/hello` -- routes are auto-discovered, no imports needed.

### 3. Add a database

Edit `.env`:

```bash
[DATABASE]
DATABASE_PATH=test.db
```

Create and run a migration:

```bash
composer tina4 migrate:create "create users table"
```

Edit the generated SQL:

```sql
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
```

```bash
composer tina4 migrate
```

### 4. Create an ORM model

Create `src/orm/User.php`:

```php
class User extends \Tina4\ORM
{
    public $tableName = "users";
    public $primaryKey = "id";

    public $id;
    public $name;
    public $email;
    public $createdAt;
}
```

### 5. Build a REST API

Create `src/routes/users.php`:

```php
use Tina4\Get;
use Tina4\Post;
use Tina4\Response;

Get::add("/api/users", function (Response $response) {
    $users = (new User())->select("*", 100)->asArray();
    return $response($users, HTTP_OK);
});

Get::add("/api/users/{id}", function ($id, Response $response) {
    $user = new User();
    $user->load($id);
    return $response($user->asArray(), HTTP_OK);
});

Post::add("/api/users", function (Response $response) {
    $user = new User($_REQUEST);
    $user->save();
    return $response($user->asArray(), HTTP_CREATED);
});
```

### 6. Add a template

Create `src/templates/base.twig`:

```twig
<!DOCTYPE html>
<html>
<head>
    <title>{% block title %}My App{% endblock %}</title>
    <link rel="stylesheet" href="/css/tina4.min.css">
    {% block stylesheets %}{% endblock %}
</head>
<body>
    {% block content %}{% endblock %}
    <script src="/js/frond.js"></script>
    {% block javascripts %}{% endblock %}
</body>
</html>
```

Create `src/templates/pages/home.twig`:

```twig
{% extends "base.twig" %}
{% block content %}
<div class="container mt-4">
    <h1>{{ title }}</h1>
    <ul>
    {% for user in users %}
        <li>{{ user.name }} -- {{ user.email }}</li>
    {% endfor %}
    </ul>
</div>
{% endblock %}
```

Render it from a route:

```php
Get::add("/", function (Response $response) {
    $users = (new User())->select("*", 20)->asArray();
    return $response(\Tina4\renderTemplate("pages/home.twig", ["title" => "Users", "users" => $users]), HTTP_OK, TEXT_HTML);
});
```

### 7. Seed, test, deploy

```bash
composer test                     # Run test suite
composer start                    # Start dev server
```

For the complete step-by-step guide, visit **[tina4.com](https://tina4.com)**.

---

## Features

### Routing

```php
use Tina4\Route;
use Tina4\Response;

Route::get("/api/items", function (Response $response) {
    return $response(["items" => []], HTTP_OK);
});

Route::post("/api/webhook", function (Response $response) {
    return $response(["ok" => true], HTTP_OK);
})->noAuth();

Route::get("/api/admin/stats", function (Response $response) {
    return $response(["secret" => true], HTTP_OK);
})->secure();
```

Path parameters: `{id}`, `{name}`, `{slug}`. Middleware chaining with `->middleware([...])`.

### ORM

Active Record with typed properties, validation, soft delete, relationships, and multi-database support.

```php
class User extends \Tina4\ORM
{
    public $tableName = "users";
    public $primaryKey = "id";
    public $softDelete = true;

    public $id;
    public $name;
    public $email;
    public $role;

    // Relationships
    public $hasMany = [["Address" => "customerId"]];
    public $hasOne = [["Profile" => "userId"]];
}

// CRUD
$user = new User(["name" => "Alice", "email" => "alice@example.com"]);
$user->save();
$user->load("email = 'alice@example.com'");
$user->delete();

// Queries
$users = (new User())->select("*", 100)->asArray();
```

### Database

Unified interface via `DatabaseFactory::create()`:

```php
use Tina4\Database\DatabaseFactory;

$db = DatabaseFactory::create("app.db");
$db = DatabaseFactory::create("sqlite::memory:");
$db = DatabaseFactory::create("mysql://localhost:3306/mydb", username: "user", password: "pass");
$db = DatabaseFactory::create("postgres://localhost:5432/mydb", username: "user", password: "pass");
$db = DatabaseFactory::create("mssql://localhost:1433/mydb", username: "sa", password: "pass");
$db = DatabaseFactory::create("firebird://localhost:3050/path/to/db", username: "SYSDBA", password: "masterkey");

$result = $db->fetch("SELECT * FROM users WHERE age > ?", 20, 0);
```

### Middleware

```php
Route::get("/protected", function (Response $response) {
    return $response(["secret" => true], HTTP_OK);
})->middleware(["AuthCheck"]);
```

### JWT Authentication

```php
$auth = new \Tina4\Auth();
$token = $auth->getToken(["user_id" => 42]);
$isValid = $auth->validToken($token);
$payload = $auth->getPayLoad($token);
```

POST/PUT/PATCH/DELETE routes require `Authorization: Bearer <token>` by default. Chain `->noAuth()` to make public, `->secure()` to protect GET routes.

### Sessions

```php
$_SESSION["user_id"] = 42;
$userId = $_SESSION["user_id"];
```

### GraphQL

```php
// Auto-schema from ORM models
// GET /graphql  -> GraphiQL IDE
// POST /graphql -> Execute queries

// Query example:
// { users { id, name, email } }
```

### Swagger / OpenAPI

Auto-generated at `/swagger`:

```php
/**
 * @description Get all users
 * @tags Users
 */
Route::get("/api/users", function (Response $response) {
    return $response((new User())->select("*", 100)->asArray(), HTTP_OK);
});
```

### Service Runner

```php
class EmailService extends \Tina4\Process
{
    public function run(): void
    {
        // Long-running background process
        while ($this->isRunning()) {
            $this->processQueue();
            sleep(5);
        }
    }
}
```

Start with: `composer start-service`

### Template Engine (Frond)

Twig-compatible, filters, macros, inheritance, fragment caching:

```twig
{% extends "base.twig" %}
{% block content %}
<h1>{{ title | upper }}</h1>
{% for item in items %}
    <p>{{ item.name }} -- {{ item.price | number_format(2) }}</p>
{% endfor %}

{% include "partials/sidebar.twig" %}
{% endblock %}
```

### CRUD Scaffolding

Auto-generate admin interfaces from ORM models with built-in CRUD operations.

### Event System

```php
\Tina4\Thread::addTrigger("user.created", static function ($name) {
    file_put_contents("./log/event.log", "New user: {$name}\n", FILE_APPEND);
});

\Tina4\Thread::trigger("user.created", ["Alice"]);
```

### REST Client

```php
$api = new \Tina4\Api("https://api.example.com", "Bearer xyz");
$result = $api->sendRequest("/users/42");
```

### SCSS Compiler

Drop `.scss` files in `src/scss/` -- auto-compiled to CSS. Variables, nesting, mixins, `@import`, `@extend`.

### Localization (i18n)

JSON translation files with placeholder interpolation.

### Annotation-Driven Testing

```php
/**
 * @tests
 *   assert (1,1) === 2, "1 + 1 = 2"
 *   assert is_integer(1,1) === true, "This should be an integer"
 */
function add($a, $b) {
    return $a + $b;
}
```

---

## Dev Mode

Set `TINA4_DEBUG=true` in `.env` to enable:

- **Twig debug extension** -- `{{ dump(variable) }}` available in templates
- **Verbose logging** -- full debug output via `Debug::message()`
- **No template caching** -- templates recompile on every request
- **Error detail** -- full stack traces shown in the browser

---

## CLI Reference

```bash
composer tina4                           # Tina4 menu
composer exec tina4 initialize:run       # Scaffold a new project
composer start                           # Start dev server (default: 7146)
composer start 8080                      # Start on specific port
composer tina4 migrate                   # Run pending migrations
composer tina4 migrate:create <desc>     # Create a migration file
composer test                            # Run test suite
composer start-service                   # Start background service runner
```

## Environment

```bash
[Section]
TINA4_DEBUG=true                        # Enable debug mode
DATABASE_PATH=app.db                    # SQLite database path
SECRET=your-jwt-secret                  # JWT signing secret
TINA4_LANGUAGE=en                       # Localization language

[SWAGGER]
SWAGGER_TITLE=My API                    # Swagger page title
SWAGGER_DESCRIPTION=API docs            # Swagger description

[DEPLOYMENT]
GIT_BRANCH=master                       # Git branch for deployments
GIT_SECRET=0123456789                   # Webhook secret
```

## Zero-Dependency Philosophy

Tina4 PHP is built from the ground up with no third-party runtime dependencies in core. The framework requires only PHP 8.2+ with `openssl` and `json` extensions. The `pcntl` extension is optional (used for graceful signal handling on Linux/macOS). Database drivers are optional and installed separately. This keeps the full deployment under 8MB and minimizes your project's carbon footprint.

## Docker Support

```bash
docker run -v $(pwd):/app tina4stack/php:latest composer require tina4stack/tina4php
docker run -v $(pwd):/app tina4stack/php:latest composer exec tina4 initialize:run
docker run -v $(pwd):/app -p7146:7146 tina4stack/php:latest composer start
```

---

## Documentation

Full guides, API reference, and examples at **[tina4.com](https://tina4.com)**.

## License

MIT (c) 2007-2025 Tina4 Stack
https://opensource.org/licenses/MIT

---

<p align="center"><b>Tina4</b> -- The framework that keeps out of the way of your coding.</p>

---

## Our Sponsors

**Sponsored with 🩵 by Code Infinity**

[<img src="https://codeinfinity.co.za/wp-content/uploads/2025/09/c8e-logo-github.png" alt="Code Infinity" width="100">](https://codeinfinity.co.za/about-open-source-policy?utm_source=github&utm_medium=website&utm_campaign=opensource_campaign&utm_id=opensource)

*Supporting open source communities <span style="color: #1DC7DE;">•</span> Innovate <span style="color: #1DC7DE;">•</span> Code <span style="color: #1DC7DE;">•</span> Empower*
