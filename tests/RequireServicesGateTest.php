<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Contract tests for the TINA4_REQUIRE_SERVICES gate (Tina4\Testing\*).
 *
 * The gate guarantees NO GREEN SKIPS in CI: when the workflow has provisioned
 * PostgreSQL/MySQL/MSSQL/Redis/Valkey/Memcached/Mongo/RabbitMQ/Kafka and set
 * TINA4_REQUIRE_SERVICES=1, a test that skips because one of those services (or
 * its client library) is missing must FAIL the run instead of passing quietly.
 *
 * It shipped with a hole: the gate subscribed only to PHPUnit's per-test
 * Test\Skipped event, but a skip declared in setUpBeforeClass() emits a SINGLE
 * TestSuite\Skipped for the whole class instead (PHPUnit catches the SkippedTest
 * thrown by the hook — vendor/phpunit/phpunit/src/Framework/TestSuite.php). So a
 * class-wide service gate skipped GREEN and exited 0 even with the flag armed.
 * Tina4\Testing\TestSuiteSkippedSubscriber closes it. Same class of hole that was
 * fixed in tina4-ruby's spec/spec_helper.rb.
 *
 * NO MOCKS: every case writes a real test file and runs the REAL phpunit binary
 * against the REAL phpunit.xml (which loads the real extension) in a subprocess,
 * then asserts on the real exit status and real output. Nothing is simulated —
 * a doubled event emitter would prove nothing about what PHPUnit actually emits.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Testing\RequireServicesGate;

class RequireServicesGateTest extends TestCase
{
    private static string $repoRoot = '';

    private string $fixtureDir = '';

