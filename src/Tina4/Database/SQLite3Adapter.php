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
     * @param bool $autoCommit Whether to auto-commit (default based on env TINA4_AUTO_COMMIT)
     */
    public function __construct(
        private readonly string $database = ':memory:',
        ?bool $autoCommit = null,
    ) {
        $envAutoCommit = \Tina4\DotEnv::get('TINA4_AUTO_COMMIT');
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

            // Fetch paginated results
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
