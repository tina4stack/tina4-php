# tina4-php v3.0 — Gameplan

## Current State (v2)
- **Strong:** Most mature, ORM with relationships + soft delete + scopes, Swagger, WSDL, route caching, CRUD generator, Messenger/Email, 6 database drivers
- **Weak:** No queue, no seeder, no localization, no GraphQL, session relies on PHP native (no Redis/Memcache), split-package architecture adds maintenance burden
- **Third-party to remove:** `twig/twig`, `nowakowskir/php-jwt`, `phpfastcache/phpfastcache`, `scssphp/scssphp`, `psr/log`

## v3 Architecture Change
- **Monorepo** — consolidate tina4php-core, tina4php-orm, tina4php-database, tina4php-env, tina4php-debug, tina4php-shape into ONE package
- Split out later when v3 is stable
- Single `composer.json`, single namespace `Tina4\`

## v3 Branch Strategy
- Create `v3` branch from current `main`
- v2 continues independently
- v3 development in monorepo under `php/`

## Implementation Phases

### Phase 1: Foundation (Zero-Dep Core)
1. [ ] **DotEnv parser** — replace `tina4php-env`, parse `.env` natively
2. [ ] **Structured logger** — replace `psr/log`, JSON (prod) / text (dev), request ID
3. [ ] **Database adapter interface** — standardize contract, merge `tina4php-database`
4. [ ] **SQLite adapter** — using `ext-sqlite3`
5. [ ] **DATABASE_URL parser** — auto-detect driver from URL scheme
6. [ ] **Router refactor** — keep route caching, add route model binding, standardize response types
7. [ ] **Middleware pipeline** — keep existing, standardize hook points
8. [ ] **Health check endpoint** — auto-registered `/health`
9. [ ] **Graceful shutdown** — `pcntl_signal` handlers (+ Swoole shutdown hooks)
10. [ ] **CORS middleware** — declarative config from env vars
11. [ ] **Rate limiter** — in-memory + database-backed

### Phase 2: ORM & Data Layer
12. [ ] **ORM consolidation** — merge `tina4php-orm` into main, keep SQL-first approach
13. [ ] **Soft delete** — keep existing, standardize API (`softDelete()`, `restore()`, `forceDelete()`, `withTrashed()`)
14. [ ] **Relationships** — keep existing `hasOne`/`hasMany`, standardize eager loading
15. [ ] **Scopes** — keep existing `tableFilter`, add named scopes
16. [ ] **Field mapping** — keep existing, standardize
17. [ ] **Paginated results** — standardize to `PaginatedResult` JSON format
18. [ ] **Result caching** — replace `phpfastcache`, build native in-memory + file cache
19. [ ] **Input validation** — from field definitions, form request pattern
20. [ ] **PostgreSQL adapter** — using `ext-pgsql`
21. [ ] **MySQL adapter** — using `ext-mysqli`
22. [ ] **MSSQL adapter** — using `ext-sqlsrv`
23. [ ] **Firebird adapter** — using `ext-interbase`
24. [ ] **ODBC adapter** — using `ext-odbc`
25. [ ] **Migrations** — keep existing with rollback, add `.down.sql` file support

### Phase 3: Frond Template Engine
26. [ ] **Lexer** — tokenize Frond syntax (replaces `twig/twig`)
27. [ ] **Parser** — build AST
28. [ ] **Compiler** — compile to PHP closures (cached)
29. [ ] **Runtime** — execute with context
30. [ ] **All filters** — implement full filter set (~55 filters)
31. [ ] **All tags** — full tag set
32. [ ] **Tests** — all type tests
33. [ ] **Functions** — all built-in functions
34. [ ] **Extensibility API** — `addFilter`/`addFunction`/`addGlobal`/`addTest`/`addTag`
35. [ ] **Auto-escaping** — html/js/css/url strategies (replace Twig escaper)
36. [ ] **Sandboxing** — restrict access for user-facing templates
37. [ ] **Template caching** — file-based compiled template cache
38. [ ] **Fragment caching** — `{% cache %}` tag

### Phase 4: Auth & Sessions
39. [ ] **JWT implementation** — replace `nowakowskir/php-jwt`, use `ext-openssl` directly
40. [ ] **Session: file backend** — for local dev (replace PHP native session dependency)
41. [ ] **Session: Redis backend** — NEW, using `ext-redis`
42. [ ] **Session: Memcache backend** — NEW, using `ext-memcached`
43. [ ] **Session: MongoDB backend** — NEW, using `ext-mongodb`
44. [ ] **Session: database backend** — NEW, using connected DB adapter
45. [ ] **Swagger/OpenAPI** — keep existing, standardize output

### Phase 5: Extended Features
46. [ ] **Queue (DB-backed)** — NEW, zero-dep, uses connected database
47. [ ] **SCSS compiler** — replace `scssphp/scssphp`, build native PHP SCSS parser
48. [ ] **API client** — keep existing `curl`-based, it's stdlib
49. [ ] **GraphQL** — NEW, port from Python/Ruby zero-dep parser
50. [ ] **WebSocket (Swoole)** — keep existing Swoole integration, document WebSocket usage
51. [ ] **WSDL/SOAP** — keep existing, standardize API
52. [ ] **Localization** — NEW, JSON/YAML translation files
53. [ ] **Email/Messenger** — keep existing, remove PHPMailer dependency if any
54. [ ] **Seeder/FakeData** — NEW, port from Python/Ruby
55. [ ] **Auto-CRUD** — keep existing `generateCRUD`, standardize endpoints
56. [ ] **Event/listener system** — NEW, simple observer pattern

### Phase 6: CLI & DX
57. [ ] **CLI: init** — scaffold project with standardized structure
58. [ ] **CLI: serve** — keep existing `webservice:run`
59. [ ] **CLI: migrate** — keep existing `migrate:run`/`migrate:create`, add rollback
60. [ ] **CLI: seed** — NEW, `seed:run`/`seed:create`
61. [ ] **CLI: test** — keep existing `tests:run`
62. [ ] **CLI: routes** — NEW, list all registered routes
63. [ ] **Debug overlay** — inject shared debug overlay in dev mode
64. [ ] **frond.js** — update existing, align with shared spec

### Phase 7: Testing
65. [ ] **Implement all shared test specs**
66. [ ] **Frond tests** — 20 positive + 5 negative
67. [ ] **ORM tests** — full coverage including soft delete, relationships, scopes
68. [ ] **Database tests** — per driver
69. [ ] **Router tests** — patterns, middleware, caching, model binding
70. [ ] **Auth tests** — JWT, session backends
71. [ ] **Queue tests** — enqueue/dequeue/retry/failure
72. [ ] **Integration tests** — end-to-end HTTP
73. [ ] **Performance benchmarks**

## Naming Conventions (PHP Best Practice + Tina4 Convention)
- Classes: `PascalCase` — `DatabaseAdapter`, `UserModel`, `FrondEngine`
- Methods: `camelCase` — `fetchOne()`, `softDelete()`, `hasMany()`
- Constants: `UPPER_SNAKE` — `DATABASE_URL`, `TINA4_DEBUG`
- Files: `PascalCase.php` — `DatabaseAdapter.php`, `FrondEngine.php`
- Namespace: `Tina4\` — `Tina4\ORM`, `Tina4\Database`, `Tina4\Frond`

## Dependencies (v3)
### Zero (built from scratch)
- Frond, JWT, SCSS, DotEnv, Queue, Logger, Rate limiter, GraphQL, Seeder, Localization, Event system, Cache

### PHP extensions only (stdlib)
- `ext-openssl`, `ext-json`, `ext-curl`, `ext-fileinfo`, `ext-libxml`, `ext-mbstring`

### Database drivers (optional PHP extensions)
- `ext-sqlite3` (SQLite)
- `ext-pgsql` (PostgreSQL)
- `ext-mysqli` (MySQL)
- `ext-sqlsrv` (MSSQL)
- `ext-interbase` (Firebird)
- `ext-odbc` (ODBC)

### Session backends (optional PHP extensions)
- `ext-redis` (Redis)
- `ext-memcached` (Memcache)
- `ext-mongodb` (MongoDB)

### Optional
- `ext-openswoole` or `ext-swoole` (Swoole WebSocket/async)
