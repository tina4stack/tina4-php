# Changelog

Tina4 keeps ONE version across all four frameworks (Python, PHP, Ruby, Node.js), so a version
number means the same thing everywhere.

**The authoritative release notes for every shipped version live in the documentation:**
https://tina4.com/php/36-releases

This file is deliberately NOT a copy of those notes. Duplicating them is exactly how a
changelog rots into claiming a version that was never cut, so this file records only
UNRELEASED work. When a version ships, its notes go to the release notes above.

## Unreleased

### CORS denies by default, and never pairs the wildcard with credentials

**Breaking:** `TINA4_CORS_ORIGINS` defaulted to `*`, which allowed every origin
on a fresh install. It now defaults to UNSET, which denies every cross-origin
request: no `Access-Control-Allow-Origin` is sent, and the browser's own CORS
check blocks the request. Django, Rails and ASP.NET all require an explicit
policy before emitting any CORS header, and now so does Tina4.

**Migration:** name the origins your frontend runs on.

```
TINA4_CORS_ORIGINS=https://app.example.com
```

Comma-separate several. `TINA4_CORS_ORIGINS=*` restores the old allow-any
behaviour for anyone who wants it: only the DEFAULT changed, not the capability.
Non-browser clients (curl, server-to-server) never consult CORS and are
unaffected. The status code of a denied preflight is unchanged at 204.

Also in this change:

- `Access-Control-Allow-Origin: *` is never sent alongside
  `Access-Control-Allow-Credentials: true`. The Fetch Standard's CORS check
  treats `*` as a literal once the request carries credentials, so every browser
  rejects the pair. When both are configured the wildcard wins, credentials are
  dropped, and a warning names the fix.
- `Vary: Origin` is now sent whenever the allowed origin is computed from the
  request's `Origin` header, including when the origin is REJECTED. Without it a
  shared cache can store one origin's response and serve it to another
  (RFC 9110 s12.5.5). It is not sent for a constant `*`, which does not vary.
- Every rejected cross-origin request logs an actionable warning naming the
  origin, the environment variable, and the fix. Silence was the common thread
  in every defect this audit found.

See ADR-0018.

### CORS preflight responses now carry `Allow`

A CORS preflight (`OPTIONS` with an `Origin`) returned 204 with the
`Access-Control-*` headers but no `Allow`, while a bare `OPTIONS` to the same
path returned `Allow`. A preflight IS an OPTIONS response, so it now carries
`Allow` too, derived from the router's real method set (RFC 9110 s9.3.7).

This is conformance, not a deviation - see ADR-0013. The frameworks' own
OPTIONS handlers already emit `Allow` (Django's `View.options()`, Express's
router). The add-on CORS libraries omit it only because they short-circuit
ahead of the framework and skip its OPTIONS handler. Tina4 owns both paths in
one dispatcher.

`Allow` and `Access-Control-Allow-Methods` are NOT interchangeable: `Allow` is
what the RESOURCE supports, `Access-Control-Allow-Methods` is what the CORS
POLICY permits cross-origin (`TINA4_CORS_METHODS`, a static list as in every
mainstream library). A policy naming DELETE on a GET-only route is still a 405.

Non-breaking: one added response header on a 204; no existing header changes.

### Fixed: CorsMiddleware swallowed the bare OPTIONS handler

`CorsMiddleware::beforeCors` short-circuited on ANY `OPTIONS` request with no
`Origin` check, so registering it meant a plain `OPTIONS` from a link checker
or monitoring probe got a 204 with no `Allow` - the RFC 9110 s9.3.7 handler
never ran. Only a real preflight (one carrying an `Origin`) short-circuits now,
matching Ruby, Python and Node.

`CorsMiddleware` also read the `Origin` from `$_SERVER` only, so the header was
invisible to anything not running under a web SAPI - the in-process TestClient,
the CLI, or a hand-built `Request`. It now reads the `Request` first and falls
back to `$_SERVER`.

`Router::methodsAllowedForPath()` is now `public` (it was `private`); the
equivalent was already public in the other three frameworks.


### Global middleware split into pre-match and post-match passes

Dispatch order is now identical in all four frameworks:

```
pre-match globals -> match -> post-match globals -> auth gate -> route middleware -> handler
```

PHP ran the WHOLE global set before route matching, singling CORS out with a
hardcoded `is_a(CorsMiddleware::class)` check. That check is gone: a middleware
now opts into the pre-match pass with `public static bool $preMatch = true`, and
`CorsMiddleware` declares it, so CORS behaviour is unchanged. See ADR-0012.

**Migration:** global middleware that does NOT declare `$preMatch` now runs
after route matching rather than before. That is what makes
`$request->handler` readable to it (how `CsrfMiddleware` honours a route marked
`->noAuth()`). Middleware that must run even when NO route matched - CORS, a
rate limiter, an access log - needs `public static bool $preMatch = true`.

