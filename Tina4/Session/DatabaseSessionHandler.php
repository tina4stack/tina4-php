<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Database Session Handler — stores sessions in a SQL database table
 * using the Tina4 DatabaseAdapter. Zero external dependencies.
 *
 * Every query is PARAMETERISED — the client-controlled session_id (the value of
 * the tina4_session cookie) and the JSON-encoded data are bound as parameters,
 * never string-interpolated into SQL. This matches the Python/Ruby/Node DB
 * session handlers and closes a SQL-injection hole that a crafted cookie could
 * otherwise exploit.
 *
 * Environment variables:
 *   TINA4_DATABASE_URL        — database connection URL (used by Database::fromEnv())
 *   TINA4_SESSION_TTL         — session TTL in seconds (default: 3600)
 */

namespace Tina4\Session;

use Tina4\Database\DatabaseAdapter;
use Tina4\Database\Database;

class DatabaseSessionHandler
{
    /**
     * CREATE TABLE per engine. The only genuinely per-engine SQL in this class -
     * every other statement is written once with `?` placeholders and rewritten
     * for the driver by the adapter.
     *
     * The COLUMNS are identical everywhere (session_id, data, expires_at)
     * because the table is a cross-framework contract: a tina4_session table
     * written by tina4-python has to be readable by tina4-php. Only the type
     * spellings and the "create it only if absent" idiom differ. The shapes come
     * from Node's databaseHandler.ts, the reference implementation under
     * ADR-0004.
     *
     * WHY THIS IS NO LONGER ONE GENERIC STATEMENT. It used to be
     * "session_id VARCHAR(255) PRIMARY KEY, data TEXT NOT NULL, expires_at
     * DOUBLE PRECISION NOT NULL" for every engine. Measured against live
     * servers, that statement is wrong on two of the five: SQL Server accepts
     * TEXT only as a deprecated type it has been documented as removing since
     * 2012, and Firebird 5.0.4 rejects TEXT outright (SQL error -607,
     * "Specified domain or source column TEXT does not exist") as well as
     * IF NOT EXISTS (SQL error -104, "Token unknown ... NOT").
     *
     * @var array<string, string>
     */
    private const CREATE_TABLE = [
        // SQLite types are AFFINITIES, so this is the same physical table an
        // older tina4-php wrote with VARCHAR(255)/TEXT/DOUBLE PRECISION -
        // VARCHAR(n) carries TEXT affinity and DOUBLE PRECISION carries REAL
        // affinity. Combined with IF NOT EXISTS never rewriting a table that is
        // already there, an existing deployment keeps working untouched; the
        // spelling simply matches the other three frameworks now.
        'sqlite' => <<<'SQL'
            CREATE TABLE IF NOT EXISTS tina4_session (
                session_id TEXT PRIMARY KEY,
                data TEXT NOT NULL,
                expires_at REAL NOT NULL
            )
            SQL,
        'postgres' => <<<'SQL'
            CREATE TABLE IF NOT EXISTS tina4_session (
                session_id VARCHAR(255) PRIMARY KEY,
                data TEXT NOT NULL,
                expires_at DOUBLE PRECISION NOT NULL
            )
            SQL,
        // MySQL has no DOUBLE PRECISION spelling of its own; DOUBLE is it.
        'mysql' => <<<'SQL'
            CREATE TABLE IF NOT EXISTS tina4_session (
                session_id VARCHAR(255) PRIMARY KEY,
                data TEXT NOT NULL,
                expires_at DOUBLE NOT NULL
            )
            SQL,
        // T-SQL has no CREATE TABLE IF NOT EXISTS; the catalog check is the
        // idiom. NVARCHAR/NVARCHAR(MAX)/FLOAT are the supported spellings -
        // VARCHAR is not Unicode and TEXT is deprecated.
        //
        // The IF OBJECT_ID is a CHEAP FIRST FILTER, NOT the race guard, and it
        // is important not to read it as one: it is check-then-act, so two
        // connections can both see NULL and both CREATE, which is exactly the
        // "Msg 2714: There is already an object named 'tina4_session'" measured
        // on live SQL Server 2022. It is kept because it costs nothing, keeps
        // this statement identical to Node's, and in the common concurrent case
        // avoids RAISING at all - which matters because a raised statement
        // leaves an open transaction aborted on some engines. What actually
        // makes concurrent first use safe is the catch in ensureTable(): Node's
        // TYPES plus PHP's CATCH is strictly better than either alone, because
        // the types make the statement legal on every engine and the catch
        // closes the window the types cannot.
        'mssql' => <<<'SQL'
            IF OBJECT_ID(N'tina4_session', N'U') IS NULL
            CREATE TABLE tina4_session (
                session_id NVARCHAR(255) NOT NULL PRIMARY KEY,
                data NVARCHAR(MAX) NOT NULL,
                expires_at FLOAT NOT NULL
            )
            SQL,
        // Firebird has neither IF NOT EXISTS nor a TEXT type, so the catalog
        // check goes in an EXECUTE BLOCK and the payload is a VARCHAR. The block
        // is one statement to a driver - the SET TERM dance is an isql artifact
        // and is not needed here.
        //
        // A VARCHAR rather than BLOB SUB_TYPE TEXT, matching Node deliberately:
        // a blob comes back from the driver as a handle rather than a string,
        // which read() would not understand. The cost is a session payload
        // ceiling of 8191 characters on this engine alone.
        //
        // VERIFIED END TO END THROUGH THE PHP FIREBIRD DRIVER against the live lab
        // Firebird 5.0.4 (pdo_firebird): a nested session payload written by one
        // handler and read back by a FRESH one round-trips intact through this
        // VARCHAR column, the row is present out of band in RDB$RELATION_FIELDS,
        // and the table is created idempotently on a second run
        // (tests/SessionDatabaseFirebirdTest.php). Its idempotence is NOT a race
        // guard (check-then-act inside one
        // block): a bare CREATE with the table present gives SQLSTATE 42S01
        // "Table TINA4_SESSION already exists", so the catch is load-bearing
        // here exactly as it is on SQL Server.
        'firebird' => <<<'SQL'
            EXECUTE BLOCK AS BEGIN
              IF (NOT EXISTS(SELECT 1 FROM RDB$RELATIONS WHERE RDB$RELATION_NAME = 'TINA4_SESSION')) THEN
                EXECUTE STATEMENT 'CREATE TABLE TINA4_SESSION (SESSION_ID VARCHAR(255) NOT NULL PRIMARY KEY, DATA VARCHAR(8191) NOT NULL, EXPIRES_AT DOUBLE PRECISION NOT NULL)';
            END
            SQL,
    ];

