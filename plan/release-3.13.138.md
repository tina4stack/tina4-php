# PHP 3.13.138 release

## Goal
Ship the merged fixes and measured-estimate skills on the coordinated 3.13.138 release.

## Scope
- [x] Fresh release branch from origin/v3; no existing feature branch reused.
- [x] Read AGENTS and release instructions; inventory all commits since 3.13.137.
- [x] Update runtime version, current agent metadata and release changelog.
- [ ] Final-head CI and independent coordinated lab verification.
- [ ] Root-coordinated release PR, merge, tag, publication and branch cleanup.

## Tests
Version consistency and packaging metadata checks passed locally. PHP App.php syntax and Composer manifest validation passed. Full services and release publication remain with the parent coordinator; no shared lab tests started here.

## Bugs
Real PostgreSQL/two-Fiber regression reproduced dirty reads before the fix. Exclusive context-local operation/transaction leases resolve the leak. Further real regressions reproduced swallowed PostgreSQL transaction failures; BEGIN/COMMIT/ROLLBACK now raise on driver failure. Focused pool/database/batch coverage: 74 tests, 558 assertions pass; no shared lab used. All included fixes are documented in CHANGELOG.md.

## Commits
Signed release-preparation commit recorded by git history.

## Status
Local preparation; do not push until the parent coordinates the final PR.

## Integrity evidence
Read-only PR/tag package build produces source archive (PHP) or both gems (Ruby), SPDX 2.3 inventory and SHA256SUMS. Tag-only publication downloads the immutable same-run artifact, verifies checksums, attests provenance and package SBOM, then publishes release assets and registry artifacts. Pinned actions; PR build has no publishing or OIDC permissions. Local packages/evidence/checksums verified; shared helper tests and YAML/shell syntax pass.
