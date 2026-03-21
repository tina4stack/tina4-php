<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * Firebird database adapter — uses PHP ext-interbase (ibase or fbird functions).
 * The extension is optional; a clear error is thrown if not installed.
 *
 * Firebird specifics:
 *   - No LIMIT/OFFSET — uses FIRST/SKIP or ROWS X TO Y
 *   - No TEXT type — uses VARCHAR(n) or BLOB SUB_TYPE TEXT
 *   - No IF NOT EXISTS for ALTER TABLE ADD
 *   - Uses generators for auto-increment: GEN_ID(gen_name, 1)
 *   - RETURNING clause is supported (Firebird 2.0+)
 */
class FirebirdAdapter implements DatabaseAdapter
{
    /** @var resource|null */
    private mixed $db = null;
    /** @var resource|null Active transaction handle */
    private mixed $transaction = null;
    private ?string $lastError = null;
    private bool $autoCommit;
    private int|string $lastId = 0;

    /** @var string The ibase/fbird function prefix */
    private string $fn;

    /**
     * @param string $connectionString URL: "firebird://user:pass@host:port/path/to/db.fdb"
     *                                  or path: "/path/to/database.fdb"
     * @param string $username Username (default: SYSDBA)
     * @param string $password Password (default: masterkey)
     * @param string $charset Character set (default: UTF8)
     * @param bool|null $autoCommit Whether to auto-commit
     */
    public function __construct(
        private readonly string $connectionString,
        private readonly string $username = 'SYSDBA',
        private readonly string $password = 'masterkey',
        private readonly string $charset = 'UTF8',
        ?bool $autoCommit = null,
    ) {
        // Check for either ibase or fbird functions
        if (function_exists('ibase_connect')) {
            $this->fn = 'ibase_';
        } elseif (function_exists('fbird_connect')) {
            $this->fn = 'fbird_';
        } else {
            throw new \RuntimeException(
                'FirebirdAdapter requires the ext-interbase PHP extension (ibase_* or fbird_* functions). '
                . 'Install it with: sudo apt-get install php-interbase (Debian/Ubuntu) '
                . 'or sudo pecl install interbase (other platforms).'
            );
        }

        $envAutoCommit = \Tina4\DotEnv::getEnv('TINA4_AUTO_COMMIT');
        $this->autoCommit = $autoCommit ?? ($envAutoCommit !== null ? filter_var($envAutoCommit, FILTER_VALIDATE_BOOLEAN) : false);
        $this->open();
    }

    public function open(): void
    {
        if ($this->db !== null) {
            return;
        }

        $params = $this->parseConnection($this->connectionString);
        $connectFn = $this->fn . 'connect';

        $dbPath = $params['database'];
        if ($params['host'] !== '') {
            $dbPath = $params['host'];
            if ($params['port'] > 0 && $params['port'] !== 3050) {
                $dbPath .= '/' . $params['port'];
            }
            $dbPath .= ':' . $params['database'];
        }

        $conn = @$connectFn($dbPath, $params['username'], $params['password'], $this->charset);
        if ($conn === false) {
            $errFn = $this->fn . 'errmsg';
            $this->lastError = $errFn();
            throw new \RuntimeException("FirebirdAdapter: Failed to connect: {$this->lastError}");
        }

        $this->db = $conn;
    }

    public function close(): void
    {
        if ($this->db !== null) {
            $closeFn = $this->fn . 'close';
            $closeFn($this->db);
            $this->db = null;
            $this->transaction = null;
        }
    }

    public function query(string $sql, array $params = []): array
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            $result = $this->executeInternal($sql, $params);
            if ($result === false) {
                return [];
            }

            // For non-result queries (DDL, DML without RETURNING)
            if ($result === true) {
                return [];
            }

            $rows = [];
            $fetchFn = $this->fn . 'fetch_assoc';
            while ($row = @$fetchFn($result)) {
                // Trim string values (Firebird pads CHAR fields)
                $cleaned = [];
                foreach ($row as $key => $value) {
                    $cleaned[$key] = is_string($value) ? rtrim($value) : $value;
                }
                $rows[] = $cleaned;
            }

