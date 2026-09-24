<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Contract tests for the TINA4_REQUIRE_SERVICES gate (Tina4\Testing\*).
 *
 * The gate guarantees NO GREEN SKIPS where TINA4_REQUIRE_SERVICES=1: a skip
 * fails the run unless its reason carries an excusable [needs:X] tag (ADR-0069
 * addendum F - optional engine only while its coordinate is unset, an
 * always-provisioned service never, a platform exclusion always; untagged
 * fails).
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
        // env(1) takes OPTIONS first and NAME=VALUE assignments after: its usage
        // is `env [OPTION]... [NAME=VALUE]... [COMMAND]`, so the first token that
        // is not an option ends option parsing. Emitting `env A=1 -u B cmd` makes
        // env treat `-u` as the COMMAND and die with
        // "env: '-u': No such file or directory" - which surfaces as the child
        // exiting non-zero and reads exactly like the gate firing. Collect the
        // two kinds separately and always write the unsets first.
        $unset = [];
        $assign = [];

        if ($armed) {
            $assign[] = 'TINA4_REQUIRE_SERVICES=1';
        } else {
            $unset[] = 'TINA4_REQUIRE_SERVICES';
        }
        foreach ($environment as $name => $value) {
            if ($value === null) {
                $unset[] = $name;
                continue;
            }
            $assign[] = $name . '=' . $value;
        }

        $prefix = 'env';
        foreach ($unset as $name) {
            $prefix .= ' -u ' . escapeshellarg($name);
        }
        foreach ($assign as $assignment) {
            $prefix .= ' ' . escapeshellarg($assignment);
        }

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
        $this->assertStringContainsString('2 test(s) SKIPPED without an excusable [needs:...] tag', $output);
        $this->assertStringContainsString('Kafka not reachable', $output);
        $this->assertStringContainsString('Redis not reachable', $output);
    }

    // ── Gate off: unchanged ─────────────────────────────────────────────────

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

    // ── ADR-0069 addendum F: only a [needs:X] tag can excuse a skip ─────────

    /**
     * An UNTAGGED skip fails the armed run, whatever it says. The phrase list
     * this replaced missed "no reachable ..." (the wording 11 skip sites used),
     * so those skipped green; wording can no longer decide anything.
     */
    public function testAnUntaggedSkipFailsTheArmedRunWhateverItsWording(): void
    {
        foreach ([
            'GateFixtureNoReachable' => 'no reachable MongoDB at mongodb://192.168.88.99:27017',
            'GateFixtureUnprovisioned' => 'Cassandra not reachable on localhost:9042',
            'GateFixturePlain' => 'this test is slow, run it manually',
        ] as $className => $reason) {
            [$output, $code] = $this->runPhpunit(
                [$className => $this->beforeClassFixture($className, $reason, 1)],
                true,
            );
            $this->assertNotSame(0, $code, "an untagged skip ('{$reason}') exited 0 under the gate:\n" . $output);
            $this->assertStringContainsString('TINA4_REQUIRE_SERVICES is set, but', $output);
            $this->assertStringContainsString($reason, $output);
        }
    }

    /**
     * An OPTIONAL engine's tag excuses the skip only while the run has NOT
     * published that engine's coordinate: the CI job that never promised a
     * Firebird stays green, the lab (and the `firebird:` job) that did fail.
     */
    public function testAnOptionalEngineTagIsExcusedOnlyWhileItsCoordinateIsUnset(): void
    {
        $reason = '[needs:firebird] Firebird unreachable at localhost:3050';

        [$output, $code] = $this->runPhpunit(
            ['GateFixtureNoFirebird' => $this->beforeClassFixture('GateFixtureNoFirebird', $reason, 1)],
            true,
            ['TINA4_TEST_FIREBIRD_URL' => null],
        );
        $this->assertSame(0, $code, "no Firebird promised: a [needs:firebird] skip must stay green.\n" . $output);
        $this->assertStringNotContainsString('TINA4_REQUIRE_SERVICES is set', $output);

        [$output, $code] = $this->runPhpunit(
            ['GateFixtureFirebird' => $this->beforeClassFixture('GateFixtureFirebird', $reason, 1)],
            true,
            ['TINA4_TEST_FIREBIRD_URL' => 'firebird://SYSDBA:masterkey@127.0.0.1:3050//data/t.fdb'],
        );
        $this->assertNotSame(0, $code, "a Firebird WAS promised: its skip must fail even when tagged.\n" . $output);
        $this->assertStringContainsString($reason, $output);
    }

    /** postgres is promised by its canonical coordinate, TINA4_TEST_PG_URL (ADR-0038). */
    public function testThePostgresTagFollowsItsCanonicalCoordinate(): void
    {
        $reason = '[needs:postgres] PostgreSQL unreachable at localhost:5432';

        [$output, $code] = $this->runPhpunit(
            ['GateFixturePgUrl' => $this->beforeClassFixture('GateFixturePgUrl', $reason, 1)],
            true,
            ['TINA4_TEST_PG_URL' => 'postgres://tina4:tina4@127.0.0.1:55432/tina4_php'],
        );
        $this->assertNotSame(0, $code, "a promised PostgreSQL skip must fail.\n" . $output);

        [$output, $code] = $this->runPhpunit(
            ['GateFixtureNoPostgres' => $this->beforeClassFixture('GateFixtureNoPostgres', $reason, 1)],
            true,
            ['TINA4_TEST_PG_URL' => null],
        );
        $this->assertSame(0, $code, "no PostgreSQL promised: the tagged skip must stay green.\n" . $output);
    }

    /** An ALWAYS-provisioned service is never excused, tagged or not. */
    public function testAnAlwaysProvisionedServiceTagIsNeverExcused(): void
    {
        $reason = '[needs:mongo] MongoDB unreachable at localhost:27017';
        [$output, $code] = $this->runPhpunit(
            ['GateFixtureTaggedMongo' => $this->perTestFixture('GateFixtureTaggedMongo', $reason)],
            true,
        );
        $this->assertNotSame(0, $code, $output);
        $this->assertStringContainsString($reason, $output);
    }

    /** A platform exclusion tag is always excused. */
    public function testAPlatformExclusionTagIsExcused(): void
    {
        [$output, $code] = $this->runPhpunit(
            ['GateFixturePlatform' => $this->perTestFixture(
                'GateFixturePlatform',
                '[needs:absent-ext=pgsql] only meaningful where ext-pgsql is NOT loaded',
            )],
            true,
        );
        $this->assertSame(0, $code, $output);
        $this->assertStringNotContainsString('TINA4_REQUIRE_SERVICES is set', $output);
    }

    // ── The predicate itself (pure function of the reason + this env) ───────

    /**
     * @param array<string, string|null> $environment
     */
    private function withEnvironment(array $environment, callable $body): void
    {
        $original = [];
        foreach ($environment as $name => $value) {
            $original[$name] = getenv($name);
            $value === null ? putenv($name) : putenv("{$name}={$value}");
        }
        try {
            $body();
        } finally {
            foreach ($original as $name => $value) {
                $value === false ? putenv($name) : putenv("{$name}={$value}");
            }
        }
    }

    public function testThePredicateCoversAllFourBranches(): void
    {
        $unsetAll = [
            'TINA4_TEST_FIREBIRD_URL' => null, 'TINA4_TEST_PG_URL' => null,
            'TINA4_TEST_MYSQL_URL' => null, 'TINA4_TEST_MSSQL_URL' => null, 'TINA4_TEST_SWOOLE' => null,
            'TINA4_TEST_OIDC_ISSUER' => null, 'TINA4_TEST_NEO4J_URL' => null, 'TINA4_TEST_MEMGRAPH_URL' => null,
            'TINA4_TEST_ARANGO_URL' => null, 'TINA4_TEST_ULTIPA_URL' => null,
        ];
        $this->withEnvironment($unsetAll, function (): void {
            // untagged -> never excused
            $this->assertFalse(RequireServicesGate::isExcusedSkip('no reachable MongoDB at x'));
            $this->assertFalse(RequireServicesGate::isExcusedSkip(''));
            $this->assertFalse(RequireServicesGate::isExcusedSkip('needs:firebird without brackets'));
            // optional engines, none promised -> excused
            foreach (['firebird', 'postgres', 'mysql', 'mssql', 'swoole', 'oidc', 'neo4j', 'memgraph', 'arango', 'ultipa'] as $engine) {
                $this->assertTrue(RequireServicesGate::isExcusedSkip("[needs:{$engine}] unavailable"), $engine);
            }
            // always-provisioned -> never excused
            foreach (['mongo', 'redis', 'valkey', 'memcached', 'rabbitmq', 'kafka', 'mqtt', 'smtp', 'imap', 's3'] as $service) {
                $this->assertFalse(RequireServicesGate::isExcusedSkip("[needs:{$service}] unavailable"), $service);
            }
            // platform exclusions -> excused
            foreach (['absent-ext=pgsql', 'no-dac-override', 'os=windows', 'composer', 'runtime=php8.4'] as $platform) {
                $this->assertTrue(RequireServicesGate::isExcusedSkip("[needs:{$platform}] n/a here"), $platform);
            }
            // every tag must be excusable
            $this->assertFalse(RequireServicesGate::isExcusedSkip('[needs:composer] [needs:redis] both'));
        });

        foreach ([
            'firebird' => 'TINA4_TEST_FIREBIRD_URL', 'postgres' => 'TINA4_TEST_PG_URL', 'mysql' => 'TINA4_TEST_MYSQL_URL',
            'mssql' => 'TINA4_TEST_MSSQL_URL', 'swoole' => 'TINA4_TEST_SWOOLE', 'oidc' => 'TINA4_TEST_OIDC_ISSUER',
            'neo4j' => 'TINA4_TEST_NEO4J_URL', 'memgraph' => 'TINA4_TEST_MEMGRAPH_URL',
            'arango' => 'TINA4_TEST_ARANGO_URL', 'ultipa' => 'TINA4_TEST_ULTIPA_URL',
        ] as $engine => $coordinate) {
            // every other coordinate unset, so the host's own exports cannot decide it
            $this->withEnvironment(array_merge($unsetAll, [$coordinate => 'promised']), function () use ($engine): void {
                $this->assertFalse(RequireServicesGate::isExcusedSkip("[needs:{$engine}] unavailable"), "{$engine} promised");
            });
            // a blank coordinate is not a promise
            $this->withEnvironment(array_merge($unsetAll, [$coordinate => '   ']), function () use ($engine): void {
                $this->assertTrue(RequireServicesGate::isExcusedSkip("[needs:{$engine}] unavailable"), "{$engine} blank");
            });
        }
    }
}
