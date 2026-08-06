<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Testing;

/**
 * Real-service test gate (parity with the Python master's conftest.py hook).
 *
 * When TINA4_REQUIRE_SERVICES is truthy, a test that SKIPPED because a
 * PROVISIONED real service (or its client library) is missing must turn into a
 * hard FAILURE — a green skip there is the exact gap that let the migration and
 * queue bugs ship. CI provisions PostgreSQL, MySQL, MSSQL, Redis, Valkey,
 * Memcached, MongoDB, RabbitMQ and Kafka, so those integration tests MUST run.
 * MySQL and MSSQL / SQL Server joined the provisioned set in 3.13.44 (#262), so
 * their reachability / boolean round-trip skips now turn into failures too.
 *
 * Firebird is gated PER RUN, not per fleet — see CONDITIONAL_SERVICE_KEYWORDS.
 * It used to be excluded outright on the stated belief that no Firebird server
 * was available, and that belief is false where it matters: the lab and the CI
 * `firebird:` job both run a live Firebird 5.0.4/5.0.2. But it is TRUE in the
 * main CI `test:` job, which runs the whole suite with this gate armed and
 * deliberately has no Firebird container (stacking one onto that already
 * 8-service job destabilised the runner). So neither a flat include nor a flat
 * exclude is honest: including it unconditionally turns every Firebird skip in
 * `test:` into a failure and reddens CI for a service that job never claimed to
 * provide, while excluding it lets a real Firebird skip pass green on the two
 * environments that DO provide one. Arm it on the evidence instead.
 *
 * Mechanism: a PHPUnit 11 event Extension subscribes to BOTH Test\Skipped and
 * TestSuite\Skipped to collect offending skips, then fails the whole run from
 * Application\Finished. The skip REASON text is only available in-process
 * (PHPUnit's JUnit XML does not carry skip messages), so an event subscriber —
 * not a post-run XML parse — is the only reliable mechanism on this PHPUnit
 * major version.
 *
 * BOTH subscriptions are load-bearing. A skip inside a test method emits
 * Test\Skipped; a skip from setUpBeforeClass() emits only ONE TestSuite\Skipped
 * for the entire class (PHPUnit catches the SkippedTest thrown by the hook — see
 * TestSuiteSkippedSubscriber). Listening to Test\Skipped alone meant a class-wide
 * service gate skipped GREEN under TINA4_REQUIRE_SERVICES. Locked in by
 * tests/RequireServicesGateTest.php.
 *
 * This is a singleton so the two subscriber objects share one violation list.
 */
final class RequireServicesGate
{
    /**
     * Provisioned real services (and their client-library names). A skip whose
     * reason mentions one of these AND an unavailable hint is a violation.
     * MySQL + MSSQL/SQL Server joined the provisioned set in 3.13.44 (#262).
     * These are provisioned by EVERY gated environment, so they are listed
     * unconditionally; a service that only some gated runs provide belongs in
     * CONDITIONAL_SERVICE_KEYWORDS instead.
     */
    private const SERVICE_KEYWORDS = [
        'redis', 'valkey', 'memcached',
        'mongo',          // also matches "mongodb" / "pymongo"
        'rabbit', 'amqp',
        'kafka',          // also matches "rdkafka" / "confluent-kafka"
        'mqtt', 'mosquitto',  // Mosquitto (+ EMQX) for the MQTT tests
        // GreenMail (real SMTP 3025 / IMAP 3143) for the Messenger round-trip
        // tests, plus ext-imap which those tests require. No mail keyword
        // existed here before, so a "not reachable" mail skip passed green.
        'greenmail', 'smtp', 'imap',
    ];

