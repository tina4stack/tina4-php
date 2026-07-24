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

- **Breaking: `\Tina4\SqlTranslation` is renamed to `\Tina4\SQLTranslator`.**
  The SQL dialect-translation class was the only one of the four frameworks not called
  `SQLTranslator` (Python, Ruby and Node.js all already used that name), drifting on both the
  acronym casing and the noun. PHP is now aligned. PSR-4 means the file moved too:
  `Tina4/SqlTranslation.php` -> `Tina4/SQLTranslator.php`.

  **Migration:** replace `\Tina4\SqlTranslation` with `\Tina4\SQLTranslator`. Method names and
  behaviour are unchanged. There is deliberately NO compatibility alias - the project rule is
  to rename the primary rather than accumulate shims.

### Fixed

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
