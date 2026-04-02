<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

use Tina4\DatabaseUrl;

/**
 * Database wrapper that provides a consistent API matching Python/Ruby conventions.
 *
 * Can be used as:
 *   - Database::create($url)  — static factory (returns Database instance)
 *   - new Database($url)      — constructor
 *   - Database::fromEnv()     — from DATABASE_URL env var
 *
 * All methods delegate to the internal DatabaseAdapter.
 *
 * Supported schemes:
 *   sqlite              => SQLite3Adapter
 *   postgres, postgresql => PostgresAdapter
 *   mysql               => MySQLAdapter
 *   mssql, sqlserver     => MSSQLAdapter
 *   firebird            => FirebirdAdapter
 *   mongodb, pymongo    => MongoDBAdapter
 *   odbc                => ODBCAdapter
 */
class Database
{
    /** @var array<string, class-string<DatabaseAdapter>> Maps scheme to adapter class */
    private const ADAPTER_MAP = [
        'sqlite' => SQLite3Adapter::class,
        'postgres' => PostgresAdapter::class,
        'postgresql' => PostgresAdapter::class,
        'mysql' => MySQLAdapter::class,
        'mssql' => MSSQLAdapter::class,
        'sqlserver' => MSSQLAdapter::class,
        'firebird' => FirebirdAdapter::class,
        'mongodb' => MongoDBAdapter::class,
        'pymongo' => MongoDBAdapter::class,
        'odbc' => ODBCAdapter::class,
    ];

    /** @var DatabaseAdapter The underlying database adapter (single-connection mode) */
    private ?DatabaseAdapter $adapter = null;

    /** @var DatabaseAdapter[] Connection pool (pooled mode) */
    private array $pool = [];

    /** @var int Pool size (0 = single connection) */
    private int $poolSize = 0;

    /** @var int Round-robin index for pool rotation */
    private int $poolIndex = 0;

    /** @var string Connection URL for lazy pool creation */
    private string $url;

    /** @var bool|null Auto-commit setting for pool connections */
    private ?bool $autoCommit;

    /** @var string Username for pool connections */
    private string $dbUsername;

    /** @var string Password for pool connections */
    private string $dbPassword;

    /**
     * Create a new Database wrapper instance.
     *
     * @param string $url Connection URL (e.g. "sqlite::memory:", "postgres://user:pass@host/db")
     * @param bool|null $autoCommit Override auto-commit setting
     * @param string $username Database username
     * @param string $password Database password
     * @param int $pool Number of pooled connections (0 = single connection, N>0 = round-robin pool)
     */
    public function __construct(string $url, ?bool $autoCommit = null, string $username = '', string $password = '', int $pool = 0)
    {
        $this->url = $url;
        $this->autoCommit = $autoCommit;
        $this->dbUsername = $username;
        $this->dbPassword = $password;
        $this->poolSize = $pool;

        if ($pool > 0) {
            // Pooled mode — adapters created lazily via getPooledAdapter()
            $this->pool = array_fill(0, $pool, null);
        } else {
            // Single-connection mode — current behavior
            $adapter = self::createAdapter($url, $autoCommit, $username, $password);
            $this->adapter = self::wrapWithCache($adapter);
        }
    }

    /**
     * Create a Database instance from a connection URL string.
     *
     * @param string $url Connection URL (e.g. "sqlite::memory:", "pgsql://user:pass@host/db")
     * @param bool|null $autoCommit Override auto-commit setting
     * @param string $username Database username
     * @param string $password Database password
     * @param int $pool Number of pooled connections (0 = single, N>0 = round-robin pool)
     * @return self
     * @throws \InvalidArgumentException If the URL scheme is unsupported
     * @throws \RuntimeException If the required PHP extension is missing
     */
    public static function create(string $url, ?bool $autoCommit = null, string $username = '', string $password = '', int $pool = 0): self
    {
        return new self($url, $autoCommit, $username, $password, $pool);
    }

