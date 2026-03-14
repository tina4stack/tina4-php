# CLAUDE.md — tina4stack/tina4php

## What This Is

Tina4 PHP is the main framework package ("This is not a 4ramework"). It provides routing, templating (Twig), SCSS compilation, API generation (OpenAPI/Swagger), WSDL/SOAP services, security (JWT auth), caching (PhpFastCache), messaging, deployment, services/threads, and Slack integration.

This is the top-level package that applications install. It depends on the other tina4stack modules (core, database, orm, debug, env, shape).

## Project Structure

```
Tina4/
  Api/              — Api.php (HTTP client), Swagger.php (OpenAPI), WSDL.php (SOAP)
  Config/           — Framework configuration
  Database/         — Database migration runner
  Deploy/           — GitDeploy (webhook-based deployment)
  Messaging/        — Messenger (email/SMS via HTTP APIs)
  Routing/          — HTTP routing engine (Get, Post, Put, Patch, Delete, Any, Crud)
                      Request/Response, Middleware, Router, Swoole support
  Security/         — Auth.php (JWT-based authentication)
  Service/          — Service.php, Thread.php, Process.php (background workers)
  Slack/            — Slack integration
  Twig/             — Twig template extensions
  Functions.php     — Bootstrap (loaded via composer "files" autoload)
  Initialize.php    — Framework initialization, constants, helpers
  Tina4Php.php      — Main entry point class
Helpers/            — Helper classes (classmap autoloaded)
src/
  app/              — Application code
  orm/              — ORM models
  routes/           — Route definitions
  templates/        — Twig templates
  scss/             — SCSS stylesheets (auto-compiled)
tests/              — PHPUnit tests (67 tests)
bin/                — CLI tools (tina4, tina4service)
migrations/         — Database migration SQL files
```

## Key Architectural Patterns

- **Routing**: Static method registration — `\Tina4\Get::add("/path", function(...) {})`. Routes are collected at file-include time, matched at request time by Router.
- **RouteCore trait**: Shared by Get, Post, Put, Patch, Delete, Any. Each class just sets the method type.
- **Crud**: `\Tina4\Crud::route("/prefix", new OrmObject())` auto-generates REST endpoints for any ORM object.
- **Middleware**: `\Tina4\Middleware::add(function($request) { ... })` — runs before route handlers.
- **Response**: Callable object — `$response($data, $httpCode, $contentType)`.
- **Twig**: Templates in `src/templates/`. Custom extensions in `Tina4/Twig/`.
- **SCSS**: Auto-compiled from `src/scss/` on request.
- **Services**: Long-running background processes via `\Tina4\Service`.
- **Threads**: Fork-based parallel execution via `\Tina4\Thread::trigger()`.

## Best Practices for Building with Tina4

### Project Setup

1. **Always start with `composer create-project tina4stack/tina4php yourproject`** or add `"tina4stack/tina4php": "^2.1"` to an existing project.
2. **Run `composer start`** to launch the built-in PHP server on port 7147.
3. **Use `.env`** for all configuration — never hardcode credentials, secrets, or environment-specific values in PHP files.
4. **Set `TINA4_DEBUG=true`** during development for detailed logging, set to `false` in production.

### Routing Best Practices

- **One route file per resource** — e.g., `src/routes/users.php`, `src/routes/products.php`. Keep files small and focused.
- **Use Crud for standard REST** — `\Tina4\Crud::route("/api/users", new User())` gives you GET, POST, PUT, DELETE automatically. Don't write boilerplate.
- **Always type your parameters** — Route callbacks should have typed parameters matching path variables:
  ```php
  \Tina4\Get::add("/api/users/{userId}", function (int $userId, \Tina4\Response $response) {
      // $userId is auto-cast from the path
  });
  ```
- **Return via $response()** — Always return `$response($data, $httpCode, $contentType)`. Never echo directly.
- **Use middleware for cross-cutting concerns** — Auth checks, CORS headers, logging, rate limiting belong in middleware, not in every route.
- **Document with annotations** — Add `@description`, `@tags`, `@example_response`, `@example_request` PHPDoc tags to routes for automatic Swagger/OpenAPI generation.

### ORM Best Practices

- **One class per table** — Each ORM class maps to one database table. Name the class in singular PascalCase (e.g., `User` maps to `user` table).
- **Use camelCase properties** — The ORM auto-maps `firstName` to `first_name` column. Don't use snake_case in PHP.
- **Declare all properties** — Explicitly declare `public` properties for all table columns. Avoids PHP 8.2 dynamic property deprecation.
- **Use `fieldMapping`** for non-standard column names:
  ```php
  class User extends \Tina4\ORM {
      public $id;
      public $firstName;
      public $lastName;
      public $fieldMapping = ["firstName" => "fname", "lastName" => "lname"];
  }
  ```
