# Plans, repositories, branches, releases and estimating

> Part of the **tina4-maintainer** skill. Moved verbatim out of `SKILL.md` on 2026-11-17 so the
> always-loaded floor stays small; load this file when the task needs it. The floor in
> `SKILL.md` (degrees of freedom, reflexes, verification, ISO controls, parity) still governs.

## Plan Documents

The full v3 plan - specs, the feature-by-feature audit, and the contract-fixture
consolidations - lives in ONE framework-agnostic place: **`tina4-documentation/plan/v3/`**
(the `tina4-documentation` repo, `main` branch). Cite plan docs by repo-prefixed path
(e.g. `tina4-documentation/plan/v3/01-FEATURE-MATRIX.md`). A per-repo `<repo>/plan/`
holds only that language's task plans; a task plan for four-framework work lives centrally in
`tina4-documentation/plan/v3/<task>.md`. A `MASTER.md` plan index is optional.

**The audit + consolidation backbone - where feature parity is actually driven:**

| Path (under `tina4-documentation/plan/v3/`) | Content |
|-----|---------|
| `98-feature-audit.md` | Master audit tracker: audit every feature, pick the best implementation (ADR-0004), park a plan |
| `specs/NNN-<feature>.md` | The canonical per-feature SPEC: intent, `AC-N` acceptance criteria, non-goals, tasks; each `AC-N` maps 1:1 to a fixture case (`audit-spec-coverage.py` gates it). The source of truth; code is derived. **Target state, adopted per feature:** today `specs/` holds only README + TEMPLATE |
| `features/NNN-*.md` | One parked plan per feature (pattern + methodology + tests to write) |
| `fixtures/*_contract.json` + `CONTRACT-MAP.md` | Executable consolidations - the SAME bytes drive all four runners; mapped spec `AC-N` -> fixture case -> ADR -> proven/owed |
| `DECISIONS.md` + `decisions/ADR-*.md` | ADR log; supersede explicitly, never silently |
| `tina4-documentation/scripts/audit-contract-fixtures.py` (repo root, not under `plan/v3/`) | The proven/owed source of truth - never hand-count (MASTER-SPEC numbers are stale) |

**To advance a feature:** pick from the parity backlog -> write/confirm its SPEC
(intent + `AC-N` acceptance criteria + non-goals, `specs/NNN-<feature>.md`) ->
MEASURE all four (no mocks, on the lab) -> DECIDE + write/supersede an ADR on a
contract change -> write the fixture (one case per `AC-N`) + fix divergences in ALL
FOUR with named +/- regressions -> flip owed->proven per criterion via
`audit-contract-fixtures.py` + `audit-spec-coverage.py` + update `CONTRACT-MAP.md` ->
verify at HEAD on the lab -> ship `feature/release<ver>` -> PR -> `v3` -> tag. Plan edits into tina4-documentation
MERGE, never rebase (the subtree graft + the PDF-sync bot).

**The specs** - deep detail beyond the bundled references:

| Doc | Content |
|-----|---------|
| 00-VISION.md | Core principles and inspirations |
| 01-FEATURE-MATRIX.md | The flat feature catalog (with `FEATURE-CATALOG.json`); read the current count there, never quote one from memory |
| 02-FROND-*.md | Frond template engine full spec |
| 03-TINA4HELPER-SPEC.md | frond.js frontend helper spec |
| 04-ARCHITECTURE.md | Full architecture details |
| 05-GAMEPLAN-PYTHON.md | Python reference implementation |
| 06-GAMEPLAN-PHP.md | PHP implementation plan |
| 07-GAMEPLAN-RUBY.md | Ruby implementation plan |
| 08-GAMEPLAN-NODEJS.md | Node.js implementation plan |
| 09-AI-REFERENCE-SPEC.md | AI-friendly paradigm reference |
| 11-QUEUE-SPEC.md | Queue system specification |
| 12-BENCHMARKS-SPEC.md | Benchmark categories and targets |
| 14-WEBSOCKET-SPEC.md | WebSocket implementation spec |

## Repository Locations

| Project | Repo | Release line (GitHub default branch) |
|---------|------|--------------------------------------|
| Python (leads API design) | tina4stack/tina4-python | `v3` |
| PHP | tina4stack/tina4-php | `v3` |
| Ruby | tina4stack/tina4-ruby | `v3` |
| Node.js | tina4stack/tina4-nodejs | `v3` |
| Rust CLI | tina4stack/tina4 | `main` |
| Docs + central plan | tina4stack/tina4-documentation | `main` |
| Book | tina4stack/tina4-book | `main` |
| Frontend | tina4stack/tina4-js | `master` |
| Delphi | tina4stack/tina4delphi | `master` |

### Branch model

There is no development / staging branch. The model is one release line per repo:

```
feature/<name> (or fix/<name>, feature/release<ver>) → PR → v3 → tag publishes
```

- **The four frameworks:** `v3` is the release line and the GitHub default branch. Every change
  goes feature branch → PR → required checks green → merge to `v3`. A tag on `v3` publishes.
- **`tina4` (CLI) and `tina4-documentation`:** the same PR flow against `main` (a CLI tag
  publishes; tina4.com deploys when `main` changes).
