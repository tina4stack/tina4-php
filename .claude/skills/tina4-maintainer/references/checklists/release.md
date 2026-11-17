# Checklist: cutting a framework release


> The canonical, end-to-end release flow is documented at https://tina4.com/general/releasing - the four release tracks, the version-bearing files per repo, the skills bundle, EV signing and the gates. This checklist is the maintainer's working quick-reference; when the two differ, the documentation is the source of truth.

A release is tagged, not merged — **merging never publishes; the tag does.** A tag matching
`[0-9]*.*.*` (e.g. `3.13.146`, no `v`) triggers the publish workflow from the protected
`release` environment (required reviewer `tina4stack`). Do FEWER, BIGGER releases — bundle many
changes; never release-per-tiny-change. The version line stays `3.13.x` while in flux.

## Go-ahead

- [ ] **The maintainer has said to ship this version.** That instruction IS the go-ahead: after
      it, tag, approve the release deployment (as `tina4stack`), publish and verify without asking
      again.

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
- [ ] Merge `feature/release<version>` into the release line (`v3`) through a PR (required checks
      green), then tag `<version>` on each repo.
- [ ] Approve the pending `release`-environment deployment when it appears. The required reviewer
      is the `tina4stack` account, so approve AS it (it is in the gh keyring), without switching the
      active account:
      `GH_TOKEN=$(gh auth token --user tina4stack) gh api repos/<owner>/<repo>/actions/runs/<id>/pending_deployments -F "environment_ids[]=<env id>" -f state=approved`.
- [ ] **Verify LIVE on each registry** — PyPI / Packagist / RubyGems / npm — via the authoritative
      full registry doc (`dist-tags.latest` + `versions`), not the lagging `/latest` endpoint.
- [ ] **Docker Hub images** (a release is not done without them): run
      `tina4-documentation/scripts/publish-docker-images.sh <version> <repo-root>` from a host logged
      in to Docker Hub (`TINA4_DOCKERPUB_WORK` where `/root` is not writable). Then verify on the Hub
      API, not the script log, that `<version>` = `v3` = `latest`, amd64 + arm64, for all four.
- [ ] **Release notes live**: the four `docs/<lang>/36-releases.md` entries and the `docs/index.md`
      current-release block, merged only after the registries have the version; then confirm
      tina4.com shows it (the server deploys on push to `main`).

## Skills ship with the release (every release they changed in)

The skills reach users only through the pinned skills ref. If the pin is not moved, a skill change
reaches no one: the pin stayed at 3.13.156 through 3.13.157-163 (2026-11) while the skills changed.

- [ ] **Did any skill change since the pinned ref?** Compare the pin
      (`grep TINA4_SKILLS_REF tina4/install-skills.sh`) with this release:
      `git -C tina4-python diff --stat <pinned-ref>..origin/v3 -- .claude/skills .agents/skills .cursor/skills`
      (and the same in the other three framework repos and `tina4`). Any change means they ship now.
- [ ] **Copies in sync:** `scripts/sync-tina4-skills.sh --check` in tina4-python is clean, and the
      sibling repos carry the byte-identical `tina4-maintainer` / `tina4-js` trees.
- [ ] **Reference files added or removed?** Update `tina4/scripts/skills-list.sh` (the installer only
      fetches listed files), then regenerate the manifest (`tina4/scripts/gen-skills-sha256.sh`) and
      commit both in `tina4` through a PR.
- [ ] **After the framework tags are live:** `tina4/scripts/bump-skills-ref.sh <version>` (installers
      and the tina4.com wrappers), then follow [[signing]] to re-sign `install-skills.ps1` (SimplySign
      logged in), and create the bare `<version>` skills tag on `tina4` at the signed commit.
- [ ] **Stage the tina4.com bundle:** `tina4/scripts/gen-skills-bundle.sh <version>` into
      `tina4-documentation/docs/public/skills/<version>/`. It refuses a SKILL.md or reference that
      shrank by more than 15%; pass `--allow-regress` ONLY for an intentional move (say why in the
      commit - e.g. the 3.13.164 floor split moved content verbatim into references).
- [ ] **Verify the published bundle:** `https://tina4.com/skills/<version>/skills.sha256` serves, and a
      fresh `install-skills.sh` run installs the new `SKILL.md` (check a line that only the new version
      has).
- [ ] Branch hygiene: delete `feature/release<version>` on remote and locally the moment it lands.
- [ ] 💥 Bazinga only after the registries confirm the publish — never on the merge alone.

Firebird CI job hung ~4h on a bad runner? Cancel it and `gh run rerun --failed`.