    /**
     * What to send to an adapter this class has no measured DDL for - ODBC, or
     * anything third-party that implements DatabaseAdapter.
     *
     * It is the exact statement that shipped for EVERY engine before the per-
     * engine table above, so an adapter outside the measured five is no worse
     * off than it was, and an unrecognised engine is never handed SQLite's
     * types on a guess.
     */
    private const CREATE_TABLE_FALLBACK = 'CREATE TABLE tina4_session ('
        . 'session_id VARCHAR(255) PRIMARY KEY, '
        . 'data TEXT NOT NULL, '
        . 'expires_at DOUBLE PRECISION NOT NULL'
        . ')';

    /** @var DatabaseAdapter|null Resolved on FIRST USE, never in the constructor. */
    private ?DatabaseAdapter $db = null;

    /** @var mixed The caller's config['db'], held unresolved until first use. */
    private mixed $configuredDb;

    private int $ttl;
    private bool $tableCreated = false;

    /**
     * @param array $config Configuration overrides:
     *   'db'  => DatabaseAdapter  An existing database adapter instance
     *   'ttl' => int              Session TTL in seconds
     */
    public function __construct(array $config = [])
    {
        $this->ttl = (int)($config['ttl'] ?? (getenv('TINA4_SESSION_TTL') ?: 3600));

        // NO NETWORK I/O IN A CONSTRUCTOR (session contract #4, ADR-0021). This
        // used to call Database::fromEnv() right here, and EVERY Tina4 adapter
        // constructor calls $this->open() - a real pg_connect / mysqli /
        // sqlsrv_connect / ibase_connect. So merely constructing the handler
        // dialled the database. MEASURED against a real counting TCP listener:
        // constructing it accepted 1 connection before this was made lazy.
        //
        // A constructor sits OUTSIDE the log-loud-and-degrade policy, so what it
        // does cannot be logged, cannot be degraded, and cannot be re-raised by
        // TINA4_SESSION_STRICT - an unreachable database took the app down at
        // construction instead of degrading per request, which is the very
        // scenario the policy exists for. The config is held as given and
        // resolved by db() on first use, inside the policy.
        $this->configuredDb = $config['db'] ?? null;
    }

