<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * PostgreSQL database adapter — uses PHP's ext-pgsql (pg_*) functions.
 * The extension is optional; a clear error is thrown if not installed.
 */
class PostgresAdapter implements DatabaseAdapter
{
    /** @var \PgSql\Connection|resource|null */
    private mixed $db = null;
    private ?string $lastError = null;
    private bool $autoCommit;
    private int|string $lastId = 0;

    /**
     * @param string $connectionString DSN: "host=... port=... dbname=... user=... password=..."
     *                                  or URL: "pgsql://user:pass@host:port/dbname"
     * @param bool|null $autoCommit Whether to auto-commit
     */
    public function __construct(
        private readonly string $connectionString,
        ?bool $autoCommit = null,
        private readonly string $username = '',
        private readonly string $password = '',
    ) {
        if (!function_exists('pg_connect')) {
            throw new \RuntimeException(
                'PostgresAdapter requires the ext-pgsql PHP extension. '
                . 'Install it with: sudo apt-get install php-pgsql (Debian/Ubuntu) '
                . 'or brew install php (macOS with pgsql enabled).'
            );
        }

        $envAutoCommit = \Tina4\DotEnv::getEnv('TINA4_AUTOCOMMIT');
        $this->autoCommit = $autoCommit ?? ($envAutoCommit !== null ? filter_var($envAutoCommit, FILTER_VALIDATE_BOOLEAN) : false);
        $this->open();
    }

    public function open(): void
    {
        if ($this->db !== null) {
            return;
        }

        $dsn = $this->buildDsn($this->connectionString);

        $conn = @pg_connect($dsn);
        if ($conn === false) {
            $this->lastError = 'Failed to connect to PostgreSQL';
            throw new \RuntimeException("PostgresAdapter: {$this->lastError}");
        }

        $this->db = $conn;
    }

    public function close(): void
    {
        if ($this->db !== null) {
            pg_close($this->db);
            $this->db = null;
        }
    }

    public function query(string $sql, array $params = []): array
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            $pgSql = $this->convertPlaceholders($sql);

            if (empty($params)) {
                $result = @pg_query($this->db, $pgSql);
            } else {
                $values = $this->flattenParams($params);
                $result = @pg_query_params($this->db, $pgSql, $values);
            }

            if ($result === false) {
                $this->lastError = pg_last_error($this->db);
                return [];
            }

            // Detect binary (bytea) columns for auto-encoding
            $binaryColumns = [];
            $numFields = pg_num_fields($result);
            for ($i = 0; $i < $numFields; $i++) {
                if (pg_field_type($result, $i) === 'bytea') {
                    $binaryColumns[] = pg_field_name($result, $i);
                }
            }

            $rows = [];
            while ($row = pg_fetch_assoc($result)) {
                // Auto-decode bytea columns: PostgreSQL returns hex-escaped
                // strings (\x...) via pg_fetch_assoc. Unescape to raw bytes.
                foreach ($binaryColumns as $col) {
                    if (isset($row[$col]) && $row[$col] !== null) {
                        $row[$col] = pg_unescape_bytea($row[$col]);
                    }
                }
                $rows[] = $row;
            }

            pg_free_result($result);

            // Track last insert ID from RETURNING clause
            if (!empty($rows) && $this->isInsertReturning($sql)) {
                $first = reset($rows[0]);
                if ($first !== false) {
                    $this->lastId = $first;
                }
            }

            return $rows;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function fetch(string $sql, array $params = [], int $limit = 100, int $offset = 0): array
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            $countSql = "SELECT COUNT(*) as total FROM ({$sql}) AS _count_query";
            $countResult = $this->query($countSql, $params);
            $total = (int)($countResult[0]['total'] ?? 0);

            $pagedSql = "{$sql} LIMIT {$limit} OFFSET {$offset}";
            $data = $this->query($pagedSql, $params);

