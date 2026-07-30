<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

use Tina4\DatabaseUrl;

/**
 * Database wrapper that provides a consistent API matching Python/Ruby conventions.
 *
 * Can be used as:
 *   - Database::create($url)  — static factory (returns Database instance)
 *   - new Database($url)      — constructor
 *   - Database::fromEnv()     — from TINA4_DATABASE_URL env var
 *
 * All methods delegate to the internal DatabaseAdapter.
 *
 * Supported schemes:
 *   sqlite              => SQLite3Adapter
 *   postgres, postgresql => PostgresAdapter
 *   mysql               => MySQLAdapter
 *   mssql, sqlserver     => MSSQLAdapter
 *   firebird            => FirebirdAdapter
 *   mongodb, pymongo    => MongoDBAdapter
 *   odbc                => ODBCAdapter
 */
class Database implements DatabaseAdapter
{
    /** @var array<string, class-string<DatabaseAdapter>> Maps scheme to adapter class */
    private const ADAPTER_MAP = [
        'sqlite' => SQLite3Adapter::class,
        'postgres' => PostgresAdapter::class,
        'postgresql' => PostgresAdapter::class,
        'mysql' => MySQLAdapter::class,
        'mssql' => MSSQLAdapter::class,
        'sqlserver' => MSSQLAdapter::class,
        'firebird' => FirebirdAdapter::class,
        'mongodb' => MongoDBAdapter::class,
        'pymongo' => MongoDBAdapter::class,
        'odbc' => ODBCAdapter::class,
    ];

    /** @var DatabaseAdapter The underlying database adapter (single-connection mode) */
    private ?DatabaseAdapter $adapter = null;

    /** @var DatabaseAdapter[] Connection pool (pooled mode) */
    private array $pool = [];

    /** @var int Pool size (0 = single connection) */
    private int $poolSize = 0;

    /** @var int Round-robin index for pool rotation */
    private int $poolIndex = 0;

    /** @var int Rows affected by the most recent execute() (0 for DDL/SELECT). */
    private int $affectedRows = 0;

    /**
     * @var DatabaseAdapter|null Adapter pinned to the current transaction.
     *
     * With pooling enabled, ordinary calls round-robin through the pool.
     * Inside a transaction, however, all calls must land on the SAME
     * adapter — otherwise startTransaction(), execute() and commit()
     * each rotate to a different connection and the transaction is
     * meaningless (executes autocommit on whatever adapter they hit;
     * the final commit lands on yet another adapter that has nothing
     * to commit; rollback() is silently no-op'd).
     *
     * PHP-FPM is one process per request, so a plain instance property
     * works as the per-request pin — there's no thread-local needed
     * (Python uses threading.local() for its multi-threaded model).
     * startTransaction() sets the pin, commit()/rollback() clear it.
     */
    private ?DatabaseAdapter $pinnedAdapter = null;

    /**
     * @var int Explicit-transaction depth counter (DB-contract C, v3.13.37).
     *
     * startTransaction() increments; commit()/rollback() decrement. A second
     * startTransaction() on a connection that's already mid-transaction is a
     * nested begin — most engines silently commit or no-op an inner BEGIN,
     * leaving the connection mid-transaction with the caller none the wiser.
     * We keep this depth counter and log a warning instead of silently
     * re-beginning; the inner commit just unwinds the depth, the OUTER commit
     * is the real one. Parity with Python's _tx_local.depth.
     */
    private int $txDepth = 0;

    /**
     * @var bool Whether the current pin came from an explicit startTransaction()
     *           (vs an internal pin like getNextId's). getNextId's SQLite path
     *           must NOT open a nested BEGIN IMMEDIATE while a user transaction
     *           is open on the same connection.
     */
    private bool $insideExplicitTransaction = false;

    /** @var string Connection URL for lazy pool creation */
    private string $url;

    /** @var bool|null Auto-commit setting for pool connections */
    private ?bool $autoCommit;
    private ?string $lastError = null;

    /**
     * Cache of introspected primary-key columns, keyed by table name.
     *
     * @var array<string, array<int, string>>
     */
    private array $pkCache = [];

    /** @var string Username for pool connections */
    private string $dbUsername;

    /** @var string Password for pool connections */
    private string $dbPassword;

    /**
     * Create a new Database wrapper instance.
     *
     * @param string $url Connection URL (e.g. "sqlite::memory:", "postgres://user:pass@host/db")
     * @param bool|null $autoCommit Override auto-commit setting
     * @param string $username Database username
     * @param string $password Database password
     * @param int $pool Number of pooled connections (0 = single connection, N>0 = round-robin pool)
     */
    public function __construct(string $url, ?bool $autoCommit = null, string $username = '', string $password = '', int $pool = 0)
    {
        $this->url = $url;
        $this->autoCommit = $autoCommit;
        $this->dbUsername = $username;
        $this->dbPassword = $password;

        // TINA4_DB_POOL: env-level default for the connection pool size.
        // The constructor arg wins when explicitly nonzero; otherwise the env
        // is consulted, falling back to 0 (single connection).
        if ($pool === 0) {
            $envPool = \Tina4\DotEnv::getEnv('TINA4_DB_POOL');
            if ($envPool !== null && $envPool !== '' && (int) $envPool > 0) {
                $pool = (int) $envPool;
            }
        }
        $this->poolSize = $pool;

        if ($pool > 0) {
            // Pooled mode — adapters created lazily via getPooledAdapter()
            $this->pool = array_fill(0, $pool, null);
        } else {
            // Single-connection mode — current behavior
            $adapter = self::createAdapter($url, $autoCommit, $username, $password);
            $this->adapter = self::wrapWithCache($adapter);
        }
    }

    /**
     * Create a Database instance from a connection URL string.
     *
     * @param string $url Connection URL (e.g. "sqlite::memory:", "postgres://user:pass@host/db")
     * @param bool|null $autoCommit Override auto-commit setting
     * @param string $username Database username
     * @param string $password Database password
     * @param int $pool Number of pooled connections (0 = single, N>0 = round-robin pool)
     * @return self
     * @throws \InvalidArgumentException If the URL scheme is unsupported
     * @throws \RuntimeException If the required PHP extension is missing
     */
    public static function create(string $url, ?bool $autoCommit = null, string $username = '', string $password = '', int $pool = 0): self
    {
        return new self($url, $autoCommit, $username, $password, $pool);
    }


    /**
     * Create a Database instance from the TINA4_DATABASE_URL environment variable.
     *
     * @param string $envKey Environment variable name (default: TINA4_DATABASE_URL)
     * @param bool|null $autoCommit Override auto-commit setting
     * @return self|null Null if the env var is not set
     */
    public static function fromEnv(string $envKey = 'TINA4_DATABASE_URL', ?bool $autoCommit = null, int $pool = 0): ?self
    {
        $url = \Tina4\DotEnv::getEnv($envKey);

        if ($url === null || $url === '') {
            return null;
        }

        $username = \Tina4\DotEnv::getEnv('TINA4_DATABASE_USERNAME') ?? '';
        $password = \Tina4\DotEnv::getEnv('TINA4_DATABASE_PASSWORD') ?? '';

        return new self($url, $autoCommit, $username, $password, $pool);
    }

    /**
     * Get the next adapter — from pool (round-robin) or single connection.
     *
     * If a transaction is in progress, returns the pinned adapter so that
     * startTransaction(), every execute(), and the final commit/rollback
     * all run on the same connection. Without this, pool>0 silently breaks
     * atomicity (rollback no-ops, executes autocommit on rotated adapters).
     *
     * @return DatabaseAdapter
     */
    private function getNextAdapter(): DatabaseAdapter
    {
        // Pinned during a transaction — same adapter for every call.
        if ($this->pinnedAdapter !== null) {
            return $this->pinnedAdapter;
        }

        if ($this->poolSize > 0) {
            $idx = $this->poolIndex;
            $this->poolIndex = ($this->poolIndex + 1) % $this->poolSize;

            if ($this->pool[$idx] === null) {
                $adapter = self::createAdapter(
                    $this->url, $this->autoCommit, $this->dbUsername, $this->dbPassword
                );
                $this->pool[$idx] = self::wrapWithCache($adapter);
            }

            return $this->pool[$idx];
        }

        return $this->adapter;
    }

    /**
     * Get the underlying DatabaseAdapter for driver-specific operations.
     *
     * Returns the RAW driver adapter (e.g. SQLite3Adapter), unwrapping the
     * transparent query-cache decorator if present. This matches Python's
     * Database.get_adapter(), whose query cache lives inside the Database
     * class rather than in a wrapper — callers reaching for getAdapter()
     * want the real driver (getDatabase(), driver-specific methods, etc.),
     * not the cache shell. Cached reads/writes still flow through the
     * wrapper via the internal getNextAdapter().
     *
     * With pooling enabled, returns the next adapter via round-robin.
     *
     * @return DatabaseAdapter
     */
    public function getAdapter(): DatabaseAdapter
    {
        $adapter = $this->getNextAdapter();
        if ($adapter instanceof CachedDatabase) {
            return $adapter->getAdapter();
        }
        return $adapter;
    }

