<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The test-environment-variable contract (ADR-0038).
 *
 * `tests/fixtures/test_env_contract.json` is byte-identical in all four
 * frameworks and is the canonical list of every environment variable the test
 * suites may read or set. This gate scans this framework's own suite plus
 * `.github/workflows` and FAILS, naming the offender and the file it lives in,
 * on any name the fixture does not allow.
 *
 * The bug it locks out: one test PostgreSQL had thirteen spellings and no two
 * frameworks read the same set (`..._PG_URL` vs `..._POSTGRES_URL`, `_USER` vs
 * `_USERNAME`, `_PASS` vs `_PASSWORD`, `_DB` vs `_DATABASE`). Twice in one
 * night a suite went green by SKIPPING, because the lab exported the single
 * spelling one framework happened to read. Adding a fourteenth spelling now
 * turns this suite RED instead of quietly turning a test off.
 *
 * Workflows are scanned as well as tests, deliberately: a CI file that SETS a
 * name no test READS is exactly the failure that hid those skips, so the gate
 * has to catch a rogue set, not just a rogue read.
 *
 * This test touches only the real filesystem and the repository's real files.
 * There are no mocks, doubles or fixtures-of-a-fixture, and none are permitted.
 */
final class TestEnvContractTest extends TestCase
{
    /**
     * The namespace prefix every name in the contract starts with, assembled
     * from two halves ON PURPOSE: written whole it would be a scannable token,
     * and this file would need an exemption from its own scan. Built this way,
     * the gate covers the gate.
     */
    private const NAMESPACE_PREFIX = 'TINA4' . '_TEST_';

    /** The shared contract, relative to the repository root. */
    private const CONTRACT_FIXTURE = 'tests/fixtures/test_env_contract.json';

    /** Names that must turn up in a healthy scan; their absence means the walk broke. */
    private const EXPECTED_NAMES = ['PG_URL', 'PG_USERNAME'];

    /** A scan that finds fewer tokens than this has lost its way, not cleaned the repo. */
    private const MINIMUM_TOKENS = 40;

    public function testEveryTestEnvironmentVariableIsOnTheCanonicalList(): void
    {
        $contract = self::loadContract();
        $canonicalNames = self::canonicalNames($contract);
        $dynamicPrefixes = $contract['dynamic_prefixes'];

        $repositoryRoot = self::repositoryRoot();
        $contractPath = $repositoryRoot . '/' . self::CONTRACT_FIXTURE;

        /** @var array<string, array<string, true>> offending name => set of files */
        $offenders = [];
        /** @var array<string, true> every name the scan actually saw */
        $namesSeen = [];
        /** @var array<string, int> scan path => files read */
        $filesPerScanPath = [];
        $tokensSeen = 0;

        foreach ($contract['scan_paths']['php'] as $scanPath) {
            $filesPerScanPath[$scanPath] = 0;

            foreach (self::filesUnder($repositoryRoot . '/' . $scanPath) as $file) {
                // The contract itself is the definition, and its prose names the
                // retired spellings on purpose. Everything else is in scope.
                if ($file === $contractPath) {
                    continue;
                }

                $source = (string) file_get_contents($file);
                $filesPerScanPath[$scanPath]++;

                foreach (self::tokensIn($source) as $token) {
                    $tokensSeen++;
                    $namesSeen[$token] = true;
                }

                $relativePath = substr($file, strlen($repositoryRoot) + 1);
                foreach (self::violationsIn($source, $canonicalNames, $dynamicPrefixes) as $violation) {
                    $offenders[$violation][$relativePath] = true;
                }
            }
        }

        // A broken file walk finds nothing and would otherwise pass green, so
        // prove the scan actually read the repository before trusting its silence.
        foreach ($filesPerScanPath as $scanPath => $fileCount) {
            self::assertGreaterThan(
                0,
                $fileCount,
                "the scan read no files under '{$scanPath}' - the file walk is broken, not the repository clean"
            );
        }
        self::assertGreaterThanOrEqual(
            self::MINIMUM_TOKENS,
            $tokensSeen,
            'the scan found only ' . $tokensSeen . ' environment-variable tokens across '
            . array_sum($filesPerScanPath) . ' files - too few to be real; the scan is broken'
        );
        foreach (self::EXPECTED_NAMES as $expectedName) {
            self::assertArrayHasKey(
                self::NAMESPACE_PREFIX . $expectedName,
                $namesSeen,
                'the scan never saw ' . self::NAMESPACE_PREFIX . $expectedName
                . ', which the suite definitely reads - the scan is broken'
            );
        }
        // The one dynamic name PHP builds at runtime (Database::fromEnv() proving
        // it fails on a variable that is guaranteed unset). Its literal prefix is
        // on the contract's dynamic list, so the scan must SEE it and ALLOW it.
        self::assertArrayHasKey(
            self::NAMESPACE_PREFIX . 'DB_NONEXISTENT_',
            $namesSeen,
            'the scan never saw the dynamic prefix the suite builds at runtime - the scan is broken'
        );

        self::assertSame([], $offenders, self::describeOffenders($offenders));
    }

