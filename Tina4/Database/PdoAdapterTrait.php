<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * Shared PDO implementation for the SILENT native-extension fallbacks
 * (PdoSqliteAdapter / PdoPostgresAdapter / PdoFirebirdAdapter).
 *
 * tina4-php prefers native DB extensions (ext-sqlite3, ext-pgsql,
 * ext-interbase). When the native extension is ABSENT, Database::create()
 * silently falls back to the matching PDO driver via one of the sibling
 * adapters — no exception, no warning, no config flag, no connection-string
 * change. The developer gets a working DB either way.
 *
 * The whole point of the fallback is that a caller CANNOT tell which driver
 * served a result: this trait forces PDO into native-type mode
 * (ATTR_STRINGIFY_FETCHES=false, ATTR_EMULATE_PREPARES=false), coerces cells
 * to the SAME PHP types the native adapter returns (int/float/bool/raw bytes),
 * returns BLOB columns as raw byte strings, produces the same getLastId() /
 * RETURNING behaviour, and preserves the FAIL-LOUD execute()/fetch() contract
 * (RAISE on error, cause on error(), never return false).
 *
 * DRY: the generic query/fetch/execute/CRUD/transaction/coerce/last-id logic
 * lives here; each sibling supplies a small set of engine hooks:
 *   - {@see buildDsn()}                 PDO DSN + credentials + options
 *   - {@see onConnect()}                post-connect setup (PRAGMAs, etc.)
 *   - {@see normalizeParams()}          bound-param normalisation (booleans)
 *   - {@see coerceRows()}               native-type coercion of fetched rows
 *   - {@see paginate()}                 engine pagination (LIMIT/OFFSET vs ROWS)
 *   - {@see wrapCountSql()}             fetch() COUNT(*) probe wrapper
 *   - {@see insertReturningClause()}    '' or ' RETURNING *'
 *   - {@see normalizeReturnedId()}      RETURNING id shape (int/string/trim)
 *   - {@see captureLastIdAfterWrite()}  non-RETURNING last-id capture
 *   - tableExists()/getColumns()/getTables()  engine schema introspection
 */
trait PdoAdapterTrait
{
    use ConnectTimeoutTrait;

    use ConnectAliasTrait;

    use SupportsAtomicBatchTrait;
    use ExecuteManyLoopTrait;
    use FetchOneTrait;

    private ?\PDO $pdo = null;
    private ?string $lastError = null;
    private bool $autoCommit = true;
    private int|string $lastId = 0;

    /** @var int Rows affected by the most recent write (PDOStatement::rowCount()). */
    private int $affectedRows = 0;

    /** Human-readable engine name for error messages (e.g. "SQLite (PDO)"). */
    abstract protected function engineLabel(): string;

    /**
     * Build the PDO DSN and credentials for this engine.
     *
     * @return array{0: string, 1: string, 2: string, 3: array<int, mixed>}
     *   [dsn, username, password, driverOptions]
     */
    abstract protected function buildDsn(): array;

    /**
     * Resolve the effective auto-commit flag (constructor arg or TINA4_AUTOCOMMIT).
     * Called from each sibling constructor.
     */
    protected function resolveAutoCommit(?bool $autoCommit): bool
    {
        $envAutoCommit = \Tina4\DotEnv::getEnv('TINA4_AUTOCOMMIT');
        return $autoCommit ?? ($envAutoCommit !== null
            ? filter_var($envAutoCommit, FILTER_VALIDATE_BOOLEAN)
            : true);
    }

    public function open(): void
    {
        if ($this->pdo !== null) {
            return;
        }

        [$dsn, $username, $password, $options] = $this->buildDsn();

        // Force native-type mode + fail-loud so PDO is indistinguishable from
        // the native driver: real ints/floats/bools out (no stringified
        // fetches), real prepared statements (no emulation), exceptions on
        // error (feeds the FAIL-LOUD execute()/fetch() contract).
        $options += [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];

        // Bound the connect (TINA4_DATABASE_CONNECT_TIMEOUT) before handing the
        // DSN to PDO. How the bound is applied is per-engine — see
        // {@see armConnectTimeout()}.
        $timeout = $this->beginConnectTimeout();
        if ($timeout > 0) {
            $options = $this->armConnectTimeout($timeout, $options);
        }

        try {
            $this->pdo = new \PDO($dsn, $username, $password, $options);
        } catch (\PDOException $e) {
            $this->throwIfConnectTimedOut($this->engineLabel(), $this->connectTarget(), $e);
            $this->lastError = $e->getMessage();
            throw new \RuntimeException(
                $this->engineLabel() . ': Failed to connect: ' . $e->getMessage()
            );
        }

        $this->onConnect();
    }

