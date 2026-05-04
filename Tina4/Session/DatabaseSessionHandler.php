<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Database Session Handler — stores sessions in a SQL database table
 * using the Tina4 DatabaseAdapter. Zero external dependencies.
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
            "SELECT data, expires_at FROM tina4_session WHERE session_id = '{$sessionId}'",
            [],
            1
        );

        if ($result === null || empty($result->records)) {
            return null;
        }

        $row = $result->records[0];
        $expiresAt = (float)($row->expiresAt ?? $row->expires_at ?? 0);

        if ($expiresAt < microtime(true)) {
            // Session expired — clean it up
            $this->destroy($sessionId);
            return null;
        }

        $data = json_decode($row->data ?? '', true);
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
    public function write(string $sessionId, array $data): void
    {
        $this->ensureTable();

        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES);
        $expiresAt = microtime(true) + $this->ttl;

        // Check if session already exists
        $existing = $this->db->fetch(
            "SELECT session_id FROM tina4_session WHERE session_id = '{$sessionId}'",
            [],
            1
        );

        if ($existing !== null && !empty($existing->records)) {
            $this->db->exec(
                "UPDATE tina4_session SET data = '{$encoded}', expires_at = {$expiresAt} "
                . "WHERE session_id = '{$sessionId}'"
            );
        } else {
            $this->db->exec(
                "INSERT INTO tina4_session (session_id, data, expires_at) "
                . "VALUES ('{$sessionId}', '{$encoded}', {$expiresAt})"
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

        $this->db->exec(
            "DELETE FROM tina4_session WHERE session_id = '{$sessionId}'"
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
        $this->db->exec(
            "DELETE FROM tina4_session WHERE expires_at > 0 AND expires_at < {$now}"
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
     * Ensure the session table exists (creates it on first call).
     */
    private function ensureTable(): void
    {
        if ($this->tableCreated) {
            return;
        }

        if (!$this->db->tableExists('tina4_session')) {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS tina4_session ("
                . "session_id VARCHAR(255) PRIMARY KEY, "
                . "data TEXT NOT NULL, "
                . "expires_at REAL NOT NULL"
                . ")"
            );
            $this->db->commit();
        }

        $this->tableCreated = true;
    }
}
