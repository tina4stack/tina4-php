<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Session management with pluggable backends — zero external dependencies.
 *
 * Supported backends:
 *   - 'file' — JSON files in a configurable directory (default: data/sessions)
 *   - 'redis' — Redis via PHP's built-in redis extension
 *   - 'database' / 'db' — SQL database via Tina4 DatabaseAdapter
 *
 * Environment variables:
 *   TINA4_SESSION_HANDLER  — 'file', 'redis', 'valkey', 'mongodb', or 'database' (default: 'file')
 *   TINA4_SESSION_PATH     — file storage path (default: 'data/sessions')
 *   TINA4_SESSION_TTL      — session lifetime in seconds (default: 3600)
 *   TINA4_SESSION_REDIS_URL — Redis connection URL (default: 'tcp://127.0.0.1:6379')
 */
class Session
{
    /** @var string Session handler type ('file', 'redis', 'valkey', 'mongodb', or 'database') */
    private string $backend;

    /** @var string Current session ID */
    private string $sessionId = '';

    /** @var array Session data store */
    private array $data = [];

    /** @var int TTL in seconds */
    private int $ttl;

    /** @var string File storage path (for file backend) */
    private string $storagePath;

    /** @var bool Whether a session is active */
    private bool $started = false;

    /**
     * @param string $backend 'file' or 'redis'
     * @param array  $config  Override defaults: 'path', 'ttl', 'redis_url'
     */
    public function __construct(string $backend = '', array $config = [])
    {
        $this->backend = $backend ?: (getenv('TINA4_SESSION_HANDLER') ?: (getenv('TINA4_SESSION_BACKEND') ?: 'file'));
        $this->ttl = (int)($config['ttl'] ?? getenv('TINA4_SESSION_TTL') ?: 3600);
        $this->storagePath = $config['path'] ?? (getenv('TINA4_SESSION_PATH') ?: 'data/sessions');
    }

    /**
     * Start or resume a session.
     *
     * @param string|null $sessionId Existing session ID, or null to generate one
     * @return string The session ID
     */
    public function start(?string $sessionId = null): string
    {
        if ($sessionId !== null) {
            $this->sessionId = $sessionId;
            $this->load();
        } else {
            $this->sessionId = bin2hex(random_bytes(16));
            $this->data = ['_meta' => ['created_at' => time(), 'last_accessed' => time()]];
        }

        $this->started = true;
        return $this->sessionId;
    }

