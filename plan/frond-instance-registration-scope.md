# Task: Frond instance registration scope

**Outcome:** Frond class registrations remain process-global while instance registrations remain local, per ADR-0052.

## Scope
- [x] Read Feature 56 and ADR-0052
- [x] Add failing scope regressions
- [x] Remove instance-to-class writes
- [ ] Verify focused and full lab suites

## Parity
| Feature | Python | PHP | Ruby | Node.js |
|---|---|---|---|---|
| ADR-0052 scope | in progress | in progress | in progress | in progress |

## Tests
- [x] Class registration reaches later instances
- [x] Instance filter/global/test registrations do not reach later instances

## Bugs
- [ ] EX-INSTANCE-LEAKS-CLASS

## Commits
- pending

## Status: In Progress
