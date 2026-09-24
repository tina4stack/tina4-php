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
 * tina4-python#133 parity: a statement that WRITES and returns rows
 * (INSERT/UPDATE/DELETE ... RETURNING, MSSQL's OUTPUT) goes through fetchOne()
 * naturally, because the caller wants the new id. Python ended every
 * fetch_one() outside a transaction with a ROLLBACK, so the caller got the id
 * of a row that no longer existed.
 *
 * The contract proven here, on REAL engines: with autocommit on (the default)
 * and outside startTransaction(), a write through fetchOne() is durable like
 * execute(), and it is applied EXACTLY ONCE. Every check reads the table back
 * through a SECOND, fresh connection, so a write that only the writer can see
 * (uncommitted) fails the assertion.
 *
 * PHP never issued that rollback. What the PHP probe found instead was
 * SQLite3Adapter running every write that reached query() TWICE: ext-sqlite3's
 * SQLite3Stmt::execute() steps the statement and resets it, and the first
 * fetchArray() steps it again. fetchOne() of an INSERT ... RETURNING therefore
 * inserted two rows, and fetchOne() of an UPDATE applied it twice.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\PostgresAdapter;

require_once __DIR__ . '/PgTestEnv.php';

class Issue133FetchWriteCommitsTest extends TestCase
{
    private const TABLE = 'issue133_php_note';

    /** @var list<Database> connections opened by a test, closed in tearDown */
    private array $opened = [];

    /** @var array{0: string, 1: string}|null engine + URL of the table to drop */
    private ?array $cleanup = null;

    protected function tearDown(): void
    {
        // Close every connection BEFORE the drop: Firebird refuses to drop a
        // table another open attachment has touched ("object in use").
        foreach ($this->opened as $db) {
            try {
                $db->close();
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
                // the table may never have been created; nothing to drop
            }
        }
        $this->cleanup = null;
    }

    /**
     * Engines with a row-returning write, each with its own DDL and the
     * statement spellings it accepts.
     *
     * @return array<string, array{0: string}>
     */
    public static function engines(): array
    {
        return [
            'postgres' => ['postgres'],
            'sqlite' => ['sqlite'],
            'firebird' => ['firebird'],
            'mssql' => ['mssql'],
        ];
    }

    /**
     * Resolve the live URL for an engine, or skip loudly naming what to set.
     */
    private function urlFor(string $engine): string
    {
        switch ($engine) {
            case 'postgres':
                $pg = PgTestEnv::resolve();
                if (!$pg->reachable()) {
                    $this->markTestSkipped("[needs:postgres] PostgreSQL not reachable at {$pg->host}:{$pg->port} — set TINA4_TEST_PG_URL");
                }
                return "postgres://{$pg->user}:{$pg->pass}@{$pg->host}:{$pg->port}/tina4_php";
            case 'sqlite':
                // Four slashes: an ABSOLUTE path under the temp dir.
                return 'sqlite:///' . sys_get_temp_dir() . '/issue133_php_' . getmypid() . '.db';
            case 'firebird':
                $url = getenv('TINA4_TEST_FIREBIRD_URL');
                if ($url === false || $url === '') {
                    $this->markTestSkipped('[needs:firebird] Firebird not reachable — set TINA4_TEST_FIREBIRD_URL to run the live leg');
                }
                return $url;
            case 'mssql':
                $url = getenv('TINA4_TEST_MSSQL_URL');
                if ($url === false || $url === '') {
                    $this->markTestSkipped('[needs:mssql] MSSQL not reachable — set TINA4_TEST_MSSQL_URL to run the live leg');
                }
                return $url;
        }
        throw new \LogicException("unknown engine {$engine}");
    }

    private function open(string $url): Database
    {
        $db = Database::create($url);
        $this->opened[] = $db;
        return $db;
    }

    /** Create the probe table on a clean slate and return the writer connection. */
    private function writer(string $engine): Database
    {
        $url = $this->urlFor($engine);
        $db = $this->open($url);
        try {
            $db->execute('DROP TABLE ' . self::TABLE);
        } catch (\Throwable) {
        }
        $db->execute(match ($engine) {
            'postgres' => 'CREATE TABLE ' . self::TABLE . ' (id serial PRIMARY KEY, txt varchar(100) NOT NULL, hits integer DEFAULT 0 NOT NULL)',
            'sqlite' => 'CREATE TABLE ' . self::TABLE . ' (id integer PRIMARY KEY AUTOINCREMENT, txt varchar(100) NOT NULL, hits integer DEFAULT 0 NOT NULL)',
            'firebird' => 'CREATE TABLE ' . self::TABLE . ' (id integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, txt varchar(100) NOT NULL, hits integer DEFAULT 0 NOT NULL)',
            'mssql' => 'CREATE TABLE ' . self::TABLE . ' (id int IDENTITY PRIMARY KEY, txt varchar(100) NOT NULL, hits int DEFAULT 0 NOT NULL)',
        });
        $this->cleanup = [$engine, $url];
        return $db;
    }

    /** Every row of the probe table as seen by a BRAND-NEW connection. */
    private function freshRows(string $engine): array
    {
        $fresh = $this->open($this->urlFor($engine));
        return $fresh->fetchAll('SELECT id, txt, hits FROM ' . self::TABLE . ' ORDER BY id', [], 0, 0, true);
    }

    private function insertReturning(string $engine): string
    {
        return $engine === 'mssql'
            ? 'INSERT INTO ' . self::TABLE . ' (txt) OUTPUT INSERTED.id VALUES (?)'
            : 'INSERT INTO ' . self::TABLE . ' (txt) VALUES (?) RETURNING id';
    }

    #[DataProvider('engines')]
    public function testFetchOneInsertReturningIsCommittedExactlyOnce(string $engine): void
    {
        $db = $this->writer($engine);

        $row = $db->fetchOne($this->insertReturning($engine), ['written with fetchOne']);

        $this->assertIsArray($row, 'fetchOne must hand back the RETURNING row');
        $rows = $this->freshRows($engine);
        $this->assertSame(['written with fetchOne'], array_column($rows, 'txt'),
            "{$engine}: a second connection must see exactly the ONE row fetchOne() inserted");
        $this->assertEquals($rows[0]['id'], $row['id'],
            "{$engine}: the id fetchOne() returned must be the id of the row that was kept");
    }

    #[DataProvider('engines')]
    public function testFetchOneUpdateIsCommittedExactlyOnce(string $engine): void
    {
        $db = $this->writer($engine);
        $db->execute('INSERT INTO ' . self::TABLE . ' (txt) VALUES (?)', ['counter']);

        $sql = $engine === 'mssql'
            ? 'UPDATE ' . self::TABLE . ' SET hits = hits + 1 OUTPUT INSERTED.hits WHERE txt = ?'
            : 'UPDATE ' . self::TABLE . ' SET hits = hits + 1 WHERE txt = ? RETURNING hits';
        $row = $db->fetchOne($sql, ['counter']);

        $this->assertEquals(1, $row['hits'] ?? null, "{$engine}: the RETURNING row reports ONE increment");
        $this->assertEquals(1, $this->freshRows($engine)[0]['hits'],
            "{$engine}: a second connection must see the increment applied exactly once");
    }

    #[DataProvider('engines')]
    public function testFetchOneDeleteReturningIsCommitted(string $engine): void
    {
        $db = $this->writer($engine);
        $db->execute('INSERT INTO ' . self::TABLE . ' (txt) VALUES (?)', ['doomed']);
        $db->execute('INSERT INTO ' . self::TABLE . ' (txt) VALUES (?)', ['kept']);

        $sql = $engine === 'mssql'
            ? 'DELETE FROM ' . self::TABLE . ' OUTPUT DELETED.txt WHERE txt = ?'
            : 'DELETE FROM ' . self::TABLE . ' WHERE txt = ? RETURNING txt';
        $row = $db->fetchOne($sql, ['doomed']);

        $this->assertSame('doomed', $row['txt'] ?? null);
        $this->assertSame(['kept'], array_column($this->freshRows($engine), 'txt'),
            "{$engine}: the delete through fetchOne() must be visible to a second connection");
    }

    /** SQLite: a plain write (no RETURNING) routed through fetchOne() runs once too. */
    public function testSqliteFetchOnePlainWriteRunsOnce(): void
    {
        $db = $this->writer('sqlite');

        $this->assertNull($db->fetchOne('INSERT INTO ' . self::TABLE . ' (txt) VALUES (?)', ['plain']));
        $db->fetchOne('UPDATE ' . self::TABLE . ' SET hits = hits + 1 WHERE txt = ?', ['plain']);

        $rows = $this->freshRows('sqlite');
        $this->assertCount(1, $rows, 'a plain INSERT through fetchOne() must insert ONE row');
        $this->assertEquals(1, $rows[0]['hits'], 'a plain UPDATE through fetchOne() must apply ONCE');
    }

    /** SQLite: a RETURNING write that FAILS leaves nothing behind and raises. */
    public function testSqliteFetchOneFailingReturningWriteRaisesAndLeavesNoRow(): void
    {
        $db = $this->writer('sqlite');
        $db->execute('INSERT INTO ' . self::TABLE . ' (id, txt) VALUES (?, ?)', [7, 'first']);

        try {
            $db->fetchOne('INSERT INTO ' . self::TABLE . ' (id, txt) VALUES (?, ?) RETURNING id', [7, 'clash']);
            $this->fail('a primary-key clash through fetchOne() must raise');
        } catch (\Tina4\Database\DatabaseException $e) {
            $this->assertStringContainsStringIgnoringCase('constraint', $e->getMessage());
        }
        $this->assertSame(['first'], array_column($this->freshRows('sqlite'), 'txt'));

        // The connection is still usable afterwards (no savepoint left open).
        $row = $db->fetchOne('INSERT INTO ' . self::TABLE . ' (txt) VALUES (?) RETURNING id', ['after']);
        $this->assertIsArray($row);
        $this->assertSame(['first', 'after'], array_column($this->freshRows('sqlite'), 'txt'));
    }

    /** Negative control: inside an explicit transaction, rollback() still discards the write. */
    #[DataProvider('engines')]
    public function testFetchOneWriteInsideExplicitTransactionHonoursRollback(string $engine): void
    {
        $db = $this->writer($engine);

        $db->startTransaction();
        $row = $db->fetchOne($this->insertReturning($engine), ['rolled back']);
        $this->assertIsArray($row);
        $db->rollback();

        $this->assertSame([], $this->freshRows($engine),
            "{$engine}: a write inside startTransaction() must be undone by rollback()");
    }

    /** PostgreSQL: neither a read nor a write through fetchOne() leaves the connection idle in transaction. */
    public function testPostgresFetchOneLeavesNoOpenTransaction(): void
    {
        $db = $this->writer('postgres');
        $adapter = $db->getAdapter();
        if (!$adapter instanceof PostgresAdapter) {
            $this->markTestSkipped('[needs:postgres] ext-pgsql not loaded — the native PostgresAdapter leg is UNVERIFIED here');
        }

        $db->fetchOne('SELECT count(*) AS n FROM ' . self::TABLE);
        $this->assertSame(PGSQL_TRANSACTION_IDLE, pg_transaction_status($adapter->getConnection()), 'after a read');

        $db->fetchOne($this->insertReturning('postgres'), ['durable']);
        $this->assertSame(PGSQL_TRANSACTION_IDLE, pg_transaction_status($adapter->getConnection()), 'after a write');
        $this->assertSame(['durable'], array_column($this->freshRows('postgres'), 'txt'));
    }
}
