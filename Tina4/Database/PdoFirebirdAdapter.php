<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * Firebird database adapter using PHP's built-in **pdo_firebird** driver.
 *
 * The legacy {@see FirebirdAdapter} talks to the C API through the deprecated
 * ext-interbase (`ibase_*`) extension, which was removed from PHP core in 7.4
 * and is unmaintained. Its build ships compiled against the Firebird 3 API, so
 * on a Firebird 4+ server any aggregate result that the engine widens to the
 * new 128-bit type (`SUM()`/`AVG()` over an exact numeric → `INT128`, or a
 * `DECFLOAT`) walks off the end of the extension's type table and hard-crashes
 * the PHP process (`0xC0000005` / `SIGSEGV`) — a native fault no `try/catch`
 * can trap. `pdo_firebird` (a first-class, maintained core driver) understands
 * the Firebird 4 types and reads an `INT128`/`DECFLOAT` back as a string, so it
 * is the correct modern transport.
 *
 * This adapter **extends** `FirebirdAdapter` deliberately: the framework
 * dispatches Firebird-specific behaviour (the `GEN_ID` generator path in
 * `Database::getNextId()`, the Firebird DDL branch in `Migration`, and
 * `ORM`/`Migration` dialect detection) via `instanceof FirebirdAdapter`.
 * Sharing the type identity keeps every one of those sites working unchanged.
 * The entire I/O surface (connect, query, fetch, execute, transactions) is
 * overridden here to run through PDO; the parent's ibase-only methods are never
 * reached. The `#121`/`#123` ibase workarounds (fetch_assoc-on-int TypeError,
 * the null-bind XSQLDA fault) simply do not exist on the PDO path.
 *
 * Firebird specifics (unchanged from the parent):
 *   - No LIMIT/OFFSET — uses `ROWS X TO Y`
 *   - Auto-increment via generators (`GEN_ID(gen, 1)`) — see Database::getNextId
 *   - RETURNING is supported (Firebird 2.0+)
 */
class PdoFirebirdAdapter extends FirebirdAdapter
{
    /**
     * Live PDO handle. Named distinctly from the parent's ibase `$db` resource
     * so the two transports never alias — this adapter only ever touches PDO.
     */
    private ?\PDO $pdo = null;

    /**
     * This class's own error/state. The parent declares private members of the
     * same purpose for its ibase path; because every state-touching method is
     * overridden here, only these are ever read or written on a PDO instance.
     */
    private ?string $lastError = null;
    private bool $autoCommit;
    private int|string $lastId = 0;

    private string $connString;
    private string $dbUser;
    private string $dbPass;
    private string $dbCharset;

