<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * The execute() contract, with PHP as the reference for all four frameworks:
 * execute() returns the rows of any statement that produces them - a SELECT, a
 * WITH ... SELECT, an INSERT/UPDATE/DELETE ... RETURNING (MSSQL: OUTPUT), a
 * CALL/EXEC with a result set - as a DatabaseResult. The statement runs exactly
 * once, with no COUNT probe and no LIMIT/OFFSET. A write that produces no rows
 * still returns true.
 *
 * Every adapter is exercised through the Database facade in its own real php
 * process against its real engine. The PDO adapters are reached the only way a
 * user reaches them - with the native extension genuinely absent (PhpChild
 * removes ext-pgsql / ext-sqlite3 from the child), so Database::create() falls
 * back to PDO. The table is read back through a SECOND connection.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExecuteReturnsRowsContractTest extends TestCase
{
    private const TABLE = 'php_execute_rows_contract';

    /** @return array<string, array{0: string}> */
    public static function legs(): array
    {
        return [
            'PostgresAdapter (ext-pgsql)' => ['pg-native'],
            'PdoPostgresAdapter' => ['pg-pdo'],
            'SQLite3Adapter' => ['sqlite3'],
            'PdoSqliteAdapter' => ['sqlite-pdo'],
            'MySQLAdapter' => ['mysql'],
            'MSSQLAdapter (pdo_dblib)' => ['mssql-dblib'],
            'MSSQLAdapter (sqlsrv)' => ['mssql-sqlsrv'],
            'FirebirdAdapter (ext-interbase)' => ['firebird-native'],
            'PdoFirebirdAdapter' => ['firebird-pdo'],
            'ODBCAdapter' => ['odbc'],
        ];
    }

    /**
     * The URL, dialect, and the extensions to remove from the child for a leg.
     *
     * @return array{0: string, 1: string, 2: list<string>, 3: string}
     */
    private function legConfig(string $leg): array
    {
        $env = static fn(string $name): string => (string) (getenv($name) ?: '');
        $pg = PgTestEnv::resolve();
        $pgUrl = $pg->reachable() ? "postgres://{$pg->user}:{$pg->pass}@{$pg->host}:{$pg->port}/tina4_php" : '';
        $sqlite = 'sqlite:///' . sys_get_temp_dir() . '/php_execute_rows_' . getmypid() . '.db';
        $firebird = $env('TINA4_TEST_FIREBIRD_URL');
        [$url, $dialect, $without, $adapter] = match ($leg) {
            'pg-native' => [$pgUrl, 'postgres', [], 'PostgresAdapter'],
            'pg-pdo' => [$pgUrl, 'postgres', ['pgsql'], 'PdoPostgresAdapter'],
            'sqlite3' => [$sqlite, 'sqlite', [], 'SQLite3Adapter'],
            'sqlite-pdo' => [$sqlite, 'sqlite', ['sqlite3'], 'PdoSqliteAdapter'],
            'mysql' => [$env('TINA4_TEST_MYSQL_URL'), 'mysql', [], 'MySQLAdapter'],
            'mssql-dblib' => [$env('TINA4_TEST_MSSQL_URL'), 'mssql', ['sqlsrv', 'pdo_sqlsrv'], 'MSSQLAdapter'],
            'mssql-sqlsrv' => [$env('TINA4_TEST_MSSQL_URL'), 'mssql', [], 'MSSQLAdapter'],
            'firebird-native' => [$firebird === '' ? '' : $firebird . '?driver=interbase', 'firebird', [], 'FirebirdAdapter'],
            'firebird-pdo' => [$firebird === '' ? '' : $firebird . '?driver=pdo', 'firebird', [], 'PdoFirebirdAdapter'],
            'odbc' => [$env('TINA4_TEST_ODBC_DSN') === '' ? '' : 'odbc:///' . $env('TINA4_TEST_ODBC_DSN'), 'postgres', [], 'ODBCAdapter'],
        };
        if ($url === '') {
            $need = match (true) {
                str_starts_with($leg, 'pg-') => 'postgres',
                str_starts_with($leg, 'mssql-') => 'mssql',
                str_starts_with($leg, 'firebird-') => 'firebird',
                $leg === 'odbc' => 'runtime=odbc',
                default => $leg,
            };
            $this->markTestSkipped("[needs:{$need}] {$leg}: its engine is not reachable here — set its TINA4_TEST_* variable");
        }
        if ($leg === 'mssql-sqlsrv' && !extension_loaded('sqlsrv')) {
            $this->markTestSkipped('[needs:runtime=sqlsrv] ext-sqlsrv is not installed on this host — the sqlsrv leg is UNVERIFIED here');
        }
        foreach ($without as $extension) {
            if (extension_loaded($extension) && !PhpChild::extensionCanBeRemoved($extension)) {
                $this->markTestSkipped("{$extension} is compiled in statically and cannot be removed [needs:absent-ext={$extension}]");
            }
        }
        // The child copies the build's own conf.d, not this run's
        // PHP_INI_SCAN_DIR, so grpc and swoole - which deadlock a php process
        // doing network I/O - are always left out of it as well.
        $without = array_merge(array_filter($without, 'extension_loaded'), ['grpc', 'openswoole', 'swoole']);
        return [$url, $dialect, array_values($without), $adapter];
    }

    /**
     * Run the whole scenario in a real child process and return its report.
     *
     * @return array<string, mixed>
     */
    private function runLeg(string $leg): array
    {
        [$url, $dialect, $without, $adapter] = $this->legConfig($leg);
        $table = self::TABLE;
        $ddl = match ($dialect) {
            'postgres' => "CREATE TABLE {$table} (id serial PRIMARY KEY, txt varchar(20) NOT NULL)",
            'sqlite' => "CREATE TABLE {$table} (id integer PRIMARY KEY AUTOINCREMENT, txt varchar(20) NOT NULL)",
            'mysql' => "CREATE TABLE {$table} (id int AUTO_INCREMENT PRIMARY KEY, txt varchar(20) NOT NULL)",
            'mssql' => "CREATE TABLE {$table} (id int IDENTITY PRIMARY KEY, txt varchar(20) NOT NULL)",
            'firebird' => "CREATE TABLE {$table} (id integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, txt varchar(20) NOT NULL)",
        };
        $insertReturning = match ($dialect) {
            'mssql' => "INSERT INTO {$table} (txt) OUTPUT INSERTED.id VALUES (?)",
            'mysql' => null,
            default => "INSERT INTO {$table} (txt) VALUES (?) RETURNING id",
        };

        $source = 'require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ";\n"
            . PhpChild::instrumentSource($without)
            . '$url = ' . var_export($url, true) . ";\n"
            . '$table = ' . var_export($table, true) . ";\n"
            . '$ddl = ' . var_export($ddl, true) . ";\n"
            . '$insertReturning = ' . var_export($insertReturning, true) . ";\n"
            . '$dialect = ' . var_export($dialect, true) . ";\n"
            . <<<'PHP'
$shape = static fn($value) => $value instanceof \Tina4\Database\DatabaseResult
    ? ['class' => 'DatabaseResult', 'records' => array_map(static fn($row) => array_map('strval', $row), $value->records)]
    : ['class' => get_debug_type($value), 'value' => $value];
$db = \Tina4\Database\Database::create($url);
try { $db->execute("DROP TABLE {$table}"); } catch (\Throwable) {}
$db->execute($ddl);
$db->execute("INSERT INTO {$table} (txt) VALUES (?)", ['a']);
$db->execute("INSERT INTO {$table} (txt) VALUES (?)", ['b']);
$report = ['instrument' => $instrument, 'adapter' => (new \ReflectionClass($db->getAdapter()))->getShortName()];
$report['pid'] = $dialect === 'postgres' ? (int) $db->fetchOne('SELECT pg_backend_pid() AS pid')['pid'] : null;

$select = "SELECT id, txt FROM {$table} WHERE txt = ? ORDER BY id";
$report['select'] = $shape($db->execute($select, ['a']));
$report['fetchAll'] = array_map(static fn($row) => array_map('strval', $row), $db->fetchAll($select, ['a']));
$report['with'] = $shape($db->execute("WITH x AS (SELECT id, txt FROM {$table}) SELECT id, txt FROM x WHERE txt = ? ORDER BY id", ['a']));
$report['insert'] = $insertReturning === null
    ? $shape($db->execute("INSERT INTO {$table} (txt) VALUES (?)", ['c']))
    : $shape($db->execute($insertReturning, ['c']));
$report['update'] = $shape($db->execute("UPDATE {$table} SET txt = ? WHERE txt = ?", ['d', 'b']));

$fresh = \Tina4\Database\Database::create($url);
$report['c_rows'] = array_map(static fn($row) => array_map('strval', $row), $fresh->fetchAll("SELECT id FROM {$table} WHERE txt = ?", ['c'], 0, 0, true));
$report['d_rows'] = count($fresh->fetchAll("SELECT id FROM {$table} WHERE txt = ?", ['d'], 0, 0, true));
if ($dialect === 'postgres' && $report['pid'] !== null) {
    $report['pg_state'] = $fresh->fetchOne('SELECT state FROM pg_stat_activity WHERE pid = ?', [$report['pid']], true)['state'] ?? null;
}
$db->close();
try { $fresh->execute("DROP TABLE {$table}"); } catch (\Throwable) {}
PHP
            . "\n" . PhpChild::reportSource('$report');

        $environment = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'TMPDIR' => sys_get_temp_dir(), 'TINA4_DEBUG' => 'false'];
        $result = PhpChild::runWithoutExtensions($without, $source, $environment);
        $this->assertIsArray($result['report'], "{$leg}: the child produced no report:\n{$result['stdout']}\n{$result['stderr']}");
        foreach ($without as $extension) {
            $this->assertFalse($result['report']['instrument']["extension_loaded:{$extension}"], "{$leg}: {$extension} must really be absent in the child");
        }
        $this->assertSame($adapter, $result['report']['adapter'], "{$leg}: the facade must have picked {$adapter}");
        return $result['report'];
    }

    #[DataProvider('legs')]
    public function testExecuteReturnsTheRowsOfEveryStatementThatProducesThem(string $leg): void
    {
        $report = $this->runLeg($leg);

        $this->assertSame('DatabaseResult', $report['select']['class'], "{$leg}: execute(SELECT) returns a DatabaseResult");
        $this->assertCount(1, $report['fetchAll']);
        $this->assertSame($report['fetchAll'], $report['select']['records'], "{$leg}: execute(SELECT) returns the rows fetchAll() returns");
        $this->assertSame($report['fetchAll'], $report['with']['records'] ?? null, "{$leg}: execute(WITH ... SELECT) returns the rows too");

        if (str_starts_with($leg, 'mysql')) {
            $this->assertSame(['class' => 'bool', 'value' => true], $report['insert'], 'MySQL has no RETURNING: a plain INSERT returns true');
        } else {
            $this->assertSame('DatabaseResult', $report['insert']['class'], "{$leg}: execute(INSERT ... RETURNING) returns a DatabaseResult");
            $this->assertCount(1, $report['insert']['records'], "{$leg}: ... holding the one returned row");
            $this->assertSame($report['c_rows'][0]['id'] ?? null, $report['insert']['records'][0]['id'] ?? null,
                "{$leg}: the returned id is the id of the row that was written");
        }
        $this->assertCount(1, $report['c_rows'], "{$leg}: the table holds EXACTLY one inserted row (run once, committed)");

        $this->assertSame(['class' => 'bool', 'value' => true], $report['update'], "{$leg}: a write with no rows returns true");
        $this->assertSame(1, $report['d_rows'], "{$leg}: the UPDATE was applied");

        if (isset($report['pid'])) {
            $this->assertSame('idle', $report['pg_state'], "{$leg}: the connection is not left idle in transaction");
        }
    }
}
