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
    use SqlNormalizerTrait;

    private ?\mysqli $db = null;
    private ?string $lastError = null;
    private bool $autoCommit;
    private int|string $lastId = 0;

    /** @var int Rows affected by the most recent write (mysqli affected_rows). */
    private int $affectedRows = 0;

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
        $this->autoCommit = $autoCommit ?? ($envAutoCommit !== null ? filter_var($envAutoCommit, FILTER_VALIDATE_BOOLEAN) : true);
        $this->open();
    }

    public function open(): void
    {
        if ($this->db !== null) {
            return;
        }

        $params = $this->parseConnection($this->connectionString);

        // PHP's mysqli has a known quirk where host == "localhost" triggers
        // Unix socket lookup and IGNORES the port argument — so connecting
        // to mysql://...:53306 against a Docker container fails with "No
        // such file or directory". When a non-default port is in play,
        // rewrite to 127.0.0.1 so mysqli takes the TCP code path.
        $host = self::rewriteHostForTcp($params['host'], $params['port']);

        try {
            $this->db = new \mysqli(
                $host,
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
                // mysqli only speaks ? — translate :named from the ORM/QueryBuilder.
                [$sql, $params] = \Tina4\SqlTranslation::namedToPositional($sql, $params);
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
        // v3.13.12: strip trailing `;` before COUNT(*) wrap + LIMIT/OFFSET append.
        $sql = self::stripTrailingSemicolons($sql);

        // v3.13.37 (DB-contract A): the MAIN query must FAIL LOUD — a bad
        // statement RAISES instead of being swallowed into an empty result set
        // (parity with execute(), fetchOne() and the Python master). The COUNT
        // probe is best-effort: it runs first and its failure only defaults
        // total to 0 — it must NEVER mask a real main-query failure. We run the
        // probe on a SEPARATE statement (its own query() call, which uses its
        // own prepared statement) so a probe failure can't leave the main
        // query's cursor half-consumed.
        $total = 0;
        $countSql = "SELECT COUNT(*) as total FROM ({$sql}) AS _count_query";
        $countResult = $this->query($countSql, $params);
        if ($this->lastError === null) {
            $total = (int)($countResult[0]['total'] ?? 0);
        }

        // v3.13.12: $limit <= 0 means "no pagination" (fetchAll's
        // default — give me ALL rows).
        // Skip the append when the user SQL already ends with its own
        // LIMIT clause — a second LIMIT is a syntax error that would
        // otherwise be swallowed into an empty result.
        $this->lastError = null;
        $pagedSql = ($limit <= 0 || self::hasTrailingLimit($sql))
            ? $sql
            : "{$sql} LIMIT {$limit} OFFSET {$offset}";
        $data = $this->query($pagedSql, $params);
        // query() clears lastError on entry and records the driver error on
        // failure (returning []), so a non-null lastError here means the MAIN
        // query failed — RAISE it (FAILS LOUD).
        if ($this->lastError !== null) {
            throw new DatabaseException('MySQL fetch() failed: ' . $this->lastError);
        }

        return [
            'data' => $data,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $sql = self::stripTrailingSemicolons($sql);
        // FAIL LOUD (v3.13.37, DB-contract A): query() clears lastError on
        // entry and records the driver error on failure (returning []), so a
        // non-null lastError after the call means the statement failed — RAISE
        // it instead of returning null (which a caller would read as "no row").
        $rows = $this->query($sql, $params);
        if ($this->lastError !== null) {
            throw new DatabaseException('MySQL fetchOne() failed: ' . $this->lastError);
        }
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
                // mysqli only speaks ? — translate :named from the ORM/QueryBuilder.
                [$sql, $params] = \Tina4\SqlTranslation::namedToPositional($sql, $params);
                $stmt = $this->db->prepare($sql);
                if ($stmt === false) {
                    $this->lastError = $this->db->error;
                    throw new DatabaseException('MySQL execute() failed: ' . ($this->lastError ?: 'prepare failed'));
                }

                $this->bindParams($stmt, $params);
                $success = $stmt->execute();
                // CAPTURE the auto-increment id from THIS statement, before close()
                // — mysqli_stmt::$insert_id reflects the row this prepared INSERT
                // just created. (See note in lastInsertId().)
                if ($success !== false && $stmt->insert_id > 0) {
                    $this->lastId = $stmt->insert_id;
                }
                // Affected rows for the prepared write, before close() drops it.
                if ($success !== false) {
                    $this->affectedRows = max(0, $stmt->affected_rows);
                }
                $stmt->close();
            }

            if ($success === false) {
                // FAIL LOUD: capture the cause on error() AND raise.
                $this->affectedRows = 0;
                $this->lastError = $this->db->error;
                throw new DatabaseException('MySQL execute() failed: ' . ($this->lastError ?: 'unknown error'));
            }

            // Non-prepared path: connection-level affected_rows for the write.
            if (empty($params)) {
                $this->affectedRows = max(0, $this->db->affected_rows);
            }

            // CAPTURE the auto-increment id at WRITE time (mirrors MSSQLAdapter's
            // $this->lastId and Python mysql.py's cursor.lastrowid). mysqli's
            // connection-level insert_id is reset to 0 by a subsequent SELECT, so
            // a later getLastId()/lastInsertId() after an intervening fetch() would
            // otherwise read 0 and LOSE the id (#262). Reading it here, right after
            // a successful INSERT, pins it. The connection-level read above only
            // applies to the non-prepared (no-params) path; insert_id is 0 for any
            // non-INSERT statement, so this never clobbers a real id with a stale 0.
            if (empty($params) && $this->db->insert_id > 0) {
                $this->lastId = $this->db->insert_id;
            }

            return true;
        } catch (DatabaseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            throw $e;
        }
    }

    public function executeMany(string $sql, array $paramsList = []): int
    {
        // FAIL LOUD: a failing row must NOT be silently swallowed (see the note
        // in PostgresAdapter::executeMany). execute() raises on a bad row; let
        // it propagate so the facade's transactional batch path can roll the
        // whole batch back instead of committing a partial, lossy result.
        $totalAffected = 0;
        foreach ($paramsList as $params) {
            $this->execute($sql, $params);
            $totalAffected++;
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
        // v3.13.14 (#48): honour a database-qualified name ("otherdb.table");
        // default to the connected database. In MySQL "schema" == database.
        [$schema, $tbl] = self::splitSchema($table);
        $rows = $this->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = COALESCE(?, DATABASE()) AND TABLE_NAME = ?",
            [$schema, $tbl]
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
        // Return the id CAPTURED at INSERT time (execute()), not a fresh read of
        // mysqli's connection-level insert_id — that property is reset to 0 by any
        // intervening SELECT, so reading it lazily here loses the id after an
        // insert+fetch (#262). The stored value mirrors MSSQLAdapter::$lastId and
        // Python mysql.py's cursor.lastrowid (both captured at write time).
        if ($this->lastId !== 0 && $this->lastId !== '0') {
            return $this->lastId;
        }

        // Fallback: nothing captured (e.g. a raw query() INSERT that bypassed
        // execute()) — best-effort read of the connection-level id.
        $this->ensureOpen();
        return $this->db->insert_id;
    }

    /**
     * Rows affected by the most recent write (mysqli affected_rows).
     */
    public function affectedRows(): int
    {
        return $this->affectedRows;
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
     * Rewrite "localhost" to "127.0.0.1" when a port is specified.
     *
     * PHP's \mysqli silently swaps to a Unix socket when host is exactly
     * "localhost" — and the port argument is ignored entirely on that
     * code path. That breaks any TCP test setup such as
     * ``mysql://tina4user:tina4@localhost:53306/tina4`` against a Docker
     * container, surfacing as "No such file or directory" because mysqli
     * is hunting for /tmp/mysql.sock.
     *
     * Only rewrite when a non-zero port is specified — if the user wrote
     * ``mysql:///db`` with no port, they almost certainly want the
     * default Unix socket path; preserve that behaviour.
     */
    public static function rewriteHostForTcp(string $host, ?int $port): string
    {
        if ($host === 'localhost' && $port !== null && $port > 0) {
            return '127.0.0.1';
        }
        return $host;
    }

    /**
     * Bind parameters to a mysqli prepared statement.
     */
    private function bindParams(\mysqli_stmt $stmt, array $params): void
    {
        if (empty($params)) {
            return;
        }

        // MySQL BOOLEAN is TINYINT(1); bind PHP booleans as 1/0 (otherwise the
        // default 's' arm stringifies `false` to '' — same class of bug as PG).
        $values = self::normalizeBoolParams(array_values($params), nativeBoolean: false);
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
