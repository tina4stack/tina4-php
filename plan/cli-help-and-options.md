# Task: CLI `--help` and option checking, all four frameworks (PHP)

## Outcome
`--help` / `-h` on any framework CLI command prints that command's usage and
writes nothing. Unknown options are refused with a did-you-mean, value options
need a value, and `ai --all` works. Same behaviour and same wording in Python,
PHP, Ruby and Node, each with equivalent no-mock tests.

Reference: tina4-python `fix/cli-help-flags` (9032149b), `plan/cli-help-and-options.md`.

## Context
Found through `tina4 generate model --help`, which created a model called
`--help`. The Rust `tina4` client forwards these commands verbatim, so the fix
belongs in the framework CLIs. What `bin/tina4php` did on v3 (measured by the
new test, unfixed):

| Command with `--help` | What it did |
|---|---|
| `generate model/route/crud/migration/...` | files named `--help` / `-h` |
| `generate auth` | ran fully (User model, migration, routes, templates) |
| `init` | scaffolded a project in the cwd |
| `lint` | installed phpcs (`composer require --dev`), rewrote composer.json |
| `serve`, `start` | started the server |
| `ai` | opened the interactive menu |
| `migrate`, `migrate:rollback`, `migrate:status`, `test`, `queue`, `build` | exit 1, no help |
| `console` | PHP fatal (no vendor/autoload.php in the project) |
| `seed`, `routes`, `commands` | ran, exit 0, no help |

Other broken switches found by the same tests:
- Misspelled or short options (`--feilds`, `--publc`, `-f`) were silently
  dropped: the model came out without its fields, exit 0.
