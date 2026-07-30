<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Session;

/**
 * Memcached session handler — zero-dependency text protocol over a socket.
 *
 * Memcached was already one of the seven CACHE backends in all four frameworks
 * but was NOT a session backend in any of them, even though it is the classic
 * PHP session store. This closes that gap.
 *
 * Speaks the memcached TEXT protocol directly, so ext-memcached is not required
 * — the same zero-dependency choice the Redis/Valkey handlers make.
 *
 * BACKEND-FAILURE POLICY. A genuine key miss returns `[]` silently (no session
 * yet is normal). A TRANSPORT failure — server unreachable, connection dropped
 * mid-reply, a protocol error — THROWS, so the Session layer can log-loud and
 * degrade. Collapsing the two is how a dead cache silently logs every user out.
 *
 * Memcached has no persistence and no replication: a restart drops every
 * session. That is a deliberate trade (it is a cache), and it is why
 * file/database remain the defaults.
 *
 * Environment variables:
 *   TINA4_SESSION_MEMCACHED_HOST   — hostname (default: localhost)
 *   TINA4_SESSION_MEMCACHED_PORT   — port (default: 11211)
 *   TINA4_SESSION_MEMCACHED_PREFIX — key prefix (default: tina4:session:)
 *   TINA4_SESSION_TTL              — session TTL in seconds (default: 3600)
 */
class MemcachedSessionHandler
{
    /**
     * Memcached rejects a key over 250 bytes or containing a space/control
     * character. A key that could break either rule is HASHED rather than
     * truncated — truncating would let two different sessions collide on one
     * key, handing one user another user's session.
     */
    private const MAX_KEY_BYTES = 250;

    private string $host;
    private int $port;
    private string $keyPrefix;
    private int $ttl;
    private float $timeout;

    /**
     * @param array<string, mixed> $config host, port, prefix, ttl, timeout
     */
    public function __construct(array $config = [])
    {
        $this->host = (string)($config['host'] ?? getenv('TINA4_SESSION_MEMCACHED_HOST') ?: 'localhost');
        $this->port = (int)($config['port'] ?? getenv('TINA4_SESSION_MEMCACHED_PORT') ?: 11211);
        $this->keyPrefix = (string)($config['prefix'] ?? getenv('TINA4_SESSION_MEMCACHED_PREFIX') ?: 'tina4:session:');
        $this->ttl = (int)($config['ttl'] ?? getenv('TINA4_SESSION_TTL') ?: 3600);
        $this->timeout = (float)($config['timeout'] ?? 5);
    }

    /**
     * Build a protocol-legal memcached key for a session id.
     */
    private function key(string $sessionId): string
    {
        $key = $this->keyPrefix . $sessionId;
        if (strlen($key) > self::MAX_KEY_BYTES || preg_match('/[\x00-\x20\x7f]/', $key)) {
            return $this->keyPrefix . hash('sha256', $sessionId);
        }
        return $key;
    }

    /**
     * Run one memcached command and return the raw reply.
     *
     * @param string   $payload     Bytes to send
     * @param string[] $terminators Any of these ends the reply
     * @return string The raw reply
     * @throws \RuntimeException On any transport failure — never swallowed to
     *         an empty result, because for a session an outage must be
     *         distinguishable from "no session yet".
     */
    private function command(string $payload, array $terminators): string
    {
        $errNo = 0;
        $errStr = '';
        $sock = @fsockopen($this->host, $this->port, $errNo, $errStr, $this->timeout);
        if ($sock === false) {
            throw new \RuntimeException(sprintf(
                'Memcached session backend at %s:%d failed: %s',
                $this->host,
                $this->port,
                $errStr !== '' ? $errStr : 'connection refused'
            ));
        }

        try {
            stream_set_timeout($sock, (int)$this->timeout);
            if (@fwrite($sock, $payload) === false) {
                throw new \RuntimeException('write failed');
            }

            $buffer = '';
            while (true) {
                foreach ($terminators as $terminator) {
                    if (str_contains($buffer, $terminator)) {
                        return $buffer;
                    }
                }
                $chunk = @fread($sock, 4096);
                $info = stream_get_meta_data($sock);
                if ($info['timed_out']) {
                    throw new \RuntimeException('timed out waiting for a reply');
                }
                if ($chunk === false || $chunk === '') {
                    throw new \RuntimeException('connection closed before a complete reply');
                }
                $buffer .= $chunk;
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf(
                'Memcached session backend at %s:%d failed: %s',
                $this->host,
                $this->port,
                $e->getMessage()
            ), 0, $e);
        } finally {
            @fclose($sock);
        }
    }

    /**
     * Read session data by session ID.
     *
     * @param string $sessionId The session ID
     * @return array<string, mixed> Session data, or [] for a genuine miss
     * @throws \RuntimeException When the backend is unreachable
     */
    public function read(string $sessionId): array
    {
        $resp = $this->command("get {$this->key($sessionId)}\r\n", ["END\r\n"]);
        if (!str_starts_with($resp, 'VALUE')) {
            return [];                       // genuine miss — not an error
        }

        $split = explode("\r\n", $resp, 2);
        if (count($split) < 2) {
            return [];
        }
        $header = explode(' ', $split[0]);
        $bytes = isset($header[3]) ? (int)$header[3] : 0;
        $data = json_decode(substr($split[1], 0, $bytes), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Write session data with a TTL.
     *
     * @param string $sessionId The session ID
     * @param array<string, mixed> $data Session data to store
     * @param int $ttl TTL override in seconds (0 = the configured default)
     * @throws \RuntimeException When the backend is unreachable or refuses the store
     */
    public function write(string $sessionId, array $data, int $ttl = 0): void
    {
        $effectiveTtl = $ttl > 0 ? $ttl : $this->ttl;
        $payload = json_encode($data, JSON_UNESCAPED_SLASHES);
        $cmd = sprintf("set %s 0 %d %d\r\n", $this->key($sessionId), $effectiveTtl, strlen($payload));

        $resp = $this->command(
            $cmd . $payload . "\r\n",
            ["STORED\r\n", "ERROR\r\n", "SERVER_ERROR", "CLIENT_ERROR"]
        );

        if (!str_starts_with($resp, 'STORED')) {
            throw new \RuntimeException('Memcached did not store the session: ' . substr($resp, 0, 80));
        }
    }

    /**
     * Delete a session. A session that was already gone is not an error.
     *
     * @param string $sessionId The session ID
     * @throws \RuntimeException When the backend is unreachable
     */
    public function destroy(string $sessionId): void
    {
        $this->command(
            "delete {$this->key($sessionId)}\r\n",
            ["DELETED\r\n", "NOT_FOUND\r\n", "ERROR\r\n"]
        );
    }

    /**
     * Whether a session exists.
     *
     * @param string $sessionId The session ID
     * @return bool True when the session is present
     */
    public function exists(string $sessionId): bool
    {
        return $this->read($sessionId) !== [];
    }

    /**
     * Refresh a session's TTL without changing its contents.
     *
     * @param string $sessionId The session ID
     */
    public function touch(string $sessionId): void
    {
        $this->command("touch {$this->key($sessionId)} {$this->ttl}\r\n", ["TOUCHED\r\n", "NOT_FOUND\r\n"]);
    }

    /**
     * Garbage collection — a no-op; memcached expires its own keys via the TTL
     * set on write.
     */
    public function gc(int $maxLifetime): void
    {
    }

    /**
     * Close — a no-op; each command uses its own short-lived connection.
     */
    public function close(): void
    {
    }
}