            $freeFn = $this->fn . 'free_result';
            @$freeFn($result);

            // Auto-commit if enabled and this is a write query
            if ($this->autoCommit && $this->isWriteQuery($sql) && $this->transaction === null) {
                $this->commitDefault();
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
            // Count total
            $countSql = "SELECT COUNT(*) AS total FROM ({$sql})";
            $countResult = $this->query($countSql, $params);
            $total = (int)($countResult[0]['TOTAL'] ?? $countResult[0]['total'] ?? 0);

            // Firebird pagination: ROWS X TO Y (1-based)
            $startRow = $offset + 1;
            $endRow = $offset + $limit;
            $pagedSql = "{$sql} ROWS {$startRow} TO {$endRow}";
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
            $result = $this->executeInternal($sql, $params);
            if ($result === false) {
                return false;
            }

            // If result is a resource (e.g. RETURNING), read first row for lastId
            if ($result !== true) {
                $fetchFn = $this->fn . 'fetch_assoc';
                $row = @$fetchFn($result);
                if ($row !== false && $row !== null) {
                    $first = reset($row);
                    if ($first !== false) {
                        $this->lastId = is_string($first) ? trim($first) : $first;
                    }
                }
                $freeFn = $this->fn . 'free_result';
                @$freeFn($result);
            }

            if ($this->autoCommit && $this->transaction === null) {
                $this->commitDefault();
            }

            return true;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function insert(string $table, array $data): bool
    {
        $cols = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders}) RETURNING *";

        // Try with RETURNING first, fall back without if it fails
        $result = $this->execute($sql, array_values($data));
        if ($result) {
            return true;
        }

        // Fallback without RETURNING
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

    public function delete(string $table, string $where = '', array $whereParams = []): bool
    {
        $sql = "DELETE FROM {$table}";
        if ($where !== '') {
            $sql .= " WHERE {$where}";
        }
        return $this->execute($sql, $whereParams);
    }

    public function tableExists(string $table): bool
    {
        $rows = $this->query(
            "SELECT RDB\$RELATION_NAME FROM RDB\$RELATIONS WHERE RDB\$SYSTEM_FLAG = 0 AND RDB\$VIEW_BLR IS NULL AND TRIM(RDB\$RELATION_NAME) = ?",
            [strtoupper($table)]
        );
        return count($rows) > 0;
    }

    public function getColumns(string $table): array
    {
        $sql = "SELECT RF.RDB\$FIELD_NAME AS field_name,
                       F.RDB\$FIELD_TYPE AS field_type,
                       RF.RDB\$NULL_FLAG AS null_flag,
                       RF.RDB\$DEFAULT_SOURCE AS default_source
                FROM RDB\$RELATION_FIELDS RF
                JOIN RDB\$FIELDS F ON RF.RDB\$FIELD_SOURCE = F.RDB\$FIELD_NAME
                WHERE RF.RDB\$RELATION_NAME = ?
                ORDER BY RF.RDB\$FIELD_POSITION";

        $rows = $this->query($sql, [strtoupper($table)]);
        $columns = [];

        // Firebird field type codes
        $typeMap = [
            7 => 'SMALLINT', 8 => 'INTEGER', 10 => 'FLOAT', 12 => 'DATE',
            13 => 'TIME', 14 => 'CHAR', 16 => 'BIGINT', 27 => 'DOUBLE PRECISION',
            35 => 'TIMESTAMP', 37 => 'VARCHAR', 261 => 'BLOB',
        ];

        // Get primary key columns
        $pkRows = $this->query(
            "SELECT TRIM(ISG.RDB\$FIELD_NAME) AS pk_field
             FROM RDB\$RELATION_CONSTRAINTS RC
             JOIN RDB\$INDEX_SEGMENTS ISG ON RC.RDB\$INDEX_NAME = ISG.RDB\$INDEX_NAME
             WHERE RC.RDB\$CONSTRAINT_TYPE = 'PRIMARY KEY' AND RC.RDB\$RELATION_NAME = ?",
            [strtoupper($table)]
        );
        $pkFields = array_column($pkRows, 'PK_FIELD');
        // Trim all PK field names
        $pkFields = array_map('trim', $pkFields);

        foreach ($rows as $row) {
            $fieldName = trim($row['FIELD_NAME'] ?? $row['field_name'] ?? '');
            $fieldType = (int)($row['FIELD_TYPE'] ?? $row['field_type'] ?? 0);

            $columns[] = [
                'name' => $fieldName,
                'type' => $typeMap[$fieldType] ?? "TYPE_{$fieldType}",
                'nullable' => ($row['NULL_FLAG'] ?? $row['null_flag'] ?? null) === null,
                'default' => $row['DEFAULT_SOURCE'] ?? $row['default_source'] ?? null,
                'primary' => in_array($fieldName, $pkFields, true),
            ];
        }

        return $columns;
    }

