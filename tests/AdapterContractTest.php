<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;

/**
 * The adapter contract (feature 3 of the feature audit).
 *
 * `tests/fixtures/adapter_contract.json` is byte-identical in all four
 * frameworks. This is the RATCHET: it pins today's implemented count per
 * adapter, so the number can go UP but never down, and a new adapter cannot
 * ship at the old level.
 *
 * PHP is the reference for this row, and it earned that by measurement rather
 * than by reading: ten adapters, every one missing exactly the same three
 * methods. A contract that every implementation satisfies identically is what
 * having one produces - contrast Ruby, whose seven drivers sat at three
 * different levels because it had no interface at all.
 */
class AdapterContractTest extends TestCase
{
    /** Measured 2026-07-30. Raise when you implement; never lower. */
    // 17 -> 18: autocommit landed via AutocommitTrait (feature 3).
    // getDatabaseType() also landed - the prerequisite for createTable/addColumn -
    // but it is not one of the 20 contract methods, so the floor is unchanged.
    private const FLOOR = 18;

    /** @var array<string, mixed> */
    private static array $contract;

    public static function setUpBeforeClass(): void
    {
        self::$contract = json_decode(
            file_get_contents(__DIR__ . '/fixtures/adapter_contract.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /** @return array<string, \ReflectionClass> */
    private function adapters(): array
    {
        $found = [];
        foreach (glob(__DIR__ . '/../Tina4/Database/*Adapter.php') as $file) {
            $class = 'Tina4\\Database\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            $rc = new ReflectionClass($class);
            if ($rc->isInterface() || $rc->isAbstract()) {
                continue;
            }
            $found[$rc->getShortName()] = $rc;
        }
        return $found;
    }

    private function implementedCount(ReflectionClass $rc): int
    {
        $count = 0;
        foreach (self::$contract['methods'] as $method) {
            if ($rc->hasMethod($method['name'])) {
                $count++;
            }
        }
        return $count;
    }

    public function testTheFixtureDeclaresTwentyMethods(): void
    {
        $this->assertCount(20, self::$contract['methods']);
    }

    public function testEveryAdapterMeetsItsRecordedFloor(): void
    {
        $below = [];
        foreach ($this->adapters() as $name => $rc) {
            $n = $this->implementedCount($rc);
            if ($n < self::FLOOR) {
                $below[] = "{$name}: {$n}/20, floor is " . self::FLOOR;
            }
        }
        $this->assertSame([], $below, "adapters dropped below the floor:\n" . implode("\n", $below));
    }

    /**
     * Consistency is the property having a declared interface buys, and it is
     * the reason PHP is this row's reference. If this starts failing, an adapter
     * has drifted from the others and the interface has stopped doing its job.
     */
    public function testEveryAdapterSitsAtTheSameLevel(): void
    {
        $levels = [];
        foreach ($this->adapters() as $name => $rc) {
            $levels[$name] = $this->implementedCount($rc);
        }
        $this->assertNotEmpty($levels, 'no adapters were found to check');
        $this->assertCount(
            1,
            array_unique($levels),
            'adapters have drifted apart: ' . json_encode($levels)
        );
    }

    /**
     * The gap this row exists to close, pinned so it cannot widen. Every adapter
     * is missing exactly these, and they are missing from three of the four
     * frameworks - the autocommit contract in particular is already agreed
     * behaviour and enforced in one framework only.
     */
    public function testTheKnownGapIsExactlyTheseThree(): void
    {
        $rc = new ReflectionClass(\Tina4\Database\SQLite3Adapter::class);
        $missing = [];
        foreach (self::$contract['methods'] as $method) {
            if (!$rc->hasMethod($method['name'])) {
                $missing[] = $method['name'];
            }
        }
        sort($missing);
        $this->assertSame(['addColumn', 'createTable'], $missing);
    }
}
