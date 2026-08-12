<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Shared migration contract — feature 15 (OWNER-DECISIONS.md Batch 4,
 * feature doc 015-migrations.md, MIG-DEC-01/02/03). Real engines only
 * (SQLite, PostgreSQL, MySQL, MSSQL, Firebird 5) — no mocks, no fakes. The
 * SAME case names are proven in all four frameworks; the shared fixture is
 * tina4-documentation/plan/v3/fixtures/migrations_contract.json.
 *
 * MIG-DEC-01: `tina4php migrate:status` used to raise TypeError at
 * construction — `new Migration($migrationsDir)` passed the migrations
 * DIRECTORY STRING into the constructor's `?DatabaseAdapter $db` parameter
 * (the handler never resolved a real $db the way `migrate` / `migrate:rollback`
 * already did). testMigrateStatusPrintsWithoutCrashing drives the REAL CLI
 * binary (bin/tina4php, proc_open — same model as CliMigrateExitCodeTest)
 * against a real migrated SQLite database.
 *
 * MIG-DEC-02: rollbackBatch() used to Log::warning() a missing down artifact
 * and still fall through to the unconditional DELETE — the tracking row was
 * silently dropped even though nothing was reversed. It now RAISES (routed
 * through the SAME catch block a failing down STATEMENT already used), which
 * skips the DELETE. testFailedOrMissingDownDoesNotDropLedger proves the row
 * survives.
 *
 * MIG-DEC-03: testFirebirdMssqlCreateAddIdempotencyReal mirrors
 * MigrationFootgunsLiveEngineTest ("NO DOUBLES") — already the model PHP set
 * for Ruby/Node this release — against REAL Firebird 5 / REAL MSSQL, no
 * change needed here (PHP already had this).
 *
 * Mutation-proved on the .99 lab, then restored: reinstate the
 * `new Migration($migrationsDir)` bug -> testMigrateStatusPrintsWithoutCrashing
 * goes RED (TypeError); reinstate the Log::warning-then-DELETE rollback path
 * -> testFailedOrMissingDownDoesNotDropLedger goes RED (the row vanishes).
 *
 * Env contract (identical to the write-path/pgprovider/mysqlprovider/
 * mssqlprovider/firebirdprovider runners): TINA4_TEST_PG_HOST/_PORT/_USERNAME/
 * _PASSWORD/_DB (default 127.0.0.1:55432, tina4/tina4); TINA4_TEST_MYSQL_HOST/
 * _PORT/_USERNAME/_PASSWORD/_DB (default 127.0.0.1:3306, tina4/tina4);
 * TINA4_TEST_MSSQL_HOST/_PORT/_USERNAME/_PASSWORD/_DB (default 127.0.0.1:1433,
 * sa/TinaSQL123!Secure); TINA4_TEST_FIREBIRD_URL (a live Firebird 5 URL; unset
 * means the Firebird cases skip locally — the lab gate's own preflight FATALs
 * if it is unreachable there, a skipped required engine is a ghost).
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\SQLite3Adapter;
use Tina4\Migration;

class MigrationContractTest extends TestCase
{
    private string $migrationsDir;

