<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * SILENT PDO fallback — factory selection.
 *
 * Database::create() must:
 *   1. keep the NATIVE adapter as the default whenever the native extension is
 *      present (the fallback must never de-prefer native), and
 *   2. select the matching PDO sibling ONLY when the native extension is absent,
 *      raising a clear combined error when neither driver is available.
 *
 * The native DB extensions on the CI/dev image are statically compiled, so this
 * process cannot unload ext-sqlite3 / ext-pgsql to exercise their fallback
 * branch in-process. What IS proven here for real:
 *   - native is the default (in-process),
 *   - the PDO sibling the factory would return is a working DatabaseAdapter
 *     end-to-end (in-process), and
 *   - the native-absent decision path runs and raises the combined error, via a
 *     no-extension subprocess (php -n unloads the shared ext-interbase).
 * No mocks — every assertion runs against the real driver / real decision.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\DatabaseAdapter;
use Tina4\Database\SQLite3Adapter;
use Tina4\Database\PdoSqliteAdapter;
use Tina4\Database\PostgresAdapter;
use Tina4\Database\PdoFirebirdAdapter;

class PdoFallbackFactoryTest extends TestCase
{
    public function testNativeSqliteIsDefaultWhenExtPresent(): void
    {
        if (!class_exists('SQLite3')) {
            $this->markTestSkipped('ext-sqlite3 not present — cannot assert native default.');
        }
        $db = Database::create('sqlite::memory:');
        $this->assertInstanceOf(
            SQLite3Adapter::class,
            $db->getAdapter(),
            'ext-sqlite3 present -> factory must keep the NATIVE SQLite3Adapter (fallback never de-prefers native)'
        );
        $db->close();
    }

