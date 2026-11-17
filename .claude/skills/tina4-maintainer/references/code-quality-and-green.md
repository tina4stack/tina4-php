# Code quality, metrics and green code

> Part of the **tina4-maintainer** skill. Moved verbatim out of `SKILL.md` on 2026-11-17 so the
> always-loaded floor stays small; load this file when the task needs it. The floor in
> `SKILL.md` (degrees of freedom, reflexes, verification, ISO controls, parity) still governs.

## Code Quality Standards

### Writing New Code

- **Earn every line** — If it doesn't serve a clear purpose, don't write it. If deleting it
  doesn't break anything, it shouldn't exist.
- **No dead code** — Remove unused imports, variables, functions. Don't comment out code.
  If it's in git history, it's not lost.
- **Explicit over implicit** — Name things clearly. `get_user_by_email` beats `find`.
- **Verbose, descriptive names — never abbreviate** — Spell every variable, method, and class
  name out in full words. `customer_invoice_total` not `cit`/`tot`; `calculate_outstanding_balance()`
  not `calcBal()`; `parsed_request_body` not `prb`. A name must read as exactly what it holds or
  does, with no decoding and no mental expansion. The only short names allowed are a conventional
  loop index (`i`/`j`) and the idiomatic one-line block/lambda argument. This is naming verbosity
  (good) and is INDEPENDENT of code volume: write fewer lines, but give every name its full word —
  verbose names, lean code.
- **Error messages are DX** — When something fails, tell the developer WHAT failed, WHY,
  and ideally HOW to fix it. Include the bad value, the missing file, the expected format.
- **Validate at boundaries only** — Trust internal code. Validate user input, HTTP requests,
  external API responses. Don't null-check parameters between internal modules.
- **Prefer stdlib** — Before reaching for a pattern, check if the language provides it.
  `itertools`, `collections`, `asyncio` in Python; built-in `Array` methods in JS; etc.
- **Think about the next reader** — Code is read 10x more than it's written. Optimize for
  comprehension, not cleverness.

### Reviewing Code

When reviewing or refactoring Tina4 code, actively hunt for:
- **Redundancy** — Duplicated logic that should be extracted (DRY)
- **Bloat** — Code that could be removed entirely without losing functionality
- **Misplaced responsibility** — Modules doing more than one thing
- **Unnecessary configuration** — Things that could be convention instead
- **External deps** — Anything that could be replaced with stdlib
- **Missing tests** — Especially negative cases and edge cases
- **Parity drift** — Output format mismatches with the Python reference
- **Performance waste** — Unnecessary allocations, copies, or loop iterations
- **Better alternatives** — Is there a simpler, faster, or more idiomatic way?

Don't just find problems — fix them or propose the fix. Every review should leave the
code better than you found it.

### Refactoring — Make It Smaller Without Changing What It Does

Refactoring is **behaviour-preserving** improvement: the code gets smaller, clearer, and faster to
read, and every existing test still passes **unchanged**. If a test had to change, that wasn't a
refactor — it was a behaviour change (do that deliberately, separately, with new lock-in tests).
The discipline:

1. **Characterise first.** Before touching code with thin coverage, add tests that pin its CURRENT
   behaviour (inputs → outputs, edge cases included). You cannot safely compact what you can't
   prove you didn't break — these tests are the safety net the whole refactor leans on.
2. **Measure before.** Capture a baseline: `tina4 metrics` (LOC, cyclomatic complexity,
   maintainability per file), bundle/dist size where it matters (e.g. tina4-js's <3 KB budget —
   `npm run test:size`), and the Carbonah numbers. A refactor that doesn't move these isn't worth
   the risk.
3. **One logical change per commit.** Extract-a-helper, collapse-a-duplicate, inline-a-needless-
   indirection, replace-a-loop-with-a-built-in — each is its own commit, suite green between them.
   Never mix a refactor with a behaviour change in one commit: a reviewer (and `git bisect`) must
   be able to trust that a "refactor" commit changed nothing observable.