    protected function setUp(): void
    {
        $this->migrationsDir = sys_get_temp_dir() . '/tina4_mig_contract_' . uniqid();
        mkdir($this->migrationsDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->migrationsDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function invokePrivate(Migration $m, string $method, array $args = [])
    {
        return (new \ReflectionMethod(Migration::class, $method))->invokeArgs($m, $args);
    }

    /**
     * Best-effort delete of a stale tina4_migration row. On a genuinely fresh
     * database/engine the tracking table itself may not exist yet (nothing
     * has called migrate() there before) -- that is not a real failure, just
     * nothing to clean up.
     */
    private function cleanLedgerRow(Database $db, string $migrationName): void
    {
        try {
            $db->execute('DELETE FROM tina4_migration WHERE migration_name = :n', [':n' => $migrationName]);
            $db->commit();
        } catch (\Throwable) {
            // table doesn't exist yet -- nothing to clean up
        }
    }

    // ── real-engine reachability helpers ────────────────────────────────────

    private static function reachable(string $host, int $port, float $timeout = 1.0): bool
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($fp === false) {
            return false;
        }
        fclose($fp);
        return true;
    }

    private static function mysqlHost(): string
    {
        return getenv('TINA4_TEST_MYSQL_HOST') ?: '127.0.0.1';
    }

    private static function mysqlPort(): int
    {
        return (int) (getenv('TINA4_TEST_MYSQL_PORT') ?: 3306);
    }

    private function mysqlOrSkip(): Database
    {
        if (!self::reachable(self::mysqlHost(), self::mysqlPort())) {
            $this->markTestSkipped(sprintf(
                'MySQL not reachable at %s:%d (set TINA4_TEST_MYSQL_*)',
                self::mysqlHost(),
                self::mysqlPort()
            ));
        }
        return Database::create(
            sprintf('mysql://%s:%d/%s', self::mysqlHost(), self::mysqlPort(), getenv('TINA4_TEST_MYSQL_DB') ?: 'tina4_test'),
            null,
            getenv('TINA4_TEST_MYSQL_USERNAME') ?: 'tina4',
            getenv('TINA4_TEST_MYSQL_PASSWORD') !== false ? getenv('TINA4_TEST_MYSQL_PASSWORD') : 'tina4'
        );
    }

    private static function pgHost(): string
    {
        return getenv('TINA4_TEST_PG_HOST') ?: '127.0.0.1';
    }

    private static function pgPort(): int
    {
        return (int) (getenv('TINA4_TEST_PG_PORT') ?: 55432);
    }

    private function pgOrSkip(): Database
    {
        if (!self::reachable(self::pgHost(), self::pgPort())) {
            $this->markTestSkipped(sprintf(
                'PostgreSQL not reachable at %s:%d (set TINA4_TEST_PG_*)',
                self::pgHost(),
                self::pgPort()
            ));
        }
        return Database::create(
            sprintf('postgres://%s:%d/%s', self::pgHost(), self::pgPort(), getenv('TINA4_TEST_PG_DB') ?: 'tina4_php'),
            null,
            getenv('TINA4_TEST_PG_USERNAME') ?: 'tina4',
            getenv('TINA4_TEST_PG_PASSWORD') !== false ? getenv('TINA4_TEST_PG_PASSWORD') : 'tina4'
        );
    }

    private static function mssqlHost(): string
    {
        return getenv('TINA4_TEST_MSSQL_HOST') ?: '127.0.0.1';
    }

    private static function mssqlPort(): int
    {
        return (int) (getenv('TINA4_TEST_MSSQL_PORT') ?: 1433);
    }

    private function mssqlOrSkip(): Database
    {
        if (!function_exists('sqlsrv_connect') && !in_array('dblib', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('MSSQL client not installed — neither ext-sqlsrv nor ext-pdo_dblib (FreeTDS) is available');
        }
        if (!self::reachable(self::mssqlHost(), self::mssqlPort())) {
            $this->markTestSkipped(sprintf('MSSQL not reachable at %s:%d', self::mssqlHost(), self::mssqlPort()));
        }
        return new Database(
            sprintf('mssql://%s:%d/%s', self::mssqlHost(), self::mssqlPort(), getenv('TINA4_TEST_MSSQL_DB') ?: 'tina4_test'),
            null,
            getenv('TINA4_TEST_MSSQL_USERNAME') ?: 'sa',
            getenv('TINA4_TEST_MSSQL_PASSWORD') !== false ? getenv('TINA4_TEST_MSSQL_PASSWORD') : 'TinaSQL123!Secure'
        );
    }

    private static function firebirdUrl(): string
    {
        $url = getenv('TINA4_TEST_FIREBIRD_URL');
        return $url === false ? '' : trim($url);
    }

    private function firebirdOrSkip(): Database
    {
        if (!function_exists('ibase_connect') && !in_array('firebird', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('Firebird client not installed — neither ext-interbase nor pdo_firebird is available');
        }
        $url = self::firebirdUrl();
        if ($url === '') {
            $this->markTestSkipped('TINA4_TEST_FIREBIRD_URL is not set — no live Firebird was promised to this run');
        }
        try {
            $db = new Database($url);
            $db->fetchOne('SELECT 1 AS N FROM RDB$DATABASE');
        } catch (\Throwable $failure) {
            $this->markTestSkipped(sprintf('Firebird cannot connect at %s — %s', $url, $failure->getMessage()));
        }
        return $db;
    }

    // ── ledger-row-commits-atomically-with-ddl ──────────────────────────────

    public function testLedgerRowCommitsAtomicallyWithDdl(): void
    {
        file_put_contents(
            $this->migrationsDir . '/000001_create_widgets.sql',
            'CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)'
        );
        $db = new SQLite3Adapter(':memory:');
        try {
            $migration = new Migration($db, $this->migrationsDir);
            $result = $migration->migrate();
            $this->assertSame(['000001_create_widgets.sql'], $result['applied']);
            $this->assertTrue($db->tableExists('widgets'), 'DDL did not apply');

            // PHP's migration_name is the FULL FILENAME including .sql (an
            // established, self-consistent PHP-only convention -- see
            // MigrationV3Test.php -- unlike Python/Ruby/Node's bare stem).
            $row = $db->fetchOne(
                'SELECT migration_name, batch FROM tina4_migration WHERE migration_name = :n',
                [':n' => '000001_create_widgets.sql']
            );
            $this->assertNotNull($row, 'ledger row was not written alongside the DDL');
            $this->assertSame(1, (int) $row['batch']);
        } finally {
            $db->close();
        }
    }

    public function testLedgerRowNeverPrecedesOrSurvivesAFailedDdlOnMysql(): void
    {
        $db = $this->mysqlOrSkip();
        $table = 'mig_php_mysql_atomic';
        $name = '000001_mysql_atomic';
        $fullName = $name . '.sql';
        try {
            $db->execute("DROP TABLE IF EXISTS {$table}");
            $db->commit();
            $this->cleanLedgerRow($db, $fullName);

            file_put_contents(
                $this->migrationsDir . "/{$fullName}",
                "CREATE TABLE {$table} (id INT PRIMARY KEY);\nTHIS IS NOT VALID SQL;"
            );

            $migration = new Migration($db, $this->migrationsDir);
            $result = $migration->migrate();

            $this->assertArrayHasKey($fullName, $result['errors'], 'the migration must be recorded as failed');
            $this->assertTrue($db->tableExists($table), 'precondition: MySQL DDL auto-commits, the table must exist');

            $row = $db->fetchOne('SELECT 1 AS X FROM tina4_migration WHERE migration_name = :n', [':n' => $fullName]);
            $this->assertNull($row, 'the ledger row must never be written for a failed migration, even on non-transactional DDL');
        } finally {
            $db->execute("DROP TABLE IF EXISTS {$table}");
            $db->commit();
            $this->cleanLedgerRow($db, $fullName);
            $db->close();
        }
    }

    // ── midfile-failure-rolls-back-on-transactional-ddl ─────────────────────

    public function testMidfileFailureRollsBackOnTransactionalDdl(): void
    {
        $db = $this->pgOrSkip();
        $table = 'mig_php_pg_midfile';
        $name = '000001_pg_midfile';
        $fullName = $name . '.sql';
        try {
            $db->execute("DROP TABLE IF EXISTS {$table}");
            $db->commit();
            $this->cleanLedgerRow($db, $fullName);

            file_put_contents(
                $this->migrationsDir . "/{$fullName}",
                "CREATE TABLE {$table} (id SERIAL PRIMARY KEY, name VARCHAR(50));\nTHIS IS NOT VALID SQL;"
            );

            $migration = new Migration($db, $this->migrationsDir);
            $result = $migration->migrate();

            $this->assertArrayHasKey($fullName, $result['errors']);
            $this->assertFalse(
                $db->tableExists($table),
                'PostgreSQL DDL is transactional — the earlier CREATE TABLE in the same failed file must roll back too'
            );
            $row = $db->fetchOne('SELECT 1 AS X FROM tina4_migration WHERE migration_name = :n', [':n' => $fullName]);
            $this->assertNull($row, 'no ledger row for a fully-rolled-back file');
        } finally {
            $db->execute("DROP TABLE IF EXISTS {$table}");
            $db->commit();
            $this->cleanLedgerRow($db, $fullName);
            $db->close();
        }
    }

    /**
     * The SQLite analog (proves CLAUDE.md's corrected "SQLite has
     * transactional DDL" claim, not just PostgreSQL's).
     */
    public function testSqliteMultiStatementFailureRollsBackDdl(): void
    {
        file_put_contents(
            $this->migrationsDir . '/000001_sqlite_midfile.sql',
            "CREATE TABLE good (id INTEGER);\nTHIS WILL FAIL;"
        );
        $db = new SQLite3Adapter(':memory:');
        try {
            $migration = new Migration($db, $this->migrationsDir);
            $result = $migration->migrate();
            $this->assertArrayHasKey('000001_sqlite_midfile.sql', $result['errors']);
            $this->assertFalse(
                $db->tableExists('good'),
                'SQLite DDL is transactional — a CREATE TABLE earlier in a failed file must roll back too'
            );
        } finally {
            $db->close();
        }
    }

    // ── migrate-status-prints-without-crashing ──────────────────────────────

    private function runCli(array $args, string $cwd, array $env): array
    {
        $binPath = realpath(__DIR__ . '/../bin/tina4php');
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $cmd = array_merge([PHP_BINARY, $binPath], $args);
        $proc = proc_open($cmd, $descriptors, $pipes, $cwd, $env);
        $this->assertIsResource($proc, 'failed to spawn CLI');

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        return [$exitCode, $stdout, $stderr];
    }

    public function testMigrateStatusPrintsWithoutCrashing(): void
    {
        $dbPath = $this->migrationsDir . '/status.db';
        $projectDir = sys_get_temp_dir() . '/tina4_mig_contract_status_' . uniqid();
        mkdir($projectDir . '/migrations', 0755, true);
        try {
            file_put_contents(
                $projectDir . '/migrations/000001_create_accounts.sql',
                'CREATE TABLE accounts (id INTEGER PRIMARY KEY, name TEXT)'
            );
            $env = ['DATABASE_URL' => 'sqlite:///' . $dbPath];

            // Apply the one migration up front via the REAL CLI (same 'migrate'
            // path CliMigrateExitCodeTest already proves).
            [$applyRc] = $this->runCli(['migrate'], $projectDir, $env);
            $this->assertSame(0, $applyRc, 'setup: migrate must succeed before status is checked');

            // Add a SECOND, still-pending migration so status prints BOTH the
            // completed and pending branches — MIG-CLI-STATUS-BROKEN crashed on
            // migration_id in BOTH.
            file_put_contents(
                $projectDir . '/migrations/000002_add_index.sql',
                'CREATE INDEX idx_accounts_name ON accounts (name)'
            );

            [$exitCode, $stdout, $stderr] = $this->runCli(['migrate:status'], $projectDir, $env);

            $this->assertSame(0, $exitCode, "migrate:status must exit 0 against a real migrated DB.\nstdout: {$stdout}\nstderr: {$stderr}");
            $this->assertStringNotContainsString('TypeError', $stderr, "the constructor TypeError regressed:\n{$stderr}");
            $this->assertStringNotContainsString('Fatal error', $stdout . $stderr);
            $this->assertStringContainsString('Completed:', $stdout);
            $this->assertStringContainsString('000001_create_accounts', $stdout);
            $this->assertStringContainsString('Pending:', $stdout);
            $this->assertStringContainsString('000002_add_index', $stdout);
            $this->assertStringContainsString('1 completed, 1 pending', $stdout);
        } finally {
            $this->removeDir($projectDir);
        }
    }

    public function testMigrateStatusPrintsWithoutCrashingWhenNothingAppliedYet(): void
    {
        $dbPath = $this->migrationsDir . '/status_empty.db';
        $projectDir = sys_get_temp_dir() . '/tina4_mig_contract_status_empty_' . uniqid();
        mkdir($projectDir . '/migrations', 0755, true);
        try {
            file_put_contents(
                $projectDir . '/migrations/000001_never_applied.sql',
                'CREATE TABLE never_applied (id INTEGER PRIMARY KEY)'
            );
            $env = ['DATABASE_URL' => 'sqlite:///' . $dbPath];

            [$exitCode, $stdout, $stderr] = $this->runCli(['migrate:status'], $projectDir, $env);

            $this->assertSame(0, $exitCode, "stdout: {$stdout}\nstderr: {$stderr}");
            $this->assertStringNotContainsString('TypeError', $stderr);
            $this->assertStringContainsString('Completed: (none)', $stdout);
            $this->assertStringContainsString('000001_never_applied', $stdout);
            $this->assertStringContainsString('0 completed, 1 pending', $stdout);
        } finally {
            $this->removeDir($projectDir);
        }
    }

    // ── failed-or-missing-down-does-not-drop-ledger ─────────────────────────

    public function testFailedOrMissingDownDoesNotDropLedger(): void
    {
        file_put_contents($this->migrationsDir . '/000001_no_down.sql', 'CREATE TABLE nd (id INTEGER)');
        $db = new SQLite3Adapter(':memory:');
        try {
            $migration = new Migration($db, $this->migrationsDir);
            $migration->migrate();

            $rowBefore = $db->fetchOne(
                'SELECT migration_name FROM tina4_migration WHERE migration_name = :n',
                [':n' => '000001_no_down.sql']
            );
            $this->assertNotNull($rowBefore, 'precondition: the migration must be recorded');

            $result = $migration->rollback();
            $this->assertArrayHasKey('000001_no_down.sql', $result['errors'], 'a missing .down.sql must be reported as a rollback error');

            $rowAfter = $db->fetchOne(
                'SELECT migration_name FROM tina4_migration WHERE migration_name = :n',
                [':n' => '000001_no_down.sql']
            );
            $this->assertNotNull(
                $rowAfter,
                'a missing .down.sql must RAISE, never silently drop the ledger row — the schema is still there and must stay tracked'
            );
            $this->assertTrue($db->tableExists('nd'));
        } finally {
            $db->close();
        }
    }

    public function testFailedDownStatementDoesNotDropLedger(): void
    {
        file_put_contents($this->migrationsDir . '/000001_bad_down.sql', 'CREATE TABLE bd (id INTEGER)');
        file_put_contents($this->migrationsDir . '/000001_bad_down.down.sql', 'THIS IS NOT VALID SQL');
        $db = new SQLite3Adapter(':memory:');
        try {
            $migration = new Migration($db, $this->migrationsDir);
            $migration->migrate();

            $result = $migration->rollback();
            $this->assertArrayHasKey('000001_bad_down.sql', $result['errors']);

            $rowAfter = $db->fetchOne(
                'SELECT migration_name FROM tina4_migration WHERE migration_name = :n',
                [':n' => '000001_bad_down.sql']
            );
            $this->assertNotNull($rowAfter, 'a FAILING down statement must also never drop the ledger row');
        } finally {
            $db->close();
        }
    }

    // ── firebird-mssql-create-add-idempotency-real ──────────────────────────
    // Already covered end-to-end by MigrationFootgunsLiveEngineTest ("NO
    // DOUBLES") — the model this release's Ruby/Node conversion follows. No
    // change needed in PHP; re-asserted here under the shared case name so
    // the cross-language fixture can point at ONE case per framework.

    public function testFirebirdMssqlCreateAddIdempotencyReal(): void
    {
        $mssql = $this->mssqlOrSkip();
        $mssqlTable = 'nomock_mig_contract_php_mssql';
        try {
            $mssql->execute("IF OBJECT_ID('{$mssqlTable}','U') IS NOT NULL DROP TABLE {$mssqlTable}");
            $mssql->commit();
            $mssql->execute("CREATE TABLE {$mssqlTable} (id INT)");
            $mssql->commit();
            $this->assertTrue($mssql->tableExists($mssqlTable));

            $m = new Migration($mssql, $this->migrationsDir);
            $reason = $this->invokePrivate($m, 'shouldSkipCreateTable', ["CREATE TABLE {$mssqlTable} (id INT)"]);
            $this->assertNotNull($reason, 'a really-existing MSSQL table must make CREATE TABLE skip');
            $this->assertStringContainsString($mssqlTable, $reason);
        } finally {
            $mssql->execute("IF OBJECT_ID('{$mssqlTable}','U') IS NOT NULL DROP TABLE {$mssqlTable}");
            $mssql->commit();
            $mssql->close();
        }

        $fb = $this->firebirdOrSkip();
        $fbTable = 'nomock_mig_ctr_php_fb';
        try {
            try {
                $fb->execute("DROP TABLE {$fbTable}");
                $fb->commit();
            } catch (\Throwable) {
            }
            $fb->execute("CREATE TABLE {$fbTable} (id INTEGER NOT NULL PRIMARY KEY)");
            $fb->commit();
            $this->assertTrue($fb->tableExists($fbTable));

            $m = new Migration($fb, $this->migrationsDir);
            $reason = $this->invokePrivate($m, 'shouldSkipCreateTable', ["CREATE TABLE {$fbTable} (id INTEGER NOT NULL PRIMARY KEY)"]);
            $this->assertNotNull($reason, 'a really-existing Firebird table must make CREATE TABLE skip');

            // ALTER TABLE ... ADD idempotency (Firebird-only guard) — a REAL
            // column on a REAL table makes it fire.
            $fb->execute("ALTER TABLE {$fbTable} ADD extra_col VARCHAR(50)");
            $fb->commit();
            $addReason = $this->invokePrivate($m, 'shouldSkipForFirebird', ["ALTER TABLE {$fbTable} ADD extra_col VARCHAR(50)"]);
            $this->assertNotNull($addReason, 'a really-existing Firebird column must make ALTER ADD skip');
            $this->assertStringContainsString('extra_col', $addReason);

            // Negative control: an absent column must not be skipped.
            $absentReason = $this->invokePrivate($m, 'shouldSkipForFirebird', ["ALTER TABLE {$fbTable} ADD never_added VARCHAR(10)"]);
            $this->assertNull($absentReason, 'an absent column must NOT be skipped — the ADD has to run');
        } finally {
            try {
                $fb->execute("DROP TABLE {$fbTable}");
                $fb->commit();
            } catch (\Throwable) {
            }
            $fb->close();
        }
    }
}
