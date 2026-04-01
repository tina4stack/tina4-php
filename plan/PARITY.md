# Tina4 PHP — Feature Parity Checklist

Version: 3.10.37 | Last updated: 2026-03-31 | Reference: tina4-python

This checklist tracks feature parity against the Python reference implementation.

## Core HTTP Engine

| Feature | Present | Up to scratch | Notes |
|---------|---------|---------------|-------|
| Router (GET/POST/PUT/PATCH/DELETE/ANY) | [x] | [x] | `Router.php` — 873 lines |
| Path params ({id:int}, {price:float}, {path:path}) | [x] | [x] | |
| Wildcard routes (*) | [x] | [x] | |
| Route grouping | [x] | [x] | |
| Route discovery (auto-load from src/) | [x] | [x] | `RouteDiscovery.php` |
| Server (built-in PHP dev server) | [x] | [x] | `Server.php` |
| Request object | [x] | [x] | `Request.php` |
| Response object | [x] | [x] | `Response.php` — JSON/HTML/streaming |
| Static file serving | [x] | [x] | `StaticFiles.php` |
| CORS middleware | [x] | [x] | `CorsMiddleware.php` |
| Health endpoint | [x] | [x] | |
| Constants | [x] | [x] | `Constants.php` |

## Auth & Security

| Feature | Present | Up to scratch | Notes |
|---------|---------|---------------|-------|
| JWT auth (zero-dep) | [x] | [x] | `Auth.php` |
| Password hashing | [x] | [x] | |
| @secured / @noauth | [x] | [x] | Via route annotations |
| Form token (CSRF) | [x] | [x] | |
| CSRF middleware | [x] | [x] | `CsrfMiddleware.php` |
| Rate limiter | [x] | [x] | `RateLimiter.php` |
| Validator | [x] | [x] | `Validator.php` |
| Security headers middleware | [x] | [x] | `SecurityHeaders.php` |
| Request logger middleware | [x] | [x] | `RequestLogger.php` |

## Database

| Feature | Present | Up to scratch | Notes |
|---------|---------|---------------|-------|
| URL-based multi-driver connection | [x] | [x] | `Database.php` |
| Connection pooling | [x] | [ ] | Limited compared to Python |
| SQLite3 driver | [x] | [x] | `SQLite3Adapter.php` |
| PostgreSQL driver | [x] | [x] | `PostgresAdapter.php` |
| MySQL driver | [x] | [x] | `MySQLAdapter.php` |
| MSSQL driver | [x] | [x] | `MSSQLAdapter.php` |
| Firebird driver | [x] | [x] | `FirebirdAdapter.php` |
| ODBC driver | [ ] | [ ] | Not implemented — Python has it |
| DatabaseResult (to_json, to_array, to_csv, to_paginate) | [x] | [x] | |
| SQL translation | [x] | [x] | `SqlTranslation.php` |
| Query caching | [x] | [x] | `CachedDatabase.php` |
| get_next_id (race-safe sequences) | [x] | [x] | |
| Transactions | [x] | [x] | |
| DatabaseUrl parser | [x] | [x] | `DatabaseUrl.php` |

## ORM

| Feature | Present | Up to scratch | Notes |
|---------|---------|---------------|-------|
| Active Record (save/load/delete/select) | [x] | [x] | `ORM.php` — 1192 lines |
| Field types | [x] | [x] | |
| Relationships (has_many, has_one, belongs_to) | [x] | [x] | |
| Soft delete | [x] | [x] | |
| create_table() | [x] | [x] | |
| QueryBuilder | [x] | [x] | `QueryBuilder.php` |
| AutoCRUD | [x] | [x] | `AutoCrud.php` |

## Template Engine (Frond)

| Feature | Present | Up to scratch | Notes |
|---------|---------|---------------|-------|
| Twig-compatible syntax | [x] | [x] | `Frond.php` — 2297 lines |
| Block inheritance | [x] | [x] | |
| parent()/super() in blocks | [x] | [x] | |
| Include/import/macro | [x] | [x] | |
| Filters | [x] | [x] | |
| Custom filters/globals/tests | [x] | [x] | |
| SafeString | [x] | [x] | |
| Fragment caching | [x] | [x] | |
| Raw blocks | [x] | [x] | |
| Sandbox mode | [x] | [x] | |
| form_token / formTokenValue | [x] | [x] | |
| Arithmetic in {% set %} | [x] | [x] | |
| Filter-aware conditions | [x] | [x] | |
| Dev mode cache bypass | [x] | [x] | |