    /**
     * Create a Database instance from the DATABASE_URL environment variable.
     *
     * @param string $envKey Environment variable name (default: DATABASE_URL)
     * @param bool|null $autoCommit Override auto-commit setting
     * @return self|null Null if the env var is not set
     */
    public static function fromEnv(string $envKey = 'DATABASE_URL', ?bool $autoCommit = null): ?self
    {
        $url = \Tina4\DotEnv::getEnv($envKey);

        if ($url === null || $url === '') {
            return null;
        }

        $username = \Tina4\DotEnv::getEnv('DATABASE_USERNAME') ?? '';
        $password = \Tina4\DotEnv::getEnv('DATABASE_PASSWORD') ?? '';

        return new self($url, $autoCommit, $username, $password);
    }

    /**
     * Get the next adapter — from pool (round-robin) or single connection.
     *
     * @return DatabaseAdapter
     */
    private function getNextAdapter(): DatabaseAdapter
    {
        if ($this->poolSize > 0) {
            $idx = $this->poolIndex;
            $this->poolIndex = ($this->poolIndex + 1) % $this->poolSize;

            if ($this->pool[$idx] === null) {
                $adapter = self::createAdapter(
                    $this->url, $this->autoCommit, $this->dbUsername, $this->dbPassword
                );
                $this->pool[$idx] = self::wrapWithCache($adapter);
            }

            return $this->pool[$idx];
        }

        return $this->adapter;
    }

    /**
     * Get the underlying DatabaseAdapter.
     *
     * With pooling enabled, returns the next adapter via round-robin.
     *
     * @return DatabaseAdapter
     */
    public function getAdapter(): DatabaseAdapter
    {
        return $this->getNextAdapter();
    }

    /**
     * Get pool size (0 = single connection mode).
     */
    public function getPoolSize(): int
    {
        return $this->poolSize;
    }

    /**
     * Get the number of active (created) connections in the pool.
     */
    public function getActivePoolCount(): int
    {
        if ($this->poolSize === 0) {
            return $this->adapter !== null ? 1 : 0;
        }
        return count(array_filter($this->pool, fn($a) => $a !== null));
    }

    // -------------------------------------------------------------------------
    // Query methods
    // -------------------------------------------------------------------------

    /**
     * Execute a query with parameter binding and return results.
     *
     * @param string $sql SQL query
     * @param array<mixed> $params Bound parameters
     * @return array<int, array<string, mixed>> Array of associative arrays
     */
    public function query(string $sql, array $params = []): array
    {
        return $this->getNextAdapter()->query($sql, $params);
    }

    /**
     * Fetch results with pagination.
     *
     * Returns a DatabaseResult object that supports iteration, counting,
     * array access, and JSON serialisation.
     *
     * @param string $sql SQL query
     * @param array<mixed> $params Bound parameters
     * @param int $limit Max rows to return
     * @param int $offset Starting offset
     * @return DatabaseResult
     */
    public function fetch(string $sql, array $params = [], int $limit = 100, int $offset = 0): DatabaseResult
    {
        $adapter = $this->getNextAdapter();
        $raw = $adapter->fetch($sql, $limit, $offset, $params);

        $records = $raw['data'] ?? [];
        $columns = !empty($records) ? array_keys($records[0]) : [];
        $total   = $raw['total'] ?? count($records);

        return new DatabaseResult(
            records: $records,
            columns: $columns,
            count:   $total,
            limit:   $limit,
            offset:  $offset,
            adapter: $adapter,
            sql:     $sql,
        );
    }

    /**
     * Run a query and return the first row or null.
     *
     * @param string $sql SQL query
     * @param array<mixed> $params Bound parameters
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        return $this->getNextAdapter()->fetchOne($sql, $params);
    }

    /**
     * Execute a DDL or data manipulation statement (no result set).
     *
     * @param string $sql SQL statement
     * @param array<mixed> $params Bound parameters
     * @return bool True on success
     */
    public function execute(string $sql, array $params = []): bool
    {
        return $this->getNextAdapter()->execute($sql, $params);
    }

    /**
     * Alias for execute() — matches adapter-level naming convention.
     *
     * @param string $sql SQL statement
     * @param array<mixed> $params Bound parameters
     * @return bool True on success
     */
    public function exec(string $sql, array $params = []): bool
    {
        return $this->getNextAdapter()->execute($sql, $params);
    }

    // -------------------------------------------------------------------------
    // CRUD convenience methods
    // -------------------------------------------------------------------------