4. **Compact, don't golf.** Smaller is the goal, but readability wins ties — prefer a stdlib/
   built-in call or a shared helper over a clever one-liner. Deleting code is the best refactor: a
   line removed can't rot, can't hide a bug, and ships faster.
5. **Verify independently.** Re-run the FULL suite yourself at each step (see *Independent
   Verification*) and re-measure. Green tests + smaller/simpler metrics + unchanged behaviour =
   done. Green tests but bigger/more-complex metrics = revert; you made it worse.

### Second pass: split large modules by concern

First-draft code is allowed to be monolithic — get it working, get it tested, ship the behaviour.
But a **second pass over any large module is expected**, and file size is the trigger to open it:
when a single source file grows past roughly **600 lines**, treat that as a *signal* to look, not a
hard cap. The number is arbitrary — a cohesive 800-line state machine can be right, and a tangled
300-line file can be wrong — but a big file is almost always where two things have accumulated
unseen: **more than one concern**, and **un-taken optimisations**. The second pass hunts both,
together:

1. **Split by concern.** A module doing N jobs becomes N files, each named for its one job and
   re-exported through the package's existing barrel / entry point so **nothing a caller imports
   changes**. Shapes seen across the stack: a 3,000-line `dev_admin` -> dashboard HTML, API
   endpoints, DB/query tools, connection tester, and metrics panel as separate files; a 2,900-line
   Frond `engine` -> tokenizer / parser / node-evaluator / filters / cache behind the same
   `render()`. This is the same one-concern-per-file rule the ORM models and route resources
   already obey (see *feedback_one_class_per_file*), applied to the framework core.
2. **Optimise while you are in there.** A large file is exactly where dead code, duplicated blocks,
   needless indirection, and hot-path waste hide. Run the *Reviewing Code* checklist and the
   *Refactoring* discipline above: delete the unused, collapse duplicates, fix the per-request hot
   paths — measured with `tina4 metrics` + Carbonah before/after (the same profiling that found
   Frond re-scanning invariant expression strings on every render).

Rules that keep the split safe:
- **Behaviour-preserving + API-stable.** Same public surface, same import paths, same rendered /
  returned bytes; the FULL suite passes **unchanged** (characterise first if coverage is thin — it
  is the safety net). A moved function is not a changed function.
- **Parity.** These monoliths are the SAME subsystems in all four languages — dev-admin, cli /
  server, frond, orm, mcp, database, metrics, migration, graphql, websocket, messenger, docs all
  cross the threshold in Python, PHP, Ruby AND Node. When you split one, split its siblings to the
  same shape so the frameworks stay navigable side by side: **Python master leads the
  decomposition, the others mirror it** (see *The Parity Mandate*).
- **One split per commit, suite green between.** Never fold a behaviour change into a split commit —
  `git bisect` and the reviewer must be able to trust that a "split" commit moved code and changed
  nothing observable.
- **Do not split for a number.** Maintainability is the goal, not a line count. If a file is over
  the threshold but is genuinely one cohesive concern, leave it and say why. Never shatter a file
  into fragments that are only ever imported together — that trades one big file for a pile of
  tightly-coupled small ones, which is worse to maintain, not better.

### Metrics + Carbonah run WHILE you code — a signal you do not enforce does nothing

Do not save quality checks for a "cleanup pass". `tina4 metrics` (LOC, cyclomatic complexity,
maintainability index, coupling per file — ranked "top offenders") and the Carbonah green
benchmark are **during-coding instruments**, not a final-gate afterthought:

- **Before you add to a module, run `tina4 metrics` on it.** If it is already an offender (low
  maintainability, high CC), your addition only makes it worse — split/refactor first, then add.
  After any non-trivial change re-run and confirm you did not push a file over the edge.
