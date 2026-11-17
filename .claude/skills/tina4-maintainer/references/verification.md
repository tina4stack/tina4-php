# Independent verification and honest claims (full rules)

> Part of the **tina4-maintainer** skill. Moved verbatim out of `SKILL.md` on 2026-11-17 so the
> always-loaded floor stays small; load this file when the task needs it. The floor in
> `SKILL.md` (degrees of freedom, reflexes, verification, ISO controls, parity) still governs.

## Independent Verification & Honest Claims — Prove It, Then Qualify It

When work is produced by a subagent, a parallel agent, or a workflow — or even by a prior step in
your own session — **do not trust its claim that tests pass.** Re-verify it yourself before you
commit, merge, or release:

- **Re-run the full suite yourself** at the exact HEAD you're about to ship (`pytest`, `phpunit`,
  `rspec`, `npm test && npm run typecheck` - `npm test` is `tsx test/run-all.ts && npm run test:i18n`, so
  calling `run-all.ts` directly skips the i18n suite). An agent's own green run has repeatedly
  masked real bugs — the **".env.local lesson"**: a stray `.env.local` override clobbered real env
  vars and broke an integration suite that the agent's own run had passed; only an independent
  re-run caught it. Several cross-framework bugs (Node fragment parser, PHP backplane autoload,
  Ruby isolated-engine WS) were caught this same way.
- **Re-read the changed source**, not just the agent's summary. Agents over-claim (a GraphQL/WSDL
  audit flagged a "CRITICAL file-XXE" that empirically did not exist in any framework) and
  mis-report which file they touched — confirm the diff matches the summary.
- **Re-derive any empirical/security claim yourself** (actually run the XXE / billion-laughs payload
  against the parser, actually invoke the CLI) rather than accepting the agent's assessment.
- **Confirm lock-in tests are real** — both positive AND negative cases present, and the negative
  test genuinely fails against the old behavior.
- **Every reported bug becomes a permanent test — a bug is a future test.** The moment you
  reproduce a reported bug, write the named regression test that captures it BEFORE the fix, and
  confirm it FAILS on the current code (that red is your proof you reproduced the real bug); then
  make it pass with the fix. The test ships WITH the fix, in ALL FOUR frameworks (a bug reported
  against one almost always exists in the others — see the issue-sweep reflex), and it stays
  forever so the bug can never silently return. A bug closed without a regression test is not
  fixed, it is waiting to come back. Log it in the plan's Bugs section and tick it only when its
  test is green on a real (no-mock) run.
- **Zero skips AND zero failures — a skip is not a pass.** "Green" means the runner reports
  **0 failed AND 0 skipped**, never just 0 failed. A test that SKIPS (required service not
  provisioned, a `TINA4_TEST_*` env var unset, a client/driver missing, a broker cert stale) is
  UNVERIFIED coverage — the code path never ran, exactly as dangerous as a ghost test. Before you
  call ANY suite green, or merge/release on it: READ THE SUMMARY LINE (passed / failed /
  **skipped**), and if the skip count is not zero, treat the run as RED until every skip is
  resolved — provision the service, set the exact env the test reads, install the client, fix the
  broker — or prove the skip is an irreducible platform exclusion carrying a machine-readable
  reason (`[needs:absent-ext=…]`, `[needs:no-dac-override]`) that a second pass supplies. Run under
  `TINA4_REQUIRE_SERVICES=1` so an unprovisioned service FAILS the run instead of skipping silently.
  We have a habit of missing skips: a suite reported "passing" while it quietly skipped work is a
  false claim, and the skip count is the tell. 0 failed with N>0 skipped is NOT done.
- **No mock testing: mocks are not acceptable in any circumstances.** A test double (mock, stub,
  fake, spy, monkeypatch, or any in-test object that stands in for a real collaborator: a
  `FakeMongoCollection`, a script-introspection assertion, a hand-rolled in-test backend) may NEVER
  substitute for a real dependency, under any justification. There is NO "supplement" exception and
  NO "hard to reproduce" exception. A test that touches a dependency (any DB engine, MongoDB,
  Redis/Valkey/Memcached, RabbitMQ/Kafka, an HTTP/SMTP service, the filesystem, a socket) MUST
  exercise the real thing. If a failure mode is hard to trigger, reproduce it for real (inject a
  real error, point at a real service in a failure state, use a real temp resource); never simulate
  it with a double. "Verified"/"green" requires a real run; a passing mock test is not verification
  and must never be reported as one. CI provisions the services (Mongo/Redis/Valkey/Memcached/
  PostgreSQL/RabbitMQ/Kafka); use them and add any that is missing. The ONLY tests that need no live
  dependency are pure-logic unit tests that have no dependency and use no double (a pure function
  over its inputs); that is not a mock test. The **MongoDB-queue lesson**: the Node Mongo queue
  re-delivered every completed job (no ack path) and shipped for two releases because the queue
  tests were mock-based; they asserted the generated script's shape, never ran pop -> complete ->
  no-redelivery against a real Mongo; one live run caught it in minutes.
- **Reap what you spawn — never leave a process running when you finish.** If you start a
  server, a dev server, a benchmark target, a container or any background process, you own its
  death. Stop it in the same piece of work: `trap cleanup EXIT INT TERM` in a script, an
  explicit kill of the process GROUP (`pkill -P` / `killpg`) for a shell you launched, and free
  the port. Prefer the committed harness (which already traps) over an ad-hoc
  hand-started server; if you must start one by hand, kill it by hand before you report done,
  and say so. Before finishing, sweep for your own strays (`ps`, `lsof -ti:<port>`) — an idle
  process at 0% CPU is easy to miss and holds its port forever. The **orphan-server lesson**:
  six hand-started competitor-benchmark servers (CodeIgniter `spark serve` + `php -S`) were
  left running for 2.5 DAYS holding ports 7213/7215/7216/7217, from a task that had long since
  "finished" — no committed script was at fault, the harness traps correctly; it was ad-hoc
  process hygiene. This is the same lesson the CLI learned as a real bug (killpg on signals so
  the `npx -> tsx -> node` tree cannot orphan): a process you spawn and forget is a leak.