    /**
     * Get a value from the session.
     *
     * @param string $key     The key to retrieve
     * @param mixed  $default Default value if key not found
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Set a value in the session.
     *
     * @param string $key   The key to set
     * @param mixed  $value The value to store
     */
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->data['_meta']['last_accessed'] = time();
        $this->save();
    }

    /**
     * Delete a key from the session.
     *
     * @param string $key The key to remove
     */
    public function delete(string $key): void
    {
        unset($this->data[$key]);
        $this->save();
    }

    /**
     * Destroy the entire session and remove stored data.
     */
    public function destroy(): void
    {
        $this->data = [];
        $this->removeStorage();
        $this->started = false;
    }

    /**
     * Clear all session data (but keep the session alive).
     * Maps to Python: clear()
     */
    public function clear(): void
    {
        $meta = $this->data['_meta'] ?? null;
        $this->data = [];
        if ($meta !== null) {
            $this->data['_meta'] = $meta;
        }
        $this->save();
    }

    /**
     * Get all session data (excluding internal metadata).
     *
     * @return array
     */
    public function all(): array
    {
        $result = $this->data;
        unset($result['_meta']);

        // Exclude flash data from all()
        foreach (array_keys($result) as $key) {
            if (str_starts_with($key, '_flash_')) {
                unset($result[$key]);
            }
        }

        return $result;
    }

    /**
     * Check if a key exists in the session.
     *
     * @param string $key The key to check
     * @return bool
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Regenerate the session ID, preserving data.
     *
     * @return string The new session ID
     */
    public function regenerate(): string
    {
        $oldData = $this->data;
        $this->removeStorage();

        $this->sessionId = bin2hex(random_bytes(16));
        $this->data = $oldData;
        $this->data['_meta']['last_accessed'] = time();
        $this->save();

        return $this->sessionId;
    }

    /**
     * Set flash data — available only until the next read.
     *
     * @param string $key   The flash key
     * @param mixed  $value The flash value
     */
    /**
     * Dual-mode flash: set with value, get+remove without.
     *
     *   $session->flash("message", "Saved!")  // set
     *   $session->flash("message")            // get + auto-remove → "Saved!"
     */
    public function flash(string $key, mixed $value = null): mixed
    {
        $flashKey = "_flash_$key";
        if ($value !== null) {
            // Set mode
            $this->data[$flashKey] = $value;
            $this->save();
            return null;
        }
        // Get mode — read and remove
        if (!array_key_exists($flashKey, $this->data)) {
            return null;
        }

        $value = $this->data[$flashKey];
        unset($this->data[$flashKey]);
        $this->save();

        return $value;
    }

    /**
     * Get flash data by key (alias for flash(key) without value).
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        $result = $this->flash($key);
        return $result !== null ? $result : $default;
    }

    /**
     * Get the current session ID.
     *
     * @return string
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Return a Set-Cookie header value for this session.
     */
    public function cookieHeader(string $cookieName = 'tina4_session'): string
    {
        $sameSite = $_ENV['TINA4_SESSION_SAMESITE'] ?? getenv('TINA4_SESSION_SAMESITE') ?: 'Lax';
        return "{$cookieName}={$this->sessionId}; Path=/; HttpOnly; SameSite={$sameSite}; Max-Age={$this->ttl}";
    }

    /**
     * Check if the session is started.
     *
     * @return bool
     */
    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * Read raw session data for a given session ID from the backend storage.
     *
     * @param string $sessionId The session ID to read
     * @return array|null The session data array, or null if not found / expired
     */
    public function read(string $sessionId): ?array
    {
        $previousId = $this->sessionId;
        $previousData = $this->data;

        $this->sessionId = $sessionId;
        $this->load();
        $result = $this->data;

        $this->sessionId = $previousId;
        $this->data = $previousData;

        // Return null if only empty or default meta (session not found)
        if (empty($result) || $result === ['_meta' => $result['_meta'] ?? []]) {
            $keys = array_keys($result);
            $nonMeta = array_filter($keys, fn($k) => $k !== '_meta');
            if (empty($nonMeta)) {
                // Check if the meta looks freshly initialised (created_at == last_accessed ~now)
                $meta = $result['_meta'] ?? [];
                if (isset($meta['created_at']) && abs($meta['created_at'] - $meta['last_accessed']) < 2) {
                    return null;
                }
            }
        }

        return $result ?: null;
    }

    /**
     * Write raw session data for a given session ID to the backend storage.
     *
     * @param string $sessionId The session ID to write
     * @param array  $data      The session data to store
     * @param int    $ttl       Optional TTL override in seconds (0 = use instance TTL)
     */
    public function write(string $sessionId, array $data, int $ttl = 0): void
    {
        $previousId = $this->sessionId;
        $previousData = $this->data;
        $previousTtl = $this->ttl;

        $this->sessionId = $sessionId;
        $this->data = $data;
        if ($ttl > 0) {
            $this->ttl = $ttl;
        }

        $this->save();

        $this->sessionId = $previousId;
        $this->data = $previousData;
        $this->ttl = $previousTtl;
    }

    // ── Storage Operations ────────────────────────────────────────

    /**
     * Load session data from the backend.
     */
    private function load(): void
    {
        match ($this->backend) {
            'redis' => $this->loadFromRedis(),
            'valkey' => $this->loadFromValkey(),
            'mongodb', 'mongo' => $this->loadFromMongo(),
            'database', 'db' => $this->loadFromDatabase(),
            default => $this->loadFromFile(),
        };
    }

    /**
     * Save session data to the backend.
     */
    public function save(): void
    {
        match ($this->backend) {
            'redis' => $this->saveToRedis(),
            'valkey' => $this->saveToValkey(),
            'mongodb', 'mongo' => $this->saveToMongo(),
            'database', 'db' => $this->saveToDatabase(),
            default => $this->saveToFile(),
        };
    }

    /**
     * Remove session data from the backend.
     */
    private function removeStorage(): void
    {
        match ($this->backend) {
            'redis' => $this->removeFromRedis(),
            'valkey' => $this->removeFromValkey(),
            'mongodb', 'mongo' => $this->removeFromMongo(),
            'database', 'db' => $this->removeFromDatabase(),
            default => $this->removeFromFile(),
        };
    }

    // ── Garbage Collection ─────────────────────────────────────────

    /**
     * Garbage-collect expired sessions from the backend.
     *
     * Only file and database backends support GC — Redis/Valkey/Mongo
     * handle TTL-based expiry natively.
     */
    public function gc(int $maxLifetime = 0): void
    {
        if ($maxLifetime > 0) {
            $this->ttl = $maxLifetime;
        }
        match ($this->backend) {
            'database', 'db' => $this->gcDatabase(),
            'file' => $this->gcFiles(),
            default => null, // Redis/Valkey/Mongo handle TTL natively
        };
    }

    /**
     * Remove expired file sessions.
     */
    private function gcFiles(): void
    {
        if (!is_dir($this->storagePath)) {
            return;
        }

        $now = time();
        foreach (glob($this->storagePath . '/*.json') as $file) {
            try {
                $content = file_get_contents($file);
                $data = json_decode($content, true);
                if (!is_array($data)) {
                    unlink($file);
                    continue;
                }

                $lastAccessed = $data['_meta']['last_accessed'] ?? 0;
                if ($lastAccessed > 0 && ($now - $lastAccessed) > $this->ttl) {
                    unlink($file);
                }
            } catch (\Throwable) {
                // Corrupt file — remove it
                @unlink($file);
            }
        }
    }

    /**
     * Remove expired database sessions.
     */
    private function gcDatabase(): void
    {
        if ($this->dbHandler !== null || class_exists(Session\DatabaseSessionHandler::class)) {
            $this->getDbHandler()->gc($this->ttl);
        }
    }

    // ── File Backend ──────────────────────────────────────────────

    /**
     * Get the file path for the current session.
     */
    private function getFilePath(): string
    {
        return $this->storagePath . '/' . $this->sessionId . '.json';
    }

    /**
     * Load session data from a JSON file.
     */
    private function loadFromFile(): void
    {
        $path = $this->getFilePath();
        if (!file_exists($path)) {
            $this->data = ['_meta' => ['created_at' => time(), 'last_accessed' => time()]];
            return;
        }

        $content = file_get_contents($path);
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            $this->data = ['_meta' => ['created_at' => time(), 'last_accessed' => time()]];
            return;
        }

        // Check TTL
        $lastAccessed = $decoded['_meta']['last_accessed'] ?? 0;
        if (time() - $lastAccessed > $this->ttl) {
            // Session expired
            $this->removeFromFile();
            $this->data = ['_meta' => ['created_at' => time(), 'last_accessed' => time()]];
            return;
        }

        $this->data = $decoded;
        $this->data['_meta']['last_accessed'] = time();
    }

    /**
     * Save session data to a JSON file.
     */
    private function saveToFile(): void
    {
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }

        file_put_contents(
            $this->getFilePath(),
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /**
     * Remove the session file.
     */
    private function removeFromFile(): void
    {
        $path = $this->getFilePath();
        if (file_exists($path)) {
            unlink($path);
        }
    }

    // ── Redis Backend (delegates to RedisSessionHandler) ────────

    private ?Session\RedisSessionHandler $redisHandler = null;

    private function getRedisHandler(): Session\RedisSessionHandler
    {
        if ($this->redisHandler === null) {
            $this->redisHandler = new Session\RedisSessionHandler();
        }
        return $this->redisHandler;
    }

    /**
     * Load session data from Redis.
     */
    private function loadFromRedis(): void
    {
        $data = $this->getRedisHandler()->read($this->sessionId);
        $this->data = $data ?: ['_meta' => ['created_at' => time(), 'last_accessed' => time()]];
        $this->data['_meta']['last_accessed'] = time();
    }

    /**
     * Save session data to Redis.
     */
    private function saveToRedis(): void
    {
        $this->getRedisHandler()->write($this->sessionId, $this->data);
    }

    /**
     * Remove session data from Redis.
     */
    private function removeFromRedis(): void
    {
        $this->getRedisHandler()->delete($this->sessionId);
    }

    // ── Valkey Backend (delegates to ValkeySessionHandler) ────────

    private ?Session\ValkeySessionHandler $valkeyHandler = null;

    private function getValkeyHandler(): Session\ValkeySessionHandler
    {
        if ($this->valkeyHandler === null) {
            $this->valkeyHandler = new Session\ValkeySessionHandler();
        }
        return $this->valkeyHandler;
    }

    private function loadFromValkey(): void
    {
        $data = $this->getValkeyHandler()->read($this->sessionId);
        $this->data = $data ?: ['_meta' => ['created_at' => time(), 'last_accessed' => time()]];
        $this->data['_meta']['last_accessed'] = time();
    }

    private function saveToValkey(): void
    {
        $this->getValkeyHandler()->write($this->sessionId, $this->data);
    }

    private function removeFromValkey(): void
    {
        $this->getValkeyHandler()->delete($this->sessionId);
    }

    // ── MongoDB Backend (delegates to MongoSessionHandler) ────────

    private ?Session\MongoSessionHandler $mongoHandler = null;

    private function getMongoHandler(): Session\MongoSessionHandler
    {
        if ($this->mongoHandler === null) {
            $this->mongoHandler = new Session\MongoSessionHandler();
        }
        return $this->mongoHandler;
    }

    private function loadFromMongo(): void
    {
        $data = $this->getMongoHandler()->read($this->sessionId);
        $this->data = $data ?: ['_meta' => ['created_at' => time(), 'last_accessed' => time()]];
        $this->data['_meta']['last_accessed'] = time();
    }

    private function saveToMongo(): void
    {
        $this->getMongoHandler()->write($this->sessionId, $this->data);
    }

    private function removeFromMongo(): void
    {
        $this->getMongoHandler()->delete($this->sessionId);
    }

    // ── Database Backend (delegates to DatabaseSessionHandler) ────

    private ?Session\DatabaseSessionHandler $dbHandler = null;

    private function getDbHandler(): Session\DatabaseSessionHandler
    {
        if ($this->dbHandler === null) {
            $this->dbHandler = new Session\DatabaseSessionHandler();
        }
        return $this->dbHandler;
    }

    private function loadFromDatabase(): void
    {
        $data = $this->getDbHandler()->read($this->sessionId);
        $this->data = $data ?: ['_meta' => ['created_at' => time(), 'last_accessed' => time()]];
        $this->data['_meta']['last_accessed'] = time();
    }

    private function saveToDatabase(): void
    {
        $this->getDbHandler()->write($this->sessionId, $this->data);
    }

    private function removeFromDatabase(): void
    {
        $this->getDbHandler()->delete($this->sessionId);
    }
}
