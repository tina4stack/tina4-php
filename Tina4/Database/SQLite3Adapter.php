<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * SQLite3 database adapter — uses PHP's built-in SQLite3 class.
 * Zero external dependencies.
 */
class SQLite3Adapter implements DatabaseAdapter
{
    private ?\SQLite3 $db = null;
    private ?string $lastError = null;
    private bool $autoCommit;

    /**
     * @param string $database Path to the SQLite database file, or ":memory:" for in-memory
     * @param bool $autoCommit Whether to auto-commit (default based on env TINA4_AUTOCOMMIT)
     */
    public function __construct(
        private readonly string $database = ':memory:',
        ?bool $autoCommit = null,
    ) {
        $envAutoCommit = \Tina4\DotEnv::getEnv('TINA4_AUTOCOMMIT');
        $this->autoCommit = $autoCommit ?? ($envAutoCommit !== null ? filter_var($envAutoCommit, FILTER_VALIDATE_BOOLEAN) : false);
        $this->open();
    }

    public function open(): void
    {
        if ($this->db !== null) {
            return;
        }

        try {
            $this->db = new \SQLite3($this->database);
            $this->db->enableExceptions(true);
            // Enable WAL mode for better concurrent access
            $this->db->exec('PRAGMA journal_mode=WAL');
            $this->db->exec('PRAGMA foreign_keys=ON');
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            throw new \RuntimeException("SQLite3: Failed to open database: {$e->getMessage()}");
        }
    }

    public function close(): void
    {
        if ($this->db !== null) {
            $this->db->close();
            $this->db = null;
        }
    }

    public function query(string $sql, array $params = []): array
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            $stmt = $this->db->prepare($sql);

            if ($stmt === false) {
                $this->lastError = $this->db->lastErrorMsg();
                return [];
            }

            $this->bindParams($stmt, $params);
            $result = $stmt->execute();

            if ($result === false) {
                $this->lastError = $this->db->lastErrorMsg();
                return [];
            }

            $rows = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $rows[] = $row;
            }

            $result->finalize();
            $stmt->close();

            if ($this->autoCommit && $this->isWriteQuery($sql)) {
                // Auto-commit is handled by SQLite's default autocommit mode
            }

            return $rows;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function fetch(string $sql, int $limit = 10, int $offset = 0, array $params = []): array
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM ({$sql})";
            $countResult = $this->query($countSql, $params);
            $total = (int)($countResult[0]['total'] ?? 0);

            // Apply pagination — skip if SQL already has LIMIT
            $sqlNoComments = preg_replace('/--.*$/m', '', $sql);
            if (stripos($sqlNoComments, 'LIMIT') !== false) {
                $pagedSql = $sql;
            } else {
                $pagedSql = "{$sql} LIMIT {$limit} OFFSET {$offset}";
            }
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

    public function exec(string $sql, array $params = []): bool
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            if (empty($params)) {
                $success = $this->db->exec($sql);
            } else {
                $stmt = $this->db->prepare($sql);
                if ($stmt === false) {
                    $this->lastError = $this->db->lastErrorMsg();
                    return false;
                }
                $this->bindParams($stmt, $params);
                $result = $stmt->execute();
                $success = $result !== false;
                if ($result !== false) {
                    $result->finalize();
                }
                $stmt->close();
            }

            if (!$success) {
                $this->lastError = $this->db->lastErrorMsg();
            }

            return $success;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function execute(string $sql, array $params = []): bool
    {
        return $this->exec($sql, $params);
    }

    public function executeMany(string $sql, array $paramsList = []): int
    {
        $this->ensureOpen();
        $this->lastError = null;
        $totalAffected = 0;

        try {
            $stmt = $this->db->prepare($sql);
            if ($stmt === false) {
                $this->lastError = $this->db->lastErrorMsg();
                return 0;
            }

            foreach ($paramsList as $params) {
                $stmt->reset();
                $stmt->clear();
                $this->bindParams($stmt, $params);
                $result = $stmt->execute();
                if ($result !== false) {
                    $totalAffected += $this->db->changes();
                    $result->finalize();
                }
            }

            $stmt->close();
            return $totalAffected;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return 0;
        }
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = $this->query($sql, $params);
        return $rows[0] ?? null;
    }

    public function insert(string $table, array $data): bool
    {
        // Detect list of rows (indexed array of assoc arrays)
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

    public function getTables(): array
    {
        $rows = $this->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        return array_column($rows, 'name');
    }

    public function startTransaction(): void
    {
        $this->begin();
    }

    public function tableExists(string $table): bool
    {
        $rows = $this->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name = :name",
            [':name' => $table]
        );
        return count($rows) > 0;
    }

    public function getColumns(string $table): array
    {
        $rows = $this->query("PRAGMA table_info('{$table}')");
        $columns = [];

        foreach ($rows as $row) {
            $columns[] = [
                'name' => $row['name'],
                'type' => $row['type'],
                'nullable' => (int)$row['notnull'] === 0,
                'default' => $row['dflt_value'],
                'primary' => (int)$row['pk'] > 0,
            ];
        }

        return $columns;
    }

    public function lastInsertId(): int|string
    {
        $this->ensureOpen();
        return $this->db->lastInsertRowID();
    }

    public function begin(): void
    {
        $this->ensureOpen();
        $this->db->exec('BEGIN TRANSACTION');
    }

    public function commit(): void
    {
        $this->ensureOpen();
        $this->db->exec('COMMIT');
    }

    public function rollback(): void
    {
        $this->ensureOpen();
        try {
            $this->db->exec('ROLLBACK');
        } catch (\Exception $e) {
            // Rollback may fail if no transaction is active
            $this->lastError = $e->getMessage();
        }
    }

    public function error(): ?string
    {
        return $this->lastError;
    }

    /**
     * Get the underlying SQLite3 connection (for advanced usage).
     */
    public function getConnection(): ?\SQLite3
    {
        return $this->db;
    }

    /**
     * Get the database path.
     */
    public function getDatabase(): string
    {
        return $this->database;
    }

    /**
     * Ensure the database connection is open.
     */
    private function ensureOpen(): void
    {
        if ($this->db === null) {
            $this->open();
        }
    }

    /**
     * Bind parameters to a prepared statement.
     */
    private function bindParams(\SQLite3Stmt $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $paramKey = is_int($key) ? $key + 1 : $key;
            $type = match (true) {
                is_int($value) => SQLITE3_INTEGER,
                is_float($value) => SQLITE3_FLOAT,
                is_null($value) => SQLITE3_NULL,
                default => SQLITE3_TEXT,
            };
            $stmt->bindValue($paramKey, $value, $type);
        }
    }

    /**
     * Check if a SQL query is a write operation.
     */
    private function isWriteQuery(string $sql): bool
    {
        $sql = ltrim($sql);
        $firstWord = strtoupper(strtok($sql, " \t\n\r"));
        return in_array($firstWord, ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'], true);
    }
}
