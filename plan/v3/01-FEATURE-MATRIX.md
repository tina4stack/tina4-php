# Tina4 v3.0 — Feature Implementation Matrix

> **Last updated:** 2026-03-20
> **Reference implementation:** tina4-python (100% complete, 622 tests, zero deps)

## Legend
- [x] Fully implemented
- [~] Partially implemented (needs v3 work)
- [ ] Not implemented

---

## Phase 1: Foundation (Zero-Dep Core)

| # | Feature | Python | PHP | Ruby | Node.js |
|---|---------|--------|-----|------|---------|
| 1 | DotEnv parser (.env loading) | [x] | [~] 3P env pkg | [x] | [ ] |
| 2 | Structured logger (JSON/text, rotation) | [x] | [~] basic debug | [~] text only | [~] console only |
| 3 | Database adapter interface | [x] | [x] | [x] | [x] |
| 4 | SQLite adapter | [x] | [x] | [x] | [x] better-sqlite3 |
| 5 | DATABASE_URL parser | [x] | [ ] | [x] | [ ] |
| 6 | Router (pattern matching, params) | [x] | [x] | [x] | [x] file-based |
| 7 | Middleware pipeline | [x] | [x] | [x] | [x] |
| 8 | Health check endpoint (/health) | [x] | [ ] | [ ] | [ ] |
| 9 | Graceful shutdown (signals) | [x] | [ ] | [~] basic | [x] |
| 10 | CORS middleware (declarative) | [x] | [~] basic | [~] basic | [~] basic |
| 11 | Rate limiter (sliding window) | [x] | [ ] | [ ] | [ ] |
| 12 | Response types (json/html/xml/file/redirect) | [x] | [x] | [x] | [x] |

**Phase 1 totals:** Python 12/12 | PHP 5/12 | Ruby 7/12 | Node.js 5/12

---

## Phase 2: ORM & Data Layer

| # | Feature | Python | PHP | Ruby | Node.js |
|---|---------|--------|-----|------|---------|
| 13 | ORM base class (SQL-first) | [x] | [x] | [x] | [~] convention models |
| 14 | Soft delete (deleted_at, restore, withTrashed) | [x] | [x] | [ ] | [ ] |
| 15 | Relationships (hasOne, hasMany, eager load) | [x] | [x] | [ ] | [ ] |
| 16 | Scopes (reusable query filters) | [x] | [~] tableFilter | [ ] | [ ] |
| 17 | Field mapping (property to column) | [x] | [x] | [x] field_types | [ ] |
| 18 | Paginated results (standardized format) | [x] | [x] | [x] | [x] |
| 19 | Result/ORM caching (in-memory, TTL) | [x] | [~] 3P phpfastcache | [ ] | [ ] |
| 20 | Input validation (from field defs) | [x] | [~] basic | [~] nullable only | [x] |
| 21 | PostgreSQL adapter | [x] | [x] | [x] | [ ] |
| 22 | MySQL adapter | [x] | [x] | [x] | [ ] |
| 23 | MSSQL adapter | [x] | [x] | [x] | [ ] |
| 24 | Firebird adapter | [x] | [x] | [x] | [ ] |
| 25 | ODBC adapter | [x] | [ ] | [ ] | [ ] |
| 26 | MongoDB adapter (SQL translation) | [x] | [ ] | [ ] | [ ] |
| 27 | Migrations (run + create + rollback) | [x] | [x] | [x] | [~] auto-sync only |

**Phase 2 totals:** Python 15/15 | PHP 10/15 | Ruby 8/15 | Node.js 2/15

---

## Phase 3: Frond Template Engine (Zero-Dep)

| # | Feature | Python | PHP | Ruby | Node.js |
|---|---------|--------|-----|------|---------|
| 28 | Lexer (tokenize Frond syntax) | [x] | [ ] uses 3P twig | [x] custom twig | [ ] uses 3P twig |
| 29 | Parser (build AST) | [x] | [ ] | [~] basic | [ ] |
| 30 | Compiler (to closures/functions) | [x] | [ ] | [~] basic | [ ] |
| 31 | Runtime (execute with context) | [x] | [ ] | [x] | [ ] |
| 32 | Filters (~55 filters) | [x] | [ ] | [~] basic set | [ ] |
| 33 | Tags (for, if, block, extends, etc.) | [x] | [ ] | [~] basic set | [ ] |
| 34 | Tests (defined, empty, odd, even, etc.) | [x] | [ ] | [ ] | [ ] |
| 35 | Functions (range, cycle, etc.) | [x] | [ ] | [ ] | [ ] |
| 36 | Extensibility API (addFilter/Function/Tag) | [x] | [ ] | [ ] | [ ] |
| 37 | Auto-escaping (html/js/css/url) | [x] | [ ] | [~] basic | [ ] |
| 38 | Sandboxing (restrict template access) | [x] | [ ] | [ ] | [ ] |
| 39 | Template caching (compiled cache) | [x] | [ ] | [ ] | [ ] |
| 40 | Fragment caching ({% cache %} tag) | [x] | [ ] | [ ] | [ ] |

**Phase 3 totals:** Python 13/13 | PHP 0/13 | Ruby 4/13 | Node.js 0/13

---

## Phase 4: Auth & Sessions

| # | Feature | Python | PHP | Ruby | Node.js |
|---|---------|--------|-----|------|---------|
| 41 | JWT (zero-dep, HS256/RS256) | [x] | [~] 3P php-jwt | [~] jwt gem | [ ] |
| 42 | Session: file backend | [x] | [x] PHP native | [x] | [ ] |
| 43 | Session: Redis backend | [x] | [ ] | [x] | [ ] |
| 44 | Session: Valkey backend | [x] | [ ] | [ ] | [ ] |
| 45 | Session: MongoDB backend | [x] | [ ] | [x] | [ ] |
| 46 | Session: database backend | [x] | [ ] | [ ] | [ ] |
| 47 | Swagger/OpenAPI generation | [x] | [x] | [x] | [x] |

