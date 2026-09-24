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
No runtime edits in this release-preparation commit. All included fixes are documented in CHANGELOG.md.

## Commits
Signed release-preparation commit recorded by git history.

## Status
Local preparation; do not push until the parent coordinates the final PR.