    /**
     * Get pool size (0 = single connection mode).
     */
    public function poolSize(): int
    {
        return $this->poolSize;
    }

    /**
     * Alias for poolSize() — returns total pool size (0 = single connection mode).
     */
    public function size(): int
    {
        return $this->poolSize;
    }

    /**
     * Get the number of active (created) connections in the pool.
     */
    public function activeCount(): int
    {
        if ($this->poolSize === 0) {
            return $this->adapter !== null ? 1 : 0;
        }
        return count(array_filter($this->pool, fn($a) => $a !== null));
    }

    /**
     * Borrow the next adapter from the pool (round-robin).
     *
     * In PHP, connections are persistent objects in the pool array.
     * checkout() returns the next available adapter, lazily creating it if needed.
     * Call checkin() when done to signal the connection is available again.
     *
     * @return DatabaseAdapter
     */
    public function checkout(): DatabaseAdapter
    {
        return $this->getAdapter();
    }

    /**
     * Return a borrowed adapter to the pool.
     *
     * In PHP, pool connections are persistent and remain in the pool array,
     * so this is a no-op. It exists to satisfy the cross-framework interface
     * and document the borrow/return pattern.
     *
     * @param DatabaseAdapter $adapter The adapter previously returned by checkout()
     */
    public function checkin(DatabaseAdapter $adapter): void
    {
        // No-op — PHP pool connections are persistent references in the pool array.
        // The adapter is already held by $this->pool and will be reused automatically.
    }

    /**
     * Close all pooled (or single) connections and release resources.
     *
     * After calling closeAll() the instance should not be used further
     * unless connections are re-established.
     */
    public function closeAll(): void
    {
        $this->close();
    }

    // -------------------------------------------------------------------------
    // Query methods
    // -------------------------------------------------------------------------

    /**
     * Execute a query with parameter binding and return results.
     *
     * @param string $sql SQL query
     * @param array<mixed> $params Bound parameters
     * @return array<int, array<string, mixed>> Array of associative arrays
     */
    public function query(string $sql, array $params = []): array
    {
        return $this->getNextAdapter()->query($sql, $params);
    }

    /**
     * Fetch results with pagination.
     *
     * Returns a DatabaseResult object that supports iteration, counting,
     * array access, and JSON serialisation.
     *
     * @param string $sql SQL query
     * @param array<mixed> $params Bound parameters
     * @param int $limit Max rows to return
     * @param int $offset Starting offset
     * @param bool $noCache When true, bypass the query cache for this call —
     *   no lookup, no store — and run straight against the connection. Default
     *   false preserves caching. Parity with Python Database.fetch(no_cache=).
     * @return DatabaseResult
     */
    public function fetch(string $sql, array $params = [], int $limit = 100, int $offset = 0, bool $noCache = false): DatabaseResult
    {
        $adapter = $this->getNextAdapter();

        // FAIL LOUD (v3.13.37, DB-contract A): the adapter's fetch() RAISES on a
        // bad statement (no swallow-to-empty-result). Mirror the Python master's
        // _fetch_direct: clear lastError on success, and on a SQL error capture
        // the cause on getError() — preferring the adapter's own error message
        // (set in its error path) over the str() of the exception — BEFORE the
        // re-raise. Engines that don't expose their own lastError still get a
        // populated getError() via $e->getMessage().
        try {
            $raw = $adapter instanceof CachedDatabase
                ? $adapter->fetch($sql, $params, $limit, $offset, $noCache)
                : $adapter->fetch($sql, $params, $limit, $offset);
            $this->lastError = null;
        } catch (\Throwable $e) {
            $this->lastError = $adapter->error() ?: ($e->getMessage() ?: $this->lastError);
            throw $e;
        }

        $records = $raw['data'] ?? [];
        $columns = !empty($records) ? array_keys($records[0]) : [];
        $total   = $raw['total'] ?? count($records);

        return new DatabaseResult(
            records: $records,
            columns: $columns,
            count:   $total,
            limit:   $limit,
            offset:  $offset,
            adapter: $adapter,
            sql:     $sql,
        );
    }

    /**
     * Fetch rows and return the records array directly.
     *
     * Symmetric with `fetchOne()`. For the common case where you just want
     * the rows and don't need the `DatabaseResult` metadata (count, limit,
     * offset, sql, error, last_id), this is one less attribute access than
     * `fetch(...)->records`.
     *
     *     $rows = $db->fetchAll("SELECT * FROM users WHERE active = ?", [1]);
     *     foreach ($rows as $row) {
     *         echo $row['name'], PHP_EOL;
     *     }
     *
     * Returns `[]` (not `null`) when no rows match. Cross-framework parity
     * with Python `db.fetch_all()`, Ruby `db.fetch_all`, and Node
     * `db.fetchAll()`.
     *
     * v3.13.12: default `$limit` is **0** (no truncation) — the method
     * name says `fetchAll`, so it returns all matching rows. Pre-v3.13.12
     * silently truncated to 100. Pass an explicit `$limit` to cap.
     *
     * `$noCache=true` bypasses the query cache for this one call (see fetch()).
     */
    public function fetchAll(string $sql, array $params = [], int $limit = 0, int $offset = 0, bool $noCache = false): array
    {
        return $this->fetch($sql, $params, $limit, $offset, $noCache)->records;
    }

    /**
     * Run a query and return the first row or null.
     *
     * @param string $sql SQL query
     * @param array<mixed> $params Bound parameters
     * @param bool $noCache When true, bypass the query cache for this call (see fetch()).
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = [], bool $noCache = false): ?array
    {
        $adapter = $this->getNextAdapter();

        // FAIL LOUD (v3.13.37, DB-contract A): fetchOne() used to call the
        // adapter directly, so a SQL error raised (good) but getError() stayed
        // null — the public API couldn't read the cause. Route it through the
        // same error-capturing path fetch() uses: clear lastError on success,
        // and on a SQL error capture the cause (adapter->error() preferred over
        // the exception message) BEFORE the re-raise. Because it raises before
        // the CachedDatabase wrapper can store the result, a buried failure can
        // never be cached. Mirrors the Python master's _fetch_one_direct.
        try {
            $result = $adapter instanceof CachedDatabase
                ? $adapter->fetchOne($sql, $params, $noCache)
                : $adapter->fetchOne($sql, $params);
            $this->lastError = null;
            return $result;
        } catch (\Throwable $e) {
            $this->lastError = $adapter->error() ?: ($e->getMessage() ?: $this->lastError);
            throw $e;
        }
    }

    /**
     * Execute a DDL or data manipulation statement (no result set).
     *
     * FAIL LOUD contract (parity with the Python master and with this
     * framework's own fetch()/fetchOne()): on a SQL error — bad statement,
     * constraint violation, dead/aborted connection, missing driver — this
     * sets getError() AND RAISES a {@see DatabaseException} (or lets a driver
     * exception propagate). It never returns false. On SUCCESS the return is
     * unchanged: true for a plain write/DDL, or a DatabaseResult for
     * RETURNING / CALL / EXEC / SELECT — always truthy.
     *
     * Callers that need a bool must wrap this in try/catch (see ORM::save(),
     * ORM::createTable(), Migration::migrate(), DevAdmin + MCP database tools).
     *
     * @param string $sql SQL statement
     * @param array<mixed> $params Bound parameters
     * @return true|DatabaseResult True for writes, DatabaseResult for RETURNING/stored proc
     * @throws DatabaseException When the statement fails (cause on getError()).
     */
    public function execute(string $sql, array $params = []): bool|DatabaseResult
    {
        $adapter = $this->getNextAdapter();
        try {
            $result = $adapter->execute($sql, $params);
        } catch (\Exception $e) {
            // Adapter raised (driver exception, or our own DatabaseException
            // re-thrown from the adapter). Capture the cause and re-raise —
            // fetch()/fetchOne() already behave this way; execute() was the
            // lone swallower. Python master: "set last_error; raise".
            $this->affectedRows = 0;
            $this->lastError = $e->getMessage();
            throw $e;
        }
        // Capture the affected-row count from the adapter that just ran the write
        // (guarded — an adapter that does not expose it reports 0). Consumers read
        // it via affectedRows(); the dev-MCP database_execute tool returns it.
        $this->affectedRows = method_exists($adapter, 'affectedRows') ? (int) $adapter->affectedRows() : 0;

        $upper = strtoupper(trim($sql));
        if (str_contains($upper, 'RETURNING') || str_starts_with($upper, 'CALL ') ||
            str_starts_with($upper, 'EXEC ') || str_starts_with($upper, 'SELECT ')) {
            $this->lastError = null;
            return $result instanceof DatabaseResult ? $result : new DatabaseResult(records: is_array($result) ? $result : []);
        }
        // Plain write/DDL: PHP adapters return a boolean and record the driver
        // error in error(). A false return means the statement failed — capture
        // the cause and RAISE rather than return false (the old behaviour
        // silently masked failed INSERT/UPDATE/DELETE/DDL one level up).
        if ($result === false) {
            $this->lastError = $adapter->error();
            throw new DatabaseException(
                'Database::execute() failed: ' . ($this->lastError ?? 'unknown error')
            );
        }
        $this->lastError = null;
        return true;
    }

