<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

/**
 * Child probe for tests/DatabaseConnectTimeoutTest.php.
 *
 * Opens ONE real adapter against one real target and reports how long the
 * connect took and what it threw. It runs in its own process ON PURPOSE: the
 * defect under test is an UNBOUNDED connect, so an in-process assertion would
 * hang the whole suite instead of failing it. The parent caps each probe and
 * kills it, which turns a regression into a bounded failure.
 *
 * Nothing here is a double — a real PHP process, the real adapter, the real
 * driver, a real socket.
 *
 * Usage: php connectTimeoutProbe.php <adapter> <host> <port>
 * Output: ELAPSED=<seconds> then OUTCOME=<CONNECTED|exception message>
 */

require __DIR__ . '/../../vendor/autoload.php';

$adapter = $argv[1] ?? '';
$host = $argv[2] ?? '';
$port = (int) ($argv[3] ?? 0);

$startedAt = microtime(true);

$report = static function (string $outcome) use ($startedAt): void {
    printf("ELAPSED=%.3f\n", microtime(true) - $startedAt);
    printf("OUTCOME=%s\n", str_replace("\n", ' ', $outcome));
    exit(0);
};

try {
    switch ($adapter) {
        case 'pgsql':
            new \Tina4\Database\PostgresAdapter("postgres://probe:probe@{$host}:{$port}/probe");
            break;
        case 'pdopgsql':
            new \Tina4\Database\PdoPostgresAdapter("postgres://probe:probe@{$host}:{$port}/probe");
            break;
        case 'mysql':
            new \Tina4\Database\MySQLAdapter("mysql://probe:probe@{$host}:{$port}/probe");
            break;
        case 'firebird':
            new \Tina4\Database\FirebirdAdapter("firebird://SYSDBA:masterkey@{$host}:{$port}//tmp/probe.fdb");
            break;
        case 'pdofirebird':
            new \Tina4\Database\PdoFirebirdAdapter("firebird://SYSDBA:masterkey@{$host}:{$port}//tmp/probe.fdb");
            break;
        case 'mongodb':
            new \Tina4\Database\MongoDBAdapter("mongodb://{$host}:{$port}/probe");
            break;
        case 'mssql':
            new \Tina4\Database\MSSQLAdapter("mssql://sa:probe@{$host}:{$port}/probe");
            break;
        // The healthy-Firebird control. It runs out here rather than in the
        // PHPUnit process on purpose: ext-interbase shares ONE physical link
        // across connections opened with identical arguments, and adding a
        // second holder of that link from inside the suite desynchronised
        // FirebirdAdapter's per-signature reference count and left twelve
        // unrelated live-Firebird tests erroring with "invalid database handle
        // (no active connection)". A separate process cannot perturb it.
        case 'firebirdlive':
            $url = getenv('TINA4_TEST_FIREBIRD_URL');
            if ($url === false || $url === '') {
                $report('NO FIREBIRD URL');
            }
            $database = \Tina4\Database\Database::create($url);
            $rows = $database->fetch('SELECT 1 AS ALIVE FROM RDB$DATABASE')->toArray();
            $alive = (int) ($rows[0]['ALIVE'] ?? $rows[0]['alive'] ?? 0);
            $report($alive === 1 ? 'CONNECTED' : 'QUERY RETURNED ' . var_export($rows, true));
            break;
        default:
            $report("UNKNOWN ADAPTER {$adapter}");
    }
    $report('CONNECTED');
} catch (\Throwable $e) {
    $report($e->getMessage());
}
