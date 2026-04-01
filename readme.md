<p align="center">
  <img src="https://tina4.com/logo.svg" alt="Tina4" width="200">
</p>

<h1 align="center">Tina4 PHP</h1>
<h3 align="center">This Is Now A 4Framework</h3>

<p align="center">
  Laravel joy. PHP speed. 10x less code. Zero third-party dependencies.
</p>

<p align="center">
  <a href="https://packagist.org/packages/tina4stack/tina4php"><img src="https://img.shields.io/badge/Packagist-v3.10.25-7b1fa2" alt="Packagist v3.9.1"></a>
  <img src="https://img.shields.io/badge/tests-1%2C421%20passing-brightgreen" alt="Tests">
  <img src="https://img.shields.io/badge/features-38-blue" alt="Features">
  <img src="https://img.shields.io/badge/dependencies-0-brightgreen" alt="Zero Deps">
  <a href="https://tina4.com"><img src="https://img.shields.io/badge/docs-tina4.com-7b1fa2" alt="Docs"></a>
</p>

<p align="center">
  <a href="https://tina4.com">Documentation</a> &bull;
  <a href="#getting-started">Getting Started</a> &bull;
  <a href="#features">Features</a> &bull;
  <a href="#cli-reference">CLI Reference</a> &bull;
  <a href="https://tina4.com">tina4.com</a>
</p>

---

## Quick Start

```bash
# Install the Tina4 CLI
cargo install tina4  # or download binary from https://github.com/tina4stack/tina4/releases

# Create a project
tina4 init php ./my-app

# Run it
cd my-app && tina4 serve
```

Open http://localhost:7146 — your app is running.

<details>
<summary><strong>Without the Tina4 CLI</strong></summary>

```bash
# 1. Create project
mkdir my-app && cd my-app
composer require tina4stack/tina4php

# 2. Create entry point
cat > index.php << 'EOF'
<?php
require_once 'vendor/autoload.php';
$app = new \Tina4\App(basePath: __DIR__);
$server = new \Tina4\Server('0.0.0.0', 7146);
$server->start();
EOF

# 3. Create .env
echo 'TINA4_DEBUG=true' > .env
echo 'TINA4_LOG_LEVEL=ALL' >> .env

# 4. Create route directory
mkdir -p src/routes

# 5. Run
php index.php
```

Open http://localhost:7146

</details>

---

## What's Included

Every feature is built from scratch -- no bloated vendor trees, no third-party runtime dependencies in core.

| Category | Features |
|----------|----------|
| **HTTP** | Built-in PHP server, static route registration, path params (`{id}`, `{name}`), middleware pipeline, CORS, rate limiting |
| **Templates** | Frond engine (Twig-compatible), inheritance, partials, filters, macros, fragment caching, sandboxing |
| **ORM** | Active Record, typed properties, soft delete, relationships (`hasOne`/`hasMany`/`belongsTo` with eager loading), field mapping, table filters, multi-database |
| **Database** | SQLite3, PostgreSQL, MySQL, MSSQL, Firebird -- unified adapter interface, query caching (TINA4_DB_CACHE=true for 4x speedup) |
| **Auth** | Zero-dep JWT (HS256/RS256), sessions (file/Redis/Valkey/MongoDB/database), password hashing, form tokens |
| **API** | Swagger/OpenAPI auto-generation, GraphQL with ORM auto-schema and GraphiQL IDE, WSDL/SOAP with auto WSDL |
| **Background** | Queue (SQLite/RabbitMQ/Kafka/MongoDB) with priority, delayed jobs, retry, batch processing |
| **Real-time** | Native WebSocket (RFC 6455), stream_select server, per-path routing, connection manager, broadcast |
| **Frontend** | tina4-css (~24 KB), frond.js helper, SCSS compiler, live reload, CSS hot-reload |
| **DX** | Dev admin dashboard, error overlay, request inspector, hot-reload (stream_select file watcher), AI tool integration, Carbonah green benchmarks |
| **Data** | Migrations with rollback and status, 26+ fake data generators, ORM and table seeders |
| **Other** | REST client, localization (i18n), cache (memory/Redis/file), event system, messenger (.env driven), configurable error pages, HTML element builder, health check |

**1,421 tests across 38 built-in features. Zero dependencies. All Carbonah benchmarks rated A+.**

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
vendor/bin/tina4php init
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
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;

Router::get("/api/hello", function (Request $request, Response $response) {
    return $response(["message" => "Hello from Tina4!"], HTTP_OK);
});

