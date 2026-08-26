# Realtime Collaboration (PHP)

Real-time collaboration control plane for tina4-php: a WebRTC **signalling relay** (mesh,
peer-to-peer calls), persistent **chat** (channels/messages/presence/typing/read-receipts), and
**file** upload/download. Tina4 carries no media — it only relays the WebRTC offer/answer/ICE
handshake and **never parses the SDP**.

- **Source of truth:** `Tina4/Realtime/Realtime.php`
- **ORM models:** `Tina4/Realtime/` — `Workspace.php`, `Channel.php`, `ChannelMember.php`,
  `Message.php`, `Attachment.php` (all `Tina4\Realtime\*`, extend `\Tina4\ORM`)
- **Storage:** `Tina4/Realtime/Storage.php` (selector + key gen), `StorageBackend.php` (interface),
  `LocalStorage.php`, `S3Storage.php`

This is a coordinated **cross-language** feature — the routes, JSON shapes, env vars, and
`tina4_rt_*` tables are identical across tina4-python / -php / -ruby / -nodejs. Only the language
changes. Paths verified against `Realtime.php`.

> **PHP-vs-other-languages gotchas** (read before porting any Python/Node example):
> - **The WS handler arg order is `($connection, $data, $event)`** — Python/Node fire
>   `(connection, event, data)`. Get this wrong and `$event` holds your payload.
> - **HTTP identity comes from `$request->user`** (the router-attached, already-verified JWT
>   payload) — PHP does NOT re-parse the `Authorization` header the way the Python HTTP handlers do.
> - **WS connection methods are camelCase** (`joinRoom`, `broadcastToRoom`, `sendJson`,
>   `getRoomConnections`) — Python uses `join_room`, `broadcast_to_room`, etc.
> - **`backend` is hardcoded to `'mesh'`** in the path map and config body regardless of
>   `TINA4_RTC_BACKEND` (Phase-1 shortcut).
>
> Pairs with the frontend **`tina4-js` `rtc` module** (`rtcConfig()`, the call/chat/file clients),
> which fetches `/api/rtc/config` and fills in the `{room}` / `{channel}` / `{id}` tokens so the
> client never hardcodes a path.

---

## Mounting: `\Tina4\Realtime\Realtime::mount(string $prefix = '', array $options = []): array`

Call this **once in your app bootstrap, before the server starts** (e.g. in `index.php` after
`new \Tina4\App()`, or a `src/` bootstrap file). It registers the routes and **returns the resolved
path map** (also served from `/api/rtc/config`, so the client discovers paths instead of
hardcoding them).

```php
use Tina4\Realtime\Realtime;

Realtime::mount();                                                    // calls only (default)
Realtime::mount('', ['features' => ['calls', 'chat']]);              // add persistent chat
Realtime::mount('/api/collab', ['features' => ['calls','chat','files']]); // relocate whole surface
```

`$options` keys:

| key | meaning |
|---|---|
| `features` | `string[]`, any of `"calls"`, `"chat"`, `"files"`. **Default `["calls"]`.** |
| `media` | a media-plane backend object. Defaults to the env-selected backend (mesh in Phase 1). |
| `authorize` | `callable(string $identity, int $channelId): bool` — membership guard for `chat`/`files`. Defaults to a `ChannelMember` membership check. `$identity` is the **string** user id from the JWT. |
| `storage` | a `StorageBackend` for the `files` feature. Defaults to the env-selected store (`local`). |

`$prefix` mounts the whole surface under `/<prefix>` (default: root). It is normalised with
`trim($prefix, '/')`, so `'/api/collab'`, `'api/collab'`, and `'api/collab/'` all resolve the same.

### Returned path map (verified)

```php
Realtime::mount();
// ['backend'=>'mesh', 'config'=>'/api/rtc/config', 'signalling'=>'/ws/rtc']

Realtime::mount('', ['features'=>['calls','chat']]);
// ['backend'=>'mesh', 'config'=>'/api/rtc/config', 'signalling'=>'/ws/rtc',
//  'chat'=>'/ws/chat', 'messages'=>'/api/channels']

Realtime::mount('', ['features'=>['files']]);
// ['backend'=>'mesh', 'config'=>'/api/rtc/config', 'files'=>'/api/files']
```

