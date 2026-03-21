# Tina4 PHP v3 — Feature Demos

Comprehensive demos for every feature in the Tina4 PHP framework. Each file contains explanations, copy-paste-ready code snippets, and best practices.

Visit [https://tina4.com](https://tina4.com) for full documentation.

## How to Run the Demo App

The demo is a single runnable PHP application that demonstrates every working feature of Tina4 PHP v3.

**Prerequisites:** PHP 8.2+ with the `openssl` and `json` extensions.

```bash
# From the project root, install dependencies first:
composer install

# Then start the demo:
cd demo
php app.php
```

The server starts on **http://localhost:7146**. Open it in your browser to see the landing page with links to every feature demo.

Each route returns JSON with this structure:
```json
{
    "feature": "Feature Name",
    "status": "working|partial|missing",
    "output": { ... },
    "notes": "Explanation of what this demo shows"
}
```

Press `Ctrl+C` to stop the server.

## Getting Started (Framework)

```bash
composer require tina4stack/tina4php
composer tina4php initialize:run
composer start
```

## Feature Index

| # | Feature | File | Description |
|---|---------|------|-------------|
| 01 | [Routing](01-routing.md) | `01-routing.md` | Route registration, path params, groups, middleware chaining, cache/secure modifiers |
| 02 | [ORM](02-orm.md) | `02-orm.md` | Active record models, save/load/delete/find, soft delete, relationships, field mapping |
| 03 | [Database](03-database.md) | `03-database.md` | DatabaseAdapter interface, SQLite3 adapter, query/fetch/exec, transactions, DATABASE_URL |
| 04 | [Templates](04-templates.md) | `04-templates.md` | Frond template engine, variables, filters, inheritance, includes, macros, sandbox mode |
| 05 | [Middleware](05-middleware.md) | `05-middleware.md` | CORS middleware, rate limiting, custom middleware, route-specific and group middleware |
| 06 | [Auth](06-auth.md) | `06-auth.md` | JWT tokens (HS256/RS256), password hashing (PBKDF2), auth middleware, secure routes |
| 07 | [Config](07-config.md) | `07-config.md` | App class, DotEnv loading, environment variables, debug mode, health checks |
| 08 | [Request & Response](08-request-response.md) | `08-request-response.md` | Request parsing, query params, body, headers, Response builder, cookies, redirects |
| 09 | [Sessions](09-sessions.md) | `09-sessions.md` | File and Redis backends, flash data, session regeneration, TTL configuration |
| 10 | [Migrations](10-migrations.md) | `10-migrations.md` | SQL migration files, batch tracking, rollback, migration naming conventions |
| 11 | [Auto-CRUD](11-auto-crud.md) | `11-auto-crud.md` | Automatic REST API generation from ORM models, pagination, filtering, sorting |
| 12 | [Logging](12-logging.md) | `12-logging.md` | Structured JSON logging, log levels, rotation, request ID correlation, dev mode |
| 13 | [Queue](13-queue.md) | `13-queue.md` | File-backed job queue, push/pop/process, delayed jobs, retries, failed job handling |
| 14 | [Services](14-services.md) | `14-services.md` | Background service runner, cron scheduling, daemon mode, process management |
| 15 | [GraphQL](15-graphql.md) | `15-graphql.md` | Zero-dependency GraphQL engine, types, queries, mutations, fragments, variables |
| 16 | [Internationalization](16-i18n.md) | `16-i18n.md` | I18n with JSON locale files, dot-notation keys, parameter substitution, fallbacks |
| 17 | [WebSocket](17-websocket.md) | `17-websocket.md` | RFC 6455 WebSocket server, event handlers, broadcast, rooms |
| 18 | [SCSS Compiler](18-scss.md) | `18-scss.md` | Zero-dependency SCSS-to-CSS compilation, variables, nesting, mixins, imports |
| 19 | [FakeData](19-seeder.md) | `19-seeder.md` | Fake data generation, database seeding, reproducible test data |
| 20 | [Route Discovery](20-route-discovery.md) | `20-route-discovery.md` | File-based route convention, directory-to-URL mapping, catch-all params |
| 21 | [Constants](21-constants.md) | `21-constants.md` | HTTP status codes, content type constants for clean route handlers |
| 22 | [Deployment](22-deployment.md) | `22-deployment.md` | Swoole HTTP/TCP servers, Docker setup, production configuration |

## Architecture Overview

Tina4 v3 is a **zero-dependency** PHP framework. Every component is built from scratch using only PHP built-in functions. The framework requires PHP 8.2+ and ships with PSR-4 autoloading under the `Tina4\` namespace.

```
composer.json          # Project manifest
index.php              # Application entry point
.env                   # Environment configuration
src/
  routes/              # Auto-discovered route files
  orm/                 # ORM model definitions
  templates/           # Frond/Twig-like templates
  services/            # Background services
  migrations/          # SQL migration files
  scss/                # SCSS stylesheets
  locales/             # i18n JSON files
  seeds/               # Database seed files
  public/              # Static assets
```
