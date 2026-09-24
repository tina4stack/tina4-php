<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * fetch() reports the TRUE total for the filter, never the page length.
 *
 * Found in tina4-nodejs: on SQL Server and MySQL the COUNT probe lacked the
 * derived-table alias those engines require, and SQL Server rejects an ORDER
 * BY inside the count subquery (error 1033), so the probe failed and the total
 * fell back to the length of the page. The contract, on real engines: 25 rows,
 * a filter that keeps 22, a 10-row page - the total is 22, with and without a
 * trailing top-level ORDER BY.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

class FetchTotalContractTest extends TestCase
{
    private const TABLE = 'php_fetch_total_contract';

    private ?Database $db = null;

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            try {
                $this->db->execute('DROP TABLE ' . self::TABLE);
            } catch (\Throwable) {
            }
            $this->db->close();
            $this->db = null;
        }
    }

    /** @return array<string, array{0: string}> */
    public static function engines(): array
    {
        return ['SQL Server' => ['mssql'], 'MySQL' => ['mysql'], 'PostgreSQL' => ['postgres'], 'SQLite' => ['sqlite']];
    }

    /** @return array<string, array{0: string}> */
    public static function orderings(): array
    {
        return ['no ORDER BY' => [''], 'ORDER BY id' => [' ORDER BY id'], 'ORDER BY id DESC' => [' ORDER BY id DESC']];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function cases(): array
    {
        $cases = [];
        foreach (self::engines() as $engineLabel => [$engine]) {
            foreach (self::orderings() as $orderLabel => [$order]) {
                $cases["{$engineLabel}, {$orderLabel}"] = [$engine, $order];
            }
        }
        return $cases;
    }

    private function connect(string $engine): Database
    {
        $url = match ($engine) {
            'mssql' => getenv('TINA4_TEST_MSSQL_URL') ?: '',
            'mysql' => getenv('TINA4_TEST_MYSQL_URL') ?: '',
            'postgres' => (static function (): string {
                $pg = PgTestEnv::resolve();
                return $pg->reachable() ? "postgres://{$pg->user}:{$pg->pass}@{$pg->host}:{$pg->port}/tina4_php" : '';
            })(),
            'sqlite' => 'sqlite:///' . sys_get_temp_dir() . '/php_fetch_total_' . getmypid() . '.db',
        };
        if ($url === '') {
            $need = str_starts_with($engine, 'firebird') ? 'firebird' : ($engine === 'odbc' ? 'runtime=odbc' : $engine);
            $this->markTestSkipped("[needs:{$need}] {$engine} not reachable here — set its TINA4_TEST_*_URL");
        }
        return Database::create($url);
    }

    #[DataProvider('cases')]
    public function testTheTotalIsTheFilterCountNotThePageLength(string $engine, string $order): void
    {
        $this->db = $this->connect($engine);
        try {
            $this->db->execute('DROP TABLE ' . self::TABLE);
        } catch (\Throwable) {
        }
        $this->db->execute('CREATE TABLE ' . self::TABLE . ' (id integer NOT NULL PRIMARY KEY, grp varchar(10) NOT NULL)');
        for ($id = 1; $id <= 25; $id++) {
            $this->db->execute('INSERT INTO ' . self::TABLE . ' (id, grp) VALUES (?, ?)', [$id, $id <= 22 ? 'keep' : 'drop']);
        }

        $page = $this->db->fetch('SELECT id, grp FROM ' . self::TABLE . ' WHERE grp = ?' . $order, ['keep'], 10, 0);

        $this->assertCount(10, $page->records, "{$engine}: one 10-row page");
        $this->assertSame(22, $page->count, "{$engine}: the total is the 22 rows the filter keeps, not the page length");
        if ($order === ' ORDER BY id DESC') {
            $this->assertEquals(22, $page->records[0]['id'], "{$engine}: the page itself still honours the ORDER BY");
        }
    }
}