- **`tina4 metrics` gates CI — it is not just a report.** Wire **`tina4 metrics --fail-on-regression`**
  as the structural gate (ADR-0002, preferred over `--fail-on error`): it exits non-zero when a scan
  is measurably WORSE than the committed `.tina4-metrics.json` baseline — a new offender file, more
  offenders on a file, a worse worst-case complexity, or more duplicated lines — and never fails on
  movement it calls clean/improved. Re-baseline deliberately with a plain `tina4 metrics` run whose
  `.tina4-metrics.json` you then commit. (`--fail-on warn|error` still exists but fires on inherent
  file-size maintainability; the regression ratchet is the one to block on.) An offenders list nobody
  blocks on is noise. Full flag reference lives in the `tina4-cli` skill.
- **Carbonah before AND after** any change to a hot path (render, serialise, query, route
  dispatch): a change that regresses energy/latency is a regression even when the tests pass.
  Profiling (`cProfile` and friends) turns "it feels slow" into a named hot function to fix.

Hard lesson (2026): `tina4 metrics` had been correctly flagging 15+ modules PER LANGUAGE as
maintainability 0.0 / CC > 50 for months — dev-admin, frond, server, cli, orm, mcp — with the
framework's own average maintainability sitting at 27.8 (floor 40). Detection was never the
failure; **nobody enforced or acted on it**. A metric you neither gate on nor read mid-work is a
metric that does nothing. Treat the offenders list as a live worklist and split/optimise the moment
a file lands on it (see *Second pass* above), not "later".
6. **Parity still applies.** A refactor in the Python master that changes a shared shape must be
   mirrored; a refactor confined to one framework's internals need not be — but say which it is.

