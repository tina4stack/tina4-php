<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Customer feedback widget — Tier 4 of the Tina4 dev-admin stack.
 *
 * End users of a shipped Tina4 app can give UX feedback via a floating bubble
 * widget. Whitelisted users only. The widget proxies one conversational turn
 * at a time to the co-located Rust agent (intake-only agent, no tools,
 * JSON output). Finalised tickets land in the dev admin sidebar.
 *
 * Architecture:
 *   1. Framework middleware injects <script src="/__feedback/widget.js">
 *      into HTML responses for whitelisted users.
 *   2. Widget POSTs to /__feedback/api/turn for each conversational turn.
 *   3. {@see _api_turn()} verifies whitelist + rate-limit, stamps the user
 *      identity server-side (client cannot fake `sender`), then forwards
 *      to the Rust agent's /feedback/intake.
 *   4. Finalised tickets land in the agent thread store with kind:"feedback".
 *      Developer sees them in the dev admin sidebar.
 *
 * Ported from Python: tina4_python.dev_admin lines 1440-1645.
 */
class Feedback
{
    /**
     * In-memory rate limit map — user => [timestamps]. Pruned lazily on each
     * {@see rateLimitOk()} call. 5 turns/hour per user.
     *
     * @var array<string, list<float>>
     */
    private static array $rateLimit = [];

    /** Window (seconds) over which {@see RATE_MAX} turns are allowed. */
    private const RATE_WINDOW = 3600;

    /** Maximum turns allowed per user per {@see RATE_WINDOW}. */
    private const RATE_MAX = 5;

    /**
     * Reset the rate-limit map. Tests call this so prior runs don't leak in.
     */
    public static function resetRateLimit(): void
    {
        self::$rateLimit = [];
    }

    /**
     * Hard master switch.
     *
     * Both gates required for the widget to render or the API to accept
     * submissions:
     *   - TINA4_ENABLE_FEEDBACK=true (explicit opt-in — off by default)
     *   - TINA4_FEEDBACK_WHITELIST=... (non-empty list of users)
     *
     * Splitting the toggle from the whitelist lets the developer leave
     * the whitelist intact while pausing the feature in production for
     * a release (set TINA4_ENABLE_FEEDBACK=false → widget vanishes
     * everywhere; whitelist comes back online with one env flip).
     */
    public static function enabled(): bool
    {
        $raw = (string) (getenv('TINA4_ENABLE_FEEDBACK') ?: '');
        return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Comma-separated emails / user IDs in env. Empty list = no one allowed.
     * Returns empty when the master switch is off.
     *
     * @return list<string>
     */
    public static function whitelist(): array
    {
        if (!self::enabled()) {
            return []; // master switch off — short-circuits everywhere
        }
        $raw = trim((string) (getenv('TINA4_FEEDBACK_WHITELIST') ?: ''));
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $entry) {
            $e = strtolower(trim($entry));
            if ($e !== '') {
                $out[] = $e;
            }
        }
        return $out;
    }

    /**
     * Best-effort user identity from auth headers.
     *
     * Priority:
     *   1. JWT/Bearer token via {@see Auth::authenticateRequest()} — pulls
     *      email/sub/user_id claim.
     *   2. TINA4_FEEDBACK_DEV_USER env var override (LOCAL DEV ONLY —
     *      lets the framework owner test the widget without a full auth
     *      setup in the test project).
     */
    public static function identifyUser(Request $request): ?string
    {
        try {
            $headers = $request->headers ?? [];
            $payload = Auth::authenticateRequest($headers);
            if (is_array($payload)) {
                foreach (['email', 'sub', 'user_id'] as $key) {
                    if (isset($payload[$key]) && $payload[$key] !== '') {
                        return strtolower(trim((string) $payload[$key]));
                    }
                }
            }
        } catch (\Throwable) {
            // best-effort — fall through to dev override
        }

        $dev = trim((string) (getenv('TINA4_FEEDBACK_DEV_USER') ?: ''));
        if ($dev !== '') {
            return strtolower($dev);
        }
        return null;
    }

    /**
     * Returns [bool $allowed, ?string $user]. Both halves required to act —
     * the boolean tells the caller to short-circuit, the string lets it
     * stamp the identity onto outbound requests.
     *
     * @return array{0: bool, 1: ?string}
     */
    public static function isWhitelisted(Request $request): array
    {
        $wl = self::whitelist();
        if (empty($wl)) {
            return [false, null]; // feature off entirely
        }
        $user = self::identifyUser($request);
        if ($user === null) {
            return [false, null];
        }
        return [in_array($user, $wl, true), $user];
    }

    /**
     * 5 turns/hour per user. Prunes old timestamps lazily so the in-memory
     * map doesn't grow unbounded across long-lived processes.
     */
    public static function rateLimitOk(string $user): bool
    {
        $now = microtime(true);
        $hits = array_values(array_filter(
            self::$rateLimit[$user] ?? [],
            fn(float $t) => ($now - $t) < self::RATE_WINDOW
        ));
        if (count($hits) >= self::RATE_MAX) {
            self::$rateLimit[$user] = $hits;
            return false;
        }
        $hits[] = $now;
        self::$rateLimit[$user] = $hits;
        return true;
    }

