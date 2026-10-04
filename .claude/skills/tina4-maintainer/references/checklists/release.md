# Checklist: cutting a framework release

A release is tagged, not merged — **merging never publishes; the tag does.** A tag matching
`[0-9]*.*.*` (e.g. `3.13.146`, no `v`) triggers the publish workflow from the protected
`release` environment (required reviewer `tina4stack`). Do FEWER, BIGGER releases — bundle many
changes; never release-per-tiny-change. The version line stays `3.13.x` while in flux.

## Bank the release

- [ ] Cut `feature/release<version>` FRESH from the active release line (`git checkout -B
      feature/release<v> origin/v3`). Never resume a leftover same-named local branch.
- [ ] Confirm the branch before committing (`git rev-parse --abbrev-ref HEAD`). The four
      frameworks default to `v3`; `tina4` (CLI), `tina4-documentation`, `tina4-book` default to `main`.
- [ ] Bank all four frameworks' work: parity fixes, docs, version bump, CHANGELOG.

## Version-bearing files (bump EVERY one, per framework) + consistency guard

- [ ] **Python:** `pyproject.toml`, `tina4_python/__init__.py`, `CLAUDE.md` (header + footer),
      `AGENTS.md`, `uv.lock` self-entry, CHANGELOG → `python scripts/check_version_consistency.py`.
- [ ] **PHP:** `Tina4/App.php` `$VERSION`, `CLAUDE.md`, `AGENTS.md`, CHANGELOG →
      `php scripts/check-version-consistency.php`.
- [ ] **Ruby:** `lib/tina4/version.rb`, `CLAUDE.md` (×2), `AGENTS.md`, `Gemfile.lock` (×2),
      `scripts/dependency-licenses.json` self-entry, CHANGELOG → `ruby scripts/check_version_consistency.rb`.
- [ ] **Node:** root + 5 workspace `package.json`, `package-lock.json` (7 entries), `CLAUDE.md`,
      `AGENTS.md`, CHANGELOG → `npm run release:precheck`.

## Verify, tag, publish

- [ ] **Independently re-run every framework's full suite green at the merge HEAD** (no mocks,
      zero skips) BEFORE tagging.
- [ ] Merge `feature/release<version>` into the release line (`v3`), then tag `<version>` on each repo.
- [ ] Approve the pending `release`-environment deployment when it appears:
      `gh api repos/<owner>/<repo>/actions/runs/<id>/pending_deployments -f state=approved`.
- [ ] **Verify LIVE on each registry** — PyPI / Packagist / RubyGems / npm — via the authoritative
      full registry doc (`dist-tags.latest` + `versions`), not the lagging `/latest` endpoint.
- [ ] Skills changed this release? Follow [[signing]] (new ref, bump both installer defaults,
      re-stage the bundle, re-sign `install-skills.ps1`) and regenerate `skills.sha256`.
- [ ] Branch hygiene: delete `feature/release<version>` on remote and locally the moment it lands.
- [ ] 💥 Bazinga only after the registries confirm the publish — never on the merge alone.

Firebird CI job hung ~4h on a bad runner? Cancel it and `gh run rerun --failed`.
