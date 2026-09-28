<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 *
 * Characterization for the DB-adapter boilerplate dedup (plan/db-adapter-dedup.md).
 *
 * Pins the CURRENT behaviour of the two genuinely-duplicated boilerplate
 * clusters BEFORE they are extracted, so the extraction is proven byte-identical:
 *
 *   1. parseConnection() — the URL/host -> {host,port,username,password,database}
 *      parse. MySQL and MSSQL are identical except the default port; the two
 *      Firebird adapters share their own identical variant (host default '',
 *      TINA4_DATABASE_FIREBIRD_PATH override, path normalisation). Pinned by
 *      invoking the private method on an adapter built WITHOUT its constructor
 *      (every adapter opens its connection in __construct) with the connection
 *      fields set to known values — pure string parsing, no live engine.
 *   2. CrudSqlTrait insert/update/delete — every branch (single + batch insert,
 *      update with WHERE, delete by string / by assoc map / by list of maps)
 *      exercised against the REAL engines the lab provisions. No mocks.
 *
 * Real engines for the CRUD cases: SQLite always; MySQL/PostgreSQL/MSSQL/Firebird
 * when the matching TINA4_TEST_*_URL is set (as on the lab and CI db jobs).
 */

namespace Tina4\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\DatabaseAdapter;
use Tina4\Database\FirebirdAdapter;
use Tina4\Database\MSSQLAdapter;
use Tina4\Database\MySQLAdapter;
use Tina4\Database\PdoFirebirdAdapter;

class DbAdapterDedupCharacterizationTest extends TestCase
{
    /**
     * Build an adapter WITHOUT running its constructor (every adapter connects
     * in __construct) and set the connection fields to known values, so
     * parseConnection() can be exercised as the pure parser it is.
     *
     * @param class-string        $class
     * @param array<string,mixed> $props connection-field name => value
     */
    private function makeAdapter(string $class, array $props): object
    {
        $adapter = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        foreach ($props as $name => $value) {
            (new \ReflectionProperty($class, $name))->setValue($adapter, $value);
        }
        return $adapter;
    }

    /**
     * Invoke an adapter's private parseConnection() and return its array.
     *
     * @param string $input The connection string / host to parse
     * @return array<string,mixed>
     */
    private function parse(object $adapter, string $input): array
    {
        $method = new \ReflectionMethod($adapter, 'parseConnection');
        $method->setAccessible(true);
        return $method->invoke($adapter, $input);
    }

    private function mysql(int $port = 3306, string $user = 'cu', string $pass = 'cp', string $db = 'cdb'): object
    {
        return $this->makeAdapter(MySQLAdapter::class, [
            'connectionString' => 'mysql://x', 'username' => $user, 'password' => $pass, 'database' => $db, 'port' => $port,
        ]);
    }

    private function mssql(int $port = 1433, string $user = 'cu', string $pass = 'cp', string $db = 'cdb'): object
    {
        return $this->makeAdapter(MSSQLAdapter::class, [
            'connectionString' => 'mssql://x', 'username' => $user, 'password' => $pass, 'database' => $db, 'port' => $port,
        ]);
    }

    private function firebird(string $class = FirebirdAdapter::class): object
    {
        return $this->makeAdapter($class, [
            'connectionString' => 'firebird://x', 'username' => 'SYSDBA', 'password' => 'masterkey',
        ]);
    }

    // ── parseConnection: MySQL (default port 3306) ──────────────────────

    public function testMysqlParsesFullUrl(): void
    {
        $this->assertSame(
            ['host' => 'h', 'port' => 3307, 'username' => 'u', 'password' => 'p', 'database' => 'db'],
            $this->parse($this->mysql(), 'mysql://u:p@h:3307/db')
        );
    }

    public function testMysqlUrlWithoutPortUsesDefault3306(): void
    {
        $parsed = $this->parse($this->mysql(), 'mysql://h/db');
        $this->assertSame(3306, $parsed['port']);
        $this->assertSame('h', $parsed['host']);
        $this->assertSame('db', $parsed['database']);
    }

    public function testMysqlUrlDecodesCredentials(): void
    {
        $parsed = $this->parse($this->mysql(), 'mysql://u%40x:p%3Aw@h/db');
        $this->assertSame('u@x', $parsed['username']);
        $this->assertSame('p:w', $parsed['password']);
    }

