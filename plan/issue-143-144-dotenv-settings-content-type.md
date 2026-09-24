# Task: tina4-python#143 / #144 parity - header Content-Type, settings read when used (PHP)

**Outcome:** a Content-Type set with `header()` is the response's one Content-Type
(any name case; an explicit content-type argument still wins), and a setting in
`.env` applies. Governed by `tina4-documentation/plan/v3/decisions/ADR-0072.md`;
the cross-framework plan is `tina4-python/plan/issue-143-144-dotenv-settings-content-type.md`.

## Scope
- [x] Reproduce #144 and #143 for real on origin/v3 (PHP)
- [x] Scan for settings read at load time
- [x] header()/withHeaders(): Content-Type (any case) stored under one key; $response($data) keeps it; send() casing
- [x] Request: a bad TINA4_MAX_UPLOAD_SIZE uses the 10MB default instead of 0
- [x] .env upload cap and health path already honoured (App loads .env before the server reads limits): locked in
- [x] Regression suite `tests/DotenvSettingsAndContentTypeTest.php`, red first, mutation-proved
- [x] Full suite on the lab (Linux, PHP 8.3.6, TINA4_REQUIRE_SERVICES=1, lab-verify recipe), 58c1172a merged with v3 f0c90875:
      main pass (grpc + openswoole off) 5788 tests: 1 error + 1 failure, both Firebird races with a concurrent root phpunit run on the shared tina4_php.fdb (MigrationV3Test, MigrationFootgunsLiveEngineTest: 2/2 green in isolation);
      41 skipped = 4 openswoole (openswoole pass: OK 4/4) + 37 graph-driver (graph pass with the drivers composer-required in a lab-only copy: 39/39, 0 skipped)
- [x] Found on the way: MigrationFootgunsLiveEngineTest listed RDB$RELATIONS through fetch()'s 100-row default; the shared DB has 102 user tables, so it failed on every branch. Filtered to the table under test (58c1172a)

## Tests (real server booted from a project whose .env carries the settings, no mocks)
- [x] header content type replaces the detected type
- [x] a lowercase content type header is the same header
- [x] header content type survives a string body
- [x] an explicit content type argument wins over the header
- [x] without a header the detected type is used (negative)
- [x] max upload size from dotenv is enforced
- [x] a body under the dotenv limit is accepted (negative)
- [x] health path from dotenv is served
- [x] max upload size follows the environment
- [x] a bad max upload size falls back to the default

## Commits
- cae030ad  fix: header Content-Type is the one Content-Type; bad upload limit falls back
- 58c1172a  test(migration): filter the Firebird relation listing to the table under test

## Status: Complete (PR open, not merged; depends on #217)