            return [
                'data' => $data,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ];
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return [
                'data' => [],
                'total' => 0,
                'limit' => $limit,
                'offset' => $offset,
            ];
        }
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = $this->query($sql, $params);
        return $rows[0] ?? null;
    }

    public function execute(string $sql, array $params = []): bool|DatabaseResult
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            $pgSql = $this->convertPlaceholders($sql);

            if (empty($params)) {
                $result = @pg_query($this->db, $pgSql);
            } else {
                $values = $this->flattenParams($params);
                $result = @pg_query_params($this->db, $pgSql, $values);
            }

            if ($result === false) {
                $this->lastError = pg_last_error($this->db);
                return false;
            }

            // Capture RETURNING id if present
            if ($this->isInsertReturning($sql)) {
                $row = pg_fetch_assoc($result);
                if ($row !== false) {
                    $first = reset($row);
                    if ($first !== false) {
                        $this->lastId = $first;
                    }
                }
            }

            pg_free_result($result);
            return true;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function executeMany(string $sql, array $paramsList = []): int
    {
        $totalAffected = 0;
        foreach ($paramsList as $params) {
            if ($this->execute($sql, $params)) {
                $totalAffected++;
            }
        }
        return $totalAffected;
    }

    public function insert(string $table, array $data): bool
    {
        // Detect list of rows
        if (isset($data[0]) && is_array($data[0])) {
            $keys = array_keys($data[0]);
            $cols = implode(', ', $keys);
            $placeholders = implode(', ', array_map(fn(int $i) => '$' . ($i + 1), array_keys($keys)));
            $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";
            $paramsList = array_map(fn($row) => array_values($row), $data);
            return $this->executeMany($sql, $paramsList) > 0;
        }

        $cols = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(
            fn(int $i) => '$' . ($i + 1),
            array_keys(array_values($data))
        ));

        $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders}) RETURNING *";
        $values = array_values($data);

        $result = @pg_query_params($this->db, $sql, $values);
        if ($result === false) {
            $this->lastError = pg_last_error($this->db);
            return false;
        }

        $row = pg_fetch_assoc($result);
        if ($row !== false) {
            $first = reset($row);
            if ($first !== false) {
                $this->lastId = $first;
            }
        }

        pg_free_result($result);
        return true;
    }

    public function update(string $table, array $data, string $where = '', array $whereParams = []): bool
    {
        $setParts = [];
        $params = [];
        $i = 1;

        foreach ($data as $col => $val) {
            $setParts[] = "{$col} = \${$i}";
            $params[] = $val;
            $i++;
        }

        $sql = "UPDATE {$table} SET " . implode(', ', $setParts);

        if ($where !== '') {
            // Re-index where placeholders
            $where = $this->reindexPlaceholders($where, $i);
            $sql .= " WHERE {$where}";
            $params = array_merge($params, $this->flattenParams($whereParams));
        }

        $result = @pg_query_params($this->db, $sql, $params);
        if ($result === false) {
            $this->lastError = pg_last_error($this->db);
            return false;
        }

        pg_free_result($result);
        return true;
    }

    public function delete(string $table, string|array $filter = '', array $whereParams = []): bool
    {
        // List of assoc arrays — delete each row
        if (is_array($filter) && isset($filter[0]) && is_array($filter[0])) {
            foreach ($filter as $row) {
                if (!$this->delete($table, $row)) return false;
            }
            return true;
        }

        // Assoc array — build WHERE from keys
        if (is_array($filter)) {
            $parts = [];
            $params = [];
            $i = 1;
            foreach ($filter as $col => $val) {
                $parts[] = "{$col} = \${$i}";
                $params[] = $val;
                $i++;
            }
            $sql = "DELETE FROM {$table} WHERE " . implode(' AND ', $parts);
            $result = @pg_query_params($this->db, $sql, $params);
            if ($result === false) {
                $this->lastError = pg_last_error($this->db);
                return false;
            }
            pg_free_result($result);
            return true;
        }

        // String filter
        $sql = "DELETE FROM {$table}";
        if ($filter !== '') {
            $pgWhere = $this->convertPlaceholders($filter);
            $sql .= " WHERE {$pgWhere}";
        }

        if (empty($whereParams)) {
            $result = @pg_query($this->db, $sql);
        } else {
            $values = $this->flattenParams($whereParams);
            $result = @pg_query_params($this->db, $sql, $values);
        }

        if ($result === false) {
            $this->lastError = pg_last_error($this->db);
            return false;
        }

        pg_free_result($result);
        return true;
    }

    public function tableExists(string $table): bool
    {
        $rows = $this->query(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename = $1",
            [$table]
        );
        return count($rows) > 0;
    }

    public function getColumns(string $table): array
    {
        $sql = "SELECT c.column_name, c.data_type, c.is_nullable, c.column_default,
                    CASE WHEN tc.constraint_type = 'PRIMARY KEY' THEN true ELSE false END AS is_primary
                FROM information_schema.columns c
                LEFT JOIN information_schema.key_column_usage kcu
                    ON c.table_name = kcu.table_name AND c.column_name = kcu.column_name
                LEFT JOIN information_schema.table_constraints tc
                    ON kcu.constraint_name = tc.constraint_name AND tc.constraint_type = 'PRIMARY KEY'
                WHERE c.table_name = $1
                ORDER BY c.ordinal_position";

        $rows = $this->query($sql, [$table]);
        $columns = [];

        foreach ($rows as $row) {
            $columns[] = [
                'name' => $row['column_name'],
                'type' => $row['data_type'],
                'nullable' => $row['is_nullable'] === 'YES',
                'default' => $row['column_default'],
                'primary' => ($row['is_primary'] ?? 'f') === 't' || ($row['is_primary'] ?? false) === true,
            ];
        }

        return $columns;
    }

    public function getTables(): array
    {
        $rows = $this->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");
        return array_column($rows, 'tablename');
    }

    public function lastInsertId(): int|string
    {
        return $this->lastId;
    }

    public function startTransaction(): void
    {
        $this->ensureOpen();
        @pg_query($this->db, 'BEGIN');
    }

    public function commit(): void
    {
        $this->ensureOpen();
        @pg_query($this->db, 'COMMIT');
    }

    public function rollback(): void
    {
        $this->ensureOpen();
        @pg_query($this->db, 'ROLLBACK');
    }

    public function error(): ?string
    {
        return $this->lastError;
    }

    /**
     * Get the underlying pg connection resource.
     */
    public function getConnection(): mixed
    {
        return $this->db;
    }

    /**
     * Get the connection string.
     */
    public function getDatabase(): string
    {
        return $this->connectionString;
    }

    // ── Private helpers ──────────────────────────────────────────────

    private function ensureOpen(): void
    {
        if ($this->db === null) {
            $this->open();
        }
    }

    /**
     * Build a libpq-style DSN from either a URL or key=value string.
     */
    private function buildDsn(string $input): string
    {
        // Already a key=value DSN
        if (str_contains($input, '=') && !str_contains($input, '://')) {
            return $input;
        }

        // Parse URL format
        $parts = parse_url($input);
        if ($parts === false) {
            return $input;
        }

        $dsn = '';
        if (isset($parts['host'])) {
            $dsn .= 'host=' . $parts['host'] . ' ';
        }
        if (isset($parts['port'])) {
            $dsn .= 'port=' . $parts['port'] . ' ';
        }
        if (isset($parts['path'])) {
            $dbName = ltrim($parts['path'], '/');
            if ($dbName !== '') {
                $dsn .= 'dbname=' . $dbName . ' ';
            }
        }
        $user = isset($parts['user']) ? urldecode($parts['user']) : $this->username;
        $pass = isset($parts['pass']) ? urldecode($parts['pass']) : $this->password;

        if ($user !== '') {
            $dsn .= 'user=' . $user . ' ';
        }
        if ($pass !== '') {
            $dsn .= 'password=' . $pass . ' ';
        }

        return trim($dsn);
    }

    /**
     * Convert ? placeholders to $1, $2, ... PostgreSQL style.
     */
    private function convertPlaceholders(string $sql): string
    {
        // If already using $N placeholders or named params, return as-is
        if (preg_match('/\$\d+/', $sql)) {
            return $sql;
        }

        $counter = 0;
        return preg_replace_callback('/\?/', function () use (&$counter) {
            $counter++;
            return '$' . $counter;
        }, $sql);
    }

    /**
     * Re-index ? or $N placeholders starting from a given index.
     */
    private function reindexPlaceholders(string $sql, int $startIndex): string
    {
        $counter = $startIndex - 1;
        return preg_replace_callback('/\?|\$\d+/', function () use (&$counter) {
            $counter++;
            return '$' . $counter;
        }, $sql);
    }

    /**
     * Flatten associative or indexed params to a simple indexed array.
     */
    private function flattenParams(array $params): array
    {
        $values = [];
        foreach ($params as $value) {
            $values[] = $value;
        }
        return $values;
    }

    /**
     * Check if a SQL statement is an INSERT with RETURNING.
     */
    private function isInsertReturning(string $sql): bool
    {
        return (bool)preg_match('/^\s*INSERT\b/i', $sql) && (bool)preg_match('/\bRETURNING\b/i', $sql);
    }
}