Bad targets to resist: rewriting a working, well-covered module for taste; renaming public API
"for clarity" (that's a breaking change, not a refactor); abstracting a pattern that occurs once.

### Testing Philosophy

Tests are written alongside code, not after. Every feature gets:
- **Positive tests** — Happy path works correctly
- **Negative tests** — Bad input is handled gracefully
- **Parity tests** — Output matches Python reference implementation

Test inline where the language supports it (Python's `@tests` decorator, PHP's doc comment
annotations). Use the standard test runner for integration tests.

## Green Code & Carbonah Benchmarking

Tina4 treats environmental impact as a first-class concern, not an afterthought. Code that burns
less CPU serves more users per watt, costs less to host, and produces less carbon. This aligns
perfectly with the "code you don't write" philosophy — less code, fewer allocations, less work
per request, less energy.

### The Green Code Mindset

Apply this at every step, not just when benchmarking:
- **Before writing**: Is there a way to do this with fewer operations? Can you avoid allocations?
- **During review**: Are there unnecessary copies, redundant loops, or wasteful serialization?
- **After implementing**: Run benchmarks before AND after your change. Every time. No exceptions.
- **When choosing patterns**: Prefer streaming over buffering, lazy over eager, O(1) over O(n)
  where the API allows it

### Carbonah Integration

Tina4 tracks its carbon footprint through Carbonah green benchmarks:
- **Energy per request** (mJ/req) — the primary metric
- **Carbon per 1M requests** (gCO2e) — the impact metric
- **Idle power** — what the framework costs just to exist
- **Carbon efficiency score** — overall rating
- Current rating: **A+** — guard this aggressively

### The 8 Benchmark Categories
JSON serialization, single DB query, multiple DB queries, template rendering,
large JSON, plaintext, CRUD operations, paginated query.

Each benchmark runs against the top 10 frameworks per language. Tina4 should be competitive
or better on all of them. When it's not, investigate why and improve.

### Continuous Improvement

Don't wait for someone to ask. When you're working on any part of Tina4:
1. **Question the status quo** — "Why does this work this way? Could it be simpler?"
2. **Measure before and after** — No change ships without benchmark comparison
3. **Look for consolidation** — Two modules doing related things? Maybe one can absorb the other
4. **Eliminate indirection** — Every layer of abstraction has a runtime and cognitive cost
5. **Propose improvements** — If you see a better pattern, suggest it. If you see dead weight, flag it

The goal is a framework that gets *better* over time — not just bigger. Every release should be
faster, simpler, and greener than the last.

### Competitive Awareness

Tina4 doesn't exist in a vacuum. Keep tabs on what competing frameworks are doing — not to copy
them, but to learn from their best ideas and adapt them to the Tina4 paradigm.

**Frameworks to watch:**
- **Python:** FastAPI, Starlette, Litestar, Django
- **PHP:** Laravel, Symfony, Slim, FrankenPHP
- **Ruby:** Rails, Sinatra, Hanami, Roda
- **Node.js:** Hono, Fastify, Express, Elysia, Bun
- **Frontend:** htmx, Alpine.js, Lit, Svelte

**What to look for:**
- Novel patterns that simplify DX (e.g., how Hono does middleware, how FastAPI does type-based validation)
- Performance tricks that could improve Tina4's benchmarks
- Clever approaches to problems Tina4 also solves (routing, ORM, templating, auth)
- Features gaining traction in the ecosystem that Tina4 users might expect

**How to apply it:**
- Filter everything through Tina4's principles — "the best code you write is the code you don't write"
- A great idea from Laravel that requires 500 lines of config is not a great idea for Tina4
- Adapt the concept, not the implementation. Tina4's strength is doing more with less.
- When you spot something worth adopting, propose it with context: what the competing framework
  does, why it's good, and how it could work in Tina4 without violating the zero-dep / simplicity principles

### Proactive Feature Gap Analysis

This is a core responsibility, not a nice-to-have. Don't just work on what's asked — actively
identify missing capabilities that would enhance the developer experience. When you're working
on any part of Tina4, you should always be asking:

- **"What's missing here?"** — What would a developer expect to find that doesn't exist?
  What's the natural next question after using this feature?
- **"What would make this simpler?"** — Can a smart default eliminate a step? Can the framework
  infer intent (like auto-JSON for objects, auto-parsing for POST bodies)?
- **Surface gaps in your dashboards** — Every audit or review should include a "Missing / Could
  Improve" section. Frame it as "this would enhance the experience because..." not just
  "this is missing."
- **Prioritize by impact** — A missing feature that 80% of developers would use matters more
  than one that 5% would use. Think about the common use cases.
- **Keep the bar high** — Any proposed addition must pass the same tests: does it earn its
  lines? Is it simple? Zero deps? Works across all four languages?
- **Think about distribution impact** — Will this addition increase the framework size? Can it
  be implemented without growing the framework? (The old ~5,000 LOC figure is an aspiration,
  not a budget; see Core Principle 3 in `references/principles-and-languages.md`.)

### Human AND AI Friendly — The North Star

This is the most important principle of all. Everything in Tina4 must be equally understandable
by humans reading the code AND by AI tools reasoning about it. This means:

**For humans:**
- Code reads like prose — clear names, obvious flow, no hidden magic
- Error messages explain what went wrong and how to fix it
- Convention over configuration means less to learn and remember
- Documentation lives close to the code it describes

**For AI:**
- Predictable patterns — an AI that understands one route handler understands all of them
- No metaprogramming or runtime code generation that hides behavior
- Consistent naming and structure across all four languages
- Self-describing APIs — the function signature tells you what it does

**The test:** If a developer or AI can't understand what a piece of Tina4 code does within
30 seconds of reading it, the code is too complex. Simplify it.

This dual-readability is Tina4's competitive edge. Most frameworks optimize for one audience.
Tina4 optimizes for both, because the future of development is humans and AI working together.

### Feature 140 Web Push

Web Push is a standalone outbound integration. Keep the contract provider-neutral and configuration-first across Python, PHP, Ruby, and Node.js. A configured but unavailable crypto capability must fail loudly; 404/410 responses mark subscriptions dead, while 408, 429, and 5xx responses are retryable. Use the backend developer references for the native API and keep the shared feature count at 140 cataloged entries.

