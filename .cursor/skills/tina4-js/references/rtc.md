# WebRTC / Realtime (`rtc`) — Complete Reference

`tina4js/rtc` is the client for a Tina4 backend's `realtime()` mount. It has
three surfaces, all signal-driven so you bind them straight into `html`
templates:

- **`rtc.call(room, options?)`** — mesh WebRTC (video / audio / screen share)
  over a signalling WebSocket, with perfect negotiation.
- **`rtc.chat(channel, options?)`** — persistent chat: messages, presence,
  typing, read receipts, history.
- **`rtc.upload(channel, file, options?)`** / **`rtc.fetchBlob(key, options?)`**
  — file transfer.

Media is **peer-to-peer**. The server only relays the SDP/ICE handshake and
tells the client where the ICE servers and WS/HTTP routes live via
`GET /api/rtc/config` — the client never hardcodes a URL.

```ts
import { rtc } from 'tina4js/rtc';
```

Everything reactive is a `Signal<T>` from `tina4js`, so the **new-reference
rule** applies: the module replaces these signals wholesale, you read them.
Never mutate the arrays in place.

---

## `rtcConfig(url = '/api/rtc/config'): Promise<RtcConfig>`

Fetches the self-describing realtime config. Also exported on the object as
`rtc.config` (it is the **function**, not a cached config object).

```ts
import { rtcConfig } from 'tina4js/rtc';
const cfg = await rtcConfig();          // GET /api/rtc/config
```

Throws `[tina4] rtc config fetch failed: <status>` on a non-2xx response.

### `RtcConfig`

| Field | Type | Meaning |
|---|---|---|
| `backend` | `string` | Backend identifier. |
| `iceServers?` | `RTCIceServer[]` | STUN/TURN servers for the peer connections. |
| `signalling?` | `string` | Call WS path template, e.g. `"/ws/rtc/{room}"`. |
| `chat?` | `string` | Chat WS path template, e.g. `"/ws/chat/{channel}"`. |
| `messages?` | `string` | History HTTP path, e.g. `"/api/channels/{id}/messages"`. |
| `files?` | `string` | File upload/download path, e.g. `"/api/files"`. |

`{room}` / `{channel}` / `{id}` in a path are substituted; if the template has
no placeholder the client appends `/<value>`.

---

## The `rtc` object

```ts
export const rtc = {
  config:    rtcConfig,     // (url?) => Promise<RtcConfig>
  call:      startCall,     // (room, options?) => Promise<CallSession>   ← async
  chat:      startChat,     // (channel, options?) => ChatSession         ← sync
  upload:    uploadFile,    // (channel, file, options?) => Promise<UploadResult>
  fetchBlob: fetchBlobUrl,  // (keyOrUrl, options?) => Promise<string>
};
```

> ⚠️ `rtc.call()` is **async** (`await` it — it fetches config and prompts for
> media). `rtc.chat()` is **synchronous** (returns the session immediately).
> Mixing these up is the #1 mistake.

---

## Calls — `rtc.call(room, options?): Promise<CallSession>`

Mesh WebRTC with the perfect-negotiation pattern. Each participant opens one
`RTCPeerConnection` per other participant in the room.

### `CallStatus`

```ts
type CallStatus = 'idle' | 'connecting' | 'connected' | 'closed';
```

A live session **starts at `'connecting'`**, flips to `'connected'` when a peer
connection reaches `connected`, and `'closed'` after `leave()`. `'idle'` exists
in the type but a session never emits it — do not wait for `'idle'`.

### `CallOptions`

| Option | Type | Default | Behaviour |
|---|---|---|---|
| `config` | `RtcConfig` | — | Pre-fetched config; **skips the per-call fetch**. |
| `configUrl` | `string` | `'/api/rtc/config'` | Where to fetch config if `config` not given. |
| `signallingUrl` | `string` | config's `signalling` or `/ws/rtc` | Explicit WS base; `{room}` filled or appended. |
| `iceServers` | `RTCIceServer[]` | config's `iceServers` or `[]` | ICE server override. |
| `media` | `MediaStreamConstraints \| MediaStream \| false` | `{ audio: true, video: true }` | Local media. A ready `MediaStream` is used as-is; `false` = **receive-only** (no getUserMedia prompt). |

### `CallSession`

