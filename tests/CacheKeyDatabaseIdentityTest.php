<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * CACHE CONTRACT - a query-cache key carries DATABASE IDENTITY.
 *
 * Pins `the-cache-key-carries-database-identity` from
 * tina4-documentation/plan/v3/fixtures/cache_contract.json (ADR-0024):
 *
 *     A query-cache key identifies the DATABASE it came from. Two databases
 *     sharing one cache backend can never serve each other's rows.
 *
 * This is a DATA ISOLATION failure wearing a caching costume. The key was
 * sha256(sql . params) with nothing naming the connection, so on ANY shared
 * backend two databases cross-served each other's rows: two apps pointed at one
 * Redis, or one app with a primary and an analytics connection, silently read
 * each other's data. Identical SQL text is exactly what a multi-tenant
 * deployment runs, so the collision is the common case, not an edge case.
 *
 * Everything here runs against REAL databases (two real SQLite files and two
 * real PostgreSQL databases) and a REAL shared Redis. Nothing is simulated.
 * Reflection is used to read the private key helper - that inspects the code
 * under test, it does not stand in for it.
 *
 * SERVICE ADDRESSES
 *     TINA4_TEST_CACHE_REDIS_URL  (default redis://127.0.0.1:6379)
 *     TINA4_TEST_POSTGRES_URL     (via the shared PgTestEnv helper)
 *
 * A skip reason below names its service, so under TINA4_REQUIRE_SERVICES=1 an
 * unreachable service is a hard FAILURE, never a quiet green.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\CachedDatabase;
use Tina4\Database\Database;

class CacheKeyDatabaseIdentityTest extends TestCase
{
    private const DEFAULT_REDIS_URL = 'redis://127.0.0.1:6379';

    /**
     * Databases this contract OWNS, created on demand and never dropped.
     *
     * PHP-specific names on purpose. The shared `tina4_cache_contract_a/b` pair
     * was dropped out from under a run by a sibling framework's suite mid-flight
     * - measured, the run errored with 'database "tina4_cache_contract_a" does
     * not exist' seconds after the same databases had been listed as present.
     * Depending on a database somebody else creates is also why this would have
     * ERRORED on a fresh CI PostgreSQL, where neither name exists at all.
     */
    private const PG_DB_A = 'tina4_cache_ident_php_a';
    private const PG_DB_B = 'tina4_cache_ident_php_b';

    /** @var array<int, string> files created by a test, removed in tearDown */
    private array $temporaryFiles = [];

    private function redisUrl(): string
    {
        return getenv('TINA4_TEST_CACHE_REDIS_URL') ?: self::DEFAULT_REDIS_URL;
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    private function clearEnv(string $key): void
    {
        unset($_ENV[$key]);
        putenv($key);
    }

    /** @return array<int, string> */
    private function managedEnvKeys(): array
    {
        return [
            'TINA4_DB_CACHE', 'TINA4_DB_CACHE_TTL', 'TINA4_DB_CACHE_BACKEND',
            'TINA4_DB_CACHE_URL', 'TINA4_AUTO_CACHING', 'TINA4_AUTO_CACHING_TTL',
        ];
    }

    protected function setUp(): void
    {
        \Tina4\DotEnv::resetEnv();
        foreach ($this->managedEnvKeys() as $key) {
            $this->clearEnv($key);
        }
    }

    protected function tearDown(): void
    {
        \Tina4\DotEnv::resetEnv();
        foreach ($this->managedEnvKeys() as $key) {
            $this->clearEnv($key);
        }
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->temporaryFiles = [];
    }

    /** Point the persistent DB query cache at ONE real shared Redis. */
    private function useSharedRedisCache(): void
    {
        $this->setEnv('TINA4_DB_CACHE', 'true');
        $this->setEnv('TINA4_DB_CACHE_TTL', '60');
        $this->setEnv('TINA4_DB_CACHE_BACKEND', 'redis');
        $this->setEnv('TINA4_DB_CACHE_URL', $this->redisUrl());
    }

    private function requireRedis(): void
    {
        $url = $this->redisUrl();
        $parts = parse_url(str_contains($url, '://') ? $url : '//' . $url);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = (int)($parts['port'] ?? 6379);
        $sock = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$sock) {
            $this->markTestSkipped("redis service not reachable at {$host}:{$port}");
        }
        fclose($sock);
    }

    /** A SQLite URL for an absolute path (four slashes = absolute). */
    private function sqliteUrl(string $absolutePath): string
    {
        return 'sqlite:///' . $absolutePath;
    }

    private function tempDatabasePath(string $name): string
    {
        $path = sys_get_temp_dir() . '/tina4-cache-identity-' . bin2hex(random_bytes(6)) . "-{$name}.db";
        $this->temporaryFiles[] = $path;
        return $path;
    }

    /**
     * The live CachedDatabase decorator wrapping this connection.
     *
     * Database::getAdapter() deliberately UNWRAPS the decorator, so the wrapper
     * is read off the private property. Going through the real Database is the
     * point: it proves the connection URL actually reaches the key, rather than
     * proving a CachedDatabase built by hand in the test can be told about one.
     */
    private function cachedAdapter(Database $database): CachedDatabase
    {
        $adapter = (new ReflectionObject($database))->getProperty('adapter')->getValue($database);
        $this->assertInstanceOf(
            CachedDatabase::class,
            $adapter,
            'the query cache is not enabled - the env must be set BEFORE Database::create()'
        );
        return $adapter;
    }

    /** @param array<int, mixed> $params */
    private function cacheKeyOf(Database $database, string $sql, array $params): string
    {
        $adapter = $this->cachedAdapter($database);
        return (new ReflectionObject($adapter))
            ->getMethod('cacheKey')
            ->invoke($adapter, $sql, $params);
    }

    /**
     * Create the two contract databases if they are absent.
     *
     * WHY THIS EXISTS: the case used to assume both databases already existed,
     * which was only ever true because somebody had created them by hand. A
     * fresh CI PostgreSQL has neither, so the case would have been RED in CI and
     * GREEN locally - the worst possible split, and exactly the
     * environment-dependent false green this contract exists to stamp out. It
     * was not theoretical: a sibling framework's suite dropped the shared pair
     * mid-run and this file errored on the spot.
     *
     * CREATE DATABASE cannot run inside a transaction block, so this uses a
     * plain PDO connection to the `postgres` maintenance database, which is in
     * autocommit unless a transaction is opened. That is a REAL connection to
     * the REAL server doing real DDL - no stand-in anywhere.
     *
     * Creating is idempotent and cheap; DROPPING is not done at all, because
     * that would make concurrent runs fight each other.
     */
    private function ensurePostgresDatabases(PgTestEnv $postgres): void
    {
        $admin = new PDO(
            "pgsql:host={$postgres->host};port={$postgres->port};dbname=postgres",
            $postgres->user,
            $postgres->pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        foreach ([self::PG_DB_A, self::PG_DB_B] as $database) {
            $exists = $admin->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
            $exists->execute([$database]);
            if ($exists->fetchColumn() !== false) {
                continue;
            }
            try {
                // The name is a private const in this file, never user input.
                $admin->exec('CREATE DATABASE ' . $database);
            } catch (\PDOException $caught) {
                // 42P04 = duplicate_database: another runner won the race
                // between the SELECT and the CREATE. That is success, not
                // failure - the database we need is there either way.
                if ($caught->getCode() !== '42P04') {
                    throw $caught;
                }
            }
        }
    }

    private function seedSqlite(string $path, string $marker): Database
    {
        $database = Database::create($this->sqliteUrl($path), autoCommit: true);
        $database->execute('CREATE TABLE IF NOT EXISTS widget (id INTEGER PRIMARY KEY, owner TEXT)');
        $database->execute('DELETE FROM widget');
        $database->insert('widget', ['id' => 1, 'owner' => $marker]);
        return $database;
    }

    // -- the rule ------------------------------------------------------------

    /**
     * The whole invariant, on real files and a real shared Redis.
     *
     * Two SQLite databases, identical schema, identical SQL, DIFFERENT rows,
     * one Redis. Reading A then B must return B's row. With no database
     * identity in the key, B gets a HIT on A's entry and reads A's data.
     */
    public function testTwoDatabasesSharingOneCacheBackendDoNotCrossServe(): void
    {
        $this->requireRedis();
        $this->useSharedRedisCache();

        $databaseA = $this->seedSqlite($this->tempDatabasePath('primary'), 'database-a');
        $databaseB = $this->seedSqlite($this->tempDatabasePath('analytics'), 'database-b');
        $databaseA->cacheClear();
        $databaseB->cacheClear();

        $sql = 'SELECT owner FROM widget WHERE id = ?';
        $rowsA = $databaseA->fetch($sql, [1])->records;
        $rowsB = $databaseB->fetch($sql, [1])->records;

        $this->assertSame('database-a', $rowsA[0]['owner']);
        $this->assertSame(
            'database-b',
            $rowsB[0]['owner'],
            "database B was served database A's cached row - the cache key carries "
            . 'no database identity, so a shared backend cross-serves between '
            . 'databases. This is a data-isolation failure, not a cache miss.'
        );
    }

    /** Direct assertion on the key itself, so the reason is unambiguous. */
    public function testTheCacheKeyChangesWhenTheDatabaseChanges(): void
    {
        $this->requireRedis();
        $this->useSharedRedisCache();

        $databaseA = Database::create($this->sqliteUrl($this->tempDatabasePath('one')), autoCommit: true);
        $databaseB = Database::create($this->sqliteUrl($this->tempDatabasePath('two')), autoCommit: true);
        $sql = 'SELECT owner FROM widget WHERE id = ?';

        $this->assertNotSame(
            $this->cacheKeyOf($databaseA, $sql, [1]),
            $this->cacheKeyOf($databaseB, $sql, [1]),
            'the same SQL against two different databases produces the SAME cache '
            . "key, so either can serve the other's rows"
        );
    }

    /**
     * NEGATIVE: identity must not be per-connection or per-process.
     *
     * A key that folds in something instance-specific (an object id, a pid, a
     * random salt) would isolate the databases by accident and destroy the
     * whole point of a SHARED cache: no instance would ever hit another's entry.
     */
    public function testTheCacheKeyIsStableForTheSameDatabase(): void
    {
        $this->requireRedis();
        $this->useSharedRedisCache();

        $path = $this->tempDatabasePath('same');
        $first = Database::create($this->sqliteUrl($path), autoCommit: true);
        $second = Database::create($this->sqliteUrl($path), autoCommit: true);
        $sql = 'SELECT owner FROM widget WHERE id = ?';

        $this->assertSame(
            $this->cacheKeyOf($first, $sql, [1]),
            $this->cacheKeyOf($second, $sql, [1]),
            'two connections to the SAME database produce different cache keys, so '
            . 'a shared cache can never hit across instances'
        );
    }

    /**
     * NEGATIVE: credentials must never reach the key.
     *
     * Two reasons. A credential in a key means every rotation silently
     * cold-starts the cache; and a shared backend's key namespace is readable
     * by every tenant of that backend, so a secret must never be folded into it.
     *
     * A pure function over its inputs, so it needs no live service and uses no
     * stand-in: cacheIdentity() is called directly rather than through a
     * connection, because constructing a Database connects eagerly and a
     * deliberately wrong password would fail before the key is ever computed.
     */
    public function testTheCacheKeyExcludesCredentials(): void
    {
        $plain = CachedDatabase::cacheIdentity('postgres://db.internal:5432/ledger');
        $withUser = CachedDatabase::cacheIdentity('postgres://reader@db.internal:5432/ledger');
        $withSecret = CachedDatabase::cacheIdentity('postgres://reader:hunter2@db.internal:5432/ledger');
        $rotated = CachedDatabase::cacheIdentity('postgres://reader:rotated-p4ss@db.internal:5432/ledger');

        $this->assertSame($plain, $withUser, 'the identity changed with the username');
        $this->assertSame(
            $plain,
            $withSecret,
            'the identity changed with the credentials - a rotation cold-starts the '
            . 'cache and a secret leaks into a shared key namespace'
        );
        $this->assertSame($plain, $rotated, 'the identity changed when the password rotated');
        $this->assertStringNotContainsString(
            'hunter2',
            $withSecret,
            'a password appears verbatim in the cache identity, which is visible to '
            . 'every tenant of a shared cache backend'
        );
        $this->assertStringNotContainsString('rotated-p4ss', $rotated);

        // And the identity still SEPARATES databases on that same server.
        $this->assertNotSame(
            $plain,
            CachedDatabase::cacheIdentity('postgres://db.internal:5432/analytics'),
            'two databases on one server share an identity - they will cross-serve'
        );
    }

    /**
     * The primary-and-analytics case, on a REAL PostgreSQL server.
     *
     * Same host, same port, same user, same SQL - only the database name
     * differs. That is the deployment ADR-0024 describes, and it is the one
     * where the SQLite path's differing file names could mask a partial fix.
     */
    public function testTwoPostgresDatabasesDoNotCrossServe(): void
    {
        $this->requireRedis();
        $postgres = PgTestEnv::resolve();
        if (!$postgres->reachable(2.0)) {
            $this->markTestSkipped(
                "postgresql service not reachable at {$postgres->host}:{$postgres->port}"
            );
        }
        $this->ensurePostgresDatabases($postgres);
        $this->useSharedRedisCache();

        $table = 'widget_' . bin2hex(random_bytes(4));
        $markers = [
            'database-a' => $postgres->url(self::PG_DB_A),
            'database-b' => $postgres->url(self::PG_DB_B),
        ];
        $handles = [];
        try {
            foreach ($markers as $marker => $url) {
                $database = Database::create(
                    $url,
                    autoCommit: true,
                    username: $postgres->user,
                    password: $postgres->pass
                );
                $database->execute("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, owner VARCHAR(50))");
                $database->insert($table, ['id' => 1, 'owner' => $marker]);
                $database->cacheClear();
                $handles[$marker] = $database;
            }

            $sql = "SELECT owner FROM {$table} WHERE id = ?";
            $gotA = $handles['database-a']->fetch($sql, [1])->records[0]['owner'];
            $gotB = $handles['database-b']->fetch($sql, [1])->records[0]['owner'];

            $this->assertSame('database-a', $gotA);
            $this->assertSame(
                'database-b',
                $gotB,
                "the analytics database was served the primary database's cached row "
                . '- one PostgreSQL server, two databases, one shared cache, and the '
                . 'key cannot tell them apart'
            );
        } finally {
            // Drop only the table WE created. Never drop a database we did not make.
            foreach ($handles as $database) {
                try {
                    $database->execute("DROP TABLE IF EXISTS {$table}");
                } catch (\Throwable) {
                    // best effort
                }
            }
        }
    }
}