    /**
     * Insert a row into a table.
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs
     * @return bool True on success
     */
    public function insert(string $table, array $data): bool
    {
        return $this->getNextAdapter()->insert($table, $data);
    }

    /**
     * Update rows in a table.
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs to set
     * @param string $filterSql WHERE clause SQL (e.g. "id = ?", "age > ? AND status = ?")
     * @param array<mixed> $params Bound parameters for the WHERE clause
     * @return bool True on success
     */
    public function update(string $table, array $data, string $filterSql = '', array $params = []): bool
    {
        $adapter = $this->getNextAdapter();
        return $adapter->update($table, $data, $filterSql, $params);
    }

    /**
     * Delete rows from a table.
     *
     * @param string $table Table name
     * @param string $filterSql WHERE clause SQL (e.g. "id = ?")
     * @param array<mixed> $params Bound parameters for the WHERE clause
     * @return bool True on success
     */
    public function delete(string $table, string $filterSql = '', array $params = []): bool
    {
        $adapter = $this->getNextAdapter();
        return $adapter->delete($table, $filterSql, $params);
    }

    // -------------------------------------------------------------------------
    // Connection & transaction management
    // -------------------------------------------------------------------------

    /**
     * Close all database connections (pool or single).
     */
    public function close(): void
    {
        if ($this->poolSize > 0) {
            foreach ($this->pool as $i => $adapter) {
                if ($adapter !== null) {
                    $adapter->close();
                    $this->pool[$i] = null;
                }
            }
        } elseif ($this->adapter !== null) {
            $this->adapter->close();
        }
    }

    /**
     * Begin a transaction.
     */
    public function startTransaction(): void
    {
        $this->getNextAdapter()->startTransaction();
    }

    /**
     * Commit the current transaction.
     */
    public function commit(): void
    {
        $this->getNextAdapter()->commit();
    }

    /**
     * Rollback the current transaction.
     */
    public function rollback(): void
    {
        $this->getNextAdapter()->rollback();
    }

    // -------------------------------------------------------------------------
    // Schema introspection
    // -------------------------------------------------------------------------

    /**
     * Check if a table exists.
     */
    public function tableExists(string $tableName): bool
    {
        return $this->getNextAdapter()->tableExists($tableName);
    }

    /**
     * Return a list of table names in the database.
     *
     * @return array<int, string>
     */
    public function getTables(): array
    {
        return $this->getNextAdapter()->getTables();
    }

    /**
     * Get the last inserted auto-increment ID.
     */
    public function getLastId(): int|string
    {
        return $this->getNextAdapter()->lastInsertId();
    }

    /**
     * Ensure the tina4_sequences table exists for race-safe ID generation.
     *
     * @param DatabaseAdapter $adapter
     */
    private function ensureSequenceTable(DatabaseAdapter $adapter): void
    {
        try {
            $adapter->execute(
                'CREATE TABLE IF NOT EXISTS tina4_sequences ('
                . 'seq_name VARCHAR(200) PRIMARY KEY, '
                . 'current_value INTEGER DEFAULT 0'
                . ')'
            );
        } catch (\Throwable) {
            // Table already exists — ignore
        }
    }

    /**
     * Get the next ID from the tina4_sequences table (atomic UPDATE + SELECT).
     *
     * If no row exists for the sequence, seeds it from MAX(pkColumn) of the target table.
     *
     * @param string $seqName Sequence name (e.g. "users.id")
     * @param string|null $table Table to seed from
     * @param string $pkColumn Primary key column to seed from
     * @param DatabaseAdapter $adapter
     * @return int The next available ID
     */
    private function sequenceNext(string $seqName, ?string $table, string $pkColumn, DatabaseAdapter $adapter): int
    {
        $this->ensureSequenceTable($adapter);

        // Check if sequence row exists
        $row = $adapter->fetchOne(
            'SELECT current_value FROM tina4_sequences WHERE seq_name = ?',
            [$seqName]
        );

        if ($row === null) {
            // Seed from MAX(pk_column) of the target table
            $seed = 0;
            if ($table !== null) {
                try {
                    $maxRow = $adapter->fetchOne("SELECT MAX({$pkColumn}) AS max_id FROM {$table}");
                    if ($maxRow !== null) {
                        $seed = (int) ($maxRow['max_id'] ?? $maxRow['MAX_ID'] ?? 0);
                    }
                } catch (\Throwable) {
                    // Table does not exist — start from 0
                }
            }

            $adapter->execute(
                'INSERT INTO tina4_sequences (seq_name, current_value) VALUES (?, ?)',
                [$seqName, $seed]
            );
        }

        // Atomic increment
        $adapter->execute(
            'UPDATE tina4_sequences SET current_value = current_value + 1 WHERE seq_name = ?',
            [$seqName]
        );

        // Read back the new value
        $row = $adapter->fetchOne(
            'SELECT current_value FROM tina4_sequences WHERE seq_name = ?',
            [$seqName]
        );

        return (int) ($row['current_value'] ?? 1);
    }

