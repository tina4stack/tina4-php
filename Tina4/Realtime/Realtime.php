<?php

namespace Tina4\Realtime;

use Tina4\Router;
use Tina4\Auth;
use Tina4\QueryBuilder;
use Tina4\Log;

/**
 * Real-time collaboration mount for Tina4 (PHP). Parity with the Python
 * tina4_python.realtime package: calls (mesh WebRTC signalling), chat (secured
 * channels + presence/typing/receipts + history), files (pluggable storage).
 *
 * Paths, env vars, JSON shapes and route names are identical across frameworks;
 * only the language changes. Media is peer-to-peer (mesh) — the server relays
 * the offer/answer/ICE handshake and never reads the SDP.
 *
 * Usage (in your app bootstrap, before the server starts):
 *   \Tina4\Realtime\Realtime::mount();                              // calls only
 *   \Tina4\Realtime\Realtime::mount('', ['features' => ['calls','chat','files']]);
 *
 * Env: TINA4_RTC_BACKEND, TINA4_RTC_STUN_URLS, TINA4_RTC_TURN_URL,
 * TINA4_RTC_TURN_SECRET, TINA4_RTC_TURN_TTL, TINA4_STORAGE_*.
 */
final class Realtime
{
    /** @var callable|null app-provided membership guard (identity, channelId): bool */
    private static $authorize = null;
    private static ?StorageBackend $storage = null;

    public const DEFAULT_STUN = 'stun:stun.l.google.com:19302';

    /**
     * Build the ICE server list from the environment. Always includes STUN;
     * adds a TURN entry with time-limited coturn use-auth-secret credentials
     * (username = expiry-epoch, credential = base64(HMAC-SHA1(secret, username)))
     * when TINA4_RTC_TURN_URL + TINA4_RTC_TURN_SECRET are set.
     */
    public static function iceServers(): array
    {
        $stun = getenv('TINA4_RTC_STUN_URLS') ?: self::DEFAULT_STUN;
        $servers = [['urls' => self::splitUrls($stun)]];

        $turnUrl = getenv('TINA4_RTC_TURN_URL');
        $secret = getenv('TINA4_RTC_TURN_SECRET');
        if ($turnUrl && $secret) {
            $ttl = (int)(getenv('TINA4_RTC_TURN_TTL') ?: 3600);
            $username = (string)(time() + $ttl);
            $credential = base64_encode(hash_hmac('sha1', $username, $secret, true));
            $servers[] = [
                'urls' => self::splitUrls($turnUrl),
                'username' => $username,
                'credential' => $credential,
            ];
        }
        return $servers;
    }

