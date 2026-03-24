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
    ];

    /** @var DatabaseAdapter The underlying database adapter */
    private DatabaseAdapter $adapter;

    /**
     * Create a new Database wrapper instance.
     *
     * @param string $url Connection URL (e.g. "sqlite::memory:", "postgres://user:pass@host/db")
     * @param bool|null $autoCommit Override auto-commit setting
     * @param string $username Database username
     * @param string $password Database password
     */
    public function __construct(string $url, ?bool $autoCommit = null, string $username = '', string $password = '')
    {
        $this->adapter = self::createAdapter($url, $autoCommit, $username, $password);
    }

    /**
     * Create a Database instance from a connection URL string.
     *
     * @param string $url Connection URL (e.g. "sqlite::memory:", "pgsql://user:pass@host/db")
     * @param bool|null $autoCommit Override auto-commit setting
     * @return self
     * @throws \InvalidArgumentException If the URL scheme is unsupported
     * @throws \RuntimeException If the required PHP extension is missing
     */
    public static function create(string $url, ?bool $autoCommit = null, string $username = '', string $password = ''): self
    {
        return new self($url, $autoCommit, $username, $password);
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
     * Get the underlying DatabaseAdapter.
     *
     * @return DatabaseAdapter
     */
    public function getAdapter(): DatabaseAdapter
    {
        return $this->adapter;
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
        return $this->adapter->query($sql, $params);
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
    public function fetch(string $sql, array $params = [], int $limit = 10, int $offset = 0): DatabaseResult
    {
        $raw = $this->adapter->fetch($sql, $limit, $offset, $params);

        $records = $raw['data'] ?? [];
        $columns = !empty($records) ? array_keys($records[0]) : [];
        $total   = $raw['total'] ?? count($records);

        return new DatabaseResult(
            records: $records,
            columns: $columns,
            count:   $total,
            limit:   $limit,
            offset:  $offset,
            adapter: $this->adapter,
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
        return $this->adapter->fetchOne($sql, $params);
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
        return $this->adapter->execute($sql, $params);
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
        return $this->adapter->execute($sql, $params);
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
        return $this->adapter->insert($table, $data);
    }

    /**
     * Update rows in a table.
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs to set
     * @param array<string, mixed> $filter Column => value pairs for WHERE clause
     * @return bool True on success
     */
    public function update(string $table, array $data, array $filter = []): bool
    {
        if (empty($filter)) {
            return $this->adapter->update($table, $data);
        }

        $wheres = implode(' AND ', array_map(fn($k) => "{$k} = ?", array_keys($filter)));
        return $this->adapter->update($table, $data, $wheres, array_values($filter));
    }

    /**
     * Delete rows from a table.
     *
     * @param string $table Table name
     * @param array<string, mixed> $filter Column => value pairs for WHERE clause
     * @return bool True on success
     */
    public function delete(string $table, array $filter = []): bool
    {
        if (empty($filter)) {
            return $this->adapter->delete($table);
        }

        return $this->adapter->delete($table, $filter);
    }

    // -------------------------------------------------------------------------
    // Connection & transaction management
    // -------------------------------------------------------------------------

    /**
     * Close the database connection.
     */
    public function close(): void
    {
        $this->adapter->close();
    }

    /**
     * Begin a transaction.
     */
    public function startTransaction(): void
    {
        $this->adapter->startTransaction();
    }

    /**
     * Commit the current transaction.
     */
    public function commit(): void
    {
        $this->adapter->commit();
    }

    /**
     * Rollback the current transaction.
     */
    public function rollback(): void
    {
        $this->adapter->rollback();
    }

    // -------------------------------------------------------------------------
    // Schema introspection
    // -------------------------------------------------------------------------

    /**
     * Check if a table exists.
     */
    public function tableExists(string $tableName): bool
    {
        return $this->adapter->tableExists($tableName);
    }

    /**
     * Return a list of table names in the database.
     *
     * @return array<int, string>
     */
    public function getTables(): array
    {
        return $this->adapter->getTables();
    }

    /**
     * Get the last inserted auto-increment ID.
     */
    public function getLastId(): int|string
    {
        return $this->adapter->lastInsertId();
    }

    /**
     * Get the last error message.
     */
    public function error(): ?string
    {
        return $this->adapter->error();
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
        };
    }
}