- **`main` / `v2` in the four framework repos** are the v2-era lines. v3 work never targets them.
- **No direct pushes** to `v3`, `main`, `master` or `v2` (ADR-0073, enforced by rulesets). Never
  force-push, never move or delete a tag.
- **State the target branch** in a plan or PR ("feature branch → PR → `v3`"). Do not ask which
  branch when the repo's release line answers it.

### Release Methodology — Feature Branch Per Release (adopted 2026-06-19)

> **Settled — signing the skills installer works on macOS AND Windows. Do not debate this again.**
> `install-skills.ps1` carries a Code Infinity EV Authenticode signature, and we have committed
> scripts to produce it on BOTH platforms: macOS `scripts/sign-installers-mac.sh` (osslsigncode +
> the SimplySign cloud card via PKCS#11; `brew install osslsigncode opensc libp11`), Windows
> `scripts/sign-installers.ps1` (signtool + the cert in `Cert:\CurrentUser\My`). The Mac-produced
> signature is validated on `windows-latest` in CI, so it is first-class, not a workaround. The only
> requirement is SimplySign Desktop open and logged in on whichever machine you sign from. Full steps:
> `references/checklists/signing.md`. `install-skills.sh` needs no signature.

**Going forward, every release is built on a dedicated release feature branch**, so the release
line stays releasable and an urgent patch can be cut without waiting on unrelated in-flight work:

1. **Cut `feature/release<version>`** (e.g. `feature/release3.13.42`) from the current release line
   in each framework repo.
2. **Bank everything for that release into the feature branch** — all four frameworks' parity work,
   fixes, docs, the version bump, and release notes. The release line stays clean until cut.
3. **On release** (the maintainer has said to ship this version - that instruction is the
   go-ahead, so do not ask again): merge `feature/release<version>` into the release line through
   a PR, **independently verify every framework's full suite is green at that HEAD** (see
   *Independent Verification*), then **tag `<version>`**, approve the parked `release` deployment
   as `tina4stack`, and verify the publish (`references/checklists/release.md`). The tag (matching
   `[0-9]*.*.*`, e.g. `3.13.42`) is what triggers the publish workflow in every repo — PyPI /
   Packagist / RubyGems / npm, and crates.io for the Rust CLI. **Merging alone never publishes;
   the tag does.**
4. **Urgent patches** get their own short-lived `feature/release<patch>` off the release line —
   banked, merged, tagged independently of any larger in-flight feature branch.
5. **Branch hygiene — merge when done, then delete.** The moment a branch's work lands (PR merged
   or tag cut), delete it on the remote AND locally: `gh pr merge <n> --merge --delete-branch` (a
   merge commit is the default; use `--squash` instead when the PR author asks for it), then
   `git branch -D <branch>` and `git push origin --delete <branch>` for anything left behind. Never
   leave merged branches lying around — they are a trap. Two hard rules, both from real incidents:
   - **Cut every release/feature branch FRESH from the base** (`git checkout -B feature/release<v> origin/v3`).
     Never resume a leftover same-named local branch — one silently carried an unshippable draft into a
     release cut and would have published it.
   - **Check the current branch before you commit** (`git rev-parse --abbrev-ref HEAD`). The repos do NOT
     share a default: the four framework repos live on **`v3`**, but `tina4` (CLI), `tina4-documentation`,
     and `tina4-book` default to **`main`**. A commit on a stale checkout lands on the wrong branch and
     pushes as a brand-new branch instead of the one you meant.
**Current reality:** the release line for the v3 series is the **`v3`** branch, which is also the
GitHub default branch of all four framework repos — every 3.13.x release is tagged there. `main` in
those repos is the v2-era line. Solo work follows the same flow: feature branch → PR → required
checks → merge (ADR-0073 has no solo exception).
The version line stays **3.13.x** while in flux — do FEWER, BIGGER releases (bundle many changes),
never release-per-tiny-change.

## Estimating time - measure, never guess

An ETA is a claim like any other: base it on measured durations, qualify it, and never give a
number you cannot explain. Human-pace estimates are wrong for agent work in both directions.

- **Estimate from measured agent durations, not human effort.** Reference points measured on
  2026-09-24 (Opus workers, four frameworks, lab + CI): a focused one-framework fix with tests
  15-30 min; a four-framework parity fix with real-engine tests 45-90 min; a large subsystem
  (a new HTTP server, a multipart parser) 90-120 min; one full framework suite on the lab
  15-25 min; a CI run 8-20 min; review + merge of a green PR under 2 min. Update these figures
  from the timestamps of completed work; do not reuse them blindly.
- **Build the ETA from the critical path, not the sum.** Parallel work finishes with its slowest
  item; sequential work (a queue run one at a time, a merge order, a rebase chain) adds up. Say
  which applies. Waits count: CI queues, required checks, lab contention between workers, rate
  limits and worker-slot limits are often longer than the work itself.
- **Give a range and name its biggest driver.** "2-3 h, driven by the four queued groups running
  one at a time" - never a single clock time with false precision.
- **Prefer milestones over clock times** when the path has many external waits: list the
  milestones in order and report each as it lands.
- **Re-estimate after the first milestone** using its real duration, and say what changed.
- **When the maintainer says an estimate is off, do not guess again.** Ask for the target time,
  or switch to milestone reporting, then state what fits before that time and what would move.
- **Record actual durations** in the plan's Commits/log lines (start and finish time), so the
  next estimate has data.