`config` is added by **any** enabled feature (`calls` sets it; `chat`/`files` use `??=`). So even a
chat-only or files-only mount still exposes `/api/rtc/config`. The map holds the **base** paths;
the config endpoint appends the template tokens (`/{room}`, `/{channel}`, `/{id}/messages`).

### What it wires (per feature)

| feature | route registered | auth |
|---|---|---|
| any | `GET  {p}/api/rtc/config` | **public** — `->noAuth()` |
| `calls` | `WS   {p}/ws/rtc/{room}` | **public** (unauthenticated) |
| `chat` | `WS   {p}/ws/chat/{channel}` | **secured** — `Router::websocket(..., secure: true)`; valid JWT required on upgrade |
| `chat` | `GET  {p}/api/channels/{id}/messages` | **secured** — `->secure()` |
| `files` | `POST {p}/api/files` | **Bearer-protected by default** (write route — no `->noAuth()`) |
| `files` | `GET  {p}/api/files/{key}` | **secured** — `->secure()` |

If `chat` or `files` is enabled, `ensureChatTables()` runs at mount time (see Footguns).

---

## `GET {p}/api/rtc/config` — public bootstrap

The public config the frontend fetches (the tina4-js `rtcConfig()` helper) so client and server
never drift. Registered with `->noAuth()`. Body is feature-gated (only keys for enabled features
appear):

```jsonc
{
  "backend": "mesh",
  "iceServers": [ /* iceServers() output */ ],  // calls
  "signalling": "/ws/rtc/{room}",               // calls
  "chat": "/ws/chat/{channel}",                 // chat
  "messages": "/api/channels/{id}/messages",    // chat
  "files": "/api/files"                          // files
}
```

`{room}` / `{channel}` / `{id}` are literal template tokens the client fills in.

---

## `\Tina4\Realtime\Realtime::iceServers(): array` — ICE/TURN config

Public static. Builds the ICE server list from the environment. **Always** includes a STUN entry.
Adds a TURN entry with time-limited coturn `use-auth-secret` credentials **only when both**
`TINA4_RTC_TURN_URL` and `TINA4_RTC_TURN_SECRET` are set.

TURN credential scheme (verified): `username = (string)(time() + $ttl)`,
`credential = base64_encode(hash_hmac('sha1', $username, $secret, true))`.

```php
// no TURN env:
[['urls' => ['stun:stun.l.google.com:19302']]]

// TINA4_RTC_TURN_URL + TINA4_RTC_TURN_SECRET set:
[['urls' => ['stun:stun.l.google.com:19302']],
 ['urls' => ['turn:turn.example.com:3478'], 'username' => '1783546725', 'credential' => 'ie7Mm…==']]
```

### Env vars

| var | default | effect |
|---|---|---|
| `TINA4_RTC_BACKEND` | `mesh` | media backend name; only `mesh` ships in Phase 1. **Note: the reported `backend` is hardcoded to `mesh` regardless of this value.** |
| `TINA4_RTC_STUN_URLS` | `stun:stun.l.google.com:19302` | comma-separated STUN URLs. |
| `TINA4_RTC_TURN_URL` | — | comma-separated TURN URLs; enables TURN when set with the secret. |
| `TINA4_RTC_TURN_SECRET` | — | coturn `use-auth-secret` shared secret (ephemeral creds). |
| `TINA4_RTC_TURN_TTL` | `3600` | ephemeral TURN credential lifetime (seconds). |

---

## Calls: signalling relay — `WS {p}/ws/rtc/{room}` (public)

Registered unauthenticated (no `secure:` flag). The handler follows the **PHP WebSocket handler
convention** `($connection, $data, $event)`:

```php
Router::websocket($paths['signalling'] . '/{room}', function ($connection, $data, $event) {
    // $connection : the WebSocket connection
    // $data       : payload — string on "message", null on "open"/"close"
    // $event      : "open" | "message" | "close"
});
```

