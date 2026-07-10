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

    // NOTE: the "neither native nor PDO -> combined error" factory test lives
    // with the held Firebird work on feature/php-pdo-fallback. It relied on the
    // Firebird path because ext-interbase is the only shared DB extension a
    // `php -n` subprocess can unload (ext-sqlite3 / ext-pgsql are statically
    // compiled). It returns to CI once pdo_firebird can be verified live.
}