**Phase 4 totals:** Python 7/7 | PHP 2/7 | Ruby 4/7 | Node.js 1/7

---

## Phase 5: Extended Features

| # | Feature | Python | PHP | Ruby | Node.js |
|---|---------|--------|-----|------|---------|
| 48 | Queue (DB-backed, zero-dep) | [x] | [ ] | [~] file-based | [ ] |
| 49 | SCSS compiler (zero-dep) | [x] | [~] 3P scssphp | [~] optional sassc | [ ] |
| 50 | API client (stdlib HTTP) | [x] | [x] curl | [x] Net::HTTP | [ ] |
| 51 | GraphQL (zero-dep parser) | [x] | [ ] | [x] | [ ] |
| 52 | WebSocket (zero-dep) | [x] | [~] Swoole | [x] | [ ] |
| 53 | WSDL/SOAP | [x] | [x] | [x] | [ ] |
| 54 | Localization/i18n (JSON translations) | [x] | [~] partial | [x] | [ ] |
| 55 | Email/Messenger (SMTP) | [x] | [x] | [ ] | [ ] |
| 56 | Seeder/FakeData (50+ generators) | [x] | [ ] | [x] | [ ] |
| 57 | Auto-CRUD (from models) | [x] | [x] | [~] basic | [x] |
| 58 | Event/listener system (priority, async) | [x] | [ ] | [ ] | [ ] |

**Phase 5 totals:** Python 11/11 | PHP 4/11 | Ruby 6/11 | Node.js 1/11

---

## Phase 6: CLI & Developer Experience

| # | Feature | Python | PHP | Ruby | Node.js |
|---|---------|--------|-----|------|---------|
| 59 | CLI: init (scaffold project) | [x] | [x] | [x] | [x] |
| 60 | CLI: serve (dev server + hot reload) | [x] | [x] | [x] | [x] |
| 61 | CLI: migrate (run/create/rollback) | [x] | [x] | [x] | [ ] |
| 62 | CLI: seed (run/create) | [x] | [ ] | [x] | [ ] |
| 63 | CLI: test (run tests) | [x] | [x] | [x] | [ ] |
| 64 | CLI: routes (list all routes) | [x] | [ ] | [x] | [ ] |
| 65 | Debug overlay (dev mode injection) | [x] | [~] basic | [ ] | [ ] |
| 66 | Dev admin dashboard (11 tabs) | [x] | [ ] | [ ] | [ ] |
| 67 | Configurable error pages (404/500/etc.) | [x] | [ ] | [ ] | [ ] |
| 68 | Request ID tracking | [x] | [ ] | [ ] | [ ] |
| 69 | AI tool integration | [x] | [ ] | [ ] | [ ] |
| 70 | Carbonah benchmarks | [x] | [ ] | [ ] | [ ] |

**Phase 6 totals:** Python 12/12 | PHP 4/12 | Ruby 6/12 | Node.js 2/12

---

## Phase 7: Testing

| # | Feature | Python | PHP | Ruby | Node.js |
|---|---------|--------|-----|------|---------|
| 71 | Comprehensive test suite | [x] 622 tests | [~] 7 test files | [~] 22 spec files | [~] 21 assertions |
| 72 | Frond template tests | [x] | [ ] | [ ] | [ ] |
| 73 | ORM tests (CRUD, soft delete, relations) | [x] | [~] | [~] | [ ] |
| 74 | Database driver tests (per driver) | [x] | [~] | [~] | [~] SQLite only |
| 75 | Router tests (patterns, middleware) | [x] | [x] | [x] | [x] |
| 76 | Auth tests (JWT, sessions) | [x] | [x] | [~] | [ ] |

**Phase 7 totals:** Python 6/6 | PHP 3/6 | Ruby 3/6 | Node.js 2/6

---

## Frontend (shared across all backends)

| # | Feature | Python | PHP | Ruby | Node.js |
|---|---------|--------|-----|------|---------|
| 77 | frond.js / tina4helper.js | [x] | [ ] | [ ] | [ ] |
| 78 | tina4css | [x] | [ ] | [ ] | [ ] |

---

## Grand Summary

| Framework | Done | Partial | Not Done | Total Features | Completeness | 3P to Remove |
|-----------|:----:|:-------:|:--------:|:--------------:|:------------:|:------------:|
| **Python** | **78** | 0 | 0 | 78 | **100%** | 0 |
| **PHP** | 24 | 10 | 44 | 78 | **31%** | 4 (twig, jwt, phpfastcache, scssphp) |
| **Ruby** | 29 | 11 | 38 | 78 | **37%** | 2 (jwt gem, thor) |
| **Node.js** | 13 | 3 | 62 | 78 | **17%** | 2 (twig npm, better-sqlite3 OK) |

**Python is the reference implementation.** All features, APIs, and test specs follow Python's patterns adapted to each language's idioms.

### Priority Order for v3 Implementation
1. **Phase 1 (Foundation)** — must be solid before anything else
2. **Phase 3 (Frond)** — zero-dep template engine is the biggest single effort
3. **Phase 2 (ORM/Data)** — standardize and extend
4. **Phase 4 (Auth/Sessions)** — security layer
5. **Phase 5 (Extended)** — feature parity
6. **Phase 6 (CLI/DX)** — developer experience
7. **Phase 7 (Testing)** — comprehensive test coverage throughout
