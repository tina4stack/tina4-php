<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Footgun [9] — CREATE TABLE idempotency on the two engines that lack
 * IF NOT EXISTS (MSSQL and Firebird) — driven against REAL ENGINES.
 *
 * NO DOUBLES. These tests replace FakeMSSQLAdapter / FakeFirebirdAdapter, two
 * in-test subclasses that skipped their parent constructor (never connecting)
 * and overrode tableExists() to return a hard-coded boolean. Because the guard
 * under test is keyed on the engine dialect, feeding it a fake of that dialect
 * only proved that a guard fires when handed something it recognises. The
 * behaviour that actually matters — whether a REAL engine reports a table as
 * existing for the bare, bracketed and quoted spellings a migration really
 * writes — was defined away.
 *
 * Each engine SKIPS LOUDLY naming host and port when absent, so a missing
 * service is visible rather than silently green.
 *
 * The two engines are NOT gated identically, and the difference is deliberate —
 * verified against Tina4/Testing/RequireServicesGate.php, not assumed:
 *   - MSSQL IS in that gate's SERVICE_KEYWORDS ('mssql', 'sqlserver', 'sqlsrv',
 *     'pdo_dblib'), so under TINA4_REQUIRE_SERVICES=1 an "MSSQL not reachable
 *     at host:port" skip becomes a hard CI FAILURE. The skip text below is
 *     worded to match that gate ('mssql' + 'not reachable' / 'not installed').
 *   - Firebird is EXCLUDED from that keyword set ON PURPOSE — CI does not
 *     provision it — so a Firebird skip legitimately stays green. Do not read
 *     a green CI run as proof the Firebird legs executed; they only run where
 *     a real Firebird is present (as on the maintainer's Mac).
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Migration;

class MigrationFootgunsLiveEngineTest extends TestCase
{
    private string $migrationsDir;

    /** Distinct names so a shared test server is never clobbered. */
    private const T_BARE      = 'nomock_mig_users';
    private const T_BRACKETED = 'nomock_mig_Things';
    private const T_QUOTED    = 'nomock_mig_Orders';

    protected function setUp(): void
    {
        $this->migrationsDir = sys_get_temp_dir() . '/tina4_mig_live_' . uniqid();
        mkdir($this->migrationsDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->migrationsDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->migrationsDir);
    }

    private function invoke(Migration $m, string $method, array $args)
    {
        return (new \ReflectionMethod(Migration::class, $method))->invokeArgs($m, $args);
    }

    private static function reachable(string $host, int $port, float $timeout = 1.0): bool
    {
        $fp = @fsockopen($host, $port, $e, $s, $timeout);
        if ($fp === false) {
            return false;
        }
        fclose($fp);

        return true;
    }

    // ── MSSQL ───────────────────────────────────────────────────────────────

    private static function mssqlHost(): string
    {
        return getenv('TINA4_TEST_MSSQL_HOST') ?: 'localhost';
    }

    private static function mssqlPort(): int
    {
        return (int) (getenv('TINA4_TEST_MSSQL_PORT') ?: 1433);
    }

    private function mssqlOrSkip(): Database
    {
        if (!function_exists('sqlsrv_connect') && !in_array('dblib', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped(
                'MSSQL client not installed — neither ext-sqlsrv nor ext-pdo_dblib (FreeTDS) is available'
            );
        }

        $host = self::mssqlHost();
        $port = self::mssqlPort();
        if (!self::reachable($host, $port)) {
            $this->markTestSkipped(sprintf('MSSQL not reachable at %s:%d — skip live migration footgun test', $host, $port));
        }

        $db = new Database(
            sprintf('mssql://%s:%d/%s', $host, $port, getenv('TINA4_TEST_MSSQL_DB') ?: 'tina4_test'),
            null,
            getenv('TINA4_TEST_MSSQL_USERNAME') ?: 'sa',
            (getenv('TINA4_TEST_MSSQL_PASSWORD') !== false ? getenv('TINA4_TEST_MSSQL_PASSWORD') : 'TinaSQL123!Secure')
        );

        $this->dropMssql($db);

        return $db;
    }

    private function dropMssql(Database $db): void
    {
        foreach ([self::T_BARE, self::T_BRACKETED] as $t) {
            try {
                $db->execute("IF OBJECT_ID('[$t]','U') IS NOT NULL DROP TABLE [$t]");
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }
        $db->commit();
    }

    /**
     * A REAL table on a REAL SQL Server makes the guard fire, and the reason
     * names the real table. The bare (unquoted) spelling.
     */
    public function testMssqlCreateTableSkippedWhenTableReallyExists(): void
    {
        $db = $this->mssqlOrSkip();
        try {
            $db->execute('CREATE TABLE ' . self::T_BARE . ' (id INT)');
            $db->commit();

            $this->assertTrue($db->tableExists(self::T_BARE), 'Precondition: the real engine must report the table.');

            $m      = new Migration($db, $this->migrationsDir);
            $reason = $this->invoke($m, 'shouldSkipCreateTable', ['CREATE TABLE ' . self::T_BARE . ' (id INT)']);

            $this->assertNotNull($reason, 'A really-existing MSSQL table must make CREATE TABLE skip.');
            $this->assertStringContainsString(self::T_BARE, $reason);
        } finally {
            $this->dropMssql($db);
        }
    }

    /**
     * The bracketed spelling — [Things] — is what MSSQL migrations actually
     * write. The guard must strip the brackets before asking the engine.
     */
    public function testMssqlCreateTableSkippedForBracketedNameWhenTableReallyExists(): void
    {
        $db = $this->mssqlOrSkip();
        try {
            $db->execute('CREATE TABLE [' . self::T_BRACKETED . '] (id INT)');
            $db->commit();

            $this->assertTrue($db->tableExists(self::T_BRACKETED), 'Precondition: the real engine must report the table.');

            $m      = new Migration($db, $this->migrationsDir);
            $reason = $this->invoke($m, 'shouldSkipCreateTable', ['CREATE TABLE [' . self::T_BRACKETED . '] (id INT)']);

            $this->assertNotNull($reason, 'A really-existing bracketed MSSQL table must make CREATE TABLE skip.');
            $this->assertStringContainsString(self::T_BRACKETED, $reason);
        } finally {
            $this->dropMssql($db);
        }
    }

    /** The negative control: a table that was never created must NOT skip. */
    public function testMssqlCreateTableNotSkippedWhenTableReallyAbsent(): void
    {
        $db = $this->mssqlOrSkip();
        try {
            $this->assertFalse($db->tableExists(self::T_BARE), 'Precondition: the table must really be absent.');

            $m      = new Migration($db, $this->migrationsDir);
            $reason = $this->invoke($m, 'shouldSkipCreateTable', ['CREATE TABLE ' . self::T_BARE . ' (id INT)']);

            $this->assertNull($reason, 'An absent table must NOT be skipped — the migration has to run.');
        } finally {
            $this->dropMssql($db);
        }
    }

    /**
     * The second negative control: on an engine where the guard IS active, a
     * NON-CREATE statement must be ignored even when a table of that name really
     * exists. Without a live MSSQL this cannot be asserted honestly — on SQLite
     * shouldSkipCreateTable() returns null at the dialect check before it ever
     * inspects the statement, so the same assertion would pass for the wrong
     * reason and survive deletion of the statement-pattern check entirely.
     */
    public function testMssqlNonCreateStatementIgnoredEvenWhenTableReallyExists(): void
    {
        $db = $this->mssqlOrSkip();
        try {
            $db->execute('CREATE TABLE ' . self::T_BARE . ' (id INT)');
            $db->commit();

            $this->assertTrue($db->tableExists(self::T_BARE), 'Precondition: the real engine must report the table.');

            $m = new Migration($db, $this->migrationsDir);

            // The table really exists, so a CREATE for it really would skip …
            $this->assertNotNull(
                $this->invoke($m, 'shouldSkipCreateTable', ['CREATE TABLE ' . self::T_BARE . ' (id INT)']),
                'Control: a CREATE for this really-existing table must skip.'
            );

            // … but an INSERT naming the same table must not be touched.
            $this->assertNull(
                $this->invoke($m, 'shouldSkipCreateTable', ['INSERT INTO ' . self::T_BARE . ' VALUES (1)']),
                'A non-CREATE statement must never be skipped by the CREATE-TABLE guard.'
            );
        } finally {
            $this->dropMssql($db);
        }
    }

    // ── Firebird ────────────────────────────────────────────────────────────

    private static function firebirdHost(): string
    {
        return getenv('TINA4_TEST_FIREBIRD_HOST') ?: 'localhost';
    }

    private static function firebirdPort(): int
    {
        return (int) (getenv('TINA4_TEST_FIREBIRD_PORT') ?: 3050);
    }

    private static function firebirdPath(): string
    {
        return getenv('TINA4_TEST_FIREBIRD_PATH') ?: (sys_get_temp_dir() . '/tina4_php_nomock_mig.fdb');
    }

    private function firebirdOrSkip(): Database
    {
        if (!function_exists('ibase_connect') && !in_array('firebird', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('Firebird client not installed — neither ext-interbase nor pdo_firebird is available');
        }

        $host = self::firebirdHost();
        $port = self::firebirdPort();
        if (!self::reachable($host, $port)) {
            $this->markTestSkipped(sprintf('Firebird not reachable at %s:%d — skip live migration footgun test', $host, $port));
        }

        $path = self::firebirdPath();
        if (!file_exists($path) && function_exists('ibase_query')) {
            // Create a REAL database on the REAL server (not a fixture stand-in).
            @ibase_query(
                IBASE_CREATE,
                sprintf(
                    "CREATE DATABASE '%s:%s' USER 'SYSDBA' PASSWORD 'masterkey' PAGE_SIZE 8192 DEFAULT CHARACTER SET UTF8",
                    $host,
                    $path
                )
            );
        }
        if (!file_exists($path)) {
            $this->markTestSkipped(sprintf(
                'Firebird database %s could not be created on %s:%d — set TINA4_TEST_FIREBIRD_PATH',
                $path,
                $host,
                $port
            ));
        }

        $db = new Database(
            sprintf('firebird://%s:%d/%s', $host, $port, ltrim($path, '/')),
            null,
            getenv('TINA4_TEST_FIREBIRD_USERNAME') ?: 'SYSDBA',
            getenv('TINA4_TEST_FIREBIRD_PASSWORD') ?: 'masterkey'
        );

        $this->dropFirebird($db);

        return $db;
    }

    private function dropFirebird(Database $db): void
    {
        foreach (['"' . self::T_QUOTED . '"', self::T_BARE] as $t) {
            try {
                $db->execute("DROP TABLE $t");
                $db->commit();
            } catch (\Throwable) {
                // best-effort cleanup — table may not exist
            }
        }
    }

    /**
     * Unquoted Firebird identifiers fold to UPPERCASE server-side. A really
     * created `nomock_mig_users` is stored as `NOMOCK_MIG_USERS`, and the guard
     * must still recognise it.
     */
    public function testFirebirdCreateTableSkippedForUnquotedNameWhenTableReallyExists(): void
    {
        $db = $this->firebirdOrSkip();
        try {
            $db->execute('CREATE TABLE ' . self::T_BARE . ' (id INTEGER)');
            $db->commit();

            $this->assertTrue($db->tableExists(self::T_BARE), 'Precondition: the real engine must report the folded table.');

            $m      = new Migration($db, $this->migrationsDir);
            $reason = $this->invoke($m, 'shouldSkipCreateTable', ['CREATE TABLE ' . self::T_BARE . ' (id INTEGER)']);

            $this->assertNotNull($reason, 'A really-existing Firebird table must make CREATE TABLE skip.');
            $this->assertStringContainsString(self::T_BARE, $reason);
        } finally {
            $this->dropFirebird($db);
        }
    }

    /**
     * KNOWN FRAMEWORK BUG — this test is EXPECTED TO FAIL until it is fixed.
     * It is deliberately left failing rather than mocked away or deleted.
     *
     * A QUOTED Firebird identifier keeps its case: `CREATE TABLE "Orders"`
     * really creates a relation literally named `Orders`. But BOTH Firebird
     * adapters look the name up with `strtoupper($table)`:
     *
     *   Tina4/Database/PdoFirebirdAdapter.php:194  (the path actually exercised
     *                                               on macOS + Firebird 5, where
     *                                               the facade selects PDO)
     *   Tina4/Database/FirebirdAdapter.php:413     (same defect, ext-interbase)
     *
     * Both classes repeat the same strtoupper() lookup in getColumns() and the
     * column-existence query (PdoFirebirdAdapter:210,223 / FirebirdAdapter:429,445),
     * so a quoted mixed-case table is invisible to those too.
     *
     *     WHERE ... TRIM(RDB$RELATION_NAME) = ?     -- bound with strtoupper($table)
     *
     * so the query looks for `ORDERS`, never matches, and reports the table
     * ABSENT even though RDB$RELATIONS lists it. These are two independent
     * classes (PdoFirebirdAdapter does NOT extend FirebirdAdapter), so a fix
     * has to land in both.
     *
     * Measured against a real Firebird 5.0.3: the relation is present and
     * usable (INSERT + SELECT both succeed) and RDB$RELATIONS lists it, yet
     * tableExists('nomock_mig_Orders') === false.
     *
     * Consequence, reproduced end to end: shouldSkipCreateTable() returns null
     * for a quoted table, so re-running a migration containing
     * CREATE TABLE "Orders" issues a duplicate CREATE and the engine raises
     *   SQLSTATE[42S01] ... -607 unsuccessful metadata update
     *   CREATE TABLE Orders failed Table Orders already exists
     * — precisely the idempotency guarantee footgun [9] exists to provide.
     *
     * The old FakeFirebirdAdapter returned a hard-coded `true` from
     * tableExists(), which is exactly why this shipped undetected.
     */
    public function testFirebirdCreateTableSkippedForQuotedMixedCaseNameWhenTableReallyExists(): void
    {
        $db = $this->firebirdOrSkip();
        try {
            $db->execute('CREATE TABLE "' . self::T_QUOTED . '" (id INTEGER)');
            $db->commit();

            // The relation genuinely exists with its case preserved.
            $rows = $db->fetch(
                "SELECT TRIM(RDB\$RELATION_NAME) AS TNAME FROM RDB\$RELATIONS WHERE RDB\$SYSTEM_FLAG = 0"
            );
            $names = array_map(static fn (array $r) => trim((string) reset($r)), $rows->records);
            $this->assertContains(
                self::T_QUOTED,
                $names,
                'Precondition: the quoted table really exists with its mixed case preserved.'
            );

            $m      = new Migration($db, $this->migrationsDir);
            $reason = $this->invoke($m, 'shouldSkipCreateTable', ['CREATE TABLE "' . self::T_QUOTED . '" (id INTEGER)']);

            $this->assertNotNull(
                $reason,
                'BUG: FirebirdAdapter::tableExists() uppercases the name, so a quoted mixed-case '
                . 'table is reported absent and the CREATE-TABLE idempotency guard never fires.'
            );
            $this->assertStringContainsString(self::T_QUOTED, $reason);
        } finally {
            $this->dropFirebird($db);
        }
    }

    /** The negative control: a table that was never created must NOT skip. */
    public function testFirebirdCreateTableNotSkippedWhenTableReallyAbsent(): void
    {
        $db = $this->firebirdOrSkip();
        try {
            $this->assertFalse($db->tableExists(self::T_BARE), 'Precondition: the table must really be absent.');

            $m      = new Migration($db, $this->migrationsDir);
            $reason = $this->invoke($m, 'shouldSkipCreateTable', ['CREATE TABLE ' . self::T_BARE . ' (id INTEGER)']);

            $this->assertNull($reason, 'An absent table must NOT be skipped — the migration has to run.');
        } finally {
            $this->dropFirebird($db);
        }
    }
}
