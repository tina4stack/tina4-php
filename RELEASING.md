# Releasing tina4-php

The published version is driven by the **git tag** — pushing a tag matching
`[0-9]*.*.*` (e.g. `3.13.121`) is what triggers the Packagist release. Merging
alone never publishes; the tag does.

## Version-bearing files

The version lives as a **literal** in two files, both bumped on every release:

| File | Where |
|------|-------|
| `Tina4/App.php` | `public static string $VERSION = 'X';` (the single source of truth) |
| `CLAUDE.md` | footer line `Version X - ...` |

`composer.json` deliberately carries **no** `version` key — Packagist derives the
version from the git tag. Do not add one.

Not version-bearing (they carry version literals that are not the current release
version, so they are never part of a bump): `CHANGELOG.md`, `llms.txt`, the
`Dockerfile` `FROM` example, the `.agents/.claude/.cursor` skill
`updated_for_version` field, and the `vX.Y.Z` "changed in" docblock annotations
throughout `Tina4/*.php`.

## Pre-tag checklist

1. Bump `Tina4/App.php` `$VERSION` and the `CLAUDE.md` footer to the new version.
2. Update `CHANGELOG.md`.
3. **Run the version-consistency precheck — BEFORE `git tag`:**

   ```sh
   php scripts/check-version-consistency.php 3.13.NNN
   ```

   It prints `PASS`/`FAIL` per file and exits non-zero, naming any file left
   behind by a partial bump, if the two version-bearing files disagree with
   `3.13.NNN`. Exit 0 means every version-bearing file agrees — safe to tag.
   This moves the check LEFT of the tag: a partial bump fails locally instead of
   on CI after the tag is already pushed.

4. Run the full suite green (`./vendor/bin/phpunit`).
5. Tag and push:

   ```sh
   git tag 3.13.NNN
   git push origin 3.13.NNN
   ```

## Branch flow

Releases are built on a dedicated `feature/release<version>` branch cut fresh
from the active release line (`v3`), merged back to `v3`, verified green at that
HEAD, then tagged. See `.claude/skills/tina4-maintainer/SKILL.md`
("Release Methodology") for the full recipe.
