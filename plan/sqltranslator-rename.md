# Task: Converge the PHP SQL dialect-translation class on `SQLTranslator`

## Goal
Rename PHP's `\Tina4\SqlTranslation` to `\Tina4\SQLTranslator` so all four backends
name the SQL dialect-translation class identically. Breaking change, no alias.

## Context — the drift (verified in source 2026-07-23)
Three of four frameworks already agree, and Python is master:

| Framework | Class | File |
|-----------|-------|------|
| Python (master) | `SQLTranslator` | `tina4_python/database/adapter.py:613` |
| Ruby | `SQLTranslator` | `lib/tina4/sql_translator.rb:18` |
| Node | `SQLTranslator` | `packages/orm/src/sqlTranslator.ts:22` |
| PHP (outlier) | `SqlTranslation` | `Tina4/SqlTranslation.php:14` |

PHP drifted on BOTH axes: the acronym casing (`Sql` vs `SQL`) and the noun
(`Translation` vs `Translator`). 3-of-4 agree and Python is master, so PHP converges.

PSR-4 requires the file basename to match the class name, so the file renames with it.

## Parity
| Item | Python | PHP | Ruby | Node |
|------|--------|-----|------|------|
| Class named `SQLTranslator` | ✅ | ✅ (this change) | ✅ | ✅ |

## Scope
- [x] `git mv Tina4/SqlTranslation.php Tina4/SQLTranslator.php` (history preserved)
- [x] Rename the class and EVERY internal reference (adapters, ORM, tests)
- [x] Rename the two dedicated test files with `git mv` + their test classes
- [x] NO backwards-compatibility alias — no `class_alias()`, no deprecated subclass
- [x] Update `tina4-php/CLAUDE.md` so the documented API matches code reality
- [x] Update forward-looking book reference docs (`tina4-book/plan/*`)
- [x] Leave historical `36-releases.md` entries untouched (they record what shipped)
- [x] `composer dump-autoload` + load the class through the real PSR-4 autoloader
- [x] Full `vendor/bin/phpunit` green, no regression against the 3819 baseline

## Tests (real, positive + negative — no mocks)
The two renamed test files ARE the behavioural proof: 17 public methods exercised
by pure-logic tests over real string inputs, unchanged except for the identifier.

Named lock-in added to `tests/SQLTranslatorTest.php`:
- [x] `testClassIsAutoloadableUnderTheParityName` — POSITIVE: resolves through the
      real composer PSR-4 autoloader; file basename matches the class name
- [x] `testPreRenameClassNameNoLongerExists` — NEGATIVE: `Tina4\SqlTranslation` is
      gone and no alias reintroduces it
- [x] `testRepresentativeTranslateStillWorksUnderTheNewName` — firebird / mssql /
      postgresql translate output pinned exactly

Negative-test proof: all three assertion bodies were run against an isolated
worktree at pre-rename HEAD (`5dd9ed18`, with its OWN vendor + regenerated
autoload) and all three FAILED there. A symlinked vendor initially contaminated
that run by resolving `__DIR__` back into the renamed tree — the copy is required
for the proof to mean anything.

## Deliberately left alone
- `tina4-documentation/docs/{php,python,ruby,nodejs}/36-releases.md` and
  `tina4-book/book-*/chapters/36-releases.md` — historical release notes. They
  correctly record what shipped under the OLD name; rewriting them falsifies history.
- `tina4-php/.claude/skills/tina4-maintainer/SKILL.md:230` — a language-agnostic
  subsystem list, byte-identical in all four repo copies plus the global install.
  Editing only the PHP copy would create skill drift; it needs one coordinated
  bump across all five copies. Surfaced, not silently changed.

## Commits
- (hash) refactor(db)!: rename SqlTranslation to SQLTranslator for cross-framework parity

## Status: Complete (tina4-php v3, uncommitted book edits reported separately)
