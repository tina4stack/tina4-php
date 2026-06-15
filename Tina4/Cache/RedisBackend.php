<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * RedisBackend — Redis / Valkey backend. Uses ext-redis if loaded, otherwise
 * falls back to the raw RESP protocol over TCP (zero-dependency).
 *
 * Mirrors Python's tina4_python.cache._RedisBackend exactly:
 *   - URL form scheme://[user[:password]@]host[:port][/db]
 *   - credentials may also come from TINA4_CACHE_USERNAME / TINA4_CACHE_PASSWORD
 *     (parity with TINA4_DATABASE_USERNAME / _PASSWORD)
 *   - isAvailable() performs a real AUTH+PING handshake so wrong credentials
 *     also fall back to the file backend
 *   - values are JSON-encoded under the "tina4:cache:" key prefix
 */

namespace Tina4\Cache;

class RedisBackend extends CacheBackend
{
    protected string $host = 'localhost';
    protected int $port = 6379;
    protected int $db = 0;
    protected ?string $username = null;
    protected ?string $password = null;

    protected string $prefix = 'tina4:cache:';
    protected string $backendName;
    protected int $maxEntries;
    protected int $hits = 0;
    protected int $misses = 0;

    protected ?\Redis $client = null;
    protected bool $useRaw = false;
    protected bool $available = false;

    public function __construct(string $url = 'redis://localhost:6379', int $maxEntries = 1000, string $name = 'redis')
    {
        $this->maxEntries = $maxEntries;
        $this->backendName = $name;

        $this->parseUrl(str_contains($url, '://') ? $url : 'redis://' . $url);

        // Try ext-redis first (parity with Python's "redis package first").
        if (extension_loaded('redis')) {
            try {
                $client = new \Redis();
                $client->connect($this->host, $this->port, 5.0);
                if ($this->password !== null) {
                    if ($this->username !== null) {
                        $client->auth([$this->username, $this->password]);
                    } else {
                        $client->auth($this->password);
                    }
                }
                if ($this->db !== 0) {
                    $client->select($this->db);
                }
                $client->ping();
                $this->client = $client;
                $this->available = true;
                return;
            } catch (\Throwable) {
                $this->client = null;
            }
        }

        // No usable client — fall back to raw RESP. Usable only if the server
        // answers (and authenticates).
        $this->useRaw = true;
        $this->available = $this->probe();
    }

    /**
     * Parse scheme://[user[:password]@]host[:port][/db], honouring env creds.
     */
    private function parseUrl(string $url): void
    {
        $parts = parse_url($url);
        if (is_array($parts)) {
            $this->host = $parts['host'] ?? 'localhost';
            $this->port = $parts['port'] ?? 6379;
            $path = ltrim($parts['path'] ?? '', '/');
            $this->db = ctype_digit($path) ? (int)$path : 0;
            $urlUser = isset($parts['user']) ? urldecode($parts['user']) : '';
            $urlPass = isset($parts['pass']) ? urldecode($parts['pass']) : '';
        } else {
            $urlUser = '';
            $urlPass = '';
        }

        $envUser = \Tina4\DotEnv::getEnv('TINA4_CACHE_USERNAME') ?? '';
        $envPass = \Tina4\DotEnv::getEnv('TINA4_CACHE_PASSWORD') ?? '';

        $username = $urlUser !== '' ? $urlUser : $envUser;
        $password = $urlPass !== '' ? $urlPass : $envPass;

        $this->username = $username !== '' ? $username : null;
        $this->password = $password !== '' ? $password : null;
    }

