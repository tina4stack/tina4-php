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
    private DatabaseAdapter $db;
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

        if (isset($config['db']) && $config['db'] instanceof DatabaseAdapter) {
            $this->db = $config['db'];
        } elseif (isset($config['db']) && $config['db'] instanceof Database) {
            $this->db = $config['db']->getAdapter();
        } else {
            $db = Database::fromEnv();
            if ($db === null) {
                throw new \RuntimeException(
                    'DatabaseSessionHandler: No database connection available. '
                    . 'Pass a DatabaseAdapter via config["db"] or set the TINA4_DATABASE_URL env var.'
                );
            }
            $this->db = $db->getAdapter();
        }
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

        $result = $this->db->fetch(
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
        $existing = $this->db->fetch(
            "SELECT session_id FROM tina4_session WHERE session_id = ?",
            [$sessionId],
            1
        );

        if ($this->firstRow($existing) !== null) {
            $this->db->execute(
                "UPDATE tina4_session SET data = ?, expires_at = ? WHERE session_id = ?",
                [$encoded, $expiresAt, $sessionId]
            );
        } else {
            $this->db->execute(
                "INSERT INTO tina4_session (session_id, data, expires_at) VALUES (?, ?, ?)",
                [$sessionId, $encoded, $expiresAt]
            );
        }

        $this->db->commit();
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

        $this->db->execute(
            "DELETE FROM tina4_session WHERE session_id = ?",
            [$sessionId]
        );
        $this->db->commit();
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
        $this->db->execute(
            "DELETE FROM tina4_session WHERE expires_at > 0 AND expires_at < ?",
            [$now]
        );
        $this->db->commit();
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
     * Ensure the session table exists (creates it on first call).
     */
    private function ensureTable(): void
    {
        if ($this->tableCreated) {
            return;
        }

        if (!$this->db->tableExists('tina4_session')) {
            $this->db->execute(
                "CREATE TABLE IF NOT EXISTS tina4_session ("
                . "session_id VARCHAR(255) PRIMARY KEY, "
                . "data TEXT NOT NULL, "
                . "expires_at DOUBLE PRECISION NOT NULL"
                . ")"
            );
            $this->db->commit();
        }

        $this->tableCreated = true;
    }
}
