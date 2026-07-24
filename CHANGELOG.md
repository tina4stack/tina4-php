# Changelog

Tina4 keeps ONE version across all four frameworks (Python, PHP, Ruby, Node.js), so a version
number means the same thing everywhere.

**The authoritative release notes for every shipped version live in the documentation:**
https://tina4.com/php/36-releases

This file is deliberately NOT a copy of those notes. Duplicating them is exactly how a
changelog rots into claiming a version that was never cut, so this file records only
UNRELEASED work. When a version ships, its notes go to the release notes above.

## Unreleased

### Added

- **MQTT 3.1.1 client** (`Tina4\Mqtt` / `Tina4\MqttMessage`), zero-dependency (PHP streams +
  `ext-openssl`), verified against a real broker with no mocks. Publish/subscribe/consume, QoS 0/1,
  retained, Last Will, per-stream TLS, QoS 2 refused loudly. Takes the family to **98 built-in
  features**.

### Changed

- **Breaking: `\Tina4\SqlTranslation` is renamed to `\Tina4\SQLTranslator`.**
  The SQL dialect-translation class was the only one of the four frameworks not called
  `SQLTranslator` (Python, Ruby and Node.js all already used that name), drifting on both the
  acronym casing and the noun. PHP is now aligned. PSR-4 means the file moved too:
  `Tina4/SqlTranslation.php` -> `Tina4/SQLTranslator.php`.

  **Migration:** replace `\Tina4\SqlTranslation` with `\Tina4\SQLTranslator`. Method names and
  behaviour are unchanged. There is deliberately NO compatibility alias - the project rule is
  to rename the primary rather than accumulate shims.

### Fixed

- **Security: the bundled Swagger UI static assets now honour the swagger gate.** `/swagger`,
  `/swagger/`, `/swagger/index.html` and `/swagger/oauth2-redirect.html` were served from the
  framework's own public directory BEFORE route matching (with directory-index resolution turning
  `/swagger` into `swagger/index.html`), so a production server with `TINA4_SWAGGER_ENABLED=false`
  still served the whole UI while `/swagger/openapi.json` correctly 404'd. Static serving now checks
  the gate before it resolves an index. Bite-verified lock-in test. (python#97)
- **The startup banner advertises only a surface that answers.** The `Swagger:` and `Dashboard:`
  rows printed unconditionally, so a production log claimed a dev surface was exposed and a
  developer following the link hit a 404. Each row is now built by one pure helper of
  (port, swagger_enabled, dev_admin_enabled), unit tested rather than inferred from stdout.
  (python#99)
- **MQTT TLS tests verify the CA before trusting it.** A stale CA file in the shared temp directory
  made six TLS tests FAIL instead of skip, in all four frameworks, pointing at correct TLS code.
  The suites now confirm the CA actually validates the broker certificate before treating the TLS
  environment as present. (python#98)
- **`App::run()` under a web SAPI serves the request instead of binding a second socket.** The
  shipped `index.php` calls `run()`; under php-fpm, apache2handler or `php -S` that tried
  `findAvailablePort()` + a new `Server()` on every request and never produced a response, so the
  documented nginx + php-fpm deployment in `nginx.conf.example` could not work. `run()` now
  delegates to `handle()` when `php_sapi_name() !== 'cli'`. Fixes existing projects with no
  `index.php` edit. (php#180)
- **The Composer archive drops from 8.8MB to 5.4MB.** A `.gitattributes` with `export-ignore` keeps
  `tests/`, `example/`, `benchmarks/`, `dockers/`, `.github/` and the plan files out of the
  published package, and `src/public/js/tina4-dev-admin.js` (937K, a byte-for-byte copy of the
  `.min.js` beside it, referenced nowhere) is deleted. No runtime file was removed. (php#181)


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