    /**
     * Alias for execute() — matches adapter-level naming convention.
     *
     * Shares execute()'s FAIL-LOUD contract: returns true on success and
     * RAISES (never returns false) on a SQL error, with the cause on
     * getError(). Callers that need a bool must wrap this in try/catch.
     *
     * @param string $sql SQL statement
     * @param array<mixed> $params Bound parameters
     * @return bool True on success
     * @throws DatabaseException When the statement fails (cause on getError()).
     *
     * @deprecated Use execute() instead. exec() is a thin backward-compat alias
     *   and will be removed in a future major version. execute() is the single
     *   canonical write method across all four Tina4 frameworks.
     */
    public function exec(string $sql, array $params = []): bool
    {
        $result = $this->execute($sql, $params);
        // execute() returns a DatabaseResult for RETURNING/CALL/EXEC/SELECT;
        // exec() promises a bool, so collapse that to true. A failure has
        // already raised inside execute() before reaching here.
        return $result !== false;
    }

    // -------------------------------------------------------------------------
    // CRUD convenience methods
    // -------------------------------------------------------------------------

    /**
     * Insert a row, or a batch of rows, into a table.
     *
     * A single associative array inserts ONE row and delegates to the engine
     * adapter (which keeps its engine-specific placeholder style + RETURNING /
     * lastId handling). A list of associative arrays is a BATCH insert: it is
     * run through the facade's own executeMany(), which pins ONE connection and
     * wraps the whole batch in a SINGLE transaction.
     *
     * This is the parity fix for the Python master's batch-insert pass. Routing
     * a batch straight to the adapter's per-row executeMany() was a footgun on
     * the auto-committing engines (PostgreSQL/MySQL/MSSQL/Firebird): every row
     * committed on its own, so the batch was NOT atomic, a failing row was
     * silently SWALLOWED (the adapter executeMany() counts only the rows that
     * did not throw and returns true if >0), and getLastId() was unreliable.
     * Running the batch through the facade's executeMany() makes it atomic +
     * fail-loud (one bad row rolls the whole batch back and RAISES) and reads a
     * deterministic last id from the pinned connection.
     *
     * @param string $table Table name
     * @param array<string, mixed>|array<int, array<string, mixed>> $data
     *        Column => value pairs (one row), or a list of such arrays (batch)
     * @return bool True on success
     * @throws DatabaseException When a batch row fails (the whole batch is rolled back).
     */
    public function insert(string $table, array $data): DatabaseResult
    {
        // A list of rows (indexed array whose first element is itself an array)
        // is a batch insert. An empty array is treated as a single (degenerate)
        // insert by the adapter, as before.
        if (isset($data[0]) && is_array($data[0])) {
            return $this->insertBatch($table, $data);
        }

        $adapter = $this->getNextAdapter();
        $result = $adapter->insert($table, $data);
        // Fail-loud: an adapter that returns false failed — capture + raise
        // (parity with execute()/fetch()). Adapters that already raise never
        // reach this branch.
        if ($result === false) {
            $this->lastError = $adapter->error();
            throw new DatabaseException('Database::insert() failed: ' . ($this->lastError ?? 'unknown error'));
        }
        return $this->writeResult($adapter, withLastId: true, minAffected: 1);
    }

    /**
     * Build the DatabaseResult returned by insert/update/delete (parity with the
     * Python master, whose write methods return a DatabaseResult carrying
     * affectedRows + lastId). Reads the affected-row count from the adapter that
     * just ran the write (guarded — 0 when the adapter can't report it), and the
     * last insert id for inserts. The object is always truthy, so `if ($db->
     * insert(...))` still works.
     *
     * @param DatabaseAdapter $adapter The adapter that ran the write (same
     *        connection, so lastInsertId()/affectedRows() are correct under pooling)
     * @param bool $withLastId Populate lastId (inserts only; null for update/delete)
     * @return DatabaseResult
     */
    private function writeResult(DatabaseAdapter $adapter, bool $withLastId, int $minAffected = 0): DatabaseResult
    {
        // A successful single-row insert affects exactly one row, but some
        // adapters (PDO `INSERT ... RETURNING` on Postgres, MSSQL's identity
        // insert) do not surface a rowcount on the insert path, reporting 0.
        // Floor the count at $minAffected (1 for a single insert) so the
        // best-effort affectedRows matches the Python master (whose adapters
        // read the real cursor rowcount of 1). update()/delete() pass 0 — there
        // a 0 legitimately means "no rows matched".
        $reported = method_exists($adapter, 'affectedRows') ? (int) $adapter->affectedRows() : 0;
        $this->affectedRows = max($reported, $minAffected);
        $this->lastError = null;
        return new DatabaseResult(
            records: [],
            columns: [],
            count: 0,
            limit: 0,
            offset: 0,
            adapter: null,
            sql: null,
            affectedRows: $this->affectedRows,
            lastId: $withLastId ? $adapter->lastInsertId() : null,
            error: null,
        );
    }

    /**
     * Atomically insert a list of associative-array rows in ONE transaction on
     * ONE connection, then record the last inserted id.
     *
     * @param string $table Table name
     * @param array<int, array<string, mixed>> $rows List of column => value rows
     * @return bool True on success (false only for an empty list)
     * @throws DatabaseException When any row fails — the whole batch is rolled back.
     */
    private function insertBatch(string $table, array $rows): DatabaseResult
    {
        if (empty($rows)) {
            $this->affectedRows = 0;
            return new DatabaseResult(records: [], columns: [], count: 0, limit: 0, offset: 0, adapter: null, sql: null, affectedRows: 0, lastId: null, error: null);
        }

        // Columns come from the first row; every row must share the same keys.
        $keys = array_keys($rows[0]);
        $cols = implode(', ', $keys);
        // Generic ? placeholders — each engine's execute() handles its own
        // dialect (PostgreSQL's execute() rewrites ? -> $N via convertPlaceholders).
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";

        $paramsList = array_map(static fn(array $row) => array_values($row), $rows);

        // executeMany() pins one adapter, opens a transaction, runs every row,
        // commits, and RE-RAISES (rolling back) on the first failed row.
        $count = $this->executeMany($sql, $paramsList);

        // After a successful batch the pin is released; getLastId() reads from
        // the (now committed) connection's last insert id where the engine
        // exposes it (SERIAL/AUTOINCREMENT/IDENTITY). Engines without a usable
        // last-id concept simply report whatever lastInsertId() returns.
        //
        // lastId must stay the LAST inserted row's id. MySQL's LAST_INSERT_ID()
        // reports the FIRST id of a multi-row INSERT, so a COLLAPSED batch would
        // silently start reporting the first id instead of the last. Only the
        // final chunk's rows matter — that chunk produced the reported id.
        // The LAST row's id, not the first: the MySQL adapter normalises that
        // itself at write time (it is the only place that knows both the first
        // id and the statement's row count), so getLastId() and this result
        // always agree.
        $this->affectedRows = $count;
        return new DatabaseResult(records: [], columns: [], count: 0, limit: 0, offset: 0, adapter: null, sql: null, affectedRows: $count, lastId: $this->getLastId(), error: null);
    }

    /**
     * Update rows in a table.
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs to set
     * @param string $filterSql WHERE clause SQL (e.g. "id = ?", "age > ? AND status = ?")
     * @param array<mixed> $params Bound parameters for the WHERE clause
     * @return DatabaseResult Truthy result carrying affectedRows (lastId null for updates)
     */
    public function update(string $table, array $data, string|array $filterSql = '', array $params = []): DatabaseResult
    {
        [$filterSql, $params] = $this->asWhere($filterSql, $params);

        if ($filterSql === '') {
            $pkColumns = $this->primaryKey($table);
            $missing = array_values(array_filter(
                $pkColumns,
                static fn(string $c): bool => !array_key_exists($c, $data)
            ));

            if ($pkColumns === [] || $missing !== []) {
                throw new DatabaseException(sprintf(
                    'Database::update() requires a filter or the complete primary key in the data; '
                    . 'pass a filter explicitly to update multiple rows (table=%s, primary key=[%s], '
                    . 'missing from data=[%s]). To empty a table use truncate(%s).',
                    $table,
                    implode(', ', $pkColumns),
                    implode(', ', $missing),
                    $table
                ));
            }

            // EVERY key column goes into the WHERE. A composite key built from
            // only its first column would match every row sharing that value -
            // the data-loss bug this method exists to prevent, reintroduced.
            $params = [];
            $where = [];
            foreach ($pkColumns as $column) {
                $params[] = $data[$column];
                $where[] = $column . ' = ?';
                unset($data[$column]);
            }

            if ($data === []) {
                throw new DatabaseException(sprintf(
                    'Database::update() was given only the primary key [%s] and no columns to set (table=%s)',
                    implode(', ', $pkColumns),
                    $table
                ));
            }

            $filterSql = implode(' AND ', $where);
        }

        $adapter = $this->getNextAdapter();
        $result = $adapter->update($table, $data, $filterSql, $params);
        if ($result === false) {
            $this->lastError = $adapter->error();
            throw new DatabaseException('Database::update() failed: ' . ($this->lastError ?? 'unknown error'));
        }
        return $this->writeResult($adapter, withLastId: false);
    }

