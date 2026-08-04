<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * SESSION CONTRACT invariant 5: the database backend works on every engine it
 * claims. PHP's half of the parity lock-in behind ADR-0028.
 *
 * THE INVARIANT. A backend that advertises support for an engine works on that
 * engine. The database session backend works on every engine the Database layer
 * supports, not a subset.
 *
 * WHY IT IS A SEPARATE FILE FROM SessionDatabaseEngineTest. That file is about
 * TTL: expires_at surviving a real round trip, an unstamped row being kept, an
 * expired row being reaped. It is driven by a #[DataProvider], so it produces
 * ONE PHPUnit case PER ENGINE - which structurally cannot assert "fewer than
 * three engines actually ran", because no single case can see the others. This
 * invariant needs exactly that assertion, so it needs a single case that walks
 * the engines itself. Different contract, different shape, different file.
 *
 * WHAT WENT WRONG ELSEWHERE, and what is therefore pinned here. Node's database
 * session backend was SQLite-only: resolveDbPath() THREW on any non-sqlite
 * TINA4_DATABASE_URL, and two assertions pinned that throw as the contract. The
 * operator-visible result was ADR-0024's founding scenario landing in the one
 * subsystem that decides whether anyone is logged in - develop on sqlite, deploy
 * on postgres, and the app does not start. ADR-0028 removed that limitation in
 * Node. PHP has never had it: DatabaseSessionHandler takes an injected adapter
 * or resolves TINA4_DATABASE_URL through Database::fromEnv(), so it follows
 * whatever the Database layer connects to. This file is the lock-in that keeps
 * it that way, in the same shape as Node's test/sessionDatabaseEngines.test.ts.
 *
 * NO MOCKS. Real PostgreSQL 16, real MySQL 8, real SQLite files, and - where the
 * driver exists - real SQL Server. Every round trip is verified OUT OF BAND
 * through a raw PDO connection this test owns, never through the handler that
 * wrote it. A handler that answered from its own cache would still be caught,
 * because the row itself is inspected on a second, independent connection that
 * is frequently a DIFFERENT client library (the handler reaches PostgreSQL
 * through ext-pgsql and MySQL through mysqli; the verifier uses PDO).
 *
 * A MISSING ENGINE IS NEVER A PASS. With TINA4_REQUIRE_SERVICES set an
 * unreachable engine FAILS immediately naming host and port. Without it the
 * engine is recorded as broken and the run still fails on the three-engine
 * floor, because "the database session backend works" proved on SQLite alone is
 * exactly the claim this invariant exists to refuse.
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\MySQLAdapter;
use Tina4\Session\DatabaseSessionHandler;

class SessionDatabaseEnginesTest extends TestCase
{
    /**
     * Engines that must ALL round-trip for this invariant to mean anything.
     *
     * Unreachable counts as BROKEN here, deliberately. That refusal to treat a
     * missing engine as an excuse is what exposed two real defects that
     * SQLite-only testing had hidden for the whole life of this backend, so it
     * is the property to protect, not soften.
     *
     * @var string[]
     */
    private const REQUIRED_ENGINES = ['sqlite', 'postgres', 'mysql'];

    /**
     * Engines verified OPPORTUNISTICALLY: exercised whenever this build can
     * reach them, and a FAILURE is a real defect that fails this test - but a
     * build with no driver for them is not a failure.
     *
     * The distinction is the whole point, and it is not the same as the
     * required tier: an engine whose DRIVER IS ABSENT cannot be tested at all,
     * while an engine that is PRESENT AND BROKEN is exactly what this invariant
     * exists to catch. Collapsing the two would either make CI red on every
     * machine without SQL Server, or let a genuine mssql regression pass
     * unnoticed on the one machine that has it. Neither is acceptable, so the
     * roster below reports which of the two actually happened, every run.
     *
     * mssql was a MEASURED_OPEN_DEFECTS entry until 2026-08-04.
     *
     * IT STAYS HERE RATHER THAN GRADUATING TO REQUIRED, and the reason is worth
     * being explicit about because the evidence looks like a case for promotion.
     * Measured on the lab the same day, against live SQL Server 2022: mssql
     * round-trips through this suite, its per-engine DDL is confirmed in the
     * catalog, and it survived the concurrent first-use race with SIX REAL
     * PROCESSES. On correctness it has earned REQUIRED.
     *
     * But this tier is not about whether an engine WORKS - it is about whether
     * every machine that runs this suite can REACH it, because REQUIRED treats
     * unreachable as broken. SQL Server is on the lab and is not on a typical
     * dev machine, so promoting it would turn a green suite red everywhere
     * except one box, for a reason that says nothing about the framework. The
     * day SQL Server is provisioned wherever this suite runs, promote it - the
     * correctness evidence is already in.
     *
     * @var string[]
     */
    private const OPPORTUNISTIC_ENGINES = ['mssql'];

