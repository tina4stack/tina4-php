# Checklist: cross-framework parity sweep (triage + contract)

Tina4 is one framework in four languages, so triage is cross-repo, not per-repo. A bug reported
against one language almost always exists in the other three. Fix it once, at parity, with a
named regression test in all four — never file N separate tickets.

## Sweep (start of a maintenance pass and before any release)

- [ ] List OPEN issues across ALL tina4stack repos, not just the one in front of you:
      `gh search issues org:tina4stack state:open --limit 100` (or `gh issue list -R
      tina4stack/<repo> --state open` per repo). Repos: tina4-python, tina4-php, tina4-ruby,
      tina4-nodejs, tina4-js, tina4 (CLI), tina4-documentation, tina4-book.
- [ ] **Group by SUBSYSTEM** (Frond / ORM / queue / router / auth), not by repo. Same number in
      two repos is usually one bug; a report with no twin may be genuinely one-language.

## Reproduce before deciding

- [ ] Reproduce the report against ALL FOUR frameworks before calling it framework-specific.
- [ ] FIRST confirm it is really a defect — against the contract fixtures and the Python master.
      A "bug" that contradicts the master is often correct behaviour you would REGRESS; a stale
      TEST asserting the old contract is the bug, not the code.

## Fix once, at parity (the contract loop)

- [ ] Open `tina4-documentation/plan/v3/CONTRACT-MAP.md`; find the governing ADR and read it.
      Changing a cross-framework contract means superseding an ADR deliberately, never silently.
- [ ] Write/adjust the executable `fixtures/<feature>_contract.json` (the SAME bytes drive all
      four runners) with named positive AND negative regressions.
- [ ] Fix the divergence in ALL FOUR, Python master leading, the others mirroring.
- [ ] Flip owed→proven via `scripts/audit-contract-fixtures.py`; sync the `CONTRACT-MAP.md` row
      from the auditor's OWN counts, never a hand count.
- [ ] Verify yourself at HEAD on the lab (no mocks, zero skips). Log the fix in the plan's Bugs
      section, ticked only when a real test proves it.
- [ ] Plan edits into tina4-documentation are a MERGE, never a rebase (subtree graft + PDF bot).

**Fix on discovery:** a defect you hit during ANY pass gets fixed in the same session at parity —
never parked as "out of scope" or "follow-up". The only legitimate defers are a genuine open
question for the maintainer, or a live worker-collision on the same file (sequence right after it).

See [[pr-review]] for landing the fix and [[release]] for shipping it.
