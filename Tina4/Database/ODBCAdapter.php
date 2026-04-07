<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * ODBC database adapter — uses PHP's PDO_ODBC extension.
 *
 * ODBC is a pass-through adapter: SQL is sent directly to the ODBC driver
 * with no dialect translation. The underlying driver handles all SQL natively.
 *
 * Connection URL formats:
 *   odbc:///DSN=MyDSN
 *   odbc:///DRIVER={SQL Server};SERVER=host;DATABASE=db
 *   odbc:///DSN=MyDSN;UID=user;PWD=pass
 */
class ODBCAdapter implements DatabaseAdapter
{
    private ?\PDO $pdo = null;
    private ?string $lastError = null;
    private bool $autoCommit;

    /**
     * @param string $connectionString ODBC connection string (everything after odbc:///)
     * @param string $username         Database username (optional — may be embedded in DSN)
     * @param string $password         Database password (optional — may be embedded in DSN)
     * @param bool|null $autoCommit   Whether to auto-commit (default based on env TINA4_AUTOCOMMIT)
     */
    public function __construct(
        private readonly string $connectionString,
        private readonly string $username = '',
        private readonly string $password = '',
        ?bool $autoCommit = null,
    ) {
        $envAutoCommit = \Tina4\DotEnv::getEnv('TINA4_AUTOCOMMIT');
        $this->autoCommit = $autoCommit ?? ($envAutoCommit !== null ? filter_var($envAutoCommit, FILTER_VALIDATE_BOOLEAN) : false);
        $this->open();
    }

