# Changelog

Tina4 keeps ONE version across all four frameworks (Python, PHP, Ruby, Node.js), so a version
number means the same thing everywhere.

**The authoritative release notes for every shipped version live in the documentation:**
https://tina4.com/php/36-releases

## 3.13.105

Bug release. Route inspection stops touching the app; Firebird's migration
ledger tolerates whatever case the driver hands back; PHP loses a colon-in-
filename that broke Windows checkouts.

### Route inspection scans, never boots

- `tina4 routes` now walks canonical route files and never executes the
  application entrypoint or starts the server. Feature 115 / ADR-0058.
- Fixes the case where `tina4 routes --override` would boot the app on the
  same port and kill whatever process was already holding it (tina4-python
  issue #104).

### Firebird migration ledger is case-agnostic

- `tina4_migration` reads and writes work regardless of the case the
  Firebird driver returns for the `migration_name` column.
- Uses the atomic sequence table pattern already in place for other engines.

### Dev toolbar works under the framework's own default CSP

- The dev toolbar's CSS and JS now ship from two dedicated `/__dev/toolbar.css`
  and `/__dev/toolbar.js` routes instead of inline `<style>` / `<script>`
  blocks and inline event handlers.
- The suppression signal for the AI port is a `data-reload="0"` attribute on
  the toolbar root; the JS in the external asset reads it and early-returns.
- Zero CSP change and no nonce -- the toolbar works under the strict
  `default-src 'self'` policy that `SecurityHeadersMiddleware` sets by
  default. tina4-php PR #195, credited to @justin-k-bruce.
- Python and Node share the same inline-CSP-hostile pattern and receive the
  same treatment in 3.13.106 (tina4-python#115).

### Windows checkouts stop dying on a colon-in-filename

- A stray `sqlite::memory:` file was tracked in the repository. Windows
  disallows colons in filenames, so `git clone` failed the first time it
  reached that entry. Removed. tina4-php PR #197.

### Queue and ORM audit bugs closed

- **`Model.clear_cache()` cascades to `db.cache_clear()`.** Under both
  `TINA4_AUTO_CACHING=true` and `TINA4_DB_CACHE=true`, a manual `clear_cache()`
  used to leave the DB-layer cache holding stale rows (PY-06-22).
- **`Queue.retry()` revives every dead letter.** The no-arg form used a
  generator inside `any(...)` and short-circuited after the first success.
  Now materialised so all N dead letters revive (PY-12-04).
- **`Job.retry()` unlinks the dead-letter file.** Iterating `dead_letters()`
  and calling `.retry()` on each used to leave the failed directory carrying
  every revived job, so the next `dead_letters()` call re-reported them
  (PY-12-05).
- **MongoDB `retry_job(id)` searches the dead-letter namespace by data.id,
  deletes the DL doc, and upserts the original back to pending.** The old
  filter `{_id, self._topic, "failed"}` could never match a dead letter.
- **MongoDB `purge(status)` returns `deleted_count`, honours every status, and
  routes dead/failed/dead_letter aliases to the `.dead_letter` namespace.**
  Previously returned None and only handled `pending` — via `clear()`, which
  nuked every doc under the topic regardless of status.

### Test-harness fixes

- **SIGTERM port probe checks both interfaces.** The graceful-shutdown test
  probed only `0.0.0.0`; on macOS with the framework's default `127.0.0.1`
  listener holding the port, that bind succeeded and the pre-assertion
  concluded the port was free. Now probes both interfaces.
- **MySQL connect-timeout assertion parses the reported elapsed via regex**
  and checks it against the configured bound instead of the outer clock;
  mysql-connector's post-abort cleanup adds ~0.7s that the two stopwatches
  legitimately disagreed on.
- **AI installer tests sandbox `HOME` per-test.** `install_context()` writes
  the global skills bundle to `Path.home()/.claude/skills` by design; in the
  test suite that raced other tests and hit stale root-owned files from prior
  sudo runs. `monkeypatch.setenv("HOME", ...)` redirects the global install
  to a throwaway dir.

### Developer skills — announce and 💩

- **Announce before you act.** All four framework developer skills now
  instruct: say what you are about to do in one line before doing it —
  Plan / Next / Done. Never write more than two files between announcements.
  Never run a schema migration, install a dependency, or edit the boot file
  without a preceding "About to:" line.
- **💩 stale-skill detection.** All four framework developer skills gained
  `updated_for_version: 3.13.105` in the frontmatter and a self-check that
  compares this to the latest published skill version at
  `https://tina4.com/skills/<name>/version` (never against the project's
  framework version — the framework version is the developer's call). If a
  newer skill is available, 💩 rides beside 🤖 on every reply plus a one-time
  update instruction.

### Doc parity

- `Queue.size()`, `Queue.failed()` and `Queue.dead_letters()` gained matching
  in-source docstrings across Python, PHP, Ruby and Node: `size("failed")` /
  `size("dead")` / `size("dead_letter")` are aliases for the dead-letter
  count (== length of `dead_letters()`); `failed()` lists retryable-but-
  attempted jobs, counted under `size("pending")`, NOT `size("failed")`.

## 3.13.103

### Metrics reports what it can prove

- Require signed Tina4 client 3.8.76 for the native metrics handoff.
- Expose `has_referencing_test` as a source-reference signal. It does not claim
  that a test ran or that coverage exists.
- Fail when the native client is stale instead of falling back to a second
  framework-owned analyser.

### Frond stays stable and gets smaller

- Split expression parsing and evaluation into focused internal steps.
- Preserve public APIs and the shared 84-case expression corpus across all four
  languages.

### One client starts every project

- Lead framework skills with `tina4 init` and `tina4 serve`.
- Keep scaffolding guidance visible and separate runtime dependencies from
  language extensions.

### PHP connection timeout diagnosis

- Treat libpq's explicit `timeout expired` result as the configured connection
  timeout at the clock boundary, while preserving instant refusal errors.

### Release integrity

- Align source, runtime, package, lockfile, and AI-facing guide versions.
- Reject a release tag that does not match the source package version before
  any registry publish begins.

+## 3.13.101

### Breaking: metrics has one owner

- Remove the framework `metrics` command and local quick census. Use the native `tina4 metrics` CLI.
- Keep dev-admin metrics as a thin `/metrics/full` and `/metrics/file` JSON handoff to that CLI.

### App-facing AI client

- Add zero-dependency `AI::chat`, `AI::complete`, and `AI::embed`.
- Rename the developer-tool installer class to `AITools`; the new application client owns
  the case-insensitive `AI` class name.
- Support local/OpenAI-compatible, OpenAI, and Anthropic chat providers.
- Normalize chat responses, stream ordered deltas, and preserve embedding cardinality.
- Fail closed on missing hosted-provider keys, verify TLS, redact sensitive failures, and
  distinguish bounded connection and total-request timeouts.
- Retry only transient connection, HTTP 429, and HTTP 5xx failures, never a partial stream.

## 3.13.100

### Breaking: Frond instance extensions stay local

Calling `addFilter`, `addGlobal`, or `addTest` on a Frond instance now changes
that renderer only. Register on `Frond` itself when every later instance must
inherit the extension.

- Reject a second `{% extends %}` tag instead of replacing the first parent without warning.
- Lock PHP's already-correct nested block behavior with the shared regression.
- Bound template, fragment, and expression caches, with TTL sweeps for stale entries.
- Retry transient AI skill-download failures.
- Activate the tina4-js skill for `tina4js` and `Tina4 JS` spellings as well as `tina4-js`.
- Keep the runtime version and AI-facing guide on one version.

## 3.13.99

### Breaking: `$request->params` is route-params-only

Client input now lives only in `$request->query` and `$request->body`; `$request->params`
holds route params and nothing else, closing a param-pollution surface in the other three
frameworks. Header `header()` lookup is case-fold only now.

**Migration.** Replace any `$request->params[...]` read of a client-supplied value with
`$request->query[...]` or `$request->body[...]`.

### Breaking: security headers, CSRF, and the dev server default on

`Content-Security-Policy: default-src 'self'` and the other security headers now emit by
default (relax with `TINA4_CSP`; HSTS on HTTPS via `TINA4_HSTS`). `TINA4_CSRF=true` now
actually attaches the CSRF middleware instead of being inert, and a blank `TINA4_SECRET`
fails closed instead of minting a forgeable public-default token. The dev server binds
`127.0.0.1` by default (`TINA4_HOST=0.0.0.0` to expose it), refuses a cross-origin `/__dev`
mutation, and never serves `.env` through the file endpoints. The public-directory search
order is now `public` before `src/public`.

**Migration.** Set `TINA4_CSP` if you depend on inline scripts or a third-party CDN. Set
`TINA4_HOST=0.0.0.0` if you need the dev server reachable from another machine.

### Breaking: Mongo, Firebird, and file-upload footguns closed

An unparseable/unsupported MongoDB WHERE now raises instead of silently matching every
document (a DELETE/UPDATE with no WHERE is rejected). MongoDB's next-id generator now raises
on error instead of silently returning `1`. Firebird's `$db->insert()->lastId` /
`$db->update()`/`$db->delete()` `->affectedRows` return real values instead of a missing or
zero value. A repeated multipart file field now yields a list instead of silently dropping
every upload but the last; the safe-save helper rejects `..`/absolute filenames, and an
over-limit upload now answers `413` mid-stream instead of after buffering the whole body.
Frond `{% include %}`/`{% extends %}`/`{% import %}` now raise on a path that escapes the
templates directory.

**Migration.** Add an explicit WHERE to any Mongo query relying on the old match-all
fallback, or call `truncate()`. Handle `$request->files[x]` as a list when multiple files can
share a field name.

### Breaking: `App::background()` returns a handle, not `$this`

`App::background()` used to be fluent, returning `$this`. It now returns a
`Tina4\BackgroundTask` handle, the same stop-handle surface Python/Ruby/Node share.

**Migration.** Split a chained `->background(a)->background(b)` into two separate calls.

### Breaking: ORM write-path and AutoCrud parity fixes

`datetime` name-inference is anchored now, so a column whose name only substring-matched
(`runtime`, `downtime`, `updated_by`) is no longer typed as a datetime. `create_table()`
injects `is_deleted` for a `softDelete = true` model automatically. A soft-deleted child no
longer appears through relationship traversal. The imperative `hasMany` cap changes from a
silent 100 to the whole result set. AutoCrud returns `422` (was `500`) on an invalid
create/update, and never accepts `is_deleted` or a client-supplied primary key in the write
body. `seed_table` now routes through the parameterized adapter insert instead of hand-written
MySQL/SQLite backtick SQL. The two validators now speak one canonical message vocabulary;
PHP's own messages drop the trailing colon (`"name: is required"` -> `"name is required"`).

**Migration.** Declare an explicit `\DateTime` property (or an `*_at`/`*_date`/`*_time` name)
on a column that relied on the old substring datetime match.

### Breaking: response, database-adapter, and dev-tooling fixes

Responses gzip-compress when eligible; a cacheable 200 gets a strong ETag, and the
static-file ETag format is unified to `W/"<size>-<mtime>"` across all four frameworks. A
`403` now negotiates HTML vs JSON like `404`/`500`, and `404` carries a `request_id`. The
Swagger UI CDN default moves to jsdelivr, off unpkg. A route group's prefix join is
normalized (PHP's own join was already correct). `tina4php serve` no longer crashes the
process when the AI port (`base + 1000`) is busy; it warns and skips. `new Server()`'s
default port changes from 7146 to 7145 (only affects a direct no-argument construct). The
CLI's `--version`/`commands --json` report the real framework version instead of `0.0.0` in
a git checkout. The session cookie now emits under a testing `Response`. The inline `@tests`
descriptor builders are renamed `Testing::assert*` -> `Testing::expect*`;
`Testing::discover()` now scans only an explicit tests directory and parses `@tests`
arguments as literals, never `eval`. `DatabaseAdapter`'s interface no longer requires the
removed `query`/`lastInsertId`/`error` methods and now requires `connect`/`getDatabaseType`/
`autocommit`, which `CachedDatabase`/`Database` were both missing and would have hard-fatalled
on class load. `Database::executeMany()` now delegates once instead of looping through the
facade.

**Migration.** Rename any `Testing::assert*` descriptor call to `Testing::expect*`. Move an
`@tests` docblock into the tests directory if it lived outside it. Expect every cache to
revalidate once after upgrade, since the static-file ETag format changed.

### Fixed: router literal-parenthesis 404, and the built-in server's first-time session cookie

`Router::compilePath()` interpolated a literal path segment unescaped, so a route like
`/products/(sale)` 404'd because `(`/`)`/`.` compiled as regex syntax; only `{param}` becomes
a capture group now. Separately, `emitSessionCookie()` branched on `headers_sent()`, which is
never true under `Tina4\Server`'s raw socket, so a first-time login through `tina4 serve`
produced no `Set-Cookie` header and session auth silently failed. A new
`Response::$rawSocket` flag (kept separate from the `testing` flag, which would also break
SSE streaming) fixes it.

### Changed (3.13.96 Swagger + Messenger cross-framework parity)

Swagger and the Messenger IMAP read path were measured side by side across all
four frameworks and moved onto one settled shape (Python is the reference).

Swagger (`Tina4\Swagger`):

- `components.schemas.<Model>` now carries a `required` array derived from column
  nullability: a NOT NULL, non-auto-increment column is required. The ORM field
  map (`getFieldDefinitions()`) gained a `nullable` flag read from the declared
  property type (`string` is NOT NULL, `?string` and untyped are nullable).
- `info.contact` now emits `name` and `url`, not only `email`. It reads
  `TINA4_SWAGGER_CONTACT_TEAM` and `TINA4_SWAGGER_CONTACT_URL` (with the legacy
  `SWAGGER_CONTACT_TEAM` / `SWAGGER_CONTACT_URL` as a fallback).
- Framework-internal routes are excluded from the published document by one
  shared list: `/swagger`, `/__dev`, `/__feedback`, and the AI/RAG service
  prefixes (`/ai`, `/rag`, `/vision`, `/embed`, `/image`), plus the bare `/`
  landing page. PHP was publishing ten service routes it registers itself.

Breaking: `info.description` now defaults to `""` (was "Auto-generated from Tina4
routes") and `servers[0].url` defaults to `"/"` (was `http://localhost:7145`,
which is wrong off port 7145). `TINA4_SWAGGER_DESCRIPTION`, `TINA4_SWAGGER_SERVERS`
and `SWAGGER_DEV_URL` still override. Migration: set those env vars if you relied
on the old defaults.

Messenger (`Tina4\Messenger`), IMAP read path:

- Every `inbox()` / `search()` item now carries `snippet` (decoded,
  transfer-decoded, tag-stripped plain text, truncated to 200 chars; `mb_*`
  guarded so it works without ext-mbstring).
- `read()` now returns a `headers` map (name => value).
- New methods: `markUnread()`, `sendTemplate()` (renders a Frond template), and
  `delete()` (flags `\Deleted` and expunges).
- IMAP credentials are now separate from SMTP: `TINA4_MAIL_IMAP_USERNAME` /
  `TINA4_MAIL_IMAP_PASSWORD` (falling back to `TINA4_MAIL_USERNAME` /
  `_PASSWORD`), and constructor params `imapUsername` / `imapPassword`. PHP used
  to authenticate IMAP to the SMTP account and could return someone else's mail.
- `imapEncryption` is now a constructor parameter (explicit beats env, ADR-0041).
  The default is port-aware (993 = tls, else none), reproducing the previous
  port-based connection selection, so nothing regresses for callers who never
  set it.
- `read()` attachments now carry their raw decoded `content` bytes (issue #69).
  Each attachment is `{filename, content_type, size, content}`, where `content`
  is the transfer-decoded bytes (base64 / quoted-printable back to the original
  bytes -- the same convention as an uploaded file's `content`) and `size` is
  that byte length, so an attachment is downloadable straight from `read()`
  (parity with the Python master's per-attachment `content`).

Breaking (inbox/read item keys): an `inbox()`/`search()` item is now exactly
`{uid, subject, from, to, date, snippet, seen}`. `to` was ADDED; `msgno`,
`flagged` and `size` were REMOVED; `date` is now ISO-8601 (was raw RFC 2822).
`read()` likewise emits `date` as ISO-8601 and adds `headers`. Migration: a
consumer that read `msgno`/`flagged`/`size` from an inbox item must stop; a
consumer that parsed the raw RFC 2822 `date` must parse ISO-8601 instead; `to`
is now available directly.

Breaking (read() item keys, issue #70): `read()` now returns EXACTLY the 10
canonical keys `{uid, subject, from, to, cc, date, body_text, body_html,
attachments, headers}`. The PHP-only extras `msgno`, `message_id`, `seen` and
`flagged` were REMOVED: `msgno` is the IMAP SEQUENCE NUMBER ADR-0042 forbids as a
public id, `message_id` duplicated `headers['Message-ID']`, and `seen`/`flagged`
are inbox()-listing concerns, not read() fields. Each attachment item likewise
dropped `mime` and `part_number` in favour of `content_type` and the new
`content`. Migration: read the Message-ID from `headers['Message-ID']`; use
`inbox()`/`unread()` for seen/unread state; read an attachment's type from
`content_type` (was `mime`). (`imap_msgno()` is still used INTERNALLY to fetch,
just never exposed in the result.)

### Fixed (Firebird held a table for the life of the process, #170)

A parameterised statement on the native ext-interbase driver kept its table
locked FOREVER -- after a successful `commit()`, after `close()`, for as long
as the PHP process lived. Any later DDL against that table from another
connection then waited forever rather than erroring, because both the native
default transaction (`IBASE_WAIT`) and pdo_firebird use a WAIT lock policy.

The cause was the statement, not the transaction. `doExecute()` bound
parameters through `ibase_prepare()` + `ibase_execute()`, and a prepared
statement's compiled request stays registered on the ATTACHMENT -- which
ext-interbase does not detach on `ibase_close()` while the process is alive.
Only PARAMETERISED statements did it, reads as well as writes; an
unparameterised one goes through `ibase_query()`, whose statement is
driver-owned and dropped with its result.

Parameters are now bound through `ibase_query()` too. They are still bound by
the driver -- no interpolation, no injection risk -- and the transaction still
leads the call, so statements join the active transaction exactly as before.
Nothing changes for a caller.

`close()` also now ROLLS BACK an explicit transaction that never reached a
commit, instead of nulling the handle and leaving the transaction open
server-side holding its locks. Rollback on close is what every mainstream
database API does; an unfinished unit of work must never be silently kept.

Measured on Ubuntu 24.04.4, PHP 8.3.6, Firebird 5.0.4, ext-interbase built
from source: unparameterised statements always released the table,
parameterised ones never did, and no ordering of `ibase_free_query()` /
`ibase_free_result()` changed that.

This is what made three test files unrunnable on any host where ext-interbase
is actually installed -- `FirebirdWriteVisibilityTest` and `MigrationV3Test`
hung indefinitely and `FirebirdOrmWriteTest` failed with `RECREATE TABLE ...
deadlock ... concurrent transaction number is N`. One cause, all three.

`tests/FirebirdStatementLeakTest.php` locks it in with three REAL php processes
against a REAL Firebird: a creator that exits, a holder that writes and closes
but stays alive, and a prober that must still be able to run DDL under a
wall-clock deadline. Red at 20s before the fix, green in 1.2s after, with an
unparameterised control that passes either way.

### Fixed (FirebirdUrlTest pointed at a server that was not there)

The three live tests hard-coded `localhost:53050` and `/firebird/data/tina4.fdb`
-- one particular container layout -- so on a host running Firebird anywhere
else they skipped every run, reporting "Firebird not reachable", which reads
like a missing service rather than a misconfigured test. They now derive host,
port, path and credentials from `TINA4_TEST_FIREBIRD_URL` when it is set,
falling back to the old constants.

### Fixed (the documented cursor chain fatalled on real MongoDB, ADR-0036)

**This was a PUBLISHED example that did not run.** From this file's own DocStore
section:

```php
foreach ($orders->find(['total' => ['$gt' => 5]])->sort('total', -1)->limit(10) as $doc)
```

It worked on the SQLite fallback and died on a real MongoDB with

```
Error: Call to undefined method MongoDB\Driver\Cursor::sort()
```

`MongoDB\Collection::find()` EXECUTES and returns a cursor with no `sort`,
`limit` or `skip` - PHP's driver takes those as `find()` OPTIONS, so by the time
find() returned there was nothing left to chain.

`getCollection()->find()` now returns `Tina4\DocStoreQuery`, a DEFERRED
chainable query: it issues NO query, accumulates `sort`/`limit`/`skip`, and calls
`find($filter, $options)` exactly once when you iterate it or call
`toArray()`/`toList()`. Nothing is buffered, so a large result still streams, and
anything else on the driver cursor is still reachable through `__call`. This is
the shape `Mongo::Collection::View` and pymongo's `Cursor` already had.

`sort()` also now accepts all three driver spellings on both providers -
`sort('total', -1)`, `sort(['total' => -1])`, `sort([['total', -1]])`. The MAP
form used to raise `TypeError: DocStoreCodec::extract(): Argument #1 ($field)
must be of type string` on the fallback while working on the driver.

  **Breaking: `find()` on the Mongo path returns `Tina4\DocStoreQuery`**, not a
  wrapped `MongoDB\Driver\Cursor`. Every documented operation is unchanged;
  a CLASS check on the return value is not. Same consequence ADR-0035 already
  carries for the collection itself.

  MEASURED 2026-08-04 against a real MongoDB 7.0.39: 4 chain cases x 2 providers
  x 4 frameworks = 32 combinations, of which **10 failed** before this change and
  0 fail after. Pinned by the substitutability suite in all four frameworks,
  which asserts every spelling on BOTH providers, that `skip` composes, that an
  ASCENDING sort actually ascends (a direction ignored outright would pass a
  descending-only test), and that the chain is LAZY - a document inserted after
  the chain is built but before it is iterated must appear.

### Fixed (the uniform DocStore spellings now work on the real provider, ADR-0035)

- `$cursor->toList()` and `$result->insertedId` worked on the SQLite fallback and
  did not exist on the MongoDB driver, so code that used them broke the moment
  `TINA4_MONGO_URI` was set. They now work on BOTH providers.

  `getCollection` returns `Tina4\DocStoreDelegator` on the Mongo path, which adds
  `toList()` plus a `__get` that resolves a uniform property name to the driver's
  getter, and forwards the entire driver surface untouched. `aggregate`,
  `bulkWrite`, `createIndex`, `watch`, `withOptions`, sessions and transactions
  are all still reachable; measured 2026-08-04 against a real MongoDB 7.0.39 with 0
  fallback-only collection methods. `->unwrap()` returns the bare driver object.

  ADDITIVE, not a replacement. `toArray()` and `getInsertedId()` are the driver's
  spellings, they are unchanged, and both forms return the same value. On the
  fallback the backing properties stay PRIVATE - the property spelling is served
  by the `DocStoreResultAccessors` trait, so there is still one mechanism.

  ADR-0025 corollary 1 said to DELETE a method the driver lacks. ADR-0035
  supersedes that corollary only: a method may exist on the fallback when it also
  exists on what `getCollection` RETURNS, and Tina4 may supply it on both sides.
  The core rule and corollaries 2, 3 and 4 stand.

  Pinned by `tests/DocStoreSubstitutabilityTest.php`, which reads a document back
  through every spelling on BOTH providers and measures the fallback's public
  methods against the wrapped driver rather than a hand-kept list.

  **Breaking: on the Mongo path `getCollection` now returns a delegator, so a
  CLASS check answers differently.** Every method call and the whole driver
  surface behave exactly as before, but

      getCollection('x') instanceof \MongoDB\Collection   // was true, now false
      get_class(getCollection('x'))                       // was MongoDB\Collection

  **Migration:** stop type-checking the return, or reach the real object with
  `->unwrap()`:

      getCollection('x')->unwrap() instanceof \MongoDB\Collection   // true

  Nothing in the framework type-checks it; this is stated because a user
  application might.

  KNOWN and NOT fixed here: the fallback `Cursor` carries `sort`, `limit` and
  `skip`, which `MongoDB\Driver\Cursor` does not have, so
  `find([...])->sort('total', -1)` still works locally and fatals on Mongo. That
  needs a `find()`/`Cursor` redesign, not a delegator method.

### Fixed (redis/valkey stats() size on the zero-dependency transport, ADR-0004)

- `Tina4\Cache\RedisBackend::stats()` computed `size` only when the ext-redis
  CLIENT was loaded. On the raw RESP transport - the zero-dependency default
  install - it returned 0 no matter how many entries were cached, so every
  reader of that number was reading a constant: a monitoring dashboard,
  `cacheStats()`, or an operator checking whether a clear had worked.

  Same root cause as the earlier `clear()` no-op: the default transport had no
  coverage.

  `size` and `clear()` now drive ONE scoped SCAN walk (`walkPrefixedKeys()`) on
  BOTH transports, so the two can never disagree about what the cache holds.
  The client path moves off `keys()` for the same reason `clear()` did: KEYS is
  O(N) and blocks the whole server, and Redis's documentation says to prefer
  SCAN. When counting, keys are de-duplicated - SCAN never MISSES a key that was
  present throughout the walk, but it may return one twice if the keyspace is
  resized mid-walk.

  Pinned by `testStatsReportsARealSizeOnBothTransports` in
  `tests/CacheClearInvalidatesTest.php` against a real Redis.

### Fixed (memcached TTL beyond 30 days vanished instantly, ADR-0024)

- `Tina4\Cache\MemcachedBackend::set()` interpolated the caller's TTL into the
  memcached `set` exptime field RAW. memcached reads that field as RELATIVE
  seconds at or below 2592000 (30 days) and as an ABSOLUTE UNIX TIMESTAMP above
  it, so any `TINA4_CACHE_TTL` over 30 days was read as a date in 1970 and the
  entry expired the instant it was written. memcached still answers `STORED`,
  so this presented as a 100% miss rate with nothing logged - a cache that looks
  like it is working and never returns a hit.

  MEASURED on real memcached 1.6.45: exptime 2592000 survives; 2592001 and
  5184000 vanish immediately despite STORED.

  The fix CONVERTS, it does not CLAMP. Clamping to 2592000 also makes the entry
  survive and is also wrong: it silently discards more than half the lifetime
  the operator explicitly configured, which is the same class of
  silent-wrong-answer as the bug it would replace. `MAX_RELATIVE_EXPTIME` is a
  public class constant; `exptime()` maps ttl <= 0 to 0, ttl > MAX to
  `time() + ttl`, and leaves everything else alone.

  The local write log deliberately keeps the RAW ttl. Building it from the
  converted value would set the deadline to `now + <a unix timestamp>` - about
  166 years out - so the map would never expire anything and `stats()` would
  report expired entries as live forever.

  Pinned by `tests/CacheMemcachedExptimeTest.php` against a real memcached. The
  load-bearing case reads the SERVER's own remaining lifetime (`mg <key> t` ->
  `HD t<seconds>`, memcached 1.6+), because a survival check alone passes under
  a clamp exactly as it does under a convert.

### Fixed (cache sweep on the database backend, ADR-0024)

- `Tina4\Cache\DatabaseBackend` had no `sweep()`, so it inherited the base
  class's `return 0`. redis, valkey, memcached and mongodb expire entries
  SERVER-SIDE, so 0 is the honest answer for them - nothing was evicted because
  there was nothing left to evict. A SQL table expires nothing by itself: rows
  were deleted only when someone happened to re-read that exact key, so expired
  rows accumulated forever while the one API whose job is reclaiming that space
  reported success having done nothing.

  `sweep()` now counts and deletes rows matching `expires_at > 0 AND expires_at
  < now`, returning the real number evicted. The `expires_at > 0` guard is
  load-bearing: an entry stored with `ttl <= 0` is permanent and carries 0, so a
  bare `now > expires_at` would evict every permanent entry on the first sweep.

  Pinned by `tests/CacheSweepCountsTest.php` against real backends (in-process,
  a real directory on disk, a real SQLite database, plus live Redis, Valkey,
  memcached and MongoDB), with negative cases for "nothing expired reports 0"
  and "an entry with no TTL is never swept".

### Breaking (query-cache key carries database identity, ADR-0024)

- The persistent DB query-cache key now includes the DATABASE IDENTITY of the
  connection it came from. It was `sha256(sql . params)` with nothing naming the
  connection.

      before:  sha256(sql . json_encode(params))
      after:   sha256(identity . "\0" . sql . "\0" . json_encode(params))
      identity: engine://host:port/database   (NO username, NO password)

  WHY: with no database identity in the key, two databases sharing ONE cache
  backend cross-served each other's rows. Two apps pointed at one Redis, or a
  single app with a primary and an analytics connection, silently read each
  other's data. Identical SQL text is exactly what a multi-tenant deployment
  runs, so the collision was the COMMON case, not an edge case. This is a
  data-isolation failure that looked like a caching optimisation. Measured
  2026-08-04 against a real shared Redis with two real SQLite files AND two real
  PostgreSQL databases: database B was served database A's row in both.

  The identity carries NO credentials, deliberately. A password in a key means
  every rotation silently cold-starts the cache, and a shared backend's key
  namespace is visible to every tenant of that backend. It also carries nothing
  per-process (no pid, no object id, no salt) - that would isolate databases by
  accident and destroy the point of a shared cache, since no instance would ever
  hit another instance's entry.

  MIGRATION: every entry already in a persistent cache backend becomes a MISS on
  upgrade, because its key no longer matches. Nothing needs to be done - the
  cache refills on the next read, and the stale entries expire under their own
  TTL. A cold cache is safe; cross-served rows are not. If you want the space
  back immediately, call `cacheClear()` on any connection (or `FLUSHDB` the
  cache database if it is dedicated to Tina4) during the deploy.

  `CachedDatabase::__construct()` takes a new optional trailing `$url` argument
  (the connection URL). Code that builds the decorator by hand keeps working -
  the argument defaults to an empty string - but SHOULD pass the URL, or every
  hand-built wrapper shares one identity. `Database` passes it automatically.

  Pinned by `tests/CacheKeyDatabaseIdentityTest.php`, which runs against a real
  shared Redis plus real SQLite and real PostgreSQL, with negative cases
  asserting the key is STABLE for the same database (not per-connection) and
  that credentials never reach it.

### Fixed (session write, PHP-only defect)

- `Session::saveToFile()` discarded the return value of `file_put_contents()`.
  That function returns `false` on failure and does NOT throw, so a write that
  never landed - a read-only file, a full disk, a revoked permission - left
  `save()` returning `true` and the caller believing the session was persisted.
  It now throws on a failed write; `safeWrite()` catches it and degrades per the
  log-loud policy, so `save()` returns `false` and the dirty flag is retained.

  PHP-only by construction: Python (`open().write`), Ruby (`File.write`) and Node
  (`writeFileSync`) all RAISE on failure, so their identical `safeWrite` wrappers
  already caught it. Verified empirically on Ruby (logs + returns false); the
  other two share the same architecture. No port required.

# Changelog

Tina4 keeps ONE version across all four frameworks (Python, PHP, Ruby, Node.js), so a version
number means the same thing everywhere.

**The authoritative release notes for every shipped version live in the documentation:**
https://tina4.com/php/36-releases

This file is deliberately NOT a copy of those notes. Duplicating them is exactly how a
changelog rots into claiming a version that was never cut, so this file records only
UNRELEASED work. When a version ships, its notes go to the release notes above.

### Breaking (DocStore: a missing MongoDB driver now raises)

`TINA4_MONGO_URI` set with the `mongodb` extension or the `mongodb/mongodb` library NOT installed used to return the local SQLite collection. It now
raises `Tina4\DocStoreDriverMissing`, naming the provider and what is missing (ADR-0033,
applying ADR-0024 rule 3).

Re-measured 2026-08-04 at `v3` HEAD in a REAL driverless environment - no mock, no
faked import - one env produced two shapes and four messages across the family:
Python, PHP and Ruby silently returned the local SQLite store, Node threw a bare
`ERR_MODULE_NOT_FOUND`. Silent degradation here means production writes landing in a
container-local file nobody reads, which vanishes on the next deploy, with no error at
any point.

**Migration - one of two lines:**

```
pecl install mongodb              # the PHP extension
composer require mongodb/mongodb  # the high-level library
unset TINA4_MONGO_URI             # or use the local SQLite store, explicitly
```

Also changed: `isServerless()` is now CONFIGURATION ONLY. It used to also return true when ext-mongodb was absent, which is
what routed the call into the local branch; without this an app branching on it would
take the local path and never reach the raise. The error message names the env var that
supplied the URI and never its VALUE, because a Mongo URI routinely carries
`user:password@` and an error string is the most-logged text a framework emits.

PHP had a **second door** that no test had opened: with `ext-mongodb` PRESENT but the
`mongodb/mongodb` library absent - the shape a production `composer install --no-dev`
produces, since that package is `require-dev` plus a `suggest` - `isServerless()`
reported FALSE while `getCollection()` still returned `Tina4\SqliteCollection`. The two
disagreed exactly as the DocStore contract forbids. Each piece is now named separately,
because telling an operator who already has the extension to "install the driver" sends
them looking in the wrong place.


### Fixed (queue operations acted on the local file store, not the configured backend)

Every operation must act on the CONFIGURED backend. These calls appeared to succeed
while operating on the wrong data, which is the worst failure class because nothing
surfaces it. `pop_by_id` was broken in ALL FOUR frameworks.

- `clear()` and `purge()` called the local file store unconditionally, so clearing a
  mongodb-backed queue emptied a local directory and left every real job in place.
- `popById()` returned `null` on every external backend, commented "External backends
  don't support ID-based pop natively" - which mongodb does.
- `clear`, `purge` and `popById` are now on the `QueueBackend` INTERFACE, so the type
  system enforces the routing, and `LiteBackend::clear()` returns its count.

### Fixed (a queue method could be a fatal error instead of resolving)

Every public `Queue` method must RESOLVE on every backend the framework offers. A
method that does not exist cannot even reach a refusal, so the upgrade path is
severed rather than degraded.

- `failed()`, `deadLetters()`, `retry()` and `retryFailed()` were a FATAL
  "Call to undefined method" on the rabbitmq AND kafka backends. The root cause was
  structural: the `QueueBackend` INTERFACE never declared them, so only LiteBackend
  and MongoBackend happened to define them while `Queue` called them on everything.
  They are now on the interface, so the type system enforces this permanently, and
  the brokers answer with a refusal naming the backend and the method rather than an
  empty list (an empty list would claim nothing has failed).
- A failed broker connect stored `false` in the socket while `ensureConnected()` only
  reconnects on `null`, so every later call did `fwrite(false, ...)` and died with a
  TypeError instead of a clean, reconnectable exception.

### Fixed (queue priority was ignored on every backend but file)

- `push(..., priority)` is now honoured on the `mongodb` backend: priority is stored
  top-level and the dequeue sorts highest-first, ties oldest-first — the same policy
  the file backend already applied. An urgent job queued behind a backlog used to wait
  for all of it in production while prioritising correctly in development. Here `Queue::push()` built a SEPARATE message array for the external-backend branch
  carrying no priority, and even once that was unified `MongoBackend` stored
  priority only INSIDE the `message` sub-document (where a sort cannot reach it)
  and sorted on `created_at` alone.
- **Breaking:** pushing with a priority to `rabbitmq` or `kafka` now RAISES, naming the
  backend and the operation, instead of silently discarding it. A RabbitMQ queue is
  FIFO: native priority needs the queue DECLARED with `x-max-priority`, and an existing
  queue cannot be redeclared with one (the broker answers PRECONDITION_FAILED), so
  switching it on would break every queue already in service. Kafka has no priority
  concept at all. Migration: use the `file` or `mongodb` backend for prioritised jobs.
  A push with priority 0 (the default) is unaffected.

### Fixed (a queue delay was silently dropped on every non-file backend)

- `push(..., delay)` is now honoured on the `mongodb` backend. It was silently DROPPED
  on every non-file backend in ALL FOUR frameworks, so a scheduled job fired immediately
  in production and on time in development. Here `Queue::push()` built a SEPARATE message array for the external-backend branch
  that carried neither `priority` nor `delay_seconds`, so the value never reached
  the backend at all. Both branches now share ONE message shape, which also
  restores `priority` on the external backends.
- **Breaking:** pushing with a delay to `rabbitmq` or `kafka` now RAISES, naming the
  backend and the operation, instead of silently discarding the delay. Neither broker
  has a per-message delay: RabbitMQ's delayed-message-exchange is a non-core plugin and
  the TTL + dead-letter workaround head-of-line blocks, and Kafka reads a partition in
  offset order. Migration: use the `file` or `mongodb` backend for delayed jobs, or
  schedule the push itself. A push with no delay is unaffected.

### Fixed (an unknown queue backend name silently used the file store)

- An unrecognised `TINA4_QUEUE_BACKEND` now RAISES, naming the bad value and the
  valid set, instead of falling through to the local file store. The name is also
  normalised (trimmed + lowercased), so ` RabbitMQ ` resolves.

  WHY: MEASURED 2026-08-03. A typo in `TINA4_QUEUE_BACKEND` produced a RUNNING app
  writing every job to local disk while the operator believed they were in
  RabbitMQ - jobs nothing consumes, on a container filesystem that vanishes on
  the next deploy, with no error at any point.

      python   raised, named the valid set     <- already correct
      ruby     raised, named the valid set     <- already correct
      php      SILENT FALLBACK to file
      nodejs   SILENT FALLBACK to file

  This is the same rule the SESSION backend already adopted, for the same
  reason, so two of four were simply behind.

  Pinned by `tests/QueueBackendValidationTest.php`, with a negative case asserting the guard still accepts
  every documented name - without it, "make everything raise" would pass.
  Mutation-proved in both directions (guard disabled, normalisation removed).

### Fixed (array queries diverged from MongoDB, ADR-0025 clause 4)

- A query against an ARRAY field now behaves the way MongoDB behaves. The rule is
  one sentence: a condition on an array-valued field matches when ANY ELEMENT
  matches it (or the whole array equals the operand), and a negation matches when
  NO element does. Implemented over SQLite's `json_each`.

  WHY: MEASURED 2026-08-03 against a real MongoDB with an 18-case matrix. EIGHT
  behaviours diverged IDENTICALLY in all four frameworks, which is the signature
  of a contract nobody had written down:

      tags = "x" against ["x","y"]      mongo 1, fallback 0   (containment)
      tags $in ["x"]                    mongo 1, fallback 0
      nums = 1 against [1,2,3]          mongo 1, fallback 0
      nums $lt 2 against [1,2,3]        mongo 1, fallback 0
      tags $regex "^x$"                 mongo 1, fallback 0
      tags $nin ["x"]                   mongo 0, fallback 1   <- FALSE POSITIVE
      tags $ne "x"                      mongo 0, fallback 1   <- FALSE POSITIVE
      nums $gt 9 against [1,2,3]        mongo 0, fallback 1   <- FALSE POSITIVE

  The three false positives are the worst of it: the fallback returned documents
  Mongo EXCLUDES. `nums $gt 9` matched [1,2,3] because json_extract of an array
  returns its JSON TEXT and SQLite sorts any text above any number - a wrong
  answer, not a missing feature.

  Also fixed in the same pass: an object field is no longer matched by one of its
  values, and IS matched by the whole object.

  Pinned by `tests/DocStoreSubstitutabilityTest.php`, which runs a 20-case matrix against BOTH providers and
  asserts they return the SAME counts - not a hard-coded number, so the test
  cannot drift towards whatever the fallback happens to do. Mutation-proved by
  removing the array branch from equality.

## Unreleased

### Breaking: the rate limiter keys on the socket peer, not X-Forwarded-For

`X-Forwarded-For` is written by whoever sends it. Reading it unconditionally let
any client pick its own rate-limit bucket, and - worse - pick SOMEONE ELSE'S,
exhausting a third party's quota. Measured with `TINA4_RATE_LIMIT=3`: a rotating
`X-Forwarded-For` scored 200,200,200,200,200,200 where a fixed one correctly
scored 200,200,200,429,429,429.

`X-Forwarded-For` and `X-Real-IP` are now read ONLY when the raw socket peer is
listed in the new `TINA4_TRUSTED_PROXIES`. Within the chain the RIGHTMOST hop
that is not itself a trusted proxy wins, matching Rack and Express (a client can
prepend its own hop, so the leftmost entry is attacker-controlled even behind a
real proxy).

**Migration.** If your app runs behind a proxy, load balancer or ingress, set
`TINA4_TRUSTED_PROXIES` to that proxy's address or range. It accepts a
comma-separated mix of exact addresses and CIDR ranges, IPv4 and IPv6:

```
TINA4_TRUSTED_PROXIES=10.0.0.0/8
TINA4_TRUSTED_PROXIES=192.168.1.5, ::1, fd00::/8
```

It is EMPTY by default, which means trust nothing. If you leave it unset behind a
proxy, every client is bucketed under the proxy's address and you will
over-limit. That is deliberate: over-limiting is a degraded service, while the
previous behaviour was an open door. Direct-to-internet apps need no change.

See ADR-0019.

### Breaking: json/html/text/xml keep an explicitly-set status code

`$response->status(429)->json([...])` returned **200**. The helpers took a
defaulted `int $status = 200` which overwrote whatever `status()` had just set.

This was not academic: `RateLimiterMiddleware::beforeRateLimit` ends in exactly
that call, so the rate limiter answered 200 to requests it was blocking. A client
reads the status, not the body, so a throttled client was told it succeeded and
never backed off.

The parameter is now a null sentinel: an explicitly-set status is preserved, an
explicitly-passed one still wins, and a fresh response still defaults to 200.

**Migration.** None for correct code. If you relied on `json()` RESETTING a
previously-set status to 200, pass the status you want explicitly:
`$response->json($data, 200)`.

### Fixed: RateLimiter::apply() could never have worked

It read `$info['limit']`, `['remaining']` and `['reset']` out of `check()`,
which returns only `allowed`/`headers`/`status`, emitting three EMPTY
`X-RateLimit-*` headers plus three PHP warnings; then on the over-limit path it
called `Response::setStatusCode()`, a method that does not exist, which is a
hard fatal. Nothing tested it. It now uses `check()`'s real headers and
`Response::status()`.

`X-RateLimit-Reset` is also now emitted (as an absolute Unix timestamp, matching
tina4-ruby and tina4-nodejs); PHP previously sent it nowhere.
### Breaking: the response cache obeys RFC 9111 (Authorization and Vary)

The response cache keyed entries on method plus URL, with NO request header in
the key. It is a shared, server-side store, so on a secured GET route the first
caller's body was served to every later caller of the same URL. Measured
end-to-end on a real secured route: a valid token for `bob` returned alice's
private body with `X-Cache: HIT`. In Node, where route middleware runs before
the auth gate, an ANONYMOUS request returned 200 with alice's body.

Two RFC 9111 rules now apply, as they do in Varnish, nginx and Rails:

- Section 3 / 3.5: a response to a request carrying `Authorization` is NOT
  stored unless the response carries `Cache-Control: public`, `s-maxage` or
  `must-revalidate`.
- Section 4.1: `Vary` is honoured. The nominated request headers are recorded
  with the entry and must match on lookup; an absent field matches only an
  absent field. `Vary: *` is never stored.

**Migration.** Authenticated GETs are no longer cached by default. If a
response body is genuinely identical for every caller, opt back in per
response:

```php
$response->header('Cache-Control', 'public');
```

Only add it where the body carries nothing user-specific. Public GET caching is
unchanged. See ADR-0020 and `plan/v3/features/043-caching.md`.

### Breaking: an unknown TINA4_CACHE_BACKEND raises instead of falling back to memory

An unrecognised name silently became an in-process memory cache, so a typo
(`TINA4_CACHE_BACKEND=redsi`) produced a running app that shared nothing while the
operator believed it was Redis. It now raises, naming the bad value and the valid
set - the contract `TINA4_SESSION_BACKEND` already uses.

**Migration.** Fix the spelling. Valid: `memory`, `file`, `redis`, `valkey`,
`memcached`, `mongodb`, `database` (plus the aliases `memcache`, `mongo`, `db`).

### Breaking: {% cache %} TTL semantics now match Python, Ruby and Node

PHP parsed a missing TTL as `0` and then treated `0` as "cache forever", so both
ends of the contract were inverted against the other three frameworks:

- `{% cache "key" %}` never re-rendered for the life of the process. It now
  defaults to 60 seconds.
- `{% cache "key" 0 %}` cached forever. `0` now means NOT cached, which is what
  `now + 0` already meant in Python, Ruby and Node.

**Migration.** A block that relied on `0` meaning "cache forever" needs an
explicit positive TTL: `{% cache "key" 86400 %}`.

### Fixed: ->middleware([ResponseCache::class]) now actually caches

It was a silent no-op: `Middleware::discoverMethods()` collects only PUBLIC
STATIC methods and `beforeCache`/`afterCache` are instance methods, so no hook
was ever discovered - no warning, no header, no caching. Static
`beforeResponseCache`/`afterResponseCache` hooks now delegate to the module
singleton. Routes using this form start caching for the first time; the route's
responses are now subject to the RFC 9111 rules above.

### One middleware return-value contract, both entry points

`Middleware::runBefore()` / `runAfter()` (the orchestrator) and
`Router::dispatch()` (the real dispatcher) read a hook's return value from the
same table now, at every scope - global and per-route:

| A hook returns | The pipeline does |
|---|---|
| a `Response` object | SHORT-CIRCUIT. That object IS the response, at ANY status |
| the `[$request, $response]` pair | rebind both, continue |
| `false` | SHORT-CIRCUIT. The response as set; 403 when still default |
| `null` | continue |

The Response rule is the primary one because it is the only rule that can
express a 302: a hook returning `$response->redirect('/login')` now ends the
chain instead of falling through to the handler. The pre-existing
"status >= 400 also short-circuits the before pass" rule is RETAINED as a legacy
compatibility path so middleware that signals by status alone keeps working.

`Middleware::runBefore()` / `runAfter()` return a THIRD element - the response
that ended the chain, or `null`. Existing callers destructuring
`[$request, $response]` are unaffected (list assignment ignores extra elements);
the dispatcher needs it because a 302 or a plain 200 `Response` is a
short-circuit no status check can recognise. The table itself lives in one
place, `Middleware::applyHookResult()` (parity with the Python master's
`Middleware.apply_hook_result`).

**Breaking:** a `before*` / `after*` hook that returned a `Response` object
where it meant "carry on" now ends the chain. Hooks that return
`[$request, $response]`, `null`, or nothing are unaffected - that is every
built-in middleware and the documented convention. PHP's fluent `Response`
methods return `$this`, so the shape to check for is a hook whose last statement
is `return $response->header(...)` or similar; change it to
`return [$request, $response];`.

**Breaking:** `false` no longer renders 403 unconditionally. A hook that set a
status or body before returning `false` now sends what it set (a deliberate 402
stays a 402); a hook that returned `false` against an untouched response still
gets the 403 it meant.

### Fixed: per-route middleware never ran its `after*` hooks

A middleware class attached with `->middleware([Audit::class])` ran its
`before*` hooks and nothing else - `Router::runClassMiddlewareHooks()` looped
only over `before`, and the dispatcher's single `runAfter()` call was passed the
GLOBAL set. The route's own `after*` methods were unreachable code. The same
class registered globally ran both, so whether a hook fired depended on how it
was attached. Python and Ruby both run the route-level after pass.

**Breaking (PHP only):** an `after*` method on a class attached per route is
currently INERT and will start executing. Audit the `after*` hooks on any class
used with `->middleware()` / `Router::group()` before upgrading - code that has
never run once will now run on every request through that route. The migration
risk differs per framework: in Ruby the equivalent call raises `NoMethodError`
today, so there it is broken-to-working rather than inert-to-live.

The after pass now runs on every request that MATCHED a route, including one a
middleware short-circuited - the dispatcher used to return the short-circuit
directly and skip the after pass entirely, which contradicted the documented
"after* always run, even on a 4xx" rule. An unmatched path (404) still has no
after pass. Order is the global set followed by the route's classes, mirroring
the before pass.

### Fixed: inherited middleware hooks ran before the base class they inherit from

`Middleware::discoverMethods()` trusted `get_class_methods()` to return
"parent methods first, then the class's own". It does the opposite - a class's
OWN methods come first and inherited ones after (verified on PHP 8.5.7) - so a
subclass's hooks ran BEFORE the base hooks they build on, the reverse of Python
and Ruby. Discovery now walks the class hierarchy explicitly, most distant
ancestor first, taking only the methods each class declares, so definition order
survives within a class and an override keeps the position of the base
declaration it replaces.

**Breaking:** a middleware class that extends another now runs `beforeBase`
before `beforeSub` (and the same for `after*`). A subclass hook that relied on
running first will need reordering.

The docblock asserting the old, false claim about `get_class_methods()` is gone.
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
  `RS256` is unchanged and still works.

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
