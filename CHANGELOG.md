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

### Breaking (DocStore result accessors, ADR-0025)

- The DocStore result objects (`InsertOneResult`, `InsertManyResult`,
  `UpdateResult`, `DeleteResult`) now expose the MongoDB driver's GETTERS
  instead of public properties. The properties are private.

      $result->insertedId      ->  $result->getInsertedId()
      $result->insertedIds     ->  $result->getInsertedIds()
      $result->matchedCount    ->  $result->getMatchedCount()
      $result->modifiedCount   ->  $result->getModifiedCount()
      $result->upsertedId      ->  $result->getUpsertedId()
      $result->deletedCount    ->  $result->getDeletedCount()

  WHY: the two halves of an advertised swap exposed DISJOINT APIs. The SQLite
  fallback offered `->insertedId` and no getter; a real `MongoDB\InsertOneResult`
  offers `getInsertedId()` and NO public properties at all. There was no
  spelling of the insert that worked on both providers, so the framework's own
  documented example

      $res = $orders->insertOne([...]);
      $orders->findOne(['_id' => $res->insertedId]);

  became `findOne(['_id' => null])` the moment `TINA4_MONGO_URI` was set, and
  the developer just saw "document not found" - SILENTLY, with no error at any
  point. Measured 2026-08-03 against a real MongoDB.

  ADR-0025 settles the general rule: the fallback imitates the driver, because
  the driver is the half that cannot be changed. Pinned by
  `tests/DocStoreSubstitutabilityTest.php`, which runs every case against BOTH
  providers, with a negative case asserting the fallback-only property spelling
  stays gone.

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

### Breaking: ->middleware([ResponseCache::class]) now actually caches

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