    public function open(): void
    {
        if ($this->pdo !== null) {
            return;
        }

        if (!extension_loaded('pdo_odbc')) {
            throw new \RuntimeException(
                'The pdo_odbc PHP extension is required for ODBC connections. '
                . 'Enable it in php.ini: extension=pdo_odbc'
            );
        }

        try {
            $dsn = 'odbc:' . $this->connectionString;
            $this->pdo = new \PDO(
                $dsn,
                $this->username !== '' ? $this->username : null,
                $this->password !== '' ? $this->password : null,
                [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]
            );

            // Disable PDO-level autocommit so we control transactions explicitly
            if (!$this->autoCommit) {
                $this->pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, 0);
            }
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new \RuntimeException("ODBC: Failed to connect: {$e->getMessage()}");
        }
    }

    public function close(): void
    {
        $this->pdo = null;
    }

    public function query(string $sql, array $params = []): array
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            $stmt = $this->pdo->prepare($sql);
            $this->bindParams($stmt, $params);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function fetch(string $sql, array $params = [], int $limit = 100, int $offset = 0): array
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            // Total count
            $countSql = "SELECT COUNT(*) AS total FROM ({$sql}) AS _tina4_count";
            $countRow = $this->fetchOne($countSql, $params);
            // COUNT(*) column may be uppercased by some ODBC drivers
            $total = (int) ($countRow['total'] ?? $countRow['TOTAL'] ?? 0);

            // Pagination — skip if SQL already has LIMIT or FETCH
            $sqlNoComments = preg_replace('/--.*$/m', '', $sql);
            if (
                stripos($sqlNoComments, 'LIMIT') !== false
                || stripos($sqlNoComments, 'FETCH NEXT') !== false
                || stripos($sqlNoComments, 'FETCH FIRST') !== false
            ) {
                $pagedSql = $sql;
            } else {
                // Try OFFSET/FETCH NEXT (SQL standard, supported by most ODBC targets)
                // Fall back to LIMIT/OFFSET for drivers that support it
                $pagedSql = "{$sql} OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
            }

            $data = $this->query($pagedSql, $params);

            return [
                'data'   => $data,
                'total'  => $total,
                'limit'  => $limit,
                'offset' => $offset,
            ];
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return [
                'data'   => [],
                'total'  => 0,
                'limit'  => $limit,
                'offset' => $offset,
            ];
        }
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            $stmt = $this->pdo->prepare($sql);
            $this->bindParams($stmt, $params);
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    public function execute(string $sql, array $params = []): bool|DatabaseResult
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            $stmt = $this->pdo->prepare($sql);
            $this->bindParams($stmt, $params);
            return $stmt->execute();
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function executeMany(string $sql, array $paramsList = []): int
    {
        $this->ensureOpen();
        $this->lastError = null;
        $totalAffected = 0;

        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($paramsList as $params) {
                $this->bindParams($stmt, $params);
                $stmt->execute();
                $totalAffected += $stmt->rowCount();
            }
            return $totalAffected;
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            return 0;
        }
    }

    public function insert(string $table, array $data): bool
    {
        // List of rows
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
        $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";
        return $this->execute($sql, array_values($data));
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
        // List of assoc arrays — delete each row
        if (is_array($filter) && isset($filter[0]) && is_array($filter[0])) {
            foreach ($filter as $row) {
                if (!$this->delete($table, $row)) {
                    return false;
                }
            }
            return true;
        }

        // Assoc array — build WHERE from keys
        if (is_array($filter)) {
            $parts = [];
            $params = [];
            foreach ($filter as $col => $val) {
                $parts[] = "{$col} = ?";
                $params[] = $val;
            }
            $where = implode(' AND ', $parts);
            return $this->delete($table, $where, $params);
        }

        // String filter
        $sql = "DELETE FROM {$table}";
        if ($filter !== '') {
            $sql .= " WHERE {$filter}";
        }
        return $this->execute($sql, $whereParams);
    }

    public function tableExists(string $table): bool
    {
        try {
            // PDO ODBC supports INFORMATION_SCHEMA on most targets
            $row = $this->fetchOne(
                "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?",
                [$table]
            );
            if ($row !== null) {
                return true;
            }
        } catch (\Exception) {
            // Fall through to direct query probe
        }

        // Fallback: try to query the table directly
        try {
            $stmt = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE 1=0");
            $stmt->execute();
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function getTables(): array
    {
        try {
            // Use PDO's built-in schema metadata if available
            $rows = $this->pdo->query(
                "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
            )->fetchAll(\PDO::FETCH_ASSOC);

            // Column name may vary by driver casing
            return array_map(
                fn($row) => $row['TABLE_NAME'] ?? $row['table_name'] ?? '',
                $rows
            );
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function getColumns(string $table): array
    {
        try {
            $rows = $this->query(
                "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT "
                . "FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? ORDER BY ORDINAL_POSITION",
                [$table]
            );

            return array_map(fn($row) => [
                'name'     => $row['COLUMN_NAME'] ?? $row['column_name'] ?? '',
                'type'     => $row['DATA_TYPE']   ?? $row['data_type']   ?? '',
                'nullable' => strtoupper($row['IS_NULLABLE'] ?? $row['is_nullable'] ?? 'YES') === 'YES',
                'default'  => $row['COLUMN_DEFAULT'] ?? $row['column_default'] ?? null,
                'primary'  => false, // INFORMATION_SCHEMA.COLUMNS does not expose PK directly
            ], $rows);
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function lastInsertId(): int|string
    {
        $this->ensureOpen();
        try {
            return $this->pdo->lastInsertId();
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return 0;
        }
    }

    public function startTransaction(): void
    {
        $this->ensureOpen();
        try {
            $this->pdo->beginTransaction();
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
        }
    }

    public function commit(): void
    {
        $this->ensureOpen();
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
        }
    }

    public function rollback(): void
    {
        $this->ensureOpen();
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
        }
    }

    public function error(): ?string
    {
        return $this->lastError;
    }

    /**
     * Get the underlying PDO connection (for advanced usage).
     */
    public function getConnection(): ?\PDO
    {
        return $this->pdo;
    }

    /**
     * Get the ODBC connection string.
     */
    public function getConnectionString(): string
    {
        return $this->connectionString;
    }

    /**
     * Ensure the database connection is open.
     */
    private function ensureOpen(): void
    {
        if ($this->pdo === null) {
            $this->open();
        }
    }

    /**
     * Bind parameters to a PDO prepared statement.
     * Supports both positional (?) and named (:name) placeholders.
     */
    private function bindParams(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $paramKey = is_int($key) ? $key + 1 : $key;
            $type = match (true) {
                is_int($value)  => \PDO::PARAM_INT,
                is_bool($value) => \PDO::PARAM_BOOL,
                is_null($value) => \PDO::PARAM_NULL,
                default         => \PDO::PARAM_STR,
            };
            $stmt->bindValue($paramKey, $value, $type);
        }
    }
}
