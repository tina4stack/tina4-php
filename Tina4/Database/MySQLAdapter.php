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
    use CrudSqlTrait;

    use AutocommitTrait;
    use ConnectTimeoutTrait;

    /**
     * The SQL dialect this adapter speaks.
     *
     * An adapter has to be able to NAME its engine before anything can build
     * DDL for it. Until now the only way to find out was ORM::detectDialect(),
     * a private instanceof chain that type-checks the adapter from outside -
     * so a caller depended on the concrete class rather than the contract, and
     * a new adapter was invisible to it until someone edited that match block.
     *
     * This is the prerequisite for createTable() and addColumn() on the
     * adapter, which is why those two are the last of the contract to land.
     */
    public function getDatabaseType(): string
    {
        return 'mysql';
    }

    /**
     * MySQL is told the setting at connect time (`$this->db->autocommit(...)`),
     * so changing the flag afterwards has to reach the driver as well or the
     * connection keeps the old behaviour. The trait's accessor alone would set a
     * flag nothing acts on.
     */
    public function autocommit(?bool $on = null): bool
    {
        if ($on !== null) {
            $this->autoCommit = $on;
            if (isset($this->db) && $this->db !== null) {
                @$this->db->autocommit($on);
            }
        }

        return $this->autoCommit;
    }

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

        // Bound the connect (TINA4_DATABASE_CONNECT_TIMEOUT). This needs BOTH
        // options, and therefore the mysqli_init()/real_connect() form — the
        // one-shot `new \mysqli(...)` constructor gives no window to set them.
        // MYSQLI_OPT_CONNECT_TIMEOUT alone bounds only the TCP connect, NOT the
        // read of the server's greeting packet: MEASURED against a socket that
        // accepts and never replies (Ubuntu 24.04.4, PHP 8.3.6), CONNECT_TIMEOUT
        // on its own still blocked past 25s, and adding MYSQLI_OPT_READ_TIMEOUT
        // stopped it at 3.002987s.
        $timeout = $this->beginConnectTimeout();

        try {
            $connection = mysqli_init();
            if ($timeout > 0) {
                $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, $timeout);
                $connection->options(MYSQLI_OPT_READ_TIMEOUT, $timeout);
            }

            // The greeting-packet read aborted by READ_TIMEOUT raises a PHP
            // warning of its own; we report the failure ourselves below.
            @$connection->real_connect(
                $host,
                $params['username'],
                $params['password'],
                $params['database'],
                $params['port']
            );
            $this->db = $connection;

            if ($this->db->connect_error) {
                $this->throwIfConnectTimedOut(
                    'MySQLAdapter',
                    $host . ':' . $params['port'],
                    (string) $this->db->connect_error
                );
                $this->lastError = $this->db->connect_error;
                $this->db = null;
                throw new \RuntimeException("MySQLAdapter: Failed to connect: {$this->lastError}");
            }

            $this->db->set_charset('utf8mb4');
            $this->db->autocommit($this->autoCommit);
        } catch (\mysqli_sql_exception $e) {
            $this->db = null;
            $this->throwIfConnectTimedOut('MySQLAdapter', $host . ':' . $params['port'], $e);
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
        $sql = self::translateDialect($sql);

        try {
            if (empty($params)) {
                $result = $this->db->query($sql);
            } else {
                // mysqli only speaks ? — translate :named from the ORM/QueryBuilder.
                [$sql, $params] = \Tina4\SQLTranslator::namedToPositional($sql, $params);
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
        // The closing paren goes on its OWN LINE (wrapCountSubquery): inline, a
        // trailing `-- comment` in the user SQL comments the `)` out and the
        // probe dies, silently reporting total = 0 next to real records.
        $total = 0;
        $countSql = self::wrapCountSubquery($sql, '_count_query');
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
        // NEW LINE (appendSqlClause): appended inline the cap lands inside a
        // trailing `-- comment` and is swallowed, returning the WHOLE table.
        $pagedSql = ($limit <= 0 || self::hasTrailingLimit($sql))
            ? $sql
            : self::appendSqlClause($sql, "LIMIT {$limit} OFFSET {$offset}");
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
        $sql = self::translateDialect($sql);

        try {
            if (empty($params)) {
                $success = $this->db->query($sql);
            } else {
                // mysqli only speaks ? — translate :named from the ORM/QueryBuilder.
                [$sql, $params] = \Tina4\SQLTranslator::namedToPositional($sql, $params);
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
                    // MySQL reports the FIRST id of a MULTI-ROW INSERT; normalise
                    // to the LAST via the shared helper, in ONE place instead of
                    // the two inline blocks this and the non-prepared path used
                    // to duplicate (MYSQL-BATCH-ID-DUP).
                    $this->captureInsertId((int)$stmt->insert_id, (int)$stmt->affected_rows);
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
                // Same normalisation as the prepared path above, via the ONE
                // shared helper (MYSQL-BATCH-ID-DUP).
                $this->captureInsertId((int)$this->db->insert_id, (int)$this->db->affected_rows);
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
        // DESCRIBE takes an IDENTIFIER, not a bind parameter, so the table name
        // is made injection-safe by STRICT backtick-quoting (escaping embedded
        // backticks) rather than interpolated raw (MYSQL-DESCRIBE-UNPARAM): a
        // crafted/odd name becomes ONE escaped identifier - a clean "unknown
        // table", never runnable SQL - and an odd-but-valid name introspects.
        $rows = $this->query('DESCRIBE ' . self::quoteMysqlIdentifier($table));
        $columns = [];

        foreach ($rows as $row) {
            $columns[] = [
                'name' => $row['Field'],
                'type' => $row['Type'],
                'nullable' => $row['Null'] === 'YES',
                'default' => $row['Default'],
                'primaryKey' => $row['Key'] === 'PRI',
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

    /**
     * Apply the MySQL dialect rewrites the translator owns: `||` -> CONCAT and
     * ILIKE -> LOWER() LIKE LOWER() (both literal-safe). MySQL reads a bare `||`
     * as logical OR and has no ILIKE, so portable canonical SQL must be rewritten
     * before it reaches the driver. A statement with neither token is returned
     * unchanged (the transforms short-circuit), so ordinary queries are untouched.
     */
    private static function translateDialect(string $sql): string
    {
        $sql = \Tina4\SQLTranslator::concatPipesToFunc($sql);
        $sql = \Tina4\SQLTranslator::ilikeToLike($sql);
        return $sql;
    }

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
     * Capture the generated id at INSERT time, normalising the FIRST id MySQL
     * reports for a multi-row INSERT to the LAST (the ids are consecutive) via
     * the shared SQLTranslator helper. ONE place instead of the two inline
     * blocks the prepared and non-prepared write paths used to duplicate
     * (MYSQL-BATCH-ID-DUP). Guarded by a positive insert_id at the call site, so
     * a non-INSERT never lands here.
     *
     * @param int $insertId     The id the driver reported (the FIRST for a batch)
     * @param int $affectedRows Rows the statement wrote
     */
    private function captureInsertId(int $insertId, int $affectedRows): void
    {
        $this->lastId = \Tina4\SQLTranslator::batchLastId($insertId, max($affectedRows, 1), 'mysql');
    }

    /**
     * Strict backtick-quote a (possibly schema-qualified) identifier, ESCAPING
     * embedded backticks. DESCRIBE takes an identifier, not a bind parameter, so
     * the table name is quoted rather than interpolated raw
     * (MYSQL-DESCRIBE-UNPARAM): a crafted/odd name becomes ONE escaped identifier
     * - a clean "unknown table", never runnable SQL.
     *
     * @param string $name A bare or ``schema.table`` identifier
     * @return string The strict-quoted identifier
     */
    private static function quoteMysqlIdentifier(string $name): string
    {
        [$schema, $table] = self::splitSchema($name);
        $q = static fn(string $part): string => '`' . str_replace('`', '``', $part) . '`';
        return $schema === null ? $q($table) : $q($schema) . '.' . $q($table);
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