    /**
     * The same checker, over a synthetic source, must REPORT an unknown name.
     *
     * The bogus name is concatenated at runtime so its literal never appears in
     * this file and can never trip the real scan above.
     */
    public function testTheCheckerReportsAnUnknownName(): void
    {
        $contract = self::loadContract();
        $bogusName = self::NAMESPACE_PREFIX . 'PG_FOO';

        $violations = self::violationsIn(
            "getenv('{$bogusName}');",
            self::canonicalNames($contract),
            $contract['dynamic_prefixes']
        );

        self::assertSame(
            [$bogusName],
            $violations,
            'the checker did not report an off-contract name - the gate proves nothing'
        );
    }

    /**
     * Every USE SITE must be caught, not just the one this framework happens to
     * favour. Each case sets or reads the SAME off-list name a different way.
     *
     * The echoed-export case is the one that was genuinely broken: an `export`
     * anchored to the line start missed all five names `tests/mqtt-infra.sh`
     * emits, so those names were SET and never checked.
     */
    public function testEveryUseSiteFormIsCaught(): void
    {
        $contract = self::loadContract();
        $canonicalNames = self::canonicalNames($contract);
        $dynamicPrefixes = $contract['dynamic_prefixes'];
        $bogusName = self::NAMESPACE_PREFIX . 'PG_FOO';

        $sourcesByForm = [
            'quoted read' => "getenv('{$bogusName}');",
            'double-quoted read' => "getenv(\"{$bogusName}\");",
            'node dot form' => "process.env.{$bogusName}",
            'yaml env key' => "      {$bogusName}: postgres://host/db",
            'line-start export' => "export {$bogusName}=x",
            'echoed export' => "echo \"export {$bogusName}=x\"",
            'echoed assignment' => "echo \"{$bogusName}=\$X\" >> \"\$GITHUB_ENV\"",
        ];

        foreach ($sourcesByForm as $form => $source) {
            self::assertContains(
                $bogusName,
                self::violationsIn($source, $canonicalNames, $dynamicPrefixes),
                "an off-list name written as a '{$form}' was not caught: {$source}"
            );
        }
    }

    /**
     * What the checker must NOT report: a canonical name, a dynamic prefix the
     * contract registers, a YAML env key, and prose that merely mentions a name.
     */
    public function testTheCheckerAllowsCanonicalNamesDynamicPrefixesAndProse(): void
    {
        $contract = self::loadContract();
        $canonicalNames = self::canonicalNames($contract);
        $dynamicPrefixes = $contract['dynamic_prefixes'];

        $canonicalName = self::NAMESPACE_PREFIX . 'PG_URL';
        $retiredName = self::NAMESPACE_PREFIX . 'POSTGRES_URL';
        $dynamicPrefix = $dynamicPrefixes[0];

        $source = "getenv('{$canonicalName}');\n"
            . "getenv('{$dynamicPrefix}' . uniqid());\n"
            . "      {$canonicalName}: postgres://localhost:55432/tina4_php\n"
            . "// prose naming the retired {$retiredName} and the {$canonicalName} family\n";

        self::assertSame(
            [],
            self::violationsIn($source, $canonicalNames, $dynamicPrefixes),
            'the checker flagged something it must allow - a canonical name, a registered '
            . 'dynamic prefix, a YAML env key, or a sentence in a comment'
        );
    }

    /**
     * Prose is documentation, not a read: a retired name merely MENTIONED in a
     * comment must not fail the gate, while the same name USED must. Both halves
     * are asserted here so the prose allowance can never silently swallow a use.
     */
    public function testProseIsAllowedButTheSameNameUsedIsReported(): void
    {
        $contract = self::loadContract();
        $canonicalNames = self::canonicalNames($contract);
        $dynamicPrefixes = $contract['dynamic_prefixes'];
        $retiredName = self::NAMESPACE_PREFIX . 'POSTGRES_URL';

        self::assertSame(
            [],
            self::violationsIn("// gated on {$retiredName} so CI can skip", $canonicalNames, $dynamicPrefixes),
            'a name mentioned in prose was reported - comments are documentation, not reads'
        );
        self::assertSame(
            [$retiredName],
            self::violationsIn("getenv('{$retiredName}');", $canonicalNames, $dynamicPrefixes),
            'a retired name actually READ was not reported - the gate has lost its teeth'
        );
    }