    public function getTables(): array
    {
        $rows = $this->query(
            "SELECT TRIM(RDB\$RELATION_NAME) AS table_name FROM RDB\$RELATIONS WHERE RDB\$SYSTEM_FLAG = 0 AND RDB\$VIEW_BLR IS NULL ORDER BY RDB\$RELATION_NAME"
        );

        return array_map(
            fn($row) => trim($row['TABLE_NAME'] ?? $row['table_name'] ?? ''),
            $rows
        );
    }

    public function lastInsertId(): int|string
    {
        return $this->lastId;
    }

    public function startTransaction(): void
    {
        $this->ensureOpen();
        $transFn = $this->fn . 'trans';
        $this->transaction = $transFn(IBASE_DEFAULT, $this->db);
    }

    public function commit(): void
    {
        $this->ensureOpen();
        if ($this->transaction !== null) {
            $commitFn = $this->fn . 'commit';
            $commitFn($this->transaction);
            $this->transaction = null;
        } else {
            $this->commitDefault();
        }
    }

    public function rollback(): void
    {
        $this->ensureOpen();
        try {
            if ($this->transaction !== null) {
                $rollbackFn = $this->fn . 'rollback';
                $rollbackFn($this->transaction);
                $this->transaction = null;
            }
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
        }
    }

    public function error(): ?string
    {
        return $this->lastError;
    }

    /**
     * Get the underlying ibase/fbird connection resource.
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
     * Parse a connection string (URL or path) into connection params.
     */
    private function parseConnection(string $input): array
    {
        if (str_contains($input, '://')) {
            $parts = parse_url($input);
            return [
                'host' => $parts['host'] ?? '',
                'port' => $parts['port'] ?? 3050,
                'username' => isset($parts['user']) ? urldecode($parts['user']) : $this->username,
                'password' => isset($parts['pass']) ? urldecode($parts['pass']) : $this->password,
                'database' => ltrim($parts['path'] ?? '', '/'),
            ];
        }

        // Plain file path
        return [
            'host' => '',
            'port' => 3050,
            'username' => $this->username,
            'password' => $this->password,
            'database' => $input,
        ];
    }

    /**
     * Execute a SQL statement with params using ibase_prepare/ibase_execute.
     *
     * @return resource|bool Result resource or true for non-SELECT, false on error
     */
    private function executeInternal(string $sql, array $params): mixed
    {
        $errFn = $this->fn . 'errmsg';
        $context = $this->transaction ?? $this->db;

        if (empty($params)) {
            $queryFn = $this->fn . 'query';
            $result = @$queryFn($context, $sql);
        } else {
            $prepareFn = $this->fn . 'prepare';
            $executeFn = $this->fn . 'execute';

            $stmt = @$prepareFn($context, $sql);
            if ($stmt === false) {
                $this->lastError = $errFn();
                return false;
            }

            $values = array_values($params);
            $result = @$executeFn($stmt, ...$values);
        }

        if ($result === false) {
            $this->lastError = $errFn();
            return false;
        }

        return $result;
    }

    /**
     * Commit the default transaction (used for auto-commit).
     */
    private function commitDefault(): void
    {
        $commitFn = $this->fn . 'commit_ret';
        if (function_exists($commitFn)) {
            @$commitFn($this->db);
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
