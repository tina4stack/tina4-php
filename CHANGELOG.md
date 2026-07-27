# Changelog

Tina4 keeps ONE version across all four frameworks (Python, PHP, Ruby, Node.js), so a version
number means the same thing everywhere.

**The authoritative release notes for every shipped version live in the documentation:**
https://tina4.com/php/36-releases

This file is deliberately NOT a copy of those notes. Duplicating them is exactly how a
changelog rots into claiming a version that was never cut, so this file records only
UNRELEASED work. When a version ships, its notes go to the release notes above.

## Unreleased

### Changed

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
  verification. A token minted for a different algorithm under the same secret —
  including `alg: "none"` — was accepted as long as its signature matched. This
  blocks algorithm substitution where one signing secret is shared by services
  configured for different algorithms.

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
  stamp an `nbf` — parity with the Python and Node masters. Closes tina4-php#187.

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
  carried an HMAC-SHA256 signature — a 32-byte digest where the header promised 64 —
  which any RFC-conformant verifier rejects. Verification was worse: `validToken()`
  only knew `HS256` and `RS256`, so with `HS512` configured *every* token was
  invalid. The digest is now looked up from the algorithm, so the header always
  names the digest that actually signed, and the whole HMAC family verifies.
  `RS256` (a PHP/Node-only extra — Python and Ruby cannot sign it without a
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