    /**
     * Engines whose failure is a MEASURED, OPEN framework defect - not a
     * regression this run introduced, and NOT a permission to fail.
     *
     * EMPTY as of 2026-08-04. The one entry, mssql, is FIXED:
     * DatabaseSessionHandler::ensureTable() no longer emits the non-T-SQL
     * "CREATE TABLE IF NOT EXISTS", and re-checks tableExists() after a failed
     * CREATE (with a rollback first, because a failed statement leaves
     * PostgreSQL's transaction aborted) instead of parsing an error message
     * every engine spells differently.
     *
     * The mechanism is kept, empty, on purpose. It is the honest way to record
     * "we know this is broken, here is its exact signature, and this test will
     * tell you the day it changes shape OR gets fixed" - and it worked: the
     * tripwire is what handed the fix back for this update.
     *
     * @var array<string, string> engine name => substring its failure must carry
     */
    private const MEASURED_OPEN_DEFECTS = [];

    /**
     * The types tina4_session must ACTUALLY be built with on each engine, as
     * that engine's own catalog spells them.
     *
     * The columns are a cross-framework contract (session_id, data, expires_at),
     * so only the type spellings differ - and they have to differ, because one
     * spelling is not legal everywhere. SQL Server is the row to watch: NVARCHAR
     * rather than VARCHAR because a session id is Unicode, NVARCHAR(MAX) rather
     * than the deprecated TEXT, and FLOAT rather than DOUBLE PRECISION.
     *
     * SQLite is listed with its Node/Python spelling. That is NOT a schema
     * change for an existing deployment: SQLite types are affinities, so the
     * VARCHAR(255)/TEXT/DOUBLE PRECISION an older tina4-php wrote has exactly
     * the same TEXT/TEXT/REAL affinities, and IF NOT EXISTS never rewrites a
     * table that is already there.
     *
     * @var array<string, array<string, string>>
     */
    private const NATIVE_COLUMN_TYPES = [
        'sqlite' => ['session_id' => 'text', 'data' => 'text', 'expires_at' => 'real'],
        'postgres' => ['session_id' => 'character varying', 'data' => 'text', 'expires_at' => 'double precision'],
        'mysql' => ['session_id' => 'varchar', 'data' => 'text', 'expires_at' => 'double'],
        'mssql' => ['session_id' => 'nvarchar', 'data' => 'nvarchar(max)', 'expires_at' => 'float'],
    ];

    /** @var array<string, string|false> getenv() values captured before this test changed them. */
    private array $savedGetenv = [];

    /** @var array<string, string|null> $_ENV values captured before this test changed them (null = absent). */
    private array $savedSuperglobal = [];

