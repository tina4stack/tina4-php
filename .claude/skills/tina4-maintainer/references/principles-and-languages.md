# Philosophy, core principles and working across languages

> Part of the **tina4-maintainer** skill. Moved verbatim out of `SKILL.md` on 2026-11-17 so the
> always-loaded floor stays small; load this file when the task needs it. The floor in
> `SKILL.md` (degrees of freedom, reflexes, verification, ISO controls, parity) still governs.

## The Guiding Philosophy

> **"The best code you write is the code you don't write."**

This isn't just about brevity — it's about discipline. Every line of code is a liability: it must
be maintained, tested, debugged, and understood by humans and AI alike. Before adding code, ask:
can this be achieved by removing something instead? Can an existing mechanism handle this? Can a
convention replace this configuration? The answer is often yes.

This philosophy extends to everything you do:
- **Proposing features**: Does this earn its complexity? Could simpler behavior suffice?
- **Fixing bugs**: Is the bug a symptom of over-engineering? Would simplifying fix it?
- **Reviewing code**: What can be deleted? What abstraction is pulling zero weight?
- **Benchmarking**: Fewer lines and fewer allocations usually mean less energy per request

## The Lazy Senior Developer Ladder

The philosophy above is only worth anything if it fires *before* you type. Make it a
reflex: before writing any code — framework core, a bug fix, a port — stop at the FIRST
rung that holds.

1. **Does this need to exist at all?** Speculative need = skip it, and say so in one line. (YAGNI)
2. **Does Tina4 already provide it?** Auth, ORM, Queue, Api, Cache, Events, Container, Session,
   Frond, GraphQL, WebSocket, Messenger, Migration, SQLTranslator, HtmlElement, Testing — the
   toolkit is large. Never rebuild what the framework ships. This rung doubles as the parity
   check: if one backend already has it, port that, don't reinvent it.
3. **Does the language / standard library do it?** Use it before reaching for a dependency.
4. **Does an already-installed dependency solve it?** Use it. Tina4 core carries zero runtime
   dependencies by design — never add one for what a few lines cover.
5. **Can it be one line?** Make it one line.
6. **Only then:** write the minimum code that works.

Take the higher rung that holds and move on — the ladder is a reflex, not a research project.
Two stdlib options the same size? Take the one that is correct on edge cases. Lazy means less
code, never a flimsier algorithm.

**Never lazy about:** input validation at trust boundaries, error handling that prevents data
loss, security, accessibility, cross-framework parity, and the tests that prove a change. A
framework fix without its equivalent tests in all four backends is unfinished, not lazy.

**Mark deliberate simplifications** with a `tina4:` comment that names the ceiling and the
upgrade path, so a shortcut reads as intent rather than ignorance:

```
// tina4: in-memory map; swap for the Redis backplane if this scales past one instance
# tina4: O(n) scan, fine for route tables; index by method+path if it ever gets hot
```

**Output discipline:** ship the code, then at most a few lines on what you skipped and when to
add it. If the explanation is longer than the code, the explanation is the problem. A report,
walkthrough, or release notes the user actually asked for is not debt — give those in full.

## Core Principles

These aren't arbitrary rules — they're the DNA of the project. Every decision you make should trace
back to one of these:

1. **Simple for humans AND AI** — No magic, no hidden behavior. If someone reads the code, they
   should understand what it does without consulting docs. This also means AI tools can reason about
   Tina4 code effectively.

2. **DX is paramount** — One import, everything works. No namespace hunting, no boilerplate ceremonies.
   The developer's time is the most expensive resource. The framework should be smart about what
   the developer intends:
   - Return an object from a route? The framework knows it should be JSON — no manual encoding.
   - Receive a JSON POST body? The framework parses it automatically — no manual decoding.
   - Return a string? It's HTML. Return a number? It's a status code.
   Every implicit conversion that eliminates a manual step for the developer is a win — as long
   as the behavior is predictable and never surprising.

