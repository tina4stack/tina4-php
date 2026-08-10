<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fail-closed runner for the audited Tina4 3.14 DotEnv contract.
 *
 * Every fixture case requires exactly one behavioural executor. Missing
 * executors fail; they are never skips or incomplete tests.
 */
final class DotEnvContract314Test extends TestCase
{
    /** @return array<string,mixed> */
    private static function contract(): array
    {
        $decoded = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/dotenv_corpus.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return $decoded['contract_3_14'];
    }

    /**
     * Implementation work registers one real-filesystem/process-environment
     * executor per case. Empty now means the completed audit turns the suite
     * red until Feature 1 is implemented.
     *
     * @return array<string,callable(array<string,mixed>):void>
     */
    private static function executors(): array
    {
        return [];
    }

    /** @return iterable<string,array{0:array<string,mixed>}> */
    public static function contractCases(): iterable
    {
        foreach (self::contract()['cases'] as $case) {
            yield $case['id'] => [$case];
        }
    }

    public function testContractCaseIdsAndWitnessesAreUnique(): void
    {
        $cases = self::contract()['cases'];
        $ids = array_column($cases, 'id');
        $witnesses = array_column($cases, 'witness');

        self::assertCount(46, $ids);
        self::assertCount(count($ids), array_unique($ids));
        self::assertCount(count($witnesses), array_unique($witnesses));
    }

    public function testExecutorRegistryHasNoUnknownCases(): void
    {
        $ids = array_flip(array_column(self::contract()['cases'], 'id'));
        foreach (array_keys(self::executors()) as $id) {
            self::assertArrayHasKey($id, $ids, "Unknown DotEnv contract executor {$id}");
        }
        self::addToAssertionCount(1);
    }

    /** @param array<string,mixed> $case */
    #[DataProvider('contractCases')]
    public function testDotEnvContract314(array $case): void
    {
        $id = $case['id'];
        $executor = self::executors()[$id] ?? null;
        self::assertIsCallable(
            $executor,
            "{$id}: contract_3_14 executor not implemented; witness={$case['witness']}"
        );
        $executor($case);
    }
}
