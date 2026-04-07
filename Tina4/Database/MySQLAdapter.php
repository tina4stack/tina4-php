<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * MySQL/MariaDB database adapter — uses PHP's mysqli extension.
 * The extension is optional; a clear error is thrown if not installed.
 */
class MySQLAdapter implements DatabaseAdapter
{
    private ?\mysqli $db = null;
    private ?string $lastError = null;
    private bool $autoCommit;

    /**
     * @param string $connectionString URL: "mysql://user:pass@host:port/dbname"
     *                                  or host string with separate params
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
        private readonly int $port = 3306,
        ?bool $autoCommit = null,
    ) {
        if (!extension_loaded('mysqli')) {
            throw new \RuntimeException(
                'MySQLAdapter requires the ext-mysqli PHP extension. '
                . 'Install it with: sudo apt-get install php-mysql (Debian/Ubuntu) '
                . 'or brew install php (macOS with mysqli enabled).'
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

        try {
            $this->db = new \mysqli(
                $params['host'],
                $params['username'],
                $params['password'],
                $params['database'],
                $params['port']
            );

            if ($this->db->connect_error) {
                $this->lastError = $this->db->connect_error;
                throw new \RuntimeException("MySQLAdapter: Failed to connect: {$this->db->connect_error}");
            }

            $this->db->set_charset('utf8mb4');
            $this->db->autocommit($this->autoCommit);
        } catch (\mysqli_sql_exception $e) {
            $this->lastError = $e->getMessage();
            throw new \RuntimeException("MySQLAdapter: Failed to connect: {$e->getMessage()}");
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
            if (empty($params)) {
                $result = $this->db->query($sql);
            } else {
                $stmt = $this->db->prepare($sql);
                if ($stmt === false) {
                    $this->lastError = $this->db->error;
                    return [];
                }

                $this->bindParams($stmt, $params);
                $stmt->execute();
                $result = $stmt->get_result();
            }

            if ($result === false) {
                $this->lastError = $this->db->error;
                return [];
            }

            if ($result === true) {
                // Non-SELECT query
                return [];
            }

            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }

            $result->free();

            if (isset($stmt)) {
                $stmt->close();
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
            if (empty($params)) {
                $success = $this->db->query($sql);
            } else {
                $stmt = $this->db->prepare($sql);
                if ($stmt === false) {
                    $this->lastError = $this->db->error;
                    return false;
                }

                $this->bindParams($stmt, $params);
                $success = $stmt->execute();
                $stmt->close();
            }

            if ($success === false) {
                $this->lastError = $this->db->error;
                return false;
            }

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
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$table]
        );
        return count($rows) > 0;
    }

    public function getColumns(string $table): array
    {
        $rows = $this->query("DESCRIBE {$table}");
        $columns = [];

        foreach ($rows as $row) {
            $columns[] = [
                'name' => $row['Field'],
                'type' => $row['Type'],
                'nullable' => $row['Null'] === 'YES',
                'default' => $row['Default'],
                'primary' => $row['Key'] === 'PRI',
            ];
        }

        return $columns;
    }

    public function getTables(): array
    {
        $rows = $this->query("SHOW TABLES");
        $tables = [];
        foreach ($rows as $row) {
            $tables[] = reset($row);
        }
        return $tables;
    }

    public function lastInsertId(): int|string
    {
        $this->ensureOpen();
        return $this->db->insert_id;
    }

    public function startTransaction(): void
    {
        $this->ensureOpen();
        $this->db->begin_transaction();
    }

    public function commit(): void
    {
        $this->ensureOpen();
        $this->db->commit();
    }

    public function rollback(): void
    {
        $this->ensureOpen();
        try {
            $this->db->rollback();
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
        }
    }

    public function error(): ?string
    {
        return $this->lastError;
    }

    /**
     * Get the underlying mysqli connection.
     */
    public function getConnection(): ?\mysqli
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
        // URL format: mysql://user:pass@host:port/dbname
        if (str_contains($input, '://')) {
            $parts = parse_url($input);
            return [
                'host' => $parts['host'] ?? 'localhost',
                'port' => $parts['port'] ?? 3306,
                'username' => isset($parts['user']) ? urldecode($parts['user']) : $this->username,
                'password' => isset($parts['pass']) ? urldecode($parts['pass']) : $this->password,
                'database' => ltrim($parts['path'] ?? '', '/'),
            ];
        }

        // Plain host string — use constructor params
        return [
            'host' => $input ?: 'localhost',
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
            'database' => $this->database,
        ];
    }

    /**
     * Bind parameters to a mysqli prepared statement.
     */
    private function bindParams(\mysqli_stmt $stmt, array $params): void
    {
        if (empty($params)) {
            return;
        }

        $values = array_values($params);
        $types = '';

        foreach ($values as $value) {
            $types .= match (true) {
                is_int($value) => 'i',
                is_float($value) => 'd',
                is_null($value) => 's',
                default => 's',
            };
        }

        $stmt->bind_param($types, ...$values);
    }
}
