# Checklist: reviewing and merging a pull request

ISO control (ADR-0073): the release lines (`v3`, `main`, `master`, `v2`) are PR-only. Never
push to them, never force-push, never move a tag. A PR merges only when the checks below pass
AND you have independently verified the work — an agent's own green run has masked real bugs.

## Before you approve

- [ ] **Re-run the full suite yourself at the PR HEAD** — never trust the PR's own green.
      `pytest` / `vendor/bin/phpunit` / `bundle exec rspec` / `npm test && npm run typecheck`.
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
- [ ] **CodeQL / code-scanning is clean on the branch.** Check the PR's security results
      for alerts the branch INTRODUCES (`gh pr checks`, or `gh api repos/<owner>/<repo>/code-scanning/alerts?ref=refs/pull/<N>/head`).
      A new high/critical (injection, ReDoS, path traversal, hardcoded secret) blocks merge;
      confirm a `polynomial-redos` alert's real severity against the target engine (V8/`re`
      backtrack, Onigmo does not). A pre-existing alert the PR doesn't touch is noted, not
      inherited - just never let the PR ADD one.
      **Catch it BEFORE CI:** run `tina4 codeql` in the checkout before you push - it runs
      the same GitHub "default setup (extended)" scan (the per-language security-extended
      suite) over JS/TS, Ruby, Python and GitHub Actions locally and exits non-zero on any
      finding, so a new alert never reaches the PR in the first place. (`tina4 install codeql`
      / `tina4 doctor` set it up; PHP is Semgrep, not CodeQL.)

## The merge gate - run it immediately before every `gh pr merge`

The 🚦 reflex in `SKILL.md` points here. It applies to EVERY PR - yours, a worker's, an agent's, a
contributor's, a bot's - and it runs even when the checks above are already green. Scan first, merge
second: in 2026-11 a PR was merged before its test-double scan, and the fake it relied on was only
found afterwards.

- [ ] **Test-double scan of the ADDED lines.** Read every hit, do not just count them:
      `gh pr diff <N> --repo <r> | grep '^+' | grep -inE 'mock|monkeypatch|stub|fake|spy|double\(|to receive|jest\.fn|vi\.fn|createMock|instance_double'`
      A comment saying "no mocks" is fine. A fake collaborator is a block - including a test that
      builds on a long-standing fake helper already in the file (it is still a fake; replace the
      helper, as nodejs#200 did for `mockReq`/`mockRes`).
- [ ] **No unresolved review threads.** The `v3` rulesets require thread resolution, and CodeQL posts
      its findings as threads, so a PR with every check green can still be blocked:
      `gh api graphql -f query='query{repository(owner:"tina4stack",name:"<repo>"){pullRequest(number:<N>){reviewThreads(first:50){nodes{isResolved comments(first:1){nodes{author{login} body}}}}}}}'`
      Fix the finding, or - only when it is genuinely safe in context - dismiss the alert with a
      written reason (the API caps it at 280 characters) and resolve the thread.
- [ ] **Honour merge-method requests** in the PR body ("please squash-merge"): an author asking for a
      squash usually has intermediate commit messages that describe wrong states.
- [ ] **The summary matches the diff.** Files, behaviour and test claims. For a new test, confirm it
      RAN in CI (the runner's count moved, the test name in the log), not only that the run was green.
- [ ] **A red check is diagnosed before it is re-run** (`references/debugging.md`). A re-run may
      unblock a correct PR; the flake behind it still gets fixed in its own PR.
- [ ] **Dependency and lock bumps** (Dependabot included) also update the licence review list
      (`scripts/dependency-licenses.json`) and pass the dependency audit; a lock-only bump fails the
      release-package licence check.
- [ ] **`UNKNOWN` mergeability right after another merge** is GitHub recomputing. Wait for it to
      settle; it is neither green nor red.
- [ ] **A contributor's PR that needs changes they have not made:** never push to their branch.
      Supersede it with a new PR that keeps their commits and authorship, and say so on theirs.

## Merging

- [ ] Merge with a merge commit by default (`gh pr merge <N> --merge --delete-branch`); use
      `--squash` instead when the PR author asks for it.
- [ ] Close the plan item in the same turn: `[x]` + the merge commit hash under Commits.

See [[release]] for cutting a release once a batch of PRs has landed, and [[parity-sweep]] for
finding the siblings of a bug before you close it.
