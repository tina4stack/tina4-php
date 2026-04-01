<p align="center">
  <img src="https://tina4.com/logo.svg" alt="Tina4" width="200">
</p>

<h1 align="center">Tina4 PHP</h1>
<h3 align="center">This Is Now A 4Framework</h3>

<p align="center">
  54 built-in features. Zero dependencies. One require, everything works.
</p>

<p align="center">
  <a href="https://packagist.org/packages/tina4stack/tina4php"><img src="https://img.shields.io/packagist/v/tina4stack/tina4php?color=7b1fa2&label=Packagist" alt="Packagist"></a>
  <img src="https://img.shields.io/badge/tests-1%2C427%20passing-brightgreen" alt="Tests">
  <img src="https://img.shields.io/badge/features-54-blue" alt="Features">
  <img src="https://img.shields.io/badge/dependencies-0-brightgreen" alt="Zero Deps">
  <a href="https://tina4.com"><img src="https://img.shields.io/badge/docs-tina4.com-7b1fa2" alt="Docs"></a>
</p>

<p align="center">
  <a href="https://tina4.com">Documentation</a> &bull;
  <a href="#quick-start">Quick Start</a> &bull;
  <a href="#whats-built-in-54-features">Features</a> &bull;
  <a href="#cli-reference">CLI Reference</a> &bull;
  <a href="https://tina4.com">tina4.com</a>
</p>

---

## Quick Start

```bash
# Install
composer require tina4stack/tina4php

# Create entry point
echo '<?php require "vendor/autoload.php"; (new Tina4\App())->start();' > index.php

# Create .env
echo 'TINA4_DEBUG=true' > .env

# Run
php -S localhost:7146 index.php
```

Open http://localhost:7146 -- your app is running.

---

## What's Built In (54 Features)

Every feature is built from scratch -- no bloated vendor trees, no third-party runtime dependencies in core.

| Category | Features |
|----------|----------|
| **Core HTTP** (7) | Router with path params (`{id:int}`, `{p:path}`), Server, Request/Response, Middleware pipeline, Static file serving, CORS |
| **Database** (6) | SQLite, PostgreSQL, MySQL, MSSQL, Firebird -- unified adapter, connection pooling, query cache, transactions, race-safe ID generation, SQL dialect translation |
| **ORM** (7) | Active Record with typed fields, relationships (`has_one`/`has_many`/`belongs_to`), soft delete, QueryBuilder + MongoDB support, Auto-CRUD generator, migrations with rollback |
| **Auth & Security** (5) | JWT (HS256/RS256), password hashing (PBKDF2-SHA256), API key validation, rate limiting, CSRF form tokens |
| **Templating** (3) | Frond engine (Twig/Jinja2-compatible, pre-compiled 2.8x faster), SCSS auto-compilation, built-in CSS (~24 KB) |
| **API & Integration** (5) | HTTP client (zero-dep), GraphQL with ORM auto-schema + GraphiQL IDE, WSDL/SOAP with auto WSDL, WebSocket (RFC 6455) + Redis backplane, MCP server (24 dev tools) |
| **Background** (3) | Job queue (File/RabbitMQ/Kafka/MongoDB) with priority, delay, retry, dead letters -- service runner -- event system (on/emit/once/off) |
| **Data & Storage** (4) | Session (File/Redis/Valkey/MongoDB/DB), response cache (LRU, TTL), seeder + 50+ fake data generators, messenger (SMTP/IMAP) |
| **Developer Tools** (7) | Dev dashboard (11 tabs), dev toolbar, error overlay (Catppuccin Mocha), dev mailbox, hot reload + CSS hot-reload, code metrics (complexity, coupling, maintainability), AI context installer (7 tools) |
| **Utilities** (7) | DI container (transient + singleton), HtmlElement builder, inline testing (`@tests` decorator), i18n (6 languages), Swagger/OpenAPI auto-generation, CLI scaffolding (`generate model/route/migration/middleware`), structured logging |

**1,427 tests. Zero dependencies. Full parity across Python, PHP, Ruby, and Node.js.**

