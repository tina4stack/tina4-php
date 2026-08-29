<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
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
    use CrudSqlTrait;

    use AutocommitTrait;

    use ConnectAliasTrait;

    use SupportsAtomicBatchTrait;
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
        return 'firebird';
    }

    use SqlNormalizerTrait;

    /** @var resource|null */
    private mixed $db = null;
    /**
     * @var array<string,int> Live-holder count per shared native link.
     *
     * ext-interbase returns the SAME physical link resource for connections
     * opened with identical (fn, path, user, pass, charset) arguments, so two
     * Tina4 adapters that believe they hold separate connections are really
     * holding one. That link tolerates exactly ONE ibase_close(): issuing one
     * per Tina4 holder over-releases it and takes the process down with a
     * segmentation fault. We therefore reference-count opens per connection
     * signature and ibase_close() only when the final holder releases it
     * (php #132 follow-up, surfaced by the native ext-interbase CI leg).
     *
     * The count only means anything if EVERY holder is subtracted again, which
     * is why {@see __destruct()} exists. Without it the count was incremented
     * by open() and decremented only by an EXPLICIT close(), so the first
     * adapter that was garbage collected instead of closed — the normal end of
     * a request, a job or a test — pinned it above zero for the life of the
     * process. From that point the final ibase_close() could never fire for
     * that signature, so the driver was never told the link had been released,
     * every later adapter piled onto the one ageing native link, and once that
     * link went stale every connection in the process failed with "invalid
     * database handle (no active connection)". MEASURED on Ubuntu 24.04.4 LTS
     * x86_64 / PHP 8.3.6 / ext-interbase against Firebird 5.0.4: one abandoned
     * holder was enough to take twelve unrelated live-Firebird tests down, and
     * a trace of the run showed the same native link handed out for the whole
     * suite with the count climbing 1..7 and not one release reaching zero.
     *
     * Closing unconditionally instead is NOT the fix: one ibase_close() per
     * holder over-releases a shared link and SEGFAULTS the process (measured,
     * same platform, exit 139 part-way through the suite). Exactly one close
     * per physical link is the contract — which is what this count is for.
     */
    private static array $linkRefs = [];
    /** @var string|null This instance's key into self::$linkRefs (null once released). */
    private ?string $linkSig = null;
    /** @var int Rows affected by the most recent write (ibase_affected_rows, best-effort). */
    private int $affectedRows = 0;
    /** @var resource|null Active EXPLICIT transaction handle (startTransaction) */
    private mixed $transaction = null;
    /**
     * @var resource|null Long-lived READ COMMITTED transaction used for every
     * statement outside an explicit startTransaction() (auto-commit reads AND
     * writes). Firebird's implicit per-link default transaction is SNAPSHOT
     * (concurrency) isolation and, once opened, keeps a FROZEN view — a reused /
     * long-lived connection then never sees another transaction's commits or new
     * DDL, so reads go stale (a just-committed DELETE still "sees" the row, a
     * freshly created table reads back "unknown"). A READ COMMITTED (rec_version)
     * transaction re-reads the latest committed data on every statement, so the
     * connection always sees committed state. Created lazily; fully committed and
     * dropped after each auto-commit statement (a fresh one opens on the next), so
     * it never holds a lock between statements. #132.
     */
    private mixed $defaultTx = null;
    private ?string $lastError = null;
    private bool $autoCommit;
    private int|string $lastId = 0;

    /**
     * @var string Resolved connection charset (php #160). Precedence: URL
     * ?charset= > explicit charset arg > TINA4_DATABASE_CHARSET env > UTF8.
     */
    private string $charset;

    /** @var string The ibase/fbird function prefix */
    private string $fn;

    /**
     * @param string $connectionString URL: "firebird://user:pass@host:port/path/to/db.fdb"
     *                                  or path: "/path/to/database.fdb"
     * @param string $username Username (default: SYSDBA)
     * @param string $password Password (default: masterkey)
     * @param string|null $charset Connection charset. When null (the default),
     *                             it is resolved from the URL ?charset= query,
     *                             then TINA4_DATABASE_CHARSET, then UTF8 (php #160).
     * @param bool|null $autoCommit Whether to auto-commit
     */
    public function __construct(
        private readonly string $connectionString,
        private readonly string $username = 'SYSDBA',
        private readonly string $password = 'masterkey',
        ?string $charset = null,
        ?bool $autoCommit = null,
    ) {
        // php #160: resolve the connection charset so a legacy NONE database is
        // not force-connected as UTF8 (which double-encodes UTF-8 bytes).
        $this->charset = self::resolveCharset($this->connectionString, $charset);

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
        $connectFn = $this->fn . 'connect';

        $dbPath = $params['database'];
        if ($params['host'] !== '') {
            $dbPath = $params['host'];
            if ($params['port'] > 0 && $params['port'] !== 3050) {
                $dbPath .= '/' . $params['port'];
            }
            $dbPath .= ':' . $params['database'];
        }

        // Bound the connect (TINA4_DATABASE_CONNECT_TIMEOUT) by reaching the
        // port ourselves first. ext-interbase exposes no connect timeout — it
        // builds its own DPB and never sets isc_dpb_connect_timeout — and
        // libfbclient retries through EINTR, so pcntl_alarm cannot interrupt
        // ibase_connect() either (both MEASURED on Ubuntu 24.04.4 / PHP 8.3.6).
        // See ConnectTimeoutTrait::preflightConnectTimeout() for the residual
        // case this does not cover.
        $this->beginConnectTimeout();
        $this->preflightConnectTimeout('FirebirdAdapter', (string) $params['host'], (int) $params['port']);

        $conn = @$connectFn($dbPath, $params['username'], $params['password'], $this->charset);
        if ($conn === false) {
            $errFn = $this->fn . 'errmsg';
            $this->lastError = $errFn();
            // libfbclient has a connect ceiling of its own (firebird.conf
            // ConnectionTimeout, default 180s — MEASURED here at 180.10s). When
            // the connect blew past the configured bound, say so in the
            // framework's words rather than leaving the operator with
            // "connection rejected by remote interface" and no variable to tune.
            $this->throwIfConnectTimedOut(
                'FirebirdAdapter',
                $params['host'] . ':' . $params['port'],
                (string) $this->lastError
            );
            throw new \RuntimeException("FirebirdAdapter: Failed to connect: {$this->lastError}");
        }

        $this->db = $conn;

        // Register this instance as a holder of the (possibly shared) link.
        $sig = implode("\x00", [
            $this->fn,
            $dbPath,
            (string) $params['username'],
            (string) $params['password'],
            (string) $this->charset,
        ]);
        $this->linkSig = $sig;
        self::$linkRefs[$sig] = (self::$linkRefs[$sig] ?? 0) + 1;
    }

    /**
     * Release this instance's hold on the native link, closing the physical
     * connection only when no sibling adapter still shares it.
     *
     * ext-interbase de-duplicates identical-argument connections to ONE link
     * resource, and ibase_close() tears that link down for every holder — so a
     * blind close() would break another Tina4 connection to the same database
     * (php #132 follow-up). Decrementing the per-signature refcount and closing
     * only at zero makes close() safe in that shared case.
     */
    private function releaseSharedLink(): void
    {
        $sig = $this->linkSig;
        $this->linkSig = null;

        if ($sig !== null && isset(self::$linkRefs[$sig])) {
            self::$linkRefs[$sig]--;
            if (self::$linkRefs[$sig] > 0) {
                return; // a sibling still holds the physical link — leave it open
            }
            unset(self::$linkRefs[$sig]);
        }

        if ($this->db !== null) {
            $closeFn = $this->fn . 'close';
            @$closeFn($this->db);
        }
    }

    public function close(): void
    {
        if ($this->db !== null) {
            if ($this->transaction !== null) {
                // An explicit transaction that reached close() without a commit
                // is an UNFINISHED unit of work: roll it back rather than
                // abandoning the handle. Abandoning it left the transaction open
                // server-side for the life of the process, holding every lock it
                // had taken. Rollback (not commit) is what every mainstream
                // database API does on close.
                $rollbackFn = $this->fn . 'rollback';
                @$rollbackFn($this->transaction);
                $this->transaction = null;
            }
            if ($this->defaultTx !== null) {
                $commitFn = $this->fn . 'commit';
                @$commitFn($this->defaultTx);
                $this->defaultTx = null;
            }
            $this->releaseSharedLink();
            $this->db = null;
        }
    }

    /**
     * Release this adapter's claim on the shared native link when it is
     * garbage collected without an explicit close().
     *
     * A holder that simply goes out of scope is the ordinary end of a request,
     * a queue job or a test, and it is still one holder fewer. Without this the
     * per-signature count in {@see $linkRefs} could only ever go up, the final
     * ibase_close() was suppressed for the rest of the process, and every
     * Firebird connection in it eventually failed with "invalid database handle
     * (no active connection)".
     *
     * close() is already idempotent and guards on a live handle, so an adapter
     * that was closed properly destructs to a no-op.
     */
    public function __destruct()
    {
        $this->close();
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
            $blobInfoFn = $this->fn . 'blob_info';
            while ($row = @$fetchFn($result, IBASE_TEXT)) {
                // Trim string values (Firebird pads CHAR fields)
                // and auto-decode BLOB columns
                $cleaned = [];
                foreach ($row as $rawKey => $value) {
                    $key = self::columnName($rawKey);
                    if (is_resource($value)) {
                        // BLOB resource handle — read into bytes
                        $blobData = '';
                        while ($chunk = fread($value, 8192)) {
                            $blobData .= $chunk;
                        }
                        fclose($value);
                        $cleaned[$key] = $blobData;
                    } elseif (is_string($value)) {
                        $cleaned[$key] = rtrim($value);
                    } else {
                        $cleaned[$key] = $value;
                    }
                }
                $rows[] = $cleaned;
            }

            $freeFn = $this->fn . 'free_result';
            @$freeFn($result);

            // Auto-commit (commit-RETAINING) after EVERY statement in the default
            // path, reads included. A read on the long-lived READ COMMITTED
            // transaction otherwise keeps a lock on the touched table until the
            // next commit, which then deadlocks a later DROP/RECREATE of it
            // ("update conflicts with concurrent update"). commit_ret releases the
            // per-statement locks while keeping the transaction usable + fresh. #132
            if ($this->autoCommit && $this->transaction === null) {
                $this->commitDefault();
            }

            return $rows;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    /**
     * Firebird's stored column name, folded back only when it was folded.
     *
     * Firebird's identifier folding is ASYMMETRIC. An unquoted `AS x` is stored
     * UPPERCASE, so the driver hands back "X" where every other engine Tina4
     * supports gives "x" - PostgreSQL folds to lower, and MySQL, SQLite and
     * MSSQL preserve what you wrote. Portable code reading $row['x'] therefore
     * broke on Firebird alone, and the tests in this repo papered over it by
     * reading `$row['X'] ?? $row['x']`.
     *
     * A QUOTED `AS "MyCol"` is stored exactly as written, and that case is
     * deliberate - the caller asked for it - so it is left alone. Lowercasing
     * unconditionally (which is what Python used to do) makes a mixed-case key
     * unreachable and is the same asymmetric-folding trap that made
     * tableExists() miss quoted tables.
     *
     * So: fold back only a name carrying no lowercase letter, the only thing
     * unquoted folding can produce. A quoted ALL-CAPS name is genuinely
     * indistinguishable from a folded one and is lowercased too; that ambiguity
     * is Firebird's, and quoting an all-caps name is the one spelling this
     * cannot round-trip.
     *
     * @param string $raw the column name exactly as the driver reported it
     * @return string the name callers see on the row
     */
    public static function columnName(string $raw): string
    {
        $name = trim($raw);
        return $name === strtoupper($name) ? strtolower($name) : $name;
    }

    public function fetch(string $sql, array $params = [], int $limit = 100, int $offset = 0): array
    {
        $this->ensureOpen();
        $this->lastError = null;
        // v3.13.12: strip trailing `;` before COUNT(*) wrap + ROWS pagination.
        $sql = self::stripTrailingSemicolons($sql);

        // v3.13.37 (DB-contract A): the MAIN paginated query must FAIL LOUD — a
        // bad statement RAISES instead of being swallowed into an empty result
        // set (parity with execute(), fetchOne() and the Python master). The
        // COUNT probe is best-effort and runs on a SEPARATE statement (its own
        // query() call), so a probe failure only defaults total to 0 and can
        // never mask a real main-query failure.
        // The closing paren goes on its OWN LINE (wrapCountSubquery): inline, a
        // trailing `-- comment` in the user SQL comments the `)` out and the
        // probe dies, silently reporting total = 0 next to real records.
        $total = 0;
        $countSql = self::wrapCountSubquery($sql);
        $countResult = $this->query($countSql, $params);
        if ($this->lastError === null) {
            $total = (int)($countResult[0]['TOTAL'] ?? $countResult[0]['total'] ?? 0);
        }

        // Firebird pagination: ROWS X TO Y (1-based).
        // v3.13.12: $limit <= 0 means "no pagination" (fetchAll's
        // default — give me ALL rows).
        $this->lastError = null;
        if ($limit <= 0) {
            $pagedSql = $sql;
        } else {
            $startRow = $offset + 1;
            $endRow = $offset + $limit;
            // NEW LINE (appendSqlClause): appended inline the cap lands inside
            // a trailing `-- comment` and is swallowed — the whole table then
            // comes back uncapped.
            $pagedSql = self::appendSqlClause($sql, "ROWS {$startRow} TO {$endRow}");
        }
        $data = $this->query($pagedSql, $params);
        // query() clears lastError on entry and records the driver error on
        // failure (returning []), so a non-null lastError here means the MAIN
        // query failed — RAISE it (FAILS LOUD).
        if ($this->lastError !== null) {
            throw new DatabaseException('Firebird fetch() failed: ' . $this->lastError);
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
            throw new DatabaseException('Firebird fetchOne() failed: ' . $this->lastError);
        }
        return $rows[0] ?? null;
    }

    public function execute(string $sql, array $params = []): bool|DatabaseResult
    {
        $this->ensureOpen();
        $this->lastError = null;

        // Portable SQLite-canonical DDL -> Firebird dialect on the way in, so a
        // generated migration OR an ORM::createTable() statement applies here
        // instead of failing: strip AUTOINCREMENT (Firebird uses generators),
        // strip IF NOT EXISTS, and map TEXT -> BLOB SUB_TYPE TEXT / REAL ->
        // DOUBLE PRECISION. Both transforms are DDL-only (ddlTypes is gated to
        // CREATE/ALTER TABLE; autoIncrementSyntax only touches the AUTOINCREMENT
        // keyword), so a SELECT/INSERT/UPDATE/DELETE is untouched. Mirrors the
        // Python master's firebird.py _translate_sql.
        $sql = \Tina4\SQLTranslator::autoIncrementSyntax($sql, 'firebird');
        $sql = \Tina4\SQLTranslator::ddlTypes($sql, 'firebird');

        try {
            $result = $this->executeInternal($sql, $params);
            if ($result === false) {
                // FAIL LOUD: capture the cause on error() AND raise.
                $this->affectedRows = 0;
                throw new DatabaseException('Firebird execute() failed: ' . ($this->lastError ?? 'unknown error'));
            }

            // Real affected-row count (FB-AFFECTED-FAB). ibase_affected_rows()
            // returns the count for the last query IN THE GIVEN TRANSACTION, so it
            // MUST be read from the SAME context the statement ran on -- the
            // explicit transaction, else the long-lived READ COMMITTED default
            // transaction ($this->defaultTx) that doExecute() ran it in. Reading
            // it off $this->db (the LINK's implicit transaction, which did
            // nothing) returned 0 -- MEASURED on the lab; the old "not verified
            // against live Firebird" note was the tell. Captured before the
            // auto-commit below tears $this->defaultTx down.
            $affectedFn = $this->fn . 'affected_rows';
            if (function_exists($affectedFn)) {
                try {
                    $count = @$affectedFn($this->transaction ?? $this->defaultTx ?? $this->db);
                    $this->affectedRows = is_int($count) && $count > 0 ? $count : 0;
                } catch (\Throwable) {
                    $this->affectedRows = 0;
                }
            }

            // Only a real result set (SELECT / RETURNING) carries a row to read for
            // lastId. A bound-parameter INSERT/UPDATE/DELETE returns a non-true,
            // non-resource value from ibase_execute(); ibase_fetch_assoc() on it raises
            // a TypeError under PHP 8.x (the @ suppresses warnings, not Errors) — see #121.
            if (is_resource($result)) {
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
        // Firebird's folding rule is ASYMMETRIC:
        //     CREATE TABLE foo     -> stored as FOO   (unquoted folds to UPPER)
        //     CREATE TABLE "Foo"   -> stored as Foo   (quoted keeps its case)
        //
        // So uppercasing is CORRECT for the unquoted case - the common one - and
        // WRONG for a quoted mixed-case table, which is a real thing on Firebird.
        // Dropping the uppercase would not fix that, it would just invert which
        // half is broken.
        //
        // tableExists('Foo') is genuinely AMBIGUOUS: the caller could mean the
        // quoted `Foo` or the unquoted `FOO`. Match EITHER and report present if
        // either exists. Do not "simplify" this back to a single comparison -
        // that is the bug this replaces (a quoted mixed-case table was reported
        // absent, so the CREATE TABLE idempotency guard never fired).
        $rows = $this->query(
            "SELECT RDB\$RELATION_NAME FROM RDB\$RELATIONS WHERE RDB\$SYSTEM_FLAG = 0 AND RDB\$VIEW_BLR IS NULL AND (TRIM(RDB\$RELATION_NAME) = ? OR TRIM(RDB\$RELATION_NAME) = ?)",
            [$table, strtoupper($table)]
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
                'primaryKey' => in_array($fieldName, $pkFields, true),
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

    /**
     * Append RETURNING * to a single-row INSERT so the last-id is read from the
     * returned row (FB-LASTID-GAP). Firebird 2.0+ supports RETURNING natively, so
     * `INSERT ... RETURNING *` returns the inserted row (whose PK the BEFORE
     * INSERT trigger drew from the generator) and execute() captures its first
     * column as lastId. Without this, db.insert()->lastId was 0 on the native
     * path (only the PDO fallback declared RETURNING). Mirrors PdoFirebirdAdapter.
     */
    public function insertReturningClause(): string
    {
        return ' RETURNING *';
    }

    public function lastInsertId(): int|string
    {
        return $this->lastId;
    }

    /**
     * Rows affected by the most recent write (best-effort ibase_affected_rows()).
     */
    public function affectedRows(): int
    {
        return $this->affectedRows;
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
     *
     * Database identifier resolution — two layers:
     *
     * 1. ``TINA4_DATABASE_FIREBIRD_PATH`` env override wins if set. Useful
     *    for Windows users with raw backslash paths (no URL encoding
     *    required) and for ops setups that keep server URL and DB
     *    location in separate config layers.
     * 2. Otherwise normalise the URL path component via
     *    {@see normalizeDbIdentifier()} — accepts every sensible variant
     *    (single/double slash, drive letter, alias).
     */
    private function parseConnection(string $input): array
    {
        $envOverride = \Tina4\DotEnv::getEnv('TINA4_DATABASE_FIREBIRD_PATH');

        if (str_contains($input, '://')) {
            $parts = parse_url($input);
            $rawPath = $parts['path'] ?? '';
            $database = ($envOverride !== null && $envOverride !== '')
                ? $envOverride
                : self::normalizeDbIdentifier($rawPath);
            return [
                'host' => $parts['host'] ?? '',
                'port' => $parts['port'] ?? 3050,
                'username' => isset($parts['user']) ? urldecode($parts['user']) : $this->username,
                'password' => isset($parts['pass']) ? urldecode($parts['pass']) : $this->password,
                'database' => $database,
            ];
        }

        // Plain file path — env override still wins if set.
        return [
            'host' => '',
            'port' => 3050,
            'username' => $this->username,
            'password' => $this->password,
            'database' => ($envOverride !== null && $envOverride !== '') ? $envOverride : $input,
        ];
    }

    /**
     * Turn the URL path component into a Firebird database identifier.
     *
     * Firebird is the awkward one — it needs either an absolute file path
     * on the server, a Windows drive-letter path, or an alias name. The
     * classic URI form uses a double-slash to keep the leading "/" of an
     * absolute path through ``parse_url``::
     *
     *     firebird://host:port//firebird/data/app.fdb   →  /firebird/data/app.fdb
     *
     * But that double slash is unintuitive to anyone used to the way
     * postgres / mysql / mssql encode the database name. We accept five
     * equivalent forms and normalise all of them:
     *
     * - ``//abs/path/db.fdb``  → ``/abs/path/db.fdb``  (classic double-slash)
     * - ``/abs/path/db.fdb``   → ``/abs/path/db.fdb``  (single-slash, what most people type)
     * - ``/C:/Data/db.fdb``    → ``C:/Data/db.fdb``    (Windows, leading URL slash dropped)
     * - ``/C%3A/Data/db.fdb``  → ``C:/Data/db.fdb``    (Windows with URL-encoded colon)
     * - ``/employee``          → ``employee``          (alias — single token)
     *
     * Aliases are detected as the leftover case: a single token with no
     * slashes. Anything path-like is kept as a path.
     */
    /**
     * Resolve the Firebird connection charset (php #160).
     *
     * The adapter used to hardcode UTF8 with no override, which double-encodes
     * UTF-8 bytes stored under a legacy NONE database. The charset is resolved
     * from, in precedence order:
     *
     *   1. the connection URL query  firebird://host:port/path?charset=NONE
     *   2. an explicit charset arg passed to the constructor
     *   3. the TINA4_DATABASE_CHARSET environment variable
     *   4. the UTF8 default (unchanged — non-breaking for existing connections)
     *
     * Pure config resolution over its inputs (URL string, arg, env) — opens no
     * connection, so it is unit-testable without a live Firebird server. Shared
     * by both the native ext-interbase adapter and PdoFirebirdAdapter.
     */
    public static function resolveCharset(string $connectionString, ?string $charsetArg = null): string
    {
        $urlCharset = null;
        if (str_contains($connectionString, '://')) {
            $query = parse_url($connectionString, PHP_URL_QUERY);
            if (is_string($query) && $query !== '') {
                parse_str($query, $params);
                if (isset($params['charset']) && is_string($params['charset']) && $params['charset'] !== '') {
                    $urlCharset = $params['charset'];
                }
            }
        }

        if ($urlCharset !== null) {
            return $urlCharset;
        }
        if ($charsetArg !== null && $charsetArg !== '') {
            return $charsetArg;
        }
        $env = \Tina4\DotEnv::getEnv('TINA4_DATABASE_CHARSET');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        return 'UTF8';
    }

    public static function normalizeDbIdentifier(string $rawPath): string
    {
        $decoded = urldecode($rawPath);

        // Classic double-slash form: //abs/path → /abs/path
        if (str_starts_with($decoded, '//')) {
            $decoded = substr($decoded, 1);
        }

        // Windows drive-letter — drop the URL-introduced leading slash.
        // /C:/Data/db.fdb → C:/Data/db.fdb
        if (preg_match('#^/?[A-Za-z]:[/\\\\]#', $decoded)) {
            if (str_starts_with($decoded, '/')) {
                $decoded = substr($decoded, 1);
            }
            return $decoded;
        }

        // Look at the content after stripping the leading slash. If it's
        // a single token with no separators, it's a Firebird alias —
        // return WITHOUT the leading slash (the alias name itself is the
        // identifier).
        $body = str_starts_with($decoded, '/') ? substr($decoded, 1) : $decoded;
        if ($body !== '' && !str_contains($body, '/') && !str_contains($body, '\\')) {
            return $body;
        }

        // Otherwise it's a file path. If it already has a leading slash,
        // keep it. If it's a relative-looking path (slash-separated but
        // no leading "/") promote it to absolute — Firebird needs
        // absolute paths and we don't know the server's CWD anyway.
        return str_starts_with($decoded, '/') ? $decoded : '/' . $decoded;
    }

    /**
     * Execute a SQL statement with params using ibase_prepare/ibase_execute.
     *
     * Wraps the underlying ibase calls in a one-shot reconnect-and-retry on
     * dead-connection errors ("Error writing data to the connection",
     * connection shutdown, network error). Idle Firebird connections die
     * silently behind NAT timeouts, server-side ConnectionIdleTimeout, or
     * Docker network rotation; without this the next prepare() crashes the
     * request. Skipped inside an explicit transaction — atomicity beats
     * resilience there; the caller handles rollback.
     *
     * @return resource|bool Result resource or true for non-SELECT, false on error
     */
    private function executeInternal(string $sql, array $params): mixed
    {
        try {
            return $this->doExecute($sql, $params);
        } catch (\Throwable $e) {
            if (!self::isDeadConnection($e->getMessage()) || $this->transaction !== null) {
                throw $e;
            }
            $this->reconnect();
            return $this->doExecute($sql, $params);
        }
    }

    /**
     * Raw single-attempt execute. Throws on dead-connection so executeInternal
     * can decide whether to retry; returns the result resource (or true/false)
     * for ordinary outcomes.
     *
     * @return resource|bool
     */
    private function doExecute(string $sql, array $params): mixed
    {
        $errFn = $this->fn . 'errmsg';
        // Every statement runs in a transaction: the explicit one when
        // startTransaction() is active, otherwise the long-lived READ COMMITTED
        // default so the connection always sees committed data / new DDL (#132).
        $context = $this->transaction ?? $this->defaultTransaction();

        if (empty($params)) {
            $queryFn = $this->fn . 'query';
            $result = @$queryFn($context, $sql);
        } else {
            // ibase/fbird only speaks ? — translate :named from the ORM/QueryBuilder.
            [$sql, $params] = \Tina4\SQLTranslator::namedToPositional($sql, $params);

            // ibase_execute()/fbird_execute() cannot reliably bind a PHP null — it
            // fails building the XSQLDA ("Incorrect values within SQLDA structure —
            // empty pointer to data at SQLVAR index N"), position/count dependent.
            // Rewrite each null parameter to a literal NULL in the SQL and drop it
            // from the bound set so no null is ever bound. Preserves true SQL NULL
            // semantics and never touches a ? inside a string literal/comment. #123
            [$sql, $params] = self::rewriteNullParamsToLiterals($sql, $params);

            // Firebird has no native boolean — a BooleanField column is INTEGER,
            // so bind PHP booleans as 1/0 (ibase otherwise stringifies `false`
            // to '' — same class of bug as PG).
            $values = self::normalizeBoolParams(array_values($params), nativeBoolean: false);

            // Bind through ibase_query(), NOT ibase_prepare() + ibase_execute().
            //
            // Parameters are still bound by the driver — this is not string
            // interpolation and carries no injection risk — but the statement is
            // driver-owned and dropped when its result is freed, instead of
            // living as a persistent prepared statement.
            //
            // A persistent statement is what caused #170: its compiled request
            // stays registered on the attachment, and ext-interbase does not
            // detach on ibase_close() while the process is alive, so the table
            // stayed locked for the life of the process. Any later DDL from
            // another connection then waited forever (both the native default
            // transaction and pdo_firebird use a WAIT lock policy). Measured on
            // the lab against Firebird 5.0.4: unparameterised statements always
            // released the table, parameterised ones never did, and no order of
            // ibase_free_query()/ibase_free_result() changed that.
            //
            // The transaction still comes first, so the statement joins the
            // ACTIVE transaction — that is the #132 fix, preserved: what must
            // never happen is binding the transaction resource where the driver
            // expects the link.
            $queryFn = $this->fn . 'query';
            $result = @$queryFn($context, $sql, ...$values);
        }

        if ($result === false) {
            $msg = $errFn();
            if (self::isDeadConnection($msg)) {
                throw new \RuntimeException($msg);
            }
            $this->lastError = $msg;
            return false;
        }

        return $result;
    }

    /**
     * Rewrite each NULL bound parameter to a literal `NULL` in the SQL and drop
     * it from the positional value list. Works around the ibase/fbird driver
     * mis-binding a PHP null (XSQLDA "empty pointer to data at SQLVAR index N").
     *
     * String literals ('' -escaped, Firebird style) and `--` line comments are
     * skipped so a `?` inside them is never miscounted. NULL semantics are
     * preserved — the column is still written as SQL NULL, just via a literal
     * instead of a bound parameter.
     *
     * @param array<int, mixed> $params  positional params (post named-to-positional)
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function rewriteNullParamsToLiterals(string $sql, array $params): array
    {
        if (!in_array(null, $params, true)) {
            return [$sql, $params];
        }

        $index = 0;
        $kept = [];
        $rewritten = preg_replace_callback(
            "/'(?:[^']|'')*'|--[^\n]*|\?/s",
            static function (array $m) use (&$index, &$kept, $params): string {
                if ($m[0] !== '?') {
                    return $m[0]; // string literal / comment — preserved verbatim
                }
                $value = array_key_exists($index, $params) ? $params[$index] : null;
                $index++;
                if ($value === null) {
                    return 'NULL';
                }
                $kept[] = $value;
                return '?';
            },
            $sql
        );

        // Fail safe: if the PCRE engine bailed, leave the statement untouched.
        return $rewritten === null ? [$sql, $params] : [$rewritten, $kept];
    }

    /**
     * Detect dead-socket Firebird errors that warrant a reconnect.
     * Substring match (case-insensitive) so we match both ibase and fbird
     * wording, and across server-side versus driver-side error sources.
     *
     * The markers are verbatim fragments of Firebird's OWN message catalogue
     * (firebird.msg, the file the client library formats its errors from),
     * checked against the installed catalogue rather than written from memory —
     * because two of them had been written from memory and could never match:
     * Firebird has no message containing "network error" (it says "Unable to
     * complete network request to host") and none containing "connection is not
     * active" (it says "invalid database handle (no active connection)"). A
     * connection the framework could have recovered transparently therefore
     * surfaced to the caller instead. Both old spellings are kept — a
     * driver-side or OS-level source may still produce them — and the two real
     * wordings are added alongside.
     */
    public static function isDeadConnection(?string $msg): bool
    {
        if ($msg === null || $msg === '') {
            return false;
        }
        $lower = strtolower($msg);
        $markers = [
            'error writing data to the connection',
            'error reading data from the connection',
            'connection shutdown',
            'connection lost',
            'invalid database handle',
            'unable to complete network request',
            'network error',
            'connection is not active',
            'broken pipe',
        ];
        foreach ($markers as $m) {
            if (str_contains($lower, $m)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Force-close any stale handle and reopen using the original connection
     * params. Idempotent — safe to call when the connection is already dead.
     */
    private function reconnect(): void
    {
        if ($this->db !== null) {
            try {
                $this->releaseSharedLink();
            } catch (\Throwable) {
                // ignored — connection already gone
            }
        }
        $this->db = null;
        $this->transaction = null;
        $this->defaultTx = null; // the old transaction died with the old link
        $this->open();
    }

    /**
     * The long-lived READ COMMITTED (rec_version) transaction used for every
     * statement outside an explicit startTransaction(). Created lazily on the
     * live link. READ COMMITTED re-reads the latest committed data on each
     * statement, so a reused/long-lived connection never serves a stale snapshot
     * (the SNAPSHOT default did). #132
     *
     * @return resource
     */
    private function defaultTransaction(): mixed
    {
        if ($this->defaultTx === null) {
            $transFn = $this->fn . 'trans';
            // @-suppressed like every other ibase_* call here: on a dead link
            // (e.g. the first statement after a forced disconnect, before the
            // reconnect fires) ibase_trans warns "connection shutdown"; that is
            // the expected transient the reconnect path handles, so it must not
            // surface as a PHP warning. The subsequent ibase_query still fails
            // loud and drives the reconnect.
            $this->defaultTx = @$transFn(
                IBASE_WRITE | IBASE_COMMITTED | IBASE_REC_VERSION | IBASE_WAIT,
                $this->db,
            );
        }
        return $this->defaultTx;
    }

    /**
     * Commit the default (auto-commit) transaction FULLY and drop it, so the next
     * statement in the default path opens a brand-new READ COMMITTED transaction.
     * This is true per-statement autocommit: it makes the write durable + visible
     * AND holds no lock between statements — a retained transaction (commit_ret)
     * would keep a lock on every table it touched and deadlock a later
     * DROP/RECREATE of one. A fresh transaction each time still reads the latest
     * committed state (that is the point of READ COMMITTED). #132
     */
    private function commitDefault(): void
    {
        if ($this->defaultTx === null) {
            return;
        }
        $commitFn = $this->fn . 'commit';
        @$commitFn($this->defaultTx);
        $this->defaultTx = null;
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
