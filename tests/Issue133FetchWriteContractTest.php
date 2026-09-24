<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
 declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 *
 * The cross-framework write-through-fetch contract (tina4-python#133 follow-up,
 * the Python master's DatabaseAdapter._is_write_statement):
 *
 *   A statement is a WRITE when, after literals, comments and leading
 *   whitespace/brackets are removed, its first word is INSERT, UPDATE, DELETE,
 *   MERGE, UPSERT or REPLACE - or it starts with WITH and its body holds
 *   INSERT, UPDATE, DELETE or MERGE. A write through fetch()/fetchOne() runs
 *   EXACTLY ONCE, with no COUNT probe and no LIMIT/OFFSET/ROWS/TOP pagination,
 *   is never served from or stored in the query cache, and commits like
 *   execute().
 *
 * Before this, fetch() of any write raised on every engine: the COUNT probe
 * wrapped the INSERT in a subquery and the pagination clause was appended to
 * it. Every leg here runs the adapter against its REAL engine and reads the
 * table back through a SECOND, fresh connection, so "exactly one row" is what
 * the database holds, not what the writer believes.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\Database\CachedDatabase;
use Tina4\Database\Database;
use Tina4\Database\DatabaseAdapter;
use Tina4\Database\ODBCAdapter;
use Tina4\Database\PdoPostgresAdapter;
use Tina4\Database\PdoSqliteAdapter;
use Tina4\Database\SQLite3Adapter;
use Tina4\Database\SqlStatement;

class Issue133FetchWriteContractTest extends TestCase
{
    private const TABLE = 'issue133_php_contract';

    /** @var list<object> adapters and connections to close in tearDown */
    private array $opened = [];

    /** @var array{0: string, 1: string}|null [dialect, fresh URL] of the table to drop */
    private ?array $cleanup = null;

    protected function tearDown(): void
    {
        foreach ($this->opened as $connection) {
            try {
                $connection->close();
            } catch (\Throwable) {
            }
        }
        $this->opened = [];
        if ($this->cleanup !== null) {
            try {
                $db = Database::create($this->cleanup[1]);
                $db->execute('DROP TABLE ' . self::TABLE);
                $db->close();
            } catch (\Throwable) {
            }
        }
        $this->cleanup = null;
    }

    // ── the classifier (a pure function: no dependency, no double) ─────────

    /** @return array<string, array{0: string, 1: bool}> */
    public static function statements(): array
    {
        return [
            'insert' => ['INSERT INTO t (a) VALUES (1) RETURNING id', true],
            'lower-case update' => ['update t set a = 1', true],
            'delete' => ['DELETE FROM t WHERE id = 1 RETURNING id', true],
            'merge' => ['MERGE INTO t USING s ON t.id = s.id WHEN MATCHED THEN UPDATE SET a = 1', true],
            'upsert' => ['UPSERT INTO t (a) VALUES (1)', true],
            'replace' => ['REPLACE INTO t (a) VALUES (1)', true],
            'leading whitespace and brackets' => ["  \n ((INSERT INTO t (a) VALUES (1)))", true],
            'leading comments' => ["-- note\n/* why */ UPDATE t SET a = 1", true],
            'data-modifying CTE' => ['WITH x AS (INSERT INTO t (a) VALUES (1) RETURNING id) SELECT id FROM x', true],
            'plain select' => ['SELECT * FROM t', false],
            'verb only in a literal' => ["SELECT * FROM t WHERE note = 'DELETE'", false],
            'verb only in a comment' => ["SELECT * FROM t -- UPDATE later", false],
            'read-only CTE' => ['WITH x AS (SELECT 1 AS a) SELECT a FROM x', false],
            'CTE with the verb in a literal' => ["WITH x AS (SELECT 'INSERT' AS a) SELECT a FROM x", false],
            'column named updated_at' => ['SELECT updated_at FROM t', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('statements')]
    public function testTheWriteClassifierFollowsTheContract(string $sql, bool $isWrite): void
    {
        $this->assertSame($isWrite, SqlStatement::isWrite($sql), $sql);
    }

    // ── every adapter, against its real engine ─────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function adapters(): array
    {
        return [
            'PostgresAdapter (ext-pgsql)' => ['pg-native'],
            'PdoPostgresAdapter' => ['pg-pdo'],
            'ODBCAdapter (PostgreSQL driver)' => ['odbc'],
            'SQLite3Adapter' => ['sqlite3'],
            'PdoSqliteAdapter' => ['sqlite-pdo'],
            'FirebirdAdapter (ext-interbase)' => ['firebird-native'],
            'PdoFirebirdAdapter' => ['firebird-pdo'],
            'MSSQLAdapter' => ['mssql'],
            'MySQLAdapter' => ['mysql'],
        ];
    }

    private function env(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            $engine = match ($name) {
                'TINA4_TEST_FIREBIRD_URL' => 'firebird',
                'TINA4_TEST_MSSQL_URL' => 'mssql',
                'TINA4_TEST_MYSQL_URL' => 'mysql',
                default => 'runtime=odbc',
            };
            $this->markTestSkipped("[needs:{$engine}] {$name} not set — this engine's leg is not reachable here");
        }
        return $value;
    }

    private function pgUrl(): string
    {
        $pg = PgTestEnv::resolve();
        if (!$pg->reachable()) {
            $this->markTestSkipped("[needs:postgres] PostgreSQL not reachable at {$pg->host}:{$pg->port}");
        }
        return "postgres://{$pg->user}:{$pg->pass}@{$pg->host}:{$pg->port}/tina4_php";
    }

    private function sqlitePath(): string
    {
        return sys_get_temp_dir() . '/issue133_php_contract_' . getmypid() . '.db';
    }

    /**
     * The adapter under test, plus the dialect and a URL for the fresh reader.
     *
     * @return array{0: DatabaseAdapter, 1: string, 2: string}
     */
    private function adapterFor(string $leg): array
    {
        switch ($leg) {
            case 'pg-native':
                $url = $this->pgUrl();
                return [$this->keep(Database::create($url))->getAdapter(), 'postgres', $url];
            case 'pg-pdo':
                if (!in_array('pgsql', \PDO::getAvailableDrivers(), true)) {
                    $this->markTestSkipped('[needs:postgres] pdo_pgsql not loaded');
                }
                $pg = PgTestEnv::resolve();
                $url = $this->pgUrl();
                return [$this->keep(new PdoPostgresAdapter("postgres://{$pg->host}:{$pg->port}/tina4_php", null, $pg->user, $pg->pass)), 'postgres', $url];
            case 'odbc':
                $dsn = $this->env('TINA4_TEST_ODBC_DSN');
                if (!function_exists('odbc_connect')) {
                    $this->markTestSkipped('[needs:runtime=odbc] ext-odbc not loaded');
                }
                return [$this->keep(new ODBCAdapter($dsn)), 'postgres', 'odbc:///' . $dsn];
            case 'sqlite3':
                return [$this->keep(new SQLite3Adapter($this->sqlitePath())), 'sqlite', 'sqlite:///' . $this->sqlitePath()];
            case 'sqlite-pdo':
                return [$this->keep(new PdoSqliteAdapter($this->sqlitePath())), 'sqlite', 'sqlite:///' . $this->sqlitePath()];
            case 'firebird-native':
            case 'firebird-pdo':
                $url = $this->env('TINA4_TEST_FIREBIRD_URL');
                $driver = $leg === 'firebird-pdo' ? 'pdo' : 'interbase';
                $forced = $url . (str_contains($url, '?') ? '&' : '?') . 'driver=' . $driver;
                return [$this->keep(Database::create($forced))->getAdapter(), 'firebird', $url];
            case 'mssql':
                $url = $this->env('TINA4_TEST_MSSQL_URL');
                return [$this->keep(Database::create($url))->getAdapter(), 'mssql', $url];
            case 'mysql':
                $url = $this->env('TINA4_TEST_MYSQL_URL');
                return [$this->keep(Database::create($url))->getAdapter(), 'mysql', $url];
        }
        throw new \LogicException("unknown leg {$leg}");
    }

    private function keep(object $connection): object
    {
        $this->opened[] = $connection;
        return $connection;
    }

    /** Recreate the probe table through a separate connection. */
    private function freshTable(string $dialect, string $url): void
    {
        $db = $this->keep(Database::create($url));
        try {
            $db->execute('DROP TABLE ' . self::TABLE);
        } catch (\Throwable) {
        }
        $db->execute(match ($dialect) {
            'postgres' => 'CREATE TABLE ' . self::TABLE . ' (id serial PRIMARY KEY, txt varchar(100) NOT NULL, hits integer DEFAULT 0 NOT NULL)',
            'sqlite' => 'CREATE TABLE ' . self::TABLE . ' (id integer PRIMARY KEY AUTOINCREMENT, txt varchar(100) NOT NULL, hits integer DEFAULT 0 NOT NULL)',
            'firebird' => 'CREATE TABLE ' . self::TABLE . ' (id integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, txt varchar(100) NOT NULL, hits integer DEFAULT 0 NOT NULL)',
            'mssql' => 'CREATE TABLE ' . self::TABLE . ' (id int IDENTITY PRIMARY KEY, txt varchar(100) NOT NULL, hits int DEFAULT 0 NOT NULL)',
            'mysql' => 'CREATE TABLE ' . self::TABLE . ' (id int AUTO_INCREMENT PRIMARY KEY, txt varchar(100) NOT NULL, hits int DEFAULT 0 NOT NULL)',
        });
        $db->close();
        $this->cleanup = [$dialect, $url];
    }

    /** The rows as a BRAND-NEW connection sees them. */
    private function freshRows(string $url): array
    {
        $db = $this->keep(Database::create($url));
        return $db->fetchAll('SELECT txt, hits FROM ' . self::TABLE . ' ORDER BY id', [], 0, 0, true);
    }

    /** INSERT that hands back the new id, in the dialect's spelling (MySQL has none). */
    private static function insertReturning(string $dialect): string
    {
        return match ($dialect) {
            'mssql' => 'INSERT INTO ' . self::TABLE . ' (txt) OUTPUT INSERTED.id VALUES (?)',
            'mysql' => 'INSERT INTO ' . self::TABLE . ' (txt) VALUES (?)',
            default => 'INSERT INTO ' . self::TABLE . ' (txt) VALUES (?) RETURNING id',
        };
    }

    #[DataProvider('adapters')]
    public function testFetchOfAWriteRunsOnceAndCommits(string $leg): void
    {
        [$adapter, $dialect, $url] = $this->adapterFor($leg);
        $this->freshTable($dialect, $url);

        $result = $adapter->fetch(self::insertReturning($dialect), ['via fetch'], 10, 0);

        $expectedRows = $dialect === 'mysql' ? 0 : 1;
        $this->assertCount($expectedRows, $result['data'], "{$leg}: fetch() hands back the RETURNING row");
        $this->assertSame($expectedRows, $result['total'], "{$leg}: total is the rows the write returned, not a COUNT probe");
        $this->assertSame(['via fetch'], array_column($this->freshRows($url), 'txt'),
            "{$leg}: a second connection must see EXACTLY one row");
    }

    #[DataProvider('adapters')]
    public function testFetchOneOfAWriteRunsOnceWithNoPaginationAndCommits(string $leg): void
    {
        [$adapter, $dialect, $url] = $this->adapterFor($leg);
        $this->freshTable($dialect, $url);

        $row = $adapter->fetchOne(self::insertReturning($dialect), ['via fetchOne']);

        if ($dialect !== 'mysql') {
            $this->assertIsArray($row, "{$leg}: fetchOne() hands back the RETURNING row (no ROWS/TOP/LIMIT appended)");
        }
        $this->assertSame(['via fetchOne'], array_column($this->freshRows($url), 'txt'),
            "{$leg}: a second connection must see EXACTLY one row");
    }

    /** No LIMIT/ROWS/TOP on a write: an UPDATE through fetch(limit: 1) still updates EVERY matching row, once. */
    #[DataProvider('adapters')]
    public function testFetchOfAnUpdateIsNotPaginatedAndAppliesOnce(string $leg): void
    {
        [$adapter, $dialect, $url] = $this->adapterFor($leg);
        $this->freshTable($dialect, $url);
        $writer = $this->keep(Database::create($url));
        foreach (['a', 'b', 'c'] as $txt) {
            $writer->execute('INSERT INTO ' . self::TABLE . ' (txt) VALUES (?)', [$txt]);
        }
        $writer->close();

        $sql = match ($dialect) {
            'mssql' => 'UPDATE ' . self::TABLE . ' SET hits = hits + 1 OUTPUT INSERTED.hits',
            'mysql' => 'UPDATE ' . self::TABLE . ' SET hits = hits + 1',
            default => 'UPDATE ' . self::TABLE . ' SET hits = hits + 1 RETURNING hits',
        };
        $adapter->fetch($sql, [], 1, 0);

        $this->assertSame([1, 1, 1], array_map('intval', array_column($this->freshRows($url), 'hits')),
            "{$leg}: every row updated exactly once — a paginated or double-run write would not give 1,1,1");
    }

    /** A data-modifying CTE is a write too. */
    public function testFetchOfAPostgresDataModifyingCteRunsOnce(): void
    {
        [$adapter, $dialect, $url] = $this->adapterFor('pg-native');
        $this->freshTable($dialect, $url);

        $result = $adapter->fetch(
            'WITH added AS (INSERT INTO ' . self::TABLE . ' (txt) VALUES (?) RETURNING id) SELECT id FROM added',
            ['cte'],
            10,
            0
        );

        $this->assertCount(1, $result['data']);
        $this->assertSame(['cte'], array_column($this->freshRows($url), 'txt'));
    }

    // ── the query cache ────────────────────────────────────────────────────

    /** A write through the cache runs every time and flushes what the cache held. */
    public function testTheQueryCacheNeverServesOrKeepsAWrite(): void
    {
        $path = $this->sqlitePath();
        $url = 'sqlite:///' . $path;
        $this->freshTable('sqlite', $url);
        $cached = $this->keep(new CachedDatabase(new SQLite3Adapter($path), enabled: true, ttl: 60));

        $this->assertSame(0, (int) $cached->fetchOne('SELECT count(*) AS n FROM ' . self::TABLE)['n']);

        $first = $cached->fetchOne('INSERT INTO ' . self::TABLE . ' (txt) VALUES (?) RETURNING id', ['same']);
        $second = $cached->fetchOne('INSERT INTO ' . self::TABLE . ' (txt) VALUES (?) RETURNING id', ['same']);
        $cached->fetch('INSERT INTO ' . self::TABLE . ' (txt) VALUES (?) RETURNING id', ['same'], 10, 0);
        $cached->fetch('INSERT INTO ' . self::TABLE . ' (txt) VALUES (?) RETURNING id', ['same'], 10, 0);

        $this->assertNotEquals($first['id'], $second['id'], 'the second identical write must RUN, not be served from the cache');
        $this->assertCount(4, $this->freshRows($url), 'four identical writes, four rows');
        $this->assertSame(4, (int) $cached->fetchOne('SELECT count(*) AS n FROM ' . self::TABLE)['n'],
            'the cached pre-write count must have been flushed by the write');
    }
}