Router::get("/api/hello/{name}", function (Request $request, Response $response) {
    $name = $request->params["name"];
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
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;

Router::get("/api/users", function (Request $request, Response $response) {
    $users = (new User())->select("*", 100)->asArray();
    return $response($users, HTTP_OK);
});

Router::get("/api/users/{id}", function (Request $request, Response $response) {
    $user = new User();
    $user->load($request->params["id"]);
    return $response($user->asArray(), HTTP_OK);
});

Router::post("/api/users", function (Request $request, Response $response) {
    $user = new User($request->body);
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
Router::get("/", function (Request $request, Response $response) {
    $users = (new User())->select("*", 20)->asArray();
    return $response->template("pages/home.twig", ["title" => "Users", "users" => $users]);
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
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;

Router::get("/api/items", function (Request $request, Response $response) {
    return $response(["items" => []], HTTP_OK);
});

Router::post("/api/webhook", function (Request $request, Response $response) {
    return $response(["ok" => true], HTTP_OK);
})->noAuth();

Router::get("/api/admin/stats", function (Request $request, Response $response) {
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
Router::get("/protected", function (Request $request, Response $response) {
    return $response(["secret" => true], HTTP_OK);
})->middleware(["AuthCheck"]);
```

### JWT Authentication

```php
$token = \Tina4\Auth::getToken(["user_id" => 42], "your-secret");
$payload = \Tina4\Auth::validToken($token, "your-secret");
$claims = \Tina4\Auth::getPayload($token);
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
Router::get("/api/users", function (Request $request, Response $response) {
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
\Tina4\Events::on("user.created", function ($name) {
    file_put_contents("./log/event.log", "New user: {$name}\n", FILE_APPEND);
});

\Tina4\Events::emit("user.created", "Alice");
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
vendor/bin/tina4php init                 # Scaffold a new project
composer start                           # Start dev server (default: 7146)
composer start 8080                      # Start on specific port
composer start --production              # Auto-use OPcache + best production config
composer tina4 migrate                   # Run pending migrations
composer tina4 migrate:create <desc>     # Create a migration file
composer tina4 generate model <name>     # Generate ORM model scaffold
composer tina4 generate route <name>     # Generate route scaffold
composer tina4 generate migration <desc> # Generate migration file
composer tina4 generate middleware <name># Generate middleware scaffold
composer test                            # Run test suite
composer start-service                   # Start background service runner
```

### Production Server Auto-Detection

`tina4 serve` auto-detects the best production configuration:

- **PHP**: OPcache enabled, optimized settings
- Use `composer start --production` to auto-apply production configuration

### Scaffolding with `tina4 generate`

Quickly scaffold new components:

```bash
composer tina4 generate model User          # Creates src/orm/User.php
composer tina4 generate route users         # Creates src/routes/users.php
composer tina4 generate migration "add age" # Creates migration SQL file
composer tina4 generate middleware AuthLog   # Creates middleware class
```

### ORM Relationships & Eager Loading

```php
// Relationships defined on the model
public $hasMany = [["Order" => "userId"]];
public $hasOne = [["Profile" => "userId"]];
public $belongsTo = [["Customer" => "customerId"]];

// Eager loading with include
$users = (new User())->select("*", 100, include: ["orders", "profile"])->asArray();
```

### DB Query Caching

Enable query caching for up to 4x speedup on read-heavy workloads:

```bash
# .env
TINA4_DB_CACHE=true
```

### Frond Pre-Compilation

Templates are pre-compiled for 2.8x faster rendering.

### Gallery

7 interactive examples with **Try It** deploy.

## Environment

```bash
[Section]
TINA4_DEBUG=true                        # Enable debug mode
DATABASE_PATH=app.db                    # SQLite database path
SECRET=your-jwt-secret                  # JWT signing secret
TINA4_LOCALE=en                         # Localization language

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
docker run -v $(pwd):/app tina4stack/php:latest vendor/bin/tina4php init
docker run -v $(pwd):/app -p7146:7146 tina4stack/php:latest composer start
```

---

## Documentation

Full guides, API reference, and examples at **[tina4.com](https://tina4.com)**.

## License

MIT (c) 2007-2026 Tina4 Stack
https://opensource.org/licenses/MIT

---

<p align="center"><b>Tina4</b> -- The framework that keeps out of the way of your coding.</p>

---

## Our Sponsors

**Sponsored with 🩵 by Code Infinity**

[<img src="https://codeinfinity.co.za/wp-content/uploads/2025/09/c8e-logo-github.png" alt="Code Infinity" width="100">](https://codeinfinity.co.za/about-open-source-policy?utm_source=github&utm_medium=website&utm_campaign=opensource_campaign&utm_id=opensource)

*Supporting open source communities <span style="color: #1DC7DE;">•</span> Innovate <span style="color: #1DC7DE;">•</span> Code <span style="color: #1DC7DE;">•</span> Empower*
