# Task: Identifiers that reach SQL come from the model (ADR-0069) - PHP

**Outcome:** AutoCrud filter/sort keys, ORM::find() filter-map keys and DocStore
fallback field paths are resolved against the model / validated before any SQL is
built; anything that does not resolve is rejected (400 on the wire, an
InvalidArgumentException in the library). Branch `fix/identifier-allow-list` off
`origin/v3`. Governing decision: ADR-0069.

## Scope
- [x] ORM: one resolver (declared field name or its column; a model with no declared
      fields uses the table's introspected columns, cached per model class)
- [x] ORM::find(array) rejects an unknown key with \InvalidArgumentException
      "Unknown filter field 'KEY' for model <ModelName>" before any SQL; emits the
      resolved column (bare, as INSERT/UPDATE already do)
- [x] AutoCrud list: every filter[KEY] resolved via the same resolver; unknown ->
      400 UNKNOWN_FIELD "Unknown filter field 'KEY'"
- [x] AutoCrud list: sort parts resolved via the same resolver (leading '-' = DESC);
      unknown -> 400 UNKNOWN_FIELD "Unknown sort field 'KEY'" (KEY without the '-');
      ORDER BY built only from resolved columns + ASC/DESC
- [x] AutoCrud list: sort applies when no filter is present (was ignored)
- [x] DocStore SQLite fallback: every field path segment validated against
      ^[A-Za-z0-9_-]+$ at the single path-building chokepoint (DocStoreCodec::path)
- [x] Unchanged by design: where(), load(), select(), QueryBuilder, the raw $orderBy
      string argument of find()/all()/where()

### Addendum 2 (maintainer, fix on discovery)
- [x] D: non-string sort / non-scalar filter value -> 400 INVALID_QUERY_PARAMETER (testOddTypedQueryValuesReturn400)
- [x] E: AutoCrud list queries through the connection it was constructed with (testAutocrudListUsesTheRegisteredConnection)
- [x] F: require-services gate - only an excusable [needs:X] tag lets a skip pass; engine skip sites tagged
- [x] MSSQL: PDO::DBLIB_ATTR_CONNECTION_TIMEOUT (deprecated in PHP 8.5) -> Pdo\Dblib::ATTR_CONNECTION_TIMEOUT when available
- [x] .gitignore the session files served test apps write under tests/fixtures/data

## Parity
| Feature                               | Python | PHP | Ruby | Node |
|---------------------------------------|--------|-----|------|------|
| AutoCrud filter/sort allow-list       | n/a    | ✅  | ❌   | ❌   |
| ORM find(filter-map) key resolver     | ❌     | ✅  | ❌   | ❌   |
| DocStore fallback path validation     | ❌     | ⚠️ (Mongo parity case owed to the lab: no ext-mongodb locally) | ❌   | ❌   |
(PHP column is this worktree; other frameworks are tracked by their own workers.)

## Tests (written first, real - no mocks, positive + negative)
File: tests/IdentifierAllowListContractTest.php (+ fixture app tests/fixtures/identifier_allow_list_app.php)
- [x] testUnknownFilterFieldReturns400 (real php -S server, real SQLite)
- [x] testUnknownSortFieldReturns400
- [x] testDeclaredFilterAndSortStillWork (declared field, mapped field by property and
      by column, -field DESC, multi-field sort, sort without filter, dynamic model filters
      on its real columns)
- [x] testOrmFindRejectsUndeclaredFilterKey (SQLite, PostgreSQL, MySQL, MSSQL, Firebird)
- [x] testDocstoreRejectsUnsafeFieldPath
- [x] testDocstoreAcceptsSafeFieldPaths
- [x] testDocstoreSafePathsMatchOnRealMongo (real MongoDB)

## Bugs
- [x] AutoCrud list passes raw filter keys into the ORM WHERE clause
- [x] AutoCrud list builds ORDER BY from raw sort text
- [x] AutoCrud list ignores sort when no filter is present
- [x] ORM::find(array) emits an unknown key unchanged as a column
- [x] DocStore fallback emits unvalidated field path segments into json path literals

## Commits
- 28f60137  ORM find() accepts only model fields as filter-map keys
- 4e9fc27b  AutoCrud accepts only declared model fields in filter and sort
- 6f1b06f3  DocStore validates field paths in the SQLite fallback
- 052d7c88  Test the ADR-0069 identifier allow-list contract

- b7c8bfd4  AutoCrud rejects non-string sort and non-scalar filter values with 400
- 0855cc57  AutoCrud list queries through the connection it was constructed with
- dc9d9b79  MSSQL pdo_dblib: use Pdo\Dblib::ATTR_CONNECTION_TIMEOUT when available
- 5ae0382d  Ignore session files written under tests/fixtures/data by served test apps
- 4f08257f  Require-services gate: only an excusable [needs:X] tag lets a skip pass
- 7899b004  Tag engine skip sites with [needs:<engine>]
- 6742095e  Gate: postgres is promised by TINA4_TEST_PG_URL only; isolate the predicate test
- 54b8a8d7  Tag the live graph-engine skips with [needs:<engine>]

## Verification (lab, PHP 8.3.6 + every service, gate armed)
- Full suite at 54b8a8d7: 5736 tests, 24502 assertions, 0 failures, 1 error, 42 skipped,
  0 gate violations. The 42 skips are all tagged optional engines this run did not promise
  (swoole, neo4j, memgraph, arango, ultipa, oidc). The error is PushTest::
  testClassifiesDeadAndRetryableResponses ("no HTTP response received"), which passed in the
  previous full run and 3/3 in isolation - intermittent, in code this change does not touch.
- IdentifierAllowListContractTest 13/13 incl. real MongoDB and Firebird.

## Verification (local, macOS)
- New file: PHP 8.5.10 green except Firebird (no ext-interbase/pdo_firebird in 8.5) and the
  real-Mongo case (no ext-mongodb); PHP 8.4.25 (ext-interbase): 10/10 incl. Firebird, Mongo case
  gate-skips (no ext-mongodb). The real-Mongo case is owed to the lab run.
- Full suite at 052d7c88 vs clean origin/v3 (94e493ca), same env: no new failure attributable to
  this change; the extra non-passes are the two environment cases above plus three Cache tests
  that fail intermittently on BOTH trees in isolation (shared Mongo/memcached).
- Mutation: each guard disabled -> its test red; restored -> green (logs in the session scratchpad).

## Status: Complete (PHP) - lab verified; lead runs the authoritative lab suites
