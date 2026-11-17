# Grounding, decisions (ADRs) and specs

> Part of the **tina4-maintainer** skill. Moved verbatim out of `SKILL.md` on 2026-11-17 so the
> always-loaded floor stays small; load this file when the task needs it. The floor in
> `SKILL.md` (degrees of freedom, reflexes, verification, ISO controls, parity) still governs.

## Verify Against the Live API — Don't Guess

The framework reflects its own code into a **live API index** — the source of truth for which classes
and methods exist and their exact signatures in the working tree. Before claiming a method exists,
writing a doc example, or changing a signature, query it instead of trusting memory. This is the
machine-checkable side of the First Principle (docs must match code reality). Three MCP tools expose
it when the dev server runs (`tina4 serve`, `TINA4_DEBUG=true`):

- **`api_search("queue consume")`** — ranked search across framework + user code; returns fqn, signature, file:line.
- **`api_class("Frond")`** — full method list + signatures for a class (bare name, import path, or full fqn all resolve).
- **`api_method("Frond", "add_test")`** — exact signature, params, return type, file and line (`addTest` in PHP/Node).

When you add or rename a method, confirm `api_method` reflects it before updating the book, the docs
site, or a CLAUDE.md example — and run `docs_search` to catch prose that now drifts. If a lookup
returns nothing for a name you expected, the name does not exist in this version: fix the code or the
doc, do not paper over it.

## Grounding — the source tree and live API index are the authority

Before writing framework code or the app-style examples that ship in docs / the gallery /
parity demos, ground in the two authorities that never lie and are always available:

1. **The source in `tina4_python/`** (and the sibling repos) — read it. It is the single source
   of truth. Nothing outranks the code.
2. **The live API index** (`api_search` / `api_class` / `api_method`, above) — exact current
   signatures from the working tree.

**`tina4_context(instruction, language)`** (the `tina4-coder` MCP server) is an OPTIONAL
orientation aid, not a required step - a quick way to surface idioms and the shapes of field
objects before you dive into the source. **Never depend on it:** it is a hosted server (Bearer
token, `https://mcp.tina4.com`) that can be unreachable, and it can lag the working tree. When it
is up it points you at the source faster; when it is not, you lose nothing - read the source and
query the live API index directly. If its guidance and the code ever disagree, the code wins.
Do not let a skill or a habit make the framework's own maintenance depend on an external service.

**Do NOT use `tina4_code` to generate framework or example code.** It is deprecated on the tools'
own evidence: in a boot-and-verify gate `tina4_code` FAILED where Claude grounded with `tina4_context` PASSED, so the tools point to grounding + a strong model, not the self-hosted coder. It also lags the working tree and emits APIs that no
longer exist, the exact drift this skill prevents. Write every line yourself so you own its
correctness, then prove it against the source and the live API.

## Decisions are ADRs: consult them before you change a contract, extend them when you make one

Cross-framework behaviour is not settled by taste or by whoever edits last. It is settled by an
**ADR** (`tina4-documentation/plan/v3/decisions/ADR-NNNN.md`, indexed in `DECISIONS.md`) and then
made machine-checkable by a **contract fixture**
(`tina4-documentation/plan/v3/fixtures/*_contract.json`, gated by
`scripts/audit-contract-fixtures.py`). The two are one system: the ADR says WHY, the fixture proves
the four frameworks still obey it.

**Start at the map.** `tina4-documentation/plan/v3/CONTRACT-MAP.md` ties every audited feature to its
fixture, its ADRs, and its proven-or-owed status. Before you touch a cross-framework contract - a
response shape, a key set, a method signature, an env var, a wire format, a status code - open
CONTRACT-MAP, find the governing ADR, and read it. The ADR is the authority; the code implements it,
the fixture guards it, this skill and its `references/` only describe it.

Five rules that keep the decisions and the code from drifting apart:

- **Read the governing ADR before changing a contract.** If a change contradicts an accepted ADR,
  you are not fixing a bug, you are reopening a decision - stop and supersede it deliberately.