## API & Protocols

| Feature | Present | Up to scratch | Notes |
|---------|---------|---------------|-------|
| API client (zero-dep) | [x] | [x] | `Api.php` |
| Swagger/OpenAPI generator | [x] | [x] | `Swagger.php` |
| GraphQL engine | [x] | [x] | `GraphQL.php` |
| WSDL/SOAP server | [x] | [x] | `WSDL.php` |
| MCP server | [x] | [x] | `MCP.php` — 997 lines |

## Real-time & Messaging

| Feature | Present | Up to scratch | Notes |
|---------|---------|---------------|-------|
| WebSocket server | [x] | [x] | `WebSocket.php` |
| WebSocket backplane | [x] | [x] | `WebSocketBackplane.php` |
| Messenger (SMTP/IMAP) | [x] | [x] | `Messenger.php` — 1078 lines |

## Queue

| Feature | Present | Up to scratch | Notes |
|---------|---------|---------------|-------|
| Database-backed job queue | [x] | [x] | `Queue.php` — 869 lines |
| Kafka backend | [x] | [x] | |
| RabbitMQ backend | [x] | [x] | |
| MongoDB backend | [x] | [x] | |

## Sessions

| Feature | Present | Up to scratch | Notes |
|---------|---------|---------------|-------|
| File session handler | [x] | [x] | Default PHP sessions |
| Database session handler | [x] | [x] | |
| Redis session handler | [ ] | [ ] | Python has it, PHP doesn't |
| Valkey session handler | [x] | [x] | |
| MongoDB session handler | [x] | [x] | |

## Infrastructure

| Feature | Present | Up to scratch | Notes |
|---------|---------|---------------|-------|
| Migrations | [x] | [x] | `Migration.php` |
| Seeder / FakeData | [x] | [x] | `FakeData.php` |
| i18n / Localization | [x] | [x] | `I18n.php` |
| SCSS compiler | [x] | [x] | `ScssCompiler.php` |
| Events | [x] | [x] | `Events.php` |
| DotEnv loader | [x] | [x] | `DotEnv.php` |
| Structured logging | [x] | [x] | `Log.php` |
| Error overlay | [x] | [x] | `ErrorOverlay.php` |
| DI Container | [x] | [x] | `Container.php` |
| Response cache | [x] | [x] | `ResponseCache.php` |
| Service runner | [x] | [x] | `ServiceRunner.php` |

## Dev Tools

| Feature | Present | Up to scratch | Notes |
|---------|---------|---------------|-------|
| DevAdmin dashboard | [x] | [x] | `DevAdmin.php` — 1769 lines |
| DevMailbox | [x] | [x] | `DevMailbox.php` |
| DevReload | [x] | [ ] | Less sophisticated than Python (no jurigged) |
| Gallery (interactive examples) | [x] | [x] | |
| Metrics (code analysis) | [ ] | [ ] | Not yet implemented — Python has it |
| Version check (proxy) | [x] | [x] | |

## Testing & CLI

| Feature | Present | Up to scratch | Notes |
|---------|---------|---------------|-------|
| TestClient | [x] | [x] | `Testing.php` |
| Inline testing | [x] | [x] | |
| CLI (init, serve, migrate, generate) | [x] | [x] | `bin/tina4php` |
| AI context detection | [x] | [x] | `AI.php` |

## Static Assets

| Feature | Present | Up to scratch | Notes |
|---------|---------|---------------|-------|
| Minified CSS (tina4.min.css) | [x] | [x] | |
| Minified JS (tina4.min.js, frond.min.js) | [x] | [x] | |
| HtmlElement builder | [x] | [x] | |

## Gaps vs Python Reference

| Gap | Priority | Notes |
|-----|----------|-------|
| ODBC driver | Low | Python has it, PHP doesn't |
| Redis session handler | Medium | Python has it, PHP only has Valkey |
| Metrics (code analysis) | High | Not yet ported from Python |
| DevReload hot-patching | Low | PHP can't hot-patch like Python's jurigged |

## Summary

- **Total features**: 75
- **Present**: 72/75
- **Up to scratch**: 70/75
- **Gaps**: 3 missing features, 2 not up to scratch