    /**
     * Pre-generate the next available primary key ID using engine-aware strategies.
     *
     * - Firebird: auto-creates a generator if missing, then increments it via GEN_ID (atomic).
     * - PostgreSQL: tries nextval() first; if the sequence is missing, auto-creates it
     *   seeded from MAX(pk), then retries; falls through to sequence table on failure.
     * - SQLite/MySQL/MSSQL: uses a race-safe tina4_sequences table with atomic UPDATE.
     * - Returns 1 if the table is empty or does not exist.
     *
     * @param string $table Table name
     * @param string $pkColumn Primary key column name
     * @param string|null $generatorName Firebird generator name override
     * @return int The next available ID
     */
    public function getNextId(string $table, string $pkColumn = 'id', ?string $generatorName = null): int
    {
        $adapter = $this->getNextAdapter();

        // Firebird — use generators (GEN_ID is atomic)
        if ($adapter instanceof FirebirdAdapter) {
            $genName = $generatorName ?? 'GEN_' . strtoupper($table) . '_ID';

            // Auto-create the generator if it does not exist
            try {
                $adapter->execute("CREATE GENERATOR {$genName}");
            } catch (\Throwable) {
                // Generator already exists — ignore
            }

            $row = $adapter->fetchOne("SELECT GEN_ID({$genName}, 1) AS NEXT_ID FROM RDB\$DATABASE");
            return (int) ($row['NEXT_ID'] ?? $row['next_id'] ?? 1);
        }

        // PostgreSQL — try nextval() first, auto-create sequence if missing
        if ($adapter instanceof PostgresAdapter) {
            $seqName = strtolower($table) . '_' . strtolower($pkColumn) . '_seq';
            try {
                $row = $adapter->fetchOne("SELECT nextval('{$seqName}') AS next_id");
                if ($row !== null && isset($row['next_id'])) {
                    return (int) $row['next_id'];
                }
            } catch (\Throwable) {
                // Sequence does not exist — try to create it seeded from MAX
                try {
                    $seed = 0;
                    $maxRow = $adapter->fetchOne("SELECT COALESCE(MAX({$pkColumn}), 0) AS max_id FROM {$table}");
                    if ($maxRow !== null) {
                        $seed = (int) ($maxRow['max_id'] ?? 0);
                    }

                    $adapter->execute("CREATE SEQUENCE {$seqName} START WITH " . ($seed + 1));
                    $row = $adapter->fetchOne("SELECT nextval('{$seqName}') AS next_id");
                    if ($row !== null && isset($row['next_id'])) {
                        return (int) $row['next_id'];
                    }
                } catch (\Throwable) {
                    // Fall through to sequence table
                }
            }

            // PostgreSQL fallback — use sequence table
            return $this->sequenceNext("{$table}.{$pkColumn}", $table, $pkColumn, $adapter);
        }

        // MongoDB — atomic findOneAndUpdate on tina4_sequences collection
        if ($adapter instanceof MongoDBAdapter) {
            return $this->mongoNextId($table, $pkColumn, $adapter);
        }

        // SQLite / MySQL / MSSQL — use race-safe sequence table
        return $this->sequenceNext("{$table}.{$pkColumn}", $table, $pkColumn, $adapter);
    }