    public static function setUpBeforeClass(): void
    {
        self::$repoRoot = dirname(__DIR__);
    }

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir() . '/tina4_gate_' . getmypid() . '_' . uniqid();
        if (!is_dir($this->fixtureDir)) {
            mkdir($this->fixtureDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        if ($this->fixtureDir === '' || !is_dir($this->fixtureDir)) {
            return;
        }
        foreach (glob($this->fixtureDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->fixtureDir);
    }

    /**
     * A test class whose gate lives in setUpBeforeClass(): the shape that used to
     * slip through. $className must match the file name PHPUnit loads.
     */
    private function beforeClassFixture(string $className, string $reason, int $tests = 2): string
    {
        $methods = '';
        for ($i = 1; $i <= $tests; $i++) {
            $methods .= "    public function testCase{$i}(): void { \$this->assertTrue(true); }\n";
        }

        return <<<PHP
        <?php declare(strict_types=1);
        use PHPUnit\Framework\TestCase;

        final class {$className} extends TestCase
        {
            public static function setUpBeforeClass(): void
            {
                self::markTestSkipped("{$reason}");
            }

        {$methods}}
        PHP;
    }

    /** A test class that skips per test method: the shape the gate already caught. */
    private function perTestFixture(string $className, string $reason): string
    {
        return <<<PHP
        <?php declare(strict_types=1);
        use PHPUnit\Framework\TestCase;

        final class {$className} extends TestCase
        {
            public function testCase1(): void
            {
                \$this->markTestSkipped("{$reason}");
            }
        }
        PHP;
    }

    /**
     * Write the fixtures and run a REAL phpunit over them.
     *
     * `env -u` genuinely removes the variable from the child so the unarmed case
     * is honest even when CI exports TINA4_REQUIRE_SERVICES=1 for the parent run.
     *
     * @param array<string, string> $fixtures className => source
     * @return array{0: string, 1: int} [combined output, exit code]
     */
    /**
     * @param array<string, string>       $fixtures    className => source.
     * @param array<string, string|null>  $environment Extra child env; null unsets.
     * @return array{0: string, 1: int}
     */
    private function runPhpunit(array $fixtures, bool $armed, array $environment = []): array
    {
        $paths = [];
        foreach ($fixtures as $className => $source) {
            $path = $this->fixtureDir . '/' . $className . '.php';
            file_put_contents($path, $source);
            $paths[] = escapeshellarg($path);
        }

        // Always go through env(1) so a case can both SET and UNSET a variable
        // for the child. A conditionally-gated service (Firebird) is armed by
        // its coordinates being present, so a test of that behaviour has to
        // control them explicitly rather than inherit whatever this host
        // happens to export -- otherwise it would assert the lab's environment
        // instead of the gate's logic, and flip colour between here and CI.
        $prefix = ['env'];
        if ($armed) {
            $prefix[] = 'TINA4_REQUIRE_SERVICES=1';
        } else {
            $prefix[] = '-u';
            $prefix[] = 'TINA4_REQUIRE_SERVICES';
        }
        foreach ($environment as $name => $value) {
            if ($value === null) {
                $prefix[] = '-u';
                $prefix[] = $name;
                continue;
            }
            $prefix[] = escapeshellarg($name . '=' . $value);
        }
        $prefix = implode(' ', $prefix);

        $cmd = sprintf(
            'cd %s && %s ./vendor/bin/phpunit -c phpunit.xml --no-coverage --do-not-cache-result %s 2>&1',
            escapeshellarg(self::$repoRoot),
            $prefix,
            implode(' ', $paths),
        );

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);

        return [implode("\n", $output), $code];
    }

    // ── The hole this test exists for ───────────────────────────────────────

    public function testProvisionedServiceSkipFromSetUpBeforeClassFailsTheRun(): void
    {
        [$output, $code] = $this->runPhpunit(
            ['GateFixtureBeforeClass' => $this->beforeClassFixture(
                'GateFixtureBeforeClass',
                'Kafka not reachable on localhost:9092',
            )],
            true,
        );

        $this->assertNotSame(
            0,
            $code,
            "a setUpBeforeClass skip of a provisioned service exited 0 (green skip):\n" . $output,
        );
        $this->assertStringContainsString('TINA4_REQUIRE_SERVICES is set, but', $output);
        $this->assertStringContainsString('Kafka not reachable on localhost:9092', $output);
        $this->assertStringContainsString('GateFixtureBeforeClass', $output);
    }

    // ── The path that already worked, locked in against regression ──────────

    public function testProvisionedServiceSkipInATestMethodFailsTheRun(): void
    {
        [$output, $code] = $this->runPhpunit(
            ['GateFixturePerTest' => $this->perTestFixture(
                'GateFixturePerTest',
                'Redis not reachable on localhost:6379',
            )],
            true,
        );

        $this->assertNotSame(0, $code, $output);
        $this->assertStringContainsString('Redis not reachable on localhost:6379', $output);
    }

    public function testBothSkipShapesAreReportedTogether(): void
    {
        [$output, $code] = $this->runPhpunit(
            [
                'GateFixtureBeforeClass' => $this->beforeClassFixture(
                    'GateFixtureBeforeClass',
                    'Kafka not reachable on localhost:9092',
                ),
                'GateFixturePerTest' => $this->perTestFixture(
                    'GateFixturePerTest',
                    'Redis not reachable on localhost:6379',
                ),
            ],
            true,
        );

        $this->assertNotSame(0, $code, $output);
        $this->assertStringContainsString('2 real-service test(s) SKIPPED', $output);
        $this->assertStringContainsString('Kafka not reachable', $output);
        $this->assertStringContainsString('Redis not reachable', $output);
    }

    // ── Negative cases: the gate must NOT fire ──────────────────────────────

    public function testProvisionedServiceSkipStaysGreenWhenTheGateIsNotArmed(): void
    {
        [$output, $code] = $this->runPhpunit(
            ['GateFixtureBeforeClass' => $this->beforeClassFixture(
                'GateFixtureBeforeClass',
                'Kafka not reachable on localhost:9092',
            )],
            false,
        );

        $this->assertSame(0, $code, $output);
        $this->assertStringNotContainsString('TINA4_REQUIRE_SERVICES is set', $output);
    }

    /**
     * A skip for something genuinely NOT provisioned must still stay green, so
     * the suite-level subscriber cannot over-reach.
     *
     * This used to use Firebird as its example of "not provisioned". That was
     * false - a live Firebird 5.0.4 has been answering on 3050 the whole time -
     * and while it stood, this test actively ENFORCED the hole: it asserted that
     * Firebird skips must pass, so 17 of them did. Firebird is now provisioned
     * and gated like every other service (see the case below); the over-reach
     * guard uses a service Tina4 genuinely does not provision instead.
     */
    public function testGenuinelyUnprovisionedServiceSkipStaysGreenEvenWhenArmed(): void
    {
        [$output, $code] = $this->runPhpunit(
            ['GateFixtureUnprovisioned' => $this->beforeClassFixture(
                'GateFixtureUnprovisioned',
                'Cassandra not reachable on localhost:9042',
                1,
            )],
            true,
        );

        $this->assertSame(0, $code, $output);
        $this->assertStringNotContainsString('TINA4_REQUIRE_SERVICES is set', $output);
    }

    /**
     * REGRESSION GUARD (positive half): where a Firebird WAS promised, a
     * Firebird skip must FAIL the armed run.
     *
     * Until 2026-08-05 the gate excluded Firebird by keyword and this file
     * asserted the exclusion, so every "ext-interbase not installed" /
     * "Firebird not reachable" skip passed green and stayed invisible.
     *
     * Note this sets the coordinates for the CHILD rather than trusting the
     * ambient ones. Firebird is gated per run, so a version of this test that
     * inherited the environment would pass on the lab (which exports the URL)
     * and fail in the CI `test:` job (which deliberately does not) -- asserting
     * where it is running rather than what the gate does.
     */
    public function testFirebirdSkipFailsTheArmedRunWhenAFirebirdWasPromised(): void
    {
        [$output, $code] = $this->runPhpunit(
            ['GateFixtureFirebird' => $this->beforeClassFixture(
                'GateFixtureFirebird',
                'Firebird not reachable on localhost:3050',
                1,
            )],
            true,
            ['TINA4_TEST_FIREBIRD_URL' => 'firebird://SYSDBA:masterkey@127.0.0.1:3050//data/t.fdb'],
        );

        $this->assertNotSame(
            0,
            $code,
            "a Firebird skip must fail the armed run when TINA4_TEST_FIREBIRD_URL promised one.\n" . $output,
        );
        $this->assertStringContainsString('TINA4_REQUIRE_SERVICES is set', $output);
    }

    /**
     * REGRESSION GUARD (negative half): where NO Firebird was promised, the
     * same skip must stay green.
     *
     * This is the half that keeps the main CI `test:` job honest. It runs this
     * whole suite with the gate armed and deliberately provisions no Firebird
     * (stacking that container onto an already 8-service job destabilised the
     * runner), so listing Firebird unconditionally would fail that job for a
     * service it never claimed to provide.
     */
    public function testFirebirdSkipStaysGreenWhenNoFirebirdWasPromised(): void
    {
        [$output, $code] = $this->runPhpunit(
            ['GateFixtureNoFirebird' => $this->beforeClassFixture(
                'GateFixtureNoFirebird',
                'Firebird not reachable on localhost:3050',
                1,
            )],
            true,
            ['TINA4_TEST_FIREBIRD_URL' => null],
        );

        $this->assertSame(
            0,
            $code,
            "with no Firebird promised, a Firebird skip must stay green.\n" . $output,
        );
        $this->assertStringNotContainsString('TINA4_REQUIRE_SERVICES is set', $output);
    }

    /**
     * The conditional matcher itself, with the environment controlled here so
     * the assertion is about the code and not about the host.
     */
    public function testMatcherArmsFirebirdOnlyWhenItsCoordinatesArePublished(): void
    {
        $reason = 'Firebird not reachable on localhost:3050';
        $original = getenv('TINA4_TEST_FIREBIRD_URL');

        try {
            putenv('TINA4_TEST_FIREBIRD_URL');
            $this->assertFalse(
                RequireServicesGate::isProvisionedServiceSkip($reason),
                'with no coordinates published, this run was never promised a Firebird',
            );
            $this->assertNotContains('firebird', RequireServicesGate::activeServiceKeywords());

            putenv('TINA4_TEST_FIREBIRD_URL=firebird://SYSDBA:masterkey@127.0.0.1:3050//data/t.fdb');
            $this->assertTrue(
                RequireServicesGate::isProvisionedServiceSkip($reason),
                'coordinates published means a Firebird WAS promised, so its skip is a violation',
            );
            $this->assertContains('firebird', RequireServicesGate::activeServiceKeywords());

            // Blank is not a promise.
            putenv('TINA4_TEST_FIREBIRD_URL=   ');
            $this->assertFalse(RequireServicesGate::isProvisionedServiceSkip($reason));
        } finally {
            if ($original === false) {
                putenv('TINA4_TEST_FIREBIRD_URL');
            } else {
                putenv('TINA4_TEST_FIREBIRD_URL=' . $original);
            }
        }
    }

    // ── The keyword matcher itself (pure predicate, no dependency) ───────────

    public function testMatcherAcceptsAProvisionedServicePlusAnUnavailableHint(): void
    {
        $this->assertTrue(RequireServicesGate::isProvisionedServiceSkip('Kafka not reachable on localhost:9092'));
        $this->assertTrue(RequireServicesGate::isProvisionedServiceSkip('rdkafka extension not installed'));
        $this->assertTrue(RequireServicesGate::isProvisionedServiceSkip('TINA4_TEST_KAFKA_URL not set'));
        $this->assertTrue(RequireServicesGate::isProvisionedServiceSkip('MongoDB could not connect'));
    }

    public function testMatcherRejectsAnUnprovisionedServiceOrAPlainSkip(): void
    {
        $this->assertFalse(RequireServicesGate::isProvisionedServiceSkip('Cassandra not reachable on localhost:9042'));
        $this->assertFalse(RequireServicesGate::isProvisionedServiceSkip('Kafka test is slow, run it manually'));
        $this->assertFalse(RequireServicesGate::isProvisionedServiceSkip(''));
    }

    /**
     * Firebird and its two clients are matched, and so are the hint phrases that
     * used to leak. Every string here is a VERBATIM skip reason this suite
     * produced while the gate looked the other way.
     */
    public function testMatcherAcceptsFirebirdAndThePreviouslyLeakingHints(): void
    {
        $this->assertTrue(RequireServicesGate::isProvisionedServiceSkip('ext-interbase not installed'));
        $this->assertTrue(RequireServicesGate::isProvisionedServiceSkip('Firebird not reachable at localhost:53050'));
        $this->assertTrue(RequireServicesGate::isProvisionedServiceSkip(
            'pdo_firebird driver not present - PDO Firebird fallback UNVERIFIED here.'
        ));
        $this->assertTrue(RequireServicesGate::isProvisionedServiceSkip(
            'live PostgreSQL not configured (TINA4_TEST_PG_URL)'
        ));
    }
}