The auth gate is unchanged: it still runs after the global passes and before
route middleware, so a global rate limiter or access log still sees 401s.


### Changed

- **Breaking: the Messenger IMAP `uid` is a STRING, not an int.** `inbox()`,
  `read()` and `search()` emitted `uid` as an int, so the same endpoint answered
  `{"uid": 1}` in PHP and `{"uid": "1"}` in Python and Node. The Python master
  sets `"uid": uid.decode() if isinstance(uid, bytes) else str(uid)` -- a string --
  and identical API responses across the four frameworks is a project rule, not
  a preference. `read()` and `markRead()` now accept `string|int` so existing
  int-passing callers keep working; only the value you READ BACK changed type.

  Migration -- a strict comparison against an int no longer matches:

  ```php
  // before
  if ($envelope['uid'] === 1) { ... }
  // after
  if ($envelope['uid'] === '1') { ... }   // or (int)$envelope['uid'] === 1
  ```

  A loose `==` comparison, string interpolation, and passing the uid straight
  back into `read()` / `markRead()` are all unaffected. JSON consumers that
  decoded `uid` into an int-typed field must widen it to a string.

- **Breaking: the metrics payload is now the native engine's shape.** `fullAnalysis` no
  longer returns a `violations` key. The ranked `offenders` list replaces it and
  `--fail-on` reads that same list, so one concept has one name instead of two.
  Verified before removal: zero consumers outside the tests.

- **Breaking: `fileDetail` returns the engine's per-file shape.** It no longer returns
  `total_lines`, `classes`, `imports` or `warnings`, and `functions` is now a COUNT rather
  than a list. Anything reading those keys must move to the engine's fields, or call
  `fullAnalysis` and read `most_complex_functions` for per-function detail.

- **Breaking: the empty-class warning is gone and is not coming back.** The old
  hand-rolled analyzer flagged `class Foo {}` with no members. An empty class is usually
  CORRECT rather than a defect: marker classes, base exception types, DTO placeholders.
  Tina4 itself ships `MetricsEngineError` as exactly that, so the check flagged the
  framework's own correct code. A check that fires on correct code is noise, and noise is
  why the offenders list went unread for months. The engine's vocabulary stays the four
  things that are actionable: complexity, large file, low maintainability, untested.

- **Breaking: the column-metadata primary-key flag is `primaryKey`.** PHP and Node use `primaryKey`; Python and Ruby use `primary_key`. Each follows its own
  language's paradigm because this is framework API surface, not data. The COLUMN NAME
  itself is unaffected and still mirrors the database verbatim.

- **Breaking: metrics REQUIRE the `tina4` CLI on PATH, with no fallback.** All four
  frameworks deleted their own hand-rolled analyzer, so `fullAnalysis`, `offenders` and
  `fileDetail` now shell out to `tina4 metrics --json` (ADR-0002: one engine, so a number
  measured in one language is comparable with the same number measured in another). A
  missing or stale CLI raises and names the install command instead of quietly returning
  worse numbers; the dev-admin endpoints answer 503, or 404 for an unknown file path.
  Previously a failure fell back to the local analyzer, which is exactly how four
  frameworks came to disagree about the same file. The file census behind the dashboard
  (`quick_metrics`) stays in-process and needs no CLI: it is a glob-and-count, and the
  engine is 8x to 37x slower on that path.

- **Breaking: every ORM read path that takes a `$limit` now defaults to 100 rows.**
  `select()`, `where()`, `withTrashed()`, `cached()` and a `scope()`-generated method
  defaulted to 20; they now default to 100. `all()`, `find()` and `$db->fetch()` already
  capped at 100 and are unchanged. Two methods differing only in how you spell the filter
  used to return a fifth as many rows. Pagination is a default, so every read path that
  advertises a limit now uses the same number.

  Migration: pass the limit explicitly wherever you relied on the old 20, for example
  `$model->select($sql, [], 20)`. Code that already passes a limit is unaffected.

  `QueryBuilder::get()` and `fetchAll()` are deliberately UNCHANGED and stay uncapped.
  Neither takes a `$limit`, so a cap there can only ever be silent, and that silent
  `LIMIT 100` was the data-loss-on-read footgun removed in 3.13.39. The rule: a path that
  advertises `$limit` caps at 100, a path without one never caps.

- **Breaking: an unsupported `TINA4_JWT_ALGORITHM` now throws instead of silently
  signing HS256.** `Auth::getToken()`, `Auth::validToken()` and
  `Auth::authenticateRequest()` throw `\InvalidArgumentException` naming the
  supported set (`HS256`, `HS384`, `HS512`, `RS256`) and the env var. Previously a
  typo such as `HS-256` was ignored: the token was signed HS256 while the operator
  believed something else was in force, so a misconfiguration produced a weaker
  token than asked for and said nothing.

  **Migration:** set `TINA4_JWT_ALGORITHM` to one of the four supported values, or
  leave it unset for the `HS256` default. A value that used to be silently ignored
  now fails at the first token operation.

