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
     * Encode one command as a RESP array of bulk strings.
     */
    private static function respEncode(string ...$args): string
    {
        $wire = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $wire .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }
        return $wire;
    }

    /**
     * Read exactly $length bytes, however many reads that takes.
     *
     * fread() on a socket returns whatever has ARRIVED, not what was asked for,
     * so a single call is only ever a lower bound on a large payload.
     *
     * @param  resource $stream
     */
    private static function respReadExact($stream, int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($stream, $length - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                break;  // EOF or read timeout - return what arrived.
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }

    /**
     * Read ONE complete RESP reply from an open stream.
     *
     * Returns a string for a simple, bulk or integer reply, null for a nil or
     * an error reply, and an ARRAY for a multi-bulk reply (nested arrays
     * recurse). The reply is assembled in full: a bulk string is read by its
     * declared byte length and a multi-bulk by its declared element count.
     *
     * The previous implementation did a single fread(65536) and string-split
     * the result, which silently truncated any reply bigger than one read - so
     * a large cached value came back corrupt and a key scan came back short.
     *
     * @param  resource $stream
     */
    private static function respRead($stream): string|array|null
    {
        $line = fgets($stream);
        if ($line === false || $line === '') {
            return null;
        }
        $prefix = $line[0];
        $body = rtrim(substr($line, 1), "\r\n");

        if ($prefix === '+' || $prefix === ':') {
            return $body;
        }
        if ($prefix === '-') {
            return null;
        }
        if ($prefix === '$') {
            $length = (int)$body;
            if ($length < 0) {
                return null;  // $-1 nil
            }
            // Value plus its trailing CRLF, which is consumed but not returned.
            return substr(self::respReadExact($stream, $length + 2), 0, $length);
        }
        if ($prefix === '*') {
            $count = (int)$body;
            if ($count < 0) {
                return null;  // *-1 nil array
            }
            $items = [];
            for ($index = 0; $index < $count; $index++) {
                $items[] = self::respRead($stream);
            }
            return $items;
        }
        return $body;
    }

    /**
     * Open an authenticated RESP socket. The caller closes it.
     *
     * Kept separate from respCommand() so a multi-step conversation (the SCAN
     * cursor loop) runs over ONE connection instead of reconnecting per
     * command. Returns null when the server is unreachable or rejects AUTH.
     *
     * @return resource|null
     */
    private function respSession()
    {
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 5);
        if (!$sock) {
            return null;
        }
        stream_set_timeout($sock, 5);

        if ($this->password !== null) {
            if ($this->username !== null) {
                fwrite($sock, self::respEncode('AUTH', $this->username, $this->password));
            } else {
                fwrite($sock, self::respEncode('AUTH', $this->password));
            }
            if (self::respRead($sock) !== 'OK') {
                fclose($sock);
                return null;
            }
        }

        if ($this->db !== 0) {
            fwrite($sock, self::respEncode('SELECT', (string)$this->db));
            self::respRead($sock);
        }

        return $sock;
    }

    /**
     * Send one command using the raw RESP protocol over TCP.
     *
     * @return string|array|null string for simple/bulk/integer replies, null
     *                           for a nil or an error, array for multi-bulk.
     */
    protected function respCommand(string ...$args): string|array|null
    {
        $sock = null;
        try {
            $sock = $this->respSession();
            if ($sock === null) {
                return null;
            }
            fwrite($sock, self::respEncode(...$args));
            return self::respRead($sock);
        } catch (\Throwable) {
            return null;
        } finally {
            if (is_resource($sock)) {
                fclose($sock);
            }
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

        // GET never answers with a multi-bulk, but respCommand() can return one
        // now, so anything that is not a string is a miss rather than a crash.
        if (!is_string($raw)) {
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

    /**
     * Remove EVERY entry this cache can serve - on BOTH transports.
     *
     * The raw RESP path used to do nothing at all: "no easy pattern delete, let
     * TTL handle cleanup". That made clear() a no-op on the ZERO-DEPENDENCY
     * default install, so a write never invalidated the persistent DB query
     * cache and every instance kept serving pre-write rows until the TTL ran
     * out (ADR-0024 rule 4: the default provider counts double).
     *
     * SCAN, not KEYS: clear() runs on every write in persistent DB-cache mode,
     * and KEYS is O(N) and blocks the whole server for its duration - Redis's
     * own documentation says to prefer SCAN. The scan is scoped to our prefix,
     * so another application sharing the server is untouched; FLUSHALL/FLUSHDB
     * would take their data with it and is never used here.
     */
    public function clear(): void
    {
        $this->hits = 0;
        $this->misses = 0;

        if ($this->client !== null) {
            try {
                $cursor = null;
                do {
                    $keys = $this->client->scan($cursor, $this->prefix . '*', 500);
                    if ($keys === false) {
                        break;
                    }
                    if ($keys !== []) {
                        $this->client->del(...$keys);
                    }
                    // A page may legitimately come back empty while the cursor
                    // is still open, so the cursor - not the page - ends this.
                } while ((int)$cursor !== 0);
            } catch (\Throwable) {
                // An unreachable cache is not a crash; the entries expire.
            }
            return;
        }

        if (!$this->useRaw) {
            return;
        }

        $sock = null;
        try {
            $sock = $this->respSession();
            if ($sock === null) {
                return;
            }
            $cursor = '0';
            do {
                fwrite($sock, self::respEncode(
                    'SCAN',
                    $cursor,
                    'MATCH',
                    $this->prefix . '*',
                    'COUNT',
                    '500'
                ));
                $reply = self::respRead($sock);
                if (!is_array($reply) || count($reply) !== 2) {
                    break;
                }
                [$cursor, $keys] = $reply;
                if (is_array($keys) && $keys !== []) {
                    fwrite($sock, self::respEncode('DEL', ...$keys));
                    self::respRead($sock);
                }
            } while (is_string($cursor) && $cursor !== '' && $cursor !== '0');
        } catch (\Throwable) {
            // An unreachable cache is not a crash; the entries expire.
        } finally {
            if (is_resource($sock)) {
                fclose($sock);
            }
        }
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