    /**
     * Services whose provisioning varies BETWEEN GATED RUNS, keyed by the env
     * var that proves this run has one.
     *
     * The flat list above encodes "our fleet provides this", which is the right
     * model only while every gated environment agrees. Firebird is the case
     * where they do not: the lab and the CI `firebird:` job run a live server
     * and set TINA4_TEST_FIREBIRD_URL; the main CI `test:` job runs the very
     * same suite with the gate armed and deliberately provides no Firebird at
     * all. Keying on the coordinates makes the gate ask the question it always
     * meant to ask — "was this service promised to THIS run?" — instead of
     * inferring it from a constant that cannot be true everywhere at once.
     *
     * So: coordinates set => a Firebird skip is a violation like any other;
     * coordinates absent => that run never claimed a Firebird and its skips
     * stay green. No environment has to be lied to.
     *
     * TINA4_TEST_FIREBIRD_URL is the canonical spelling (ADR-0038) and the one
     * every live Firebird test reads.
     *
     * @var array<string, string[]>
     */
    private const CONDITIONAL_SERVICE_KEYWORDS = [
        // The engine, the native client (ext-interbase, functions ibase_*/
        // fbird_*) and the PDO driver (pdo_firebird).
        'TINA4_TEST_FIREBIRD_URL' => ['firebird', 'interbase', 'ibase'],

        // THE SQL ENGINES ARE CONDITIONAL FOR THE SAME REASON FIREBIRD IS, and
        // leaving them unconditional is what kept the `firebird:` CI job's gate
        // switched off. MEASURED 2026-08-06 on the 14 files that job runs: nine
        // skip sites emit a PostgreSQL or mysqli reason - "PostgreSQL not
        // reachable at %s:%d", "ext-mysqli not installed", "no PostgreSQL driver
        // (pdo_pgsql / ext-pgsql) installed" - and that job provisions neither
        // engine. Arming the gate there would have turned all nine into hard
        // failures and reddened CI for services the job never claimed to
        // provide.
        //
        // The two obvious workarounds are both wrong. Dropping those files from
        // the job removes the FIREBIRD cases they carry, which is the only
        // reason they are in that list. Rewording the skip reasons to dodge a
        // keyword is gaming the gate: those reasons are accurate, and the next
        // honestly-worded one re-opens the hole.
        //
        // Every gated environment that PROVIDES these sets its URL - the CI
        // `test:` job (all three), the CI `cache-driver:` job (postgres), and
        // the lab's lab-env.sh / lab-env-for.sh (all three) - so keying on the
        // coordinates keeps the gate exactly as strict where the service is
        // promised, and silent where it is not.
        // Swoole/OpenSwoole is conditional for the same reason: only the
        // `swoole:` CI job installs the extension, and the main `test:` job
        // must not fail for a PECL extension it never claimed to provide. The
        // job that DOES install it sets TINA4_TEST_SWOOLE=1, so a skip there -
        // which would mean the extension silently failed to load - is a hard
        // failure instead of a green.
        'TINA4_TEST_SWOOLE' => ['swoole', 'openswoole'],

        'TINA4_TEST_PG_URL' => ['postgres', 'postgresql', 'psycopg2', 'pg_connect', 'ext-pgsql'],
        'TINA4_TEST_MYSQL_URL' => ['mysql'],   // also matches "ext-mysqli" / "pdo_mysql"
        // SQL Server, native (ext-sqlsrv) or via FreeTDS/pdo_dblib.
        'TINA4_TEST_MSSQL_URL' => ['mssql', 'sqlserver', 'sqlsrv', 'pdo_dblib'],
    ];

    /**
     * STILL UNCONDITIONAL, and the reason is a limit rather than a decision:
     * redis, valkey, memcached, mongo, rabbit, kafka, mqtt and the mail
     * services stay in SERVICE_KEYWORDS because a run that provides them does
     * not always announce them through a single canonical URL variable the way
     * the SQL engines do. Moving one without a coordinate to key on would
     * silently DISARM the gate for it everywhere, which is the opposite of the
     * bug being fixed here.
     *
     * The consequence, stated rather than hidden: the `firebird:` job provides
     * none of those either, so a future test added to its file list that skips
     * on, say, Mongo would fail that job. That is loud rather than silent, and
     * the fix at that point is to give the service a coordinate and move it
     * here too.
     */