- **A fork in the road is not settled until it is an ADR.** When you pick between real options that
  affect more than one framework, write the next `ADR-NNNN`, cite it in the fixture's invariants and
  in your commit, and only then implement.
- **Supersede explicitly, never silently.** A new ADR carries `Supersedes: ADR-XXXX`; you never quietly
  change what an accepted ADR decided. (See `reference_decision_log`: supersede, do not silently change.)
- **When you prove or change a contract, move the fixture and the map together.** Update the
  `*_contract.json`, re-run the auditor, and sync CONTRACT-MAP from the auditor's OWN counts, never a
  hand count. A row that disagrees with the auditor is a bug in the map.
- **A doc that states a contract cites the ADR that governs it.** The pagination envelope in
  `references/routing-and-orm.md` cites ADR-0043; do the same for any behaviour you document here.

Recent decisions worth knowing by number: ADR-0002 (native metrics engine), ADR-0004 (best
implementation prevails, parity flows both ways), ADR-0008 / G5 (a property name keeps its idiomatic
casing - no framework rewrites it), ADR-0022 (queue at-least-once), ADR-0024 (one shared fixture +
one checker per pluggable subsystem), ADR-0041 (explicit argument beats the environment), ADR-0042
(the messenger uid is the IMAP UID, never a sequence number), ADR-0043 (the paginate envelope is
seven snake_case keys derived from the query, no arguments). The full list is `DECISIONS.md`.

## Spec-Driven Development (SDD) — the spec is the executable contract

Tina4 is already most of the way to Spec-Driven Development (SDD): the ADR says WHY, the contract
fixture is that decision made EXECUTABLE, and `audit-contract-fixtures.py` proves all four frameworks
obey it. SDD adds the missing front-end - one canonical, human-readable spec per feature that every
framework, and every agent, builds from. It does NOT replace the ADR/fixture backbone; it sits on top
of it.

**Target state: every feature has a spec at `tina4-documentation/plan/v3/specs/NNN-<feature>.md`.**
This is not the current state. Adoption is per feature, and today `specs/` holds only `README.md`
and `TEMPLATE.md`, so most features have no spec yet. A spec carries:

- **Intent** - what the feature does and why, in two or three lines, citing the governing ADR(s).
- **Acceptance criteria** - the behaviour as numbered `AC-N` given/when/then lines. Each `AC-N` is one
  claim a single test can turn red or green.
- **Non-goals** - what the feature deliberately does NOT do (the discipline that stops scope creep).
- **Tasks** - the per-framework work, Python reference first, each linking its `<repo>/plan/<task>.md`.

**The spec and the fixture are one system, mapped 1:1.** Every `AC-N` maps to a case in
`fixtures/<feature>_contract.json`: the AC is the human form, the fixture case is the executable form,
and they can never drift - `tina4-documentation/scripts/audit-spec-coverage.py --strict` (CI, in
`audit-truth.yml`) fails the build when a spec'd feature has an AC with no fixture case or a case with
no AC. A feature with no spec is not gated yet, and the gate passes while `specs/` holds no feature
spec. "Proven/owed" becomes
per-CRITERION, not per-feature: `CONTRACT-MAP.md` can say AC-3 is proven in Ruby and owed in Node.

**The spec is the source of truth; the code is derived from it.** When behaviour must change, change
the SPEC first (add or amend an `AC-N`), supersede the ADR if a decision moves, update the fixture,
then fix the four frameworks to the new criterion - never the reverse. A spec that disagrees with the
code is the bug, exactly like a doc that disagrees with the code (the First Principle). `MASTER-SPEC.md`
retires into per-feature specs; its stale numbers were the drift this closes.

**A NEW feature starts from the spec, not from code.** Write the spec (intent + acceptance criteria +
non-goals) and the ADR before you measure or build anything; for an EXISTING feature you are advancing,
confirm or extend its spec first. This is the spec-first discipline `tina4-architect` applies to a new
project's journeys, carried down to a single framework feature - and `tina4-developer-*` applies the
same acceptance-criteria-before-code rule to app features built ON Tina4.