    /**
     * Return the table's primary-key columns, introspected once and cached.
     *
     * Returns a LIST because a primary key may span several columns. A composite
     * key is still one primary key; it just has more than one column.
     *
     * @param string $table Table name
     * @return array<int, string> Key column names, empty when there is no primary key
     */
    public function primaryKey(string $table): array
    {
        if (!array_key_exists($table, $this->pkCache)) {
            try {
                $columns = $this->getColumns($table);
                $this->pkCache[$table] = array_values(array_map(
                    static fn(array $c): string => (string)$c['name'],
                    array_filter($columns, static fn(array $c): bool => !empty($c['primaryKey']))
                ));
            } catch (\Throwable) {
                $this->pkCache[$table] = [];
            }
        }
        return $this->pkCache[$table];
    }

    /**
     * Normalise a filter to a [sql, params] pair, accepting an array or a string.
     *
     * @param string|array<string, mixed> $filter WHERE clause SQL, or column => value pairs
     * @param array<mixed> $params Bound parameters (string filters only)
     * @return array{0: string, 1: array<mixed>}
     */
    private function asWhere(string|array $filter, array $params): array
    {
        if (is_array($filter)) {
            if ($filter === []) {
                return ['', []];
            }
            $where = array_map(static fn(string $k): string => $k . ' = ?', array_keys($filter));
            return [implode(' AND ', $where), array_values($filter)];
        }
        return [$filter, $params];
    }

    /**
     * Remove every row from a table. The explicit spelling of a whole-table delete.
     *
     * @param string $table Table name
     * @return DatabaseResult Truthy result carrying affectedRows (lastId null)
     * @throws DatabaseException When the delete fails
     */
    public function truncate(string $table): DatabaseResult
    {
        $adapter = $this->getNextAdapter();
        $result = $adapter->delete($table, '1 = 1', []);
        if ($result === false) {
            $this->lastError = $adapter->error();
            throw new DatabaseException('Database::truncate() failed: ' . ($this->lastError ?? 'unknown error'));
        }
        return $this->writeResult($adapter, withLastId: false);
    }

    /**
     * Delete rows from a table.
     *
     * @param string $table Table name
     * @param string $filterSql WHERE clause SQL (e.g. "id = ?")
     * @param array<mixed> $params Bound parameters for the WHERE clause
     * @return DatabaseResult Truthy result carrying affectedRows (lastId null for deletes)
     */
    public function delete(string $table, string|array $filter = '', array $whereParams = []): DatabaseResult
    {
        [$filterSql, $whereParams] = $this->asWhere($filter, $whereParams);
        if ($filterSql === '') {
            throw new DatabaseException(sprintf(
                'Database::delete() requires a filter (table=%s). To remove every row use truncate(%s).',
                $table,
                $table
            ));
        }

        $adapter = $this->getNextAdapter();
        $result = $adapter->delete($table, $filterSql, $whereParams);
        if ($result === false) {
            $this->lastError = $adapter->error();
            throw new DatabaseException('Database::delete() failed: ' . ($this->lastError ?? 'unknown error'));
        }
        return $this->writeResult($adapter, withLastId: false);
    }

    // -------------------------------------------------------------------------
    // Connection & transaction management
    // -------------------------------------------------------------------------

    /**
     * Close all database connections (pool or single).
     */
    /**
     * Open the database connection (no-op — connections are opened lazily in the constructor).
     */
    public function open(): void
    {
        // Connections are opened in the constructor — this satisfies the interface.
    }

    public function close(): void
    {
        if ($this->poolSize > 0) {
            foreach ($this->pool as $i => $adapter) {
                if ($adapter !== null) {
                    $adapter->close();
                    $this->pool[$i] = null;
                }
            }
        } elseif ($this->adapter !== null) {
            $this->adapter->close();
        }
    }

    /**
     * Get the last inserted auto-increment ID.
     */
    public function lastInsertId(): int|string
    {
        return $this->getNextAdapter()->lastInsertId();
    }

    /**
     * Begin a transaction. Pins the adapter for the whole transaction so
     * executes and the final commit/rollback all run on the same connection.
     *
     * Nested-begin guard (v3.13.37, DB-contract C): a second startTransaction()
     * on a connection that already has an open transaction is a double-begin —
     * the inner BEGIN silently commits or no-ops on most engines, leaving the
     * connection mid-transaction with the caller none the wiser. We keep a
     * depth counter and log a clear warning instead of silently re-beginning.
     * The pin stays on the original adapter so commit/rollback still land on
     * the right connection.
     */
    public function startTransaction(): void
    {
        if ($this->pinnedAdapter !== null && $this->insideExplicitTransaction) {
            // Nested begin — warn and bump the depth; do NOT begin again.
            \Tina4\Log::warning(
                'startTransaction() called while a transaction is already open '
                . '(depth would become ' . ($this->txDepth + 1) . '). Nested '
                . 'transactions are not supported — the existing transaction '
                . 'stays open on its pinned connection and this nested begin is '
                . 'ignored. Commit or rollback the outer transaction first.'
            );
            $this->txDepth++;
            return;
        }
        $adapter = $this->getNextAdapter();
        $this->pinnedAdapter = $adapter;
        $this->insideExplicitTransaction = true;
        $this->txDepth = 1;
        $adapter->startTransaction();
    }

    /**
     * Commit the current transaction and release the adapter pin.
     *
     * FAIL LOUD (v3.13.37, DB-contract C): if the underlying commit raises,
     * capture getError() and RE-RAISE — never swallow. On failure the
     * transaction pin is RETAINED so the caller's follow-up rollback() lands on
     * the SAME connection (clearing it would leak a dirty connection back into
     * the pool and route the rollback to a different one). The pin is cleared
     * ONLY on a successful commit. An inner commit of an ignored nested begin
     * just unwinds the depth — the outer commit is the real one.
     */
    public function commit(): void
    {
        if ($this->txDepth > 1) {
            // Inner commit of an ignored nested begin — just unwind the depth.
            $this->txDepth--;
            return;
        }
        $adapter = $this->getNextAdapter();
        try {
            $adapter->commit();
            $this->lastError = null;
        } catch (\Throwable $e) {
            // Keep the pin so rollback() reaches this same connection.
            $this->lastError = $adapter->error() ?: ($e->getMessage() ?: $this->lastError);
            throw $e;
        }
        // Success — release the pin.
        $this->pinnedAdapter = null;
        $this->insideExplicitTransaction = false;
        $this->txDepth = 0;
    }

    /**
     * Rollback the current transaction and release the adapter pin.
     *
     * Rollback is the terminal cleanup of a transaction, so it ALWAYS clears the
     * pin (and the depth counter) — even after a failed commit it routes to the
     * retained pinned connection and cleans it up. If the underlying rollback
     * itself raises, getError() is captured and the error re-raised, but the pin
     * is still released so a poisoned connection doesn't stay pinned forever.
     */
    public function rollback(): void
    {
        $adapter = $this->getNextAdapter();
        try {
            $adapter->rollback();
            $this->lastError = null;
        } catch (\Throwable $e) {
            $this->lastError = $adapter->error() ?: ($e->getMessage() ?: $this->lastError);
            throw $e;
        } finally {
            // Terminal cleanup — always release the pin.
            $this->pinnedAdapter = null;
            $this->insideExplicitTransaction = false;
            $this->txDepth = 0;
        }
    }

    // -------------------------------------------------------------------------
    // Schema introspection
    // -------------------------------------------------------------------------

    /**
     * Check if a table exists.
     */
    public function tableExists(string $tableName): bool
    {
        return $this->getNextAdapter()->tableExists($tableName);
    }

    /**
     * Return a list of table names in the database.
     *
     * @return array<int, string>
     */
    public function getTables(): array
    {
        return $this->getNextAdapter()->getTables();
    }

    /**
     * Return column information for a table.
     *
     * @param string $tableName Table name
     * @return array<int, array{name: string, type: string, nullable: bool}>
     */
    public function getColumns(string $tableName): array
    {
        return $this->getNextAdapter()->getColumns($tableName);
    }

    /**
     * Return the last execute() error message, or null.
     */
    public function getError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Get the last inserted auto-increment ID.
     */
    public function getLastId(): int|string
    {
        return $this->getNextAdapter()->lastInsertId();
    }

    /**
     * Rows affected by the most recent execute() (INSERT/UPDATE/DELETE).
     *
     * Returns 0 for DDL, SELECT, or an engine whose adapter does not report a
     * count. Mirrors the row count the Python master surfaces on execute().
     */
    public function affectedRows(): int
    {
        return $this->affectedRows;
    }

    /**
     * Ensure the tina4_sequences table exists for race-safe ID generation.
     *
     * @param DatabaseAdapter $adapter
     */
    private function ensureSequenceTable(DatabaseAdapter $adapter): void
    {
        try {
            $adapter->execute(
                'CREATE TABLE IF NOT EXISTS tina4_sequences ('
                . 'seq_name VARCHAR(200) PRIMARY KEY, '
                . 'current_value INTEGER DEFAULT 0'
                . ')'
            );
        } catch (\Throwable) {
            // Table already exists — ignore
        }
    }