    /** Post-connect hook (e.g. SQLite busy_timeout / foreign_keys). Default no-op. */
    protected function onConnect(): void
    {
    }

    /**
     * Apply the connect bound for this engine. Called only when a bound is in
     * force, so an implementation never has to test for the disabled case.
     *
     * The default is a no-op, which is right for SQLite: it opens a local file
     * and has no network to block on.
     *
     * @param int $timeout Seconds resolved from TINA4_DATABASE_CONNECT_TIMEOUT.
     * @param array<int, mixed> $options PDO driver options built so far.
     * @return array<int, mixed> The driver options to construct PDO with.
     */
    protected function armConnectTimeout(int $timeout, array $options): array
    {
        return $options;
    }

    /**
     * What this connection is reaching for, as "host:port", for the
     * connect-timeout message. Empty when there is no network target.
     */
    protected function connectTarget(): string
    {
        return '';
    }

    public function close(): void
    {
        $this->pdo = null;
    }

    private function ensureOpen(): void
    {
        if ($this->pdo === null) {
            $this->open();
        }
    }

    /**
     * Run a query and return coerced associative rows.
     *
     * Mirrors the native adapters' query() contract: on a driver error it
     * RECORDS the cause on error() and returns [] (it does NOT raise) — the
     * higher-level fetch()/fetchOne()/execute() are the ones that raise. A
     * non-SELECT (columnCount() === 0) returns [].
     */
    public function query(string $sql, array $params = []): array
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            if (!empty($params)) {
                // PDO speaks ? positionally; the ORM/QueryBuilder may emit
                // :named — translate to ? and reorder params, exactly as the
                // native adapters do.
                [$sql, $params] = \Tina4\SQLTranslator::namedToPositional($sql, $params);
            }
            $params = $this->normalizeParams(array_values($params));

            $stmt = $this->pdo->prepare($sql);
            $this->bindValues($stmt, $params);
            $stmt->execute();

            $columnCount = $stmt->columnCount();
            if ($columnCount === 0) {
                // Non-result statement (DDL / DML without RETURNING).
                return [];
            }

            // Capture column metadata BEFORE fetching so per-engine coercion
            // (PG native_type casters, Firebird BLOB/CHAR handling) never
            // depends on the cursor still being open.
            $meta = [];
            for ($i = 0; $i < $columnCount; $i++) {
                $meta[] = $stmt->getColumnMeta($i) ?: [];
            }

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return $this->coerceRows($rows, $meta);
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function fetch(string $sql, array $params = [], int $limit = 100, int $offset = 0): array
    {
        $this->ensureOpen();
        $this->lastError = null;
        $sql = self::stripTrailingSemicolons($sql);

        // Count probe is BEST-EFFORT: it runs on its own statement and its
        // failure only defaults total to 0 — it must never mask a real
        // main-query failure (parity with the MySQL/Firebird native fetch()).
        $total = 0;
        $countResult = $this->query($this->wrapCountSql($sql), $params);
        if ($this->lastError === null) {
            $total = (int) ($countResult[0]['total'] ?? $countResult[0]['TOTAL'] ?? 0);
        }

        // MAIN query FAILS LOUD (DB-contract A): a bad statement RAISES instead
        // of being swallowed into an empty result set.
        $this->lastError = null;
        $pagedSql = ($limit <= 0 || self::hasTrailingLimit($sql))
            ? $sql
            : $this->paginate($sql, $limit, $offset);
        $data = $this->query($pagedSql, $params);
        if ($this->lastError !== null) {
            throw new DatabaseException($this->engineLabel() . ' fetch() failed: ' . $this->lastError);
        }

        return [
            'data' => $data,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Per-engine DDL translation applied to a write statement before it reaches
     * the driver. Default: identity — SQLite and PostgreSQL accept the
     * SQLite-canonical DDL the generator / ORM::createTable() emit.
     * {@see PdoFirebirdAdapter::translateDdl()} overrides this to strip
     * AUTOINCREMENT, strip IF NOT EXISTS, and map TEXT -> BLOB SUB_TYPE TEXT /
     * REAL -> DOUBLE PRECISION. DDL-only by construction (ddlTypes is gated to
     * CREATE/ALTER TABLE, autoIncrementSyntax only touches the AUTOINCREMENT
     * keyword), so an ordinary DML statement is returned unchanged.
     */
    protected function translateDdl(string $sql): string
    {
        return $sql;
    }

    public function execute(string $sql, array $params = []): bool|DatabaseResult
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            // Per-engine DDL translation on the way in (identity by default;
            // PdoFirebirdAdapter maps SQLite-canonical DDL to Firebird dialect)
            // so a generated migration or ORM::createTable() statement applies.
            $execSql = $this->translateDdl($sql);
            if (!empty($params)) {
                [$execSql, $params] = \Tina4\SQLTranslator::namedToPositional($execSql, $params);
            }
            $params = $this->normalizeParams(array_values($params));

            $stmt = $this->pdo->prepare($execSql);
            $this->bindValues($stmt, $params);
            $stmt->execute();
            // rowCount() is the canonical PDO affected-row count for a write.
            $this->affectedRows = $stmt->rowCount();

            if ($this->hasReturning($execSql)) {
                // RETURNING row carries the new id (PostgreSQL/Firebird).
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (is_array($row) && $row !== []) {
                    $first = reset($row);
                    if ($first !== false) {
                        $this->lastId = $this->normalizeReturnedId($first);
                    }
                }
            } else {
                $this->captureLastIdAfterWrite($execSql);
            }

            return true;
        } catch (\PDOException $e) {
            // FAIL LOUD: capture the cause on error() AND raise — never swallow.
            $this->affectedRows = 0;
            $this->lastError = $e->getMessage();
            throw new DatabaseException(
                $this->engineLabel() . ' execute() failed: ' . ($this->lastError ?: 'unknown error'),
                0,
                $e
            );
        }
    }

