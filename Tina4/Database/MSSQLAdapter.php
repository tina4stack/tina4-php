<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * Microsoft SQL Server adapter — uses PHP's ext-sqlsrv (sqlsrv_*) functions.
 * The extension is optional; a clear error is thrown if not installed.
 */
class MSSQLAdapter implements DatabaseAdapter
{
    /** @var resource|null */
    private mixed $db = null;
    private ?string $lastError = null;
    private bool $autoCommit;
    private int|string $lastId = 0;

    /**
     * @param string $connectionString URL: "mssql://user:pass@host:port/dbname"
     *                                  or "host\instance" with separate params
     * @param string $username Username (used if not in URL)
     * @param string $password Password (used if not in URL)
     * @param string $database Database name (used if not in URL)
     * @param int $port Port (used if not in URL)
     * @param bool|null $autoCommit Whether to auto-commit
     */
    public function __construct(
        private readonly string $connectionString,
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly string $database = '',
        private readonly int $port = 1433,
        ?bool $autoCommit = null,
    ) {
        if (!function_exists('sqlsrv_connect')) {
            throw new \RuntimeException(
                'MSSQLAdapter requires the ext-sqlsrv PHP extension. '
                . 'Install it with: sudo pecl install sqlsrv (Linux/macOS) '
                . 'or enable it in php.ini on Windows.'
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

        $params = $this->parseConnection($this->connectionString);

        $serverName = $params['host'];
        if ($params['port'] > 0 && $params['port'] !== 1433) {
            $serverName .= ', ' . $params['port'];
        }

        $connectionInfo = [
            'Database' => $params['database'],
            'CharacterSet' => 'UTF-8',
            'ReturnDatesAsStrings' => true,
        ];

        if ($params['username'] !== '') {
            $connectionInfo['UID'] = $params['username'];
        }
        if ($params['password'] !== '') {
            $connectionInfo['PWD'] = $params['password'];
        }

        $conn = @sqlsrv_connect($serverName, $connectionInfo);
        if ($conn === false) {
            $errors = sqlsrv_errors();
            $msg = $errors ? $errors[0]['message'] : 'Unknown connection error';
            $this->lastError = $msg;
            throw new \RuntimeException("MSSQLAdapter: Failed to connect: {$msg}");
        }

        $this->db = $conn;
    }

    public function close(): void
    {
        if ($this->db !== null) {
            sqlsrv_close($this->db);
            $this->db = null;
        }
    }

    public function query(string $sql, array $params = []): array
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            $values = empty($params) ? [] : array_values($params);
            $stmt = empty($values)
                ? @sqlsrv_query($this->db, $sql)
                : @sqlsrv_query($this->db, $sql, $values);

            if ($stmt === false) {
                $errors = sqlsrv_errors();
                $this->lastError = $errors ? $errors[0]['message'] : 'Query failed';
                return [];
            }

            $rows = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $rows[] = $row;
            }

            sqlsrv_free_stmt($stmt);
            return $rows;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function fetch(string $sql, int $limit = 100, int $offset = 0, array $params = []): array
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            // Count total
            $countSql = "SELECT COUNT(*) as total FROM ({$sql}) AS _count_query";
            $countResult = $this->query($countSql, $params);
            $total = (int)($countResult[0]['total'] ?? 0);

            // MSSQL pagination: OFFSET...FETCH NEXT (requires ORDER BY)
            // If no ORDER BY present, add a neutral one
            $pagedSql = $sql;
            if (!preg_match('/\bORDER\s+BY\b/i', $pagedSql)) {
                $pagedSql .= " ORDER BY (SELECT NULL)";
            }
            $pagedSql .= " OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";

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

    public function execute(string $sql, array $params = []): bool
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            $values = empty($params) ? [] : array_values($params);
            $stmt = empty($values)
                ? @sqlsrv_query($this->db, $sql)
                : @sqlsrv_query($this->db, $sql, $values);

            if ($stmt === false) {
                $errors = sqlsrv_errors();
                $this->lastError = $errors ? $errors[0]['message'] : 'Execute failed';
                return false;
            }

            sqlsrv_free_stmt($stmt);
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
            $placeholders = implode(', ', array_fill(0, count($keys), '?'));
            $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";
            $paramsList = array_map(fn($row) => array_values($row), $data);
            return $this->executeMany($sql, $paramsList) > 0;
        }

        $cols = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        // Use OUTPUT INSERTED to get the new ID (MSSQL equivalent of RETURNING)
        $sql = "INSERT INTO {$table} ({$cols}) OUTPUT INSERTED.* VALUES ({$placeholders})";
        $values = array_values($data);