    /**
     * Best-effort MAX(pk) seed for a new sequence row. Returns 0 if the target
     * table is missing or empty. Parity with Python's _sequence_seed_value.
     *
     * @param DatabaseAdapter $adapter
     * @param string|null $table
     * @param string $pkColumn
     * @return int
     */
    private function sequenceSeedValue(DatabaseAdapter $adapter, ?string $table, string $pkColumn): int
    {
        if ($table === null || $table === '') {
            return 0;
        }
        try {
            $maxRow = $adapter->fetchOne("SELECT MAX({$pkColumn}) AS max_id FROM {$table}");
            if ($maxRow !== null) {
                $val = $maxRow['max_id'] ?? $maxRow['MAX_ID'] ?? null;
                if ($val !== null) {
                    return (int) $val;
                }
            }
        } catch (\Throwable) {
            // Table doesn't exist — start at 0.
        }
        return 0;
    }

    /**
     * Atomically increment and return the next value from tina4_sequences.
     *
     * v3.13.37 (DB-contract B): the old read-increment-read path had a RACE —
     * two concurrent callers could read the same current_value and return the
     * same id (duplicate primary keys). This now uses a single atomic
     * increment-and-return per engine, pinned to ONE connection so the two
     * statements (where two are needed) land on the same connection:
     *
     *   - SQLite: serialise the whole op under a `BEGIN IMMEDIATE` write
     *     transaction (acquires SQLite's file write lock before the UPDATE and
     *     holds it through the SELECT) — race-safe across processes. PHP's
     *     ext-sqlite3 double-executes an `UPDATE ... RETURNING` when the result
     *     is fetched (the write applies TWICE), so we deliberately use
     *     `UPDATE +1` then `SELECT current_value` under the lock instead of
     *     RETURNING — exactly the Python master's pre-3.35 shape.
     *   - MySQL: `UPDATE ... SET current_value = LAST_INSERT_ID(current_value+1)`
     *     then `SELECT LAST_INSERT_ID()` on the SAME connection (LAST_INSERT_ID
     *     is per-connection → race-safe).
     *   - MSSQL: `UPDATE ... SET current_value += 1 OUTPUT inserted.current_value`
     *     — one atomic statement; read the OUTPUT row.
     *
     * Seeding the row is race-safe: an atomic insert-if-absent (INSERT OR
     * IGNORE / INSERT IGNORE / INSERT ... WHERE NOT EXISTS) seeded from MAX(pk)
     * runs BEFORE the increment, so there is never a read-then-insert gap. On
     * error we RAISE (never silently fall back to 1).
     *
     * @param string $seqName Sequence name (e.g. "users.id")
     * @param string|null $table Table to seed from
     * @param string $pkColumn Primary key column to seed from
     * @param DatabaseAdapter $adapter The (possibly cache-wrapped) pinned adapter
     * @return int The next available ID
     */
    private function sequenceNext(string $seqName, ?string $table, string $pkColumn, DatabaseAdapter $adapter): int
    {
        // Resolve the raw driver adapter (unwrap the cache decorator) so the
        // SQLite low-level path can reach the live SQLite3 connection, and so
        // engine dispatch is reliable even when query caching is on.
        $raw = $adapter instanceof CachedDatabase ? $adapter->getAdapter() : $adapter;

        if ($raw instanceof SQLite3Adapter) {
            // If we're already inside an explicit transaction the connection is
            // mid-transaction — opening a nested BEGIN IMMEDIATE would fail
            // ("cannot start a transaction within a transaction"). In that case
            // the outer transaction already serialises the increment, so skip
            // our own BEGIN/COMMIT.
            $insideTxn = $this->pinnedAdapter !== null && $this->insideExplicitTransaction;
            return $this->sequenceNextSqlite($raw, $seqName, $table, $pkColumn, !$insideTxn);
        }

        $this->ensureSequenceTable($adapter);

        if ($raw instanceof MySQLAdapter) {
            return $this->sequenceNextMysql($adapter, $seqName, $table, $pkColumn);
        }
        if ($raw instanceof MSSQLAdapter) {
            return $this->sequenceNextMssql($adapter, $seqName, $table, $pkColumn);
        }
        // PostgreSQL fallback / any other engine routed here — generic
        // atomic-ish path (seed insert-if-absent, then +1, then SELECT).
        return $this->sequenceNextGeneric($adapter, $seqName, $table, $pkColumn);
    }

    /**
     * SQLite atomic sequence-next.
     *
     * Runs ensure-table + race-safe seed + atomic increment under a single
     * `BEGIN IMMEDIATE` write transaction directly on the live SQLite3
     * connection. IMMEDIATE acquires SQLite's file write lock up front, so the
     * UPDATE and the read-back SELECT are atomic with respect to every other
     * connection/process — no two callers can read the same counter. We avoid
     * `UPDATE ... RETURNING` because PHP's ext-sqlite3 executes the write a
     * SECOND time when the RETURNING result is fetched (the increment would
     * apply twice).
     *
     * @param SQLite3Adapter $raw
     * @param string $seqName
     * @param string|null $table
     * @param string $pkColumn
     * @return int
     */
    private function sequenceNextSqlite(SQLite3Adapter $raw, string $seqName, ?string $table, string $pkColumn, bool $ownTransaction = true): int
    {
        $conn = $raw->getConnection();
        if ($conn === null) {
            throw new \RuntimeException('getNextId: SQLite adapter has no live connection');
        }
        // Don't let a slow concurrent writer error out with SQLITE_BUSY — wait
        // for the write lock instead (the fork concurrency test depends on this).
        @$conn->busyTimeout(5000);

        $conn->exec(
            'CREATE TABLE IF NOT EXISTS tina4_sequences ('
            . 'seq_name VARCHAR(200) NOT NULL PRIMARY KEY, '
            . 'current_value INTEGER NOT NULL DEFAULT 0)'
        );

        $seed = $this->sequenceSeedValue($raw, $table, $pkColumn);

        // The whole increment runs inside one IMMEDIATE write transaction so
        // the seed + UPDATE + read-back are atomic under SQLite's file write
        // lock — race-safe across connections/processes. Skip BEGIN/COMMIT when
        // we're already inside an outer explicit transaction (a nested BEGIN
        // would fail) — the outer transaction already serialises us.
        if ($ownTransaction) {
            $conn->exec('BEGIN IMMEDIATE');
        }
        try {
            $insert = $conn->prepare(
                'INSERT OR IGNORE INTO tina4_sequences (seq_name, current_value) VALUES (?, ?)'
            );
            $insert->bindValue(1, $seqName, SQLITE3_TEXT);
            $insert->bindValue(2, $seed, SQLITE3_INTEGER);
            $insert->execute();
            $insert->close();

            $update = $conn->prepare(
                'UPDATE tina4_sequences SET current_value = current_value + 1 WHERE seq_name = ?'
            );
            $update->bindValue(1, $seqName, SQLITE3_TEXT);
            $update->execute();
            $update->close();

            $select = $conn->prepare(
                'SELECT current_value FROM tina4_sequences WHERE seq_name = ?'
            );
            $select->bindValue(1, $seqName, SQLITE3_TEXT);
            $result = $select->execute();
            $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
            $select->close();

            if ($ownTransaction) {
                $conn->exec('COMMIT');
            }
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                @$conn->exec('ROLLBACK');
            }
            throw $e;
        }