    /**
     * Real AUTH+PING handshake so wrong credentials also fall back to file.
     */
    private function probe(): bool
    {
        try {
            return $this->respCommand('PING') === 'PONG';
        } catch (\Throwable) {
            return false;
        }
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Send a command using the raw RESP protocol over TCP.
     */
    protected function respCommand(string ...$args): ?string
    {
        try {
            $cmd = '*' . count($args) . "\r\n";
            foreach ($args as $arg) {
                $cmd .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
            }

            $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 5);
            if (!$sock) {
                return null;
            }
            stream_set_timeout($sock, 5);

            if ($this->password !== null) {
                if ($this->username !== null) {
                    $auth = "*3\r\n\$4\r\nAUTH\r\n\$" . strlen($this->username) . "\r\n" . $this->username
                        . "\r\n\$" . strlen($this->password) . "\r\n" . $this->password . "\r\n";
                } else {
                    $auth = "*2\r\n\$4\r\nAUTH\r\n\$" . strlen($this->password) . "\r\n" . $this->password . "\r\n";
                }
                fwrite($sock, $auth);
                $authResp = fread($sock, 1024);
                if ($authResp === false || !str_starts_with($authResp, '+')) {
                    fclose($sock);
                    return null;
                }
            }

            if ($this->db !== 0) {
                $select = "*2\r\n\$6\r\nSELECT\r\n\$" . strlen((string)$this->db) . "\r\n" . $this->db . "\r\n";
                fwrite($sock, $select);
                fread($sock, 1024);
            }

            fwrite($sock, $cmd);
            $response = fread($sock, 65536);
            fclose($sock);

            if ($response === false) {
                return null;
            }
            if (str_starts_with($response, '+')) {
                return trim(substr($response, 1));
            }
            if (str_starts_with($response, '$-1')) {
                return null;
            }
            if (str_starts_with($response, '$')) {
                $lines = explode("\r\n", $response);
                return $lines[1] ?? null;
            }
            if (str_starts_with($response, ':')) {
                return trim(substr($response, 1));
            }
            if (str_starts_with($response, '-')) {
                return null;
            }
            return trim($response);
        } catch (\Throwable) {
            return null;
        }
    }

    public function get(string $key): mixed
    {
        $fullKey = $this->prefix . $key;
        $raw = null;
        if ($this->client !== null) {
            try {
                $value = $this->client->get($fullKey);
                $raw = $value === false ? null : $value;
            } catch (\Throwable) {
                $raw = null;
            }
        } elseif ($this->useRaw) {
            $raw = $this->respCommand('GET', $fullKey);
        }

        if ($raw === null) {
            $this->misses++;
            return null;
        }
        $this->hits++;
        $decoded = json_decode($raw, true);
        return $decoded === null && $raw !== 'null' ? $raw : $decoded;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        $fullKey = $this->prefix . $key;
        $serialized = json_encode($value);
        if ($this->client !== null) {
            try {
                if ($ttl > 0) {
                    $this->client->setex($fullKey, $ttl, $serialized);
                } else {
                    $this->client->set($fullKey, $serialized);
                }
            } catch (\Throwable) {
                // ignore
            }
        } elseif ($this->useRaw) {
            if ($ttl > 0) {
                $this->respCommand('SETEX', $fullKey, (string)$ttl, $serialized);
            } else {
                $this->respCommand('SET', $fullKey, $serialized);
            }
        }
    }

    public function delete(string $key): bool
    {
        $fullKey = $this->prefix . $key;
        if ($this->client !== null) {
            try {
                return $this->client->del($fullKey) > 0;
            } catch (\Throwable) {
                return false;
            }
        }
        if ($this->useRaw) {
            return $this->respCommand('DEL', $fullKey) === '1';
        }
        return false;
    }

    public function clear(): void
    {
        $this->hits = 0;
        $this->misses = 0;
        if ($this->client !== null) {
            try {
                $keys = $this->client->keys($this->prefix . '*');
                if (!empty($keys)) {
                    $this->client->del(...$keys);
                }
            } catch (\Throwable) {
                // ignore
            }
        }
        // Raw RESP path: no easy pattern delete — let TTL handle cleanup
        // (parity with Python's _RedisBackend.clear()).
    }

    public function stats(): array
    {
        $size = 0;
        if ($this->client !== null) {
            try {
                $keys = $this->client->keys($this->prefix . '*');
                $size = is_array($keys) ? count($keys) : 0;
            } catch (\Throwable) {
                // ignore
            }
        }
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'size' => $size,
            'backend' => $this->backendName,
        ];
    }

    public function name(): string
    {
        return $this->backendName;
    }

    /**
     * @internal Test seam — expose parsed connection params (host/port/db/
     * username/password) so credential parsing can be verified without a live
     * server (parity with Python's test_*_credentials_parsed).
     *
     * @return array{host: string, port: int, db: int, username: ?string, password: ?string}
     */
    public function _credentials(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'db' => $this->db,
            'username' => $this->username,
            'password' => $this->password,
        ];
    }
}