Signalling behaviour (mesh relay):

- Reads `$room = $connection->params['room'] ?? '';` — empty room → returns (no-op).
- `$event === 'open'` → `$connection->joinRoom("rtc:{$room}")`.
- `$event === 'message'` → `$connection->broadcastToRoom("rtc:{$room}", (string)$data, true)` —
  relays the **raw** payload to the other peers (`true` = exclude self). Tina4 never parses the SDP;
  peers filter by a `to` field themselves.

Rooms are namespaced `rtc:<room>` so signalling rooms never collide with chat channels
(`chat:<channel>`) sharing the same WebSocket manager.

`WebSocketConnection` surface used across realtime (all camelCase in PHP): `$connection->params`,
`$connection->auth`, `$connection->joinRoom($name)`,
`$connection->broadcastToRoom($name, $message, $excludeSelf)`, `$connection->sendJson($data)`,
`$connection->close()`, `$connection->getRoomConnections($key)`.

---

## Chat: `WS {p}/ws/chat/{channel}` (secured)

Handler `Realtime::chatHandler($connection, $data, $event)`, registered with
`Router::websocket(..., secure: true)` — a **valid JWT is required on the upgrade**; an
unauthenticated upgrade is rejected by the router before the handler runs.

- Channel is addressed by **integer id**: the handler bails via `ctype_digit()` if `{channel}` is
  non-numeric (the socket opens and does nothing — no error frame).
- `$identity = self::identity($connection->auth)` — the string user id from the verified JWT.
- Room key is `chat:<channelId>`.

Event flow (inbound frames are JSON; broadcasts are `json_encode(...)` strings):