        if ($row === false || !isset($row['current_value'])) {
            throw new \RuntimeException(
                "getNextId: sequence row '{$seqName}' vanished mid-increment"
            );
        }
        return (int) $row['current_value'];
    }

    /**
     * MySQL atomic sequence-next via per-connection LAST_INSERT_ID.
     *
     * @param DatabaseAdapter $adapter The pinned adapter (same connection both calls).
     * @param string $seqName
     * @param string|null $table
     * @param string $pkColumn
     * @return int
     */
    private function sequenceNextMysql(DatabaseAdapter $adapter, string $seqName, ?string $table, string $pkColumn): int
    {
        // Race-safe seed: INSERT IGNORE is a no-op if the row already exists.
        $seed = $this->sequenceSeedValue($adapter, $table, $pkColumn);
        $adapter->execute(
            'INSERT IGNORE INTO tina4_sequences (seq_name, current_value) VALUES (?, ?)',
            [$seqName, $seed]
        );
        // LAST_INSERT_ID(expr) stashes expr in THIS connection's session var and
        // returns it — atomic per-connection, no read-back race.
        $adapter->execute(
            'UPDATE tina4_sequences SET current_value = LAST_INSERT_ID(current_value + 1) WHERE seq_name = ?',
            [$seqName]
        );
        $row = $adapter->fetchOne('SELECT LAST_INSERT_ID() AS next_id');
        if ($row === null) {
            throw new \RuntimeException(
                "getNextId: LAST_INSERT_ID() returned nothing for '{$seqName}'"
            );
        }
        return (int) ($row['next_id'] ?? reset($row));
    }

    /**
     * MSSQL atomic sequence-next via a single UPDATE ... OUTPUT statement.
     *
     * @param DatabaseAdapter $adapter
     * @param string $seqName
     * @param string|null $table
     * @param string $pkColumn
     * @return int
     */
    private function sequenceNextMssql(DatabaseAdapter $adapter, string $seqName, ?string $table, string $pkColumn): int
    {
        // Race-safe seed: INSERT only when absent (single statement).
        $seed = $this->sequenceSeedValue($adapter, $table, $pkColumn);
        $adapter->execute(
            'INSERT INTO tina4_sequences (seq_name, current_value) '
            . 'SELECT ?, ? WHERE NOT EXISTS '
            . '(SELECT 1 FROM tina4_sequences WHERE seq_name = ?)',
            [$seqName, $seed, $seqName]
        );
        // Single atomic statement: increment + return the new value via OUTPUT.
        // The MSSQL adapter's execute() doesn't surface the OUTPUT rows, so read
        // them through fetchOne() on the same pinned connection.
        $row = $adapter->fetchOne(
            'UPDATE tina4_sequences SET current_value = current_value + 1 '
            . 'OUTPUT inserted.current_value AS next_id WHERE seq_name = ?',
            [$seqName]
        );
        if ($row === null || !isset($row['next_id'])) {
            throw new \RuntimeException(
                "getNextId: OUTPUT produced no row for sequence '{$seqName}'"
            );
        }
        return (int) $row['next_id'];
    }

    /**
     * Generic fallback atomic-ish sequence-next (e.g. PostgreSQL sequence-table
     * fallback, or any other engine routed here). Seeds if absent via an
     * insert-if-absent, then increments and reads on the pinned connection.
     *
     * @param DatabaseAdapter $adapter
     * @param string $seqName
     * @param string|null $table
     * @param string $pkColumn
     * @return int
     */
    private function sequenceNextGeneric(DatabaseAdapter $adapter, string $seqName, ?string $table, string $pkColumn): int
    {
        $seed = $this->sequenceSeedValue($adapter, $table, $pkColumn);
        try {
            $adapter->execute(
                'INSERT INTO tina4_sequences (seq_name, current_value) VALUES (?, ?)',
                [$seqName, $seed]
            );
        } catch (\Throwable) {
            // Row likely already exists (PK conflict) — fine, keep going.
        }
        $adapter->execute(
            'UPDATE tina4_sequences SET current_value = current_value + 1 WHERE seq_name = ?',
            [$seqName]
        );
        $row = $adapter->fetchOne(
            'SELECT current_value FROM tina4_sequences WHERE seq_name = ?',
            [$seqName]
        );
        if ($row === null) {
            throw new \RuntimeException("getNextId: sequence row '{$seqName}' missing");
        }
        return (int) ($row['current_value'] ?? 1);
    }

    /**
     * Pre-generate the next available primary key ID using engine-aware strategies.
     *
     * - Firebird: auto-creates a generator if missing, then increments it via GEN_ID (atomic).
     * - PostgreSQL: tries nextval() first; if the sequence is missing, auto-creates it
     *   seeded from MAX(pk), then retries; falls through to sequence table on failure.
     * - SQLite/MySQL/MSSQL: uses a race-safe tina4_sequences table with atomic UPDATE.
     * - Returns 1 if the table is empty or does not exist.
     *
     * @param string $table Table name
     * @param string $pkColumn Primary key column name
     * @param string|null $generatorName Firebird generator name override
     * @return int The next available ID
     */
    public function getNextId(string $table, string $pkColumn = 'id', ?string $generatorName = null): int
    {
        // Pin ONE adapter for the whole operation so the sequence-table engines
        // that need two statements (MySQL LAST_INSERT_ID, MSSQL seed+OUTPUT) hit
        // the SAME connection. If we're already inside a transaction the adapter
        // is already pinned; otherwise pin here and release in the finally so
        // the pool can rotate afterwards. Parity with Python's _sequence_next.
        $alreadyPinned = $this->pinnedAdapter !== null;
        $adapter = $this->getNextAdapter();
        if (!$alreadyPinned) {
            $this->pinnedAdapter = $adapter;
        }
        try {
            return $this->getNextIdPinned($table, $pkColumn, $generatorName, $adapter);
        } finally {
            if (!$alreadyPinned) {
                $this->pinnedAdapter = null;
            }
        }
    }

    /**
     * getNextId() body, run with the adapter pinned. Resolves the raw driver so
     * engine dispatch works even when the query cache wrapper is active.
     *
     * @param string $table
     * @param string $pkColumn
     * @param string|null $generatorName
     * @param DatabaseAdapter $adapter The pinned (possibly cache-wrapped) adapter.
     * @return int
     */
    private function getNextIdPinned(string $table, string $pkColumn, ?string $generatorName, DatabaseAdapter $adapter): int
    {
        $raw = $adapter instanceof CachedDatabase ? $adapter->getAdapter() : $adapter;

        // Firebird — use generators (GEN_ID is atomic). Both the native adapter
        // and the pdo_firebird fallback speak Firebird, so match both.
        if ($raw instanceof FirebirdAdapter || $raw instanceof PdoFirebirdAdapter) {
            $genName = $generatorName ?? 'GEN_' . strtoupper($table) . '_ID';

            // Auto-create the generator if it does not exist
            try {
                $adapter->execute("CREATE GENERATOR {$genName}");
            } catch (\Throwable) {
                // Generator already exists — ignore
            }

            $row = $adapter->fetchOne("SELECT GEN_ID({$genName}, 1) AS NEXT_ID FROM RDB\$DATABASE");
            return (int) ($row['NEXT_ID'] ?? $row['next_id'] ?? 1);
        }

        // PostgreSQL — try nextval() first, auto-create sequence if missing
        if ($raw instanceof PostgresAdapter || $raw instanceof PdoPostgresAdapter) {
            $seqName = strtolower($table) . '_' . strtolower($pkColumn) . '_seq';
            try {
                $row = $adapter->fetchOne("SELECT nextval('{$seqName}') AS next_id");
                if ($row !== null && isset($row['next_id'])) {
                    return (int) $row['next_id'];
                }
            } catch (\Throwable) {
                // Sequence does not exist — try to create it seeded from MAX
                try {
                    $seed = 0;
                    $maxRow = $adapter->fetchOne("SELECT COALESCE(MAX({$pkColumn}), 0) AS max_id FROM {$table}");
                    if ($maxRow !== null) {
                        $seed = (int) ($maxRow['max_id'] ?? 0);
                    }

                    $adapter->execute("CREATE SEQUENCE {$seqName} START WITH " . ($seed + 1));
                    $row = $adapter->fetchOne("SELECT nextval('{$seqName}') AS next_id");
                    if ($row !== null && isset($row['next_id'])) {
                        return (int) $row['next_id'];
                    }
                } catch (\Throwable) {
                    // Fall through to sequence table
                }
            }

            // PostgreSQL fallback — use sequence table
            return $this->sequenceNext("{$table}.{$pkColumn}", $table, $pkColumn, $adapter);
        }

        // MongoDB — atomic findOneAndUpdate on tina4_sequences collection
        if ($raw instanceof MongoDBAdapter) {
            return $this->mongoNextId($table, $pkColumn, $raw);
        }

        // SQLite / MySQL / MSSQL — use race-safe sequence table
        return $this->sequenceNext("{$table}.{$pkColumn}", $table, $pkColumn, $adapter);
    }

    /**
     * Get the next ID for MongoDB using an atomic findOneAndUpdate on the
     * tina4_sequences collection (race-safe, no transactions required).
     *
     * @param string $table Table / collection name
     * @param string $pkColumn Primary key column name
     * @param MongoDBAdapter $adapter
     * @return int The next available ID
     */
    private function mongoNextId(string $table, string $pkColumn, MongoDBAdapter $adapter): int
    {
        $seqName = "{$table}.{$pkColumn}";
        $db = $adapter->getDatabase();

        if ($db === null) {
            return 1;
        }

        try {
            $sequences = $db->selectCollection('tina4_sequences');

            // Seed from the maximum existing value if sequence row does not exist yet
            $existing = $sequences->findOne(
                ['_id' => $seqName],
                ['typeMap' => ['root' => 'array', 'document' => 'array']]
            );

            if ($existing === null) {
                $seed = 0;
                try {
                    $collection = $db->selectCollection($table);
                    $maxDoc = $collection->findOne(
                        [],
                        [
                            'sort'    => [$pkColumn => -1],
                            'typeMap' => ['root' => 'array', 'document' => 'array'],
                        ]
                    );
                    if ($maxDoc !== null && isset($maxDoc[$pkColumn])) {
                        $seed = (int) $maxDoc[$pkColumn];
                    }
                } catch (\Throwable) {
                    // Collection may not exist yet
                }

                // upsert the seed so findOneAndUpdate below starts from the right value
                $sequences->updateOne(
                    ['_id' => $seqName],
                    ['$setOnInsert' => ['_id' => $seqName, 'current_value' => $seed]],
                    ['upsert' => true]
                );
            }

            // Atomic increment — findOneAndUpdate returns the document AFTER the update
            $result = $sequences->findOneAndUpdate(
                ['_id' => $seqName],
                ['$inc' => ['current_value' => 1]],
                [
                    'upsert'         => true,
                    'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                    'typeMap'        => ['root' => 'array', 'document' => 'array'],
                ]
            );

            return (int) ($result['current_value'] ?? 1);
        } catch (\Throwable $e) {
            // Fallback to 1 on any unexpected error
            return 1;
        }
    }

    /**
     * Execute a SQL statement with multiple parameter arrays in a single transaction.
     *
     * Each entry in $paramSets is passed to execute() as bound parameters.
     * If any execution fails, the transaction is rolled back and the exception is re-thrown.
     *
     * @param string $sql SQL statement with placeholders
     * @param array<int, array<mixed>> $paramSets Array of parameter arrays
     * @return array<int, bool> Array of execute() results
     * @throws \Exception If any execution fails (transaction is rolled back)
     */
    public function executeMany(string $sql, array $paramsList = []): int
    {
        $count = 0;
        // ONE round-trip per CHUNK instead of one per ROW. Looping execute()
        // here pays a full network round-trip for every row: 500 rows took
        // 9848ms on PostgreSQL against 15.8ms as a single multi-row VALUES
        // (625x), MySQL 216x, MSSQL 121x. buildBatchInserts() returns an empty
        // array for anything it cannot collapse safely — RETURNING, upserts,
        // non-INSERT statements, ragged rows, Firebird — and the row-at-a-time
        // loop below then runs unchanged.
        $engine = $this->getNextAdapter()->getDatabaseType();
        $batched = \Tina4\SQLTranslator::buildBatchInserts($sql, $paramsList, $engine);

        $this->startTransaction();
        try {
            if ($batched !== []) {
                foreach ($batched as [$chunkSql, $chunkParams]) {
                    $this->execute($chunkSql, $chunkParams);
                }
                // The collapse must be invisible: the count is the total ROW
                // count, never the number of statements run.
                $count = count($paramsList);
            } else {
                foreach ($paramsList as $params) {
                    $this->execute($sql, $params);
                    $count++;
                }
            }
            $this->commit();
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
        return $count;
    }

    /**
     * Get the last error message.
     */
    public function error(): ?string
    {
        return $this->getNextAdapter()->error();
    }

    // -------------------------------------------------------------------------
    // Static utility methods
    // -------------------------------------------------------------------------

    /**
     * Convenience alias for fromEnv().
     *
     * Open a database connection — convention name matching SQLAlchemy
     * `engine.connect()` and the cross-framework Database.get_connection()
     * surface (Python tina4_python.database.Database.get_connection).
     *
     *     $db = Database::getConnection();                       // from TINA4_DATABASE_URL env
     *     $db = Database::getConnection('CUSTOM_URL_ENV');       // from a different env var
     *     $db = Database::getConnection('sqlite:///app.db');     // explicit URL
     *     $db = Database::getConnection('postgres://...', username: 'u', password: 'p');
     *
     * The first argument may be either an env-var NAME (back-compat path)
     * or a connection URL — anything containing `:` is treated as a URL,
     * otherwise it's looked up via DotEnv. Falls back to in-memory SQLite
     * when no URL can be resolved.
     */
    public static function getConnection(string $urlOrEnvKey = 'TINA4_DATABASE_URL', ?bool $autoCommit = null, string $username = '', string $password = '', int $pool = 0): self
    {
        // Treat anything with a scheme separator as a URL; otherwise it's
        // an env var name to look up.
        if (str_contains($urlOrEnvKey, '://') || str_starts_with($urlOrEnvKey, 'sqlite:')) {
            return new self($urlOrEnvKey, $autoCommit, $username, $password, $pool);
        }

        $db = self::fromEnv($urlOrEnvKey, $autoCommit, $pool);
        if ($db !== null) {
            return $db;
        }
        // Fallback: in-memory SQLite — same as Python tina4_python's default.
        return new self('sqlite::memory:', $autoCommit, $username, $password, $pool);
    }

    /**
     * Get the list of supported database schemes.
     *
     * @return array<string>
     */
    public static function supportedSchemes(): array
    {
        return array_unique(array_keys(self::ADAPTER_MAP));
    }

    /**
     * Check if a scheme is supported.
     */
    public static function isSupported(string $scheme): bool
    {
        return isset(self::ADAPTER_MAP[strtolower($scheme)]);
    }

    // -------------------------------------------------------------------------
    // Query cache convenience
    // -------------------------------------------------------------------------

    /**
     * Wrap an adapter in CachedDatabase when query caching is active.
     *
     * Both cache layers are OPT-IN — the wrapper reads TINA4_AUTO_CACHING /
     * TINA4_DB_CACHE and their TTL env vars internally, so no explicit config
     * is needed here. The request-scoped layer DEFAULTS OFF (TINA4_AUTO_CACHING
     * unset → false): an on-by-default request cache is a footgun — a
     * `SELECT MAX(id)` (or generator read) right before an INSERT in the same
     * request would return a cached pre-write value and produce duplicate
     * primary keys; any read-after-write in one request would show stale state.
     * Opt in via TINA4_AUTO_CACHING=true for read-heavy endpoints. We only wrap
     * when at least one layer is enabled:
     *
     *   TINA4_AUTO_CACHING=true   OR  TINA4_DB_CACHE=true   → wrapper
     *   neither set                                          → no wrapper
     *
     * Mirrors Python: enabled = persistent || requestScoped (both default off).
     *
     * @param DatabaseAdapter $adapter
     * @return DatabaseAdapter Original adapter or CachedDatabase wrapper
     */
    private static function wrapWithCache(DatabaseAdapter $adapter): DatabaseAdapter
    {
        $persistent = \Tina4\DotEnv::isTruthy(\Tina4\DotEnv::getEnv('TINA4_DB_CACHE') ?? 'false');
        $requestScoped = \Tina4\DotEnv::isTruthy(\Tina4\DotEnv::getEnv('TINA4_AUTO_CACHING') ?? 'false');
        if ($persistent || $requestScoped) {
            return new CachedDatabase($adapter);
        }
        return $adapter;
    }

    /**
     * Get query cache statistics reflecting the real cache.
     *
     * @return array{enabled: bool, mode: string, hits: int, misses: int, size: int, ttl: int, backend: string}
     */
    public function cacheStats(): array
    {
        $adapter = $this->getNextAdapter();
        if ($adapter instanceof CachedDatabase) {
            return $adapter->cacheStats();
        }
        return [
            'enabled' => false,
            'mode' => 'off',
            'hits' => 0,
            'misses' => 0,
            'size' => 0,
            'ttl' => 0,
            'backend' => 'memory',
        ];
    }

    /**
     * Flush the query cache and reset counters.
     */
    public function cacheClear(): void
    {
        $adapter = $this->getNextAdapter();
        if ($adapter instanceof CachedDatabase) {
            $adapter->cacheClear();
        }
    }

    // -------------------------------------------------------------------------
    // Internal adapter factory
    // -------------------------------------------------------------------------

    /**
     * SILENT PDO fallback (SQLite): prefer ext-sqlite3 (the \SQLite3 class);
     * fall back to the pdo_sqlite driver when it is absent. Externally
     * identical — the developer gets a working DB either way.
     *
     * @throws \RuntimeException When neither ext-sqlite3 nor pdo_sqlite is present.
     */
    private static function makeSqlite(string $path, ?bool $autoCommit): DatabaseAdapter
    {
        if (class_exists('SQLite3')) {
            return new SQLite3Adapter($path, $autoCommit);
        }
        if (in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            return new PdoSqliteAdapter($path, $autoCommit);
        }
        throw new \RuntimeException(
            'SQLite requires ext-sqlite3 (the SQLite3 class) or the pdo_sqlite PDO driver. '
            . 'Install one with: brew install php (macOS), or apt-get install php-sqlite3 (Debian/Ubuntu).'
        );
    }

    /**
     * SILENT PDO fallback (PostgreSQL): prefer ext-pgsql (pg_connect); fall back
     * to the pdo_pgsql driver when it is absent.
     *
     * @throws \RuntimeException When neither ext-pgsql nor pdo_pgsql is present.
     */
    private static function makePostgres(string $url, ?bool $autoCommit, string $username, string $password): DatabaseAdapter
    {
        if (function_exists('pg_connect')) {
            return new PostgresAdapter($url, $autoCommit, username: $username, password: $password);
        }
        if (in_array('pgsql', \PDO::getAvailableDrivers(), true)) {
            return new PdoPostgresAdapter($url, $autoCommit, username: $username, password: $password);
        }
        throw new \RuntimeException(
            'PostgreSQL requires ext-pgsql (pg_connect) or the pdo_pgsql PDO driver. '
            . 'Install one with: apt-get install php-pgsql (Debian/Ubuntu) or brew install php (macOS).'
        );
    }

    /**
     * SILENT PDO fallback (Firebird): prefer ext-interbase (ibase_ / fbird_
     * functions), and fall back to the pdo_firebird driver when ext-interbase is
     * either ABSENT or PRESENT-BUT-BROKEN. ext-interbase was removed from PHP
     * core in 7.4 (PECL-only) and its macOS + Firebird 5 build is clumplet-broken
     * — ibase_connect exists yet every connect fails — so auto-mode tries native
     * first and, if that connect throws, transparently retries on pdo_firebird
     * ("ibase is broken -> use pdo"). A native failure is only surfaced when
     * there is no pdo_firebird to fall back to.
     *
     * Force a driver to skip the auto-detection: `TINA4_FIREBIRD_DRIVER=pdo`
     * (or `=interbase`) app-wide, or a `?driver=pdo` query param per connection.
     * Forcing pdo avoids the wasted native connect attempt on a known-broken host.
     *
     * @throws \RuntimeException When the requested — or the only remaining — driver is unavailable.
     */
    private static function makeFirebird(string $url, string $username, string $password, ?bool $autoCommit): DatabaseAdapter
    {
        $hasInterbase = function_exists('ibase_connect') || function_exists('fbird_connect');
        // Guard the PDO class: it can be absent (e.g. under `php -n`, or a build
        // without ext-pdo) — referencing \PDO unguarded would fatal instead of
        // giving the clear combined error below.
        $hasPdo = class_exists('PDO') && in_array('firebird', \PDO::getAvailableDrivers(), true);
        $forced = self::firebirdDriverPreference($url);

        if ($forced === 'pdo') {
            if (!$hasPdo) {
                throw new \RuntimeException(
                    'Firebird driver forced to pdo (TINA4_FIREBIRD_DRIVER/?driver=pdo) but the '
                    . 'pdo_firebird PDO driver is not installed.'
                );
            }
            return new PdoFirebirdAdapter($url, username: $username, password: $password, autoCommit: $autoCommit);
        }
        if ($forced === 'interbase') {
            if (!$hasInterbase) {
                throw new \RuntimeException(
                    'Firebird driver forced to interbase (TINA4_FIREBIRD_DRIVER/?driver=interbase) but '
                    . 'ext-interbase (ibase_*/fbird_* functions) is not available.'
                );
            }
            return new FirebirdAdapter($url, username: $username, password: $password, autoCommit: $autoCommit);
        }

        // Auto: prefer the native extension. If ext-interbase is PRESENT but
        // cannot connect — the macOS + Firebird 5 case, where the loaded
        // interbase build is clumplet-broken and every connect fails — fall
        // through to pdo_firebird instead of failing. This is the whole point of
        // a silent fallback: "ibase is broken -> use pdo". Only re-throw the
        // native error when there is no pdo_firebird to fall back to, so a real
        // misconfig (bad credentials, server down) still surfaces its cause.
        if ($hasInterbase) {
            try {
                return new FirebirdAdapter($url, username: $username, password: $password, autoCommit: $autoCommit);
            } catch (\Throwable $nativeError) {
                if (!$hasPdo) {
                    throw $nativeError;
                }
                \Tina4\Log::warning(
                    'Firebird: ext-interbase is installed but failed to connect ('
                    . $nativeError->getMessage() . '); falling back to pdo_firebird. '
                    . 'Set TINA4_FIREBIRD_DRIVER=pdo to skip the native attempt.'
                );
                // fall through to the pdo_firebird path below
            }
        }
        if ($hasPdo) {
            return new PdoFirebirdAdapter($url, username: $username, password: $password, autoCommit: $autoCommit);
        }
        throw new \RuntimeException(
            'Firebird requires ext-interbase (ibase_*/fbird_* functions) or the pdo_firebird PDO driver. '
            . 'ext-interbase was removed from PHP core in 7.4 (PECL-only); enable pdo_firebird instead.'
        );
    }

    /**
     * Resolve an explicit Firebird driver choice: a `?driver=` URL query param
     * (per-connection, wins) then the `TINA4_FIREBIRD_DRIVER` env var (app-wide).
     * Returns 'pdo', 'interbase', or '' (auto). `ibase` is accepted as a synonym
     * for 'interbase'.
     */
    private static function firebirdDriverPreference(string $url): string
    {
        $raw = '';
        if (str_contains($url, '://')) {
            $query = parse_url($url, PHP_URL_QUERY);
            if (is_string($query) && $query !== '') {
                parse_str($query, $params);
                $raw = (string) ($params['driver'] ?? '');
            }
        }
        if ($raw === '') {
            $raw = (string) (\Tina4\DotEnv::getEnv('TINA4_FIREBIRD_DRIVER') ?? '');
        }
        $raw = strtolower(trim($raw));
        return match ($raw) {
            'pdo', 'pdo_firebird' => 'pdo',
            'interbase', 'ibase', 'firebird' => 'interbase',
            default => '',
        };
    }

    /**
     * Create the raw DatabaseAdapter from a connection URL string.
     *
     * @param string $url Connection URL
     * @param bool|null $autoCommit Override auto-commit setting
     * @return DatabaseAdapter
     * @throws \InvalidArgumentException If the URL scheme is unsupported
     * @throws \RuntimeException If the required PHP extension is missing
     */
    private static function createAdapter(string $url, ?bool $autoCommit = null, string $username = '', string $password = ''): DatabaseAdapter
    {
        // Handle SQLite special cases
        if ($url === ':memory:' || $url === 'sqlite::memory:' || $url === 'sqlite:///:memory:') {
            return self::makeSqlite(':memory:', $autoCommit);
        }

        if (str_starts_with($url, 'sqlite:')) {
            // Strip the scheme on the RAW string (sqlite:/// -> sqlite:// -> sqlite:),
            // matching DatabaseUrl and tina4-python/ruby/nodejs. This keeps the documented
            // forms (three slashes = relative to cwd, four = absolute) AND makes a one-slash
            // absolute path (sqlite:/abs/app.db) resolve absolute instead of being mis-read
            // as a bare "sqlite:/abs/app.db" filename by the .db branch below (the footgun).
            //   sqlite:///data/app.db  -> "data/app.db"   (relative)
            //   sqlite:////abs/app.db  -> "/abs/app.db"   (absolute)
            //   sqlite:/abs/app.db     -> "/abs/app.db"   (absolute — the fix)
            //   sqlite:///C:/Users/app -> "C:/Users/app"  (Windows absolute)
            if (str_starts_with($url, 'sqlite:///')) {
                $path = substr($url, 10);   // "sqlite:///" is 10 chars
            } elseif (str_starts_with($url, 'sqlite://')) {
                $path = substr($url, 9);
            } else {
                $path = substr($url, 7);    // "sqlite:"
            }
            // Windows: drop the leading "/" before a drive letter (sqlite:///C:/...).
            if (preg_match('/^\/[A-Za-z]:/', $path)) {
                $path = substr($path, 1);
            }
            return self::makeSqlite($path === '' ? ':memory:' : $path, $autoCommit);
        }

        // For a bare file path ending in .db or .sqlite, assume SQLite
        if (!str_contains($url, '://') && preg_match('/\.(db|sqlite|sqlite3)$/i', $url)) {
            return self::makeSqlite($url, $autoCommit);
        }

        // Parse standard URL
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'])) {
            throw new \InvalidArgumentException(
                "Database: Cannot determine database type from '{$url}'. "
                . "Use a URL like 'postgres://user:pass@host/db' or 'sqlite:///path/to/db'."
            );
        }

        $scheme = strtolower($parts['scheme']);

        if (!isset(self::ADAPTER_MAP[$scheme])) {
            throw new \InvalidArgumentException(
                "Database: Unsupported database scheme '{$scheme}'. "
                . 'Supported: ' . implode(', ', array_unique(array_keys(self::ADAPTER_MAP)))
            );
        }

        $adapterClass = self::ADAPTER_MAP[$scheme];

        return match ($adapterClass) {
            SQLite3Adapter::class => self::makeSqlite(
                ltrim($parts['path'] ?? ':memory:', '/'),
                $autoCommit,
            ),
            PostgresAdapter::class => self::makePostgres($url, $autoCommit, $username, $password),
            MySQLAdapter::class => new MySQLAdapter($url, username: $username, password: $password, autoCommit: $autoCommit),
            MSSQLAdapter::class => new MSSQLAdapter($url, username: $username, password: $password, autoCommit: $autoCommit),
            FirebirdAdapter::class => self::makeFirebird(
                $url,
                $username !== '' ? $username : (isset($parts['user']) ? urldecode($parts['user']) : 'SYSDBA'),
                $password !== '' ? $password : (isset($parts['pass']) ? urldecode($parts['pass']) : 'masterkey'),
                $autoCommit,
            ),
            MongoDBAdapter::class => new MongoDBAdapter(
                $url,
                username: $username,
                password: $password,
                autoCommit: $autoCommit,
            ),
            ODBCAdapter::class => new ODBCAdapter(
                // Strip leading slashes from the path — everything after odbc:/// is the DSN/connection string
                ltrim($parts['path'] ?? '', '/'),
                username: $username !== '' ? $username : (isset($parts['user']) ? urldecode($parts['user']) : ''),
                password: $password !== '' ? $password : (isset($parts['pass']) ? urldecode($parts['pass']) : ''),
                autoCommit: $autoCommit,
            ),
        };
    }
}