    /**
     * @param string    $connectionString URL: "firebird://user:pass@host:port/path/to/db.fdb" or a path
     * @param string    $username         Username (default: SYSDBA)
     * @param string    $password         Password (default: masterkey)
     * @param string    $charset          Character set (default: UTF8)
     * @param bool|null $autoCommit       Whether a standalone write commits on its own
     */
    public function __construct(
        string $connectionString,
        string $username = 'SYSDBA',
        string $password = 'masterkey',
        string $charset = 'UTF8',
        ?bool $autoCommit = null,
    ) {
        // NB: intentionally does NOT call parent::__construct() — the parent
        // constructor requires the ibase extension and opens an ibase handle.
        if (!extension_loaded('pdo_firebird') || !in_array('firebird', \PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException(
                'PdoFirebirdAdapter requires the pdo_firebird PHP extension (PDO Firebird driver). '
                . 'Enable it with extension=pdo_firebird in php.ini.'
            );
        }

        $this->connString = $connectionString;
        $this->dbUser = $username;
        $this->dbPass = $password;
        $this->dbCharset = $charset;

        $envAutoCommit = \Tina4\DotEnv::getEnv('TINA4_AUTOCOMMIT');
        $this->autoCommit = $autoCommit ?? ($envAutoCommit !== null ? filter_var($envAutoCommit, FILTER_VALIDATE_BOOLEAN) : true);
        $this->open();
    }

    public function open(): void
    {
        if ($this->pdo !== null) {
            return;
        }

        $params = $this->parsePdoConnection($this->connString);

        // PDO Firebird DSN: firebird:dbname=HOST/PORT:DATABASE;charset=UTF8
        // (host/port omitted for a local/embedded database).
        $dbSpec = $params['database'];
        if ($params['host'] !== '') {
            $port = $params['port'] > 0 ? $params['port'] : 3050;
            $dbSpec = "{$params['host']}/{$port}:{$params['database']}";
        }
        $dsn = "firebird:dbname={$dbSpec};charset={$this->dbCharset}";

        try {
            $this->pdo = new \PDO($dsn, $params['username'], $params['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            throw new \RuntimeException("PdoFirebirdAdapter: Failed to connect: {$this->lastError}");
        }
    }

    public function close(): void
    {
        // PDO closes the connection when the handle is released.
        $this->pdo = null;
    }

    public function query(string $sql, array $params = []): array
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            $stmt = $this->runStatement($sql, $params);

            // Non-result statements (DDL, DML without RETURNING) have no columns.
            if ($stmt->columnCount() === 0) {
                return [];
            }

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return array_map([$this, 'cleanRow'], $rows);
        } catch (\PDOException $e) {
            // Read path swallows to [] and records the cause — parity with the
            // ibase adapter's query() (fetch()/fetchOne()/execute() FAIL LOUD).
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function fetch(string $sql, array $params = [], int $limit = 100, int $offset = 0): array
    {
        $this->ensureOpen();
        $this->lastError = null;
        // Strip trailing `;` before the COUNT(*) wrap + ROWS pagination.
        $sql = self::stripTrailingSemicolons($sql);

        // FAIL LOUD (DB-contract A): the COUNT probe is best-effort (its own
        // query() call, its failure only defaults total to 0), but the MAIN
        // paginated query RAISES on a bad statement rather than swallowing to [].
        $total = 0;
        $countResult = $this->query("SELECT COUNT(*) AS total FROM ({$sql})", $params);
        if ($this->lastError === null) {
            $total = (int) ($countResult[0]['TOTAL'] ?? $countResult[0]['total'] ?? 0);
        }

        $this->lastError = null;
        if ($limit <= 0) {
            $pagedSql = $sql;
        } else {
            $startRow = $offset + 1;
            $endRow = $offset + $limit;
            $pagedSql = "{$sql} ROWS {$startRow} TO {$endRow}";
        }
        $data = $this->query($pagedSql, $params);
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
        // FAIL LOUD (DB-contract A): a non-null lastError after query() means the
        // statement failed — raise instead of returning null ("no row").
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

        try {
            $stmt = $this->runStatement($sql, $params);

            // A real result set (SELECT / RETURNING / EXECUTE PROCEDURE) carries a
            // row we can read for lastId. A plain INSERT/UPDATE/DELETE has none.
            if ($stmt->columnCount() > 0) {
                $row = $stmt->fetch(\PDO::FETCH_NUM);
                if (is_array($row) && array_key_exists(0, $row)) {
                    $first = $row[0];
                    $this->lastId = is_string($first) ? rtrim($first) : $first;
                }
            }

            return true;
        } catch (\PDOException $e) {
            // FAIL LOUD: capture the cause on error() AND raise (parity with the
            // Python master and with fetch()/fetchOne()).
            $this->lastError = $e->getMessage();
            throw new DatabaseException('Firebird execute() failed: ' . $this->lastError, 0, $e);
        }
    }

    public function lastInsertId(): int|string
    {
        return $this->lastId;
    }

    public function startTransaction(): void
    {
        $this->ensureOpen();
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
     * The underlying PDO handle (parity with the parent's getConnection()).
     */
    public function getConnection(): mixed
    {
        return $this->pdo;
    }

    public function getDatabase(): string
    {
        return $this->connString;
    }

    // ── Private helpers ──────────────────────────────────────────────

    private function ensureOpen(): void
    {
        if ($this->pdo === null) {
            $this->open();
        }
    }

    /**
     * Prepare + execute a statement, with a one-shot reconnect-and-retry on a
     * dead connection (idle Firebird sockets die behind NAT / server idle
     * timeout / Docker network rotation). Skipped inside an explicit
     * transaction — atomicity beats resilience; the caller handles rollback.
     *
     * @param array<int|string, mixed> $params
     */
    private function runStatement(string $sql, array $params): \PDOStatement
    {
        try {
            return $this->prepareAndExecute($sql, $params);
        } catch (\PDOException $e) {
            if (!FirebirdAdapter::isDeadConnection($e->getMessage()) || $this->pdo->inTransaction()) {
                throw $e;
            }
            $this->reconnect();
            return $this->prepareAndExecute($sql, $params);
        }
    }

    /**
     * Single-attempt prepare + bind + execute.
     *
     * @param array<int|string, mixed> $params
     */
    private function prepareAndExecute(string $sql, array $params): \PDOStatement
    {
        if (!empty($params)) {
            // Normalise :named placeholders (ORM/QueryBuilder) to positional ?,
            // matching the parent — PDO Firebird then binds by position.
            [$sql, $params] = \Tina4\SqlTranslation::namedToPositional($sql, $params);
        }
        // Firebird has no native boolean (BooleanField is INTEGER); bind PHP
        // booleans as 1/0. PDO binds a null param as SQL NULL natively — so the
        // ibase #123 null-rewrite is not needed here.
        $values = self::normalizeBoolParams(array_values($params), nativeBoolean: false);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        return $stmt;
    }

    /**
     * Trim CHAR padding from strings and materialise BLOB streams into bytes —
     * matching the ibase adapter's query() row cleaning so callers see the same
     * shapes regardless of transport.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function cleanRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_resource($value)) {
                // pdo_firebird returns BLOB columns as stream resources.
                $row[$key] = stream_get_contents($value) ?: '';
            } elseif (is_string($value)) {
                $row[$key] = rtrim($value);
            }
        }
        return $row;
    }

    /**
     * Force-close the stale handle and reopen. Idempotent.
     */
    private function reconnect(): void
    {
        $this->pdo = null;
        $this->open();
    }

    /**
     * Parse a connection URL (or path) into host/port/username/password/database.
     *
     * Mirrors the parent's private parseConnection(): honours the
     * TINA4_DATABASE_FIREBIRD_PATH override and normalises the URL path into a
     * Firebird database identifier via the parent's public static helper.
     *
     * @return array{host: string, port: int, username: string, password: string, database: string}
     */
    private function parsePdoConnection(string $input): array
    {
        $envOverride = \Tina4\DotEnv::getEnv('TINA4_DATABASE_FIREBIRD_PATH');

        if (str_contains($input, '://')) {
            $parts = parse_url($input);
            $rawPath = $parts['path'] ?? '';
            $database = ($envOverride !== null && $envOverride !== '')
                ? $envOverride
                : FirebirdAdapter::normalizeDbIdentifier($rawPath);
            return [
                'host' => $parts['host'] ?? '',
                'port' => (int) ($parts['port'] ?? 3050),
                'username' => isset($parts['user']) ? urldecode($parts['user']) : $this->dbUser,
                'password' => isset($parts['pass']) ? urldecode($parts['pass']) : $this->dbPass,
                'database' => $database,
            ];
        }

        return [
            'host' => '',
            'port' => 3050,
            'username' => $this->dbUser,
            'password' => $this->dbPass,
            'database' => ($envOverride !== null && $envOverride !== '') ? $envOverride : $input,
        ];
    }
}
