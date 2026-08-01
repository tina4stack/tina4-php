<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
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
    use CrudSqlTrait;

    use AutocommitTrait;

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
        return 'sqlite';
    }

    use SqlNormalizerTrait;

    private ?\SQLite3 $db = null;
    private ?string $lastError = null;
    private bool $autoCommit;

    /** @var int Rows changed by the most recent write (SQLite3::changes()). */
    private int $affectedRows = 0;

    /**
     * @param string $database Path to the SQLite database file, or ":memory:" for in-memory
     * @param bool $autoCommit Whether to auto-commit (default based on env TINA4_AUTOCOMMIT)
     */
    public function __construct(
        string $database = ':memory:',
        ?bool $autoCommit = null,
    ) {
        $envAutoCommit = \Tina4\DotEnv::getEnv('TINA4_AUTOCOMMIT');
        $this->autoCommit = $autoCommit ?? ($envAutoCommit !== null ? filter_var($envAutoCommit, FILTER_VALIDATE_BOOLEAN) : true);
        $this->database = self::resolveDatabasePath($database);
        $this->open();
    }

    /** Resolved database path (after cwd-relative resolution). */
    private string $database;

    /**
     * Resolve a SQLite path argument against the project root (cwd).
     *
     * Matches the tina4-python and tina4-nodejs convention:
     *   ":memory:"         → passthrough
     *   "data/app.db"      → {cwd}/data/app.db  (auto-mkdir under cwd)
     *   "/abs/app.db"      → /abs/app.db        (NO auto-mkdir)
     *   "C:/Users/app.db"  → C:/Users/app.db    (NO auto-mkdir)
     *
     * Never mkdir outside cwd — that was the root cause of the
     * `Read-only file system: '/data'` crash reported on macOS.
     */
    private static function resolveDatabasePath(string $dbPath): string
    {
        if ($dbPath === ':memory:') {
            return $dbPath;
        }

        $isUnixAbs = str_starts_with($dbPath, '/');
        $isWindowsAbs = (
            strlen($dbPath) >= 3
            && ctype_alpha($dbPath[0])
            && $dbPath[1] === ':'
            && ($dbPath[2] === '/' || $dbPath[2] === '\\')
        );

        if ($isUnixAbs || $isWindowsAbs) {
            // Absolute — trust the user. Do NOT auto-mkdir outside cwd.
            return $dbPath;
        }

        // Relative — resolve under cwd and ensure parent exists.
        $resolved = getcwd() . DIRECTORY_SEPARATOR . $dbPath;
        $parent = dirname($resolved);
        if (!is_dir($parent)) {
            @mkdir($parent, 0775, true);
        }
        return $resolved;
    }

    public function open(): void
    {
        if ($this->db !== null) {
            return;
        }

        try {
            $this->db = new \SQLite3($this->database);
            $this->db->enableExceptions(true);
            // Wait (don't error) when another connection/process holds the
            // write lock — set BEFORE the WAL PRAGMA, which is itself a write
            // and can hit "database is locked" under concurrent opens.
            $this->db->busyTimeout(5000);
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

    public function fetch(string $sql, array $params = [], int $limit = 100, int $offset = 0): array
    {
        $this->ensureOpen();
        $this->lastError = null;
        // v3.13.12: strip trailing `;` before COUNT(*) wrap + LIMIT/OFFSET append.
        $sql = self::stripTrailingSemicolons($sql);

        // FAIL LOUD: query() records the driver error in error() and returns
        // []. fetch() must RAISE that instead of returning an empty result set
        // (parity with execute() and the Python master). query() clears
        // lastError on entry, so a non-null lastError after the call means the
        // statement failed.
        // The closing paren goes on its OWN LINE. MEASURED: inline, a trailing
        // `-- comment` in the user SQL commented the `)` out and the probe died
        // with "incomplete input" (see wrapCountSubquery).
        $countSql = self::wrapCountSubquery($sql);
        $countResult = $this->query($countSql, $params);
        if ($this->lastError !== null) {
            throw new DatabaseException('SQLite3 fetch() failed: ' . $this->lastError);
        }
        $total = (int)($countResult[0]['total'] ?? 0);

        // Apply pagination — skip if the SQL already ends with its own
        // LIMIT clause (appending a second one is a syntax error), or if
        // $limit <= 0 (v3.13.12: fetchAll's "give me all rows" path).
        // hasTrailingLimit() scrubs literals and BOTH comment styles itself;
        // the old local `--`-only strip missed a trailing block comment, so
        // `... LIMIT 3 /* c */` got a SECOND LIMIT appended and raised
        // "near LIMIT: syntax error".
        if ($limit <= 0 || self::hasTrailingLimit($sql)) {
            $pagedSql = $sql;
        } else {
            // NEW LINE: appended inline the cap lands inside a trailing
            // `-- comment` and is silently swallowed — the whole table then
            // comes back uncapped (see appendSqlClause).
            $pagedSql = self::appendSqlClause($sql, "LIMIT {$limit} OFFSET {$offset}");
        }
        $data = $this->query($pagedSql, $params);
        if ($this->lastError !== null) {
            throw new DatabaseException('SQLite3 fetch() failed: ' . $this->lastError);
        }

        return [
            'data' => $data,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * @deprecated Use execute() instead. exec() is kept for backward
     *   compatibility and will be removed in a future major version. execute()
     *   is the single canonical write method across all four Tina4 frameworks.
     */
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
                $this->affectedRows = 0;
            } else {
                // changes() reports rows modified by the most recent
                // INSERT/UPDATE/DELETE on this connection (0 for DDL).
                $this->affectedRows = $this->db->changes();
            }

            return $success;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            $this->affectedRows = 0;
            return false;
        }
    }

    /**
     * Rows changed by the most recent write on this connection.
     */
    public function affectedRows(): int
    {
        return $this->affectedRows;
    }

    public function execute(string $sql, array $params = []): bool|DatabaseResult
    {
        // FAIL LOUD: exec() records the driver error in error() and returns
        // false on failure. execute() must RAISE that instead of swallowing it
        // (parity with the Python master and with fetch()/fetchOne() here).
        if ($this->exec($sql, $params) === false) {
            throw new DatabaseException(
                'SQLite3 execute() failed: ' . ($this->lastError ?? $this->db?->lastErrorMsg() ?? 'unknown error')
            );
        }
        return true;
    }

    public function executeMany(string $sql, array $paramsList = []): int
    {
        // FAIL LOUD: capture the cause on error() AND raise on a prepare/execute
        // failure — do not swallow it into a 0 return (which a caller reads as
        // "nothing happened" while the real cause is hidden). Atomicity for a
        // batch is provided by the facade's transactional batch path.
        $this->ensureOpen();
        $this->lastError = null;
        $totalAffected = 0;

        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            $this->lastError = $this->db->lastErrorMsg();
            throw new DatabaseException('SQLite3 executeMany() failed: ' . ($this->lastError ?: 'prepare failed'));
        }

        try {
            foreach ($paramsList as $params) {
                $stmt->reset();
                $stmt->clear();
                $this->bindParams($stmt, $params);
                $result = $stmt->execute();
                if ($result === false) {
                    $this->lastError = $this->db->lastErrorMsg();
                    throw new DatabaseException('SQLite3 executeMany() failed: ' . ($this->lastError ?: 'unknown error'));
                }
                $totalAffected += $this->db->changes();
                $result->finalize();
            }
        } finally {
            $stmt->close();
        }

        return $totalAffected;
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
            throw new DatabaseException('SQLite3 fetchOne() failed: ' . $this->lastError);
        }
        return $rows[0] ?? null;
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
        // v3.13.14 (#48): honour an attached-database prefix ("attached.table").
        // SQLite's "schema" is an ATTACH alias with its own sqlite_master.
        [$schema, $tbl] = self::splitSchema($table);
        if ($schema !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)) {
            $rows = $this->query(
                "SELECT name FROM {$schema}.sqlite_master WHERE type='table' AND name = :name",
                [':name' => $tbl]
            );
        } else {
            $rows = $this->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name = :name",
                [':name' => $table]
            );
        }
        return count($rows) > 0;
    }

    public function getColumns(string $table): array
    {
        // v3.13.14 (#48): honour an attached-database prefix via
        // PRAGMA <schema>.table_info(<table>).
        [$schema, $tbl] = self::splitSchema($table);
        if ($schema !== null
            && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)
            && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tbl)) {
            $rows = $this->query("PRAGMA {$schema}.table_info('{$tbl}')");
        } else {
            $rows = $this->query("PRAGMA table_info('{$table}')");
        }
        $columns = [];

        foreach ($rows as $row) {
            $columns[] = [
                'name' => $row['name'],
                'type' => $row['type'],
                'nullable' => (int)$row['notnull'] === 0,
                'default' => $row['dflt_value'],
                'primaryKey' => (int)$row['pk'] > 0,
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
        if ($this->db->querySingle('SELECT 1') !== null) {
            try {
                $this->db->exec('COMMIT');
            } catch (\Throwable $e) {
                // Under autocommit-on (default) a write has already committed via
                // SQLite's own autocommit, so an explicit COMMIT finds no active
                // transaction. That is harmless — the data persisted — so swallow
                // it. Catch \Throwable, not \SQLite3Exception: when SQLite raises
                // this as an E_WARNING the test harness (PHPUnit) converts it into
                // a non-SQLite3Exception throwable that a narrow catch would miss
                // (the macOS libsqlite3 stayed silent; the CI one warns). Any OTHER
                // commit error still surfaces loudly.
                if (!str_contains($e->getMessage(), 'no transaction is active')) {
                    throw $e;
                }
            }
        }
    }

    public function rollback(): void
    {
        $this->ensureOpen();
        try {
            $this->db->exec('ROLLBACK');
        } catch (\Throwable $e) {
            // Rollback may fail if no transaction is active — record it, never
            // raise (same \Throwable reasoning as commit(): the PHPUnit-converted
            // warning is not a \SQLite3Exception).
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
        // SQLite has no native boolean — a BooleanField column is INTEGER, so
        // bind PHP booleans as 1/0. Without this they fall to SQLITE3_TEXT and
        // `false` binds as '' (same class of bug as the PG adapter).
        $params = self::normalizeBoolParams($params, nativeBoolean: false);
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
