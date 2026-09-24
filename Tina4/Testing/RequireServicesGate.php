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
 * When TINA4_REQUIRE_SERVICES is truthy, a SKIP is a failure unless its reason
 * carries a machine-readable `[needs:X]` tag AND X is excusable in this run
 * (ADR-0069 addendum F, the same rule in all four frameworks):
 *
 *   - X is an OPTIONAL engine with a coordinate env var (OPTIONAL_ENGINES):
 *     excused ONLY while that coordinate is unset. A CI job that never promised
 *     the engine stays green; the lab and any job that did promise it fail.
 *   - X is an ALWAYS-provisioned service (ALWAYS_PROVISIONED): never excused.
 *   - any other X (absent-ext=..., no-dac-override, os=..., composer,
 *     runtime=...) is a platform exclusion: always excused.
 *   - an UNTAGGED skip fails.
 *
 * No phrase matching. The phrase list this replaced ("not reachable",
 * "unreachable", ...) missed wordings such as "no reachable MongoDB at ...",
 * and every miss was a test that skipped green under the gate.
 *
 * Mechanism: a PHPUnit 11 event Extension subscribes to BOTH Test\Skipped and
 * TestSuite\Skipped to collect offending skips, then fails the whole run from
 * Application\Finished. The skip REASON text is only available in-process
 * (PHPUnit's JUnit XML does not carry skip messages), so an event subscriber -
 * not a post-run XML parse - is the only reliable mechanism on this PHPUnit
 * major version.
 *
 * BOTH subscriptions are load-bearing. A skip inside a test method emits
 * Test\Skipped; a skip from setUpBeforeClass() emits only ONE TestSuite\Skipped
 * for the entire class (PHPUnit catches the SkippedTest thrown by the hook - see
 * TestSuiteSkippedSubscriber). Locked in by tests/RequireServicesGateTest.php.
 *
 * This is a singleton so the two subscriber objects share one violation list.
 */
final class RequireServicesGate
{
    /**
     * Engines some gated runs provide and others deliberately do not, keyed by
     * the tag name, valued by the coordinate env var(s) that promise one. The
     * CI `test:` job has no Firebird/Swoole, `firebird:` has no PG/MySQL/MSSQL,
     * `cache-driver:` has only PostgreSQL; the lab sets every coordinate.
     *
     * @var array<string, string[]>
     */
    private const OPTIONAL_ENGINES = [
        'firebird' => ['TINA4_TEST_FIREBIRD_URL'],
        'postgres' => ['TINA4_TEST_PG_URL', 'TINA4_TEST_POSTGRES_URL'],
        'mysql'    => ['TINA4_TEST_MYSQL_URL'],
        'mssql'    => ['TINA4_TEST_MSSQL_URL'],
        'swoole'   => ['TINA4_TEST_SWOOLE'],
        'oidc'     => ['TINA4_TEST_OIDC_ISSUER'],
        'neo4j'    => ['TINA4_TEST_NEO4J_URL'],
        'memgraph' => ['TINA4_TEST_MEMGRAPH_URL'],
        'arango'   => ['TINA4_TEST_ARANGO_URL'],
        'ultipa'   => ['TINA4_TEST_ULTIPA_URL'],
    ];

    /** Services every gated run provisions: a skip for one is never excused. */
    private const ALWAYS_PROVISIONED = [
        'mongo', 'redis', 'valkey', 'memcached', 'rabbitmq', 'kafka', 'mqtt', 'smtp', 'imap', 's3',
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
     * True when a skip reason carries at least one `[needs:X]` tag and every
     * tag is excusable in this run (see the class docblock).
     */
    public static function isExcusedSkip(string $reason): bool
    {
        if (!preg_match_all('/\[needs:([^\]\s]+)\]/i', $reason, $matches)) {
            return false;
        }

        foreach ($matches[1] as $need) {
            $need = strtolower($need);
            if (in_array($need, self::ALWAYS_PROVISIONED, true)) {
                return false;
            }
            foreach (self::OPTIONAL_ENGINES[$need] ?? [] as $coordinate) {
                $value = getenv($coordinate);
                if ($value !== false && trim($value) !== '') {
                    return false;
                }
            }
        }

        return true;
    }

    /** Record a skip if the gate is armed and the reason is a violation. */
    public function recordSkip(string $testId, string $reason): void
    {
        if (!self::isRequired()) {
            return;
        }
        if (self::isExcusedSkip($reason)) {
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
            . ' test(s) SKIPPED without an excusable [needs:...] tag:');
        foreach ($this->violations as $v) {
            $writeLine('  - ' . $v['id']);
            $writeLine('      ' . $v['reason']);
        }
        $writeLine('Provision the service / install the client. Only a genuine platform exclusion may skip,');
        $writeLine('and it must say so in its reason: [needs:absent-ext=...], [needs:os=...], [needs:<optional engine>].');
        $writeLine('');
    }
}
