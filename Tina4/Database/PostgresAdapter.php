<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * PostgreSQL database adapter — uses PHP's ext-pgsql (pg_*) functions.
 * The extension is optional; a clear error is thrown if not installed.
 */
class PostgresAdapter implements DatabaseAdapter
{
    use AutocommitTrait;

    // Provides stripTrailingSemicolons() (v3.13.12) and splitSchema() (v3.13.14 #48).
    // This adapter referenced both helpers but never mixed the trait in, so every
    // fetch()/fetchOne()/getColumns() fatalled on PostgreSQL — undetected because
    // the PG test suite skips without a live server.
    use SqlNormalizerTrait;

    /** @var \PgSql\Connection|resource|null */
    private mixed $db = null;
    private ?string $lastError = null;
    private bool $autoCommit;
    private int|string $lastId = 0;

    /** @var int Rows affected by the most recent write (pg_affected_rows()). */
    private int $affectedRows = 0;

    /**
     * @param string $connectionString DSN: "host=... port=... dbname=... user=... password=..."
     *                                  or URL: "pgsql://user:pass@host:port/dbname"
     * @param bool|null $autoCommit Whether to auto-commit
     */
    public function __construct(
        private readonly string $connectionString,
        ?bool $autoCommit = null,
        private readonly string $username = '',
        private readonly string $password = '',
    ) {
        if (!function_exists('pg_connect')) {
            throw new \RuntimeException(
                'PostgresAdapter requires the ext-pgsql PHP extension. '
                . 'Install it with: sudo apt-get install php-pgsql (Debian/Ubuntu) '
                . 'or brew install php (macOS with pgsql enabled).'
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

        $dsn = $this->buildDsn($this->connectionString);

        // PGSQL_CONNECT_FORCE_NEW: without it, pg_connect() reuses one libpq
        // connection for every adapter sharing a DSN — so a pool of N adapters
        // would all share ONE connection (and closing one would break the
        // rest). Forcing a new connection makes each pooled adapter genuinely
        // independent, matching psycopg2 / node-pg / the pg gem.
        // Clear any unrelated pending warning so error_get_last() below can only
        // report THIS pg_connect's failure.
        error_clear_last();
        $conn = @pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if ($conn === false) {
            // Say WHY and say WHICH. "Failed to connect to PostgreSQL" on its own
            // sent 43 identical errors to a maintainer who then went looking for a
            // driver bug; the real cause was a database that did not exist, and
            // libpq had said so all along. The @ suppresses the warning but
            // error_get_last() still holds its message.
            $why = error_get_last()['message'] ?? '';
            $why = trim(preg_replace('/^pg_connect\(\):\s*/i', '', $why) ?? '');
            $this->lastError = 'Failed to connect to PostgreSQL'
                . ' [' . $this->safeDsn($dsn) . ']'
                . ($why !== '' ? ': ' . $why : '');
            throw new \RuntimeException("PostgresAdapter: {$this->lastError}");
        }

        $this->db = $conn;
    }

    public function close(): void
    {
        if ($this->db !== null) {
            pg_close($this->db);
            $this->db = null;
        }
    }

    public function query(string $sql, array $params = []): array
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            // pg_query_params binds positionally by $1, $2…; named placeholders
            // emitted by ORM/QueryBuilder must be translated to ? first
            // (then convertPlaceholders rewrites ? to $N).
            if (!empty($params)) {
                [$sql, $params] = \Tina4\SQLTranslator::namedToPositional($sql, $params);
            }
            $pgSql = $this->convertPlaceholders($sql);

            if (empty($params)) {
                $result = @pg_query($this->db, $pgSql);
            } else {
                $values = $this->flattenParams($params);
                $result = @pg_query_params($this->db, $pgSql, $values);
            }

            if ($result === false) {
                $this->lastError = pg_last_error($this->db);
                return [];
            }

            // ext-pgsql has no automatic type map — pg_fetch_assoc() returns
            // EVERY column as a PHP string (id "1", bool 't', floats "1.5").
            // Build a name→caster map once per result so a Tina4 app on SQLite
            // (native-ish types) sees the SAME shapes on PostgreSQL, matching
            // the Python (psycopg2) and Node (node-postgres) adapters.
            $casters = $this->buildColumnCasters($result);

            $rows = [];
            while ($row = pg_fetch_assoc($result)) {
                foreach ($casters as $col => $cast) {
                    // Skip nulls — a NULL column stays null in every engine.
                    if (isset($row[$col])) {
                        $row[$col] = $cast($row[$col]);
                    }
                }
                $rows[] = $row;
            }

            pg_free_result($result);

            // Track last insert ID from RETURNING clause
            if (!empty($rows) && $this->isInsertReturning($sql)) {
                $first = reset($rows[0]);
                if ($first !== false) {
                    $this->lastId = $first;
                }
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
        $countSql = "SELECT COUNT(*) as total FROM ({$sql}) AS _count_query";
        $countResult = $this->query($countSql, $params);
        if ($this->lastError !== null) {
            throw new DatabaseException('PostgreSQL fetch() failed: ' . $this->lastError);
        }
        $total = (int)($countResult[0]['total'] ?? 0);

        // v3.13.12: $limit <= 0 means "no pagination" (fetchAll's
        // default — give me ALL rows).
        // When the user SQL already ends with its own LIMIT clause, don't
        // append a second one — `... LIMIT 1 LIMIT 100 OFFSET 0` is a PG
        // syntax error that would otherwise be swallowed into an empty
        // result.
        $pagedSql = ($limit <= 0 || self::hasTrailingLimit($sql))
            ? $sql
            : "{$sql} LIMIT {$limit} OFFSET {$offset}";
        $data = $this->query($pagedSql, $params);
        if ($this->lastError !== null) {
            throw new DatabaseException('PostgreSQL fetch() failed: ' . $this->lastError);
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
            throw new DatabaseException('PostgreSQL fetchOne() failed: ' . $this->lastError);
        }
        return $rows[0] ?? null;
    }

    public function execute(string $sql, array $params = []): bool|DatabaseResult
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            // pg_query_params binds positionally by $1, $2…; named placeholders
            // emitted by ORM/QueryBuilder must be translated to ? first
            // (then convertPlaceholders rewrites ? to $N).
            if (!empty($params)) {
                [$sql, $params] = \Tina4\SQLTranslator::namedToPositional($sql, $params);
            }
            $pgSql = $this->convertPlaceholders($sql);

            if (empty($params)) {
                $result = @pg_query($this->db, $pgSql);
            } else {
                $values = $this->flattenParams($params);
                $result = @pg_query_params($this->db, $pgSql, $values);
            }

            if ($result === false) {
                // FAIL LOUD: capture the cause on error() AND raise — execute()
                // must never swallow a failed statement (parity with Python and
                // with fetch()/fetchOne() here).
                $this->affectedRows = 0;
                $this->lastError = pg_last_error($this->db);
                throw new DatabaseException('PostgreSQL execute() failed: ' . ($this->lastError ?: 'unknown error'));
            }

            // Rows affected by this write (0 for DDL); read before freeing.
            $this->affectedRows = (int) pg_affected_rows($result);

            $hasReturning = $this->isInsertReturning($sql);

            // Capture RETURNING id if present
            if ($hasReturning) {
                $row = pg_fetch_assoc($result);
                if ($row !== false) {
                    $first = reset($row);
                    if ($first !== false) {
                        $this->lastId = $first;
                    }
                }
            }

            pg_free_result($result);

            // For an INSERT without RETURNING, probe lastval() so getLastId()
            // works on auto-increment PKs.
            //
            // Issue #38: SELECT lastval() raises on tables with no sequence
            // (UUID, ULID, hash PKs etc.). The exception itself isn't fatal,
            // but PostgreSQL marks the whole transaction as aborted, so every
            // subsequent statement on this connection fails with
            // "current transaction is aborted" — far away from the real cause.
            //
            // Fix: wrap the probe in a SAVEPOINT. If lastval() raises, we
            // ROLLBACK TO SAVEPOINT and the outer transaction stays usable;
            // lastId just stays at its previous value. On success we RELEASE.
            if (!$hasReturning && $this->isInsert($sql)) {
                $sp = @pg_query($this->db, 'SAVEPOINT _t4_lastval_probe');
                if ($sp !== false) {
                    @pg_free_result($sp);
                    $probe = @pg_query($this->db, 'SELECT lastval()');
                    if ($probe === false) {
                        $rb = @pg_query($this->db, 'ROLLBACK TO SAVEPOINT _t4_lastval_probe');
                        if ($rb !== false) { @pg_free_result($rb); }
                    } else {
                        $row = pg_fetch_row($probe);
                        if ($row !== false && isset($row[0])) {
                            $this->lastId = $row[0];
                        }
                        pg_free_result($probe);
                        $rel = @pg_query($this->db, 'RELEASE SAVEPOINT _t4_lastval_probe');
                        if ($rel !== false) { @pg_free_result($rel); }
                    }
                }
            }

            return true;
        } catch (DatabaseException $e) {
            // Already captured + raised above — let it propagate unchanged.
            throw $e;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            throw $e;
        }
    }