    public function testMysqlBareHostUsesConstructorParams(): void
    {
        $this->assertSame(
            ['host' => 'somehost', 'port' => 3399, 'username' => 'cu', 'password' => 'cp', 'database' => 'cdb'],
            $this->parse($this->mysql(3399), 'somehost')
        );
    }

    public function testMysqlEmptyHostFallsBackToLocalhost(): void
    {
        $this->assertSame('localhost', $this->parse($this->mysql(), '')['host']);
    }

    // ── parseConnection: MSSQL (default port 1433) ──────────────────────

    public function testMssqlParsesFullUrl(): void
    {
        $this->assertSame(
            ['host' => 'h', 'port' => 1434, 'username' => 'sa', 'password' => 'pw', 'database' => 'db'],
            $this->parse($this->mssql(), 'mssql://sa:pw@h:1434/db')
        );
    }

    public function testMssqlUrlWithoutPortUsesDefault1433(): void
    {
        $this->assertSame(1433, $this->parse($this->mssql(), 'mssql://h/db')['port']);
    }

    public function testMssqlBareHostUsesConstructorParams(): void
    {
        $this->assertSame(
            ['host' => 'sqlhost', 'port' => 1444, 'username' => 'cu', 'password' => 'cp', 'database' => 'cdb'],
            $this->parse($this->mssql(1444), 'sqlhost')
        );
    }

    // ── parseConnection: Firebird — default port 3050, host '' ──────────

    public function testFirebirdParsesUrlWithDoubleSlashAbsolutePath(): void
    {
        $parsed = $this->parse($this->firebird(), 'firebird://SYSDBA:masterkey@fbhost:3050//srv/data/a.fdb');
        $this->assertSame('fbhost', $parsed['host']);
        $this->assertSame(3050, $parsed['port']);
        $this->assertSame('SYSDBA', $parsed['username']);
        $this->assertSame('masterkey', $parsed['password']);
        $this->assertSame('/srv/data/a.fdb', $parsed['database']);
    }

    public function testFirebirdUrlWithoutPortUsesDefault3050(): void
    {
        $this->assertSame(3050, $this->parse($this->firebird(), 'firebird://fbhost//srv/a.fdb')['port']);
    }

    public function testFirebirdAliasPathNormalised(): void
    {
        $this->assertSame('employee', $this->parse($this->firebird(), 'firebird://fbhost/employee')['database']);
    }

    public function testFirebirdBareInputUsesEmptyHostAndInputAsDatabase(): void
    {
        $this->assertSame(
            ['host' => '', 'port' => 3050, 'username' => 'SYSDBA', 'password' => 'masterkey', 'database' => '/local/path.fdb'],
            $this->parse($this->firebird(), '/local/path.fdb')
        );
    }

    public function testFirebirdEnvOverrideWinsForUrlAndBareInput(): void
    {
        $adapter = $this->firebird();
        $key = 'TINA4_DATABASE_FIREBIRD_PATH';
        $prevEnv = $_ENV[$key] ?? null;
        $prevGet = getenv($key);
        $_ENV[$key] = '/override/db.fdb';
        putenv("$key=/override/db.fdb");
        try {
            $this->assertSame('/override/db.fdb', $this->parse($adapter, 'firebird://fbhost:3050//srv/data/a.fdb')['database']);
            $this->assertSame('/override/db.fdb', $this->parse($adapter, '/some/other.fdb')['database']);
        } finally {
            if ($prevEnv === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $prevEnv;
            }
            if ($prevGet === false) {
                putenv($key);
            } else {
                putenv("$key=$prevGet");
            }
        }
    }

    // ── parseConnection: native Firebird and PDO Firebird are identical ─

    public static function firebirdInputProvider(): array
    {
        return [
            'double-slash abs' => ['firebird://SYSDBA:masterkey@fbhost:3050//srv/data/a.fdb'],
            'single-slash abs' => ['firebird://fbhost:3050/srv/data/a.fdb'],
            'no port'          => ['firebird://fbhost//srv/a.fdb'],
            'alias'            => ['firebird://fbhost/employee'],
            'windows path'     => ['firebird://fbhost/C:/Data/db.fdb'],
            'bare path'        => ['/var/lib/firebird/data/x.fdb'],
        ];
    }

    #[DataProvider('firebirdInputProvider')]
    public function testNativeAndPdoFirebirdParseIdentically(string $input): void
    {
        $this->assertSame(
            $this->parse($this->firebird(FirebirdAdapter::class), $input),
            $this->parse($this->firebird(PdoFirebirdAdapter::class), $input),
            "native and PDO Firebird parseConnection diverged for: {$input}"
        );
    }