    /**
     * Get the next ID for MongoDB using an atomic findOneAndUpdate on the
     * tina4_sequences collection (race-safe, no transactions required).
     *
     * @param string $table Table / collection name
     * @param string $pkColumn Primary key column name
     * @param MongoDBAdapter $adapter
     * @return int The next available ID
     */
    private function mongoNextId(string $table, string $pkColumn, MongoDBAdapter $adapter): int
    {
        $seqName = "{$table}.{$pkColumn}";
        $db = $adapter->getDatabase();

        if ($db === null) {
            return 1;
        }

        try {
            $sequences = $db->selectCollection('tina4_sequences');

            // Seed from the maximum existing value if sequence row does not exist yet
            $existing = $sequences->findOne(
                ['_id' => $seqName],
                ['typeMap' => ['root' => 'array', 'document' => 'array']]
            );

            if ($existing === null) {
                $seed = 0;
                try {
                    $collection = $db->selectCollection($table);
                    $maxDoc = $collection->findOne(
                        [],
                        [
                            'sort'    => [$pkColumn => -1],
                            'typeMap' => ['root' => 'array', 'document' => 'array'],
                        ]
                    );
                    if ($maxDoc !== null && isset($maxDoc[$pkColumn])) {
                        $seed = (int) $maxDoc[$pkColumn];
                    }
                } catch (\Throwable) {
                    // Collection may not exist yet
                }

                // upsert the seed so findOneAndUpdate below starts from the right value
                $sequences->updateOne(
                    ['_id' => $seqName],
                    ['$setOnInsert' => ['_id' => $seqName, 'current_value' => $seed]],
                    ['upsert' => true]
                );
            }

            // Atomic increment — findOneAndUpdate returns the document AFTER the update
            $result = $sequences->findOneAndUpdate(
                ['_id' => $seqName],
                ['$inc' => ['current_value' => 1]],
                [
                    'upsert'         => true,
                    'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                    'typeMap'        => ['root' => 'array', 'document' => 'array'],
                ]
            );

            return (int) ($result['current_value'] ?? 1);
        } catch (\Throwable $e) {
            // Fallback to 1 on any unexpected error
            return 1;
        }
    }

