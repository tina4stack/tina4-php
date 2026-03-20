# Tina4 v3.0 — Plan Index

> **Last updated:** 2026-03-20

## Status

| Framework | Completeness | Tests | Zero-dep core |
|-----------|:---:|:---:|:---:|
| **Python** | **100%** (73/73 tasks + 3 bonus) | 647 passing | Yes |
| PHP | 44% | — | No (4 3P deps) |
| Ruby | 46% | — | Partial |
| Node.js | 25% | — | No (2 3P deps) |

Python is the **reference implementation**. All other frameworks should match its feature set.

## The Four Frameworks
1. **tina4-php** — PHP 8.2+
2. **tina4-nodejs** — Node.js 20+ / TypeScript
3. **tina4-python** — Python 3.10+
4. **tina4-ruby** — Ruby 3.2+

## Core Philosophy
- **Zero third-party dependencies** — every core feature built from scratch
- **Full feature parity** — same features across all four languages
- **SQL-first ORM** — embrace SQL, paginate and cache results
- **Security first** — auto-escaping, input validation, no arbitrary code execution
- **DX is the top priority** — identical debug overlays, identical test coverage, seamless cross-platform
- **Monorepo development** — build uniform, split out when stable

## Plan Documents

| # | Document | Description |
|---|----------|-------------|
| 00 | [VISION.md](00-VISION.md) | Mission, principles, lessons from other frameworks |
| 01 | [FEATURE-MATRIX.md](01-FEATURE-MATRIX.md) | What exists vs what's missing per framework (**updated**) |
| 02 | [FROND-FEATURES-DECISION.md](02-FROND-FEATURES-DECISION.md) | Full Frond template engine feature set for approval |
| 02b | [FROND-SPEC.md](02-FROND-SPEC.md) | Frond syntax reference and API specification |
| 03 | [TINA4HELPER-SPEC.md](03-TINA4HELPER-SPEC.md) | frond.js unified frontend helper specification |
| 04 | [ARCHITECTURE.md](04-ARCHITECTURE.md) | Monorepo structure, zero-dep strategy, contracts, debug overlay |
| 05 | [GAMEPLAN-PYTHON.md](05-GAMEPLAN-PYTHON.md) | tina4-python v3 — **100% complete** (73/73 tasks) |
| 06 | [GAMEPLAN-PHP.md](06-GAMEPLAN-PHP.md) | tina4-php v3 implementation gameplan (73 tasks) |
| 07 | [GAMEPLAN-RUBY.md](07-GAMEPLAN-RUBY.md) | tina4-ruby v3 implementation gameplan (74 tasks) |
| 08 | [GAMEPLAN-NODEJS.md](08-GAMEPLAN-NODEJS.md) | tina4-nodejs v3 implementation gameplan (75 tasks) |
| 09 | [AI-REFERENCE-SPEC.md](09-AI-REFERENCE-SPEC.md) | CLAUDE.md / llm.txt content — full paradigm reference for AI assistants |
| 10 | [EXAMPLES-AND-DOCS.md](10-EXAMPLES-AND-DOCS.md) | Real-world examples (identical across all four) + README requirements |
| 11 | [QUEUE-SPEC.md](11-QUEUE-SPEC.md) | Queue system: DB core + RabbitMQ/Kafka extensions |
| 12 | [BENCHMARKS-SPEC.md](12-BENCHMARKS-SPEC.md) | Benchmark suite: 9 categories (incl. Carbonah carbon ranking), top 10 frameworks per language |
| 13 | [CONSOLE-AND-ERROR-HANDLING.md](13-CONSOLE-AND-ERROR-HANDLING.md) | Backend console, global exception handler, .broken file system, health check integration |
| 14 | [WEBSOCKET-SPEC.md](14-WEBSOCKET-SPEC.md) | Zero-dep RFC 6455 WebSocket: frame protocol, connection manager, live block integration |
| 15 | [DEPLOYMENT-SPEC.md](15-DEPLOYMENT-SPEC.md) | Dev vs prod servers, Docker (40-80MB), K8s, CLI build/stage/deploy |
| 16 | [DEV-WORKFLOW.md](16-DEV-WORKFLOW.md) | Development → Staging → Production best practices and CLI workflow |
| 17 | [TINA4PRESS.md](17-TINA4PRESS.md) | VitePress-style docs site generator built on tina4-js, powers tina4.com |

## Key Decisions Made
- Template engine name: **Frond** (twig-like, zero-dep, identical syntax across all four)
- Frontend helper: **frond.js** (unified, zero-dep, works on all backends)
- Naming: Same concept names, language-idiomatic casing (snake_case for Python/Ruby, camelCase for PHP/Node.js)
- Packaging: Monorepo for all during development, split when stable
- PHP: Consolidate split packages into single monorepo
- Scope: Full feature parity — every feature in all four frameworks
- Testing: Same positive AND negative test specs, implemented in each language
- Debug overlay: Shared HTML/CSS/JS, identical appearance across all backends
- Databases: SQLite, Firebird, MySQL, MSSQL, PostgreSQL, MongoDB (Python), ODBC (planned)
- Sessions: File (dev), Redis, Valkey, MongoDB, Database (Python has all 5)
- WebSocket: All four frameworks (PHP via Swoole)
- Queue: Database-backed, zero third-party dependencies

## Recent Progress (2026-03-20)
- **Dev Admin JS extracted** — `tina4-dev-admin.js` is now a standalone file, reusable across all 4 frameworks (no more Python triple-quoted string issues)
- **Self-diagnostic check** — Dev Admin detects its own JS load failures and shows error banner
- **Verbose field names** — `IntegerField`, `StringField`, `BooleanField` with short aliases and `.kind` attribute for GraphQL
- **Default landing page** — auto-served when no user templates exist
- **CLI binary naming standardised** — `tina4python`, `tina4php`, `tina4ruby`, `tina4nodejs` across all frameworks
- **READMEs aligned** — all 4 frameworks have consistent README structure
- **Logo fixed** — `tina4.com/logo.svg` across all docs
- **PHP bin entry** — `composer.json` now has `bin` entry for global `tina4php` command
- **All 4 frameworks committed and pushed** on `v3` branch
- **Python/PHP stay on v2 as default release** — v3 on branch only

## What's Next
1. Python v3 is **100% complete** — use as reference implementation
2. Begin PHP and Ruby v3 implementations
3. Node.js last (most work required)
4. Port `tina4-dev-admin.js` to PHP, Ruby, and Node.js backends
5. Build default landing page into all 4 framework cores