    // ── CrudSqlTrait: real-engine CRUD across every branch ──────────────

    /** @return array<string, array{0:string}> engine => [url] */
    public static function crudEngineProvider(): array
    {
        $engines = ['sqlite' => ['sqlite::memory:']];
        foreach ([
            'mysql'    => 'TINA4_TEST_MYSQL_URL',
            'postgres' => 'TINA4_TEST_POSTGRES_URL',
            'mssql'    => 'TINA4_TEST_MSSQL_URL',
            'firebird' => 'TINA4_TEST_FIREBIRD_URL',
        ] as $engine => $envVar) {
            $url = getenv($envVar);
            if (is_string($url) && $url !== '') {
                $engines[$engine] = [$url];
            }
        }
        return $engines;
    }

    /**
     * The RAW concrete adapter (SQLite3/MySQL/MSSQL/Postgres/Firebird) — the one
     * that actually `use`s CrudSqlTrait — not the Database facade. The facade
     * routes batch-insert and delete-by-list through its OWN helpers, so only a
     * direct adapter call reaches every CrudSqlTrait branch under test.
     */
    private function rawAdapter(string $url): DatabaseAdapter
    {
        $create = new \ReflectionMethod(Database::class, 'createAdapter');
        $create->setAccessible(true);
        return $create->invoke(null, $url);
    }

    #[DataProvider('crudEngineProvider')]
    public function testCrudSqlTraitBranchesOnRealEngine(string $url): void
    {
        $db = $this->rawAdapter($url);
        $table = 'dedup_crud_' . substr(bin2hex(random_bytes(4)), 0, 8);

        $this->dropTableQuietly($db, $table);
        $db->execute("CREATE TABLE {$table} (id INTEGER, name VARCHAR(50), qty INTEGER)");

        try {
            // single-row insert
            $db->insert($table, ['id' => 1, 'name' => 'alpha', 'qty' => 10]);
            $this->assertSame(1, $this->rowCount($db, $table));

            // batch insert (indexed list of assoc maps) — CrudSqlTrait batch branch
            $db->insert($table, [
                ['id' => 2, 'name' => 'beta', 'qty' => 20],
                ['id' => 3, 'name' => 'gamma', 'qty' => 30],
                ['id' => 4, 'name' => 'delta', 'qty' => 40],
            ]);
            $this->assertSame(4, $this->rowCount($db, $table));

            // update with WHERE (string filter + params) — assignmentClause path
            $db->update($table, ['qty' => 99], 'id = ?', [1]);
            $this->assertSame(99, (int) $this->scalar($db, "SELECT qty FROM {$table} WHERE id = 1"));

            // delete by assoc map — assignmentClause + recursion into string form
            $db->delete($table, ['id' => 1]);
            $this->assertSame(0, $this->rowCount($db, $table, 'id = 1'));

            // delete by string filter + params
            $db->delete($table, 'id = ?', [2]);
            $this->assertSame(0, $this->rowCount($db, $table, 'id = 2'));

            // delete by list of assoc maps — CrudSqlTrait list branch, recursion per row
            $db->delete($table, [['id' => 3], ['id' => 4]]);
            $this->assertSame(0, $this->rowCount($db, $table));
        } finally {
            $this->dropTableQuietly($db, $table);
        }
    }

    private function rowCount(DatabaseAdapter $db, string $table, string $where = ''): int
    {
        $sql = "SELECT COUNT(*) AS c FROM {$table}";
        if ($where !== '') {
            $sql .= " WHERE {$where}";
        }
        return (int) $this->scalar($db, $sql);
    }

    /** First column of the first row, case-insensitive to engine column casing. */
    private function scalar(DatabaseAdapter $db, string $sql): mixed
    {
        $result = $db->fetch($sql);
        // A raw adapter's fetch() returns the pre-facade shape {data,total,...};
        // the facade wraps it in a DatabaseResult. Normalise both to a row list.
        if (is_array($result)) {
            $rows = $result['data'] ?? $result;
        } else {
            $rows = $result->toArray();
        }
        if ($rows === []) {
            return null;
        }
        $values = array_values($rows[0]);
        return $values[0] ?? null;
    }

    private function dropTableQuietly(DatabaseAdapter $db, string $table): void
    {
        try {
            $db->execute("DROP TABLE {$table}");
        } catch (\Throwable) {
            // table absent — fine
        }
    }
}