- **Use `load()` with parameterized queries** — `$user->load("id = ?", [$id])`. Never concatenate user input into SQL.
- **Use `select()` for complex queries** — `(new User())->select("*")->where("active = ?", [1])->limit(10)->fetch()`.

### Database Best Practices

- **Instantiate $DBA once** in your bootstrap/index.php:
  ```php
  global $DBA;
  $DBA = new \Tina4\DataSQLite3("mydb.db");
  // or: $DBA = new \Tina4\DataMySQL("localhost/mydb", "user", "pass");
  ```
- **Use migrations** for schema changes — `bin/tina4 migrate:create "add user email column"`. Never modify tables directly in production.
- **Use `exec()` for writes, `fetch()` for reads**:
  ```php
  $DBA->exec("INSERT INTO users (name) VALUES (?)", ["John"]);
  $result = $DBA->fetch("SELECT * FROM users WHERE id = ?", [1]);
  ```
- **Check DataError** after operations — `$error = $DBA->error()` returns a `DataError` with code and message.
- **Supported drivers**: SQLite3, MySQL, PostgreSQL, Firebird, MSSQL, ODBC, PDO, MongoDB. Install the driver package you need (e.g., `tina4stack/tina4php-mysql`).

### Template Best Practices

- **Templates go in `src/templates/`** — Twig auto-discovers them.
- **Use Twig inheritance** — Base layout in `base.twig`, extend with `{% extends "base.twig" %}`.
- **Pass data from routes**:
  ```php
  \Tina4\Get::add("/dashboard", function (\Tina4\Response $response) {
      return $response(\Tina4\renderTemplate("dashboard.twig", ["user" => $userData]));
  });
  ```

### Security Best Practices

- **Set `TINA4_SECRET`** in `.env` — Used for JWT token signing. Make it long and random.
- **Use Auth middleware** for protected routes:
  ```php
  \Tina4\Middleware::add(function (\Tina4\Request $request) {
      if (str_starts_with($request->url, "/api/admin")) {
          $auth = new \Tina4\Auth();
          if (!$auth->validToken($request->headers["Authorization"] ?? "")) {
              return \Tina4\Router::forbidden();
          }
      }
  });
  ```
- **Never commit `.env` files** — Add to `.gitignore`. Use `.env.example` as a template.
- **Use HTTPS in production** — Set `TINA4_PROTOCOL=https` in `.env`.
- **CSRF tokens** — Use `\Tina4\Token::getToken()` in forms and validate with `\Tina4\Token::validToken()`.

### Caching Best Practices

- **Enable caching in production** — Set `TINA4_CACHE_ON=true` in `.env`.
- **Use `@cache` annotation** on routes that return static/rarely-changing data.
- **Use `@no-cache`** on routes with user-specific data.
- **Clear cache** via the built-in `/cache/clear` route or programmatically with `\Tina4\Cache`.

### Queue Best Practices (tina4php-queue)

- **Use LiteQueue for development** — Zero config, SQLite-based.
- **Use RabbitMQ or Kafka for production** — Persistent, distributed, reliable.
- **Keep messages small** — Send IDs/references, not full data payloads.
- **Use separate topics** for different message types.
- **Consumer pattern**:
  ```php
  $consumer = new \Tina4\Consumer(new \Tina4\Queue(topic: 'emails'));
  $consumer->runForever(function (\Tina4\QueueMessage $msg) {
      $data = json_decode($msg->data, true);
      sendEmail($data['to'], $data['subject'], $data['body']);
  });
  ```

### Session Best Practices (tina4php-session)

- **Use database backend in production** — Works with any Tina4 database driver.
- **Configure before any output**:
  ```php
  $config = new \Tina4\SessionConfig();
  $config->database = $DBA;
  \Tina4\SessionHandler::start($config);
  ```

### GraphQL Best Practices (tina4php-graphql)

- **Use `fromORM()` for auto-schema** — Generates types, queries, and mutations from your ORM classes.
- **Add custom resolvers** for computed fields or complex business logic.
- **Register the route**:
  ```php
  $schema = new \Tina4\GraphQLSchema();
  $schema->fromORM(\App\User::class);
  \Tina4\GraphQLRoute::register($schema);
  ```

### WSDL/SOAP Best Practices

- **Define `$returnSchemas`** for every public method — Enables proper WSDL type generation.
- **Use typed parameters** — PHP type hints map directly to XSD types.
- **Use `onRequest()` hook** for authentication.
- **Set `SERVICE_URL` constant** when behind a reverse proxy.

## Anti-Patterns to Avoid