    /**
     * Phrases that mean "the provisioned thing is not there right now".
     *
     * 'not configured' and 'not present' were missing, and each one was a live
     * leak: tests/DatabaseUrlCredentialsTest.php skips with the literal reason
     * "live PostgreSQL not configured (TINA4_TEST_PG_URL)", which names a
     * provisioned service on the keyword axis but matched NO hint, so the gate
     * never fired and the test skipped green against a running PostgreSQL.
     */
    private const UNAVAILABLE_HINTS = [
        'not reachable', 'unreachable', 'not running', 'not set',
        'not installed', 'could not connect', 'not available', 'refused',
        'not configured', 'not present', 'cannot connect', 'connect failed',
        // The escape-hatch phrase. A whole family of Firebird skips reads
        // "<driver> present but cannot connect (...) - native Firebird
        // UNVERIFIED here", i.e. the test is telling you in plain words that it
        // proved nothing. Against a PROVISIONED service that is a failure, not a
        // pass: the escape exists for a genuinely broken client on a dev box,
        // and it must never be how CI reports success.
        'unverified',
    ];

    /** @var array<int, array{id:string, reason:string}> */
    private array $violations = [];

    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /** TINA4_REQUIRE_SERVICES truthy => the gate is armed. */
    public static function isRequired(): bool
    {
        $raw = getenv('TINA4_REQUIRE_SERVICES');
        if ($raw === false) {
            return false;
        }

        return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * The service keywords in force for THIS run: the unconditional set, plus
     * each conditional service whose coordinates the environment actually
     * publishes.
     *
     * @return string[]
     */
    public static function activeServiceKeywords(): array
    {
        $keywords = self::SERVICE_KEYWORDS;

        foreach (self::CONDITIONAL_SERVICE_KEYWORDS as $environmentVariable => $conditionalKeywords) {
            $value = getenv($environmentVariable);
            if ($value !== false && trim($value) !== '') {
                $keywords = array_merge($keywords, $conditionalKeywords);
            }
        }

        return $keywords;
    }

    /**
     * A skip reason is a violation when it names a PROVISIONED service (or its
     * client library) AND signals that the thing is unavailable.
     */
    public static function isProvisionedServiceSkip(string $reason): bool
    {
        $low = strtolower($reason);

        $namesService = false;
        foreach (self::activeServiceKeywords() as $keyword) {
            if (str_contains($low, $keyword)) {
                $namesService = true;
                break;
            }
        }
        if (!$namesService) {
            return false;
        }

        foreach (self::UNAVAILABLE_HINTS as $hint) {
            if (str_contains($low, $hint)) {
                return true;
            }
        }

        return false;
    }

    /** Record a skip if the gate is armed and the reason is a violation. */
    public function recordSkip(string $testId, string $reason): void
    {
        if (!self::isRequired()) {
            return;
        }
        if (!self::isProvisionedServiceSkip($reason)) {
            return;
        }

        $this->violations[] = ['id' => $testId, 'reason' => trim($reason)];
    }

    /** @return array<int, array{id:string, reason:string}> */
    public function violations(): array
    {
        return $this->violations;
    }

    public function hasViolations(): bool
    {
        return $this->violations !== [];
    }

    /**
     * Print the violation report. Called from the Application\Finished
     * subscriber, which then exits non-zero so CI fails the run.
     */
    public function reportTo(callable $writeLine): void
    {
        if (!$this->hasViolations()) {
            return;
        }

        $writeLine('');
        $writeLine('TINA4_REQUIRE_SERVICES is set, but ' . count($this->violations)
            . ' real-service test(s) SKIPPED because a provisioned service or client library is missing:');
        foreach ($this->violations as $v) {
            $writeLine('  - ' . $v['id']);
            $writeLine('      ' . $v['reason']);
        }
        $writeLine('Provision the service / install the client, or unset TINA4_REQUIRE_SERVICES.');
        $writeLine('');
    }
}