    /**
     * Resolve the database adapter, on FIRST USE rather than at construction.
     *
     * A Database wrapper satisfies DatabaseAdapter, so an injected wrapper is
     * kept AS GIVEN (its pool, cache and transaction bookkeeping stay in play)
     * exactly as before; only the env fallback builds a connection, and it now
     * does so here instead of in the constructor.
     *
     * @return DatabaseAdapter The adapter every query runs through
     * @throws \RuntimeException When no adapter was injected and no
     *         TINA4_DATABASE_URL is configured
     */
    private function db(): DatabaseAdapter
    {
        if ($this->db !== null) {
            return $this->db;
        }

        if ($this->configuredDb instanceof DatabaseAdapter) {
            return $this->db = $this->configuredDb;
        }

        $database = Database::fromEnv();
        if ($database === null) {
            throw new \RuntimeException(
                'DatabaseSessionHandler: No database connection available. '
                . 'Pass a DatabaseAdapter via config["db"] or set the TINA4_DATABASE_URL env var.'
            );
        }

        return $this->db = $database->getAdapter();
    }

    /**
     * Read session data by session ID.
     *
     * @param string $sessionId The session ID
     * @return array|null Session data or null if not found / expired
     */
    public function read(string $sessionId): ?array
    {
        $this->ensureTable();

        $result = $this->db()->fetch(
            "SELECT data, expires_at FROM tina4_session WHERE session_id = ?",
            [$sessionId],
            1
        );

        $row = $this->firstRow($result);
        if ($row === null) {
            return null;
        }

        $expiresAt = (float)($row['expiresAt'] ?? $row['expires_at'] ?? 0);

        // An absent or zero expiry means "never expires" and is guarded OUT of
        // the comparison. Without the `> 0` test a row carrying no expiry (0)
        // is judged expired against every clock and destroyed on read.
        if ($expiresAt > 0 && $expiresAt < microtime(true)) {
            // Session expired — clean it up
            $this->destroy($sessionId);
            return null;
        }

        $data = json_decode($row['data'] ?? '', true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * Write session data (insert or update).
     *
     * @param string $sessionId The session ID
     * @param array  $data      Session data to store
     */
    public function write(string $sessionId, array $data, int $ttl = 0): void
    {
        $this->ensureTable();

        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES);
        // A per-call $ttl WINS over the handler default; a ttl of 0 means "never
        // expires" and is stored as the absolute 0 that read() guards out.
        $effectiveTtl = $ttl > 0 ? $ttl : $this->ttl;
        $expiresAt = $effectiveTtl > 0 ? microtime(true) + $effectiveTtl : 0.0;

        // Check if session already exists — parameterised.
        $existing = $this->db()->fetch(
            "SELECT session_id FROM tina4_session WHERE session_id = ?",
            [$sessionId],
            1
        );

        if ($this->firstRow($existing) !== null) {
            $this->db()->execute(
                "UPDATE tina4_session SET data = ?, expires_at = ? WHERE session_id = ?",
                [$encoded, $expiresAt, $sessionId]
            );
        } else {
            $this->db()->execute(
                "INSERT INTO tina4_session (session_id, data, expires_at) VALUES (?, ?, ?)",
                [$sessionId, $encoded, $expiresAt]
            );
        }

        $this->db()->commit();
    }

    /**
     * Delete a session.
     *
     * @param string $sessionId The session ID
     */
    public function delete(string $sessionId): void
    {
        $this->destroy($sessionId);
    }

    /**
     * Destroy a session (alias used internally).
     *
     * @param string $sessionId The session ID
     */
    public function destroy(string $sessionId): void
    {
        $this->ensureTable();

        $this->db()->execute(
            "DELETE FROM tina4_session WHERE session_id = ?",
            [$sessionId]
        );
        $this->db()->commit();
    }

    /**
     * Garbage-collect expired sessions from the database.
     *
     * @param int $ttl Session TTL in seconds (unused — expiry is absolute)
     */
    public function gc(int $ttl): void
    {
        $this->ensureTable();

        $now = microtime(true);
        $this->db()->execute(
            "DELETE FROM tina4_session WHERE expires_at > 0 AND expires_at < ?",
            [$now]
        );
        $this->db()->commit();
    }

    /**
     * Close the handler (no-op for database — connection managed externally).
     */
    public function close(): void
    {
        // Nothing to do — the DatabaseAdapter manages its own connection lifecycle.
    }

    /**
     * Normalise the first row of a fetch() result.
     *
     * A raw DatabaseAdapter::fetch() returns an array shaped
     * ['data' => [ assoc-rows... ], 'total' => n, ...]; the Database wrapper
     * returns a DatabaseResult whose ->records holds the same assoc rows.
     * Return the first associative row, or null when there are none.
     *
     * @return array<string,mixed>|null
     */
    private function firstRow(mixed $result): ?array
    {
        if ($result === null) {
            return null;
        }

        if (is_array($result)) {
            $rows = $result['data'] ?? [];
        } elseif (is_object($result) && isset($result->records)) {
            $rows = $result->records;
        } else {
            return null;
        }

        if (empty($rows)) {
            return null;
        }

        $row = $rows[0];
        return is_array($row) ? $row : (array)$row;
    }

    /**
     * Which engine's CREATE TABLE to send.
     *
     * Unwraps the Database / CachedDatabase facades down to the concrete adapter
     * and then ASKS IT WHAT IT IS, the same way ORM::detectDialect() and
     * Migration::detectDialect() already do. Asking beats matching a list of
     * class names: a new adapter is handled here without this file being edited,
     * and an adapter that answers something with no measured DDL (odbc, or
     * anything third-party) falls back to the portable statement rather than
     * being handed SQLite's types on a guess.
     *
     * @return string sqlite | postgres | mysql | mssql | firebird, or whatever
     *                the adapter calls itself when it is none of those
     */
    private function engineName(): string
    {
        $adapter = $this->db();
        while (is_object($adapter) && method_exists($adapter, 'getAdapter')) {
            $next = $adapter->getAdapter();
            if ($next === $adapter || !$next instanceof DatabaseAdapter) {
                break;
            }
            $adapter = $next;
        }

        $declared = method_exists($adapter, 'getDatabaseType') ? $adapter->getDatabaseType() : null;
        $engine = is_string($declared) ? $declared : '';

        // Both PostgreSQL adapters call themselves 'postgresql'; the canonical
        // engine name (DatabaseUrl::$engine, and Node's key) is 'postgres'.
        return $engine === 'postgresql' ? 'postgres' : $engine;
    }

    /**
     * Ensure the session table exists (creates it on first call).
     *
     * THREE THINGS GUARD THIS, and only one of them is the race guard.
     *
     * 1. tableExists() below. It is also what lets a LEAST-PRIVILEGED database
     *    user work: a session user with no CREATE grant against an already
     *    provisioned table never issues DDL at all.
     * 2. The engine's own idempotency inside the statement - IF NOT EXISTS on
     *    SQLite/PostgreSQL/MySQL, IF OBJECT_ID on SQL Server, the RDB$RELATIONS
     *    check on Firebird. Every one of those is CHECK-THEN-ACT and has a
     *    window; none of them is a race guard.
     * 3. The catch. THIS is the race guard, and it is engine-agnostic: two
     *    workers both pass (1) and (2), both issue CREATE TABLE, and the loser
     *    errors. It RE-CHECKS rather than parsing the error message, because
     *    "already exists" is spelled differently by every engine (Msg 2714 on
     *    SQL Server, SQLSTATE 42S01 on Firebird, a pg_type unique-index
     *    violation on PostgreSQL) and a string match would rot.
     *
     * Node's DDL plus this catch is strictly better than either alone: the
     * per-engine types make the statement legal everywhere, and the catch closes
     * the window the types cannot.
     *
     * Firebird's branch is verified at the SQL level against a live Firebird
     * 5.0.4 but has not been exercised end to end through a PHP Firebird driver,
     * and the read path has a known gap on that engine (Firebird folds unquoted
     * identifiers to UPPER, and firstRow() reads $row['data'] only), so the
     * database session backend is not claimed working on Firebird.
     */
    private function ensureTable(): void
    {
        if ($this->tableCreated) {
            return;
        }

        if (!$this->db()->tableExists('tina4_session')) {
            try {
                $this->db()->execute(self::CREATE_TABLE[$this->engineName()] ?? self::CREATE_TABLE_FALLBACK);
                $this->db()->commit();
            } catch (\Throwable $e) {
                // A failed statement leaves PostgreSQL's transaction aborted, so
                // the re-check below would fail for the wrong reason without this.
                try {
                    $this->db()->rollback();
                } catch (\Throwable) {
                    // Best effort - an engine with no open transaction is fine.
                }
                if (!$this->db()->tableExists('tina4_session')) {
                    throw $e;
                }
            }
        }

        $this->tableCreated = true;
    }
}
