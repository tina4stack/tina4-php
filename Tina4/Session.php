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
    /**
     * A session id is OPAQUE — an unguessable lookup token and nothing else. It
     * is never a filename, a path, a SQL fragment or a Redis key fragment, so
     * the only characters it may contain are the ones every backend treats as
     * inert.
     *
     * The alphabet is the RFC 4648 base64url set, which is exactly what all four
     * frameworks already mint: PHP/Node hex(16), Ruby hex(32), Python
     * secrets.token_urlsafe(32). Validation is therefore non-breaking for every
     * id the family has ever issued, while rejecting the '.' and '/' that turn a
     * cookie into a path traversal.
     *
     * There is deliberately NO entropy floor here. Unguessability is guaranteed
     * by the framework's own minting (bin2hex(random_bytes(16))), not by
     * inspecting an id a trusted caller passed to start() on purpose — an app is
     * entitled to run $session->start('my-session-id') with an id it manages
     * itself, and a length rule would break that without closing any attack. The
     * vulnerability was always the ALPHABET, never the length. The 128-character
     * ceiling only bounds what an attacker can push through a backend key.
     */
    public const SESSION_ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/';

    /**
     * Every accepted backend name, aliases included. Byte-identical membership in
     * all four frameworks. This is the one place the list is written in PHP, so
     * the five match() statements below and the error message cannot disagree.
     */
    public const VALID_BACKENDS = [
        'file', 'filesystem',
        'redis',
        'valkey',
        'mongodb', 'mongo',
        'memcached', 'memcache',
        'database', 'db',
    ];

    /** Canonical name of each backend, for the error message (aliases omitted for brevity). */
    public const CANONICAL_BACKENDS = ['file', 'redis', 'valkey', 'mongodb', 'memcached', 'database'];

    /** @var string Session handler type — always a normalised member of VALID_BACKENDS */
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
     * @var bool Whether the last backend load AFFIRMATIVELY returned a stored
     *           record. Drives strict mode in start(): an id the store has
     *           never issued is discarded rather than adopted.
     */
    private bool $backendHadRecord = false;

    /**
     * @var bool Whether the last backend load THREW. Kept separate from
     *           $backendHadRecord because "the store did not answer" is not the
     *           same claim as "the store has no such session" — see start().
     */
    private bool $backendReadFailed = false;

    /** @var bool Whether the data store has unsaved changes (retained across a failed save for retry) */
    private bool $dirty = false;

    /**
     * @var bool Strict mode — when true a backend read/write/destroy/gc failure
     *           RE-RAISES instead of logging + degrading. Set via TINA4_SESSION_STRICT.
     */
    private bool $strict = false;

    /**
     * @var bool Whether the last load() actually FOUND a stored record.
     *
     * read() needs to tell "no such session" from "a live session that happens to
     * be empty", and every loadFrom*() collapses both into the same default meta
     * array. This flag carries that one bit out of the load path so read() does
     * not have to guess from the shape of the data (it used to, via a timestamp
     * heuristic that reported a cleared-but-live session as null).
     */
    private bool $loadFound = false;

    /**
     * @param string $backend 'file' or 'redis'
     * @param array  $config  Override defaults: 'path', 'ttl', 'redis_url'
     */
    public function __construct(string $backend = '', array $config = [])
    {
        $this->backend = self::resolveBackend(
            $backend ?: (getenv('TINA4_SESSION_HANDLER') ?: (getenv('TINA4_SESSION_BACKEND') ?: 'file'))
        );
        $this->ttl = (int)($config['ttl'] ?? getenv('TINA4_SESSION_TTL') ?: 3600);
        $this->storagePath = $config['path'] ?? (getenv('TINA4_SESSION_PATH') ?: 'data/sessions');
        $this->strict = DotEnv::isTruthy(DotEnv::getEnv('TINA4_SESSION_STRICT', 'false'));
    }

    /**
     * Whether $sessionId is a well-formed opaque session identifier.
     *
     * Callers pass UNTRUSTED input here (the session cookie is attacker-chosen),
     * so anything outside SESSION_ID_PATTERN is rejected.
     *
     * @param string|null $sessionId The candidate identifier
     * @return bool True when the id is safe to use as an opaque lookup token
     */
    public static function isValidSessionId(?string $sessionId): bool
    {
        return $sessionId !== null && preg_match(self::SESSION_ID_PATTERN, $sessionId) === 1;
    }

    /**
     * Start or resume a session.
     *
     * $sessionId is UNTRUSTED — it arrives from the session cookie, which the
     * client fully controls. A supplied id is adopted ONLY when it clears both
     * gates; otherwise it is discarded and a fresh one minted:
     *
     *   1. It must be a well-formed opaque identifier (SESSION_ID_PATTERN).
     *      Adopting anything else let a cookie steer a filesystem path —
     *      arbitrary read/write on the file backend.
     *   2. The backend must already hold a record for it (strict mode). An id
     *      the store never issued is not a session, so an attacker cannot plant
     *      one in the victim's browser and ride it after the victim logs in.
     *
     * BREAKING: gate 2 means every session in flight at deploy time is dropped
     * once — an existing cookie misses the store on the first request and the
     * client is issued a new, empty session. This is the intended trade.
     *
     * @param string|null $sessionId Existing session ID, or null to generate one
     * @return string The session ID actually in use (may differ from the input)
     */
    public function start(?string $sessionId = null): string
    {
        if ($sessionId !== null && !self::isValidSessionId($sessionId)) {
            $sessionId = null;
        }

        if ($sessionId !== null) {
            $this->sessionId = $sessionId;
            $this->load();

            // Strict mode (OWASP; PHP's own session.use_strict_mode=1 default):
            // only an id the STORE has actually issued may be adopted. A
            // well-formed id the backend has never seen is discarded, so an
            // attacker cannot plant a session id in the victim's browser and
            // then ride it once the victim logs in (session fixation).
            //
            // A FAILED read is deliberately NOT treated as "no such session":
            // during a backend outage that would mint a brand-new id on every
            // request, logging out every user and orphaning their stored
            // sessions. Absence must be asserted by the store, not inferred
            // from its silence.
            if (!$this->backendHadRecord && !$this->backendReadFailed) {
                $sessionId = null;
            }
        }

        if ($sessionId === null) {
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
        $this->dirty = true;
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
        $this->dirty = true;
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
        $this->dirty = true;
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
     * Call this immediately after a successful login or any privilege change:
     * issuing a fresh session ID on authentication is the standard defence
     * against session-fixation attacks (an attacker who planted a known ID
     * before login can no longer ride the authenticated session).
     *
     * @return string The new session ID
     */
    public function regenerate(): string
    {
        $oldData = $this->data;
        $oldId = $this->sessionId;

        $this->sessionId = bin2hex(random_bytes(16));
        $this->data = $oldData;
        $this->data['_meta']['last_accessed'] = time();
        $this->dirty = true;
        $this->save();

        // Best-effort removal of the old record (log + swallow on failure).
        $this->safeDestroy($oldId);

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
            $this->dirty = true;
            $this->save();
            return null;
        }
        // Get mode — read and remove
        if (!array_key_exists($flashKey, $this->data)) {
            return null;
        }

        $value = $this->data[$flashKey];
        unset($this->data[$flashKey]);
        $this->dirty = true;
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
     * Resolve the session cookie name — the single source of truth shared by the
     * WRITE side (the Set-Cookie emitted in Router::dispatch and this class's
     * cookieHeader) and the READ side (the incoming-cookie parse in
     * Router::dispatch), so a cookie written under a renamed name is read back on
     * the next request.
     *
     *   TINA4_SESSION_NAME   cookie name (default: "tina4_session")
     *
     * Keeping the name in one place means the default can never drift between the
     * two sides: setting TINA4_SESSION_NAME renames the cookie on both the emit
     * and the parse paths at once. A blank/unset value falls back to the default.
     *
     * @return string The configured session cookie name, or "tina4_session".
     */
    public static function cookieName(): string
    {
        $envName = DotEnv::getEnv('TINA4_SESSION_NAME');
        if ($envName !== null && $envName !== '') {
            return $envName;
        }
        return 'tina4_session';
    }

    /**
     * Return a Set-Cookie header value for this session.
     *
     * Env var overrides (the cookie name is only consulted when the caller keeps
     * the default "tina4_session" — an explicit name from a caller always wins):
     *   TINA4_SESSION_NAME      — cookie name (default: "tina4_session"), resolved by cookieName()
     *   TINA4_SESSION_HTTPONLY  — emit HttpOnly attr (default: "true")
     *   TINA4_SESSION_SECURE    — emit Secure attr (default: "false"; SameSite=None forces it on)
     *   TINA4_SESSION_SAMESITE  — SameSite attr (default: "Lax")
     */
    public function cookieHeader(string $cookieName = 'tina4_session'): string
    {
        // Honor env-defined cookie name only if the caller didn't override — the
        // name resolves through the one shared resolver used by the read side too.
        if ($cookieName === 'tina4_session') {
            $cookieName = self::cookieName();
        }

        $sameSite = DotEnv::getEnv('TINA4_SESSION_SAMESITE') ?: 'Lax';

        $httpOnly = DotEnv::isTruthy(DotEnv::getEnv('TINA4_SESSION_HTTPONLY', 'true'));
        // SameSite=None requires Secure per RFC — silently flip Secure on.
        $secure = DotEnv::isTruthy(DotEnv::getEnv('TINA4_SESSION_SECURE', 'false'))
            || strcasecmp($sameSite, 'None') === 0;

        $parts = ["{$cookieName}={$this->sessionId}", "Path=/"];
        if ($httpOnly) {
            $parts[] = 'HttpOnly';
        }
        if ($secure) {
            $parts[] = 'Secure';
        }
        $parts[] = "SameSite={$sameSite}";
        $parts[] = "Max-Age={$this->ttl}";

        return implode('; ', $parts);
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

        $found = $this->loadFound;
        $this->sessionId = $previousId;
        $this->data = $previousData;

        // "Not found" is reported by the load path, not guessed from the shape of
        // the data. This used to compare _meta.created_at against
        // _meta.last_accessed and call anything within 2 seconds "freshly
        // initialised, therefore absent" — which is exactly what a session that
        // was just clear()ed looks like, so a LIVE cleared session read as null
        // while its record sat on disk. An empty session is a session.
        return $found ? $result : null;
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

    // ── Backend-failure policy (log-loud + degrade) ───────────────
    //
    // The policy lives HERE, at the Session boundary — one set of wrappers for
    // every backend (file/redis/valkey/mongo/database), mirroring the Python
    // master. A backend that throws is logged ONCE via Log::error and degraded:
    //   read    -> empty session
    //   write   -> best-effort (dirty flag retained for a later retry)
    //   destroy -> swallowed
    //   gc      -> swallowed
    // A genuinely empty session (the backend returns no data WITHOUT throwing)
    // is NOT a failure and is never logged. TINA4_SESSION_STRICT=true re-raises.

    /**
     * Read raw data for $sessionId via the backend; log + degrade to an empty
     * session on failure (or re-raise when strict).
     */
    private function safeRead(string $sessionId): void
    {
        $this->backendReadFailed = false;
        try {
            $this->dispatchLoad();
        } catch (\Throwable $e) {
            $this->backendReadFailed = true;
            Log::error(
                "Session read failed ({$this->handlerLabel()}): " . $e->getMessage()
            );
            if ($this->strict) {
                throw $e;
            }
            // Degrade — start from an empty, writable session.
            $this->data = ['_meta' => ['created_at' => time(), 'last_accessed' => time()]];
        }
    }

    /**
     * Persist the current data via the backend.
     *
     * @return bool true on success; false (after logging) on a degraded write,
     *              with the dirty flag left set so a later save() can retry.
     */
    private function safeWrite(): bool
    {
        try {
            $this->dispatchSave();
            return true;
        } catch (\Throwable $e) {
            Log::error(
                "Session write failed ({$this->handlerLabel()}): " . $e->getMessage()
            );
            if ($this->strict) {
                throw $e;
            }
            return false;
        }
    }

    /**
     * Remove $sessionId from the backend; log + swallow on failure (or re-raise
     * when strict).
     */
    private function safeDestroy(string $sessionId): bool
    {
        $previousId = $this->sessionId;
        $this->sessionId = $sessionId;
        try {
            $this->dispatchRemove();
            return true;
        } catch (\Throwable $e) {
            Log::error(
                "Session destroy failed ({$this->handlerLabel()}): " . $e->getMessage()
            );
            if ($this->strict) {
                throw $e;
            }
            return false;
        } finally {
            $this->sessionId = $previousId;
        }
    }

    /**
     * Normalise a backend name and reject anything unrecognised.
     *
     * An UNKNOWN name used to fall through to the file backend at each of the
     * five match() statements below. A typo in TINA4_SESSION_BACKEND therefore
     * produced a running app writing sessions to local disk while the operator
     * believed they were in Redis: nothing logged, nothing failed, and the
     * symptom surfaced much later as users being logged out whenever a request
     * landed on another instance. Validating ONCE here means those five
     * `default =>` arms now only ever receive 'file' or 'filesystem'.
     *
     * A BLANK name still means file. An env var set to '' is a SET variable, so
     * treating blank as unknown would break every deployment that clears the var
     * to take the default.
     *
     * Normalisation (trim + lowercase) matches the Python master, so ' Redis '
     * from a .env line resolves rather than being rejected.
     *
     * @throws \InvalidArgumentException when the name is not a known backend
     */
    private static function resolveBackend(string $name): string
    {
        $normalised = strtolower(trim($name));
        if ($normalised === '') {
            return 'file';
        }
        if (!in_array($normalised, self::VALID_BACKENDS, true)) {
            throw new \InvalidArgumentException(
                'Unknown session backend "' . $normalised . '". '
                . 'Valid backends: ' . implode(', ', self::CANONICAL_BACKENDS) . '. '
                . 'Leave TINA4_SESSION_BACKEND unset for the file default.'
            );
        }
        return $normalised;
    }

    /**
     * Human-readable label for the active backend handler (for log messages).
     */
    private function handlerLabel(): string
    {
        return match ($this->backend) {
            'redis' => 'RedisSessionHandler',
            'valkey' => 'ValkeySessionHandler',
            'mongodb', 'mongo' => 'MongoSessionHandler',
            'memcached', 'memcache' => 'MemcachedSessionHandler',
            'database', 'db' => 'DatabaseSessionHandler',
            default => 'FileSessionHandler',
        };
    }

    // ── Storage Operations ────────────────────────────────────────

    /**
     * Load session data from the backend (applies the log-loud + degrade policy).
     */
    private function load(): void
    {
        $this->safeRead($this->sessionId);
    }

    /**
     * Raw backend dispatch for a load — may throw; the policy lives in safeRead().
     */
    private function dispatchLoad(): void
    {
        // Each loader sets this true only when the backend affirmatively
        // returned a stored record; "no record" and "corrupt/expired record"
        // both leave it false, which is what start()'s strict mode acts on.
        $this->backendHadRecord = false;

        match ($this->backend) {
            'redis' => $this->loadFromRedis(),
            'valkey' => $this->loadFromValkey(),
            'mongodb', 'mongo' => $this->loadFromMongo(),
            'memcached', 'memcache' => $this->loadFromMemcached(),
            'database', 'db' => $this->loadFromDatabase(),
            default => $this->loadFromFile(),
        };
    }

    /**
     * Save session data to the backend.
     *
     * Honours the log-loud + degrade policy: on a successful write the dirty
     * flag is cleared; on a degraded write it is retained so a later save()
     * retries the same data. Returns true on success, false on a degraded write.
     */
    public function save(): bool
    {
        if ($this->safeWrite()) {
            $this->dirty = false;
            return true;
        }
        // Write failed — keep dirty set for a later retry (do not crash).
        return false;
    }

    /**
     * Raw backend dispatch for a save — may throw; the policy lives in safeWrite().
     */
    private function dispatchSave(): void
    {
        match ($this->backend) {
            'redis' => $this->saveToRedis(),
            'valkey' => $this->saveToValkey(),
            'mongodb', 'mongo' => $this->saveToMongo(),
            'memcached', 'memcache' => $this->saveToMemcached(),
            'database', 'db' => $this->saveToDatabase(),
            default => $this->saveToFile(),
        };
    }

    /**
     * Remove session data from the backend (applies the log-loud + degrade policy).
     */
    private function removeStorage(): void
    {
        $this->safeDestroy($this->sessionId);
    }

    /**
     * Raw backend dispatch for a remove — may throw; the policy lives in safeDestroy().
     */
    private function dispatchRemove(): void
    {
        match ($this->backend) {
            'redis' => $this->removeFromRedis(),
            'valkey' => $this->removeFromValkey(),
            'mongodb', 'mongo' => $this->removeFromMongo(),
            'memcached', 'memcache' => $this->removeFromMemcached(),
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
        // log-loud + degrade: a GC failure is logged once and swallowed (or
        // re-raised when strict) — it must never crash the caller.
        try {
            match ($this->backend) {
                'database', 'db' => $this->gcDatabase(),
                'file' => $this->gcFiles(),
                default => null, // Redis/Valkey/Mongo handle TTL natively
            };
        } catch (\Throwable $e) {
            Log::error(
                "Session gc failed ({$this->handlerLabel()}): " . $e->getMessage()
            );
            if ($this->strict) {
                throw $e;
            }
        }
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

                // Same contract as the read path, via the same helper: an absent
                // or zero stamp is never a GC candidate.
                if ($this->fileRecordHasExpired($data['_meta'] ?? [])) {
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
     * Decide whether a stored file record has expired, from its _meta alone.
     *
     * THE CONTRACT: an ABSENT or ZERO stamp means "never expires". It is guarded
     * OUT of the comparison, never fed INTO it. This read path used to do
     * `time() - ($meta['last_accessed'] ?? 0) > $ttl`, which puts a missing stamp
     * (0) into a subtraction that is then unconditionally true for any sane ttl —
     * so a record carrying no stamp was judged infinitely old and UNLINKED on
     * read. Every mainstream implementation measured (PHP's own native files
     * handler, Django, Rails, Laravel, express-session, connect-mongo,
     * connect-redis) treats a missing expiry as valid, and none of them deletes.
     *
     * expires_at (absolute, stamped at write time) is authoritative. last_accessed
     * is only consulted for records written by an older version that carry no
     * expires_at — without that fallback every already-stored session would become
     * immortal on upgrade, which trades data loss for a security bug.
     *
     * @param array $meta The record's _meta block (may be empty)
     * @return bool True only when a stamp is genuinely PRESENT and in the PAST
     */
    private function fileRecordHasExpired(array $meta): bool
    {
        $expiresAt = (int)($meta['expires_at'] ?? 0);
        if ($expiresAt > 0) {
            return $expiresAt < time();
        }

        // Legacy record (pre-expires_at): fall back to the relative comparison.
        $lastAccessed = (int)($meta['last_accessed'] ?? 0);
        return $lastAccessed > 0 && (time() - $lastAccessed) > $this->ttl;
    }

    /**
     * Adopt the payload an external handler just returned.
     *
     * One helper for redis/valkey/memcached/mongo/database, which all previously
     * carried the same three lines. Records whether anything was actually found
     * so read() can report a genuine miss without inspecting the payload.
     *
     * @param array|null $data The handler's payload, or null/[] when there is no record
     */
    private function applyLoadedData(?array $data): void
    {
        // Two names for one fact, introduced independently on two branches:
        // loadFound (session-backend-defects) and backendHadRecord (ADR-0021,
        // which gates the auth decision). Both are read, so both are set here.
        // Unifying the name is a follow-up, not a merge-time decision.
        $this->loadFound = !empty($data);
        $this->backendHadRecord = !empty($data);
        $this->data = $data ?: ['_meta' => ['created_at' => time(), 'last_accessed' => time()]];
        $this->data['_meta']['last_accessed'] = time();
    }

    /**
     * Get the file path for the current session.
     *
     * TWO independent defences, because read() and write() take a
     * caller-supplied id straight from the application and never pass through
     * start():
     *
     *   1. A malformed id is REFUSED outright. '../../outside/pwned' would
     *      otherwise resolve to an attacker-chosen path anywhere the worker can
     *      write.
     *   2. The filename is the SHA-256 of the id, never the id itself (parity
     *      with the Python master's FileSessionHandler._file). Even if a future
     *      change loosened gate 1, a hex digest cannot contain a path separator
     *      or a '..' segment. It also keeps the raw session id — a bearer
     *      credential — out of directory listings, backups and log output.
     *
     * The hash is NOT a substitute for the validation; both stay.
     *
     * @return string Absolute-or-relative path of this session's JSON file
     * @throws \InvalidArgumentException When the current session id is malformed
     */
    private function getFilePath(): string
    {
        if (!self::isValidSessionId($this->sessionId)) {
            throw new \InvalidArgumentException(
                'Refusing to derive a session file path from a malformed session id'
            );
        }

        return $this->storagePath . '/' . hash('sha256', $this->sessionId) . '.json';
    }

    /**
     * Load session data from a JSON file.
     */
    private function loadFromFile(): void
    {
        $this->loadFound = false;
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

        if ($this->fileRecordHasExpired($decoded['_meta'] ?? [])) {
            $this->removeFromFile();
            $this->data = ['_meta' => ['created_at' => time(), 'last_accessed' => time()]];
            return;
        }

        $this->loadFound = true;
        $this->data = $decoded;
        $this->data['_meta']['last_accessed'] = time();
        $this->backendHadRecord = true;
    }

    /**
     * Save session data to a JSON file.
     */
    private function saveToFile(): void
    {
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }

        // The PERSISTENCE LAYER owns the expiry stamp, never the caller's payload.
        // Session::write($id, $data) assigns $data verbatim, so without this a
        // record written through the public write()/read() facade carried no
        // stamp at all — and the read path then had to guess what that meant.
        // Every mainstream implementation stamps on its own write; so do we.
        //
        // expires_at is an ABSOLUTE deadline computed from the ttl HERE, at write
        // time. That is what makes a per-call ttl durable: read() compares against
        // the stored deadline and never needs to know what the ttl was, so it can
        // no longer judge a record against whatever ttl the READER happens to
        // carry. A ttl of 0 stores 0, which means "never expires".
        $this->data['_meta']['last_accessed'] = time();
        $this->data['_meta']['created_at'] ??= time();
        $this->data['_meta']['expires_at'] = $this->ttl > 0 ? time() + $this->ttl : 0;

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
        $this->applyLoadedData($this->getRedisHandler()->read($this->sessionId));
    }

    /**
     * Save session data to Redis.
     */
    private function saveToRedis(): void
    {
        $this->getRedisHandler()->write($this->sessionId, $this->data, $this->ttl);
    }

    /**
     * Remove session data from Redis.
     */
    private function removeFromRedis(): void
    {
        $this->getRedisHandler()->destroy($this->sessionId);
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
        $this->applyLoadedData($this->getValkeyHandler()->read($this->sessionId));
    }

    private function saveToValkey(): void
    {
        $this->getValkeyHandler()->write($this->sessionId, $this->data, $this->ttl);
    }

    private function removeFromValkey(): void
    {
        $this->getValkeyHandler()->destroy($this->sessionId);
    }

    // ── Memcached Backend (delegates to MemcachedSessionHandler) ──

    private ?Session\MemcachedSessionHandler $memcachedHandler = null;

    private function getMemcachedHandler(): Session\MemcachedSessionHandler
    {
        if ($this->memcachedHandler === null) {
            $this->memcachedHandler = new Session\MemcachedSessionHandler();
        }
        return $this->memcachedHandler;
    }

    private function loadFromMemcached(): void
    {
        $this->applyLoadedData($this->getMemcachedHandler()->read($this->sessionId));
    }

    private function saveToMemcached(): void
    {
        $this->getMemcachedHandler()->write($this->sessionId, $this->data, $this->ttl);
    }

    private function removeFromMemcached(): void
    {
        $this->getMemcachedHandler()->destroy($this->sessionId);
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
        $this->applyLoadedData($this->getMongoHandler()->read($this->sessionId));
    }

    private function saveToMongo(): void
    {
        $this->getMongoHandler()->write($this->sessionId, $this->data, $this->ttl);
    }

    private function removeFromMongo(): void
    {
        $this->getMongoHandler()->destroy($this->sessionId);
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
        $this->applyLoadedData($this->getDbHandler()->read($this->sessionId));
    }

    private function saveToDatabase(): void
    {
        $this->getDbHandler()->write($this->sessionId, $this->data, $this->ttl);
    }

    private function removeFromDatabase(): void
    {
        $this->getDbHandler()->destroy($this->sessionId);
    }
}