| event / message `type` | server behaviour |
|---|---|
| `open` | authorize; **fail →** `sendJson(['type'=>'error','error'=>'not a member of this channel'])` then `close()`. **ok →** `joinRoom`, send caller the roster `{"type":"presence","event":"roster","users":[…]}`, then broadcast `{"type":"presence","event":"join","user_id":<id>}` (exclude self). |
| `close` | broadcast `{"type":"presence","event":"leave","user_id":<id>}` (exclude self). |
| `typing` | broadcast `{"type":"typing","user_id":<id>}` (exclude self). |
| `read` | advance the member's read cursor (`last_read_at = now`), broadcast `{"type":"read","user_id":<id>,"at":<iso>}` (exclude self). |
| `message` | trim `body`; empty → ignored. Persist a `Message` row; on success broadcast `{"type":"message","message":<saved>}` to **everyone including the sender** (`broadcastToRoom(..., false)` — so the sender's optimistic message reconciles with its server `id` + `created_at`). |

`type` defaults to `"message"` when absent; unknown `type` values are ignored. **Authorization is
re-checked on every inbound frame**, not just on join — membership can be revoked mid-session, and
the server never trusts an identity carried in the payload. Keep a custom `authorize` cheap: it runs
on every message.

The roster (`users`) is the sorted set of distinct identities currently in the room, derived from
each live connection's `auth`. It is built as a **list, not array keys** — PHP would coerce
numeric-string keys to ints and send `[1,2]` instead of `["1","2"]`, breaking the client's string
comparison.

Saved-message JSON shape (also returned by history):

```jsonc
{ "id": <int>, "channel_id": <int>, "user_id": "<str>", "body": "<str>",
  "thread_id": <int|null>, "created_at": "<iso8601>" }
```

`thread_id` is `null` for a top-level message, or the parent message id for a threaded reply.

---

## Chat history: `GET {p}/api/channels/{id}/messages` (secured)

Catch-up-on-reconnect endpoint, chained with `->secure()`.

- Identity comes from `$request->user` (router-attached, already verified). Not authorized → `403`.
- Query params: `before` (return messages with `id < before`) and `limit` (default **50**, clamped
  to **1..200**).
- Returns messages **newest-first** (`ORDER BY id DESC` in SQL, via
  `QueryBuilder::fromTable('tina4_rt_messages')`), the standard infinite-scroll-backwards shape.
  Each item has the saved-message JSON shape above.

---

## Files: upload / download

Enabled by `features=['files']`; uses a `StorageBackend` (the `storage` option or the env-selected
store, default `LocalStorage`). The backend is resolved once at mount via `Storage::select($storage)`.

### `POST {p}/api/files` — upload (Bearer-protected by default)

- Multipart: file field **`file`** (`$request->files['file']`), plus form field **`channel_id`**
  (required integer, read from body/query/params).
- Missing/invalid `channel_id` → `400`; not a channel member → `403`; no file → `400`.
- Stores the blob under an opaque, collision-free `storage_key` (`Storage::key()` — random 16 bytes
  hex + sanitized extension, never a user-controlled path), inserts an `Attachment` row (metadata
  only), and responds **`201`**:

```jsonc
{ "id": <int>, "key": "<storage_key>", "filename": "<str>", "mime": "<str>",
  "size": <int>, "url": "<direct url OR {files}/{key}>" }
```

`url` is `$store->url($key)` when the backend exposes a direct URL (e.g. S3 presigned), else the app
download route `{files}/{key}`.

### `GET {p}/api/files/{key}` — download (secured)

Chained with `->secure()`.

- Looks up the `Attachment` by `storage_key`; missing → `404`. Authorizes against the attachment's
  `channelId`; non-member → `403`.
- If the backend has a direct URL → **`302`** redirect (`$response->redirect($direct, 302)`).
  Otherwise **streams the bytes** (`200`) with `Content-Disposition: inline; filename="…"` and
  `Content-Type = $attachment->mime` (default `application/octet-stream`).

### Storage backends (`Storage.php`, `StorageBackend.php`)

`Storage::select(?StorageBackend $storage = null)` resolves from the `storage` option or
`TINA4_STORAGE_BACKEND` (`local` default | `s3`). `S3Storage` requires the AWS SDK
(`aws/aws-sdk-php`); if it can't be built (SDK missing, or `TINA4_STORAGE_BUCKET` unset) it **falls
back to `LocalStorage`** with a logged warning — a real store, never a silent no-op.

The `StorageBackend` interface: `put($key,$data,$mime)`, `get($key): ?string`,
`url($key,$ttl=3600): ?string`, `delete($key)`, `exists($key): bool`.

| var | default | effect |
|---|---|---|
| `TINA4_STORAGE_BACKEND` | `local` | `local` \| `s3`. |
| `TINA4_STORAGE_DIR` | `data/rt_storage` | local filesystem directory. |
| `TINA4_STORAGE_URL` | — | S3 endpoint URL (S3-compatible / MinIO → path-style addressing). |
| `TINA4_STORAGE_KEY` / `TINA4_STORAGE_SECRET` | — | S3 credentials. |
| `TINA4_STORAGE_BUCKET` | — | S3 bucket (required for S3). |
| `TINA4_STORAGE_REGION` | `us-east-1` | S3 region. |

`LocalStorage` resolves every key inside its root and rejects path traversal (keys containing `/`,
`\`, `..`, or NUL); `url()` returns `null` (served by the permissioned download route). `S3Storage`
returns a presigned GET URL from `url()` so clients fetch large blobs straight from object storage.

---

## Auth & identity (PHP specifics)

- **`Realtime::identity($auth): ?string`** — extracts a stable **string** user id from a verified
  JWT payload array, trying claims **`user_id` → `sub` → `id`** in order; returns `null` if `$auth`
  is not an array or none of those claims are present. Identities round-trip as strings, so an int
  id, a UUID, or an email all work.
- **WS identity** comes from `$connection->auth` (the verified JWT payload the router attached on the
  secured chat upgrade).
- **HTTP identity** comes from **`$request->user`** (via the private `reqAuth($request)` helper) —
  the router has already validated the JWT on the secured/Bearer-protected route and exposed its
  decoded payload there. This is the single source of truth in PHP; do **not** re-parse the
  `Authorization` header yourself.
- **Default guard** — the user must be a member of the channel
  (`(new ChannelMember())->count('channel_id = ? AND user_id = ?', [$channelId, $identity]) > 0`).
  Any exception logs and returns `false` (deny).
- **`authorize` overrides it** — pass `authorize(string $identity, int $channelId): bool`. Use it to,
  e.g., open public channels to any authenticated user. Internally, an unauthenticated caller
  (`$identity === null`) is **always denied** before the guard runs, so a custom guard never has to
  handle a null identity.

---

## Data model (`Tina4/Realtime/*.php`)

Framework-owned ORM models, all with the **`tina4_rt_`** table prefix (so they never collide with an
app's own tables). Properties are **camelCase** (Tina4 PHP ORM convention); the ORM maps them to
snake_case columns and to snake_case JSON keys via `toDict()`, so the schema and wire shape stay
byte-identical to the Python master. `ensureChatTables()` creates them in dependency order:
`Workspace, Channel, ChannelMember, Message, Attachment`.

| model | table | key fields (camelCase → wire snake_case) |
|---|---|---|
| `Workspace` | `tina4_rt_workspaces` | `id`, `name`, `createdAt` |
| `Channel` | `tina4_rt_channels` | `id`, `workspaceId`, `name`, `kind` (`public`\|`private`\|`dm`, default `public`), `createdAt` |
| `ChannelMember` | `tina4_rt_channel_members` | `id`, `channelId`, `userId` (string), `role` (default `member`), `lastReadAt` (read cursor) |
| `Message` | `tina4_rt_messages` | `id`, `channelId`, `userId` (string), `body`, `threadId` (nullable parent id), `createdAt`, `editedAt` (nullable) |
| `Attachment` | `tina4_rt_attachments` | `id`, `channelId`, `messageId` (nullable), `storageKey`, `filename`, `mime`, `size`, `thumbKey` (nullable) |

`userId` is a **string** field everywhere so any JWT identity shape (int id / UUID / email) fits.

---

## ⚠️ Footguns / hard rules

- **Chat needs a bound database — but a missing one does NOT crash boot.** With
  `features=['chat'|'files']`, `ensureChatTables()` runs at mount. If no DB is bound it **logs an
  ERROR and continues**: `mount()` still returns the full path map and registers every route; the
  failure only resurfaces at query time. Bind a database **before** mounting realtime with
  chat/files (`TINA4_DATABASE_URL` / your DB init) or chat/history/files will error per-request
  while the app appears healthy.
- **The signalling WS (`/ws/rtc/{room}`) is PUBLIC** — it is not `secure:`, so anyone can join any
  room and receive relayed signalling frames. Only the **chat** WS is JWT-secured. Gate call access
  at the app layer if you need it.
- **The config endpoint (`/api/rtc/config`) is PUBLIC** and returns your ICE/TURN config, including
  freshly-minted ephemeral TURN credentials.
- **WS handler signature is `($connection, $data, $event)`** — event is `"open"`/`"message"`/
  `"close"`, `$data` is a string on message and `null` on open/close. This is the **PHP** order and
  differs from Python/Node's `(connection, event, data)`.
- **Channels are addressed by integer id.** A non-integer `{channel}` makes `chatHandler` return
  silently (no error frame) — the client sees a socket that opens and does nothing.
- **Chat authorization is re-checked on every frame**, and identity is always taken from the verified
  token (`$connection->auth` / `$request->user`), never from the message payload. A custom
  `authorize` must be cheap — it runs on every inbound message.
- **A message with an empty/whitespace `body` is silently dropped** (no persist, no broadcast).
  `read`/`typing`/unknown types never persist anything.
- **`backend` is hardcoded to `'mesh'`** in the path map and config body regardless of
  `TINA4_RTC_BACKEND` (Phase-1 shortcut). Only mesh ships in Phase 1 (browsers connect
  peer-to-peer). An SFU/LiveKit backend is the documented Phase-2 drop-in with no route changes.
- **Files upload (`POST /api/files`) relies on the framework's default Bearer protection** — it has
  no `->noAuth()`, so it is auth-required like any write route. Don't add `->noAuth()` to it.
