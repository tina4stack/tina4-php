# Checklist: reviewing and merging a pull request

ISO control (ADR-0073): the release lines (`v3`, `main`, `master`, `v2`) are PR-only. Never
push to them, never force-push, never move a tag. A PR merges only when the checks below pass
AND you have independently verified the work — an agent's own green run has masked real bugs.

## Before you approve

- [ ] **Re-run the full suite yourself at the PR HEAD** — never trust the PR's own green.
      `pytest` / `vendor/bin/phpunit` / `bundle exec rspec` / `npm test` + typecheck.
- [ ] **Zero failures AND zero skips.** Read the summary line. A skip is UNVERIFIED coverage,
      not a pass. Run under `TINA4_REQUIRE_SERVICES=1` so an unprovisioned service fails loud.
- [ ] **No mocks.** Any test touching a dependency (DB, Mongo, Redis, broker, SMTP, socket,
      filesystem) exercises the REAL thing. A passing mock test is not verification.
- [ ] **Re-read the diff**, not the summary. Agents over-claim and mis-report which file changed.
      Confirm the diff matches the description; re-derive any security/empirical claim yourself.
- [ ] **New tests are real gates** — positive AND negative, proven by mutation (break the guarded
      thing, watch it go red, restore). Every fixed bug ships a named regression test.
- [ ] **Parity.** A fix in one framework lands in all four with equivalent tests, unless the PR
      states why it is genuinely one-language. Output shapes match the Python reference.
- [ ] **Cross-cutting change?** Run the BROADER affected suites (the whole subsystem
      neighbourhood in every language), not just the feature's own gate — a shared contract
      change (message string, response shape, status code, DDL column, env var) breaks suites
      the feature gate never runs.
- [ ] **Security + zero-dep.** No new runtime dependency without a written reason. No secret,
      PII, or exploit payload in the diff, tests, or PR text.
- [ ] **CI required checks are green** — and authored by a real identity, not a bot token that
      never triggers the required checks (see the CLI packaging-PR lesson).

## Merging

- [ ] Squash/merge per the repo convention, then `--delete-branch`.
- [ ] If it was a hotfix on `main`, back-merge to `development`/`staging` immediately — never let
      `main` run ahead of the lower branches.
- [ ] Close the plan item in the same turn: `[x]` + the merge commit hash under Commits.

See [[release]] for cutting a release once a batch of PRs has landed, and [[parity-sweep]] for
finding the siblings of a bug before you close it.