        $stmt = @sqlsrv_query($this->db, $sql, $values);
        if ($stmt === false) {
            // Fall back without OUTPUT if table has no identity
            $sqlFallback = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";
            $stmt = @sqlsrv_query($this->db, $sqlFallback, $values);
            if ($stmt === false) {
                $errors = sqlsrv_errors();
                $this->lastError = $errors ? $errors[0]['message'] : 'Insert failed';
                return false;
            }
            sqlsrv_free_stmt($stmt);

            // Try to get last insert ID via SCOPE_IDENTITY
            $idStmt = @sqlsrv_query($this->db, "SELECT SCOPE_IDENTITY() AS id");
            if ($idStmt !== false) {
                $row = sqlsrv_fetch_array($idStmt, SQLSRV_FETCH_ASSOC);
                if ($row && $row['id'] !== null) {
                    $this->lastId = $row['id'];
                }
                sqlsrv_free_stmt($idStmt);
            }
            return true;
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row !== null && $row !== false) {
            $first = reset($row);
            if ($first !== false) {
                $this->lastId = $first;
            }
        }

        sqlsrv_free_stmt($stmt);
        return true;
    }

    public function update(string $table, array $data, string $where = '', array $whereParams = []): bool
    {
        $setParts = [];
        $params = [];
        foreach ($data as $col => $val) {
            $setParts[] = "{$col} = ?";
            $params[] = $val;
        }
        $sql = "UPDATE {$table} SET " . implode(', ', $setParts);
        if ($where !== '') {
            $sql .= " WHERE {$where}";
            $params = array_merge($params, $whereParams);
        }
        return $this->execute($sql, $params);
    }

    public function delete(string $table, string|array $filter = '', array $whereParams = []): bool
    {
        if (is_array($filter) && isset($filter[0]) && is_array($filter[0])) {
            foreach ($filter as $row) {
                if (!$this->delete($table, $row)) return false;
            }
            return true;
        }
        if (is_array($filter)) {
            $parts = [];
            $params = [];
            foreach ($filter as $col => $val) {
                $parts[] = "{$col} = ?";
                $params[] = $val;
            }
            return $this->delete($table, implode(' AND ', $parts), $params);
        }
        $sql = "DELETE FROM {$table}";
        if ($filter !== '') {
            $sql .= " WHERE {$filter}";
        }
        return $this->execute($sql, $whereParams);
    }

    public function tableExists(string $table): bool
    {
        $rows = $this->query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME = ?",
            [$table]
        );
        return count($rows) > 0;
    }

    public function getColumns(string $table): array
    {
        $sql = "SELECT c.COLUMN_NAME, c.DATA_TYPE, c.IS_NULLABLE, c.COLUMN_DEFAULT,
                    CASE WHEN kcu.COLUMN_NAME IS NOT NULL THEN 1 ELSE 0 END AS is_primary
                FROM INFORMATION_SCHEMA.COLUMNS c
                LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                    ON c.TABLE_NAME = kcu.TABLE_NAME AND c.COLUMN_NAME = kcu.COLUMN_NAME
                    AND kcu.CONSTRAINT_NAME IN (
                        SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                        WHERE CONSTRAINT_TYPE = 'PRIMARY KEY' AND TABLE_NAME = c.TABLE_NAME
                    )
                WHERE c.TABLE_NAME = ?
                ORDER BY c.ORDINAL_POSITION";

        $rows = $this->query($sql, [$table]);
        $columns = [];

        foreach ($rows as $row) {
            $columns[] = [
                'name' => $row['COLUMN_NAME'],
                'type' => $row['DATA_TYPE'],
                'nullable' => $row['IS_NULLABLE'] === 'YES',
                'default' => $row['COLUMN_DEFAULT'],
                'primary' => (int)$row['is_primary'] === 1,
            ];
        }

        return $columns;
    }

    public function getTables(): array
    {
        $rows = $this->query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
        );
        return array_column($rows, 'TABLE_NAME');
    }

    public function lastInsertId(): int|string
    {
        if ($this->lastId !== 0) {
            return $this->lastId;
        }

        // Fallback: SCOPE_IDENTITY
        $rows = $this->query("SELECT SCOPE_IDENTITY() AS id");
        if (!empty($rows) && $rows[0]['id'] !== null) {
            return $rows[0]['id'];
        }

        return 0;
    }

    public function startTransaction(): void
    {
        $this->ensureOpen();
        sqlsrv_begin_transaction($this->db);
    }

    public function commit(): void
    {
        $this->ensureOpen();
        sqlsrv_commit($this->db);
    }

    public function rollback(): void
    {
        $this->ensureOpen();
        try {
            sqlsrv_rollback($this->db);
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
        }
    }

    public function error(): ?string
    {
        return $this->lastError;
    }

    /**
     * Get the underlying sqlsrv connection resource.
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
     * Parse a connection string (URL or host) into connection params.
     */
    private function parseConnection(string $input): array
    {
        if (str_contains($input, '://')) {
            $parts = parse_url($input);
            return [
                'host' => $parts['host'] ?? 'localhost',
                'port' => $parts['port'] ?? 1433,
                'username' => isset($parts['user']) ? urldecode($parts['user']) : $this->username,
                'password' => isset($parts['pass']) ? urldecode($parts['pass']) : $this->password,
                'database' => ltrim($parts['path'] ?? '', '/'),
            ];
        }

        return [
            'host' => $input ?: 'localhost',
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
            'database' => $this->database,
        ];
    }
}
