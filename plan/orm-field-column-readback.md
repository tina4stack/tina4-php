# Task: ORM field column read-back (lock-in, parity with tina4-python)

**Outcome:** PHP has no per-field column option; `$fieldMapping` (property => column) is the
one resolver (`getDbColumn()`, reversed in `fill()`). Reproduced the Python scenario on five
real engines: PHP already round-trips. This adds the shared lock-in test so it stays that way.

## Scope
- [x] Reproduce on origin/v3: SQLite, PostgreSQL, MySQL, MSSQL green locally; Firebird on the lab
- [x] tests/OrmFieldColumnReadbackTest.php (16 cases x 5 engines), mutation-proved (fill() reverse -> 48 failures)
- [x] Full suite on the lab: 5806 tests, 0 failures, 41 skipped (37 [needs:graph] + 4 Swoole cases that the second, openswoole-on pass runs green)
- [x] Found on the way: pdo_dblib timeout constant deprecated in PHP 8.5; Firebird migration test precondition paged past its table (102 tables > 100-row page)

## Parity
| Path | Python | PHP | Ruby | Node |
|------|--------|-----|------|------|
| per-field column option | `Field(column=)` | none | none | none |
| read-back through the resolver | fixed | already correct | fixed | fixed |

## Bugs
- none in PHP. Noted, not changed: `toDict()` default `'snake'` case emits DB COLUMN names
  (documented PHP contract); Python `to_dict()` emits attribute names. Open question for the
  maintainer, see the PR.

## Commits
- 0d9e557b  Lock in: fieldMapping round-trips on every ORM read path
- cdca3a9f  MSSQL (pdo_dblib): use Pdo\Dblib::ATTR_CONNECTION_TIMEOUT when present
- 0836c874  test: Firebird quoted-table precondition asks for the relation by name

## Status: Complete