    public function testNativePostgresIsDefaultWhenExtPresent(): void
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('ext-pgsql not present — cannot assert native default.');
        }
        $pg = \PgTestEnv::resolve();
        if (!$pg->reachable()) {
            $this->markTestSkipped(sprintf('PostgreSQL not reachable at %s:%d', $pg->host, $pg->port));
        }
        $db = Database::create($pg->url('tina4'), username: $pg->user, password: $pg->pass);
        $this->assertInstanceOf(
            PostgresAdapter::class,
            $db->getAdapter(),
            'ext-pgsql present -> factory must keep the NATIVE PostgresAdapter'
        );
        $db->close();
    }

    /**
     * The exact adapter the factory hands back when ext-sqlite3 is absent must
     * be a fully working DatabaseAdapter — construct it directly and run a full
     * CRUD + read cycle against a real pdo_sqlite database.
     */
    public function testPdoSqliteFallbackIsAWorkingDatabaseAdapter(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not present.');
        }
        $adapter = new PdoSqliteAdapter(':memory:');
        $this->assertInstanceOf(DatabaseAdapter::class, $adapter);

        $adapter->execute('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, qty INTEGER)');
        $adapter->insert('widgets', ['name' => 'alpha', 'qty' => 3]);
        $this->assertSame(1, $adapter->lastInsertId());
        $this->assertTrue($adapter->tableExists('widgets'));

        $row = $adapter->fetchOne('SELECT name, qty FROM widgets WHERE id = 1');
        $this->assertSame('alpha', $row['name']);
        $this->assertSame(3, $row['qty']);       // native int, not '3'

        $adapter->update('widgets', ['qty' => 9], 'id = ?', [1]);
        $this->assertSame(9, $adapter->fetchOne('SELECT qty FROM widgets WHERE id = 1')['qty']);

        $adapter->delete('widgets', 'id = ?', [1]);
        $this->assertNull($adapter->fetchOne('SELECT qty FROM widgets WHERE id = 1'));
        $adapter->close();
    }

    /**
     * When BOTH the native extension AND the PDO driver are absent for an
     * engine, the factory raises a clear combined error naming both options.
     * Exercised for real in a no-extension subprocess (php -n unloads the
     * shared ext-interbase); portable — asserts only when the subprocess env
     * genuinely has neither Firebird driver.
     */
    public function testFactoryRaisesCombinedErrorWhenNoFirebirdDriver(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $script = <<<'PHP'
require %s;
$avail = [
    'ibase'   => function_exists('ibase_connect'),
    'fbird'   => function_exists('fbird_connect'),
    // `php -n` unloads ext-pdo on shared-ext builds (CI Linux), so the PDO class
    // can be absent here — guard it, exactly as Database::makeFirebird() does.
    'pdo_fb'  => class_exists('PDO') && in_array('firebird', PDO::getAvailableDrivers(), true),
];
try {
    \Tina4\Database\Database::create('firebird://SYSDBA:masterkey@localhost:3050/test.fdb');
    $avail['result'] = 'no-error';
    $avail['msg'] = '';
} catch (\RuntimeException $e) {
    $avail['result'] = 'runtime-exception';
    $avail['msg'] = $e->getMessage();
} catch (\Throwable $e) {
    $avail['result'] = 'other:' . get_class($e);
    $avail['msg'] = $e->getMessage();
}
echo json_encode($avail);
PHP;
        $script = sprintf($script, var_export($autoload, true));
        $cmd = escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg($script);
        $out = shell_exec($cmd);
        $this->assertNotNull($out, 'subprocess produced no output');
        $data = json_decode(trim($out), true);
        $this->assertIsArray($data, "subprocess output was not JSON: {$out}");

        if ($data['ibase'] || $data['fbird'] || $data['pdo_fb']) {
            $this->markTestSkipped(
                'Subprocess still has a Firebird driver — cannot exercise the no-driver error path here.'
            );
        }

        // Neither native ext-interbase nor pdo_firebird -> combined error.
        $this->assertSame('runtime-exception', $data['result'], "unexpected result: {$out}");
        $this->assertStringContainsString('Firebird requires', $data['msg']);
        $this->assertStringContainsString('pdo_firebird', $data['msg']);
    }

    /**
     * The driver override is what makes the fallback usable where it matters:
     * a host with a PRESENT-but-BROKEN ext-interbase (the macOS + FB5 clumplet
     * case). `?driver=pdo` on the URL must force the working PdoFirebirdAdapter
     * even though ibase_connect exists, so the app is not stuck on the broken
     * native driver. Needs a reachable server (the adapter opens on construct).
     */
    public function testUrlDriverOverrideForcesPdoFirebird(): void
    {
        [$url, $user, $pass] = $this->firebirdTarget();
        $sep = str_contains($url, '?') ? '&' : '?';
        $db = Database::create($url . $sep . 'driver=pdo', username: $user, password: $pass);
        $this->assertInstanceOf(
            PdoFirebirdAdapter::class,
            $db->getAdapter(),
            '?driver=pdo must force the pdo_firebird adapter even when ext-interbase is present'
        );
        $db->close();
    }

    /**
     * The app-wide switch — TINA4_FIREBIRD_DRIVER=pdo — does the same for every
     * Firebird connection (the setting a macOS/FB5 dev drops in .env).
     */
    public function testEnvDriverOverrideForcesPdoFirebird(): void
    {
        [$url, $user, $pass] = $this->firebirdTarget();
        putenv('TINA4_FIREBIRD_DRIVER=pdo');
        try {
            $db = Database::create($url, username: $user, password: $pass);
            $this->assertInstanceOf(
                PdoFirebirdAdapter::class,
                $db->getAdapter(),
                'TINA4_FIREBIRD_DRIVER=pdo must force the pdo_firebird adapter'
            );
            $db->close();
        } finally {
            putenv('TINA4_FIREBIRD_DRIVER');
        }
    }

    /**
     * The silent-fallback contract, end to end: auto-mode (no override) must
     * hand back a WORKING Firebird connection even when native ext-interbase is
     * present-but-broken. On this macOS host the native driver clumplets on every
     * connect, so a plain Database::create('firebird://...') proves the automatic
     * "ibase broken -> pdo" fallback: it does not throw, and a query runs. On a
     * host with a working native driver the same call succeeds via native — either
     * way the guarantee is "it just connects".
     */
    public function testAutoModeYieldsWorkingAdapterEvenWhenNativeBroken(): void
    {
        [$url, $user, $pass] = $this->firebirdTarget();
        $db = Database::create($url, username: $user, password: $pass);
        $row = $db->fetchOne('SELECT 1 AS N FROM RDB$DATABASE');
        $this->assertNotNull($row, 'auto-mode Firebird connection must return a live query result');
        $this->assertSame(1, (int) ($row['N'] ?? $row['n'] ?? reset($row)));
        $db->close();
    }

    /** Real Firebird target for the driver-override tests, or a loud skip. */
    private function firebirdTarget(): array
    {
        if (!in_array('firebird', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_firebird driver not present — driver override UNVERIFIED.');
        }
        $url = getenv('TINA4_TEST_FIREBIRD_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped('TINA4_TEST_FIREBIRD_URL not set (needs a real Firebird server) — UNVERIFIED.');
        }
        return [$url, 'SYSDBA', 'masterkey'];
    }
}