    /** @var string[] SQLite files this test created. */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->savedGetenv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv("{$name}={$value}");
            }

            $previous = $this->savedSuperglobal[$name] ?? null;
            if ($previous === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $previous;
            }
        }
        $this->savedGetenv = [];
        $this->savedSuperglobal = [];
        \Tina4\DotEnv::resetEnv();

        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
        $this->temporaryFiles = [];
    }

    /**
     * A REAL round trip on every engine the Database layer supports, with the
     * row confirmed OUT OF BAND on a connection the handler knows nothing about.
     *
     * The write goes through a handler that resolved TINA4_DATABASE_URL itself
     * (no injected adapter), because that is the deployment the invariant is
     * about: one .env, swapped engine, everything still works. The read goes
     * through a SECOND, FRESH handler so nothing in the writer's process state
     * can answer instead of the engine.
     */
    public function testTheDatabaseSessionBackendWorksOnEveryEngineItClaims(): void
    {
        /** @var string[] $ran engines that round-tripped end to end */
        $ran = [];
        /** @var string[] $broken engines that failed and are NOT a known open defect */
        $broken = [];
        /** @var array<string, string|null> $openDefects known-defect engines => failure reason, null when it worked */
        $openDefects = [];
        /** @var string[] $notExercised engines this build could not reach at all */
        $notExercised = [];

        $unreachableOpportunistic = $this->opportunisticEnginesWithoutDrivers();

        foreach ($this->engineSpecifications() as $engine) {
            $name = $engine['name'];

            // An opportunistic engine this build cannot reach is REPORTED, never
            // run and never counted broken. Running it would fail for "not
            // reachable", which says nothing about whether the backend supports
            // it - the failure this invariant is about.
            if (array_key_exists($name, $unreachableOpportunistic)) {
                $notExercised[] = $name . ' (' . $unreachableOpportunistic[$name] . ')';
                continue;
            }

            $reason = $this->roundTripThrough($engine);

            if (array_key_exists($name, self::MEASURED_OPEN_DEFECTS)) {
                $openDefects[$name] = $reason;
                continue;
            }

            if ($reason !== null) {
                // Reached here, an opportunistic engine is PRESENT and FAILING,
                // which is a real defect and is treated exactly like a required
                // one. Only unreachability buys an exemption, never brokenness.
                $broken[] = $reason;
                continue;
            }

            $ran[] = $name;
        }

        // The roster is the evidence. A green run that does not say WHICH
        // engines it exercised cannot be told apart from a green run that
        // exercised one.
        $this->reportRoster($ran, $openDefects, $notExercised);

        $this->assertSame(
            [],
            $broken,
            'these engines did NOT work through the database session backend: ' . implode('; ', $broken)
            . ' - a backend that advertises support for an engine has to work on that engine'
        );

        // Every REQUIRED engine must appear in $ran. Counting alone would let
        // an opportunistic engine silently substitute for a required one that
        // never ran, which is the same "one engine passing" hole in disguise.
        $missingRequired = array_values(array_diff(self::REQUIRED_ENGINES, $ran));
        $this->assertSame(
            [],
            $missingRequired,
            'these REQUIRED engines did not round-trip: ' . implode(', ', $missingRequired)
            . ' (ran: ' . ($ran !== [] ? implode(', ', $ran) : 'none') . ') - one engine passing is not'
            . ' the invariant; SQLite, PostgreSQL and MySQL are all required, and unreachable counts as broken'
        );

        // The known-defect tripwire. It fails BOTH ways: if the defect changed
        // shape, and if it is gone.
        foreach (self::MEASURED_OPEN_DEFECTS as $engineName => $signature) {
            if (!array_key_exists($engineName, $openDefects)) {
                continue;   // no driver on this build; the roster line says so
            }

            $observed = $openDefects[$engineName];

            $this->assertNotNull(
                $observed,
                $engineName . ' now WORKS through the database session backend. That is good news and this'
                . ' test is the last thing standing in the way: promote ' . $engineName . ' into the required'
                . ' roster: add it to OPPORTUNISTIC_ENGINES (or REQUIRED_ENGINES if every build can'
                . ' reach it) and delete its MEASURED_OPEN_DEFECTS entry. That is exactly what happened'
                . ' to mssql on 2026-08-04 - this tripwire is what handed the fix back'
            );

            $this->assertStringContainsString(
                $signature,
                (string)$observed,
                $engineName . ' still fails, but NOT for the reason this file measured. The recorded defect is'
                . ' the redundant IF NOT EXISTS in ensureTable(); a different failure is a different bug and'
                . ' needs its own measurement. Got: ' . (string)$observed
            );
        }
    }

    /**
     * What it cannot do, it refuses loudly and BY NAME.
     *
     * The refusal comes from the Database layer, not from the session handler:
     * db() calls Database::fromEnv(), which reaches Database::createAdapter(),
     * which raises \InvalidArgumentException naming the scheme it was given and
     * listing the ones it supports. The session handler adds no scheme knowledge
     * of its own, which is precisely why it inherits every engine the Database
     * layer gains.
     *
     * It refuses on FIRST USE, not at construction (ADR-0021: no I/O and no
     * resolution in a constructor, so the failure lands inside the log-loud-and-
     * degrade policy where it can be logged, degraded, or re-raised under
     * TINA4_SESSION_STRICT). Both halves are pinned below.
     */
    public function testAnUnsupportedEngineRefusesByNameInsteadOfDegrading(): void
    {
        $this->setEnvironment('TINA4_DATABASE_URL', 'notareal://user:pass@127.0.0.1:1234/db');
        $this->setEnvironment('TINA4_DATABASE_USERNAME', '');
        $this->setEnvironment('TINA4_DATABASE_PASSWORD', '');
        \Tina4\DotEnv::resetEnv();

        try {
            $handler = new DatabaseSessionHandler();
        } catch (\Throwable $constructionFailure) {
            $this->fail(
                'the CONSTRUCTOR refused the unsupported engine (' . get_class($constructionFailure) . ': '
                . $constructionFailure->getMessage() . ') - ADR-0021 puts resolution on first use so the'
                . ' failure lands inside the session failure policy; a constructor sits outside it'
            );
        }

        $refusal = null;
        try {
            $handler->read('session-that-will-never-be-looked-up');
        } catch (\Throwable $failure) {
            $refusal = $failure;
        }

        $this->assertNotNull(
            $refusal,
            'the unsupported engine "notareal" did not raise on first use - a session backend that accepts'
            . ' an engine it cannot speak degrades silently, and a degraded session store is indistinguishable'
            . ' from a working one until users start losing their logins'
        );

        $message = $refusal->getMessage();

        $this->assertStringContainsString(
            'notareal',
            $message,
            'the refusal must NAME the scheme it was given, or the operator cannot tell a typo from an'
            . ' unsupported engine. Got ' . get_class($refusal) . ': ' . $message
        );

        $this->assertStringContainsString(
            'postgres',
            $message,
            'the refusal must also list what IS supported, so the next step is obvious from the error alone.'
            . ' Got ' . get_class($refusal) . ': ' . $message
        );

        $this->assertStringNotContainsString(
            'No database connection available',
            $message,
            'a bad SCHEME must not be reported as "nothing is configured" - that sends the operator to check'
            . ' an env var that is in fact set, and hides the real cause. Got ' . $message
        );

        // NEGATIVE CONTROL. The refusal has to be about the scheme, not about
        // the handler refusing everything: the identical first-use path on a
        // SUPPORTED engine must resolve and answer.
        $sqlitePath = $this->temporaryPath('supported-control');
        $this->setEnvironment('TINA4_DATABASE_URL', 'sqlite:' . $sqlitePath);
        \Tina4\DotEnv::resetEnv();

        $supported = new DatabaseSessionHandler();
        $this->assertNull(
            $supported->read('session-that-will-never-be-looked-up'),
            'a SUPPORTED engine must resolve on the same first-use path and simply report no session -'
            . ' otherwise the refusal above proves nothing about the scheme'
        );
    }

    /**
     * Each engine gets ITS OWN column types, confirmed in the engine's own
     * catalog after a REAL first use.
     *
     * WHY THIS IS NOT A STRING TEST OVER THE DDL CONSTANT. Reading the SQL back
     * out of the class and asserting it contains "NVARCHAR" proves the constant
     * says NVARCHAR; it does not prove SQL Server accepted the statement or
     * built the column that way. Every type below is read from the ENGINE, on a
     * connection the handler knows nothing about, after the handler created the
     * table for real.
     *
     * WHAT IT PINS. One generic statement for all five engines is wrong on two
     * of them: SQL Server takes TEXT only as a deprecated type, and Firebird has
     * no TEXT at all and rejects IF NOT EXISTS outright. The mssql row is the
     * one that goes red the moment the generic DDL comes back - varchar instead
     * of nvarchar, text instead of nvarchar(max).
     */
    public function testEveryEngineGetsItsOwnNativeColumnTypes(): void
    {
        /** @var string[] $exercised */
        $exercised = [];
        /** @var string[] $wrong */
        $wrong = [];

        foreach ($this->reachableEngineSpecifications() as $engine) {
            $name = $engine['name'];
            $verifier = null;

            try {
                $verifier = new \PDO($engine['dsn'], $engine['username'], $engine['password'], [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                ]);
                // A tina4_session left behind by an older run would let
                // ensureTable() skip creation, and the types read back would
                // describe that leftover rather than this code.
                $verifier->exec('DROP TABLE IF EXISTS tina4_session');

                $this->pointTheEnvironmentAt($engine);

                // A REAL first use: write() calls ensureTable() on its way in.
                $handler = new DatabaseSessionHandler();
                $handler->write('types-' . $name . '-' . bin2hex(random_bytes(4)), ['engine' => $name], 60);

                $declared = $this->declaredColumnTypes($verifier, $name);
                $expected = self::NATIVE_COLUMN_TYPES[$name] ?? [];

                if ($expected === []) {
                    $wrong[] = $name . ' has no measured native column types in this test';
                    continue;
                }

                // Rebuild in the expected key order: === on arrays is
                // order-sensitive, and a catalog is free to answer in any order.
                $observed = [];
                foreach ($expected as $column => $ignored) {
                    $observed[$column] = $declared[$column] ?? 'COLUMN MISSING';
                }

                if ($observed !== $expected) {
                    $wrong[] = sprintf(
                        '%s built %s, expected %s',
                        $name,
                        json_encode($observed),
                        json_encode($expected)
                    );
                    continue;
                }

                $exercised[] = $name;
            } catch (\Throwable $failure) {
                $wrong[] = sprintf('%s (%s: %s)', $name, get_class($failure), $failure->getMessage());
            } finally {
                if ($verifier !== null) {
                    try {
                        $verifier->exec('DROP TABLE IF EXISTS tina4_session');
                    } catch (\Throwable) {
                        // Cleanup only; the assertions own the verdict.
                    }
                }
                $verifier = null;
            }
        }

        fwrite(STDERR, "\n[session-contract] per-engine session DDL confirmed in the catalog of: "
            . ($exercised !== [] ? implode(', ', $exercised) : 'NONE') . "\n");

        $this->assertSame(
            [],
            $wrong,
            'these engines did not get their own native column types: ' . implode('; ', $wrong)
            . ' - one generic CREATE TABLE for every engine is exactly what this replaced'
        );

        $missingRequired = array_values(array_diff(self::REQUIRED_ENGINES, $exercised));
        $this->assertSame(
            [],
            $missingRequired,
            'these REQUIRED engines were never exercised: ' . implode(', ', $missingRequired)
            . ' - a green run that checked one engine cannot be told apart from one that checked all of them'
        );
    }

    /**
     * Concurrent FIRST USE is safe on every engine: several real processes race
     * to create tina4_session against ONE real database, all of them survive,
     * and the table is intact and holding every row afterwards.
     *
     * WHY REAL PROCESSES AND NOT THE OBVIOUS SHAPE. The obvious shape -
     * pre-create the table, then call ensureTable() on a handler whose
     * tableCreated flag is false - does not reach the code it means to test, for
     * two independent reasons, both measured:
     *
     *   1. ensureTable() checks tableExists() FIRST, so with the table already
     *      there the CREATE is never issued at all.
     *   2. Even if it were issued, every per-engine statement is idempotent on
     *      its own engine (IF NOT EXISTS on SQLite/PostgreSQL/MySQL, IF OBJECT_ID
     *      on SQL Server, the RDB$RELATIONS check on Firebird - the last
     *      confirmed idempotent against a live Firebird 5.0.4). A CREATE against
     *      an existing table therefore does not raise on ANY engine, so the catch
     *      would still never run.
     *
     * The catch exists for the interleave those idempotency checks cannot cover:
     * two workers both pass the check, both CREATE, and the loser errors. That
     * interleave needs genuine concurrency, PHP has no threads, so it needs
     * genuine processes. Each worker is the real handler on a real connection to
     * the real engine - see tests/fixtures/session_concurrent_first_use.php.
     *
     * WHAT IT WOULD CATCH. Removing the catch leaves the losing worker's
     * exception escaping ensureTable() and its process exiting non-zero, which
     * this test reports by name and engine. The failure is not hypothetical: it
     * is Msg 2714 on SQL Server, SQLSTATE 42S01 on Firebird, and a
     * pg_type_typname_nsp_index unique violation on PostgreSQL - three
     * spellings, which is why the guard re-checks instead of matching strings.
     */
    public function testConcurrentFirstUseIsSafeOnEveryEngine(): void
    {
        $workerCount = 6;
        $workerScript = __DIR__ . '/fixtures/session_concurrent_first_use.php';
        $repositoryRoot = dirname(__DIR__);

        $this->assertFileExists($workerScript, 'the concurrent first-use worker is missing');

        /** @var string[] $exercised */
        $exercised = [];
        /** @var string[] $failures */
        $failures = [];

        foreach ($this->reachableEngineSpecifications() as $engine) {
            $name = $engine['name'];
            $verifier = null;

            try {
                $verifier = new \PDO($engine['dsn'], $engine['username'], $engine['password'], [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                ]);
                // The race is about FIRST use, so the table has to be absent.
                $verifier->exec('DROP TABLE IF EXISTS tina4_session');
                $this->settleSqliteJournalMode($verifier, $name);

                $childEnvironment = array_merge(getenv(), [
                    'T4_RACE_URL' => $engine['url'],
                    'T4_RACE_USERNAME' => $engine['username'],
                    'T4_RACE_PASSWORD' => $engine['password'],
                    // A cache in front of the adapter could answer tableExists()
                    // from memory, which would decide the race instead of the
                    // engine.
                    'TINA4_AUTO_CACHING' => 'false',
                    'TINA4_DB_CACHE' => 'false',
                ]);

                // Enough lead for every worker to boot PHP and CONNECT before
                // the barrier releases - a worker still connecting is a worker
                // not in the race.
                $startAt = microtime(true) + 1.5;

                $workers = [];
                for ($worker = 0; $worker < $workerCount; $worker++) {
                    $pipes = [];
                    $process = proc_open(
                        [
                            PHP_BINARY,
                            $workerScript,
                            sprintf('%.6F', $startAt),
                            sprintf('race-%s-%d-%s', $name, $worker, bin2hex(random_bytes(3))),
                        ],
                        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                        $pipes,
                        $repositoryRoot,
                        $childEnvironment
                    );

                    if (!is_resource($process)) {
                        $failures[] = $name . ' could not start concurrent worker ' . $worker;
                        continue;
                    }

                    $workers[] = ['process' => $process, 'pipes' => $pipes, 'index' => $worker];
                }

                // Reap every worker: read its pipes to EOF, close them, and wait
                // on the process. Nothing is left running when this returns.
                foreach ($workers as $started) {
                    $standardOutput = trim((string)stream_get_contents($started['pipes'][1]));
                    $standardError = trim((string)stream_get_contents($started['pipes'][2]));
                    fclose($started['pipes'][1]);
                    fclose($started['pipes'][2]);
                    $exitCode = proc_close($started['process']);

                    if ($exitCode !== 0) {
                        $failures[] = sprintf(
                            '%s worker %d exited %d: %s',
                            $name,
                            $started['index'],
                            $exitCode,
                            $standardError !== '' ? $standardError : ($standardOutput !== '' ? $standardOutput : 'no output')
                        );
                    }
                }

                // The table has to be there AND hold every worker's row: a
                // survivor that swallowed its own write would look identical to
                // a survivor that wrote, without this.
                $rowCount = (int)$verifier->query('SELECT COUNT(*) FROM tina4_session')->fetchColumn();
                if ($rowCount !== $workerCount) {
                    $failures[] = sprintf(
                        '%s ended the race with %d of %d rows in tina4_session',
                        $name,
                        $rowCount,
                        $workerCount
                    );
                    continue;
                }

                $exercised[] = $name;
            } catch (\Throwable $failure) {
                $failures[] = sprintf('%s (%s: %s)', $name, get_class($failure), $failure->getMessage());
            } finally {
                if ($verifier !== null) {
                    try {
                        $verifier->exec('DROP TABLE IF EXISTS tina4_session');
                    } catch (\Throwable) {
                        // Cleanup only; the assertions own the verdict.
                    }
                }
                $verifier = null;
            }
        }

        fwrite(STDERR, '[session-contract] concurrent first use (' . $workerCount
            . ' real processes per engine) survived on: '
            . ($exercised !== [] ? implode(', ', $exercised) : 'NONE') . "\n");

        $this->assertSame(
            [],
            $failures,
            'concurrent first use is NOT safe on these engines: ' . implode('; ', $failures)
            . ' - two workers racing to create tina4_session is what happens every time an app starts'
            . ' more than one process, and the loser must not take a request down'
        );

        $missingRequired = array_values(array_diff(self::REQUIRED_ENGINES, $exercised));
        $this->assertSame(
            [],
            $missingRequired,
            'the race was never run on these REQUIRED engines: ' . implode(', ', $missingRequired)
        );
    }

    /**
     * The engines this build can actually exercise, under the same rules the
     * roster test applies: an opportunistic engine with no driver is passed
     * over, and an unreachable one FAILS under TINA4_REQUIRE_SERVICES rather
     * than quietly shrinking the roster.
     *
     * @return array<int, array{name:string,url:string,username:string,password:string,host:?string,port:int,dsn:string}>
     */
    private function reachableEngineSpecifications(): array
    {
        $withoutDrivers = $this->opportunisticEnginesWithoutDrivers();
        $reachable = [];

        foreach ($this->engineSpecifications() as $engine) {
            if (array_key_exists($engine['name'], $withoutDrivers)) {
                continue;
            }

            if ($engine['host'] !== null) {
                $unreachable = $this->unreachableReason($engine['name'], $engine['host'], $engine['port']);
                if ($unreachable !== null) {
                    if (getenv('TINA4_REQUIRE_SERVICES')) {
                        $this->fail('TINA4_REQUIRE_SERVICES is set but ' . $unreachable);
                    }
                    continue;
                }
            }

            $reachable[] = $engine;
        }

        return $reachable;
    }

    /**
     * Convert a SQLite file to WAL up front, on the out-of-band connection.
     *
     * NOT a convenience. Every Tina4 SQLite adapter runs "PRAGMA
     * journal_mode=WAL" when it opens, and converting a database that is still
     * in rollback-journal mode needs EXCLUSIVE access - which the out-of-band
     * verifier, held open for the whole engine, denies. SQLite answers
     * SQLITE_BUSY for that conversion WITHOUT consulting the busy handler, so
     * the adapter's busyTimeout(5000) does not cover it and the worker dies at
     * CONNECT with "SQLite3: Failed to open database: database is locked".
     *
     * MEASURED: two of six workers died exactly that way, before the barrier,
     * before any session code ran. A failure at connect says nothing about the
     * session DDL, so the conversion is done ONCE here and every later opener
     * finds the pragma already satisfied.
     *
     * (The underlying adapter behaviour - a cold pool of processes opening a
     * fresh SQLite database concurrently can fail at open - is a real and
     * separate framework question. It is deliberately NOT what this file
     * measures.)
     */
    private function settleSqliteJournalMode(\PDO $verifier, string $engine): void
    {
        if ($engine !== 'sqlite') {
            return;
        }

        $verifier->exec('PRAGMA journal_mode=WAL');
    }

    /**
     * The column types tina4_session was ACTUALLY built with, read from the
     * engine's own catalog on the out-of-band connection.
     *
     * @return array<string, string> lower-cased column name => lower-cased type
     */
    private function declaredColumnTypes(\PDO $verifier, string $engine): array
    {
        $types = [];

        if ($engine === 'sqlite') {
            foreach ($verifier->query('PRAGMA table_info(tina4_session)')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $types[strtolower((string)($row['name'] ?? ''))] = strtolower((string)($row['type'] ?? ''));
            }
            return $types;
        }

        $sql = match ($engine) {
            'postgres' => 'SELECT column_name, data_type, character_maximum_length'
                . ' FROM information_schema.columns'
                . " WHERE table_name = 'tina4_session' AND table_schema = current_schema()",
            'mysql' => 'SELECT COLUMN_NAME AS column_name, DATA_TYPE AS data_type,'
                . ' CHARACTER_MAXIMUM_LENGTH AS character_maximum_length'
                . ' FROM information_schema.COLUMNS'
                . " WHERE TABLE_NAME = 'tina4_session' AND TABLE_SCHEMA = DATABASE()",
            'mssql' => 'SELECT COLUMN_NAME AS column_name, DATA_TYPE AS data_type,'
                . ' CHARACTER_MAXIMUM_LENGTH AS character_maximum_length'
                . " FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'tina4_session'",
            default => '',
        };

        if ($sql === '') {
            return $types;
        }

        foreach ($verifier->query($sql)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $type = strtolower((string)($row['data_type'] ?? ''));
            // NVARCHAR(MAX) reports as nvarchar with length -1, and that
            // distinction is the whole point on SQL Server: a plain NVARCHAR
            // would cap the session payload, and NVARCHAR(MAX) is what replaced
            // the deprecated TEXT.
            if ((int)($row['character_maximum_length'] ?? 0) === -1) {
                $type .= '(max)';
            }
            $types[strtolower((string)($row['column_name'] ?? ''))] = $type;
        }

        return $types;
    }

    /**
     * Write a session on one engine and read it back through a FRESH handler,
     * confirming the row OUT OF BAND.
     *
     * @param array{name:string,url:string,username:string,password:string,host:?string,port:int,dsn:string} $engine
     * @return string|null Null when the engine worked end to end, otherwise why it did not.
     */
    private function roundTripThrough(array $engine): ?string
    {
        $name = $engine['name'];

        if ($engine['host'] !== null) {
            $unreachable = $this->unreachableReason($name, $engine['host'], $engine['port']);
            if ($unreachable !== null) {
                if (getenv('TINA4_REQUIRE_SERVICES')) {
                    $this->fail('TINA4_REQUIRE_SERVICES is set but ' . $unreachable);
                }
                return $unreachable;
            }
        }

        $sessionId = 'engine-' . $name . '-' . bin2hex(random_bytes(4));
        $payload = ['seeded' => true, 'engine' => $name];
        $verifier = null;
        $rowQuery = null;

        try {
            $verifier = new \PDO($engine['dsn'], $engine['username'], $engine['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            // Start from a known schema. A tina4_session left behind by an older
            // run would let ensureTable() skip creation entirely, so the result
            // would describe that leftover rather than this code.
            $verifier->exec('DROP TABLE IF EXISTS tina4_session');

            $this->pointTheEnvironmentAt($engine);

            $writer = new DatabaseSessionHandler();
            $writer->write($sessionId, $payload, 60);

            // A FRESH handler: separate object, separate connection, nothing
            // carried over from the write.
            $reader = new DatabaseSessionHandler();
            $readBack = $reader->read($sessionId);
            if ($readBack !== $payload) {
                return sprintf(
                    '%s (a fresh handler read %s, expected %s)',
                    $name,
                    json_encode($readBack),
                    json_encode($payload)
                );
            }

            // OUT OF BAND - raw PDO, an independent connection, not the code
            // under test. This is what separates "the handler agreed with
            // itself" from "the row is really in THIS engine".
            $rowQuery = $verifier->prepare('SELECT data FROM tina4_session WHERE session_id = ?');
            $rowQuery->execute([$sessionId]);
            $storedData = $rowQuery->fetchColumn();

            if ($storedData === false) {
                return $name . ' (the handler round-tripped but NO ROW is in this engine tina4_session'
                    . ' - a session store that answers from anywhere other than the configured engine is'
                    . ' the outage that looks exactly like success)';
            }

            if (json_decode((string)$storedData, true) !== $payload) {
                return sprintf('%s (the row in the engine holds %s)', $name, (string)$storedData);
            }

            // NEGATIVE HALF of the same round trip: destroy() must actually
            // remove the row from THIS engine, not just from the handler's view
            // of it. It is also the cleanup.
            $reader->destroy($sessionId);
            $rowQuery->execute([$sessionId]);
            if ($rowQuery->fetchColumn() !== false) {
                return $name . ' (destroy() left the row behind in the engine)';
            }

            return null;
        } catch (\Throwable $failure) {
            return sprintf('%s (%s: %s)', $name, get_class($failure), $failure->getMessage());
        } finally {
            if ($verifier !== null) {
                try {
                    $verifier->exec('DROP TABLE IF EXISTS tina4_session');
                } catch (\Throwable) {
                    // Cleanup only; the caller's assertions own the verdict.
                }
            }
            // Drop every reference so the verifier connection closes here rather
            // than at the end of the process. A prepared statement holds its PDO
            // alive, so nulling the handle alone is not enough.
            $rowQuery = null;
            $verifier = null;
        }
    }

    /**
     * Every engine this invariant covers, in the order they are exercised.
     *
     * 'host' is null for SQLite (there is nothing to probe). 'dsn' is the raw
     * PDO connection string for the OUT OF BAND verifier and MUST name the same
     * database as 'url', or the test writes in one place and looks in another.
     *
     * @return array<int, array{name:string,url:string,username:string,password:string,host:?string,port:int,dsn:string}>
     */
    private function engineSpecifications(): array
    {
        $sqlitePath = $this->temporaryPath('engines');

        $postgresHost = getenv('TINA4_TEST_PG_HOST') ?: '127.0.0.1';
        $postgresPort = (int)(getenv('TINA4_TEST_PG_PORT') ?: 55432);
        $postgresDatabase = getenv('TINA4_TEST_PG_DB') ?: 'tina4_php';

        // libmysqlclient reads the host "localhost" as a request for the UNIX
        // SOCKET and ignores the port, so a TCP-published server (every Docker
        // MySQL, and every CI runner) is missed while fsockopen - which has no
        // such rule - reports it reachable. MySQLAdapter::rewriteHostForTcp is
        // the framework's own fix for that footgun; reuse it so the probe, the
        // verifier and the code under test cannot disagree about which server
        // they are talking to.
        $mysqlPort = (int)(getenv('TINA4_TEST_MYSQL_PORT') ?: 3306);
        $mysqlHost = MySQLAdapter::rewriteHostForTcp(getenv('TINA4_TEST_MYSQL_HOST') ?: '127.0.0.1', $mysqlPort);
        $mysqlDatabase = getenv('TINA4_TEST_MYSQL_DB') ?: 'tina4';

        $engines = [
            [
                'name' => 'sqlite',
                'url' => 'sqlite:' . $sqlitePath,
                'username' => '',
                'password' => '',
                'host' => null,
                'port' => 0,
                'dsn' => 'sqlite:' . $sqlitePath,
            ],
            [
                'name' => 'postgres',
                'url' => sprintf('postgres://%s:%d/%s', $postgresHost, $postgresPort, $postgresDatabase),
                'username' => getenv('TINA4_TEST_PG_USERNAME') ?: 'tina4',
                'password' => getenv('TINA4_TEST_PG_PASSWORD') ?: 'tina4',
                'host' => $postgresHost,
                'port' => $postgresPort,
                'dsn' => sprintf('pgsql:host=%s;port=%d;dbname=%s', $postgresHost, $postgresPort, $postgresDatabase),
            ],
            [
                'name' => 'mysql',
                'url' => sprintf('mysql://%s:%d/%s', $mysqlHost, $mysqlPort, $mysqlDatabase),
                'username' => getenv('TINA4_TEST_MYSQL_USERNAME') ?: 'root',
                'password' => getenv('TINA4_TEST_MYSQL_PASSWORD') ?: 'tina4',
                'host' => $mysqlHost,
                'port' => $mysqlPort,
                'dsn' => sprintf('mysql:host=%s;port=%d;dbname=%s', $mysqlHost, $mysqlPort, $mysqlDatabase),
            ],
        ];

        if ($this->opportunisticEnginesWithoutDrivers() === []) {
            $mssqlHost = getenv('TINA4_TEST_MSSQL_HOST') ?: '127.0.0.1';
            $mssqlPort = (int)(getenv('TINA4_TEST_MSSQL_PORT') ?: 1433);
            $mssqlDatabase = getenv('TINA4_TEST_MSSQL_DB') ?: 'tina4_test';

            $engines[] = [
                'name' => 'mssql',
                'url' => sprintf('mssql://%s:%d/%s', $mssqlHost, $mssqlPort, $mssqlDatabase),
                'username' => getenv('TINA4_TEST_MSSQL_USERNAME') ?: 'sa',
                'password' => getenv('TINA4_TEST_MSSQL_PASSWORD') ?: '',
                'host' => $mssqlHost,
                'port' => $mssqlPort,
                'dsn' => sprintf('dblib:host=%s:%d;dbname=%s', $mssqlHost, $mssqlPort, $mssqlDatabase),
            ];
        }

        return $engines;
    }

    /**
     * Engines this PHP build cannot exercise at all, with the reason.
     *
     * SQL Server needs a driver on BOTH sides: the adapter takes ext-sqlsrv or
     * ext-pdo_dblib, and the out-of-band verifier takes the dblib PDO driver.
     * Where either is missing the engine is named as NOT EXERCISED rather than
     * quietly counted as fine.
     *
     * @return string[]
     */
    private function opportunisticEnginesWithoutDrivers(): array
    {
        $pdoDrivers = \PDO::getAvailableDrivers();
        $adapterDriver = function_exists('sqlsrv_connect') || in_array('dblib', $pdoDrivers, true);
        $verifierDriver = in_array('dblib', $pdoDrivers, true);

        if ($adapterDriver && $verifierDriver) {
            return [];
        }

        return ['mssql' => 'needs ext-sqlsrv or ext-pdo_dblib for the adapter, and ext-pdo_dblib'
            . ' for the out-of-band verifier; this build has neither'];
    }

    /**
     * Print the engine roster to STDERR so a GREEN run still says what it proved.
     *
     * @param string[]                    $ran
     * @param array<string, string|null>  $openDefects
     * @param string[]                    $notExercised
     */
    private function reportRoster(array $ran, array $openDefects, array $notExercised): void
    {
        fwrite(STDERR, "\n[session-contract] database session engines exercised: "
            . ($ran !== [] ? implode(', ', $ran) : 'NONE') . "\n");

        foreach ($openDefects as $engineName => $reason) {
            fwrite(STDERR, '[session-contract] measured OPEN defect, not fixed by this lock-in: '
                . ($reason === null ? $engineName . ' UNEXPECTEDLY WORKED' : $reason) . "\n");
        }

        foreach ($notExercised as $reason) {
            fwrite(STDERR, '[session-contract] NOT exercised on this build: ' . $reason . "\n");
        }
    }

    /**
     * Point TINA4_DATABASE_URL (and its credentials) at one engine.
     *
     * The query caches are pinned OFF for the duration. This invariant is about
     * the ENGINE, and a persistent cross-request cache could let the "fresh"
     * handler answer from the cache instead of the database - which is the one
     * thing this test must never allow to look like success.
     *
     * @param array{name:string,url:string,username:string,password:string,host:?string,port:int,dsn:string} $engine
     */
    private function pointTheEnvironmentAt(array $engine): void
    {
        $this->setEnvironment('TINA4_DATABASE_URL', $engine['url']);
        $this->setEnvironment('TINA4_DATABASE_USERNAME', $engine['username']);
        $this->setEnvironment('TINA4_DATABASE_PASSWORD', $engine['password']);
        $this->setEnvironment('TINA4_AUTO_CACHING', 'false');
        $this->setEnvironment('TINA4_DB_CACHE', 'false');
        \Tina4\DotEnv::resetEnv();
    }

    /**
     * Remember an env var's current value, then set it. tearDown restores it.
     *
     * Both getenv() and $_ENV are written, because DotEnv::getEnv() consults its
     * own store, then $_ENV, then getenv() - so a value present in $_ENV would
     * otherwise win over the putenv() this test just made.
     */
    private function setEnvironment(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->savedGetenv)) {
            $this->savedGetenv[$name] = getenv($name);
            $this->savedSuperglobal[$name] = $_ENV[$name] ?? null;
        }
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
    }

    /**
     * A temp SQLite path that tearDown removes.
     */
    private function temporaryPath(string $label): string
    {
        $path = sys_get_temp_dir() . "/tina4-session-{$label}-" . bin2hex(random_bytes(4)) . '.db';
        $this->temporaryFiles[] = $path;
        @unlink($path);
        return $path;
    }

    /**
     * Why an engine could not be reached, or null when it can.
     */
    private function unreachableReason(string $engine, string $host, int $port): ?string
    {
        $errorNumber = 0;
        $errorMessage = '';
        $probe = @fsockopen($host, $port, $errorNumber, $errorMessage, 2);
        if ($probe === false) {
            return sprintf(
                '%s is not reachable at %s:%d (%s)',
                $engine,
                $host,
                $port,
                $errorMessage !== '' ? $errorMessage : 'no error reported'
            );
        }
        fclose($probe);
        return null;
    }
}