- `--fields` / `--every` with no value were silently ignored (default fields,
  default schedule). In PHP they did not crash (Python's `--fields` did).
- `ai --all` was documented but the code checked for the bare word `all`.

## Design (Python is the reference)
- Each registry entry declares `'flags' => [name => takesValue]`. Absent = no
  options; `help` is `null` (not checked: `help --help` / `help -h` print the
  full reference, as Python's `"flags": None`). Queue subcommands have `$TINA4_QUEUE_FLAGS` (the subcommand list is
  now derived from it), every generator also accepts `--json` / `--dry-run`
  (`TINA4_RESOLUTION_FLAGS`; all fifteen are resolution-aware in PHP, listed once
  in `TINA4_RESOLVABLE_GENERATORS`, which also replaces the two inline copies).
- Before any handler runs:
  1. `--help` / `-h` anywhere after a command -> `tina4CommandHelp()`, exit 0.
  2. `tina4OptionError()`: unknown option ->
     `Unknown option --feilds for "generate model". Did you mean --fields?` then
     `Run "tina4php generate model --help" for its options.`, exit 1.
     Value option with no value ->
     `Option --fields for "generate model" needs a value: --fields <value>`, exit 1.
  3. Did-you-mean: `similar_text()` percentage >= 60 (same 2M/T ratio as
     Python's difflib cutoff 0.6).
- `--name=value` is accepted as well as `--name value` (parseFlags already did).
- `generate` refuses a name starting with `-` (after the unknown-generator check).
- Delegated commands (`doctor`, `setup`, `deploy`) are untouched.

### Options per command (inventoried from what the PHP code reads)
| Command | Options (v = takes a value) |
|---|---|
| serve, start | port v, host v, no-browser, no-reload, no-kill, production, managed |
| migrate:create | fields v, json, dry-run |
| lint | fix, no-install |
| build | tag v, file v |
| ai | all |
| commands | json |
| queue work / stats / retry / clear | once, poll v, services v / json / - / - |
| init, migrate, migrate:rollback, migrate:status, seed, routes, test, console | none |
| help | not checked (null) |

| Generator | Options (all also take json, dry-run) |
|---|---|
| model | fields v, table-name v, no-migration |
| route | model v, public |
| crud | fields v, table-name v, public, no-templates, no-migration, skip-model |
| migration | fields v |
| test | model v, public |
| form, view | fields v, table-name v |
| service | every v, cron v |
| seeder | table-name v |
| middleware, auth, queue, validator, websocket, listener | none |

### Differences from Python (and why)
- `test` is checked with no options. Python's `test` hands its arguments to
  pytest (`None`); PHP's never passed them to PHPUnit, so `test --filter x`
  silently ran the whole suite. It is now refused. Forwarding to PHPUnit would be
  the parity move; left as a follow-up.
- `serve` / `start`: positional `host:port` (PHP) plus the options it reads;
  `managed` is accepted because the `tina4` client runs `tina4php serve --managed`.
- `init` takes no options (Python: `--ai`, `--production`); PHP's init reads none.
- `migrate:create` and `generate migration`: no `--table-name` (PHP's migration
  generator never reads it). `generate auth`: no options (PHP's auth ignores
  `--fields` / `--table-name` / `--no-migration`).
- `generate crud` usage now lists `--no-templates` (it was accepted but undocumented).
- No `env-migrate` command in PHP.

## Parity
| Surface | Python | PHP | Ruby | Node |
|---------|--------|-----|------|------|
| `--help` / `-h` per command, no side effects | ✅ | ✅ | ❌ BUILD | ❌ BUILD |
| unknown option refused + did-you-mean | ✅ | ✅ | ❌ BUILD | ❌ BUILD |
| value option needs a value | ✅ | ✅ | ❌ BUILD | ❌ BUILD |
| `--name=value` | ✅ | ✅ | ❌ BUILD | ❌ BUILD |
| `ai --all` | ✅ | ✅ | ❌ BUILD | ❌ BUILD |
| generate name can't start with `-` | ✅ | ✅ | ❌ BUILD | ❌ BUILD |

## Tests (real, no mocks, positive + negative) - tests/CliHelpFlagsTest.php
Every case runs the real `bin/tina4php` as a subprocess in a temp project
(index.php + composer.json), HOME pointed at a temp dir, stdin closed, 30s kill.
Command and generator lists come from the real `commands --json` manifest.
- [x] every command and generator, `--help` and `-h`: exit 0, usage printed,
      project tree hashed before/after and unchanged (64 cases)
- [x] `--help` after the name and options still wins
- [x] top-level `--help` / `-h`; `generate --help` lists every generator
- [x] `help`, `help --help`, `help -h`: exit 0, full reference, no "Unknown option"
- [x] a name that looks like an option is refused, nothing written
- [x] misspelled options refused with the right suggestion, nothing written
- [x] short and foreign options refused (incl. `--x=y`, `test --filter`)
- [x] value option without a value refused, no PHP fatal / stack trace
- [x] `--fields price:float` and `--fields=price:float` both reach the model
- [x] `ai --all` and `ai all` write CLAUDE.md + AGENTS.md without the menu
- [x] `generate model Product --fields price:float` then `generate crud Product
      --skip-model`: exit 0, no "already exists", model byte-identical, route written
- [x] drift guard: every `--option` in a usage line is accepted (black-box probe
      `<cmd> --opt=x --zz-not-an-option` names the bogus one), and every queue
      subcommand has an option map
- [x] unfixed v3: 84 of 89 fail (all 64 help cases; the 3 `help`-command cases
      were added later and already pass on v3); fixed: 93/93, 674 assertions (the --skip-model case fails on the
      pre-skip-model commit with "Unknown option --skip-model")
- [x] CLI-related suites (AITest, CLIScaffolding*, Cli*, CommandsManifest,
      Generate*): all green, 1 skip (CliBuildTest, docker)
- [x] full suite, macOS PHP 8.5.11, `-d memory_limit=-1` (GraphQLFanoutLimitsTest
      exhausts the default 128M and kills the run): 6847 tests, 11 failures +
      1 error, 748 skipped. All 12 also fail on clean v3 (a844eb70), none CLI:
      DatabaseDriversTest (1), QueueBackendValidationTest (1 error),
      SessionDatabaseEnginesTest (3), DocStoreSubstitutabilityTest (1),
      DriverMissingMessageTest (1), MetricsHandoffTest (3), QueueBackendTest (2)
- [ ] full suite on CI / the lab at HEAD

## Bugs found and fixed here
- The `generate model` Next steps suggest `generate crud <Name> --skip-model`,
  an option no generator had (same in Python). It used to be ignored and would
  now be refused, so crud implements it (as Python now does): `--skip-model`
  skips step 1 (model + migration) and keeps the existing model untouched.

## Commits
- fix(cli): --help prints usage; unknown options are refused

## Status: PHP done; Ruby, Node in progress