    /**
     * Execute a SQL statement with multiple parameter arrays in a single transaction.
     *
     * Each entry in $paramSets is passed to execute() as bound parameters.
     * If any execution fails, the transaction is rolled back and the exception is re-thrown.
     *
     * @param string $sql SQL statement with placeholders
     * @param array<int, array<mixed>> $paramSets Array of parameter arrays
     * @return array<int, bool> Array of execute() results
     * @throws \Exception If any execution fails (transaction is rolled back)
     */
    public function executeMany(string $sql, array $paramSets): array
    {
        $results = [];
        $this->startTransaction();
        try {
            foreach ($paramSets as $params) {
                $results[] = $this->execute($sql, $params);
            }
            $this->commit();
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
        return $results;
    }

    /**
     * Get the last error message.
     */
    public function error(): ?string
    {
        return $this->getNextAdapter()->error();
    }

    // -------------------------------------------------------------------------
    // Static utility methods
    // -------------------------------------------------------------------------

    /**
     * Convenience alias for fromEnv().
     *
     * @param string $envKey Environment variable name (default: DATABASE_URL)
     * @return self|null Null if the env var is not set
     */
    public static function getConnection(string $envKey = 'DATABASE_URL'): ?self
    {
        return self::fromEnv($envKey);
    }

    /**
     * Get the list of supported database schemes.
     *
     * @return array<string>
     */
    public static function supportedSchemes(): array
    {
        return array_unique(array_keys(self::ADAPTER_MAP));
    }

    /**
     * Check if a scheme is supported.
     */
    public static function isSupported(string $scheme): bool
    {
        return isset(self::ADAPTER_MAP[strtolower($scheme)]);
    }

    // -------------------------------------------------------------------------
    // Query cache convenience
    // -------------------------------------------------------------------------

    /**
     * Wrap an adapter in CachedDatabase if TINA4_DB_CACHE is enabled.
     *
     * The CachedDatabase reads TINA4_DB_CACHE and TINA4_DB_CACHE_TTL env vars
     * internally, so no explicit config is needed here.
     *
     * @param DatabaseAdapter $adapter
     * @return DatabaseAdapter Original adapter or CachedDatabase wrapper
     */
    private static function wrapWithCache(DatabaseAdapter $adapter): DatabaseAdapter
    {
        if (\Tina4\DotEnv::isTruthy(\Tina4\DotEnv::getEnv('TINA4_DB_CACHE') ?? 'false')) {
            return new CachedDatabase($adapter);
        }
        return $adapter;
    }

    /**
     * Get query cache statistics (if caching is enabled).
     *
     * @return array{enabled: bool, hits: int, misses: int, size: int, ttl: int}
     */
    public function cacheStats(): array
    {
        $adapter = $this->getNextAdapter();
        if ($adapter instanceof CachedDatabase) {
            return $adapter->cacheStats();
        }
        return ['enabled' => false, 'hits' => 0, 'misses' => 0, 'size' => 0, 'ttl' => 0];
    }

    /**
     * Flush the query cache and reset counters.
     */
    public function cacheClear(): void
    {
        $adapter = $this->getNextAdapter();
        if ($adapter instanceof CachedDatabase) {
            $adapter->cacheClear();
        }
    }

    // -------------------------------------------------------------------------
    // Internal adapter factory
    // -------------------------------------------------------------------------

    /**
     * Create the raw DatabaseAdapter from a connection URL string.
     *
     * @param string $url Connection URL
     * @param bool|null $autoCommit Override auto-commit setting
     * @return DatabaseAdapter
     * @throws \InvalidArgumentException If the URL scheme is unsupported
     * @throws \RuntimeException If the required PHP extension is missing
     */
    private static function createAdapter(string $url, ?bool $autoCommit = null, string $username = '', string $password = ''): DatabaseAdapter
    {
        // Handle SQLite special cases
        if ($url === ':memory:' || $url === 'sqlite::memory:' || $url === 'sqlite:///:memory:') {
            return new SQLite3Adapter(':memory:', $autoCommit);
        }

        if (str_starts_with($url, 'sqlite:///')) {
            $path = substr($url, 9);
            return new SQLite3Adapter($path, $autoCommit);
        }

        // For a bare file path ending in .db or .sqlite, assume SQLite
        if (!str_contains($url, '://') && preg_match('/\.(db|sqlite|sqlite3)$/i', $url)) {
            return new SQLite3Adapter($url, $autoCommit);
        }

        // Parse standard URL
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'])) {
            throw new \InvalidArgumentException(
                "Database: Cannot determine database type from '{$url}'. "
                . "Use a URL like 'postgres://user:pass@host/db' or 'sqlite:///path/to/db'."
            );
        }

        $scheme = strtolower($parts['scheme']);

        if (!isset(self::ADAPTER_MAP[$scheme])) {
            throw new \InvalidArgumentException(
                "Database: Unsupported database scheme '{$scheme}'. "
                . 'Supported: ' . implode(', ', array_unique(array_keys(self::ADAPTER_MAP)))
            );
        }

        $adapterClass = self::ADAPTER_MAP[$scheme];

        return match ($adapterClass) {
            SQLite3Adapter::class => new SQLite3Adapter(
                ltrim($parts['path'] ?? ':memory:', '/'),
                $autoCommit,
            ),
            PostgresAdapter::class => new PostgresAdapter($url, $autoCommit, username: $username, password: $password),
            MySQLAdapter::class => new MySQLAdapter($url, username: $username, password: $password, autoCommit: $autoCommit),
            MSSQLAdapter::class => new MSSQLAdapter($url, username: $username, password: $password, autoCommit: $autoCommit),
            FirebirdAdapter::class => new FirebirdAdapter(
                $url,
                username: $username !== '' ? $username : (isset($parts['user']) ? urldecode($parts['user']) : 'SYSDBA'),
                password: $password !== '' ? $password : (isset($parts['pass']) ? urldecode($parts['pass']) : 'masterkey'),
                autoCommit: $autoCommit,
            ),
            MongoDBAdapter::class => new MongoDBAdapter(
                $url,
                username: $username,
                password: $password,
                autoCommit: $autoCommit,
            ),
            ODBCAdapter::class => new ODBCAdapter(
                // Strip leading slashes from the path — everything after odbc:/// is the DSN/connection string
                ltrim($parts['path'] ?? '', '/'),
                username: $username !== '' ? $username : (isset($parts['user']) ? urldecode($parts['user']) : ''),
                password: $password !== '' ? $password : (isset($parts['pass']) ? urldecode($parts['pass']) : ''),
                autoCommit: $autoCommit,
            ),
        };
    }
}