    /** The repository root - this file lives in its `tests` directory. */
    private static function repositoryRoot(): string
    {
        return dirname(__DIR__);
    }

    /**
     * The shared contract fixture, decoded.
     *
     * @return array<string, mixed>
     */
    private static function loadContract(): array
    {
        $path = self::repositoryRoot() . '/' . self::CONTRACT_FIXTURE;
        $raw = file_get_contents($path);
        self::assertIsString($raw, "cannot read the shared contract fixture at {$path}");

        $contract = json_decode($raw, true);
        self::assertIsArray($contract, "the shared contract fixture is not valid JSON: {$path}");

        return $contract;
    }

    /**
     * Every name the contract allows: <PREFIX><SERVICE>_<ATTRIBUTE> for each
     * service, plus the test-owned fixture names.
     *
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    private static function canonicalNames(array $contract): array
    {
        $names = [];

        foreach ($contract['services'] as $serviceName => $attributeNames) {
            foreach ($attributeNames as $attributeName) {
                $names[] = self::NAMESPACE_PREFIX . $serviceName . '_' . $attributeName;
            }
        }

        foreach ($contract['fixtures'] as $fixtureName) {
            $names[] = $fixtureName;
        }

        return $names;
    }

    /**
     * Every environment-variable name in $source, matched only where a name is
     * actually USED. This rule is identical in all four frameworks.
     *
     * A bare scan for the prefix also matches PROSE, and prose is documentation,
     * not a read: a docblock saying "relocate with the ..._MYSQL_* env vars" or
     * a workflow comment saying "the same ..._* values work locally" would turn
     * a suite red for a sentence. So a name counts only when it is
     *
     *   (a) immediately preceded by a quote - getenv('X'), $_ENV["X"],
     *       Database::fromEnv('X'), putenv("X=value"), or any string literal;
     *   (b) immediately preceded by `process.env.` - no use in PHP, carried for
     *       rule parity with the Node framework;
     *   (c) preceded by `export ` ANYWHERE on the line, not only at its start;
     *   (d) at the start of a line after optional whitespace - a YAML env key,
     *       or a bare shell assignment.
     *
     * (c) must NOT be anchored to the line start. `tests/mqtt-infra.sh` emits its
     * coordinates as `echo "export ..._MQTT_URL=mqtt://127.0.0.1:1883"`, so an
     * anchored `export` misses all five MQTT names - names that are SET and never
     * checked, which is the precise failure this gate exists to catch.
     *
     * tina4: a name built by CONCATENATION is invisible to this rule, because
     * the literal stops at the prefix and the pattern requires at least one
     * character after it. That is the contract's known hole (see its
     * `_dynamic_comment`): prefer an explicit name, and register any prefix you
     * genuinely must build under `dynamic_prefixes`.
     *
     * @return list<string>
     */
    private static function tokensIn(string $source): array
    {
        $usePosition = '(?:["\']|process\.env\.|export\s+|^\s*)';
        preg_match_all('/' . $usePosition . '(' . self::NAMESPACE_PREFIX . '[A-Z0-9_]+)/m', $source, $matches);

        return $matches[1];
    }

    /**
     * Every token in $source the contract does not allow, in order of appearance.
     *
     * @param list<string> $canonicalNames
     * @param list<string> $dynamicPrefixes
     * @return list<string>
     */
    private static function violationsIn(string $source, array $canonicalNames, array $dynamicPrefixes): array
    {
        $violations = [];

        foreach (self::tokensIn($source) as $token) {
            if (in_array($token, $canonicalNames, true) || in_array($token, $dynamicPrefixes, true)) {
                continue;
            }
            $violations[] = $token;
        }

        return $violations;
    }

    /**
     * The failure message: every offending name, and every file it was found in.
     *
     * @param array<string, array<string, true>> $offenders
     */
    private static function describeOffenders(array $offenders): string
    {
        if ($offenders === []) {
            return '';
        }

        $lines = [];
        foreach ($offenders as $name => $files) {
            $lines[] = '  ' . $name . '  in  ' . implode(', ', array_keys($files));
        }

        return count($offenders) . ' test environment variable name(s) are not on the canonical list ('
            . self::CONTRACT_FIXTURE . "):\n" . implode("\n", $lines)
            . "\n\nAdd the name to the fixture under the right service, or use the canonical spelling."
            . "\nThe fixture is byte-identical in all four frameworks - change it in all four.";
    }

    /**
     * Every file under $directory, recursively. Missing directory yields none.
     *
     * @return list<string>
     */
    private static function filesUnder(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($entries as $entry) {
            if ($entry->isFile()) {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