- **State every claim at 100% and qualify it — never assert what you haven't proven.** No "should
  work", no "probably passes", no "fixed" before you ran it. And do not assert a fact about the
  code from a single failed grep — VERIFY it (a mis-crafted pattern once "proved" a bench
  harness had no cleanup trap when it had one on line 354). Scope each claim to *where you actually
  verified it*: "3251 tests pass **on macOS, PHP 8.5**" — not "tests pass"; "green on Linux CI,
  not yet run on Windows" when that's the truth. An unqualified claim that holds on only one
  platform/runtime/DB engine is a false claim on every other. Qualify by **OS (Windows/macOS/Linux),
  language version, and DB engine** whenever behaviour could differ there — sessions, file paths,
  path separators, SSL/cert stores, native extensions, line endings, and case-sensitivity are the
  classic per-platform divergences. If you didn't test it on a platform, say so; don't imply you did.
- **A cross-cutting change breaks tests your feature gate never runs.** A per-feature gate that
  runs only the feature's OWN suite is blind to a SHARED contract you changed - a message/error
  string, a response shape, a status code, a DDL column, an env var. Other suites across the
  subsystem still assert the OLD contract and go red, but the gate never runs them, so the break
  ships "green" and surfaces only at the far-off full-suite merge gate, or gets misread as a flake.
  When a change touches anything more than one test file could assert, run the BROADER affected
  suites (the whole subsystem neighbourhood - ORM + validation + model + footguns, in every
  language) before calling it done. Example: feature 19 unified the validation message vocabulary;
  its gate ran only the validation suite, so a Node `orm.test.ts` assertion on the old "pattern"
  wording and a Ruby footguns assertion on the old "null" DB error both shipped red until a later
  feature's broader run caught them.
- **Prove a flake before you call it one.** "Flaky" or "seed-dependent" is a claim to PROVE, never
  assume - run the suspect in ISOLATION and at several seeds. A test that fails DETERMINISTICALLY in
  isolation is a real regression wearing a flake's coat; labelling it an "order-dependent state
  leak" buries a genuine contract break. Example: a Ruby footguns spec was reported as a seed flake;
  isolated at seed 1 it failed every time - feature 19's richer `validate()` answered "name is
  required" where the test still expected the driver's "null". Real regression, wrong label. Real
  order-dependent leaks DO exist in Tina4 - the point is to DISTINGUISH them by isolating and
  varying the seed, not to guess.


**Ghost tests are not acceptable, in any circumstances.** A ghost test is one
that LOOKS like coverage and never actually runs, or runs and proves nothing.
It is worse than no test: an absent test is visible in the count, a ghost is a
green tick over an untested code path. Every one of these has been found and
fixed in this project, so none of it is hypothetical:

- **A test that cannot run.** An unconditional stub - `skip("PostgreSQL live
  connection", "Requires running PostgreSQL server")` with NO code behind it -
  is not a skipped test, it is a test nobody wrote, wearing a skip's clothes.
  Four of these sat in tina4-nodejs reading as "environment not set up" while
  the lab had PostgreSQL, MySQL, MSSQL and Firebird running the whole time.
- **A test excluded before it is counted.** RSpec `describe ..., if: cond` DROPS
  its examples when `cond` is false - not pending, not skipped, simply absent
  from the total. Same for a file filtered out of a runner's list: tina4-nodejs
  reported "253 files, 0 failed" while 44 i18n tests were filtered out before
  counting, and no lab run had ever executed them. If something is not going to
  run, it must be REPORTED as not running.
- **A gate that can never open.** A guard that probes the wrong address is a
  permanently-dead test: `localhost:53050` when Firebird is on 3050, or
  `host === "localhost"` when the URL says `127.0.0.1`. The skip reason then
  reads like a missing service and hides an unwired test for months.
- **A guard that tests a PROXY instead of the property.** `geteuid() == 0` is
  not "the permission bits bind" - root loses that power the moment
  CAP_DAC_OVERRIDE is dropped, so the test skipped on hosts that could have run
  it perfectly well. Measure the property: write a 0400 probe and ask the kernel.
- **A test that asserts nothing, or cannot fail.** No assertion, a tautology, or
  an assertion so permissive it holds either way (`$row['X'] ?? $row['x']` hid a
  real cross-framework divergence for months). If you cannot say what change
  would turn it red, it is not a test.

**The discipline.** Prove every new test is a GATE by mutation: break the thing
it guards and watch it go red, then restore it. A test never seen to fail is not
known to work. When a test genuinely needs an environment the current one cannot
provide, say so in a machine-readable way - `[needs:absent-ext=pgsql]`,
`[needs:no-dac-override]` - and give it a second pass that supplies it, rather
than a skip that becomes permanent. And audit periodically: compare tests
DECLARED in source against tests REPORTED by the runner, and check every file on
disk is in the runner's list.

This is non-negotiable for anything that commits, merges, or releases — and the same honesty governs
every status you report: a claim is either proven-and-qualified, or it is not made. A re-run is
trivial against the cost of shipping a masked regression to four public registries.