    private static function splitUrls(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv))));
    }

    /**
     * Mount the realtime surface and return the resolved path map.
     *
     * @param array{media?:mixed,storage?:StorageBackend,authorize?:callable,features?:string[]} $options
     * @return array<string,string>
     */
    public static function mount(string $prefix = '', array $options = []): array
    {
        $features = $options['features'] ?? ['calls'];
        $p = trim($prefix, '/');
        $p = $p === '' ? '' : '/' . $p;
        $backendName = strtolower((string)(getenv('TINA4_RTC_BACKEND') ?: 'mesh')); // mesh only in phase 1
        self::$authorize = $options['authorize'] ?? null;
        self::$storage = $options['storage'] ?? null;

        $paths = ['backend' => $backendName === '' ? 'mesh' : 'mesh'];
        if (in_array('calls', $features, true)) {
            $paths['config'] = "{$p}/api/rtc/config";
            $paths['signalling'] = "{$p}/ws/rtc";
        }
        if (in_array('chat', $features, true)) {
            $paths['config'] ??= "{$p}/api/rtc/config";
            $paths['chat'] = "{$p}/ws/chat";
            $paths['messages'] = "{$p}/api/channels";
        }
        if (in_array('files', $features, true)) {
            $paths['config'] ??= "{$p}/api/rtc/config";
            $paths['files'] = "{$p}/api/files";
        }

        if (in_array('chat', $features, true) || in_array('files', $features, true)) {
            self::ensureChatTables();
        }

        // ── Self-describing bootstrap ──
        if (isset($paths['config'])) {
            Router::get($paths['config'], function ($request, $response) use ($paths, $features) {
                $body = ['backend' => 'mesh'];
                if (in_array('calls', $features, true)) {
                    $body['iceServers'] = self::iceServers();
                    $body['signalling'] = $paths['signalling'] . '/{room}';
                }
                if (in_array('chat', $features, true)) {
                    $body['chat'] = $paths['chat'] . '/{channel}';
                    $body['messages'] = $paths['messages'] . '/{id}/messages';
                }
                if (in_array('files', $features, true)) {
                    $body['files'] = $paths['files'];
                }
                return $response($body);
            })->noAuth();
        }

        // ── calls: WebRTC signalling relay (mesh) ──
        if (in_array('calls', $features, true)) {
            Router::websocket($paths['signalling'] . '/{room}', function ($connection, $data, $event) {
                $room = $connection->params['room'] ?? '';
                if ($room === '') {
                    return;
                }
                $key = 'rtc:' . $room;
                if ($event === 'open') {
                    $connection->joinRoom($key);
                } elseif ($event === 'message') {
                    // Relay the raw signalling payload to the other peers; the
                    // server never parses the SDP, peers filter by `to`.
                    $connection->broadcastToRoom($key, (string)$data, true);
                }
            });
        }

        // ── chat: persistent channels + presence/typing/receipts ──
        if (in_array('chat', $features, true)) {
            Router::websocket($paths['chat'] . '/{channel}', [self::class, 'chatHandler'], secure: true);

            Router::get($paths['messages'] . '/{id}/messages', function ($request, $response, $id) {
                $identity = self::identity(self::reqAuth($request));
                $channelId = (int)$id;
                if (!self::authorized($identity, $channelId)) {
                    return $response(['error' => 'forbidden'], 403);
                }
                return $response(self::history($channelId, $request->query ?? []));
            })->secure();
        }

        // ── files: permissioned upload / download via a StorageBackend ──
        if (in_array('files', $features, true)) {
            $store = Storage::select(self::$storage);

            Router::post($paths['files'], function ($request, $response) use ($store, $paths) {
                $identity = self::identity(self::reqAuth($request));
                $channelId = (int)self::formValue($request, 'channel_id');
                if ($channelId <= 0) {
                    return $response(['error' => 'channel_id is required'], 400);
                }
                if (!self::authorized($identity, $channelId)) {
                    return $response(['error' => 'forbidden'], 403);
                }
                $upload = $request->files['file'] ?? null;
                if (!$upload) {
                    return $response(['error' => "no file uploaded (field 'file')"], 400);
                }
                $saved = self::storeUpload($store, $channelId, $upload);
                $saved['url'] = $store->url($saved['key']) ?? ($paths['files'] . '/' . $saved['key']);
                return $response($saved, 201);
            });

            Router::get($paths['files'] . '/{key}', function ($request, $response, $key) use ($store) {
                $identity = self::identity(self::reqAuth($request));
                $rows = (new Attachment())->where('storage_key = ?', [$key], 1);
                // where() returns a ModelCollection (ADR-0064); empty() is always
                // false on an object, so test the page size via count().
                if (count($rows) === 0) {
                    return $response(['error' => 'not found'], 404);
                }
                $att = $rows[0];
                if (!self::authorized($identity, (int)$att->channelId)) {
                    return $response(['error' => 'forbidden'], 403);
                }
                $direct = $store->url($key);
                if ($direct !== null) {
                    return $response->redirect($direct, 302);
                }
                $data = $store->get($key);
                if ($data === null) {
                    return $response(['error' => 'not found'], 404);
                }
                $response->header('Content-Disposition',
                    'inline; filename="' . ($att->filename ?: $key) . '"');
                return $response($data, 200, $att->mime ?: 'application/octet-stream');
            })->secure();
        }

        return $paths;
    }

    /**
     * Secured chat WebSocket handler. PHP fires ($connection, $data, $event).
     */
    public static function chatHandler($connection, $data, $event): void
    {
        $raw = $connection->params['channel'] ?? '';
        if (!ctype_digit((string)$raw)) {
            return; // framework channels are addressed by integer id
        }
        $channelId = (int)$raw;
        $identity = self::identity($connection->auth);
        $key = 'chat:' . $channelId;

        if ($event === 'open') {
            if (!self::authorized($identity, $channelId)) {
                $connection->sendJson(['type' => 'error', 'error' => 'not a member of this channel']);
                $connection->close();
                return;
            }
            $connection->joinRoom($key);
            $connection->sendJson(['type' => 'presence', 'event' => 'roster',
                'users' => self::roster($connection, $key)]);
            $connection->broadcastToRoom($key, json_encode([
                'type' => 'presence', 'event' => 'join', 'user_id' => $identity]), true);
            return;
        }

        if ($event === 'close') {
            $connection->broadcastToRoom($key, json_encode([
                'type' => 'presence', 'event' => 'leave', 'user_id' => $identity]), true);
            return;
        }

        if ($event !== 'message') {
            return;
        }

        // Re-check membership on every frame (it can be revoked mid-session).
        if (!self::authorized($identity, $channelId)) {
            return;
        }
        $payload = json_decode((string)$data, true);
        if (!is_array($payload)) {
            return;
        }
        $kind = $payload['type'] ?? 'message';

        if ($kind === 'typing') {
            $connection->broadcastToRoom($key, json_encode([
                'type' => 'typing', 'user_id' => $identity]), true);
            return;
        }
        if ($kind === 'read') {
            self::markRead($channelId, $identity);
            $connection->broadcastToRoom($key, json_encode([
                'type' => 'read', 'user_id' => $identity, 'at' => self::nowIso()]), true);
            return;
        }
        if ($kind === 'message') {
            $bodyText = trim((string)($payload['body'] ?? ''));
            if ($bodyText === '') {
                return;
            }
            $saved = self::persistMessage($channelId, $identity, $bodyText, $payload['thread_id'] ?? null);
            if ($saved !== null) {
                // Deliver to everyone INCLUDING the sender (id + timestamp reconcile).
                $connection->broadcastToRoom($key, json_encode(['type' => 'message', 'message' => $saved]), false);
            }
        }
    }

    // ── helpers (parity with Python's module-level helpers) ──

    private static function identity($auth): ?string
    {
        if (!is_array($auth)) {
            return null;
        }
        foreach (['user_id', 'sub', 'id'] as $claim) {
            if (isset($auth[$claim]) && $auth[$claim] !== null) {
                return (string)$auth[$claim];
            }
        }
        return null;
    }

    private static function authorized(?string $identity, int $channelId): bool
    {
        if ($identity === null) {
            return false;
        }
        if (self::$authorize !== null) {
            return (bool)(self::$authorize)($identity, $channelId);
        }
        try {
            return (new ChannelMember())->count('channel_id = ? AND user_id = ?', [$channelId, $identity]) > 0;
        } catch (\Throwable $e) {
            Log::error('realtime chat authorize check failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function roster($connection, string $key): array
    {
        // Collect into a list (NOT array keys): PHP coerces numeric-string keys
        // to ints, which would send [1,2] instead of ["1","2"] and break the
        // client's string comparison.
        $users = [];
        foreach ($connection->getRoomConnections($key) as $conn) {
            $id = self::identity($conn->auth ?? null);
            if ($id !== null && !in_array($id, $users, true)) {
                $users[] = $id;
            }
        }
        sort($users);
        return $users;
    }

    /**
     * The authenticated identity for an HTTP request. All realtime HTTP routes
     * run behind framework auth (secured GET / auth-required POST), so the
     * router has already validated the JWT and exposed its decoded payload on
     * $request->user — use that single source of truth rather than re-parsing
     * the Authorization header (which the built-in server does not surface
     * identically for every method).
     */
    private static function reqAuth($request): ?array
    {
        $user = $request->user ?? null;
        return is_array($user) ? $user : null;
    }

    private static function nowIso(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private static function formValue($request, string $name)
    {
        $body = $request->body ?? null;
        if (is_array($body) && array_key_exists($name, $body)) {
            return $body[$name];
        }
        return $request->query[$name] ?? ($request->params[$name] ?? null);
    }

    private static function persistMessage(int $channelId, ?string $identity, string $body, $threadId): ?array
    {
        $thread = ($threadId === null || $threadId === '') ? null : (int)$threadId;
        $createdAt = self::nowIso();
        $msg = new Message();
        $msg->channelId = $channelId;
        $msg->userId = (string)$identity;
        $msg->body = $body;
        $msg->threadId = $thread;
        $msg->createdAt = $createdAt;
        if ($msg->save() === false) {
            Log::error("realtime chat: failed to persist message in channel {$channelId}");
            return null;
        }
        return [
            'id' => $msg->id,
            'channel_id' => $channelId,
            'user_id' => (string)$identity,
            'body' => $body,
            'thread_id' => $thread,
            'created_at' => $createdAt,
        ];
    }

    private static function markRead(int $channelId, ?string $identity): void
    {
        try {
            $rows = (new ChannelMember())->where('channel_id = ? AND user_id = ?', [$channelId, $identity], 1);
            // where() returns a ModelCollection (ADR-0064); empty() is always
            // false on an object, so test the page size via count().
            if (count($rows) > 0) {
                $member = $rows[0];
                $member->lastReadAt = self::nowIso();
                $member->save();
            }
        } catch (\Throwable $e) {
            Log::error('realtime chat: read-receipt update failed: ' . $e->getMessage());
        }
    }

    private static function history(int $channelId, array $query): array
    {
        $limit = (int)($query['limit'] ?? 50);
        $limit = max(1, min($limit, 200));
        $clause = 'channel_id = ?';
        $params = [$channelId];
        $before = $query['before'] ?? null;
        if ($before !== null && $before !== '') {
            $clause .= ' AND id < ?';
            $params[] = (int)$before;
        }
        $rows = QueryBuilder::fromTable('tina4_rt_messages')
            ->select('*')
            ->where($clause, $params)
            ->orderBy('id DESC')
            ->limit($limit)
            ->get();
        $items = [];
        foreach ($rows as $r) {
            $r = (array)$r;
            $items[] = [
                'id' => (int)$r['id'],
                'channel_id' => (int)$r['channel_id'],
                'user_id' => (string)$r['user_id'],
                'body' => $r['body'],
                'thread_id' => isset($r['thread_id']) ? ($r['thread_id'] === null ? null : (int)$r['thread_id']) : null,
                'created_at' => $r['created_at'] ?? null,
            ];
        }
        return $items;
    }

    private static function storeUpload(StorageBackend $store, int $channelId, array $upload): array
    {
        $filename = $upload['filename'] ?? 'file';
        $mime = $upload['type'] ?? 'application/octet-stream';
        $content = $upload['content'] ?? '';
        $key = Storage::key($filename);
        $store->put($key, $content, $mime);
        $att = new Attachment();
        $att->channelId = $channelId;
        $att->storageKey = $key;
        $att->filename = $filename;
        $att->mime = $mime;
        $att->size = strlen($content);
        $att->save();
        return [
            'id' => $att->id,
            'key' => $key,
            'filename' => $filename,
            'mime' => $mime,
            'size' => strlen($content),
        ];
    }

    private static function ensureChatTables(): void
    {
        try {
            foreach ([Workspace::class, Channel::class, ChannelMember::class, Message::class, Attachment::class] as $model) {
                (new $model())->createTable();
            }
        } catch (\Throwable $e) {
            Log::error(
                'realtime chat could not create its tables (' . $e->getMessage()
                . '). Chat needs a bound database -- bind one before mounting realtime with chat/files.'
            );
        }
    }
}