3. **Code efficiency** — Every line must earn its place. The original aspiration was ~5,000 LOC per
   language; each framework is now far larger (Python's `tina4_python/` is ~69k lines), so treat it
   as direction, not a budget: if you can delete code without losing functionality, do it, and never
   grow a subsystem to add what an existing one covers.

4. **SQL-first ORM** — Developers write less code, not less SQL. The ORM helps with CRUD but never
   hides the database. Complex queries should be SQL, not method chains.

5. **Zero third-party deps for core** — Frond, Queue, JWT, SCSS, WebSocket — all built from scratch.
   Node.js uses the stdlib `node:sqlite` (Node 22+), so core carries zero runtime dependencies in all four backends.

6. **Same concepts, language-idiomatic names** — Method and class names follow each language's
   conventions (see Naming Conventions below). But everything a developer or user SEES must be
   literally identical across all four frameworks:
   - **Connection strings** — same format (`sqlite:app.db`, `postgresql://user:pass@host:port/db`)
   - **Constants** — same names, same values (`TINA4_DEBUG`, `TINA4_SESSION_BACKEND`, etc.)
   - **Environment variables** — same `.env` keys and expected values
   - **Log output format** — same JSON structure in production, same human-readable format in dev
   - **Debug overlay** — same 8 panels, same layout, same data
   - **Error messages** — same wording, same status codes, same JSON structure
   - **API responses** — same JSON shapes (pagination, CRUD, health check, Swagger)
   - **Project structure** — same directories, same conventions
   - **CLI commands** — same flags, same output format (framework CLIs: `tina4python`, `tina4php`,
     `tina4ruby`, `tina4nodejs`, all reached through the `tina4` client)

   The rule: if a developer learns Tina4 in Python, they should feel at home in the PHP, Ruby,
   or Node.js version without reading new docs. Only the code they write changes — everything
   else stays the same.

7. **Convention over configuration** — File location IS configuration. Routes in `src/routes/` are
   auto-discovered. Models in `src/orm/` are auto-registered. No config files needed.

8. **DRY relentlessly** — If you see the same pattern repeated, extract it. But don't create
   premature abstractions — three concrete instances before abstracting.

9. **Separation of concerns** — Routes handle HTTP. ORM handles data. Frond handles templates.
   Each module has a single responsibility. Cross-cutting concerns (auth, logging) use middleware.

10. **Production-ready by default** — Health check, graceful shutdown, request ID tracking,
    structured logging, rate limiting, CORS — all included, not bolted on.

11. **Smallest possible distribution** — Obsess over distribution size. Every byte must justify its
    existence:
    - Multi-stage Docker builds — build deps never ship to production
    - Tree-shake aggressively — if a module isn't imported, it doesn't ship
    - Prefer stdlib over vendored code — it's already in the runtime
    - Compress what you can — minify JS/CSS, strip debug symbols in production
    - Measure image size before and after every change, just like benchmarks
    - Zero third-party deps already helps enormously — no bloated `node_modules` or `vendor/`
    - Current `:v3` images, compressed on Docker Hub (2026-11-17): Python ~14 MB, PHP ~43 MB,
      Ruby ~42 MB, Node.js ~65 MB. Re-measure on the Hub API; never let a change grow them
      without a reason (`references/cli-and-deployment.md`, Docker)

    The goal is the leanest possible distribution that still has 100% of Tina4's features.
    Smaller images mean faster deploys, less bandwidth, less storage, less attack surface,
    and less carbon. Never trade functionality for size — but always look for ways to shrink
    without losing anything.

## Working Across Languages

### Naming Conventions

| Aspect | Python | PHP | Ruby | Node.js/TS |
|--------|--------|-----|------|------------|
| Classes | PascalCase | PascalCase | PascalCase | PascalCase |
| Methods | snake_case | camelCase | snake_case | camelCase |
| Constants | UPPER_SNAKE | UPPER_SNAKE | UPPER_SNAKE | UPPER_SNAKE |
| Files | snake_case.py | PascalCase.php | snake_case.rb | camelCase.ts |
| Test files | test_*.py | *Test.php | *_spec.rb | *.test.ts |

Inside Frond templates, ALL filter names use snake_case regardless of host language — this is a
template-language convention, not a host-language one.

### Language-Specific Idioms

**Python:** Python 3.12+ minimum (`pyproject.toml`; never target older versions), async/await everywhere, type hints, ASGI-based, `uv` as package manager
**PHP:** PHP 8.2+ minimum (`composer.json`), Swoole for WebSocket (fallback to stream_socket), `hash_hmac` (PHP core) for the HMAC JWT family — ext-openssl is needed only for opt-in RS256
**Ruby:** Ruby 3.1+ minimum (gemspec `required_ruby_version`), `?` suffix for predicates, `!` for mutators, OptionParser for CLI
**Node.js:** Node.js 22+ minimum (`package.json` `engines`; `node:sqlite`), TypeScript-first, ESM-only, file-based routing

### Deprecation Awareness

Stay current with deprecations in all four languages. When working on any Tina4 code:

- **Actively look for deprecated APIs** — If you encounter deprecated functions, classes, or
  patterns, refactor them immediately. Don't leave deprecation warnings for later.
- **Use modern replacements** — Python 3.12+, PHP 8.2+, Ruby 3.1+, and Node.js 22+ all
  have modern alternatives for deprecated features. Use them.
- **Flag deprecations you find** — When auditing or reviewing code, surface any deprecated usage
  in your dashboard output so the maintainer can see the full picture.
- **Check changelogs** — Newer runtimes often add better ways to do things. Prefer the latest
  idiomatic pattern that still runs on the minimum version above over a legacy approach.

This is part of the continuous improvement mindset — Tina4 code should never emit deprecation
warnings. If it does, that's a bug to fix, not a warning to ignore.

### Security — Zero Tolerance

Security flaws do not get to creep in. Every piece of code you write, review, or port must be
secure by default. This is non-negotiable.

**Always enforce:**
- **SQL injection prevention** — Parameterized queries only. Never concatenate user input into SQL.
  The ORM and database adapters handle this, but raw queries in routes must use `?` placeholders.
- **XSS prevention** — Frond auto-escapes output by default. Only use `|raw` when the source is
  trusted. Never render user input without escaping.
- **CSRF protection** — Form tokens are built-in. Verify them on every state-changing request.
- **Path traversal** — Validate and sanitize all file paths. Never pass user input directly to
  file operations. Use `realpath()` / `os.path.realpath()` and verify the result is inside the
  expected directory.
- **JWT security** — Always verify signatures. Never decode without validation. Use the
  `TINA4_SECRET` env var, never hardcode keys. **HMAC (HS256 default, HS384, HS512) is the
  Tina4 algorithm family** — zero-dependency in all four frameworks (Python `hmac`+`hashlib`,
  PHP core `hash_hmac`, Ruby stdlib `OpenSSL::HMAC`, Node builtin `node:crypto`).
  **RS256 is OPT-IN and works in all four**, never as a bundled third-party dependency:
  Ruby signs it with stdlib `OpenSSL::PKey::RSA` (NO gem — never suggest installing one),
  Node with builtin `node:crypto`, PHP with `ext-openssl` (composer SUGGESTS it, does not
  require it), Python with the `cryptography` package the APP installs itself (Tina4 declares
  it nowhere). Where a backend is missing, fail loudly AT THE POINT OF USE naming the exact
  remedy — no boot probe, no import-time check, no startup warning for the HMAC majority.
  Pick RS256 only when a verifier must NOT be able to mint tokens (see "JWT algorithms" in
  `references/subsystems.md`).
- **JWT algorithm pinning** — Verify against the algorithm the app is CONFIGURED for, never
  the one the token's `alg` header asks for. An RS256 verifier's key is public, so an attacker
  can use it as an HMAC secret and mint a valid HS256 token; a header-trusting verifier accepts
  it. `alg: "none"` must be rejected for the same reason. All four frameworks pin.
- **Header injection** — Sanitize any user input that ends up in HTTP headers or redirect URLs.
- **Dependency security** — Zero third-party deps means a smaller attack surface. Keep it that way.
  If a stdlib function has a known vulnerability in an older version, the minimum version targets
  should protect against it — but verify.

**When reviewing or writing code:**
- Think like an attacker. What happens if this input is malicious?
- If you spot a security flaw — even in code you're not currently working on — flag it immediately
  and fix it. Don't leave it for later.
- Never suppress security warnings or bypass validation "for convenience"
- Log security-relevant events (failed auth, invalid tokens, blocked requests) but never log
  sensitive data (passwords, tokens, PII)

### Running Tests

```
Python:  uv run tina4 test    OR  uv run pytest
PHP:     vendor/bin/phpunit
Ruby:    bundle exec rspec
Node.js: npm test
```

Always run tests after making changes. If tests fail, fix them before moving on.

## Project Structure

All four frameworks use this identical layout:

```
.env.example          # Environment template
bin/                  # CLI entry point + frond.js (auto-updated on startup)
data/                 # Runtime data (.broken files, file-backed queue)
logs/                 # Structured logs (JSON in prod, human in dev)
secrets/              # Certificates, keys (gitignored)
src/
  routes/             # Auto-discovered route handlers
  orm/                # Auto-discovered ORM models
  migrations/         # Versioned SQL migration files
  seeds/              # Data seeders
  templates/          # Frond template files
  public/             # Static assets (served directly)
  locales/            # i18n translation files
tests/                # Test suites mirroring src/ structure
```

The framework auto-creates missing directories on startup — no scaffolding needed.

## Architecture Quick Reference

Read the bundled reference files for deep dives on specific subsystems:

- **`references/routing-and-orm.md`** — How routing, middleware, ORM, and database adapters work
  across all languages. Read this when implementing routes, models, or database features.

- **`references/frond-and-frontend.md`** — The Frond template engine spec and frond.js frontend
  helper. Read this when working on templates, live blocks, or frontend integration.

- **`references/subsystems.md`** — Queue, WebSocket, Auth/JWT, Sessions, GraphQL, WSDL, SCSS,
  i18n, Seeder, CRUD, Email, Events. Read this when working on any extended feature.

- **`references/cli-and-deployment.md`** — CLI commands, debug overlay, .broken file system,
  deployment, Docker, Kubernetes. Read this for CLI or ops work.