    public function executeMany(string $sql, array $paramsList = []): int
    {
        // FAIL LOUD: a failing row must NOT be silently swallowed — the old
        // catch-and-continue counted only the rows that did not throw and
        // returned a partial count, hiding data loss. execute() raises on a bad
        // row; let it propagate so the caller (and the facade's transactional
        // batch path) can roll back the whole batch. Atomicity for a batch is
        // provided by Database::insert()/executeMany() (one pinned connection +
        // one transaction); this primitive just runs each row and never hides a
        // failure.
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
            $placeholders = implode(', ', array_map(fn(int $i) => '$' . ($i + 1), array_keys($keys)));
            $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";
            $paramsList = array_map(fn($row) => array_values($row), $data);
            return $this->executeMany($sql, $paramsList) > 0;
        }

        $cols = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(
            fn(int $i) => '$' . ($i + 1),
            array_keys(array_values($data))
        ));

        $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders}) RETURNING *";
        $values = array_values($data);

        $result = @pg_query_params($this->db, $sql, $values);
        if ($result === false) {
            $this->lastError = pg_last_error($this->db);
            return false;
        }

        $row = pg_fetch_assoc($result);
        if ($row !== false) {
            $first = reset($row);
            if ($first !== false) {
                $this->lastId = $first;
            }
        }

        pg_free_result($result);
        return true;
    }

    public function update(string $table, array $data, string $where = '', array $whereParams = []): bool
    {
        $setParts = [];
        $params = [];
        $i = 1;

        foreach ($data as $col => $val) {
            $setParts[] = "{$col} = \${$i}";
            $params[] = $val;
            $i++;
        }

        $sql = "UPDATE {$table} SET " . implode(', ', $setParts);

        if ($where !== '') {
            // Re-index where placeholders
            $where = $this->reindexPlaceholders($where, $i);
            $sql .= " WHERE {$where}";
            $params = array_merge($params, $this->flattenParams($whereParams));
        }

        $result = @pg_query_params($this->db, $sql, $params);
        if ($result === false) {
            $this->lastError = pg_last_error($this->db);
            return false;
        }

        pg_free_result($result);
        return true;
    }

    public function delete(string $table, string|array $filter = '', array $whereParams = []): bool
    {
        // List of assoc arrays — delete each row
        if (is_array($filter) && isset($filter[0]) && is_array($filter[0])) {
            foreach ($filter as $row) {
                if (!$this->delete($table, $row)) return false;
            }
            return true;
        }

        // Assoc array — build WHERE from keys
        if (is_array($filter)) {
            $parts = [];
            $params = [];
            $i = 1;
            foreach ($filter as $col => $val) {
                $parts[] = "{$col} = \${$i}";
                $params[] = $val;
                $i++;
            }
            $sql = "DELETE FROM {$table} WHERE " . implode(' AND ', $parts);
            $result = @pg_query_params($this->db, $sql, $params);
            if ($result === false) {
                $this->lastError = pg_last_error($this->db);
                return false;
            }
            pg_free_result($result);
            return true;
        }

        // String filter
        $sql = "DELETE FROM {$table}";
        if ($filter !== '') {
            $pgWhere = $this->convertPlaceholders($filter);
            $sql .= " WHERE {$pgWhere}";
        }

        if (empty($whereParams)) {
            $result = @pg_query($this->db, $sql);
        } else {
            $values = $this->flattenParams($whereParams);
            $result = @pg_query_params($this->db, $sql, $values);
        }

        if ($result === false) {
            $this->lastError = pg_last_error($this->db);
            return false;
        }

        pg_free_result($result);
        return true;
    }

    public function tableExists(string $table): bool
    {
        // v3.13.14 (#48): to_regclass() resolves a (possibly schema-qualified)
        // relation name using the same rules as a FROM clause (search_path
        // included), returning NULL if absent. Pre-fix this hardcoded
        // schemaname='public' and matched the whole dotted string as a flat
        // tablename, so a table in a non-public schema was invisible.
        $rows = $this->query("SELECT to_regclass($1) AS oid", [$table]);
        return count($rows) > 0 && ($rows[0]['oid'] ?? null) !== null;
    }

    public function getColumns(string $table): array
    {
        // v3.13.14 (#48): honour a schema-qualified name; default to public.
        [$schema, $tbl] = self::splitSchema($table);
        $schema = $schema ?? 'public';
        $sql = "SELECT c.column_name, c.data_type, c.is_nullable, c.column_default,
                    CASE WHEN tc.constraint_type = 'PRIMARY KEY' THEN true ELSE false END AS is_primary
                FROM information_schema.columns c
                LEFT JOIN information_schema.key_column_usage kcu
                    ON c.table_name = kcu.table_name AND c.column_name = kcu.column_name
                    AND c.table_schema = kcu.table_schema
                LEFT JOIN information_schema.table_constraints tc
                    ON kcu.constraint_name = tc.constraint_name AND tc.constraint_type = 'PRIMARY KEY'
                WHERE c.table_name = $1 AND c.table_schema = $2
                ORDER BY c.ordinal_position";

        $rows = $this->query($sql, [$tbl, $schema]);
        $columns = [];

        foreach ($rows as $row) {
            $columns[] = [
                'name' => $row['column_name'],
                'type' => $row['data_type'],
                'nullable' => $row['is_nullable'] === 'YES',
                'default' => $row['column_default'],
                'primaryKey' => ($row['is_primary'] ?? 'f') === 't' || ($row['is_primary'] ?? false) === true,
            ];
        }

        return $columns;
    }

    public function getTables(): array
    {
        // v3.13.14 (#48): list every user schema; public tables stay bare,
        // others are returned schema-qualified so they round-trip back through
        // tableExists()/getColumns().
        $rows = $this->query(
            "SELECT schemaname, tablename FROM pg_tables
             WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
             ORDER BY schemaname, tablename"
        );
        return array_map(
            fn($r) => $r['schemaname'] === 'public'
                ? $r['tablename']
                : $r['schemaname'] . '.' . $r['tablename'],
            $rows
        );
    }

    public function lastInsertId(): int|string
    {
        return $this->lastId;
    }

    /**
     * Rows affected by the most recent write (pg_affected_rows()).
     */
    public function affectedRows(): int
    {
        return $this->affectedRows;
    }

    public function startTransaction(): void
    {
        $this->ensureOpen();
        @pg_query($this->db, 'BEGIN');
    }

    public function commit(): void
    {
        $this->ensureOpen();
        @pg_query($this->db, 'COMMIT');
    }

    public function rollback(): void
    {
        $this->ensureOpen();
        @pg_query($this->db, 'ROLLBACK');
    }

    public function error(): ?string
    {
        return $this->lastError;
    }

    /**
     * Get the underlying pg connection resource.
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
     * Build a libpq-style DSN from either a URL or key=value string.
     */
    /**
     * The DSN with the password removed, safe to put in an exception message.
     *
     * The host/port/dbname/user are exactly what a maintainer needs to see; the
     * password is exactly what must never reach a log or a 500 body.
     */
    private function safeDsn(string $dsn): string
    {
        return trim((string)preg_replace('/\bpassword=\S*/i', 'password=***', $dsn));
    }

    private function buildDsn(string $input): string
    {
        // Already a key=value DSN
        if (str_contains($input, '=') && !str_contains($input, '://')) {
            return $input;
        }

        // Parse URL format
        $parts = parse_url($input);
        if ($parts === false) {
            return $input;
        }

        $dsn = '';
        if (isset($parts['host'])) {
            $dsn .= 'host=' . $parts['host'] . ' ';
        }
        if (isset($parts['port'])) {
            $dsn .= 'port=' . $parts['port'] . ' ';
        }
        if (isset($parts['path'])) {
            $dbName = ltrim($parts['path'], '/');
            if ($dbName !== '') {
                $dsn .= 'dbname=' . $dbName . ' ';
            }
        }
        $user = isset($parts['user']) ? urldecode($parts['user']) : $this->username;
        $pass = isset($parts['pass']) ? urldecode($parts['pass']) : $this->password;

        if ($user !== '') {
            $dsn .= 'user=' . $user . ' ';
        }
        if ($pass !== '') {
            $dsn .= 'password=' . $pass . ' ';
        }

        return trim($dsn);
    }

    /**
     * Convert ? placeholders to $1, $2, ... PostgreSQL style.
     */
    private function convertPlaceholders(string $sql): string
    {
        // If already using $N placeholders or named params, return as-is
        if (preg_match('/\$\d+/', $sql)) {
            return $sql;
        }

        $counter = 0;
        return preg_replace_callback('/\?/', function () use (&$counter) {
            $counter++;
            return '$' . $counter;
        }, $sql);
    }

    /**
     * Re-index ? or $N placeholders starting from a given index.
     */
    private function reindexPlaceholders(string $sql, int $startIndex): string
    {
        $counter = $startIndex - 1;
        return preg_replace_callback('/\?|\$\d+/', function () use (&$counter) {
            $counter++;
            return '$' . $counter;
        }, $sql);
    }

    /**
     * Flatten associative or indexed params to a simple indexed array.
     */
    private function flattenParams(array $params): array
    {
        $values = [];
        foreach ($params as $value) {
            $values[] = $value;
        }
        // PostgreSQL has a native BOOLEAN; ext-pgsql sends params as text and
        // stringifies PHP `false` to '' — which PG rejects ("invalid input
        // syntax for type boolean"). Bind booleans as 't'/'f' so they
        // round-trip. (Top-level scalars only — IN-clause arrays pass through.)
        return self::normalizeBoolParams($values, nativeBoolean: true);
    }

    /**
     * Check if a SQL statement is an INSERT with RETURNING.
     */
    private function isInsertReturning(string $sql): bool
    {
        return $this->isInsert($sql) && (bool)preg_match('/\bRETURNING\b/i', $sql);
    }

    /**
     * Check if a SQL statement is an INSERT.
     */
    private function isInsert(string $sql): bool
    {
        return (bool)preg_match('/^\s*INSERT\b/i', $sql);
    }

    /**
     * Build a per-result map of column name → value caster.
     *
     * ext-pgsql returns every column as a PHP string. We inspect each
     * field's PostgreSQL type once (via pg_field_type) and pre-compute a
     * cheap closure that coerces the cell to the native PHP type the other
     * Tina4 frameworks (Python/psycopg2, Node/node-postgres) and SQLite3
     * return. Computing the casters once per result — not per cell — keeps
     * the fetch loop tight.
     *
     * Type map (must-fix conversions are int + bool — they break `===` and
     * boolean logic):
     *   int2/int4/int8           → int
     *   bool                     → bool   ('t' → true, 'f' → false)
     *   float4/float8/numeric    → float  (numeric may lose precision; float
     *                                       is accepted for parity — see report)
     *   bytea                    → raw bytes (unescaped, unchanged behaviour)
     *   everything else          → left as string (text/varchar/uuid/json/
     *                                       jsonb/timestamp/date/time/…)
     *
     * Columns left as strings cost nothing: they get no entry in the map, so
     * the fetch loop never touches them.
     *
     * @param \PgSql\Result|resource $result A pg_query[_params] result handle
     * @return array<string, callable(string):mixed>
     */
    private function buildColumnCasters($result): array
    {
        $casters = [];
        $numFields = pg_num_fields($result);

        for ($i = 0; $i < $numFields; $i++) {
            $name = pg_field_name($result, $i);
            $type = pg_field_type($result, $i);

            $caster = match ($type) {
                // Integers: int2 (smallint), int4 (integer/serial),
                // int8 (bigint/bigserial). bigint can exceed PHP_INT_MAX on
                // 32-bit builds — (int) is correct on 64-bit, which is the
                // only supported target.
                'int2', 'int4', 'int8' => static fn(string $v): int => (int) $v,

                // Booleans: PG hands back 't' / 'f'.
                'bool' => static fn(string $v): bool => $v === 't',

                // Floating point + arbitrary-precision numeric. numeric is
                // cast to float for parity with psycopg2/node-postgres default
                // shapes; this can lose precision on very large/precise values
                // (documented as a known minor diff).
                'float4', 'float8', 'numeric' => static fn(string $v): float => (float) $v,

                // Binary: PG returns hex-escaped (\x…) strings via
                // pg_fetch_assoc — unescape to raw bytes (pre-existing
                // behaviour, now folded into the single caster pass).
                'bytea' => static fn(string $v): string => pg_unescape_bytea($v),

                // text / varchar / char / uuid / json / jsonb / timestamp /
                // timestamptz / date / time / interval / etc. → leave as
                // string. json/jsonb stay raw strings (caller decodes if it
                // wants an array). timestamps stay strings — conventional in
                // PHP and what SQLite returns too.
                default => null,
            };

            if ($caster !== null) {
                $casters[$name] = $caster;
            }
        }

        return $casters;
    }
}