| Member | Type | Notes |
|---|---|---|
| `status` | `Signal<CallStatus>` | See lifecycle above. |
| `localStream` | `Signal<MediaStream \| null>` | Your camera/mic stream (`null` if `media: false`). |
| `peers` | `Signal<RemotePeer[]>` | Remote participants + their streams. |
| `screenSharing` | `Signal<boolean>` | True while sharing the screen. |
| `error` | `Signal<Error \| null>` | Last negotiation/ICE error. |
| `id` | `string` (readonly) | This peer's random 8-char id in the room. |
| `shareScreen()` | `=> Promise<void>` | `getDisplayMedia` + replace outgoing video track. |
| `stopScreen()` | `=> Promise<void>` | Restore the camera track. |
| `toggleAudio(on?)` | `=> boolean` | Enable/disable mic track; returns new enabled state (`false` if no track). |
| `toggleVideo(on?)` | `=> boolean` | Same for the camera track. |
| `leave()` | `=> void` | Terminal — see footguns. |

### `RemotePeer`

```ts
interface RemotePeer { id: string; stream: MediaStream | null; }
```

### Minimal call

```ts
import { rtc } from 'tina4js/rtc';
import { html, mount, effect } from 'tina4js';

const call = await rtc.call('standup');   // camera + mic by default

// bind local + remote video
effect(() => {
  const local = call.localStream.value;
  if (local) (document.querySelector('#me') as HTMLVideoElement).srcObject = local;
});

mount('#peers', () => html`
  ${call.peers.value.map(p => html`
    <video autoplay playsinline .srcObject=${p.stream}></video>
  `)}
`);

// controls
document.querySelector('#mute')!.addEventListener('click', () => call.toggleAudio());
document.querySelector('#share')!.addEventListener('click', () => call.shareScreen());
document.querySelector('#leave')!.addEventListener('click', () => call.leave());
```

Receive-only viewer (no camera prompt):

```ts
const viewer = await rtc.call('standup', { media: false });
```

Reuse a pre-fetched config across many calls (avoids a fetch each time):

```ts
const config = await rtc.config();
const a = await rtc.call('room-a', { config });
const b = await rtc.call('room-b', { config });
```

---

## Chat — `rtc.chat(channel, options?): ChatSession`

A data-channel-style chat over a WebSocket, plus REST history. **Synchronous** —
returns the session immediately; the socket connects in the background (watch
`session.connected`).

### `ChatOptions`

| Option | Type | Default | Behaviour |
|---|---|---|---|
| `token` | `string` | — | JWT for the secured chat WS **and** history calls (bearer subprotocol on the WS, `Authorization` header on HTTP). |
| `url` | `string` | `'/ws/chat'` | Explicit WS base; `{channel}` filled or appended. |
| `apiBase` | `string` | `''` (same origin) | HTTP base for history. |
| `messagesPath` | `string` | `'/api/channels/{id}/messages'` | History path template; `{id}` → channel. |
| `typingTimeout` | `number` | `3000` | ms a typing indicator lingers before auto-clearing. |

### `ChatSession`

| Member | Type | Notes |
|---|---|---|
| `status` | `Signal<SocketStatus>` | `'connecting' \| 'open' \| 'closed' \| 'reconnecting'` (from the underlying `ManagedSocket`). |
| `connected` | `Signal<boolean>` | True when the socket is open. |
| `messages` | `Signal<ChatMessage[]>` | Live + prepended history. |
| `presence` | `Signal<string[]>` | User ids currently in the channel. |
| `typing` | `Signal<string[]>` | User ids currently typing (auto-expire after `typingTimeout`). |
| `send(body, threadId?)` | `=> void` | Send a message (`thread_id` defaults to `null`). |
| `sendTyping()` | `=> void` | Emit a typing signal. |
| `markRead()` | `=> void` | Emit a read receipt. |
| `history(before?, limit=50)` | `=> Promise<ChatMessage[]>` | Fetch older messages (see footguns). |
| `close()` | `=> void` | Clear typing timers and close the socket. |

### `ChatMessage`

```ts
interface ChatMessage {
  id?: number;
  channel_id?: number;
  user_id?: string;
  body?: string;
  thread_id?: number | null;
  created_at?: string;
}
```

### Minimal chat

```ts
import { rtc } from 'tina4js/rtc';
import { html, mount } from 'tina4js';

const chat = rtc.chat('general', { token: myJwt });

await chat.history();        // load the last 50, prepended into chat.messages

mount('#log', () => html`
  ${chat.messages.value.map(m => html`<p><b>${m.user_id}</b> ${m.body}</p>`)}
`);

mount('#who', () => html`Online: ${chat.presence.value.join(', ')}`);
mount('#typing', () => html`
  ${chat.typing.value.length ? `${chat.typing.value.join(', ')} typing…` : ''}
`);

const input = document.querySelector('#msg') as HTMLInputElement;
input.addEventListener('input', () => chat.sendTyping());
input.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && input.value) { chat.send(input.value); input.value = ''; }
});
```

Load an earlier page — pass the oldest id you already have as `before`:

```ts
const oldest = chat.messages.value[0]?.id;
await chat.history(oldest, 50);
```

---

## Files — `rtc.upload` / `rtc.fetchBlob`

### `rtc.upload(channel, file, options?): Promise<UploadResult>`

POSTs `multipart/form-data` (`channel_id` + `file`) to `filesPath`.

```ts
const res = await rtc.upload('general', fileInput.files![0], { token: myJwt });
// res: UploadResult
```

### `UploadResult`

```ts
interface UploadResult {
  id: number;
  key: string;
  filename: string;
  mime: string;
  size: number;
  url: string;   // presigned URL for S3 backends; a route path for local storage
}
```

### `FileOptions`

| Option | Type | Default |
|---|---|---|
| `token` | `string` | — |
| `apiBase` | `string` | `''` |
| `filesPath` | `string` | `'/api/files'` |

### `rtc.fetchBlob(keyOrUrl, options?): Promise<string>`

Fetches a permissioned download **with the auth header** and returns an
`ObjectURL` you can drop into `<img src>` / `<a href>`. A secured GET route
can't be hit by a bare `<img src>` (no way to attach a bearer header), so this
is the local-storage path. For an S3 backend, `UploadResult.url` is already a
presigned URL — pass it here (it's used directly) or use it as `src` directly.

```ts
const src = await rtc.fetchBlob(res.key, { token: myJwt });
imgEl.src = src;
// when done: URL.revokeObjectURL(src)   // ← you must revoke it yourself
```

---

## Backend wiring

- The client bootstraps from **`GET /api/rtc/config`**, served by the Tina4
  backend's `realtime()` module. It returns the ICE servers and the resolved
  WS/HTTP paths so the front end hardcodes nothing.
- **Calls** use a signalling WebSocket (`RtcConfig.signalling`, default
  `/ws/rtc/{room}`). The server relays only `hello`/`welcome`/`bye`/`desc`/`ice`
  messages between peers; the actual audio/video is peer-to-peer.
- **Chat** uses a separate WebSocket (`RtcConfig.chat`, default
  `/ws/chat/{channel}`) plus a REST history endpoint (`RtcConfig.messages`).
- **Files** use REST (`RtcConfig.files`, default `/api/files`).

---

## ⚠️ Footguns / Hard rules

1. **`rtc.call()` is async, `rtc.chat()` is sync.** `await rtc.call(...)`;
   do NOT await `rtc.chat(...)`.

2. **Config loads before a call — automatically.** `rtc.call()` fetches
   `/api/rtc/config` for you if you don't pass `config`. If that fetch 404s or
   errors, the returned promise **rejects**. For many calls, fetch once with
   `rtc.config()` and pass `{ config }` to skip the repeat fetch.

3. **`rtc.config` is the fetch function, not a config object.** It's `===
   rtcConfig`. Call it: `await rtc.config()`.

4. **`status` never becomes `'connected'` alone in a room.** It flips only when
   a remote peer's connection reaches `connected`. A sole occupant stays
   `'connecting'`. And `'idle'` is never emitted by a live session.

5. **`leave()` is terminal.** It sends `bye`, closes every peer, **stops your
   local tracks** (camera light off), closes the socket, and sets status
   `'closed'`. The session cannot be reused — call `rtc.call()` again to rejoin.

6. **Screen share targets current peers.** `shareScreen()` replaces the outgoing
   video track on existing senders. Clicking the browser's native "Stop
   sharing" restores the camera automatically (via the track's `onended`).

7. **Calls have no `token` option.** The signalling WebSocket is opened without
   auth. Only **chat** and **file** options take a `token`. Secure a private
   room on the server side (the signalling route), not from this client.

8. **`history()` mutates AND returns.** It prepends the fetched (older) messages
   into the `messages` signal *and* returns the raw rows (newest-first). Use the
   signal for rendering; don't also append the return value or you'll duplicate.

9. **Read receipts aren't surfaced by `ChatSession`.** `markRead()` sends a
   `read` event and the server broadcasts them, but the session does not expose
   the socket, so there is no built-in way to subscribe to incoming `read`
   events through the returned API.

10. **`fetchBlob()` leaks without cleanup.** It returns an `ObjectURL` from
    `URL.createObjectURL`. Call `URL.revokeObjectURL(src)` when you're done with
    it (e.g. when the element unmounts).

11. **Signals follow the new-reference rule.** `peers`, `messages`, `presence`,
    `typing`, etc. are replaced wholesale by the module. Read them; never
    `.push()` / mutate in place.

12. **Always `close()` the chat session / `leave()` the call on teardown.** Both
    stop timers/sockets and, for calls, release the camera and mic.
```
