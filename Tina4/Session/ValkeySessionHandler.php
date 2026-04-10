<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Valkey Session Handler — stores sessions in Valkey (Redis-compatible)
 * using the RESP protocol via raw TCP sockets. Zero external dependencies.
 *
 * Environment variables:
 *   TINA4_SESSION_VALKEY_HOST     — hostname (default: localhost)
 *   TINA4_SESSION_VALKEY_PORT     — port (default: 6379)
 *   TINA4_SESSION_VALKEY_PASSWORD — password (default: none)
 *   TINA4_SESSION_VALKEY_DB       — database number (default: 0)
 *   TINA4_SESSION_TTL             — session TTL in seconds (default: 3600)
 */

namespace Tina4\Session;

class ValkeySessionHandler
{
    private string $host;
    private int $port;
    private ?string $password;
    private int $db;
    private int $ttl;
    private string $keyPrefix;

    /** @var resource|null TCP socket */
    private $socket = null;

    /**
     * @param array $config Configuration overrides:
     *   'host'      => string  Valkey host
     *   'port'      => int     Valkey port
     *   'password'  => string  Authentication password
     *   'db'        => int     Database number
     *   'ttl'       => int     Session TTL in seconds
     *   'keyPrefix' => string  Key prefix (default: 'tina4:session:')
     */
    public function __construct(array $config = [])
    {
        $this->host = $config['host'] ?? (getenv('TINA4_SESSION_VALKEY_HOST') ?: 'localhost');
        $this->port = (int)($config['port'] ?? (getenv('TINA4_SESSION_VALKEY_PORT') ?: 6379));

        $envPass = getenv('TINA4_SESSION_VALKEY_PASSWORD');
        $this->password = $config['password'] ?? ($envPass !== false && $envPass !== '' ? $envPass : null);

        $this->db = (int)($config['db'] ?? (getenv('TINA4_SESSION_VALKEY_DB') ?: 0));
        $this->ttl = (int)($config['ttl'] ?? (getenv('TINA4_SESSION_TTL') ?: 3600));
        $this->keyPrefix = $config['keyPrefix'] ?? 'tina4:session:';
    }

    /**
     * Read session data by session ID.
     *
     * @param string $sessionId The session ID
     * @return array Session data or empty array
     */
    public function read(string $sessionId): array
    {
        $this->ensureConnected();

        $key = $this->keyPrefix . $sessionId;
        $result = $this->sendCommand('GET', $key);

        if ($result === null) {
            return [];
        }

        $data = json_decode($result, true);
        if (!is_array($data)) {
            return [];
        }

        return $data;
    }

    /**
     * Write session data.
     *
     * @param string $sessionId The session ID
     * @param array  $data      Session data to store
     */
    public function write(string $sessionId, array $data): void
    {
        $this->ensureConnected();

        $key = $this->keyPrefix . $sessionId;
        $value = json_encode($data, JSON_UNESCAPED_SLASHES);

        $this->sendCommand('SETEX', $key, (string)$this->ttl, $value);
    }

    /**
     * Delete a session.
     *
     * @param string $sessionId The session ID
     */
    public function destroy(string $sessionId): void
    {
        $this->ensureConnected();

        $key = $this->keyPrefix . $sessionId;
        $this->sendCommand('DEL', $key);
    }

    /**
     * Check if a session exists.
     *
     * @param string $sessionId The session ID
     * @return bool
     */
    public function exists(string $sessionId): bool
    {
        $this->ensureConnected();

        $key = $this->keyPrefix . $sessionId;
        $result = $this->sendCommand('EXISTS', $key);

        return $result === '1' || $result === 1;
    }

    /**
     * Refresh the TTL on a session.
     *
     * @param string $sessionId The session ID
     */
    public function touch(string $sessionId): void
    {
        $this->ensureConnected();

        $key = $this->keyPrefix . $sessionId;
        $this->sendCommand('EXPIRE', $key, (string)$this->ttl);
    }

    /**
     * Close the connection.
     */
    public function close(): void
    {
        if ($this->socket) {
            $this->sendCommand('QUIT');
            fclose($this->socket);
            $this->socket = null;
        }
    }

    // ── RESP Protocol Implementation ────────────────────────────

    private function ensureConnected(): void
    {
        if ($this->socket === null) {
            $this->connect();
        }
    }

    private function connect(): void
    {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, 10);
        if (!$this->socket) {
            throw new \RuntimeException("Valkey connection failed: [{$errno}] {$errstr}");
        }

        stream_set_timeout($this->socket, 30);

        // Authenticate if password is set
        if ($this->password !== null) {
            $result = $this->sendCommand('AUTH', $this->password);
            if ($result !== 'OK') {
                throw new \RuntimeException('Valkey authentication failed');
            }
        }

        // Select database if non-zero
        if ($this->db > 0) {
            $result = $this->sendCommand('SELECT', (string)$this->db);
            if ($result !== 'OK') {
                throw new \RuntimeException("Valkey SELECT database {$this->db} failed");
            }
        }
    }

    /**
     * Send a RESP command and read the response.
     *
     * @param string ...$args Command and arguments
     * @return mixed Response value
     */
    private function sendCommand(string ...$args): mixed
    {
        // RESP array format: *<count>\r\n$<len>\r\n<data>\r\n...
        $cmd = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $cmd .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }

        fwrite($this->socket, $cmd);

        return $this->readReply();
    }

    /**
     * Read a RESP reply from the socket.
     */
    private function readReply(): mixed
    {
        $line = $this->readLine();
        $type = $line[0];
        $data = substr($line, 1);

        switch ($type) {
            case '+': // Simple string
                return $data;

            case '-': // Error
                throw new \RuntimeException('Valkey error: ' . $data);

            case ':': // Integer
                return (int)$data;

            case '$': // Bulk string
                $len = (int)$data;
                if ($len === -1) {
                    return null;
                }

                $value = '';
                $remaining = $len + 2; // +2 for trailing \r\n
                while ($remaining > 0) {
                    $chunk = fread($this->socket, $remaining);
                    if ($chunk === false || $chunk === '') {
                        throw new \RuntimeException('Failed to read Valkey bulk string');
                    }
                    $value .= $chunk;
                    $remaining -= strlen($chunk);
                }

                return substr($value, 0, $len); // Strip trailing \r\n

            case '*': // Array
                $count = (int)$data;
                if ($count === -1) {
                    return null;
                }

                $result = [];
                for ($i = 0; $i < $count; $i++) {
                    $result[] = $this->readReply();
                }
                return $result;

            default:
                throw new \RuntimeException('Unknown RESP reply type: ' . $type);
        }
    }

    /**
     * Read a line from the socket (terminated by \r\n).
     */
    private function readLine(): string
    {
        $line = '';
        while (true) {
            $char = fgetc($this->socket);
            if ($char === false) {
                throw new \RuntimeException('Failed to read from Valkey socket');
            }
            if ($char === "\r") {
                fgetc($this->socket); // consume \n
                break;
            }
            $line .= $char;
        }
        return $line;
    }
}