    /**
     * Inject the widget <script> into HTML responses for whitelisted users.
     *
     * Called from {@see Router::injectDevToolbar()} (or the response-write
     * path) right before the body is sent. No-op if:
     *   - The request is for a /__dev or /__feedback path (developer
     *     dashboard / widget assets — never inject the customer widget
     *     on developer pages; the dev admin has its OWN chat trigger).
     *   - TINA4_ENABLE_FEEDBACK + TINA4_FEEDBACK_WHITELIST not both set.
     *   - Requesting user isn't in the whitelist.
     *   - Response doesn't have a closing </body> tag (fragment, JSON, etc.).
     * Idempotent: a second call won't double-inject (looks for marker).
     */
    public static function injectFeedbackWidget(Request $request, string $html): string
    {
        if ($html === '') {
            return $html;
        }

        // The customer feedback widget is for END USERS of the shipped app —
        // injecting on developer-only paths creates a confusing "two bubbles"
        // UX where the dev chat trigger + customer feedback bubble sit on
        // top of each other. Hard exclusion at the framework layer.
        $path = $request->path ?? '';
        if (str_starts_with($path, '/__dev') || str_starts_with($path, '/__feedback')) {
            return $html;
        }

        [$allowed, ] = self::isWhitelisted($request);
        if (!$allowed) {
            return $html;
        }

        if (str_contains($html, 'data-tina4-feedback')) {
            return $html; // already injected upstream
        }

        $snippet = '<script src="/__feedback/widget.js" data-tina4-feedback></script>';
        $pos = strrpos($html, '</body>');
        if ($pos === false) {
            return $html;
        }
        return substr($html, 0, $pos) . $snippet . substr($html, $pos);
    }

    /**
     * Register the /__feedback/api/turn and /__feedback/widget.js routes.
     * Called from {@see DevAdmin::register()} so the feature shares the
     * dev-admin lifecycle — but it's deliberately gated on the runtime
     * env flags, not TINA4_DEBUG, so a production op can opt in without
     * flipping debug mode on.
     */
    public static function register(): void
    {
        // POST /__feedback/api/turn — proxy a single conversational turn
        // to the Rust agent /feedback/intake.
        //
        // Stamps `sender` server-side from the verified identity — client
        // cannot inject who they are. Rate-limited per user.
        (Router::post('/__feedback/api/turn', function (Request $request, Response $response) {
            [$allowed, $user] = self::isWhitelisted($request);
            if (!$allowed || $user === null) {
                return $response->json(['error' => 'not authorised for feedback'], 403);
            }
            if (!self::rateLimitOk($user)) {
                return $response->json([
                    'error' => 'rate limit exceeded',
                    'hint'  => 'max ' . self::RATE_MAX . ' turns per hour',
                ], 429);
            }

            $body = $request->body ?? null;
            if (!is_array($body)) {
                return $response->json(['error' => 'expected JSON body'], 400);
            }

            $forward = $body;
            $forward['sender'] = $user; // server-stamped identity

            $url = DevAdmin::supervisorBaseUrl() . '/feedback/intake';
            $result = DevAdmin::callSupervisorTransport(
                'POST',
                $url,
                json_encode($forward),
                ['Content-Type' => 'application/json']
            );

            if ($result['status'] === 0 || ($result['error'] ?? '') !== '') {
                return $response->json([
                    'error'  => 'agent unreachable',
                    'detail' => $result['error'] ?? '',
                ], 502);
            }

            // Consume the chunk generator into a single buffer — the
            // intake agent returns one JSON document per turn, not a
            // long-lived SSE stream, so buffering is correct here.
            $chunksFn = $result['chunks'];
            $raw = '';
            foreach ($chunksFn() as $chunk) {
                $raw .= $chunk;
            }

            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $response->json($decoded, $result['status']);
            }
            return $response->text($raw, $result['status']);
        }))->noAuth();

        // GET /__feedback/widget.js — serves the bundle if present, else
        // a tiny stub. Cache-Control: no-cache so browsers re-check the
        // bundle on every load (otherwise an old cached bundle can keep
        // painting the bubble on /__dev pages even after a server-side
        // path-block fix lands).
        (Router::get('/__feedback/widget.js', function (Request $request, Response $response) {
            $jsPath = __DIR__ . '/../src/public/__feedback/widget.js';
            $body = is_file($jsPath)
                ? (string) file_get_contents($jsPath)
                : "console.warn('tina4-feedback-widget bundle not built yet');";

            $response->text($body, 200);
            return $response
                ->header('Content-Type', 'application/javascript; charset=utf-8')
                ->header('Cache-Control', 'no-cache, must-revalidate')
                ->header('Pragma', 'no-cache');
        }))->noAuth();
    }
}
