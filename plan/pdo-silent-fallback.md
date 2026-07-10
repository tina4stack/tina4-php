# Task: Silent PDO fallback for tina4-php native-extension DB adapters (3.13.66)

## Goal
tina4-php prefers native DB extensions, but three engines hard-depend on an
extension that is often missing/dead. Add a SILENT PDO fallback so a developer
gets a working DB either way, with EXTERNALLY IDENTICAL behavior to the native
adapter (native types, BLOB-as-raw-bytes, getLastId, RETURNING, txn semantics,
execute() fail-loud). PHP-only — no cross-framework mirror.

| Engine | Native ext (default) | PDO fallback |
|--------|----------------------|--------------|
| SQLite | ext-sqlite3 (`SQLite3`) | `pdo_sqlite` |
| PostgreSQL | ext-pgsql (`pg_*`) | `pdo_pgsql` |
| Firebird | ext-interbase (`ibase_*`/`fbird_*`) — THROWS if absent today | `pdo_firebird` (highest value) |

MySQL/MSSQL/ODBC already use PDO/mysqli — left untouched.

## Design (grounded in the real adapter source + empirical driver probes)
- Sibling `Pdo{Engine}Adapter` classes selected by `Database::create()` ONLY when
  the native ext is missing (native stays the default, never de-preferred). Sibling
  classes are directly instantiable, which is exactly what the native-vs-PDO parity
  test needs (force the PDO path even where the native ext IS present).
- DRY: a shared `PdoAdapterTrait` implements the generic PDO query/fetch/execute/
  CRUD/txn/coerce/lastId logic; each sibling supplies engine hooks (DSN, pagination,
  type coercion, RETURNING/lastId shape, schema introspection).
- Hard requirement on the PDO path: `ATTR_STRINGIFY_FETCHES=false`,
  `ATTR_EMULATE_PREPARES=false`, `ATTR_ERRMODE=EXCEPTION`, + per-engine coercion so a
  caller cannot tell which driver served the result.

### Empirical findings (real drivers, this box: PHP 8.5.7)
- **pdo_sqlite** with stringify/emulate off already returns native `int`/`double`/
  `string` and raw BLOB bytes (null bytes preserved); `lastInsertId` matches native.
  -> identity coercion; cast lastId to `int` to match native SQLite3.
- **pdo_pgsql** with stringify/emulate off returns `int`/`double`/`bool` natively BUT
  leaves `numeric` a **string** and returns `bytea` as a **stream resource**.
  -> coercion keyed on `getColumnMeta()` native_type, mirroring the native adapter's
  casters: int2/4/8->int, bool->bool, float4/8+numeric->float, bytea(resource)->raw
  bytes. Native PG `lastInsertId()` is a numeric **string** -> PDO stores it as string.
- Native PG **cannot** round-trip raw bytes into `bytea` via a bound param either
  (first `\x00` truncates) — bytea write encoding is the caller's job on BOTH. The
  blob parity test therefore writes via `decode('<hex>','hex')` and asserts READ
  parity (raw bytes out) across native and PDO.
- **pdo_firebird** NOT compiled into this PHP build; ext-interbase present but the
  PDO driver is absent -> Firebird fallback is implemented + tested but the live run
  is UNVERIFIED here (see report).

## Scope
- [x] Read the three native adapters + factory + trait + interface (source = authority)
- [x] Empirically probe pdo_sqlite / pdo_pgsql (types, BLOB, lastId) vs native
- [x] `PdoAdapterTrait` (shared PDO logic + hooks)
- [x] `PdoSqliteAdapter`
- [x] `PdoPostgresAdapter`
- [x] `PdoFirebirdAdapter` (UNVERIFIED live — no pdo_firebird locally)
- [x] `Database::create()` selects native, else PDO sibling, else clear combined error
- [x] `PdoFallbackParityTest` — native-vs-PDO IDENTICAL results+types for each engine:
      typed reads (int/float/bool), BLOB raw-bytes round-trip, getLastId after insert,
      txn commit + rollback, execute() raises on a bad statement.
- [x] Run parity test + DB adapter tests + full suite; report real numbers per engine

