<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\SQLTranslator;

/**
 * The batch-write contract (feature 3 of the feature audit).
 *
 * `tests/fixtures/batch_write_contract.json` is byte-identical in all four
 * frameworks and is the shared answer key: the same cases, the same engine
 * parameter caps, the same rejections, checked identically in python, php, ruby
 * and node.
 *
 * A batch that loops one INSERT per row pays a full network round-trip per row.
 * Measured over 500 rows on the .99 host: PostgreSQL 9848ms row-at-a-time
 * against 15.8ms as one multi-row VALUES (625x), MySQL 216x, MSSQL 121x.
 *
 * The chunking rules are PURE, so they are checked here without a database. The
 * live-engine half of the contract lives in the write-path runner, which proves
 * the rows actually land — a faster batch that writes the wrong rows is a bug,
 * not an optimisation.
 */
class BatchWriteContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $contract;

    public static function setUpBeforeClass(): void
    {
        self::$contract = json_decode(
            file_get_contents(__DIR__ . '/fixtures/batch_write_contract.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /** @return array<int, array<int, mixed>> */
    private function rows(int $count, int $columns): array
    {
        $rows = [];
        for ($r = 0; $r < $count; $r++) {
            $row = [];
            for ($c = 0; $c < $columns; $c++) {
                $row[] = "v{$r}c{$c}";
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return array<int, array{0: string}> */
    public static function caseProvider(): array
    {
        $contract = json_decode(
            file_get_contents(__DIR__ . '/fixtures/batch_write_contract.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $out = [];
        foreach ($contract['cases'] as $case) {
            $out[$case['name']] = [$case['name']];
        }
        return $out;
    }

    /**
     * Every case in the shared fixture, checked against the same answer key.
     *
     * @dataProvider caseProvider
     */
    public function testCaseFromTheSharedFixture(string $name): void
    {
        $case = null;
        foreach (self::$contract['cases'] as $c) {
            if ($c['name'] === $name) {
                $case = $c;
                break;
            }
        }
        $this->assertNotNull($case, "case {$name} vanished from the fixture");

        $statements = SQLTranslator::buildBatchInserts(
            $case['sql'],
            $this->rows($case['rows'], $case['columns']),
            $case['engine']
        );

        $this->assertCount(
            $case['expect']['statements'],
            $statements,
            "{$name}: wrong statement count"
        );

        if ($case['expect']['statements'] === 0) {
            return;
        }

        [$firstSql, $firstParams] = $statements[0];
        $this->assertSame(
            $case['expect']['rows_in_first'],
            substr_count($firstSql, '(') - 1,
            "{$name}: first statement should carry {$case['expect']['rows_in_first']} row tuples"
        );
        $this->assertCount($case['expect']['params_in_first'], $firstParams);
        $this->assertSame($case['expect']['params_in_first'], substr_count($firstSql, '?'));
    }

    /**
     * The caps are a real engine limit, not a tunable. MSSQL's 2100 is the
     * tightest and is what makes chunking mandatory. A drift here would emit a
     * statement a live engine rejects outright.
     */
    public function testTheEngineCapsMatchTheSharedFixture(): void
    {
        foreach (self::$contract['max_bind_params'] as $engine => $cap) {
            if (str_starts_with($engine, '_')) {
                continue;
            }
            $this->assertSame(
                $cap,
                SQLTranslator::MAX_BIND_PARAMS[$engine] ?? null,
                "{$engine} cap drifted from the shared fixture"
            );
        }
    }

    /**
     * The property the caps exist for, across every engine and width. Exceeding
     * the cap is a hard error on a real engine, so this is checked exhaustively
     * rather than at the two boundaries the fixture names.
     */
    public function testNoChunkEverExceedsTheEngineCap(): void
    {
        foreach (SQLTranslator::MAX_BIND_PARAMS as $engine => $cap) {
            if ($cap <= 0) {
                continue;
            }
            for ($columns = 1; $columns <= 11; $columns++) {
                $cols = [];
                for ($i = 0; $i < $columns; $i++) {
                    $cols[] = 'c' . $i;
                }
                $sql = 'INSERT INTO t (' . implode(', ', $cols) . ') VALUES ('
                    . implode(', ', array_fill(0, $columns, '?')) . ')';
                foreach (SQLTranslator::buildBatchInserts($sql, $this->rows(1500, $columns), $engine) as [$_s, $params]) {
                    $this->assertLessThanOrEqual(
                        $cap,
                        count($params),
                        "{$engine}/{$columns}col chunk exceeded the cap"
                    );
                }
            }
        }
    }

    /**
     * Rows must be written in the order supplied, with nothing dropped. Chunk
     * boundaries are the risk: a batch spanning several statements must still
     * flatten to exactly the original sequence of values.
     */
    public function testEveryRowAndValueSurvivesTheCollapseInOrder(): void
    {
        $rows = $this->rows(701, 3);
        $statements = SQLTranslator::buildBatchInserts(
            'INSERT INTO t (a, b, c) VALUES (?, ?, ?)',
            $rows,
            'mssql'
        );
        $this->assertGreaterThan(1, count($statements), 'this case is only meaningful when it chunks');

        $flat = [];
        foreach ($statements as [$_sql, $params]) {
            foreach ($params as $value) {
                $flat[] = $value;
            }
        }
        $expected = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $expected[] = $value;
            }
        }
        $this->assertSame($expected, $flat);
    }

    /**
     * Each rejection in the shared fixture, proven. Returning empty means "keep
     * looping", which is always correct — collapsing one of these would silently
     * change what the batch writes.
     */
    public function testNegativeTheFixturesRejectedShapesNeverCollapse(): void
    {
        $samples = [
            'RETURNING' => ['INSERT INTO t (a) VALUES (?) RETURNING *', 1, 10],
            'ON CONFLICT' => ['INSERT INTO t (a) VALUES (?) ON CONFLICT (a) DO NOTHING', 1, 10],
            'ON DUPLICATE KEY' => ['INSERT INTO t (a) VALUES (?) ON DUPLICATE KEY UPDATE a = a', 1, 10],
            'not_an_insert' => ['UPDATE t SET a = ? WHERE b = ?', 2, 10],
            'literal_in_values' => ['INSERT INTO t (a, b) VALUES (?, now())', 1, 10],
            'single_row' => ['INSERT INTO t (a) VALUES (?)', 1, 1],
        ];

        foreach (self::$contract['collapsible']['rejected'] as $reason => $_why) {
            if (str_starts_with($reason, '_')) {
                continue;
            }
            if ($reason === 'ragged_params') {
                $this->assertSame(
                    [],
                    SQLTranslator::buildBatchInserts('INSERT INTO t (a, b) VALUES (?, ?)', [['a', 'b'], ['c']], 'postgres'),
                    'ragged rows were collapsed'
                );
                continue;
            }
            [$sql, $columns, $count] = $samples[$reason];
            $this->assertSame(
                [],
                SQLTranslator::buildBatchInserts($sql, $this->rows($count, $columns), 'postgres'),
                "{$reason} was collapsed — it must fall back to the row-at-a-time loop"
            );
        }
    }

    /**
     * Firebird has no multi-row VALUES syntax. Collapsing there emits SQL the
     * engine cannot parse, trading a working slow path for a broken fast one.
     */
    public function testNegativeABatchIsNeverCollapsedOnFirebird(): void
    {
        foreach (['firebird', 'odbc', 'mongodb'] as $engine) {
            $this->assertSame(
                [],
                SQLTranslator::buildBatchInserts('INSERT INTO t (a, b, c) VALUES (?, ?, ?)', $this->rows(100, 3), $engine),
                "{$engine} must keep the row-at-a-time loop"
            );
        }
    }

    /** An engine with no recorded cap must not be assumed to have one. */
    public function testNegativeAnUnknownEngineFallsBackRatherThanGuessing(): void
    {
        $this->assertSame(
            [],
            SQLTranslator::buildBatchInserts('INSERT INTO t (a) VALUES (?)', $this->rows(10, 1), 'some_new_engine')
        );
    }

    /**
     * The drift that made this optimisation a no-op on PostgreSQL.
     *
     * PHP and Python report "postgresql" while Ruby and Node report "postgres".
     * Reading the cap table without normalising misses, the cap comes back 0,
     * and the batch silently keeps looping — on the engine with the largest
     * measured win (625x). A live run caught it; this pins it.
     */
    public function testAnEngineAliasResolvesToTheSameCapAsItsCanonicalName(): void
    {
        foreach (self::$contract['engine_aliases'] as $alias => $canonical) {
            if (str_starts_with($alias, '_')) {
                continue;
            }
            $this->assertSame($canonical, SQLTranslator::ENGINE_ALIASES[$alias] ?? null);

            $sql = 'INSERT INTO t (a, b, c) VALUES (?, ?, ?)';
            $rows = $this->rows(10, 3);
            $this->assertEquals(
                SQLTranslator::buildBatchInserts($sql, $rows, $canonical),
                SQLTranslator::buildBatchInserts($sql, $rows, $alias),
                "{$alias} did not resolve to {$canonical}"
            );
        }
    }

    /**
     * Regression: collapsing a batch silently changed lastId on MySQL.
     *
     * MySQL's LAST_INSERT_ID() reports the FIRST generated id of a multi-row
     * INSERT, not the last (verified live: a 3-row insert into a fresh table
     * reports 1 while MAX(id) is 3). A row-at-a-time batch reported the LAST
     * row's id simply because the last statement inserted the last row, so the
     * collapse quietly redefined the contract.
     */
    public function testACollapsedBatchStillReportsTheLastRowsIdOnMysql(): void
    {
        $this->assertSame(3, SQLTranslator::batchLastId(1, 3, 'mysql'));
        $this->assertSame(14, SQLTranslator::batchLastId(10, 5, 'mariadb'));
    }

    /**
     * Only the engines that need it are adjusted. SQLite, PostgreSQL and MSSQL
     * already report the LAST id — adding the offset there would push lastId
     * PAST the real row.
     */
    public function testNegativeEnginesThatAlreadyReportTheLastIdAreLeftAlone(): void
    {
        foreach (['sqlite', 'postgres', 'postgresql', 'mssql'] as $engine) {
            $this->assertSame(7, SQLTranslator::batchLastId(7, 3, $engine), $engine);
        }
    }

    /** first + 1 - 1 == first: a one-row chunk must be untouched everywhere. */
    public function testNegativeASingleRowChunkNeverShiftsTheId(): void
    {
        foreach (['mysql', 'sqlite', 'postgres', 'mssql'] as $engine) {
            $this->assertSame(42, SQLTranslator::batchLastId(42, 1, $engine), $engine);
        }
    }

    /** A UUID/ULID primary key has no arithmetic successor. */
    public function testNegativeANonNumericLastIdIsPassedThroughUnchanged(): void
    {
        $this->assertSame('018f-2b7c-uuid', SQLTranslator::batchLastId('018f-2b7c-uuid', 3, 'mysql'));
        $this->assertNull(SQLTranslator::batchLastId(null, 3, 'mysql'));
    }

    /**
     * The exact value PHP's own adapter returns. getDatabaseType() returns
     * "postgresql", so this is the spelling that actually reaches
     * buildBatchInserts in production. If it stops collapsing, every PostgreSQL
     * batch quietly reverts to one round-trip per row.
     */
    public function testNegativePostgresqlSpelledInFullStillCollapses(): void
    {
        $statements = SQLTranslator::buildBatchInserts(
            'INSERT INTO t (a, b, c) VALUES (?, ?, ?)',
            $this->rows(50, 3),
            'postgresql'
        );
        $this->assertCount(1, $statements, 'postgresql must collapse, not fall back to the loop');
        $this->assertCount(150, $statements[0][1]);
    }
}
