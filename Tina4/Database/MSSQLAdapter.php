<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * Microsoft SQL Server adapter.
 *
 * Two backends, chosen automatically in the constructor:
 *   - 'sqlsrv' (PRIMARY): PHP's ext-sqlsrv (sqlsrv_* functions) — the Microsoft
 *     driver, the natural choice on Windows / Microsoft-driver deployments.
 *   - 'pdo'    (FALLBACK): PDO with the dblib driver (ext-pdo_dblib / FreeTDS) —
 *     the same FreeTDS stack tina4-python (pymssql) and tina4-ruby (tiny_tds)
 *     use. This is what reaches MSSQL on macOS/CI where ext-sqlsrv is painful.
 *
 * The public API and behaviour are identical between the two backends — every
 * method branches once on $driver. A clear error is thrown only when NEITHER
 * backend is available.
 */
class MSSQLAdapter implements DatabaseAdapter
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
        return 'mssql';
    }

    use SqlNormalizerTrait;
    use FetchOneTrait;
    use ExecuteManyLoopTrait;

    /** @var resource|\PDO|null sqlsrv connection resource OR a PDO handle. */
    private mixed $db = null;
    /** @var 'sqlsrv'|'pdo' Which backend this instance drives. */
    private string $driver;
    private ?string $lastError = null;
    private bool $autoCommit;
    private int|string $lastId = 0;

    /** @var int Rows affected by the most recent write (sqlsrv_rows_affected / rowCount). */
    private int $affectedRows = 0;

    /**
     * @param string $connectionString URL: "mssql://user:pass@host:port/dbname"
     *                                  or "host\instance" with separate params
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
        private readonly int $port = 1433,
        ?bool $autoCommit = null,
    ) {
        // Pick a backend: prefer the Microsoft driver (ext-sqlsrv); fall back to
        // PDO+dblib (ext-pdo_dblib / FreeTDS) — the same stack the Python/Ruby
        // frameworks use for MSSQL. Throw only when neither is available.
        if (function_exists('sqlsrv_connect')) {
            $this->driver = 'sqlsrv';
        } elseif (in_array('dblib', \PDO::getAvailableDrivers(), true)) {
            $this->driver = 'pdo';
        } else {
            throw new \RuntimeException(
                'MSSQLAdapter requires either the ext-sqlsrv PHP extension '
                . '(Microsoft driver: sudo pecl install sqlsrv on Linux/macOS, '
                . 'or enable it in php.ini on Windows) '
                . 'OR the ext-pdo_dblib extension (FreeTDS: pdo_dblib must list '
                . "'dblib' in PDO::getAvailableDrivers())."
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

        // Bound the connect (TINA4_DATABASE_CONNECT_TIMEOUT). Both driver
        // branches below arm their own mechanism; see each for what it covers.
        $timeout = $this->beginConnectTimeout();
        $target = $params['host'] . ':' . $params['port'];

        if ($this->driver === 'pdo') {
            // dblib DSN uses host:port with a COLON (not a comma like sqlsrv).
            // The FreeTDS TDS protocol version is taken from the environment
            // (TDSVER / freetds.conf) — do not hard-code it here.
            $port = ($params['port'] > 0) ? (int)$params['port'] : 1433;
            $dsn = "dblib:host={$params['host']}:{$port};dbname={$params['database']};charset=UTF-8";

            // FreeTDS login timeout. pdo_dblib maps both of these onto
            // dbsetlogintime(). MEASURED CAVEAT (Ubuntu 24.04.4, PHP 8.3.6,
            // FreeTDS via pdo_dblib): this bounds the CONNECT, but against a
            // peer that completes the TCP handshake and then never speaks TDS
            // it did NOT fire — the connect still blocked past 25s. There is no
            // other timeout knob pdo_dblib exposes, so that case stays
            // unbounded on this branch; use ext-sqlsrv (LoginTimeout, below)
            // where a hard bound matters.
            $options = [];
            if ($timeout > 0) {
                $options[\PDO::ATTR_TIMEOUT] = $timeout;
                $options[\PDO::DBLIB_ATTR_CONNECTION_TIMEOUT] = $timeout;
            }

            try {
                $pdo = new \PDO($dsn, $params['username'], $params['password'], $options);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            } catch (\PDOException $e) {
                $this->throwIfConnectTimedOut('MSSQLAdapter', $target, $e);
                $this->lastError = $e->getMessage();
                throw new \RuntimeException("MSSQLAdapter: Failed to connect: {$e->getMessage()}");
            }

            $this->db = $pdo;
            return;
        }

        $serverName = $params['host'];
        if ($params['port'] > 0 && $params['port'] !== 1433) {
            $serverName .= ', ' . $params['port'];
        }

        $connectionInfo = [
            'Database' => $params['database'],
            'CharacterSet' => 'UTF-8',
            'ReturnDatesAsStrings' => true,
        ];

        if ($params['username'] !== '') {
            $connectionInfo['UID'] = $params['username'];
        }
        if ($params['password'] !== '') {
            $connectionInfo['PWD'] = $params['password'];
        }

        // sqlsrv's own login timeout, in seconds — the driver's documented
        // mechanism. NOT verified on the lab box, which has no ext-sqlsrv.
        if ($timeout > 0) {
            $connectionInfo['LoginTimeout'] = $timeout;
        }

        $conn = @sqlsrv_connect($serverName, $connectionInfo);
        if ($conn === false) {
            $errors = sqlsrv_errors();
            $msg = $errors ? $errors[0]['message'] : 'Unknown connection error';
            $this->throwIfConnectTimedOut('MSSQLAdapter', $target, $msg);
            $this->lastError = $msg;
            throw new \RuntimeException("MSSQLAdapter: Failed to connect: {$msg}");
        }

        $this->db = $conn;
    }

    public function close(): void
    {
        if ($this->db !== null) {
            if ($this->driver === 'pdo') {
                // PDO closes the connection when the handle is released.
                $this->db = null;
                return;
            }
            sqlsrv_close($this->db);
            $this->db = null;
        }
    }

    public function query(string $sql, array $params = []): array
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            if (!empty($params)) {
                // sqlsrv only speaks ? — translate :named from the ORM/QueryBuilder.
                [$sql, $params] = \Tina4\SQLTranslator::namedToPositional($sql, $params);
            }
            // MSSQL BIT takes 1/0; bind PHP booleans as 1/0 (sqlsrv otherwise
            // stringifies `false` to '' — same class of bug as PG).
            $values = empty($params) ? [] : self::normalizeBoolParams(array_values($params), nativeBoolean: false);
            // Splice raw-binary params as 0x literals (FreeTDS cannot bind them).
            [$sql, $values] = self::inlineBinaryParams($sql, $values);

            if ($this->driver === 'pdo') {
                $stmt = $this->db->prepare($sql);
                $stmt->execute($values);
                return $stmt->fetchAll(\PDO::FETCH_ASSOC);
            }

            $stmt = empty($values)
                ? @sqlsrv_query($this->db, $sql)
                : @sqlsrv_query($this->db, $sql, $values);

            if ($stmt === false) {
                $errors = sqlsrv_errors();
                $this->lastError = $errors ? $errors[0]['message'] : 'Query failed';
                return [];
            }

            $rows = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $rows[] = $row;
            }

            sqlsrv_free_stmt($stmt);
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
        // v3.13.12: strip trailing `;` before COUNT(*) wrap + OFFSET/FETCH append.
        $sql = self::stripTrailingSemicolons($sql);

        // v3.13.37 (DB-contract A): the MAIN query must FAIL LOUD — a bad
        // statement RAISES instead of being swallowed into an empty result set
        // (parity with execute(), fetchOne() and the Python master). The COUNT
        // probe is best-effort and runs on a SEPARATE statement (its own
        // sqlsrv_query inside query()), so a probe failure only defaults total
        // to 0 and can never mask a real main-query failure.
        // SQL Server (error 20018) REJECTS a subquery whose inner SELECT ends in
        // an ORDER BY without TOP/OFFSET/FETCH/FOR XML, so wrapping the raw user
        // SQL in COUNT(*) silently fell back to total = 0 while the rows came
        // back fine (#262). Strip a trailing ORDER BY for the COUNT probe ONLY —
        // it is meaningless inside a COUNT. The paged query below keeps its ORDER
        // BY intact (OFFSET/FETCH needs it). Applies to BOTH the pdo_dblib and
        // sqlsrv backends since the probe runs through query().
        $total = 0;
        // The closing paren goes on its OWN LINE (wrapCountSubquery): inline, a
        // trailing `-- comment` in the user SQL comments the `)` out and the
        // probe fails on otherwise-valid SQL.
        $countInner = self::stripTrailingOrderBy($sql);
        $countSql = self::wrapCountSubquery($countInner, '_count_query');
        $countResult = $this->query($countSql, $params);
        if ($this->lastError === null) {
            $total = (int)($countResult[0]['total'] ?? 0);
        }

        // MSSQL pagination: OFFSET...FETCH NEXT (requires ORDER BY).
        // v3.13.12: $limit <= 0 means "no pagination" (fetchAll's
        // default — give me ALL rows).
        $this->lastError = null;
        // Skip the append when the caller's statement already ENDS with its own
        // row cap — a second OFFSET/FETCH is a syntax error, exactly as a second
        // LIMIT is on the LIMIT/OFFSET engines.
        $pagedSql = $sql;
        if ($limit > 0 && !self::hasTrailingFetch($sql)) {
            // Look for the ORDER BY in the SCRUBBED copy: an ORDER BY that only
            // appears inside a `-- comment` or a string literal is not one, and
            // treating it as one omits the ORDER BY that OFFSET/FETCH requires.
            if (!preg_match('/\bORDER\s+BY\b/i', self::scrubSqlText($pagedSql))) {
                $pagedSql = self::appendSqlClause($pagedSql, 'ORDER BY (SELECT NULL)');
            }
            // NEW LINE (appendSqlClause): appended inline the cap lands inside a
            // trailing `-- comment` and is swallowed, returning the WHOLE table.
            $pagedSql = self::appendSqlClause(
                $pagedSql,
                "OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY"
            );
        }

        $data = $this->query($pagedSql, $params);
        // query() clears lastError on entry and records the driver error on
        // failure (returning []), so a non-null lastError here means the MAIN
        // query failed — RAISE it (FAILS LOUD).
        if ($this->lastError !== null) {
            throw new DatabaseException('MSSQL fetch() failed: ' . $this->lastError);
        }

        return [
            'data' => $data,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    protected function engineLabel(): string
    {
        return 'MSSQL';
    }

    public function execute(string $sql, array $params = []): bool|DatabaseResult
    {
        $this->ensureOpen();
        $this->lastError = null;

        // Portable SQLite-canonical DDL -> MSSQL dialect on the way in, so a
        // generated migration OR an ORM::createTable() statement applies here:
        // AUTOINCREMENT -> IDENTITY(1,1), strip IF NOT EXISTS (MSSQL has none),
        // and TIMESTAMP -> DATETIME2 (MSSQL's TIMESTAMP is a rowversion, not a
        // datetime). DDL-only, so DML/queries are untouched. Mirrors the Python
        // master's mssql.py _translate_sql.
        $sql = \Tina4\SQLTranslator::autoIncrementSyntax($sql, 'mssql');
        $sql = \Tina4\SQLTranslator::ddlTypes($sql, 'mssql');

        try {
            if (!empty($params)) {
                // sqlsrv only speaks ? — translate :named from the ORM/QueryBuilder.
                [$sql, $params] = \Tina4\SQLTranslator::namedToPositional($sql, $params);
            }
            // MSSQL BIT takes 1/0; bind PHP booleans as 1/0 (sqlsrv otherwise
            // stringifies `false` to '' — same class of bug as PG).
            $values = empty($params) ? [] : self::normalizeBoolParams(array_values($params), nativeBoolean: false);
            // Splice raw-binary params as 0x literals (FreeTDS cannot bind them).
            [$sql, $values] = self::inlineBinaryParams($sql, $values);

            if ($this->driver === 'pdo') {
                // PDO is in ERRMODE_EXCEPTION — a bad statement raises a
                // PDOException, caught below and re-raised as DatabaseException
                // (FAIL LOUD parity with the sqlsrv branch).
                $stmt = $this->db->prepare($sql);
                $stmt->execute($values);
                $this->affectedRows = max(0, $stmt->rowCount());
                return true;
            }

            $stmt = empty($values)
                ? @sqlsrv_query($this->db, $sql)
                : @sqlsrv_query($this->db, $sql, $values);

            if ($stmt === false) {
                // FAIL LOUD: capture the cause on error() AND raise.
                $this->affectedRows = 0;
                $errors = sqlsrv_errors();
                $this->lastError = $errors ? $errors[0]['message'] : 'Execute failed';
                throw new DatabaseException('MSSQL execute() failed: ' . ($this->lastError ?: 'unknown error'));
            }

            // sqlsrv_rows_affected returns -1 for statements with no count (DDL);
            // clamp to 0 so the reported count is never negative.
            $rows = sqlsrv_rows_affected($stmt);
            $this->affectedRows = is_int($rows) && $rows > 0 ? $rows : 0;
            sqlsrv_free_stmt($stmt);
            return true;
        } catch (DatabaseException $e) {
            throw $e;
        } catch (\PDOException $e) {
            // FAIL LOUD: capture the cause on error() AND raise.
            $this->affectedRows = 0;
            $this->lastError = $e->getMessage();
            throw new DatabaseException('MSSQL execute() failed: ' . $e->getMessage());
        } catch (\Exception $e) {
            $this->affectedRows = 0;
            $this->lastError = $e->getMessage();
            throw $e;
        }
    }

    /**
     * Rows affected by the most recent write (sqlsrv_rows_affected / PDO rowCount).
     */
    public function affectedRows(): int
    {
        return $this->affectedRows;
    }

    public function tableExists(string $table): bool
    {
        // v3.13.14 (#48): honour a schema-qualified name ("dbo.widget"); a
        // bare name matches in any schema (unchanged). Schemas are everyday
        // in SQL Server, and pre-fix any qualified table_name was invisible.
        [$schema, $tbl] = self::splitSchema($table);
        $rows = $this->query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME = ? AND (? IS NULL OR TABLE_SCHEMA = ?)",
            [$tbl, $schema, $schema]
        );
        return count($rows) > 0;
    }

    public function getColumns(string $table): array
    {
        // v3.13.14 (#48): honour a schema-qualified name; bare = any schema.
        [$schema, $tbl] = self::splitSchema($table);
        $sql = "SELECT c.COLUMN_NAME, c.DATA_TYPE, c.IS_NULLABLE, c.COLUMN_DEFAULT,
                    CASE WHEN kcu.COLUMN_NAME IS NOT NULL THEN 1 ELSE 0 END AS is_primary
                FROM INFORMATION_SCHEMA.COLUMNS c
                LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                    ON c.TABLE_NAME = kcu.TABLE_NAME AND c.COLUMN_NAME = kcu.COLUMN_NAME
                    AND c.TABLE_SCHEMA = kcu.TABLE_SCHEMA
                    AND kcu.CONSTRAINT_NAME IN (
                        SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                        WHERE CONSTRAINT_TYPE = 'PRIMARY KEY' AND TABLE_NAME = c.TABLE_NAME
                    )
                WHERE c.TABLE_NAME = ? AND (? IS NULL OR c.TABLE_SCHEMA = ?)
                ORDER BY c.ORDINAL_POSITION";

        $rows = $this->query($sql, [$tbl, $schema, $schema]);
        $columns = [];

        foreach ($rows as $row) {
            $columns[] = [
                'name' => $row['COLUMN_NAME'],
                'type' => $row['DATA_TYPE'],
                'nullable' => $row['IS_NULLABLE'] === 'YES',
                'default' => $row['COLUMN_DEFAULT'],
                'primaryKey' => (int)$row['is_primary'] === 1,
            ];
        }

        return $columns;
    }

    public function getTables(): array
    {
        $rows = $this->query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
        );
        return array_column($rows, 'TABLE_NAME');
    }

    public function lastInsertId(): int|string
    {
        if ($this->lastId !== 0) {
            return $this->lastId;
        }

        // Fallback: SCOPE_IDENTITY
        $rows = $this->query("SELECT SCOPE_IDENTITY() AS id");
        if (!empty($rows) && $rows[0]['id'] !== null) {
            return $rows[0]['id'];
        }

        return 0;
    }

    public function startTransaction(): void
    {
        $this->ensureOpen();
        if ($this->driver === 'pdo') {
            $this->db->beginTransaction();
            return;
        }
        sqlsrv_begin_transaction($this->db);
    }

    public function commit(): void
    {
        $this->ensureOpen();
        if ($this->driver === 'pdo') {
            // pdo_dblib runs in autocommit mode, so a standalone write has
            // already committed and there is no open transaction. PDO::commit()
            // throws "There is no active transaction" in that case — guard it so
            // a stray commit (e.g. the facade's autocommit after a standalone
            // write) is a harmless no-op, matching the tolerant sqlsrv_commit.
            if ($this->db->inTransaction()) {
                $this->db->commit();
            }
            return;
        }
        sqlsrv_commit($this->db);
    }

    public function rollback(): void
    {
        $this->ensureOpen();
        try {
            if ($this->driver === 'pdo') {
                // Only roll back an actually-open transaction (autocommit means
                // a standalone write left none) — PDO::rollBack() otherwise
                // throws "There is no active transaction".
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return;
            }
            sqlsrv_rollback($this->db);
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
        }
    }

    public function error(): ?string
    {
        return $this->lastError;
    }

    /**
     * Get the underlying connection: a sqlsrv resource ($driver === 'sqlsrv')
     * or a PDO handle ($driver === 'pdo' / dblib).
     */
    public function getConnection(): mixed
    {
        return $this->db;
    }

    /**
     * Which backend this instance drives: 'sqlsrv' (Microsoft driver) or
     * 'pdo' (PDO+dblib / FreeTDS fallback).
     */
    public function getDriver(): string
    {
        return $this->driver;
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
        if (str_contains($input, '://')) {
            $parts = parse_url($input);
            return [
                'host' => $parts['host'] ?? 'localhost',
                'port' => $parts['port'] ?? 1433,
                'username' => isset($parts['user']) ? urldecode($parts['user']) : $this->username,
                'password' => isset($parts['pass']) ? urldecode($parts['pass']) : $this->password,
                'database' => ltrim($parts['path'] ?? '', '/'),
            ];
        }

        return [
            'host' => $input ?: 'localhost',
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
            'database' => $this->database,
        ];
    }

    /**
     * Inline any BINARY parameter as a T-SQL `0x` varbinary literal, returning
     * the rewritten SQL and the remaining bound values.
     *
     * MSSQL-BUFFER: neither backend can carry raw binary through a `?` bind -
     * FreeTDS (pdo_dblib) breaks the TDS stream on a NUL byte (MEASURED against
     * real SQL Server: even PDO::PARAM_LOB fails with "Incorrect syntax"), and
     * ext-sqlsrv mishandles it the same way - so a value that is not valid UTF-8
     * text is spliced as `0x<hex>`, which round-trips byte-for-byte on both
     * backends. Ordinary UTF-8 text keeps the bound `?` path (a crafted string is
     * still parameterised, never inlined).
     *
     * @param string $sql    SQL whose placeholders are all positional `?`
     *                       (post SQLTranslator::namedToPositional).
     * @param array  $values Positional bind values, in order.
     * @return array{0: string, 1: array} The rewritten SQL and the kept values.
     */
    private static function inlineBinaryParams(string $sql, array $values): array
    {
        if ($values === []) {
            return [$sql, $values];
        }
        $out = '';
        $kept = [];
        $index = 0;
        $length = strlen($sql);
        $inString = false;
        for ($position = 0; $position < $length; $position++) {
            $char = $sql[$position];
            if ($char === "'") {
                // A doubled '' inside a literal is an escaped quote, not a close.
                if ($inString && $position + 1 < $length && $sql[$position + 1] === "'") {
                    $out .= "''";
                    $position++;
                    continue;
                }
                $inString = !$inString;
                $out .= $char;
                continue;
            }
            if ($char === '?' && !$inString) {
                $value = $values[$index] ?? null;
                $index++;
                if (is_string($value) && self::isBinaryParam($value)) {
                    $out .= '0x' . bin2hex($value);
                } else {
                    $out .= '?';
                    $kept[] = $value;
                }
                continue;
            }
            $out .= $char;
        }
        // Any values past the placeholders we scanned (should not happen) are kept.
        for ($count = count($values); $index < $count; $index++) {
            $kept[] = $values[$index];
        }
        return [$out, $kept];
    }

    /**
     * Whether a string parameter is raw BINARY (inline it as `0x<hex>`) rather
     * than text. True when it holds a NUL byte or is not valid UTF-8 - both cases
     * a `?` bind cannot carry over FreeTDS. Valid UTF-8 text stays bound.
     *
     * @param string $value The candidate bind value.
     * @return bool True when the value must be inlined as a varbinary literal.
     */
    private static function isBinaryParam(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        if (strpos($value, "\0") !== false) {
            return true;
        }
        // preg_match with the /u flag returns 1 for valid UTF-8, false otherwise.
        return @preg_match('//u', $value) !== 1;
    }
}
