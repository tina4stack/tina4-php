<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * ADR-0070 fixture runner: tests/fixtures/browser_open_contract.json, a copy
 * of tina4-documentation/plan/v3/fixtures/browser_open_contract.json.
 *
 * Runs every decision_table row that App::run()'s gate can see - env, .env
 * and mode - against the REAL App::shouldOpenBrowser(), with the variables set
 * for real in the process environment / a real .env loaded by DotEnv. No
 * mocks. Rows with a --no-browser flag belong to `bin/tina4php serve`, which
 * has its own flag; App::run() takes no flags, so those rows are counted and
 * reported here rather than silently dropped.
 *
 * It also asserts App::CI_ENVIRONMENT_VARIABLES and App::CI_NOT_SET_VALUES
 * against the fixture's ci_env_vars / ci_not_set_values element for element, so PHP's list cannot drift from the
 * other four launchers.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\App;
use Tina4\DotEnv;

final class BrowserOpenContractTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];
    private ?string $tempDir = null;

    private static function fixture(): array
    {
        return json_decode((string)file_get_contents(__DIR__ . '/fixtures/browser_open_contract.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Every variable the gate reads. */
    private static function gateVariables(): array
    {
        return ['TINA4_NO_BROWSER', ...self::fixture()['ci_env_vars']];
    }

    protected function setUp(): void
    {
        foreach (self::gateVariables() as $name) {
            $this->saved[$name] = getenv($name);
            $this->clear($name);
        }
        DotEnv::resetEnv();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            $this->clear($name);
            if ($value !== false) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
            }
        }
        DotEnv::resetEnv();
        if ($this->tempDir !== null) {
            @unlink($this->tempDir . '/.env');
            @rmdir($this->tempDir);
        }
    }

    private function clear(string $name): void
    {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    public function testTheCiListMatchesTheContractFixtureElementForElement(): void
    {
        $this->assertSame(self::fixture()['ci_env_vars'], App::CI_ENVIRONMENT_VARIABLES);
        $this->assertSame(self::fixture()['ci_not_set_values'], App::CI_NOT_SET_VALUES);
    }

    public function testFlagRowsAreReportedNotDropped(): void
    {
        $withFlag = array_filter(self::fixture()['decision_table'], static fn(array $row) => in_array('--no-browser', $row['flags'], true));
        foreach ($withFlag as $row) {
            $this->assertFalse($row['opens'], "{$row['name']}: --no-browser can only veto");
        }
        $this->assertGreaterThan(0, count($withFlag));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function envRows(): array
    {
        $rows = [];
        foreach (self::fixture()['decision_table'] as $row) {
            // --production is already expressed by the row's mode; only the
            // --no-browser flag has no App::run() equivalent.
            if (!in_array('--no-browser', $row['flags'], true)) {
                $rows[$row['name']] = [$row];
            }
        }
        return $rows;
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('envRows')]
    public function testTheGateAnswersEveryEnvironmentRow(array $row): void
    {
        foreach ($row['env'] as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }
        if (!empty($row['dotenv'])) {
            $this->tempDir = \TempPath::dir('tina4_browser_contract_');
            $lines = '';
            foreach ($row['dotenv'] as $name => $value) {
                $lines .= "{$name}={$value}\n";
            }
            file_put_contents($this->tempDir . '/.env', $lines);
            DotEnv::loadEnv($this->tempDir . '/.env');
        }

        $opens = App::shouldOpenBrowser($row['mode'] === 'development');

        $this->assertSame($row['opens'], $opens, "{$row['name']}: " . ($row['why'] ?? json_encode($row)));
    }
}