For full documentation visit **[tina4.com](https://tina4.com)**.

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
```

Path parameters: `{id}`, `{name}`, `{slug}`. Middleware chaining with `->middleware([...])`. Chain `->secure()` to protect GET routes, `->noAuth()` to make routes public.

### ORM

```php
class User extends \Tina4\ORM
{
    public $tableName = "users";
    public $primaryKey = "id";
    public $softDelete = true;

    public $id;
    public $name;
    public $email;

    public $hasMany = [["Order" => "userId"]];
    public $hasOne = [["Profile" => "userId"]];
    public $belongsTo = [["Customer" => "customerId"]];
}

$user = new User($request);
$user->save();
$user->load("email = 'alice@example.com'");
$user->delete();

$users = (new User())->select("*", 100)->asArray();
```

### Database

Unified interface via `Database::create()`:

```php
use Tina4\Database\Database;

$db = Database::create('sqlite:///app.db');
$db = Database::create('postgres://localhost:5432/mydb', username: 'user', password: 'pass');
$db = Database::create('mysql://localhost:3306/mydb', username: 'root', password: 'secret');
$db = Database::create('mssql://localhost:1433/mydb', username: 'sa', password: 'pass');
$db = Database::create('firebird://localhost:3050/path/to/db.fdb', username: 'SYSDBA', password: 'masterkey');

$result = $db->fetch("SELECT * FROM users WHERE age > ?", [18]);
```

### JWT Authentication

```php
$token = \Tina4\Auth::getToken(["user_id" => 42], "your-secret");
$payload = \Tina4\Auth::validToken($token, "your-secret");
$claims = \Tina4\Auth::getPayload($token);
```

POST/PUT/PATCH/DELETE routes require `Authorization: Bearer <token>` by default.

### Sessions

```php
// File, Redis, Valkey, MongoDB, or database-backed sessions
$_SESSION["user_id"] = 42;
$userId = $_SESSION["user_id"];
```

Configure via `.env`:

```bash
TINA4_SESSION_HANDLER=redis
TINA4_SESSION_PATH=redis://localhost:6379
```

### Queues

```php
use Tina4\Queue;

$queue = new Queue();
$queue->push("email.send", ["to" => "alice@example.com", "subject" => "Welcome"]);
$queue->push("report.generate", ["id" => 42], priority: 10, delay: 60);

// Process jobs
$queue->process("email.send", function ($job) {
    sendEmail($job["to"], $job["subject"]);
});
```

Backends: File (default), RabbitMQ, Kafka, MongoDB. Supports priority, delay, retry, and dead letters.

### GraphQL

```php
// Auto-schema from ORM models -- no configuration needed
// GET  /graphql  -> GraphiQL IDE
// POST /graphql  -> Execute queries

// Query:  { users { id, name, email } }
// Mutation support via ORM save/delete
```

### WebSocket

```php
use Tina4\WebSocket;

$ws = new WebSocket('0.0.0.0', 8080);

$ws->on('message', function ($connection, $message) {
    $connection->send("Echo: " . $message);
});

$ws->on('open', function ($connection) {
    $connection->send("Welcome!");
});

$ws->start();
```

Supports RFC 6455, per-path routing, connection manager, broadcast, and Redis backplane for horizontal scaling.

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

### Event System

```php
use Tina4\Events;

Events::on("user.created", function ($user) {
    sendWelcomeEmail($user);
}, priority: 10);

Events::once("app.boot", function () {
    warmCaches();
});

Events::emit("user.created", $userData);
Events::off("user.created");
```

### WSDL / SOAP

```php
// Auto WSDL generation from annotated service classes
// Endpoint: /wsdl
```

### REST Client

```php
$api = new \Tina4\Api("https://api.example.com", "Bearer xyz");
$result = $api->sendRequest("/users/42");
$result = $api->sendRequest("/users", "POST", ["name" => "Alice"]);
```

### Seeder & Fake Data

```php
use Tina4\FakeData;

$fake = new FakeData();
$fake->name();        // "Alice Johnson"
$fake->email();       // "alice.johnson@example.com"
$fake->phone();       // "+1-555-0123"
$fake->address();     // "123 Main St, Springfield"
$fake->paragraph();   // Lorem ipsum...
```

50+ generators for names, emails, addresses, dates, numbers, text, and more.

### Messenger (SMTP/IMAP)

```php
use Tina4\Messenger;

$messenger = new Messenger();
$messenger->sendEmail(
    "recipient@example.com",
    "Subject Line",
    "<h1>Hello</h1><p>Message body</p>"
);
```

Configure via `.env`:

```bash
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USERNAME=user@example.com
SMTP_PASSWORD=secret
```

### Template Engine (Frond)

Twig-compatible with pre-compilation for 2.8x faster rendering:

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

### DI Container

```php
$container = new \Tina4\Container();
$container->singleton('db', fn() => Database::create(getenv('DB_URL')));
$container->register('mailer', fn() => new MailService());

$db = $container->get('db');       // same instance every time
$mailer = $container->get('mailer'); // new instance each time
```

### Inline Testing

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

### i18n (Localization)

JSON translation files with placeholder interpolation. Supports 6 languages out of the box.

```php
use Tina4\I18n;

$i18n = new I18n('en');
echo $i18n->translate("welcome.message", ["name" => "Alice"]);
```

### HtmlElement Builder

```php
extract(\Tina4\HtmlElement::helpers());
echo $_div(["class" => "card"],
    $_h2("Title"),
    $_p("Content")
);
```

---

## Dev Mode

Set `TINA4_DEBUG=true` in `.env` to enable:

- **Dev dashboard** (`/__dev/`) -- 11-tab admin UI with route inspection, query runner, queue management, WebSocket monitor, dev mailbox, and more
- **Dev toolbar** -- fixed bar showing HTTP method, matched route, request ID, and PHP version
- **Error overlay** -- syntax-highlighted stack traces with Catppuccin Mocha theme
- **Hot reload** -- auto-reload on file changes + CSS hot-reload
- **Template debug** -- `{{ dump(variable) }}` available, no caching

---

## CLI Reference

```bash
bin/tina4php serve [port]                    # Start dev server (default: 7146)
bin/tina4php migrate                         # Run pending migrations
bin/tina4php migrate:create "description"    # Create a migration file
bin/tina4php generate model <name>           # Generate ORM model scaffold
bin/tina4php generate route <name>           # Generate route scaffold
bin/tina4php generate migration <desc>       # Generate migration file
bin/tina4php generate middleware <name>       # Generate middleware scaffold
bin/tina4php seed                            # Run database seeders
bin/tina4php ai [--all]                      # Install AI context files
```

Or via Composer:

```bash
composer start                               # Start dev server
composer start --production                  # Start with OPcache + production config
composer test                                # Run test suite
composer tina4 migrate                       # Run migrations
composer tina4 generate model User           # Scaffold a model
```

---

## Performance

Benchmarked with `wrk` — 5,000 requests, 50 concurrent, median of 3 runs:

| Framework | JSON req/s | Deps | Features |
|-----------|-----------|------|----------|
| **Tina4 PHP** | **29,293** | 0 | 54 |
| Slim | 5,714 | 10+ | ~6 |
| Laravel | 445 | 50+ | ~25 |

Tina4 PHP is **5× faster than Slim and 65× faster than Laravel** — with zero dependencies and 54 features built in.

**Across all 4 Tina4 implementations:**

| | Python | PHP | Ruby | Node.js |
|---|--------|-----|------|---------|
| **JSON req/s** | 6,508 | 29,293 | 10,243 | 84,771 |
| **Dependencies** | 0 | 0 | 0 | 0 |
| **Features** | 54 | 54 | 54 | 54 |

---

## Cross-Framework Parity

Tina4 ships the same 54 features across four languages with full test parity:

| Language | Package | Tests |
|----------|---------|-------|
| **Python** | `pip install tina4` | 1,427 |
| **PHP** | `composer require tina4stack/tina4php` | 1,427 |
| **Ruby** | `gem install tina4` | 1,427 |
| **Node.js** | `npm install tina4` | 1,427 |

Same routing, same ORM, same templates, same CLI, same dev tools. Learn one, deploy in any.

---

## Environment

Key `.env` variables:

```bash
# Core
TINA4_DEBUG=true                        # Enable debug mode
DATABASE_PATH=app.db                    # SQLite database path (or use DATABASE_URL)
DATABASE_URL=postgres://localhost/mydb  # Connection URL for any database
SECRET=your-jwt-secret                  # JWT signing secret
TINA4_LOCALE=en                         # Localization language

# Database
TINA4_DB_CACHE=true                     # Enable query caching (4x speedup)
TINA4_AUTOCOMMIT=false                  # Auto-commit control (default: off)

# Sessions
TINA4_SESSION_HANDLER=file              # file, redis, valkey, mongodb, database
TINA4_SESSION_PATH=redis://localhost    # Session backend URL
TINA4_SESSION_SAMESITE=Lax             # SameSite cookie attribute

# Cache
TINA4_CACHE_TTL=60                      # Response cache TTL in seconds
TINA4_CACHE_MAX_ENTRIES=1000            # Max cached responses

# WebSocket
TINA4_WS_BACKPLANE=redis               # WebSocket scaling backplane
TINA4_WS_BACKPLANE_URL=redis://localhost # Backplane connection URL

# Swagger
SWAGGER_TITLE=My API                    # Swagger page title
SWAGGER_DESCRIPTION=API docs            # Swagger description

# SMTP
SMTP_HOST=smtp.example.com             # Mail server
SMTP_PORT=587                           # Mail port
SMTP_USERNAME=user@example.com         # Mail username
SMTP_PASSWORD=secret                    # Mail password
```

---

## Zero-Dependency Philosophy

Tina4 PHP is built from the ground up with no third-party runtime dependencies in core. The framework requires only PHP 8.2+ with `openssl` and `json` extensions. Database drivers are optional and installed separately. This keeps the full deployment under 8 MB and minimizes your project's carbon footprint.

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

**Sponsored with :blue_heart: by Code Infinity**

[<img src="https://codeinfinity.co.za/wp-content/uploads/2025/09/c8e-logo-github.png" alt="Code Infinity" width="100">](https://codeinfinity.co.za/about-open-source-policy?utm_source=github&utm_medium=website&utm_campaign=opensource_campaign&utm_id=opensource)

*Supporting open source communities <span style="color: #1DC7DE;">*</span> Innovate <span style="color: #1DC7DE;">*</span> Code <span style="color: #1DC7DE;">*</span> Empower*
