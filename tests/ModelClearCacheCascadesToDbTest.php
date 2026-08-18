<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Regression: Model::clearCache() must invalidate BOTH cache layers.
 *
 * PY-06-22 (3.13.105). Before the fix, Model::clearCache() cleared only the
 * ORM-layer tag cache (QueryCache) and left the DB-layer cache alone — so a
 * caller using it as a manual escape hatch (an out-of-band write, a race with
 * another process, a deliberate refresh) still read stale rows from db.fetch()
 * on the next query.
 *
 * The invariant: after Model::clearCache(), $db->cacheStats()['size'] on this
 * model's connection is 0. Named positive AND negative cases; proven a real
 * gate by mutation (revert the cascade call — both fail).
 *
 * NOT a mock: real SQLite Database instance (via Database::create), real ORM
 * cached() round-trip that populates both layers.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

class Widget622ClearCache extends \Tina4\ORM
{
    public string $tableName = 'widgets_622';
    public string $primaryKey = 'id';
}

class ModelClearCacheCascadesToDbTest extends TestCase
{
    /** @var array<int, string> */
    private array $tempFiles = [];

    /** @var array<int, string> */
    private array $managedEnvKeys = [
        'TINA4_AUTO_CACHING',
        'TINA4_AUTO_CACHING_TTL',
        'TINA4_DB_CACHE',
        'TINA4_DB_CACHE_TTL',
        'TINA4_DB_CACHE_BACKEND',
        'TINA4_DB_CACHE_URL',
    ];

    protected function setUp(): void
    {
        \Tina4\DotEnv::resetEnv();
        foreach ($this->managedEnvKeys as $key) {
            unset($_ENV[$key]);
            putenv($key);
        }
        // Both cache layers opted in — the only combination in which the
        // PY-06-22 bug is reachable (the DB layer is dormant otherwise).
        $this->setEnv('TINA4_AUTO_CACHING', 'true');
        $this->setEnv('TINA4_DB_CACHE', 'true');
        $this->setEnv('TINA4_DB_CACHE_BACKEND', 'memory');
    }

    protected function tearDown(): void
    {
        \Tina4\DotEnv::resetEnv();
        foreach ($this->managedEnvKeys as $key) {
            unset($_ENV[$key]);
            putenv($key);
        }
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    private function freshDb(): \Tina4\Database\Database
    {
        $path = \TempPath::file('widgets622_', '.db');
        $this->tempFiles[] = $path;
        $db = Database::create('sqlite:///' . $path);
        $db->exec(
            'CREATE TABLE widgets_622 '
            . '(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)'
        );
        $db->exec("INSERT INTO widgets_622 (name) VALUES ('one'), ('two')");
        return $db;
    }

    public function testPositiveClearCacheCascadesToDbLayer(): void
    {
        $db = $this->freshDb();
        $widget = new Widget622ClearCache();
        $widget->setDb($db);

        // Populate BOTH layers: ORM query cache (via cached() tag) AND the
        // wrapped DB layer (CachedDatabase writes through on the underlying
        // select() call).
        $widget->cached('SELECT * FROM widgets_622', [], 60);
        $this->assertGreaterThan(
            0,
            $db->cacheStats()['size'],
            'prime failed: db cache did not populate on the cached() read'
        );

        $widget->clearCache();

        $this->assertSame(
            0,
            $db->cacheStats()['size'],
            'clearCache() did not cascade to db->cacheClear(); '
            . 'the db cache still holds stale rows and a read-after-write '
            . 'through the DB layer will serve them'
        );
    }

    public function testNegativeClearCacheLeavesUnrelatedDbsAlone(): void
    {
        // A model bound to db_a calling clearCache() must NOT touch an
        // unrelated db_b (the cascade is scoped to this model's own connection,
        // matching how ORM writes already behave).
        $dbA = $this->freshDb();
        $dbB = $this->freshDb();
        $widget = new Widget622ClearCache();
        $widget->setDb($dbA);

        // Prime BOTH connections' DB caches with a read this test owns.
        $dbA->fetch('SELECT * FROM widgets_622');
        $dbB->fetch('SELECT * FROM widgets_622');
        $this->assertGreaterThan(0, $dbA->cacheStats()['size']);
        $this->assertGreaterThan(0, $dbB->cacheStats()['size']);

        $widget->clearCache();

        $this->assertSame(
            0,
            $dbA->cacheStats()['size'],
            'db_a is the widget\'s bound connection and must have been cleared'
        );
        $this->assertGreaterThan(
            0,
            $dbB->cacheStats()['size'],
            'db_b (unrelated connection) must NOT be cleared by widget->clearCache()'
        );
    }
}
