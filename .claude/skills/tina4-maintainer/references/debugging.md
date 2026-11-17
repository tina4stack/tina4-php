# Debugging: evidence before hypothesis

> Part of the **tina4-maintainer** skill. Load it whenever something fails: a red CI check, a crashed
> test, a scanner verdict, a server that will not boot, a result that "cannot" happen. The 🐞 reflex in
> `SKILL.md` points here. The verification floor still governs: no doubles, zero skips, mutation proof.

The expensive debugging failures in this project were not hard bugs. They were theories formed before
anyone read what the tool had already said. Work the steps in order and do not skip ahead to a fix.

## 1. Read the tool's own evidence first

Before any hypothesis, read what the failing thing reported, in full:

- **CI:** the whole job log (`gh api repos/<org>/<repo>/actions/jobs/<id>/logs`), not just
  `gh run view --log-failed`, which truncates. Find the runner's summary line and the FIRST error, not
  the last noise. A step that died before any test ran (a setup or download failure) is not a test
  failure.
- **A scanner** (CodeQL, a dependency checker, a licence check): open its report and read its verdict
  word for word. "Failed to detect issues" is the tool failing, not a finding.
- **A server or child process:** its own log file or captured stdout. "Never became ready" is a
  symptom; the child's log says why ("already bound by another worker", "TINA4_SECRET is not set").
- **Write down the literal message** before you interpret it. If you cannot quote the evidence, you
  are not ready to explain it.

## 2. Reproduce it for real, where it failed

- Reproduce on the platform that failed. Linux CI failures are reproduced on Linux (WSL or a Docker
  image of the same runtime), not reasoned about from Windows.
- Use the real dependency in the real failure state: a closed port for "unreachable", a real broker
  producing real tombstones, a real SIGTERM. Never a double (see the verification floor).
- If you cannot reproduce it, say exactly that, and say what you tried. Do not present a theory as a
  reproduction.

## 3. Locate it: the change, or the base?

- Run the same failing test against the base (`v3`) and against the change. Check recent base runs
  for the same test.
- A failure that also happens on the base is pre-existing: it still gets fixed (the 🔧 reflex), but it
  does not block a correct change, and the PR author is told so.
- When a change and a failure coincide, prove the causal link (the code path the test exercises, a
  minimal revert) before blaming the change.

## 4. One falsifiable hypothesis at a time

- State the hypothesis and the check that would DISprove it. Run the check. Record what you ruled out.
- Never stack a second guess on an unconfirmed first one. Two unverified theories in a row is the
  signal to go back to step 1.

## 5. Flake or regression: prove it

- Isolate the test, repeat it, vary the seed. Deterministic in isolation means it is a regression,
  whatever it looks like.
- A genuine flake has a cause (a shared port, a timing race, leaked state), and the cause is a bug.
  Re-running a job only unblocks a merge. The flake gets its own fix and its own test, in every
  framework that shares the shape.

## 6. Fix the cause, prove it, sweep

- Write the regression test first and watch it fail on the current code; then fix; then watch it pass.
  Prove the new test is a gate by mutation.
- Sweep the other three frameworks for the same shape (the parity mandate). A shape found in one
  framework is usually in more.

## 7. Report what you know, and correct what you said

- Separate what you verified from what you inferred, and qualify by platform.
- If an earlier message, comment or PR body stated a wrong cause, correct it where it was said.

## Cases this procedure would have shortened (2026-11)

| What happened | The evidence that settled it |
|---|---|
| Snyk "1 test has failed" on every `pyproject.toml` change was treated as a vulnerability, then as a licence finding | Its report: `ERROR - Failed to detect issues`. The integration cannot read a PEP 621 manifest with a uv lock. The real scan is the `uv.lock` pip-audit job. |
| The Docker publish gate refused all four images | Container logs: PHP/Ruby/Node exited on `TINA4_SECRET is not set` (ADR-0079); Python's `/health` hid its version (ADR-0078). The script had drifted from the CI gate. |
| Raw-TCP contract tests failed "child server never became ready" on unrelated PRs | Server log: `port N is already bound by another worker`. The tests used HTTP port + 1 without checking it was free, in all four frameworks. |
| php#362 stayed red on a shutdown test it never touches | Linux reproduction: `stream_select()` TypeError when SIGTERM closed the sockets mid-select, exit 255 - a race in `Server.php` on the base branch. |
| A Node Kafka PR timed out a different Kafka test | That test popped an empty topic six times; the PR had raised the fetch long-poll from 1 s to 5 s, and Node's `pop()` blocks the event loop. |