    /**
     * Rows affected by the most recent write (PDOStatement::rowCount()).
     */
    public function affectedRows(): int
    {
        return $this->affectedRows;
    }

    public function insert(string $table, array $data): bool
    {
        // List of rows -> batch (delegates to executeMany).
        if (isset($data[0]) && is_array($data[0])) {
            $keys = array_keys($data[0]);
            $cols = implode(', ', $keys);
            $placeholders = implode(', ', array_fill(0, count($keys), '?'));
            $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";
            $paramsList = array_map(static fn($row) => array_values($row), $data);
            return $this->executeMany($sql, $paramsList) > 0;
        }

        $cols = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $returning = $this->insertReturningClause();
        $values = array_values($data);

        $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders}){$returning}";
        try {
            return (bool) $this->execute($sql, $values);
        } catch (\Throwable $e) {
            if ($returning === '') {
                throw $e;
            }
            // Some statements/engines reject RETURNING — mirror the native
            // Firebird adapter's fallback to a plain INSERT.
            $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";
            return (bool) $this->execute($sql, $values);
        }
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
        return (bool) $this->execute($sql, $params);
    }

    public function delete(string $table, string|array $filter = '', array $whereParams = []): bool
    {
        // List of assoc arrays — delete each row.
        if (is_array($filter) && isset($filter[0]) && is_array($filter[0])) {
            foreach ($filter as $row) {
                if (!$this->delete($table, $row)) {
                    return false;
                }
            }
            return true;
        }
        // Assoc array — build WHERE from keys.
        if (is_array($filter)) {
            $parts = [];
            $params = [];
            foreach ($filter as $col => $val) {
                $parts[] = "{$col} = ?";
                $params[] = $val;
            }
            return $this->delete($table, implode(' AND ', $parts), $params);
        }
        // String filter.
        $sql = "DELETE FROM {$table}";
        if ($filter !== '') {
            $sql .= " WHERE {$filter}";
        }
        return (bool) $this->execute($sql, $whereParams);
    }

    public function lastInsertId(): int|string
    {
        return $this->lastId;
    }

    /**
     * Whether startTransaction()/commit()/rollback() must toggle
     * PDO::ATTR_AUTOCOMMIT around an explicit transaction.
     *
     * Default false — sqlite/pgsql report inTransaction() honestly, so the
     * beginTransaction() guard below is enough. ONLY pdo_firebird needs this:
     * it reports inTransaction()===true even in autocommit mode, so the guard
     * would skip beginTransaction() and no real rollback boundary would ever be
     * established (an INSERT auto-commits immediately and rollback() cannot undo
     * it). Turning autocommit OFF clears that phantom state so a genuine
     * transaction begins; it is restored to ON on commit()/rollback() so
     * standalone reads/writes keep working. Overridden in PdoFirebirdAdapter.
     */
    protected function transactionsNeedAutocommitToggle(): bool
    {
        return false;
    }

    public function startTransaction(): void
    {
        $this->ensureOpen();
        if ($this->transactionsNeedAutocommitToggle()) {
            $this->pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, false);
        }
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        $this->ensureOpen();
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
        if ($this->transactionsNeedAutocommitToggle()) {
            $this->pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, true);
        }
    }

    public function rollback(): void
    {
        $this->ensureOpen();
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollback();
            }
        } catch (\Throwable $e) {
            // Rollback may fail if no transaction is active — record, never raise
            // (parity with the native adapters).
            $this->lastError = $e->getMessage();
        } finally {
            if ($this->transactionsNeedAutocommitToggle()) {
                $this->pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, true);
            }
        }
    }

    public function error(): ?string
    {
        return $this->lastError;
    }

    /** Get the underlying PDO connection (advanced usage / tests). */
    public function getConnection(): ?\PDO
    {
        return $this->pdo;
    }

    // ── Engine hooks (overridable defaults) ───────────────────────────

    /**
     * Coerce fetched rows to native PHP types matching the native adapter.
     * Default is identity (pdo_sqlite already returns native types).
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $meta Per-column getColumnMeta().
     * @return array<int, array<string, mixed>>
     */
    protected function coerceRows(array $rows, array $meta): array
    {
        return $rows;
    }

    /** Normalise bound params (booleans) for this engine. Default: 1/0. */
    protected function normalizeParams(array $params): array
    {
        return self::normalizeBoolParams($params, nativeBoolean: false);
    }

    /**
     * Append engine pagination to a SELECT. Default: LIMIT/OFFSET.
     *
     * The clause lands on a NEW LINE — appended inline it would sit inside a
     * trailing `-- comment` in the caller's SQL and be silently swallowed,
     * returning the whole table uncapped (see appendSqlClause).
     */
    protected function paginate(string $sql, int $limit, int $offset): string
    {
        return self::appendSqlClause($sql, "LIMIT {$limit} OFFSET {$offset}");
    }

    /**
     * Wrap a SELECT in a COUNT(*) probe for fetch(). Default: no subquery alias.
     *
     * The closing paren lands on its OWN LINE — inline, a trailing `-- comment`
     * comments it out and the probe fails on otherwise-valid SQL.
     */
    protected function wrapCountSql(string $sql): string
    {
        return self::wrapCountSubquery($sql);
    }

    /** RETURNING clause appended by insert(). Default: none. */
    protected function insertReturningClause(): string
    {
        return '';
    }

    /** Shape a RETURNING id to match the native adapter. Default: unchanged. */
    protected function normalizeReturnedId(mixed $value): int|string
    {
        return is_string($value) ? $value : (is_int($value) ? $value : (string) $value);
    }

    /** Capture the last-insert id for a non-RETURNING write. Default: no-op. */
    protected function captureLastIdAfterWrite(string $sql): void
    {
    }

    // ── Shared SQL helpers ────────────────────────────────────────────

    /**
     * Bind positional values (1-based) with a PDO type derived from the PHP
     * type. Booleans are already normalised to 1/0 or 't'/'f' by
     * normalizeParams(); binary strings bind as PARAM_STR (PDO preserves raw
     * bytes, including NULs).
     */
    protected function bindValues(\PDOStatement $stmt, array $params): void
    {
        $index = 1;
        foreach (array_values($params) as $value) {
            $type = match (true) {
                is_int($value) => \PDO::PARAM_INT,
                is_null($value) => \PDO::PARAM_NULL,
                is_bool($value) => \PDO::PARAM_BOOL,
                default => \PDO::PARAM_STR,
            };
            $stmt->bindValue($index++, $value, $type);
        }
    }

    protected function isWriteQuery(string $sql): bool
    {
        $firstWord = strtoupper(strtok(ltrim($sql), " \t\n\r"));
        return in_array($firstWord, ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'], true);
    }

    protected function isInsert(string $sql): bool
    {
        return (bool) preg_match('/^\s*INSERT\b/i', $sql);
    }

    protected function hasReturning(string $sql): bool
    {
        return (bool) preg_match('/\bRETURNING\b/i', $sql);
    }
}
