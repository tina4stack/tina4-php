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
    use CrudSqlTrait;

    use AutocommitTrait;

    use ConnectAliasTrait;

    use SupportsAtomicBatchTrait;
    use ConnectTimeoutTrait;

    // Shared SQL normalisation: the row-cap detectors (scrub + anchor) and the
    // newline-safe COUNT wrapper / clause append used by fetch().
    use SqlNormalizerTrait;

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
        return 'odbc';
    }

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
        $this->autoCommit = $autoCommit ?? ($envAutoCommit !== null ? filter_var($envAutoCommit, FILTER_VALIDATE_BOOLEAN) : true);
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

        // Bound the connect (TINA4_DATABASE_CONNECT_TIMEOUT) with the ODBC login
        // timeout. An ODBC connection string names a DSN or a driver, not
        // necessarily a host and port, so the message names the DSN instead.
        // NOT verified on the lab box, which has no pdo_odbc.
        $timeout = $this->beginConnectTimeout();
        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];
        if ($timeout > 0) {
            $options[\PDO::ATTR_TIMEOUT] = $timeout;
        }

        try {
            $dsn = 'odbc:' . $this->connectionString;
            $this->pdo = new \PDO(
                $dsn,
                $this->username !== '' ? $this->username : null,
                $this->password !== '' ? $this->password : null,
                $options
            );

            // Disable PDO-level autocommit so we control transactions explicitly
            if (!$this->autoCommit) {
                $this->pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, 0);
            }
        } catch (\PDOException $e) {
            $this->throwIfConnectTimedOut('ODBC', $this->connectionString, $e);
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

        // FAIL LOUD (parity with PostgresAdapter + the Python master): query()
        // records the driver error on error() and returns []. fetch() must RAISE
        // that instead of swallowing it into an empty result set - the old
        // catch-return-empty hid a real query error as "no rows". query() clears
        // lastError on entry, so a non-null lastError after the call means the
        // statement failed.
        // The closing paren goes on its OWN LINE (wrapCountSubquery): inline, a
        // trailing `-- comment` in the user SQL comments it out and the probe
        // fails on otherwise-valid SQL.
        $countSql = self::wrapCountSubquery($sql, '_tina4_count');
        $countRow = $this->query($countSql, $params);
        if ($this->lastError !== null) {
            throw new DatabaseException('ODBC fetch() failed: ' . $this->lastError);
        }
        // COUNT(*) column may be uppercased by some ODBC drivers
        $total = (int) ($countRow[0]['total'] ?? $countRow[0]['TOTAL'] ?? 0);

        // Pagination — skip if the statement already ENDS with its own row cap,
        // or if $limit <= 0 (v3.13.12: fetchAll's "give me all rows").
        //
        // This used to be a bare `stripos($sql, 'LIMIT') !== false` over a
        // `--`-stripped copy. MEASURED: a column named `rate_limit`, or a
        // literal `WHERE label != 'LIMIT'`, or a LIMIT inside a SUBQUERY all read
        // as "the caller supplied their own cap", so the cap was dropped and the
        // WHOLE TABLE came back — the production incident the cap exists to
        // prevent. The shared detectors scrub literals and comments and anchor to
        // the END of the statement.
        if ($limit <= 0 || self::hasTrailingLimit($sql) || self::hasTrailingFetch($sql)) {
            $pagedSql = $sql;
        } else {
            // Try OFFSET/FETCH NEXT (SQL standard, supported by most ODBC
            // targets). NEW LINE: appended inline the cap lands inside a trailing
            // `-- comment` and is silently swallowed.
            $pagedSql = self::appendSqlClause(
                $sql,
                "OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY"
            );
        }

        $data = $this->query($pagedSql, $params);
        if ($this->lastError !== null) {
            throw new DatabaseException('ODBC fetch() failed: ' . $this->lastError);
        }

        return [
            'data'   => $data,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ];
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $this->ensureOpen();
        // FAIL LOUD (parity with PostgresAdapter + the Python master): query()
        // clears lastError on entry and records the driver error on failure
        // (returning []), so a non-null lastError after the call means the
        // statement failed — RAISE it instead of returning null (which a caller
        // reads as "no row").
        $rows = $this->query($sql, $params);
        if ($this->lastError !== null) {
            throw new DatabaseException('ODBC fetchOne() failed: ' . $this->lastError);
        }
        return $rows[0] ?? null;
    }

    public function execute(string $sql, array $params = []): bool|DatabaseResult
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            $stmt = $this->pdo->prepare($sql);
            $this->bindParams($stmt, $params);
            if ($stmt->execute() === false) {
                // FAIL LOUD: capture the cause on error() AND raise.
                $info = $stmt->errorInfo();
                $this->lastError = $info[2] ?? 'Execute failed';
                throw new DatabaseException('ODBC execute() failed: ' . ($this->lastError ?: 'unknown error'));
            }
            return true;
        } catch (DatabaseException $e) {
            throw $e;
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            throw $e;
        }
    }

    public function executeMany(string $sql, array $paramsList = []): int
    {
        // FAIL LOUD: capture the cause on error() AND raise on a driver failure —
        // do not swallow it into a 0 return. Atomicity for a batch is provided by
        // the facade's transactional batch path.
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
            throw new DatabaseException('ODBC executeMany() failed: ' . ($this->lastError ?: 'unknown error'));
        }
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

            // Real PK, from INFORMATION_SCHEMA - not the old `false` stub.
            // Feature 4's filterless-write guard reads primaryKey, so without
            // this a PK-keyed update(table, data) on ODBC could not introspect
            // the key and threw.
            $pkColumns = $this->primaryKeyColumns($table);
            return array_map(fn($row) => [
                'name'     => $row['COLUMN_NAME'] ?? $row['column_name'] ?? '',
                'type'     => $row['DATA_TYPE']   ?? $row['data_type']   ?? '',
                'nullable' => strtoupper($row['IS_NULLABLE'] ?? $row['is_nullable'] ?? 'YES') === 'YES',
                'default'  => $row['COLUMN_DEFAULT'] ?? $row['column_default'] ?? null,
                'primaryKey' => in_array(
                    strtolower((string) ($row['COLUMN_NAME'] ?? $row['column_name'] ?? '')),
                    $pkColumns,
                    true
                ),
            ], $rows);
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    /**
     * The table's primary-key columns, lower-cased for case-insensitive matching.
     *
     * PDO_ODBC exposes no SQLPrimaryKeys catalog call, so this reads the portable
     * INFORMATION_SCHEMA constraint views (PostgreSQL, MySQL and MSSQL all
     * implement them). Empty on any target that does not report a primary key -
     * the write-guard then requires an explicit filter.
     *
     * @param string $table Table name
     * @return string[] Lower-cased primary-key column names
     */
    private function primaryKeyColumns(string $table): array
    {
        try {
            $rows = $this->query(
                'SELECT kcu.COLUMN_NAME AS column_name '
                . 'FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc '
                . 'JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu '
                . '  ON kcu.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
                . ' AND kcu.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA '
                . 'WHERE tc.CONSTRAINT_TYPE = ? AND tc.TABLE_NAME = ? '
                . 'ORDER BY kcu.ORDINAL_POSITION',
                ['PRIMARY KEY', $table]
            );
            return array_map(
                static fn($r) => strtolower((string) ($r['column_name'] ?? $r['COLUMN_NAME'] ?? '')),
                $rows
            );
        } catch (\Exception) {
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
