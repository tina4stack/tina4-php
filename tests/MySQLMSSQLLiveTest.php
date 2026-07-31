<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Live MySQL + MSSQL integration tests (#262).
 *
 * Mirrors the tina4-python reference (tests/test_database_drivers.py —
 * TestMySQLLive / TestMSSQLLive): a TCP reachability probe per engine that
 * SKIPS the test when nothing is listening, plus a raw-boolean round-trip that
 * locks in the cross-framework bind contract — a PHP `true`/`false` binds to a
 * TINYINT (MySQL) / BIT (MSSQL) column without crashing or stringifying to ''.
 *
 * NOT a mock — every assertion runs against the REAL engine via the framework's
 * own Database facade. The connection is built exactly as a Tina4 app would:
 *   new \Tina4\Database\Database("mysql://localhost:3306/tina4_test", null, "tina4", "tina4")
 *   new \Tina4\Database\Database("mssql://localhost:1433/tina4_test", null, "sa", "TinaSQL123!Secure")
 *
 * Skip reasons name the engine + "not reachable" so that, under
 * TINA4_REQUIRE_SERVICES, the RequireServicesGate turns a skip into a hard
 * failure (MySQL/MSSQL are provisioned in CI since 3.13.44). Defaults point at
 * the local docker harness (localhost:3306 / localhost:1433) and are overridable
 * via the TINA4_TEST_MYSQL_* / TINA4_TEST_MSSQL_* env vars.
 *
 * IMPORTANT — the two adapters return DIFFERENT native shapes for the same
 * round-trip and these tests assert what each ACTUALLY returns (no normalising):
 *   - MySQL (mysqli): every column comes back as a STRING — flag is "1"/"0".
 *   - MSSQL (pdo_dblib / FreeTDS): integer columns come back as native PHP int —
 *     flag is 1/0, id is an int. On a host with ext-sqlsrv the Microsoft driver
 *     may instead return "1"/"0" strings; the test tolerates both shapes by
 *     locking in the (int) round-trip value, while pinning the dblib type on
 *     this host.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\MySQLAdapter;
use Tina4\Database\MSSQLAdapter;

class MySQLMSSQLLiveTest extends TestCase
{
    private const TABLE = '_tina4_test';

    private static function mysqlHost(): string
    {
        return getenv('TINA4_TEST_MYSQL_HOST') ?: 'localhost';
    }

    private static function mysqlPort(): int
    {
        return (int) (getenv('TINA4_TEST_MYSQL_PORT') ?: 3306);
    }

    private static function mysqlUser(): string
    {
        return getenv('TINA4_TEST_MYSQL_USERNAME') ?: 'tina4';
    }

    private static function mysqlPass(): string
    {
        $p = getenv('TINA4_TEST_MYSQL_PASSWORD');
        return $p !== false ? $p : 'tina4';
    }

    private static function mysqlDb(): string
    {
        return getenv('TINA4_TEST_MYSQL_DB') ?: 'tina4_test';
    }

    private static function mssqlHost(): string
    {
        return getenv('TINA4_TEST_MSSQL_HOST') ?: 'localhost';
    }

    private static function mssqlPort(): int
    {
        return (int) (getenv('TINA4_TEST_MSSQL_PORT') ?: 1433);
    }

    private static function mssqlUser(): string
    {
        return getenv('TINA4_TEST_MSSQL_USERNAME') ?: 'sa';
    }

    private static function mssqlPass(): string
    {
        $p = getenv('TINA4_TEST_MSSQL_PASSWORD');
        return $p !== false ? $p : 'TinaSQL123!Secure';
    }

    private static function mssqlDb(): string
    {
        return getenv('TINA4_TEST_MSSQL_DB') ?: 'tina4_test';
    }

    /** TCP-reachability probe — identical intent to PgTestEnv::reachable(). */
    private static function reachable(string $host, int $port, float $timeout = 1.0): bool
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($fp === false) {
            return false;
        }
        fclose($fp);

        return true;
    }

    // ── MySQL ────────────────────────────────────────────────────────────────

    private function mysqlDbOrSkip(): Database
    {
        if (!extension_loaded('mysqli')) {
            // "mysql" in the reason + "not installed" → gate violation under
            // TINA4_REQUIRE_SERVICES (mysql is provisioned in CI).
            $this->markTestSkipped('MySQL client not installed — ext-mysqli is missing');
        }
        $host = self::mysqlHost();
        $port = self::mysqlPort();
        if (!self::reachable($host, $port)) {
            $this->markTestSkipped(sprintf('MySQL not reachable at %s:%d — skip integration test', $host, $port));
        }

        $db = new Database(
            sprintf('mysql://%s:%d/%s', $host, $port, self::mysqlDb()),
            null,
            self::mysqlUser(),
            self::mysqlPass()
        );

        $db->execute('DROP TABLE IF EXISTS ' . self::TABLE);
        $db->execute(
            'CREATE TABLE ' . self::TABLE
            . ' (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), flag TINYINT)'
        );
        $db->commit();

        return $db;
    }

    public function testMySQLBooleanRoundTrips(): void
    {
        $db = $this->mysqlDbOrSkip();
        try {
            // Raw PHP booleans bound through execute() — must not crash or
            // stringify to ''. MySQL stores them in a TINYINT.
            $db->execute('INSERT INTO ' . self::TABLE . ' (name, flag) VALUES (?, ?)', ['alpha', true]);
            $db->execute('INSERT INTO ' . self::TABLE . ' (name, flag) VALUES (?, ?)', ['beta', false]);
            $db->commit();

            $rows = $db->fetch('SELECT id, flag, name FROM ' . self::TABLE . ' ORDER BY id')->records;
            $this->assertCount(2, $rows);

            // The mysqli adapter returns every column as a STRING — assert the
            // exact shape it really produces ("1"/"0"), then lock in the
            // numeric round-trip value via (int).
            $this->assertSame('1', $rows[0]['flag'], 'true must round-trip as the string "1" on MySQL/mysqli');
            $this->assertSame('0', $rows[1]['flag'], 'false must round-trip as the string "0" on MySQL/mysqli');
            $this->assertSame([1, 0], [(int) $rows[0]['flag'], (int) $rows[1]['flag']]);

            $this->assertSame('alpha', $rows[0]['name']);
            $this->assertSame('beta', $rows[1]['name']);
        } finally {
            $db->execute('DROP TABLE IF EXISTS ' . self::TABLE);
            $db->commit();
            $db->close();
        }
    }

    public function testMySQLUnderlyingAdapterIsMySQL(): void
    {
        $db = $this->mysqlDbOrSkip();
        try {
            $this->assertInstanceOf(MySQLAdapter::class, $db->getAdapter());
        } finally {
            $db->execute('DROP TABLE IF EXISTS ' . self::TABLE);
            $db->commit();
            $db->close();
        }
    }

    /**
     * #262 BUG 1 lock-in — getLastId() must survive an INTERVENING fetch().
     *
     * mysqli's connection-level insert_id is reset to 0 by any subsequent
     * SELECT, so reading it lazily in lastInsertId() LOST the id after an
     * insert+fetch. The fix CAPTURES the id at INSERT time (mirrors
     * MSSQLAdapter::$lastId / Python mysql.py's cursor.lastrowid). This test
     * inserts into an AUTO_INCREMENT table, runs a SELECT in between, then
     * asserts getLastId() still equals the new row id.
     */
    public function testMySQLGetLastIdSurvivesInterveningFetch(): void
    {
        $db = $this->mysqlDbOrSkip();
        try {
            $db->execute('INSERT INTO ' . self::TABLE . ' (name, flag) VALUES (?, ?)', ['first', 1]);
            $db->commit();
            $idImmediate = $db->getLastId();
            $this->assertSame(1, (int) $idImmediate, 'first AUTO_INCREMENT insert must yield id 1');

            // The bug trigger: a SELECT between the INSERT and the getLastId()
            // read. mysqli resets the connection-level insert_id to 0 here.
            $rows = $db->fetch('SELECT id, name FROM ' . self::TABLE . ' ORDER BY id')->records;
            $this->assertCount(1, $rows);

            $idAfterFetch = $db->getLastId();
            $this->assertSame(
                (int) $idImmediate,
                (int) $idAfterFetch,
                'getLastId() must still equal the new row id AFTER an intervening fetch() (#262)'
            );
            $this->assertSame(1, (int) $idAfterFetch);

            // A second insert advances the captured id past the intervening read.
            $db->execute('INSERT INTO ' . self::TABLE . ' (name, flag) VALUES (?, ?)', ['second', 0]);
            $db->commit();
            $db->fetch('SELECT id FROM ' . self::TABLE)->records; // intervening fetch again
            $this->assertSame(2, (int) $db->getLastId(), 'getLastId() tracks the latest insert across a fetch');
        } finally {
            $db->execute('DROP TABLE IF EXISTS ' . self::TABLE);
            $db->commit();
            $db->close();
        }
    }

    // ── MSSQL ──────────────────────────────────────────────────────────────

    private function mssqlDbOrSkip(): Database
    {
        // MSSQL needs either ext-sqlsrv OR ext-pdo_dblib (FreeTDS 'dblib' PDO
        // driver). Reason names "mssql" + "not installed" so the gate flags it.
        if (!function_exists('sqlsrv_connect')
            && !in_array('dblib', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped(
                'MSSQL client not installed — neither ext-sqlsrv nor ext-pdo_dblib (FreeTDS) is available'
            );
        }
        $host = self::mssqlHost();
        $port = self::mssqlPort();
        if (!self::reachable($host, $port)) {
            $this->markTestSkipped(sprintf('MSSQL not reachable at %s:%d — skip integration test', $host, $port));
        }

        $db = new Database(
            sprintf('mssql://%s:%d/%s', $host, $port, self::mssqlDb()),
            null,
            self::mssqlUser(),
            self::mssqlPass()
        );

        $db->execute("IF OBJECT_ID('" . self::TABLE . "','U') IS NOT NULL DROP TABLE " . self::TABLE);
        $db->execute(
            'CREATE TABLE ' . self::TABLE
            . ' (id INT IDENTITY(1,1) PRIMARY KEY, name VARCHAR(100), flag BIT)'
        );
        $db->commit();

        return $db;
    }

    private function dropMssql(Database $db): void
    {
        $db->execute("IF OBJECT_ID('" . self::TABLE . "','U') IS NOT NULL DROP TABLE " . self::TABLE);
        $db->commit();
    }

    public function testMSSQLBooleanRoundTrips(): void
    {
        $db = $this->mssqlDbOrSkip();
        try {
            // Raw PHP booleans bound to a BIT column — must not stringify to ''
            // (the PG/MSSQL footgun the #262 fix closed).
            $db->execute('INSERT INTO ' . self::TABLE . ' (name, flag) VALUES (?, ?)', ['alpha', true]);
            $db->execute('INSERT INTO ' . self::TABLE . ' (name, flag) VALUES (?, ?)', ['beta', false]);
            $db->commit();

            $rows = $db->fetch('SELECT id, flag, name FROM ' . self::TABLE . ' ORDER BY id')->records;
            $this->assertCount(2, $rows);

            // The (int) round-trip value is the engine-agnostic contract — lock
            // it in regardless of whether the active driver returns 1/"1".
            $this->assertSame([1, 0], [(int) $rows[0]['flag'], (int) $rows[1]['flag']],
                'a raw PHP bool must round-trip to a BIT column as 1/0');
            $this->assertTrue((bool) $rows[0]['flag'], 'inserted true must read back truthy');
            $this->assertFalse((bool) $rows[1]['flag'], 'inserted false must read back falsy');

            $this->assertSame('alpha', $rows[0]['name']);
            $this->assertSame('beta', $rows[1]['name']);

            // Pin the exact pdo_dblib (FreeTDS) shape on this host: integer
            // columns come back as native PHP ints, not strings. (Skipped when
            // the Microsoft sqlsrv driver is the active backend, which may
            // instead return "1"/"0".)
            $adapter = $db->getAdapter();
            if ($adapter instanceof MSSQLAdapter && $adapter->getDriver() === 'pdo') {
                $this->assertIsInt($rows[0]['flag'], 'pdo_dblib returns BIT as a native PHP int');
                $this->assertSame(1, $rows[0]['flag']);
                $this->assertSame(0, $rows[1]['flag']);
                $this->assertIsInt($rows[0]['id'], 'pdo_dblib returns INT/IDENTITY as a native PHP int');
            }
        } finally {
            $this->dropMssql($db);
            $db->close();
        }
    }

    public function testMSSQLUnderlyingAdapterIsMSSQL(): void
    {
        $db = $this->mssqlDbOrSkip();
        try {
            $adapter = $db->getAdapter();
            $this->assertInstanceOf(MSSQLAdapter::class, $adapter);
            // On this CI/host (no ext-sqlsrv) the FreeTDS fallback drives it.
            $this->assertContains($adapter->getDriver(), ['sqlsrv', 'pdo'],
                'MSSQLAdapter must report a known backend');
        } finally {
            $this->dropMssql($db);
            $db->close();
        }
    }

    /**
     * #262 BUG 2 lock-in — fetch() of a query WITH a trailing ORDER BY must
     * report the CORRECT count.
     *
     * The COUNT(*) probe wraps the user SQL in a derived table; SQL Server
     * rejects a derived table whose inner SELECT ends in ORDER BY (error 20018,
     * no TOP/OFFSET/FETCH), so the probe silently fell back to total = 0 while
     * the rows came back fine. The fix strips the trailing ORDER BY for the
     * COUNT probe only. This test inserts 3 rows and asserts that a fetch() of
     * `... ORDER BY id` returns count === 3 (not 0) AND the rows are still
     * correctly ordered.
     */
    public function testMSSQLCountWithTrailingOrderBy(): void
    {
        $db = $this->mssqlDbOrSkip();
        try {
            $db->execute('INSERT INTO ' . self::TABLE . ' (name, flag) VALUES (?, ?)', ['a', 1]);
            $db->execute('INSERT INTO ' . self::TABLE . ' (name, flag) VALUES (?, ?)', ['b', 1]);
            $db->execute('INSERT INTO ' . self::TABLE . ' (name, flag) VALUES (?, ?)', ['c', 0]);
            $db->commit();

            // The bug: a trailing ORDER BY made the COUNT probe return 0.
            $result = $db->fetch('SELECT id, name FROM ' . self::TABLE . ' ORDER BY id');
            $this->assertSame(3, (int) $result->count,
                'fetch() count must be 3 for a query WITH a trailing ORDER BY (#262), not silently 0');
            $this->assertCount(3, $result->records, 'all rows must still come back');
            $this->assertSame('a', $result->records[0]['name'], 'ORDER BY id must still order the rows');
            $this->assertSame('c', $result->records[2]['name']);

            // Control: the count must match a plain (no ORDER BY) fetch.
            $plain = $db->fetch('SELECT id, name FROM ' . self::TABLE);
            $this->assertSame((int) $plain->count, (int) $result->count,
                'ORDER BY must not change the reported total');

            // Pagination still works — the paged query KEEPS its ORDER BY
            // (OFFSET/FETCH needs it); only the COUNT probe strips it.
            $paged = $db->fetch('SELECT id, name FROM ' . self::TABLE . ' ORDER BY id DESC', [], 2, 0);
            $this->assertSame(3, (int) $paged->count, 'paged fetch total is still the full count');
            $this->assertCount(2, $paged->records, 'limit applied to the page');
            $this->assertSame('c', $paged->records[0]['name'], 'ORDER BY id DESC preserved on the paged query');
        } finally {
            $this->dropMssql($db);
            $db->close();
        }
    }

    /**
     * #262 control — MSSQL getLastId() returns the new IDENTITY value.
     *
     * The MSSQLAdapter already captures the IDENTITY at write time (the pattern
     * the MySQL fix mirrors); this locks that contract in alongside the MySQL
     * getLastId test so a regression in either adapter is caught here.
     */
    public function testMSSQLGetLastIdReturnsIdentity(): void
    {
        $db = $this->mssqlDbOrSkip();
        try {
            $db->execute('INSERT INTO ' . self::TABLE . ' (name, flag) VALUES (?, ?)', ['only', 1]);
            $db->commit();
            $id = $db->getLastId();
            $this->assertSame(1, (int) $id, 'first IDENTITY insert must yield id 1');

            // An intervening fetch must not lose the captured IDENTITY either.
            $db->fetch('SELECT id FROM ' . self::TABLE . ' ORDER BY id')->records;
            $this->assertSame(1, (int) $db->getLastId(),
                'getLastId() must survive an intervening fetch() on MSSQL too');
        } finally {
            $this->dropMssql($db);
            $db->close();
        }
    }
}