## Tests (real, no mocks, positive + negative)
- [x] SQLite: both ext-sqlite3 and pdo_sqlite present -> both paths run for real
- [x] PostgreSQL: real PG (docker :55432) -> ext-pgsql vs pdo_pgsql for real
- [ ] Firebird: needs real Firebird + pdo_firebird -> UNVERIFIED here (no driver)

## Verification (macOS, PHP 8.5.7)
- `PdoFallbackParityTest` + `PdoFallbackFactoryTest`: 14 tests, 48 assertions, 12
  pass / 2 skip (Firebird — no pdo_firebird). SQLite ran vs a shared temp file;
  PostgreSQL ran vs a real server (docker postgres:16 on :55432).
- Full suite: 2792 tests, 6965 assertions, 0 failures / 0 errors, 48 skipped
  (unprovisioned MySQL/MSSQL/Firebird + optional services), 1 pre-existing risky
  (GalleryTest), deprecations all pre-existing (LogTest). Native selection is
  byte-identical to before (factory routes to native when the ext is present).
- Factory native-absent decision proven for real via a no-extension subprocess
  (php -n unloads ext-interbase; no pdo_firebird) -> combined error raised.

## Firebird — VERIFIED LIVE (2026-07-10, macOS PHP 8.5.7 + real FB5.0.2)
Built pdo_firebird from PHP 8.5.7 source against the Firebird 5.0.3 macOS client
framework, stood up a real Firebird 5 container (t4-fb-test on :3052), and ran
the fallback for real over TCP. The hold is lifted.

- **Rebuilt cleanly on current v3** (the old feature/php-pdo-fallback branch was
  28 commits behind and re-added the now-merged SQLite/PG trait+adapters — a
  guaranteed conflict). This is a Firebird-only delta on top of 3.13.66's
  shipped PDO infrastructure: PdoFirebirdAdapter + Database::makeFirebird +
  the Firebird parity/factory cases + a standalone PDO-only adapter test.
- **Driver override (the core "make it work"):** auto-mode still prefers native
  ext-interbase, but `?driver=pdo` on the URL or `TINA4_FIREBIRD_DRIVER=pdo`
  forces the working pdo_firebird adapter even when ext-interbase is PRESENT but
  BROKEN — exactly the macOS + FB5 clumplet case. Without this the broken native
  driver always won and the fallback never engaged.
- **Type fidelity:** INTEGER arrives as int; NUMERIC/DECIMAL carry a decimal
  scale (`precision` > 0) and are cast to float to match native. DOUBLE/FLOAT
  report precision 0 with len 8/4 (indistinguishable from CHAR/BLOB) so they are
  returned as exact numeric STRINGS — the one documented divergence from native,
  called out in the adapter docblock. Full BLOB byte fidelity confirmed.
- **Live verification:** PdoFirebirdAdapterTest (typed reads incl. numeric cast,
  transaction commit/rollback, execute() fail-loud, introspection) + the two
  driver-override factory tests all RAN GREEN vs the real FB5.0.2 container. The
  native-vs-PDO parity cases skip loudly where ext-interbase is present-but-broken
  (nothing to compare against); they run on a host with a working ext-interbase.
- **Full suite (macOS, live FB5):** 2851 tests, 7059 assertions, 0 failures /
  0 errors, 89 skipped (unprovisioned MySQL/MSSQL/PG/Mongo + broken-native
  Firebird parity), 1 pre-existing risky (GalleryTest).

## Scope — Firebird (done)
- [x] `PdoFirebirdAdapter` numeric caster (NUMERIC/DECIMAL -> float) + DOUBLE-string doc
- [x] `Database::makeFirebird` + `firebirdDriverPreference` (env + `?driver=` override)
- [x] `PdoFirebirdAdapterTest` — standalone PDO-only contract (no ext-interbase needed)
- [x] Firebird parity cases: numeric-by-value compare + skip-loud on broken native
- [x] no-driver combined-error factory test restored + 2 driver-override tests
- [x] DatabaseDriversTest / MigrationV3Test: skip-loud on present-but-broken ext-interbase
- [x] Verified live vs real FB5.0.2; full suite green

## Status: COMPLETE — SQLite + PostgreSQL shipped in 3.13.66; Firebird PDO fallback verified live and rebuilt on current v3 (supersedes the held feature/php-pdo-fallback / PR #152)
