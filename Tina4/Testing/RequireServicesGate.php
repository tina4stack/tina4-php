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
 * Firebird is deliberately NOT in the keyword set — it is not provisioned, so
 * its "set TINA4_TEST_FIREBIRD_URL" / "not reachable" skips stay green.
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
     * EXCLUDES firebird on purpose (not provisioned — its skips stay green).
     */
    private const SERVICE_KEYWORDS = [
        'postgres', 'postgresql', 'psycopg2', 'pg_connect', 'ext-pgsql',
        'mysql',          // also matches "ext-mysqli" / "pdo_mysql"
        'mssql', 'sqlserver', 'sqlsrv', 'pdo_dblib',  // SQL Server (ext-sqlsrv or FreeTDS/pdo_dblib)
        'redis', 'valkey', 'memcached',
        'mongo',          // also matches "mongodb" / "pymongo"
        'rabbit', 'amqp',
        'kafka',          // also matches "rdkafka" / "confluent-kafka"
        'mqtt', 'mosquitto',  // Mosquitto (+ EMQX) for the MQTT tests
    ];

    /** Phrases that mean "the provisioned thing is not there right now". */
    private const UNAVAILABLE_HINTS = [
        'not reachable', 'unreachable', 'not running', 'not set',
        'not installed', 'could not connect', 'not available', 'refused',
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
     * A skip reason is a violation when it names a PROVISIONED service (or its
     * client library) AND signals that the thing is unavailable.
     */
    public static function isProvisionedServiceSkip(string $reason): bool
    {
        $low = strtolower($reason);

        $namesService = false;
        foreach (self::SERVICE_KEYWORDS as $keyword) {
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