- **Don't echo/print in routes** — Always use `$response()`. Direct output breaks content-type headers and caching.
- **Don't use `global $DBA` inside ORM classes** — The ORM auto-discovers it. Only set it once at bootstrap.
- **Don't put business logic in route callbacks** — Keep routes thin. Extract logic to service classes.
- **Don't skip migrations** — Never use `exec("ALTER TABLE...")` in application code. Use the migration system.
- **Don't use `curl_close()`** — Deprecated since PHP 8.0. Handles auto-close. Use `unset($ch)` if you want to be explicit.
- **Don't use `${$variable}` syntax** — Deprecated since PHP 8.2, removed in PHP 9.0. Use `$$variable` or refactor.
- **Don't declare dynamic properties** — Always add `public $propertyName;` declarations. Dynamic properties are deprecated in PHP 8.2.
- **Don't hardcode database credentials** — Use `.env` and the `TINA4_*` constants.
- **Don't commit `vendor/`, `node_modules/`, `.env`, `*.db` files** — Add them to `.gitignore`.

## Dependencies (Runtime)

| Package | Purpose |
|---------|---------|
| tina4stack/tina4php-core | Core utilities, Data class, initialization |
| tina4stack/tina4php-database | DataBase interface, DataResult, DataError, NoSQLParser |
| tina4stack/tina4php-orm | ORM base class, SQL builder |
| tina4stack/tina4php-debug | PSR-3 logger |
| tina4stack/tina4php-env | .env file parser |
| tina4stack/tina4php-shape | HTML element builder |
| twig/twig | Template engine |
| phpfastcache/phpfastcache | File-based caching |
| nowakowskir/php-jwt | JWT token handling |
| scssphp/scssphp | SCSS to CSS compiler |
| coyl/git | Git operations for deployment |

## How to Run Tests

```bash
composer install
composer run-tests
# or
./vendor/bin/phpunit tests --verbose --color
```

Tests use SQLite in-memory (tina4php-sqlite3 is a dev dependency). The test suite has 67 tests covering auth, caching, CRUD routes, response handling, router security, and routing.

## Important Constants

Defined in `Initialize.php` / `.env`:
- `TINA4_PROJECT_ROOT` — Project root directory
- `TINA4_DOCUMENT_ROOT` — Web document root
- `TINA4_CACHE_ON` — Enable/disable PhpFastCache
- `TINA4_DEBUG` — Enable debug logging
- `TINA4_DEBUG_LEVEL` — Log level (DEBUG, INFO, WARNING, ERROR)
- `TINA4_SECRET` — JWT signing secret
- `TINA4_AUTH` — Auth class to use
- `SWAGGER_TITLE`, `SWAGGER_DESCRIPTION`, `SWAGGER_VERSION` — OpenAPI metadata

## Coding Conventions

- **Namespace**: Everything is `namespace Tina4`
- **PHP version**: 8.1+ required
- **No deprecated calls**: `curl_close()` replaced with `unset()`, no `${$var}` syntax
- **Traits**: Used for shared behavior (RouteCore, DataBaseCore pattern)
- **Static registration**: Routes, middleware registered via static methods at include time
- **Global $DBA**: Database connection passed via global variable to ORM objects
- **Response pattern**: Route callbacks receive typed params + `\Tina4\Response $response`, return `$response(...)`
- **camelCase properties** in ORM, auto-mapped to **snake_case columns**
- **One file per class**, PSR-4 autoloading

## Common Tasks

- **Add a route**: Create a PHP file in `src/routes/`, use `\Tina4\Get::add(...)` etc.
- **Add an ORM model**: Create a class extending `\Tina4\ORM` in `src/orm/`
- **Add a migration**: Use `bin/tina4 migrate:create "description"`
- **Add middleware**: `\Tina4\Middleware::add(function($request) { ... })`
- **Generate CRUD**: `\Tina4\Crud::route("/api/users", new User())`
- **WSDL service**: Extend `\Tina4\WSDL`, register with `\Tina4\Any::add()`
- **Add caching**: Set `TINA4_CACHE_ON=true`, use `@cache` on routes
- **Add sessions**: `composer require tina4stack/tina4php-session`, configure with `SessionConfig`
- **Add queues**: `composer require tina4stack/tina4php-queue`, use `Queue`/`Producer`/`Consumer`
- **Add GraphQL**: `composer require tina4stack/tina4php-graphql`, use `GraphQLSchema::fromORM()`
- **Add localization**: `composer require tina4stack/tina4php-localization`, use `Localization`

## Related Modules

The full ecosystem (all under tina4stack/):

| Category | Packages |
|----------|----------|
| **Core** | tina4php-core, tina4php-database, tina4php-orm, tina4php-debug, tina4php-env, tina4php-shape |
| **Database Drivers** | tina4php-sqlite3, tina4php-mysql, tina4php-postgresql, tina4php-firebird, tina4php-mssql, tina4php-odbc, tina4php-pdo, tina4php-mongodb |
| **Features** | tina4php-queue, tina4php-session, tina4php-graphql, tina4php-localization, tina4php-reports |
| **Integrations** | tina4php-shopify, tina4-cms |
| **Frontend** | tina4-css |
| **Tooling** | tina4-module, tina4-documentation |

## Documentation

Full documentation: https://tina4stack.github.io/tina4-documentation/
