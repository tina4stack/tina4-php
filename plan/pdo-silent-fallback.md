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

## UNVERIFIED
- Firebird PDO fallback live run: this PHP build has NO pdo_firebird driver
  compiled in, so the Firebird parity test SKIPS (loudly). Code + test written;
  live run against a real Firebird + pdo_firebird still owed.

## Status: DONE — SQLite + PostgreSQL verified live; Firebird implemented, live UNVERIFIED