- **Breaking: `Auth::validToken()` rejects a token whose header `alg` is not the
  configured algorithm.** The header used to be ignored entirely during
  verification. To be precise about what this is and is not: it was **not**
  exploitable. Verification always computed the signature using the *configured*
  algorithm and never read the header to choose a verifier, so a token minted for
  a different algorithm produced a different signature and was already rejected,
  and rewriting a real token's header invalidates its signature because the
  header is part of the signing input. What changes is that a mismatched `alg`  -
  including `alg: "none"`  - is now refused up front, before any signature work,
  instead of being caught incidentally by the comparison. The value is parity with
  the other three frameworks, early rejection, and a regression lock against a
  future refactor that trusts `header.alg`. Marked breaking because a token whose
  header disagrees with the algorithm that signed it no longer authenticates.

- `Auth::authenticateRequest()`'s third parameter changed from
  `string $algorithm = 'HS256'` to `?string $algorithm = null`, and is now actually
  forwarded to `validToken()`. It was previously accepted and dropped on the floor,
  so a caller asking for a specific algorithm silently got the environment's.
  `null` resolves `TINA4_JWT_ALGORITHM`, then `HS256`; passing `'HS256'`
  explicitly behaves as before.

- **Breaking: `Auth::validToken()` now honours the `nbf` (not-before) claim.**
  A post-dated token is refused until its `nbf` passes, with 60 seconds of clock
  skew tolerated (`Auth::JWT_LEEWAY_SECONDS`). A token with no `nbf` claim is
  unaffected, so tokens already in circulation keep working. `getToken()` does not
  stamp an `nbf`  - parity with the Python and Node masters. Closes tina4-php#187.

- **Breaking: `\Tina4\SqlTranslation` is renamed to `\Tina4\SQLTranslator`.**
  The SQL dialect-translation class was the only one of the four frameworks not called
  `SQLTranslator` (Python, Ruby and Node.js all already used that name), drifting on both the
  acronym casing and the noun. PHP is now aligned. PSR-4 means the file moved too:
  `Tina4/SqlTranslation.php` -> `Tina4/SQLTranslator.php`.

  **Migration:** replace `\Tina4\SqlTranslation` with `\Tina4\SQLTranslator`. Method names and
  behaviour are unchanged. There is deliberately NO compatibility alias - the project rule is
  to rename the primary rather than accumulate shims.

### Fixed

- **`TINA4_JWT_ALGORITHM=HS384` / `HS512` were broken in both directions.**
  `Auth::sign()` hardcoded `sha256`, so a token advertising `HS512` in its header
  carried an HMAC-SHA256 signature  - a 32-byte digest where the header promised 64  -
  which any RFC-conformant verifier rejects. Verification was worse: `validToken()`
  only knew `HS256` and `RS256`, so with `HS512` configured *every* token was
  invalid. The digest is now looked up from the algorithm, so the header always
  names the digest that actually signed, and the whole HMAC family verifies.
  `RS256` (a PHP/Node-only extra  - Python and Ruby cannot sign it without a
  dependency) is unchanged and still works.

- **`tina4 deploy docker` produced images that could not start.** Of the eight
  Dockerfile generators in the stack (four templates in the `tina4` CLI plus one
  in each framework's own CLI), exactly one was correct. Python named
  `python -m tina4_python.cli`, a package with no `__main__.py`, so the container
  died on startup; PHP ran `php index.php <addr>`, but `App::run(?host, port)`
  never reads argv so the address was dropped and production never engaged;
  Node named a path that exists only inside the tina4-nodejs monorepo and
  depended on tsx, which `npm ci --omit=dev` strips. Every generator now names a
  published entry point and requests production. Verified by scaffolding,
  generating, building and running a container for all four languages.
- **`serve` no longer kills PID 1.** The port-reclaim step read `lsof -ti`
  without validating it. Where lsof prints a different shape, a non-numeric field
  coerced to 0 or 1 -- and signalling PID 0 hits every process in the caller's
  own process group. In a container the server IS PID 1, so it killed itself
  (Node logged "Killed existing process on port 7148 (PID: 1 ...)" then exited
  143; PHP logged the same attempt and survived by luck). Reclaiming is now
  skipped inside a container, only all-digit PIDs are accepted, and PID 0, PID 1
  and the current process are never signalled.

## Earlier history (pre-3.x)

Kept for reference only. Everything from 3.x onward is in the release notes linked above.
Note: the 2.0.99 entry below was duplicated in this file with overlapping content; the two
copies have been merged into the single entry that reflects what actually shipped.

## [2.0.99] - 2026-03-14

### Changed
- Performance audit and cleanup across the ecosystem

### Added
- MIT LICENSE file
- CI workflow
