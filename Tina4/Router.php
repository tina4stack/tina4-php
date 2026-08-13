<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Zero-dependency HTTP router with dynamic params, middleware, groups, and caching.
 *
 * Usage:
 *   Router::get('/users/{id}', fn($req, $res) => $res->json(['id' => $req->params['id']]));
 *   Router::group('/api/v1', function() {
 *       Router::get('/users', $handler);
 *   });
 */
class Router
{
    /**
     * @var array<string, array<int, array{
     *     pattern: string,
     *     regex: string,
     *     paramNames: array<string>,
     *     callback: callable,
     *     middleware: array<callable>,
     *     cache: bool,
     *     secure: bool,
     *     noAuth: bool,
     *     catchAll: bool,
     *     catchAllName: string|null,
     * }>> Routes indexed by HTTP method
     */
    private static array $routes = [];

    /** @var array<int, array{path: string, handler: callable, secure: bool, auth_required: bool}> Registered WebSocket routes */
    private static array $wsRoutes = [];

    /** @var string Current group prefix */
    private static string $groupPrefix = '';

    /** @var array<callable> Current group middleware */
    private static array $groupMiddleware = [];

    // ── The dispatch pipeline ────────────────────────────────────
    //
    // dispatchInner was 440 lines at cyclomatic complexity 73 - the largest
    // function in the family. Its concerns are named below as DATA, so the
    // pipeline can be read, tested and compared across the four frameworks
    // without reading an implementation.
    //
    // Four groups, matching the shape of tina4-ruby's dispatch_pipeline.rb:
    //
    //   PROLOGUE_STAGES  run in dispatch(), before dispatchInner is entered.
    //   REQUEST_STAGES   run until one RETURNS a Response; that response is
    //                    the answer. dispatchNoMatch is terminal.
    //   ROUTE_STAGES     run for a matched route, in this order; each returns
    //                    a Response to short-circuit or null to continue.
    //   RESPONSE_STAGES  run over the finished Response on the way out.
    //
    // Ordering is BEHAVIOUR, not taste, and each entry is decided:
    // ADR-0010 (static resolves inside dispatchNoMatch, after matching),
    // ADR-0012 (post-match globals -> auth gate -> the route's own middleware).
    //
    // The methods NOT listed here - extractAuthToken, bindHandlerArgs,
    // runOneRouteMiddleware, runClassMiddlewareHooks, runCallableMiddleware,
    // invokeCallableMiddleware, resolveMiddlewareClass,
    // middlewareResultToResponse, handlerResultToResponse - are HELPERS a
    // stage calls, not pipeline steps. The distinction matters: only a stage's
    // POSITION is a contract, and none of those nine has one.
    //
    // runGlobalMiddlewarePass used to be on that list, and by that same rule it
    // did not belong there: its position IS the contract - ADR-0012 in full.
    // The pre-match pass must run before the match so a global's headers
    // survive a short-circuited 401; the post-match pass must run after
    // $request->handler is assigned and BEFORE the auth gate. Leaving it out
    // meant PHP's list did not show where the globals sit relative to the gate,
    // which is the single most-argued ordering decision in this feature.
    // Ruby, Python and Node all list it. One method, two phases - which is
    // exactly what the pre/post split is.

    /** @var array<int, string> Stages run by dispatch() before dispatchInner. */
    public const PROLOGUE_STAGES = [
        'startNativeSession',
    ];

    /** @var array<int, string> Stages run until one returns a Response. */
    public const REQUEST_STAGES = [
        'trailingSlashRedirect',
        'runGlobalMiddlewarePass',
        'dispatchNoMatch',
    ];

    /** @var array<int, string> Stages run for a matched route, in order. */
    public const ROUTE_STAGES = [
        'runGlobalMiddlewarePass',
        'enforceRouteAuth',
        'runRouteMiddleware',
        'invokeRouteHandler',
    ];

    /** @var array<int, string> Stages run over the finished Response. */
    public const RESPONSE_STAGES = [
        'finaliseResponse',
        'saveSessionAndSetCookie',
        'stripHeadBody',
        'logRequest',
    ];

    /** @var string Base path for static file serving */
    public static string $basePath = '.';

    /** @var int Index of the last registered route (for chaining) */
    private static ?int $lastRouteIndex = null;

    /** @var string Method of the last registered route */
    private static ?string $lastRouteMethod = null;

    /**
     * Memoised continuations for string middleware specs ("ResponseCache:300")
     * so one ResponseCache instance (and its backend) is reused across requests
     * — parity with Python resolving the spec once at registration.
     *
     * @var array<string, \Closure>
     */
    private static array $resolvedStringMiddleware = [];

    /** @var array<string, string>|null Cached template lookup: url_path => template_file */
    private static ?array $templateCache = null;

    /**
     * Auto-routing scans this single subdirectory of src/templates/. Only files
     * in src/templates/pages/ become URLs — everything else (partials, layouts,
     * base.twig, errors, components, macros) is never URL-exposed and remains
     * renderable only via {% include %} / {% extends %} / $response->render().
     *
     * Convention adapted from Next.js' pages/ directory and Nuxt's pages/ folder.
     * Explicit, secure by default, no skip lists to maintain.
     */
    private const TEMPLATE_PAGES_DIR = 'pages';

    /**
     * Register a global middleware class.
     *
     * Delegates to Middleware::use(). The middleware class should follow the
     * standardized convention with static `before*` and `after*` methods.
     *
     * Usage:
     *   Router::use(\Tina4\Middleware\CorsMiddleware::class);
     *   Router::use(\Tina4\Middleware\RateLimiter::class);
     *
     * @param string $class Fully-qualified middleware class name
     */
    public static function use(string $class): void
    {
        Middleware::use($class);
    }

    /**
     * Register a route for a specific HTTP method.
     * Core registration method — all convenience methods delegate here.
     *
     * @param string   $method   HTTP method (GET, POST, PUT, PATCH, DELETE, ANY)
     * @param string   $path     URL pattern
     * @param callable $handler  Route handler callable
     */
    public static function add(string $method, string $path, callable $handler, array $middleware = [], array $swaggerMeta = [], ?string $template = null): self
    {
        $method = strtoupper($method);
        if ($method === 'ANY') {
            return self::any($path, $handler, $middleware, $swaggerMeta, $template);
        }
        return self::addRoute($method, $path, $handler, $swaggerMeta, $middleware, $template);
    }

    /**
     * Register a GET route.
     */
    public static function get(string $path, callable $handler, array $middleware = [], array $swaggerMeta = [], ?string $template = null): self
    {
        return self::addRoute('GET', $path, $handler, $swaggerMeta, $middleware, $template);
    }

    /**
     * Register a POST route.
     */
    public static function post(string $path, callable $handler, array $middleware = [], array $swaggerMeta = [], ?string $template = null): self
    {
        return self::addRoute('POST', $path, $handler, $swaggerMeta, $middleware, $template);
    }

    /**
     * Register a PUT route.
     */
    public static function put(string $path, callable $handler, array $middleware = [], array $swaggerMeta = [], ?string $template = null): self
    {
        return self::addRoute('PUT', $path, $handler, $swaggerMeta, $middleware, $template);
    }

    /**
     * Register a PATCH route.
     */
    public static function patch(string $path, callable $handler, array $middleware = [], array $swaggerMeta = [], ?string $template = null): self
    {
        return self::addRoute('PATCH', $path, $handler, $swaggerMeta, $middleware, $template);
    }

    /**
     * Register a DELETE route.
     */
    public static function delete(string $path, callable $handler, array $middleware = [], array $swaggerMeta = [], ?string $template = null): self
    {
        return self::addRoute('DELETE', $path, $handler, $swaggerMeta, $middleware, $template);
    }

    /**
     * Register an explicit HEAD route.
     *
     * By default the framework auto-handles HEAD by falling back to the GET
     * route and stripping the body (RFC 9110 §9.3.2). Use this method only
     * when you need a HEAD handler that does something different from GET —
     * e.g. cheaper existence-check logic, custom validator headers without
     * the cost of building the body.
     *
     * The framework still strips the response body for you on the way out —
     * HEAD MUST NOT return content, even if your handler does, so we
     * enforce that unconditionally rather than relying on developer care.
     */
    public static function head(string $path, callable $handler, array $middleware = [], array $swaggerMeta = [], ?string $template = null): self
    {
        return self::addRoute('HEAD', $path, $handler, $swaggerMeta, $middleware, $template);
    }

    /**
     * Register an explicit OPTIONS route.
     *
     * By default the framework auto-handles OPTIONS by building an Allow
     * header from every method registered for the path and returning 204
     * (RFC 9110 §9.3.7). Use this method to take over that behaviour —
     * e.g. to return a richer OPTIONS payload describing the resource.
     */
    public static function options(string $path, callable $handler, array $middleware = [], array $swaggerMeta = [], ?string $template = null): self
    {
        return self::addRoute('OPTIONS', $path, $handler, $swaggerMeta, $middleware, $template);
    }

    /**
     * Register a route for any HTTP method.
     */
    public static function any(string $path, callable $handler, array $middleware = [], array $swaggerMeta = [], ?string $template = null): self
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            self::addRoute($method, $path, $handler, $swaggerMeta, $middleware, $template);
        }
        // Point lastRoute to the GET one for chaining
        self::$lastRouteMethod = 'GET';
        self::$lastRouteIndex = count(self::$routes['GET']) - 1;
        return new self();
    }

    /**
     * Define a route group with a shared prefix and optional middleware.
     *
     * @param string $prefix URL prefix for all routes in the group
     * @param callable $callback Closure that registers routes within the group
     * @param array<callable> $middleware Middleware to apply to all routes in the group
     */
    public static function group(string $prefix, callable $callback, array $middleware = []): void
    {
        $previousPrefix = self::$groupPrefix;
        $previousMiddleware = self::$groupMiddleware;

        self::$groupPrefix = $previousPrefix . rtrim($prefix, '/');
        self::$groupMiddleware = array_merge($previousMiddleware, $middleware);

        $callback();

        self::$groupPrefix = $previousPrefix;
        self::$groupMiddleware = $previousMiddleware;
    }

    /**
     * Add middleware to the last registered route.
     *
     * @param array<callable> $middleware
     * @return $this
     */
    public function middleware(array $middleware): self
    {
        if (self::$lastRouteMethod !== null && self::$lastRouteIndex !== null) {
            $route = &self::$routes[self::$lastRouteMethod][self::$lastRouteIndex];
            $route['middleware'] = array_merge($route['middleware'], $middleware);

            // Middleware is purely additive — it never opens up auth as a
            // side effect. Developers explicitly open write routes with
            // ->noAuth() (or Router::any()) and lock GET routes with
            // ->secure(). Pre-3.13.2 this branch silently set
            // noAuth=true whenever middleware was attached, which let
            // POST/PUT/PATCH/DELETE routes serve unauthenticated traffic
            // the moment a logging or CORS middleware landed on them —
            // a security footgun. tina4-book#141 PY-10-02 parity fix.

            // If this was registered via any(), apply to all methods
            if ($this->wasAnyRoute()) {
                foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                    $idx = $this->findMatchingRoute($method, $route['pattern']);
                    if ($idx !== null) {
                        self::$routes[$method][$idx]['middleware'] = $route['middleware'];
                    }
                }
            }
        }
        return $this;
    }

    /**
     * Mark the last registered route as cacheable.
     *
     * @return $this
     */
    public function cache(): self
    {
        if (self::$lastRouteMethod !== null && self::$lastRouteIndex !== null) {
            self::$routes[self::$lastRouteMethod][self::$lastRouteIndex]['cache'] = true;
        }
        return $this;
    }

    /**
     * Mark the last registered route as non-cacheable.
     *
     * @return $this
     */
    public function noCache(): self
    {
        if (self::$lastRouteMethod !== null && self::$lastRouteIndex !== null) {
            self::$routes[self::$lastRouteMethod][self::$lastRouteIndex]['cache'] = false;
        }
        return $this;
    }

    /**
     * Attach Swagger/OpenAPI metadata to the last registered route.
     *
     * #59: MERGE into any metadata already attached rather than replacing it, so
     * chained calls — the PHP analog of stacking @summary/@description/@tags
     * decorators — all survive. Each call contributes its keys; a later call may
     * override an earlier same key (last-write-wins) but never drops a sibling
     * key. A single swagger([...]) call with every key is unchanged. Mirrors the
     * Python master, where each decorator annotates the handler in place so no
     * stacked metadata is lost.
     *
     * @param array $meta Swagger metadata (summary, tags, description, example, etc.)
     * @return $this
     */
    public function swagger(array $meta): self
    {
        if (self::$lastRouteMethod !== null && self::$lastRouteIndex !== null) {
            $existing = self::$routes[self::$lastRouteMethod][self::$lastRouteIndex]['swagger'] ?? [];
            self::$routes[self::$lastRouteMethod][self::$lastRouteIndex]['swagger'] = array_merge($existing, $meta);
        }
        return $this;
    }

    /**
     * Mark the last registered route as secure (requires valid JWT).
     *
     * @return $this
     */
    public function secure(): self
    {
        if (self::$lastRouteMethod !== null && self::$lastRouteIndex !== null) {
            self::$routes[self::$lastRouteMethod][self::$lastRouteIndex]['secure'] = true;
        }
        return $this;
    }

    /**
     * Opt out of secure-by-default auth on a write route (POST/PUT/PATCH/DELETE).
     *
     * Write routes require a valid Bearer JWT by default. Call noAuth() to
     * mark the route as publicly accessible without a token.
     *
     * @return $this
     */
    public function noAuth(): self
    {
        if (self::$lastRouteMethod !== null && self::$lastRouteIndex !== null) {
            self::$routes[self::$lastRouteMethod][self::$lastRouteIndex]['noAuth'] = true;
        }
        return $this;
    }

    /**
     * Match a request method and path to a registered route.
     *
     * @return array{route: array, params: array<string, string>}|null
     */
    public static function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');

        // RFC 9110 §9.3.2: HEAD is identical to GET except no body. If the
        // app didn't register a dedicated HEAD route, transparently fall
        // back to the GET route. dispatchInner strips the body on the way
        // out so the handler doesn't need to know HEAD even happened.
        if ($method === 'HEAD' && empty(self::$routes['HEAD']) && !empty(self::$routes['GET'])) {
            return self::matchInTable('GET', $path);
        }

        if (!isset(self::$routes[$method])) {
            return null;
        }

        return self::matchInTable($method, $path);
    }

    /**
     * Detect Express-style "function middleware" — a Closure (or other
     * non-class callable) that declares 3+ parameters. The third is the
     * `$next` continuation; the middleware calls `$next($req, $resp)` to
     * descend into the chain, or returns its own Response to
     * short-circuit. Class-string middleware (resolved via class_exists)
     * is dispatched separately via the before_ / after_ method pattern.
     *
     * Two-arg closures keep the legacy "filter" behaviour: run inline,
     * return false/Response to short-circuit. tina4-book#141 PY-10-01.
     */
    private static function isFunctionMiddleware(mixed $mw): bool
    {
        // Class strings → before_ / after_ dispatch, not function-style.
        if (is_string($mw)) {
            return false;
        }
        if (!($mw instanceof \Closure) && !is_callable($mw)) {
            return false;
        }
        try {
            $ref = $mw instanceof \Closure
                ? new \ReflectionFunction($mw)
                : new \ReflectionMethod($mw, '__invoke');
        } catch (\Throwable) {
            return false;
        }
        return $ref->getNumberOfParameters() >= 3;
    }

    /**
     * Resolve a string middleware spec to an Express-style continuation
     * closure, or null if the string is not a known parameterised middleware.
     *
     * Mirrors Python's `_resolve_string_middleware` registry. Accepts:
     *   "ResponseCache"      → ResponseCache with default TTL
     *   "ResponseCache:300"  → ResponseCache with ttl=300
     *
     * The returned closure ($req, $resp, $next) delegates to
     * ResponseCache::handle(), which short-circuits with a cached HIT
     * (skipping $next) or runs the handler via $next and stores the result on
     * a MISS — stamping X-Cache / X-Cache-TTL in both paths. This is the path
     * the dispatcher actually invokes (function-style middleware), so the
     * headers land on both the hit and miss responses the dispatcher returns.
     *
     * Unknown / plain class-name strings return null so the caller's
     * before_* static-method loop handles them unchanged.
     */
    private static function resolveStringMiddleware(string $spec): ?\Closure
    {
        $head = strstr($spec, ':', true);
        $name = $head !== false ? $head : $spec;
        $tail = $head !== false ? substr($spec, strlen($head) + 1) : '';

        // Registry of string-addressable instance middleware (parity with
        // Python). Only ResponseCache participates today; add entries here as
        // other instance middleware gain string specs.
        if ($name !== 'ResponseCache') {
            return null;
        }

        // Memoise one continuation per spec. Python resolves the string once at
        // route-registration time, so a single ResponseCache instance (and its
        // backend) is reused across requests — that is what makes the cache
        // actually hit on the 2nd request. PHP resolves per dispatch, so cache
        // the closure here to get the same single-instance behaviour.
        if (isset(self::$resolvedStringMiddleware[$spec])) {
            return self::$resolvedStringMiddleware[$spec];
        }

        // Parse the first colon-arg as the TTL (e.g. "ResponseCache:300").
        $config = [];
        if ($tail !== '') {
            $firstArg = strstr($tail, ':', true);
            $ttlArg = $firstArg !== false ? $firstArg : $tail;
            if (is_numeric($ttlArg)) {
                $config['ttl'] = (int) $ttlArg;
            }
        }

        $cache = new \Tina4\Middleware\ResponseCache($config);

        // ResponseCache::handle() is a self-contained continuation: lookup +
        // HIT short-circuit + MISS store, stamping X-Cache headers in every
        // path, without mutating the Request.
        $closure = static function (Request $request, Response $response, callable $next) use ($cache): mixed {
            return $cache->handle($request, $response, $next);
        };
        self::$resolvedStringMiddleware[$spec] = $closure;
        return $closure;
    }

    /**
     * Match a path against the route table for a single, already-uppercased
     * method. Extracted so HEAD's auto-fallback can reuse the GET table
     * without duplicating the param-extraction logic.
     */
    private static function matchInTable(string $method, string $path): ?array
    {
        foreach (self::$routes[$method] as $route) {
            if (preg_match($route['regex'], $path, $matches)) {
                $params = [];

                // Extract named parameters with type casting
                $types = $route['paramTypes'] ?? [];
                foreach ($route['paramNames'] as $name) {
                    if (isset($matches[$name])) {
                        $value = $matches[$name];
                        $type = $types[$name] ?? 'string';
                        $params[$name] = match ($type) {
                            'int', 'integer' => (int) $value,
                            'float', 'number' => (float) $value,
                            default => $value,
                        };
                    }
                }

                // Extract catch-all parameter
                if ($route['catchAll'] && $route['catchAllName'] !== null) {
                    if ($route['catchAllName'] === '*' && isset($matches['__wildcard__'])) {
                        $params['*'] = $matches['__wildcard__'];
                    } elseif (isset($matches[$route['catchAllName']])) {
                        $params[$route['catchAllName']] = $matches[$route['catchAllName']];
                    }
                }

                return [
                    'route' => $route,
                    'params' => $params,
                ];
            }
        }

        return null;
    }

    /**
     * Return the list of HTTP methods registered for a given path, in the
     * order they appear in $ALL_METHODS. Used by dispatchInner to build the
     * `Allow:` header on 405 / OPTIONS responses (RFC 9110 §10.2.1, §9.3.7).
     *
     * If GET is registered for the path, HEAD and OPTIONS are appended
     * implicitly — the framework handles those automatically, so they
     * count as supported even without explicit registration.
     *
     * @return string[] e.g. ['GET', 'POST', 'HEAD', 'OPTIONS']
     */
    /**
     * The HTTP methods registered for a path, for Allow / 405 responses.
     *
     * PUBLIC because CorsMiddleware needs it to stamp Allow on a preflight
     * (RFC 9110 s9.3.7). The other three frameworks already expose their
     * equivalent (Router.methods_allowed_for_path / methodsAllowedForPath);
     * PHP was the only one keeping it private.
     *
     * @param string $path Request path to look up
     * @return array<int, string> Registered method names, e.g. ['GET','POST']
     */
    public static function methodsAllowedForPath(string $path): array
    {
        $path = '/' . trim($path, '/');
        $methods = [];

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'] as $m) {
            if (empty(self::$routes[$m])) {
                continue;
            }
            foreach (self::$routes[$m] as $route) {
                if (preg_match($route['regex'], $path)) {
                    $methods[] = $m;
                    break;
                }
            }
        }

        // GET implies HEAD and OPTIONS auto-handling. Always append them
        // (without duplicates) whenever any concrete method exists for
        // the path — every path the router knows about supports OPTIONS,
        // and any path with GET supports HEAD.
        if (!empty($methods)) {
            if (in_array('GET', $methods, true) && !in_array('HEAD', $methods, true)) {
                $methods[] = 'HEAD';
            }
            if (!in_array('OPTIONS', $methods, true)) {
                $methods[] = 'OPTIONS';
            }
        }

        return $methods;
    }

    /**
     * Dispatch a request — match, run middleware, invoke handler.
     */
    public static function dispatch(Request $request, Response $response): Response
    {
        // v3.13.14: stamp the request start so we can log elapsed time below.
        $reqStart = microtime(true);

        // Feature 43: PER-REQUEST correlation id (was process-scoped in the App
        // constructor - useless for correlation under the long-running server,
        // where every request shared one id). Honour a sanitized inbound
        // X-Request-ID so a client/upstream can thread its own id through; an
        // attacker-controlled CR/LF, over-long or illegal-charset value is
        // rejected (never echoed), and an absent one is generated. Thread it into
        // the logger NOW so every log line for this request - including the 500
        // handler and logRequest() below - carries it; it is echoed on the
        // response at the end of dispatch().
        $requestId = Log::sanitizeRequestId($request->header('X-Request-ID'))
            ?? bin2hex(random_bytes(4));
        Log::setRequestId($requestId);
        try {
            return self::dispatchBody($request, $response, $requestId, $reqStart);
        } finally {
            // The request pipeline installs the id before its first log and
            // clears it in `finally` after its last (Decision 12 / LOG-Q03),
            // so an overlapping request can never observe a stale id from a
            // request that already finished.
            Log::clearRequestId();
        }
    }

    private static function dispatchBody(Request $request, Response $response, string $requestId, float $reqStart): Response
    {
        // Request-scoped DB query cache (default-on). Tina4 PHP runs a
        // LONG-RUNNING built-in server, so in-memory cache state persists
        // across requests. Clear the request-scoped layer on every live
        // connection at the START of each request so cached rows never leak
        // across requests (zero cross-request staleness). Persistent-mode
        // connections (TINA4_DB_CACHE=true) are left untouched. Every server
        // path — built-in Server, Swoole, RoadRunner, PHP-FPM — funnels
        // through dispatch(), so this is the universal request entry point.
        \Tina4\Database\CachedDatabase::resetRequestCaches();

        // Start PHP's native session so $_SESSION writes persist across
        // requests on every SAPI — Apache + PHP-FPM, FastCGI, CGI,
        // command-line — not just on the built-in dev server.
        //
        // Why this matters: PHP's default `session.auto_start = Off`
        // means $_SESSION is a transient empty array unless something
        // calls `session_start()`. The `php -S` built-in server
        // behaves as if auto_start were On for convenience, which
        // masks the bug in local development — code that reads or
        // writes $_SESSION works fine locally, then silently loses
        // every value when deployed to shared hosting.
        //
        // Tina4's own $request->session API (backed by the
        // tina4_session cookie + data/sessions/*.json files) is
        // untouched by this call; both coexist. We just make sure
        // native sessions are wired so existing app code that uses
        // $_SESSION directly — login flows, booking flows,
        // third-party integrations — keeps working.
        //
        // Storage: configurable via TINA4_PHP_SESSION_PATH (default:
        // data/sessions-php/ under the project root so shared-hosting
        // /tmp quotas don't surprise us). Cookie name via
        // TINA4_PHP_SESSION_NAME (default: PHPSESSID, PHP's default).
        //
        // Fixes tina4stack/tina4-php#112.
        self::startNativeSession();

        // Auto-start Tina4's own session — read session ID from cookie,
        // lazy-create on first use. This is independent of $_SESSION
        // above; both share nothing but coexist without conflict.
        //
        // Read the incoming cookie by the SAME configured name the write side
        // emits (TINA4_SESSION_NAME, default tina4_session) via
        // Session::cookieName() — the one shared resolver. Reading a hardcoded
        // "tina4_session" here while the write side honoured the env name meant a
        // renamed cookie was written but never read back, so the session silently
        // never resumed. $_COOKIE is keyed on the exact cookie name, so this is an
        // exact-name match (a renamed "tina4_session_foo" can never collide).
        //
        // $request->cookies FIRST (feature 131, TC-DEC-01 parity fix):
        // $_COOKIE is a raw PHP superglobal that only a real HTTP SAPI
        // (Apache/FPM/`php -S`) ever populates by parsing the actual `Cookie:`
        // header itself receives — a caller that builds $request by hand
        // (TestClient, over a CLI process with no such SAPI) can set
        // $request->cookies (Request::create() parses it from the SAME `Cookie`
        // header it was given) but can never make $_COOKIE agree. Reading
        // $_COOKIE alone meant a session-token login-then-authenticated-request
        // flow was structurally unreachable through TestClient: a login route
        // could set request->session, but the follow-up request replaying that
        // cookie could never resume it. For a real SAPI request the two sources
        // already agree (both parse the identical incoming header), so this is
        // additive there; $_COOKIE stays as the fallback for any caller that
        // never threads a Cookie header onto $request at all.
        $sessionCookieName = Session::cookieName();
        $sessionCookie = $request->cookies[$sessionCookieName] ?? $_COOKIE[$sessionCookieName] ?? null;
        // LOG LOUD, THEN DEGRADE (ADR-0021). Session's own read/write policy
        // already logs and degrades, but CONSTRUCTION sits outside it: a
        // refused TINA4_SESSION_BACKEND throws from the constructor, and a
        // handler that cannot be built throws from start(). Both were unguarded
        // here, so an unusable session backend returned a 500 for EVERY request
        // instead of serving the page without a session.
        //
        // An EMPTY session never reaches this catch. start() returns an empty
        // session for an id the store has never heard of, which is an ordinary
        // outcome and not an error - logging that would put a line in the log
        // for every new visitor and bury the real outage.
        $session = null;
        try {
            $session = new Session();
            $session->start($sessionCookie);
        } catch (\Throwable $sessionError) {
            // Log::error, the same sink Session's own backend-failure policy
            // writes to, so an outage reads as one story rather than two.
            Log::error(
                'Session unavailable for this request ('
                . get_class($sessionError) . '): ' . $sessionError->getMessage()
            );
            if (DotEnv::isTruthy(DotEnv::getEnv('TINA4_SESSION_STRICT', 'false'))) {
                throw $sessionError;
            }
            $session = null;
        }
        $request->session = $session;

        $result = self::dispatchInner($request, $response);

        // No session means nothing to persist and no cookie to emit. The
        // request still serves - that is the degrade half of the policy.
        if ($session !== null) {
            self::saveSessionAndSetCookie($session, $sessionCookie, $sessionCookieName, $result);
        }

        // Compression + ETag + conditional-GET (feature 40, CE-DEC-01/02) — the
        // ONE header-builder step every response funnels through, matching
        // Python's build_headers() + app() dispatch. Runs BEFORE stripHeadBody
        // so a HEAD response's preserved Content-Length reflects the (possibly
        // compressed) body the equivalent GET would have sent.
        $result = self::applyConditionalGet(
            $request,
            self::compressAndTag($request, $result)
        );

        $result = self::stripHeadBody($request, $result);

        self::logRequest($request, $result, $reqStart);

        // Echo the correlation id on the response (the SAME id as the log lines
        // and the error page), whatever outcome dispatchInner produced - 200,
        // 404 or 500 - so a client or downstream service can reference it.
        $result->header('X-Request-ID', $requestId);

        return $result;
    }


    /**
     * Whether to emit a per-request log line (v3.13.14).
     *
     * TINA4_LOG_REQUESTS is the explicit control (true/false). When unset,
     * request logging follows dev mode: on under TINA4_DEBUG, off in
     * production. Same contract across all four frameworks.
     */
    private static function requestLoggingEnabled(): bool
    {
        $val = DotEnv::getEnv('TINA4_LOG_REQUESTS');
        if ($val !== null && $val !== '') {
            return DotEnv::isTruthy($val);
        }
        return DotEnv::isTruthy(DotEnv::getEnv('TINA4_DEBUG', 'false'));
    }

    /**
     * Inner dispatch — handles route matching, middleware, and handler invocation.
     */
    /**
     * Invoke the route handler, wrapped in any function-style middleware.
     *
     * The call is built as a closure so continuation middleware can wrap it
     * Express-style: each entry in $functionMiddlewares receives a $next that
     * descends to the next layer, or it returns early to short-circuit. First
     * declared is the OUTERMOST layer.
     *
     * A throwing handler is caught here: the trace goes to Log::error and to
     * any listener, the dev overlay renders it, and production gets the
     * generic page plus a request id. SECURITY (CWE-209): the production body
     * must NOT carry the stack trace.
     *
     * @param Request  $request             Rebound when middleware returns a new pair
     * @param Response $response            Rebound when middleware returns a new pair
     * @param array    $route               The matched route
     * @param array    $functionMiddlewares Continuation-style middleware, outermost first
     * @return mixed The handler's result, or a Response when it short-circuited or threw
     */
    private static function invokeRouteHandler(
        Request &$request,
        Response &$response,
        array $route,
        array $functionMiddlewares
    ): mixed {
        // Build the route handler invocation as a closure so function-style
        // middleware can wrap it Express-style. Each middleware in
        // $functionMiddlewares is given a $next continuation that either
        // invokes the next layer or — at the innermost layer — runs the
        // actual route callback. Iteration is reversed so the first
        // declared middleware ends up as the outermost wrapper.
        $invokeRouteHandler = function (Request $request, Response $response) use ($route): mixed {
            $args = self::bindHandlerArgs($request, $response, $route['callback']);

            return count($args) === 0
                ? ($route['callback'])()
                : ($route['callback'])(...$args);
        };

        $handlerChain = $invokeRouteHandler;
        foreach (array_reverse($functionMiddlewares) as $mw) {
            $next = $handlerChain;
            $handlerChain = static function (Request $req, Response $resp) use ($mw, $next): mixed {
                return $mw($req, $resp, $next);
            };
        }

        try {
            $handlerResult = $handlerChain($request, $response);
        } catch (\Throwable $e) {
            // v3.13.7: Surface route failures to observability (CloudWatch,
            // Sentry, etc.) BEFORE rendering the 500. Listeners get the
            // canonical {exception, request} pair — same shape as Python /
            // Ruby / Node. Listener throws are swallowed + warning-logged
            // so a broken listener can't break the 500 page.
            Log::error(sprintf(
                'Route error: %s: %s',
                $e::class,
                $e->getMessage()
            ), [
                'method' => $request->method ?? null,
                'path'   => $request->path ?? null,
            ]);
            try {
                Events::emit('tina4.request.error', [
                    'exception' => $e,
                    'request'   => $request,
                ]);
            } catch (\Throwable $listenerErr) {
                try {
                    Log::warning(sprintf(
                        'Listener for tina4.request.error raised: %s: %s',
                        $listenerErr::class,
                        $listenerErr->getMessage()
                    ));
                } catch (\Throwable) {
                    // Log failures must never block the 500 render.
                }
            }

            if (ErrorOverlay::isDebugMode()) {
                // OVERLAY-DEC-03: guard the dev-overlay render. This call site sits
                // INSIDE the catch, so if the overlay itself throws (a malformed
                // frame, an unrenderable request value) it would double-fault out of
                // dispatch. Wrap it and fall back to the same safe production page, so
                // a broken overlay still yields a bounded 500 — never a crash.
                try {
                    $overlayHtml = ErrorOverlay::renderErrorOverlay($e, [
                        'REQUEST_METHOD' => $request->method,
                        'REQUEST_URI' => $request->path,
                        'CONTENT_TYPE' => $request->contentType ?? '',
                        'REMOTE_ADDR' => $request->ip ?? '',
                        'QUERY_STRING' => $request->query ?? '',
                        'headers' => $request->headers ?? [],
                        'params' => $request->params ?? [],
                        'body' => is_array($request->body) ? $request->body : [],
                    ]);
                    return $response->html($overlayHtml, 500);
                } catch (\Throwable $overlayErr) {
                    try {
                        Log::warning(sprintf(
                            'Error overlay render failed, serving the safe page: %s: %s',
                            $overlayErr::class,
                            $overlayErr->getMessage()
                        ));
                    } catch (\Throwable) {
                        // Log failures must never block the 500 render.
                    }
                    // fall through to the safe production page below
                }
            }
            // v3.13.7 SECURITY (CWE-209): production response body must
            // NOT contain the stack trace. The trace stays in Log::error
            // above and in any listener consumers. Clients only see the
            // generic page + request_id (request_id itself is resolved
            // centrally by renderError() from Log::getRequestId()).
            $errorResp = self::renderError($response, 500, 'Server Error', $request, [
                'error_message' => '',
            ]);
            return self::injectDevToolbar($request, $errorResp, 'error');
        }


        return $handlerResult;
    }


    /**
     * TINA4_TRAILING_SLASH_REDIRECT: 301 `/foo/` to `/foo`, keeping the query.
     *
     * The bare `/` is skipped so the homepage still works. Dropping the query
     * on the redirect would silently lose the user's filters, which is why it
     * is rebuilt onto the target.
     *
     * @param Request  $request  The incoming request
     * @param Response $response Used to build the redirect
     * @return Response|null The 301, or null when the feature is off or N/A
     */
    private static function trailingSlashRedirect(Request $request, Response $response): ?Response
    {
        // TINA4_TRAILING_SLASH_REDIRECT — when truthy, any path with a trailing
        // slash (other than the bare "/") redirects 301 to the slash-stripped
        // form. Lets operators normalize URLs without per-route boilerplate.
        if (
            $request->path !== '/'
            && str_ends_with($request->path, '/')
            && DotEnv::isTruthy(DotEnv::getEnv('TINA4_TRAILING_SLASH_REDIRECT', 'false'))
        ) {
            $target = rtrim($request->path, '/');
            // Preserve query string when present
            if (!empty($request->query)) {
                $target .= '?' . http_build_query($request->query);
            }
            return $response->redirect($target, 301);
        }

        return null;
    }

    /**
     * Run the after-hooks and the two injectors, in that order.
     *
     * The dev toolbar goes first and the feedback widget second, so the
     * widget's <script> sits next to the toolbar's marker tags. Both target the
     * LAST </body> independently and are idempotent, so the order is about
     * placement rather than correctness. Both are no-ops when their feature
     * flags are unset.
     *
     * $afterMiddleware is the global set followed by the classes attached to
     * the matched route, so the after pass mirrors the order the before pass
     * ran in. Every dispatch that MATCHED a route ends here - including one a
     * middleware short-circuited - which is what makes the after pass reliable
     * enough to audit or add headers from. An unmatched path (404) has no
     * route and no after pass.
     *
     * @param Request    $request         Rebound by the after-hooks
     * @param Response   $finalResponse   The response to finish
     * @param array      $afterMiddleware Classes whose after* hooks are owed
     * @param array|null $result          The match result, for the toolbar's pattern label
     * @return Response The finished response
     */
    private static function finaliseResponse(
        Request $request,
        Response $finalResponse,
        array $afterMiddleware,
        ?array $result
    ): Response {
        if (!empty($afterMiddleware)) {
            [$request, $finalResponse] = Middleware::runAfter(
                $afterMiddleware, $request, $finalResponse, self::renderForbidden(...)
            );
        }

        $matchedPattern = $result !== null ? ($result['route']['pattern'] ?? '') : 'none';
        $finalResponse = self::injectDevToolbar($request, $finalResponse, $matchedPattern);

        return self::injectFeedbackWidget($request, $finalResponse);
    }

    /**
     * Run ONE already-normalised, non-continuation middleware.
     *
     * By the time this is called the caller has resolved any string spec and
     * peeled off the function-style set, so `$mw` is either a class name or a
     * plain callable.
     *
     *   - a resolvable class name runs its `before*` hooks;
     *   - a callable is invoked directly;
     *   - a string that is neither is logged and skipped, because a typo in a
     *     middleware name should be visible without taking the request down.
     *
     * @param Request  $request         Rebound when a middleware returns a new pair
     * @param Response $response        Rebound when a middleware returns a new pair
     * @param mixed    $mw              A class-name string or a callable
     * @param array    $classMiddleware Collects each resolved class, for the after pass
     * @return Response|null A response to send immediately, or null to continue
     */
    private static function runOneRouteMiddleware(
        Request &$request,
        Response &$response,
        mixed $mw,
        array &$classMiddleware
    ): ?Response {
        if (is_string($mw)) {
            $className = self::resolveMiddlewareClass($mw);
            if ($className !== null) {
                // Recorded BEFORE the hooks run: a class whose before* hook
                // short-circuits still owes its after* hooks (the after pass
                // runs on a 4xx, which is exactly when audit/header hooks
                // matter most).
                $classMiddleware[] = $className;
                // Returns null on success, which means "carry on" - the same
                // as the `continue` this replaced.
                return self::runClassMiddlewareHooks($request, $response, $className);
            }
            if (!is_callable($mw)) {
                Log::warning("Middleware not found: {$mw}");
                return null;
            }
        } elseif (!is_callable($mw)) {
            return null;
        }

        $mwResult = self::runCallableMiddleware($request, $response, $mw, $short);
        if ($short !== null) {
            return $short;
        }

        return self::middlewareResultToResponse($mwResult, $request, $response);
    }

    /**
     * Invoke a callable middleware and unwrap the outcome.
     *
     * Thin wrapper over {@see invokeCallableMiddleware()} so both call sites
     * read the same two lines instead of repeating the unwrap. `$short` is set
     * when the invocation itself failed and a 500 must be sent.
     *
     * @param Request       $request  Passed to the middleware
     * @param Response      $response Passed to the middleware
     * @param callable      $mw       The middleware to invoke
     * @param Response|null $short    Set to a 500 when the invocation threw
     * @return mixed The middleware's own return value, or null when it threw
     */
    private static function runCallableMiddleware(
        Request &$request,
        Response &$response,
        callable $mw,
        ?Response &$short
    ): mixed {
        $outcome = self::invokeCallableMiddleware($request, $response, $mw);
        if ($outcome instanceof Response) {
            $short = $outcome;
            return null;
        }
        $short = null;
        return $outcome[0];
    }

    /**
     * Interpret what a callable middleware returned.
     *
     * The same return-value table the class-style hooks obey, so a closure and
     * a middleware class attached to the same route cannot mean different
     * things by the same return value.
     *
     * @param mixed    $mwResult What the middleware returned
     * @param Request  $request  Rebound when the middleware returns a new pair
     * @param Response $response Rebound when the middleware returns a new pair
     * @return Response|null A response to send immediately, or null to continue
     */
    private static function middlewareResultToResponse(
        mixed $mwResult,
        Request &$request,
        Response &$response
    ): ?Response {
        return Middleware::applyHookResult(
            $mwResult,
            $request,
            $response,
            self::renderForbidden(...)
        );
    }

    /**
     * Run one global-middleware pass.
     *
     * The pre-match and post-match passes had identical bodies; this is that
     * body, once. The pass short-circuits when a hook ENDED it - it returned a
     * Response (at any status, which is how a redirect gets out) or `false` -
     * or when it leaves a non-default status: an error (4xx/5xx) or a CORS
     * preflight (204).
     *
     * $request and $response are BY REFERENCE because runBefore returns a
     * replaced pair, and the caller must see the replacement.
     *
     * @param array<int, class-string> $middleware The pass to run
     * @param Request                  $request    Rebound from the pass's result
     * @param Response                 $response   Rebound from the pass's result
     * @return Response|null A response to send immediately, or null to continue
     */
    private static function runGlobalMiddlewarePass(
        array $middleware,
        Request &$request,
        Response &$response
    ): ?Response {
        if (empty($middleware)) {
            return null;
        }

        [$request, $response, $shortCircuit] = Middleware::runBefore(
            $middleware, $request, $response, self::renderForbidden(...)
        );
        if ($shortCircuit !== null) {
            return $shortCircuit;
        }

        // A 204 is the CORS preflight answer: complete, and not an error, so
        // the status check still has one job the short-circuit signal does not
        // cover - a hook that sets 204 and returns the pair.
        return $response->getStatusCode() === 204 ? $response : null;
    }

    /**
     * Turn a handler's return value into a Response.
     *
     * A handler may return a Response (used as is), an array (JSON), a string
     * (HTML), or nothing at all - in which case whatever it wrote onto the
     * response object stands.
     *
     * @param mixed    $handlerResult Whatever the handler returned
     * @param Response $response      The response being built, and the fallback
     * @return Response The response to send
     */
    private static function handlerResultToResponse(mixed $handlerResult, Response $response): Response
    {
        if ($handlerResult instanceof Response) {
            return $handlerResult;
        }
        if (is_array($handlerResult)) {
            return $response->json($handlerResult);
        }
        if (is_string($handlerResult)) {
            return $response->html($handlerResult);
        }
        return $response;
    }

    /**
     * Start PHP's native session so $_SESSION writes persist on every SAPI.
     *
     * PHP's default `session.auto_start = Off` means $_SESSION is a transient
     * empty array unless something calls session_start(). The `php -S` built-in
     * server behaves as if auto_start were On, which MASKS the bug in local
     * development: code that reads or writes $_SESSION works fine locally, then
     * silently loses every value on shared hosting.
     *
     * Tina4's own $request->session API (the tina4_session cookie +
     * data/sessions/*.json) is untouched by this; both coexist. This only wires
     * native sessions so existing app code using $_SESSION directly - login
     * flows, booking flows, third-party integrations - keeps working.
     *
     * Storage is configurable via TINA4_PHP_SESSION_PATH (default
     * data/sessions-php/ under the project root, so shared-hosting /tmp quotas
     * do not surprise us) and TINA4_PHP_SESSION_NAME (default PHPSESSID).
     *
     * Fixes tina4stack/tina4-php#112.
     */
    private static function startNativeSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            self::configureNativeSessionStorage();

            // Harden the cookie BEFORE session_start() emits it. PHP's ini
            // defaults (session.cookie_httponly=0, cookie_samesite="",
            // cookie_secure=0) ship a bare `PHPSESSID=...; path=/` that is
            // readable by any XSS and sent on cross-site requests — and $_SESSION
            // is exactly where an app keeps auth state. Mirror the attributes the
            // tina4_session cookie already sets below; lifetime/path/domain are
            // carried over from ini so the cookie's scope is unchanged.
            $sameSite = getenv('TINA4_SESSION_SAMESITE') ?: 'Lax';
            // Secure when: explicitly asked for, forced by SameSite=None (browsers
            // reject None without Secure), or the client is really on https.
            // Request::isSecureScheme() honours x-forwarded-proto, so a TLS-
            // terminating proxy no longer reads as plain HTTP (#175).
            $nativeSecure = DotEnv::isTruthy(DotEnv::getEnv('TINA4_SESSION_SECURE', 'false'))
                || strcasecmp($sameSite, 'None') === 0
                || Request::isSecureScheme();
            $cookieParams = session_get_cookie_params();
            session_set_cookie_params([
                'lifetime' => $cookieParams['lifetime'],
                'path' => $cookieParams['path'],
                'domain' => $cookieParams['domain'],
                'secure' => $nativeSecure,
                'httponly' => true,
                'samesite' => $sameSite,
            ]);
            @session_start();
        }
    }

    /**
     * Point PHP's native session at a writable, predictable directory.
     *
     * Defaults to `data/sessions-php/` under the project root rather than
     * /tmp, so a shared-hosting /tmp quota does not surprise anyone. Override
     * with TINA4_PHP_SESSION_PATH; the cookie name with
     * TINA4_PHP_SESSION_NAME (default PHPSESSID, PHP's own).
     *
     * The directory is created if missing and only used when it is actually
     * writable - otherwise PHP's default is left alone rather than pointing
     * sessions at a path that silently fails to persist.
     *
     * NOTE the $basePath resolution is INSIDE this method. A first attempt at
     * this split left it in the caller, so $basePath was undefined here and
     * the save path became a bare "/data/sessions-php" - unwritable, so
     * session_save_path was never applied and sessions stopped persisting.
     * Two SessionCookieName resume tests caught it. PHP does not error on an
     * undefined variable in a string concatenation, so nothing else would have.
     */
    private static function configureNativeSessionStorage(): void
    {
            // Router::$basePath is set by App::__construct, so falling
            // back to getcwd() is defensive for code paths that use
            // Router::dispatch() directly in tests without booting an
            // App instance first.
            $basePath = self::$basePath !== '.' ? self::$basePath : getcwd();
            $sessionPath = getenv('TINA4_PHP_SESSION_PATH')
                ?: ($basePath . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'sessions-php');
            if (!is_dir($sessionPath)) {
                @mkdir($sessionPath, 0755, true);
            }
            if (is_writable($sessionPath)) {
                session_save_path($sessionPath);
            }
            $sessionName = getenv('TINA4_PHP_SESSION_NAME') ?: 'PHPSESSID';
            session_name($sessionName);
    }

    /**
     * Persist Tina4's own session and set its cookie after the handler ran.
     *
     * The cookie is only re-emitted when the session id actually CHANGED,
     * compared against the value that arrived on the request - otherwise every
     * response would carry a redundant Set-Cookie.
     *
     * @param Session     $session            The session to save
     * @param string|null $sessionCookie      The id that arrived on the request, if any
     * @param string      $sessionCookieName  The configured cookie name (Session::cookieName())
     * @param Response    $result             The response a Set-Cookie header is added to
     */
    private static function saveSessionAndSetCookie(
        Session $session,
        ?string $sessionCookie,
        string $sessionCookieName,
        Response $result
    ): void {
        // Save session and set cookie after handler runs
        $session->save();

        // Probabilistic garbage collection (~1% of requests)
        if (random_int(1, 100) === 1) {
            try {
                $session->gc();
            } catch (\Throwable) {
                // GC failure is non-critical — silently ignore
            }
        }
        $sid = $session->getSessionId();
        if ($sid && $sid !== $sessionCookie) {
            self::emitSessionCookie($sid, $sessionCookieName, $result);
        }
    }

    /**
     * Emit the Tina4 session cookie, by whichever route the SAPI allows.
     *
     * Two paths, and the choice is forced rather than stylistic: once headers
     * are sent PHP's setcookie() is a no-op with a warning, so the built-in
     * server path writes a Set-Cookie header onto the Response instead.
     * Apache/nginx/FPM use the native call so PHP owns the encoding.
     *
     * TINA4_SESSION_TTL, _SAMESITE and _SECURE are all honoured here. Secure is
     * forced on when SameSite=None, because browsers reject `SameSite=None`
     * without it and the cookie would be silently dropped.
     *
     * @param string   $sid                The session id to write
     * @param string   $sessionCookieName  The configured cookie name
     * @param Response $result             Response a Set-Cookie is added to on the header path
     */
    private static function emitSessionCookie(string $sid, string $sessionCookieName, Response $result): void
    {
            $ttl = (int)(getenv('TINA4_SESSION_TTL') ?: 3600);
            $sameSite = getenv('TINA4_SESSION_SAMESITE') ?: 'Lax';
            // Same rule as the native PHPSESSID cookie above: explicit opt-in,
            // forced by SameSite=None, or the client is really on https (which
            // Request::isSecureScheme() detects through a TLS proxy, #175).
            $secure = DotEnv::isTruthy(DotEnv::getEnv('TINA4_SESSION_SECURE', 'false'))
                || strcasecmp($sameSite, 'None') === 0
                || Request::isSecureScheme();

            if (headers_sent() || $result->isTesting() || $result->isRawSocket()) {
                // Built-in server mode: headers are managed via the Response object,
                // so setcookie() would trigger a fatal error. Build the Set-Cookie
                // header manually and attach it to the Response instead.
                //
                // $result->isTesting() (feature 131, TC-DEC-02 fix) catches a
                // case headers_sent() alone never could: a CLI process
                // (TestClient, PHPUnit) that will never send real headers at
                // all, so headers_sent() stays false for the whole request.
                // Before this, the ELSE branch below called PHP's native
                // setcookie() into the void -- no real SAPI ever reads it
                // back, so a TestClient-driven login route set
                // request->session, but the session cookie never reached
                // TestResponse, making a login-then-authenticated-request
                // test structurally impossible to write.
                //
                // $result->isRawSocket() (real-bug pre-merge, 3.13.99) closes
                // the gap feature 131 found and deliberately left open:
                // Tina4\Server (tina4 serve's OWN raw-socket engine) ALSO
                // never triggers headers_sent() -- a raw socket engages no
                // real PHP SAPI header-sending mechanism at all, so it isn't
                // caught by isTesting() either (a Server-driven Response is
                // genuinely transported, over a real socket, unlike a
                // TestClient Response that nothing ever sends). Before this,
                // the ELSE branch's native setcookie() call was reached under
                // Tina4\Server, into the same void -- no real SAPI ever reads
                // it back -- so a first-time session login under `tina4
                // serve` silently emitted NO Set-Cookie at all: session auth
                // was broken on the framework's own recommended dev/prod
                // server. Server::handleHttp() and Server::handle() both
                // construct their Response with rawSocket: true for exactly
                // this reason; App::__invoke() (Apache/nginx/FPM/php -S,
                // where headers_sent() is a REAL, meaningful signal) does
                // not, so this branch's reach on that path is unchanged.
                $expires = gmdate('D, d M Y H:i:s T', time() + $ttl);
                $cookie = "{$sessionCookieName}={$sid}; Expires={$expires}; Path=/; HttpOnly; SameSite={$sameSite}";
                if ($secure) {
                    $cookie .= '; Secure';
                }
                $result->header('Set-Cookie', $cookie);
            } else {
                // Apache/nginx/FPM mode: use PHP's native setcookie()
                setcookie($sessionCookieName, $sid, [
                    'expires' => time() + $ttl,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => $sameSite,
                    'secure' => $secure,
                ]);
            }
    }

    /**
     * RFC 9110 s9.3.2: the server MUST NOT send content in a HEAD response.
     *
     * Applied unconditionally - even an explicit Router::head() handler that
     * accidentally returned a body gets it stripped here, so the framework
     * cannot ship a non-conformant HEAD response whatever the handler did.
     *
     * Content-Length is preserved as the byte count the GET-equivalent body
     * WOULD have been, so cache validators, link checkers and monitoring probes
     * still get useful size information (s9.3.2 SHOULD - same headers as the
     * equivalent GET).
     *
     * PHP strips LATE, at the single exit; Node wraps write/end EARLY because
     * it streams. ADR-0011 keeps the OUTCOME shared and the mechanism idiomatic
     * per runtime.
     *
     * @param Request  $request The request, read for its method
     * @param Response $result  The response to strip
     * @return Response The same response, body removed when this was a HEAD
     */
    /**
     * Gzip-compress + attach an ETag (feature 40, CE-DEC-01) — the one
     * header-builder step every response funnels through. A streaming
     * response (SSE) has no buffered body to compress or hash, so it is
     * skipped, mirroring Python's "streaming responses bypass ETag/compression".
     *
     * @param Request  $request  The incoming request (read for Accept-Encoding)
     * @param Response $response The response to compress/tag in place
     * @return Response The same instance, mutated
     */
    private static function compressAndTag(Request $request, Response $response): Response
    {
        if ($response->isStreaming()) {
            return $response;
        }
        $response->compressAndTag($request->headers['accept-encoding'] ?? '');
        return $response;
    }

    /**
     * Answer a matching conditional GET with a 304 that PRESERVES whichever
     * validators (ETag / Last-Modified) the 200 would have carried (feature 40,
     * CE-PY-304-DROPS-VALIDATORS was a Python-only bug — PHP's static path
     * already preserved them; this extends the SAME preservation to a dynamic
     * response, which never carried a validator to preserve before CE-DEC-01).
     *
     * A static-file 304 is already terminal by the time this runs
     * (StaticFiles::tryServe's own short-circuit already answered it, so its
     * status is 304, not 200) — the guard below leaves it untouched rather than
     * re-deciding it, so nothing here can double-304 or diverge from that
     * already-tested path.
     *
     * @param Request  $request  The incoming request (read for the conditional headers)
     * @param Response $response The candidate 200 response
     * @return Response Either $response unchanged, or a fresh 304
     */
    private static function applyConditionalGet(Request $request, Response $response): Response
    {
        if ($response->getStatusCode() !== 200 || $response->getBody() === '') {
            return $response;
        }

        $etag = $response->getHeader('ETag');
        $lastModified = $response->getHeader('Last-Modified');
        $ifNoneMatch = $request->headers['if-none-match'] ?? '';
        $ifModifiedSince = $request->headers['if-modified-since'] ?? '';

        // If-None-Match takes precedence over If-Modified-Since (RFC 9110 S13.1.3).
        $notModified = false;
        if ($ifNoneMatch !== '' && $etag !== null) {
            $notModified = Response::etagMatches($ifNoneMatch, $etag);
        } elseif ($ifNoneMatch === '' && $ifModifiedSince !== '' && $lastModified !== null) {
            $since = strtotime($ifModifiedSince);
            $modified = strtotime($lastModified);
            $notModified = $since !== false && $modified !== false && $modified <= $since;
        }

        if (!$notModified) {
            return $response;
        }

        $notModifiedResponse = new Response();
        $notModifiedResponse->status(304);
        if ($etag !== null) {
            $notModifiedResponse->header('ETag', $etag);
        }
        if ($lastModified !== null) {
            $notModifiedResponse->header('Last-Modified', $lastModified);
        }
        $notModifiedResponse->setBody('');
        return $notModifiedResponse;
    }

    private static function stripHeadBody(Request $request, Response $result): Response
    {
        // RFC 9110 §9.3.2: the server MUST NOT send content in a HEAD
        // response. Apply unconditionally — even an explicit Router::head()
        // handler that accidentally returned a body gets the body stripped
        // here, so the framework can't ship a non-conformant HEAD response
        // no matter what the handler did.
        //
        // Content-Length is preserved (as the byte count the GET-equivalent
        // body WOULD have been) so cache validators, link checkers, and
        // monitoring probes get useful size information from the HEAD probe.
        // RFC 9110 §9.3.2 SHOULD — same headers as the equivalent GET.
        if (strtoupper($request->method) === 'HEAD') {
            $body = $result->getBody();
            if ($body !== '') {
                $result->header('Content-Length', (string) strlen($body));
                $result->setBody('');
            }
        }

        return $result;
    }

    /**
     * Emit the per-request log line.
     *
     * On by default in dev so `tina4 serve` shows request activity on stdout,
     * opt-in in production via TINA4_LOG_REQUESTS. Routed through Tina4\Log so
     * it lands on stdout like every other log. Same format across all four
     * frameworks.
     *
     * @param Request  $request  The request, for method and path
     * @param Response $result   The response, for the status
     * @param float    $reqStart microtime(true) captured at dispatch entry
     */
    private static function logRequest(Request $request, Response $result, float $reqStart): void
    {
        // Request log line (v3.13.14). On by default in dev (so `tina4 serve`
        // shows request activity on stdout), opt-in in production via
        // TINA4_LOG_REQUESTS. Routed through Tina4\Log so it lands on stdout
        // like every other log. Same format across all four frameworks.
        if (self::requestLoggingEnabled()) {
            $elapsed = round((microtime(true) - $reqStart) * 1000, 3);
            $status = $result->getStatusCode();
            Log::info("{$request->method} {$request->path} -> {$status} ({$elapsed}ms)");
        }
    }

    /**
     * Bind a route handler's parameters BY NAME.
     *
     * A handler declares whatever it needs - `($id, $request, $response)`,
     * `($request, $response)`, or nothing - and each parameter is resolved by
     * its own name and type hint:
     *
     *   1. a path parameter of that name wins;
     *   2. otherwise a `Request` type hint (or the literal name `request`)
     *      gets the request, a `Response` type hint (or the literal name
     *      `response`) gets the response;
     *   3. a param that matches NEITHER (untyped, unrecognised name) is
     *      unclaimed - with exactly two unclaimed-or-request/response params
     *      total and no OTHER param already claiming the request, the FIRST
     *      unclaimed one positionally becomes the request (parity with the
     *      Python/Ruby/Node masters, which bind an ambiguous 2-param handler
     *      positionally). This is what makes an untyped `fn($req, $res)`
     *      bind `$req` to the Request instead of both params silently
     *      landing on the Response (the pre-3.13.99 misbinding);
     *   4. anything still unclaimed after that gets the response.
     *
     * @param Request  $request  Candidate for injection, and the source of path params
     * @param Response $response The default injection for an unmatched parameter
     * @param callable $callback The handler whose signature is being read
     * @return array<int, mixed> Positional arguments for the handler
     */
    private static function bindHandlerArgs(Request $request, Response $response, callable $callback): array
    {
        $refParams = (new \ReflectionFunction($callback))->getParameters();
        $routeParams = $request->params;

        $args = [];
        $unclaimed = [];        // indices into $args a Request/Response type/name never pinned
        $requestClaimed = false; // some param already unambiguously claimed the request
        $remainingCount = 0;     // params not resolved by a path-parameter name

        foreach ($refParams as $i => $p) {
            $name = $p->getName();
            if (array_key_exists($name, $routeParams)) {
                $args[$i] = $routeParams[$name];
                continue;
            }

            $remainingCount++;
            $type = $p->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            $isRequest = $typeName === Request::class || $typeName === 'Tina4\\Request' || $name === 'request';
            $isResponse = $typeName === Response::class || $typeName === 'Tina4\\Response' || $name === 'response';

            if ($isRequest) {
                $args[$i] = $request;
                $requestClaimed = true;
            } elseif ($isResponse) {
                $args[$i] = $response;
            } else {
                $unclaimed[] = $i;
                $args[$i] = $response; // default; corrected below if it wins the positional fallback
            }
        }

        // Positional fallback: exactly two non-path params (the documented
        // `($request, $response)` arity) and nothing already pinned the
        // request explicitly - the first unclaimed param IS the request.
        // A param with an explicit type hint or literal name always wins;
        // this only ever corrects params that gave no signal at all.
        if ($remainingCount === 2 && !$requestClaimed && count($unclaimed) > 0) {
            $args[$unclaimed[0]] = $request;
        }

        return $args;
    }

    /**
     * Resolve a middleware string to a real class name.
     *
     * Tries the name as given, then ucfirst - so "authMiddleware" finds
     * `AuthMiddleware`.
     *
     * @param string $mw The middleware spec
     * @return string|null The resolved class name, or null when none exists
     */
    private static function resolveMiddlewareClass(string $mw): ?string
    {
        foreach ([$mw, ucfirst($mw)] as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Invoke a callable middleware, converting a throw into a clean 500.
     *
     * Both call sites in {@see runRouteMiddleware()} had this try/catch
     * written out identically; this is that body, once.
     *
     * The return is deliberately two-shaped so a thrown 500 cannot be confused
     * with a middleware that legitimately RETURNED a Response: a bare Response
     * means "the invocation failed, send this", while a one-element array
     * wraps whatever the middleware itself returned.
     *
     * @param Request  $request  Passed to the middleware
     * @param Response $response Passed to the middleware, and used to build a 500
     * @param callable $mw       The middleware to invoke
     * @return Response|array{0: mixed} A 500 to send, or [the middleware's result]
     */
    private static function invokeCallableMiddleware(
        Request &$request,
        Response &$response,
        callable $mw
    ): Response|array {
        try {
            return [$mw($request, $response)];
        } catch (\Throwable $error) {
            return Middleware::middleware500($response, 'Closure', '__invoke', $error);
        }
    }

    /**
     * Run every `before*` static hook on a class-style middleware.
     *
     * Discovery and return-value handling both come from {@see Middleware},
     * so a class attached to a route behaves exactly as the same class
     * registered globally: base-class hooks first, definition order within a
     * class, and the one return-value table (Response ends it at any status,
     * a pair rebinds, `false` ends it, null continues).
     *
     * Each invocation is wrapped (M2): a throwing before* is logged and
     * converted to a clean 500 rather than an unhandled crash.
     *
     * @param Request  $request   Rebound when a hook returns a new pair
     * @param Response $response  Rebound when a hook returns a new pair
     * @param string   $className The middleware class to run
     * @return Response|null A response to send immediately, or null to continue
     */
    private static function runClassMiddlewareHooks(
        Request &$request,
        Response &$response,
        string $className
    ): ?Response {
        foreach (Middleware::discoverMethods($className, 'before') as $method) {
            try {
                $mwResult = $className::$method($request, $response);
            } catch (\Throwable $error) {
                return Middleware::middleware500($response, $className, $method, $error);
            }

            $shortCircuit = Middleware::applyHookResult(
                $mwResult,
                $request,
                $response,
                self::renderForbidden(...)
            );
            if ($shortCircuit !== null) {
                return $shortCircuit;
            }

            // LEGACY COMPATIBILITY PATH, not the main mechanism: a hook that
            // returned null but left an error status still ends the chain.
            if ($response->getStatusCode() >= 400) {
                return $response;
            }
        }

        return null;
    }

    /**
     * Build the 403 a middleware gets when it says no without saying what to send.
     *
     * Routed through the normal error renderer so a middleware refusal looks
     * like every other error page - a user template if the app ships one, the
     * framework's 403 template otherwise, JSON as the last resort.
     *
     * @param Request  $request  Read for the path shown on the error page
     * @param Response $response The response being built
     * @return Response The 403
     */
    private static function renderForbidden(Request $request, Response $response): Response
    {
        return self::renderError($response, 403, 'Forbidden', $request);
    }

    /**
     * Find the request's auth token, in priority order.
     *
     * Three transports, and the ORDER is the contract: an explicit
     * Authorization header beats a form token, which beats a session token.
     * The source is returned alongside because a body formToken earns a
     * FreshToken response header, and the other two do not.
     *
     * @param Request $request The incoming request
     * @return array{0: string|null, 1: string|null} [token, source] - both null when absent
     */
    private static function extractAuthToken(Request $request): array
    {
        $token = null;
        $tokenSource = null;

            // Priority 1: Authorization Bearer header
            $bearerToken = $request->bearerToken();
            if ($bearerToken !== null) {
                $token = $bearerToken;
                $tokenSource = 'header';
            }

            // Priority 2: formToken in request body
            if ($token === null && is_array($request->body) && !empty($request->body['formToken'])) {
                $token = $request->body['formToken'];
                $tokenSource = 'body';
            }

            // Priority 3: Session token
            if ($token === null && $request->session !== null && $request->session->has('token')) {
                $token = $request->session->get('token');
                $tokenSource = 'session';
            }

        return [$token, $tokenSource];
    }

    /**
     * Run a route's own middleware, splitting it into two groups.
     *
     *   - class-style (string class names) run their `before*` static methods
     *     INLINE here, before the handler. Two-arg closures ($req, $resp) are
     *     treated as "filter" style and run here too.
     *   - function-style continuation middleware (closures/callables declaring
     *     3+ parameters: $req, $resp, $next) are COLLECTED into
     *     $functionMiddlewares and wrap the handler Express-style, each calling
     *     $next to descend or returning early to short-circuit. That is the
     *     pattern documented in chapter 10 for 8+ examples; pre-3.13.2 PHP
     *     silently ignored the $next argument and ran them as two-arg filters,
     *     which made the example bodies dead code (tina4-book#141 PY-10-01).
     *
     * Collecting the function-style set rather than running it here is not a
     * reordering: those middlewares were always deferred to the handler wrap.
     *
     * $request and $response are BY REFERENCE because a middleware may return
     * a replaced pair, and the caller must see the replacement.
     *
     * The class-style names are also COLLECTED into $classMiddlewares, because
     * their `after*` hooks belong to the response pass rather than to this one
     * - see {@see finaliseResponse()}.
     *
     * @param Request  $request              Rebound when a middleware returns a new pair
     * @param Response $response             Rebound when a middleware returns a new pair
     * @param array    $route                The matched route, read for its middleware list
     * @param array    $functionMiddlewares  Filled with the continuation-style middleware
     * @param array    $classMiddlewares     Filled with the class-style middleware
     * @return Response|null A response to send immediately, or null to continue
     */
    private static function runRouteMiddleware(
        Request &$request,
        Response &$response,
        array $route,
        array &$functionMiddlewares,
        array &$classMiddlewares
    ): ?Response {
        // Split middleware into two groups:
        //   - class-style (string class names) → before_*/before static
        //     methods run inline before the handler. Two-arg closures
        //     ($req, $resp) are also treated as "filter" style here.
        //   - function-style continuation middleware (closures/callables
        //     declaring 3+ parameters: $req, $resp, $next). These wrap
        //     the route handler Express-style — each one calls $next to
        //     descend, or returns early to short-circuit. This is the
        //     pattern documented in chapter 10 for 8+ examples; pre-3.13.2
        //     PHP silently ignored the $next argument and ran them as
        //     two-arg filters, which made the example bodies dead code.
        //     tina4-book#141 PY-10-01 parity fix.
        foreach ($route['middleware'] as $mw) {
            // String specs for known instance middleware ("ResponseCache",
            // "ResponseCache:300") resolve to an Express-style continuation
            // that runs the instance's before/after hooks around the handler.
            // Parity with Python's _resolve_string_middleware registry. Returns
            // null for plain class strings (handled by the before_* loop below).
            if (is_string($mw)) {
                $resolved = self::resolveStringMiddleware($mw);
                if ($resolved !== null) {
                    $mw = $resolved;
                }
            }
            if (self::isFunctionMiddleware($mw)) {
                $functionMiddlewares[] = $mw;
                continue;
            }
            $short = self::runOneRouteMiddleware($request, $response, $mw, $classMiddlewares);
            if ($short !== null) {
                return $short;
            }
        }

        return null;
    }

    /**
     * Enforce the secure-by-default auth gate for a matched route.
     *
     * Dev admin routes (/__dev/) are always public. Write routes
     * (POST/PUT/PATCH/DELETE) are secure by default - use ->noAuth() or
     * @noauth to opt out. GET/HEAD/OPTIONS are open by default - use
     * ->secure() or @secured to require a token.
     *
     * The presence of custom middleware does NOT relax this gate
     * (tina4-book#141 PY-10-02 parity fix). Pre-3.13.2 PHP silently opened
     * write routes the moment any logging or CORS middleware was attached;
     * middleware is now purely additive.
     *
     * @param Request  $request  The incoming request; $request->user is set on success
     * @param Response $response The response being built; may gain a FreshToken header
     * @param array    $route    The matched route, read for noAuth / secure flags
     * @return Response|null A 401 to send, or null when the request may proceed
     */
    private static function enforceRouteAuth(Request $request, Response $response, array $route): ?Response
    {
        // ── Auth enforcement ──────────────────────────────────────
        // Dev admin routes (/__dev/) are always public — no auth required.
        // Write routes (POST/PUT/PATCH/DELETE) are secure by default.
        // Use ->noAuth() or @noauth to opt out.
        // GET/HEAD/OPTIONS are open by default; use ->secure() or @secured to require auth.
        //
        // Note: presence of custom middleware does NOT relax this gate
        // (tina4-book#141 PY-10-02 parity fix). Pre-3.13.2 PHP silently
        // opened write routes the moment any logging/CORS middleware was
        // attached. Middleware is now purely additive — developers
        // explicitly open routes with ->noAuth() and lock GETs with
        // ->secure().
        // Match on $request->path (the path portion, e.g. "/__dev/api/reload"),
        // NOT $request->url (the full "scheme://host/path" — which never starts
        // with "/__dev" so the bypass silently never fired and write routes
        // under /__dev returned 401). The trailing-slash redirect branch above
        // already uses $request->path; this aligns with it.
        $isDevAdmin = str_starts_with($request->path, '/__dev') || str_starts_with($request->path, '/api/gallery/') || str_starts_with($request->path, '/gallery/');
        $isWriteMethod = in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $requiresAuth = false;

        if ($isDevAdmin) {
            // Dev admin routes never require auth
            $requiresAuth = false;
        } elseif ($isWriteMethod) {
            // Write routes require auth unless ->noAuth()
            $requiresAuth = empty($route['noAuth']);
        } else {
            // Read routes require auth only when explicitly marked secure
            $requiresAuth = !empty($route['secure']);
        }

        if ($requiresAuth) {
            $token = null;
            $tokenSource = null;

            [$token, $tokenSource] = self::extractAuthToken($request);

            if ($token === null) {
                return $response->json(['error' => 'Unauthorized'], 401);
            }

            // Pass token only — Auth::validToken resolves SECRET consistently
            // ($_ENV first, then getenv). Pre-resolving here with `getenv()` only
            // would mismatch Auth::getToken's resolution and reject valid tokens
            // whenever $_ENV['SECRET'] differs from getenv('SECRET') (e.g. when
            // .env loads SECRET into $_ENV but a test or runtime override calls
            // putenv with a different value).
            if (!Auth::validToken($token)) {
                return $response->json(['error' => 'Unauthorized'], 401);
            }

            // Attach decoded JWT payload to the request for downstream use
            $request->user = Auth::getPayload($token);

            // When body formToken validates, return a FreshToken header so
            // frond.js can use the Authorization header on subsequent requests
            if ($tokenSource === 'body') {
                $freshToken = Auth::refreshToken($token);
                if ($freshToken !== null) {
                    $response->header('FreshToken', $freshToken);
                }
            }
        }

        return null;
    }

    /**
     * Nothing matched the path: 405/OPTIONS, then static, then a template, then 404.
     *
     * Order is BEHAVIOUR. The RFC 9110 method scan runs FIRST, so a known path
     * with the wrong method is a 405 rather than being mistaken for a missing
     * file. Static resolution happens HERE - in the not-found fallback, after
     * matching - which is the ADR-0010 position: a file arriving from a build
     * step, an upload directory or a careless deploy must never shadow a
     * reviewed route.
     *
     * @param Request  $request  The incoming request
     * @param Response $response The response being built
     * @return Response The 204, 405, static asset, rendered template, or 404
     */
    private static function dispatchNoMatch(Request $request, Response $response): Response
    {
        // RFC 9110 conformance — if the path itself has registered methods but
        // the request's method isn't one of them, we owe the client 405 (not
        // 404) with an Allow header. §15.5.6 + §10.2.1.
        //
        // OPTIONS gets special handling: §9.3.7 says an OPTIONS request to an
        // existing resource returns 204 No Content with the same Allow header.
        // We treat it as a 204-shaped 405 — same scan, different status — so
        // the client can discover the resource's method set.
        //
        // TRACE and CONNECT (and any other unknown method like PROPFIND) fall
        // into this path naturally when the resource exists — they get 405,
        // never 200, because the framework deliberately ships no handlers.
        $allowedMethods = self::methodsAllowedForPath($request->path);
        if (!empty($allowedMethods)) {
            $allowHeader = implode(', ', $allowedMethods);
            if (strtoupper($request->method) === 'OPTIONS') {
                $response->header('Allow', $allowHeader);
                // 204 No Content — OPTIONS responses have no body by
                // convention. Use the status setter, not the JSON helper, so
                // the body stays empty.
                return $response->status(204);
            }
            $errorResp = self::renderError($response, 405, 'Method Not Allowed', $request);
            $errorResp->header('Allow', $allowHeader);
            return self::injectDevToolbar($request, $errorResp, 'error');
        }

        // Path is genuinely unknown — try a static file before 404. The
        // conditional-request headers are passed through so a matching
        // validator is answered with a cheap 304 instead of re-streaming the
        // asset.
        $staticResponse = StaticFiles::tryServe(
            $request->path,
            self::$basePath,
            $request->headers['if-none-match'] ?? null,
            $request->headers['if-modified-since'] ?? null
        );
        if ($staticResponse !== null) {
            return $staticResponse;
        }

        // Try serving a template file (e.g. /hello -> src/templates/hello.twig
        // or hello.html). HEAD on a template path falls back here too,
        // matching GET's behaviour.
        if ($request->method === 'GET' || $request->method === 'HEAD') {
            $tplFile = self::resolveTemplate($request->path);
            if ($tplFile !== null) {
                return $response->render($tplFile, []);
            }
        }

        $errorResp = self::renderError($response, 404, 'Not Found', $request);
        return self::injectDevToolbar($request, $errorResp, 'error');
    }

    private static function dispatchInner(Request $request, Response $response): Response
    {
        $redirect = self::trailingSlashRedirect($request, $response);
        if ($redirect !== null) {
            return $redirect;
        }

        // PRE-MATCH global middleware: runs before a route is looked up, so its
        // headers survive a short-circuited 401/403. CORS opts in with
        // `public static bool $preMatch = true`.
        //
        // This REPLACES a hardcoded is_a(CorsMiddleware) check that ran the
        // WHOLE global set before matching and singled CORS out by class name.
        // Running everything pre-match only ever worked here because PHP's
        // CsrfMiddleware is attached per-route rather than globally; the other
        // three register it globally and read the matched route's metadata, so
        // the same ordering would break them. The flag says what each
        // middleware actually depends on instead of hardcoding one class.
        // Short-circuits when a global middleware set a non-default status -
        // an error (4xx/5xx) or a CORS preflight (204).
        $shortCircuit = self::runGlobalMiddlewarePass(Middleware::getPreMatch(), $request, $response);
        if ($shortCircuit !== null) {
            return $shortCircuit;
        }

        $result = self::match($request->method, $request->path);

        if ($result === null) {
            return self::dispatchNoMatch($request, $response);
        }

        $route = $result['route'];
        $request->params = $result['params'];

        // Expose the matched route's metadata on the request BEFORE any
        // middleware runs, so a middleware can read handler-level flags such
        // as `noAuth`. CsrfMiddleware skips a route marked ->noAuth() / @noauth
        // by reading `$request->handler['noAuth']`; without this assignment
        // `$request->handler` stayed null and that bypass was DEAD CODE on a
        // real dispatch — a @noauth POST guarded by CsrfMiddleware would be
        // wrongly blocked with 403 (tina4-python parity: request._handler).
        $request->handler = $route;

        // POST-MATCH global middleware: the default group. It runs after
        // $request->handler is assigned, so CSRF can read the matched route's
        // ->noAuth() flag, and BEFORE the auth gate below.
        //
        // That order is the mainstream one, not an internal accident: Django
        // ships CsrfViewMiddleware ahead of AuthenticationMiddleware and
        // enforces auth in a view decorator after all middleware; Laravel runs
        // the `web` group (VerifyCsrfToken) before the `auth` route middleware;
        // ASP.NET puts UseAuthorization last before the endpoint. Middleware
        // that ran only after the gate could not throttle a brute-force login
        // or log a 401 - both real operational bugs.

        // What the after pass will owe, gathered up front because EVERY exit
        // from here on - short-circuit included - finishes through
        // finaliseResponse(). The AFTER hooks run for the WHOLE global set,
        // both the pre-match and post-match groups, followed by the classes the
        // route itself attaches (filled in by runRouteMiddleware below).
        // Splitting the BEFORE pass by dependency (ADR-0012) says nothing about
        // the after pass: an after_* hook adds headers or logging and needs no
        // route metadata either way.
        //
        // REGRESSION GUARD: this assignment went missing when the pre/post
        // split landed (538cf99f) - the read survived but nothing set the
        // variable, so `!empty(null)` was false and NO global after_* hook ran
        // at all. It was silent because PHP treats an undefined variable in
        // empty() as empty. Locked by GlobalAfterMiddlewareTest.
        $globalMiddleware = Middleware::getGlobal();
        $classMiddlewares = [];

        $shortCircuit = self::runGlobalMiddlewarePass(Middleware::getPostMatch(), $request, $response);
        if ($shortCircuit !== null) {
            return self::finaliseResponse($request, $shortCircuit, $globalMiddleware, $result);
        }

        // Auth gate. Returns a 401 to send, or null to continue.
        $unauthorized = self::enforceRouteAuth($request, $response, $route);
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        // Per-route middleware. Fills $functionMiddlewares for the handler
        // wrap below and $classMiddlewares for the after pass, and returns a
        // Response when a class-style middleware short-circuits.
        $functionMiddlewares = [];
        $shortCircuit = self::runRouteMiddleware($request, $response, $route, $functionMiddlewares, $classMiddlewares);
        if ($shortCircuit !== null) {
            return self::finaliseResponse($request, $shortCircuit, [...$globalMiddleware, ...$classMiddlewares], $result);
        }

        // Invoke the handler, wrapped in any function-style middleware.
        // Returns either the handler's raw result, or a Response when the
        // invocation short-circuited or threw.
        $handlerResult = self::invokeRouteHandler($request, $response, $route, $functionMiddlewares);
        $finalResponse = self::handlerResultToResponse($handlerResult, $response);

        return self::finaliseResponse($request, $finalResponse, [...$globalMiddleware, ...$classMiddlewares], $result);
    }

    /**
     * Inject the customer feedback widget <script> into HTML responses for
     * whitelisted users. Mirrors {@see injectDevToolbar()} content-type +
     * dev-path gating — the actual whitelist/marker logic lives in
     * {@see Feedback::injectFeedbackWidget()}.
     */
    private static function injectFeedbackWidget(Request $request, Response $finalResponse): Response
    {
        $contentType = $finalResponse->getContentType() ?? '';
        if (!str_contains($contentType, 'text/html')) {
            return $finalResponse;
        }
        $body = $finalResponse->getBody();
        if (!str_contains($body, '</body>')) {
            return $finalResponse;
        }
        $injected = Feedback::injectFeedbackWidget($request, $body);
        if ($injected !== $body) {
            $finalResponse->setBody($injected);
        }
        return $finalResponse;
    }

    /**
     * Inject the dev toolbar into an HTML response when TINA4_DEBUG is on
     * and the path isn't itself a /__dev internal. Pulled out so 404 / 403
     * / 500 error pages still get the toolbar (was missing before — users
     * hitting an unknown route saw a bare error page and assumed the dev
     * admin had broken).
     */
    private static function injectDevToolbar(Request $request, Response $finalResponse, string $matchedPattern = 'none'): Response
    {
        $isDev = DotEnv::isTruthy(DotEnv::getEnv('TINA4_DEBUG', 'false'));
        if (!$isDev) {
            return $finalResponse;
        }
        if (str_starts_with($request->path, '/__dev')) {
            return $finalResponse;
        }
        $contentType = $finalResponse->getContentType() ?? '';
        if (!str_contains($contentType, 'text/html')) {
            return $finalResponse;
        }
        $requestId = Log::getRequestId() ?? '';
        $toolbar = DevAdmin::renderToolbar(
            method: $request->method,
            path: $request->path,
            matchedPattern: $matchedPattern,
            requestId: $requestId,
            routeCount: self::count(),
        );
        $body = $finalResponse->getBody();
        if (str_contains($body, '</body>')) {
            $finalResponse->setBody(str_replace('</body>', $toolbar . "\n</body>", $body));
        } else {
            $finalResponse->setBody($body . $toolbar);
        }
        return $finalResponse;
    }

    /**
     * Get all registered routes as a flat list for CLI/debug.
     *
     * @return array<int, array{method: string, pattern: string, middleware: int, cache: bool, secure: bool}>
     */
    public static function getRoutes(): array
    {
        $list = [];

        foreach (self::$routes as $method => $routes) {
            foreach ($routes as $route) {
                // Derive handler name and module from the callback
                $handler = '';
                $module = '';
                $cb = $route['callback'];
                if ($cb instanceof \Closure) {
                    $ref = new \ReflectionFunction($cb);
                    $file = $ref->getFileName();
                    $handler = $file ? basename($file) . ':' . $ref->getStartLine() : 'Closure';
                    $module = $file ? dirname($file) : '';
                } elseif (is_string($cb)) {
                    $handler = $cb;
                } elseif (is_array($cb) && count($cb) === 2) {
                    $handler = (is_object($cb[0]) ? get_class($cb[0]) : $cb[0]) . '::' . $cb[1];
                }
                $list[] = [
                    'method' => $method,
                    'pattern' => $route['pattern'],
                    'path' => $route['pattern'],
                    'middleware' => count($route['middleware']),
                    'cache' => $route['cache'],
                    'secure' => $route['secure'],
                    // Expose the fluent ->noAuth() flag so consumers (Swagger) can
                    // honour the write-secure-by-default rule. Without this key the
                    // Swagger generator's empty($route['noAuth']) was always true and
                    // a ->noAuth() write route was still documented as requiring auth.
                    'noAuth' => $route['noAuth'] ?? false,
                    'auth_required' => $route['secure'] && !($route['noAuth'] ?? false),
                    'handler' => $handler,
                    'module' => $module,
                    'callback' => $route['callback'],
                    'swagger' => $route['swagger'] ?? [],
                ];
            }
        }

        // Include WebSocket routes
        foreach (self::$wsRoutes as $wsRoute) {
            $list[] = [
                'method' => 'WS',
                'pattern' => $wsRoute['path'],
                'path' => $wsRoute['path'],
                'middleware' => 0,
                'cache' => false,
                'secure' => $wsRoute['secure'] ?? false,
                'auth_required' => $wsRoute['auth_required'] ?? ($wsRoute['secure'] ?? false),
                'handler' => '',
                'module' => '',
            ];
        }

        return $list;
    }

    /** Alias for getRoutes(). */
    public static function listRoutes(): array
    {
        return self::getRoutes();
    }

    /**
     * Get the number of registered routes (including WebSocket routes).
     */
    public static function count(): int
    {
        $count = 0;
        foreach (self::$routes as $routes) {
            $count += count($routes);
        }
        $count += count(self::$wsRoutes);
        return $count;
    }

    /** Machine-readable error codes for the negotiated JSON envelope (ERR-DEC-02). */
    private const ERROR_CODE_NAMES = [
        403 => 'FORBIDDEN', 404 => 'NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED', 500 => 'INTERNAL_SERVER_ERROR',
    ];

    /**
     * The ONE JSON error envelope for a negotiated 403/404/405/500 (ERR-DEC-02).
     *
     * Reuses the existing `Response::errorResponse()` envelope
     * (`error: true, code, message, status`) already shared by app-level
     * `$response->error()` calls, plus `request_id` for correlation
     * (feature 43, ERR-404-REQUESTID) - the SAME shape Python/Ruby/Node build.
     *
     * @param int $code HTTP status code
     * @param string $message Human-readable message (never the raw exception - CWE-209)
     * @param string $requestId The request's correlation id
     * @return array The JSON-ready error body
     */
    private static function errorJsonBody(int $code, string $message, string $requestId): array
    {
        $body = Response::errorResponse(self::ERROR_CODE_NAMES[$code] ?? ('HTTP_' . $code), $message, $code);
        $body['request_id'] = $requestId;
        return $body;
    }

    /**
     * Render an error page via Frond templates, negotiated on Accept, with a
     * JSON fallback.
     *
     * ERR-DEC-02 (content negotiation, the ONE shared decision reused by
     * 403/404/405/500 - see {@see Request::wantsJson()}): a JSON API client
     * gets the canonical JSON error envelope directly - no template attempt at
     * all. A browser negotiates the HTML page:
     *   1. User override:    src/templates/errors/{code}.twig
     *   2. Framework default: __DIR__/templates/errors/{code}.twig
     *   3. JSON fallback (defensive only - the framework ships a template for
     *      every code this is called with)
     *
     * @param Response $response The response object
     * @param int $code HTTP status code
     * @param string $message Error message (the CWE-209 guard: the 500 caller
     *                        passes a generic message here, never the real
     *                        exception - see extraData's error_message)
     * @param Request $request The incoming request (path + Accept + request id)
     * @param array $extraData Additional template variables (e.g. the 500
     *                        caller forces error_message='' in production)
     * @return Response
     */
    private static function renderError(Response $response, int $code, string $message, Request $request, array $extraData = []): Response
    {
        $requestId = $extraData['request_id'] ?? (Log::getRequestId() ?? '');

        if ($request->wantsJson()) {
            return $response->json(self::errorJsonBody($code, $message, $requestId), $code);
        }

        $templateFile = "errors/{$code}.twig";
        $data = array_merge(
            ['path' => $request->path, 'error_message' => $message, 'request_id' => $requestId],
            $extraData
        );

        // 1. Try user override in src/templates/
        $userTemplateDir = 'src/templates';
        if (is_file($userTemplateDir . '/' . $templateFile)) {
            try {
                $frond = Response::getFrond();
                $html = $frond->render($templateFile, $data);
                return $response->html($html, $code);
            } catch (\Throwable $e) {
                // Fall through to framework default
            }
        }

        // 2. Try framework default in Tina4/templates/
        $frameworkTemplateDir = __DIR__ . '/templates';
        if (is_file($frameworkTemplateDir . '/' . $templateFile)) {
            try {
                $frond = Response::getFrameworkFrond();
                $html = $frond->render($templateFile, $data);
                return $response->html($html, $code);
            } catch (\Throwable $e) {
                // Fall through to JSON
            }
        }

        // 3. JSON fallback (no template found at all)
        return $response->json(self::errorJsonBody($code, $message, $requestId), $code);
    }

    /**
     * Register a WebSocket route.
     *
     * The handler receives ($connection, $message, $event) where:
     *   - $connection is a WebSocketConnection object with send(), broadcast(), close()
     *   - $message is the message payload (null for open/close events)
     *   - $event is 'open', 'message', or 'close'
     *
     * A WebSocket route is PUBLIC by default (mirrors a GET route). It becomes
     * secured — a valid JWT is required on the upgrade — when either:
     *   (a) the handler carries an `@secured` docblock annotation, OR
     *   (b) it is registered with `$secure = true` (imperative form).
     * Both paths set `auth_required` on the route, which the upgrade entry
     * points (Server::handleWebSocketUpgrade and WebSocket::handleNewConnection)
     * enforce via WebSocket::wsAuthorized(). Mirrors Python's @secured() on a WS
     * handler / route["auth_required"].
     *
     * @param string   $path    WebSocket endpoint path (e.g. '/ws/chat')
     * @param callable $handler Handler function
     * @param bool     $secure  Force-secure the route imperatively (default false)
     */
    public static function websocket(string $path, callable $handler, bool $secure = false): void
    {
        $fullPath = self::$groupPrefix . '/' . ltrim($path, '/');
        $fullPath = '/' . trim($fullPath, '/');
        $fullPath = preg_replace('#/+#', '/', $fullPath);

        // Parse the handler docblock for @secured (mirrors addRoute()'s parsing
        // for HTTP routes). Either the docblock OR the imperative $secure flag
        // marks the route as requiring auth on the upgrade.
        if (!$secure) {
            try {
                $ref = new \ReflectionFunction($handler);
                $doc = $ref->getDocComment();
                if ($doc !== false && preg_match('/@secured\b/i', $doc)) {
                    $secure = true;
                }
            } catch (\Throwable) {
                // Not a closure or reflection failed — leave public.
            }
        }

        // Replace in place when the same path is re-registered (latest wins) so
        // a DevReload re-register doesn't append a stale duplicate.
        $entry = [
            'path' => $fullPath,
            'handler' => $handler,
            'secure' => $secure,
            'auth_required' => $secure,
        ];
        foreach (self::$wsRoutes as $index => $existing) {
            if ($existing['path'] === $fullPath) {
                self::$wsRoutes[$index] = $entry;
                return;
            }
        }
        self::$wsRoutes[] = $entry;
    }

    /**
     * Get all registered WebSocket routes.
     *
     * @return array<int, array{path: string, handler: callable}>
     */
    public static function getWebSocketRoutes(): array
    {
        return self::$wsRoutes;
    }

    /**
     * Match a WebSocket path against registered routes, extracting {param}
     * values. Mirrors Python's Router.match_ws so `/ws/rtc/{room}` and
     * `/ws/chat/{channel}` work on the built-in server (which previously matched
     * WS routes by exact string equality and could not carry path params).
     *
     * @return array{0: ?array, 1: array<string,string>} [route, params]
     */
    public static function matchWebSocket(string $path): array
    {
        foreach (self::$wsRoutes as $route) {
            // Fast path: exact match (no params) — keeps prior behaviour.
            if ($route['path'] === $path) {
                return [$route, []];
            }
            if (!str_contains($route['path'], '{') && !str_contains($route['path'], '*')) {
                continue;
            }
            $compiled = self::compilePath($route['path']);
            if (preg_match($compiled['regex'], $path, $m)) {
                $params = [];
                foreach ($compiled['paramNames'] as $name) {
                    if (isset($m[$name])) {
                        $params[$name] = $m[$name];
                    }
                }
                return [$route, $params];
            }
        }
        return [null, []];
    }

    /**
     * Reset all routes (for testing).
     */
    public static function clear(): void
    {
        self::$routes = [];
        self::$wsRoutes = [];
        self::$groupPrefix = '';
        self::$groupMiddleware = [];
        self::$lastRouteIndex = null;
        self::$lastRouteMethod = null;
        self::$basePath = '.';
        self::$resolvedStringMiddleware = [];
    }

    /**
     * Register a route.
     */
    private static function addRoute(string $method, string $path, callable $handler, array $swagger = [], array $middleware = [], ?string $template = null): self
    {
        $fullPath = self::$groupPrefix . '/' . ltrim($path, '/');
        $fullPath = '/' . trim($fullPath, '/');

        // Avoid double slashes
        $fullPath = preg_replace('#/+#', '/', $fullPath);

        // Parse the path into a regex
        $parsed = self::compilePath($fullPath);

        if (!isset(self::$routes[$method])) {
            self::$routes[$method] = [];
        }

        // Parse docblock annotations on the callback for @noauth / @secured
        $noAuth = false;
        $secure = false;
        try {
            $ref = new \ReflectionFunction($handler);
            $doc = $ref->getDocComment();
            if ($doc !== false) {
                if (preg_match('/@noauth\b/i', $doc)) {
                    $noAuth = true;
                }
                if (preg_match('/@secured\b/i', $doc)) {
                    $secure = true;
                }
            }
        } catch (\Throwable) {
            // Not a closure or reflection failed — ignore
        }

        $entry = [
            'pattern' => $fullPath,
            'regex' => $parsed['regex'],
            'paramNames' => $parsed['paramNames'],
            'paramTypes' => $parsed['paramTypes'],
            'callback' => $handler,
            'middleware' => array_merge(self::$groupMiddleware, $middleware),
            'cache' => false,
            'secure' => $secure,
            'noAuth' => $noAuth,
            'catchAll' => $parsed['catchAll'],
            'catchAllName' => $parsed['catchAllName'],
            'swagger' => $swagger,
            'template' => $template,
        ];

        // Replace in place when the same (method, path) is registered again —
        // latest wins. Without this, a re-loaded route file (DevReload editing
        // an existing handler) would APPEND a duplicate and matchInTable would
        // keep returning the FIRST (stale) entry, so the edit never takes
        // effect. Distinct paths keep their original slot, so order and
        // first-match behaviour are preserved.
        $existingIndex = null;
        foreach (self::$routes[$method] as $index => $existing) {
            if ($existing['pattern'] === $fullPath) {
                $existingIndex = $index;
                break;
            }
        }

        if ($existingIndex !== null) {
            self::$routes[$method][$existingIndex] = $entry;
            self::$lastRouteIndex = $existingIndex;
        } else {
            self::$routes[$method][] = $entry;
            self::$lastRouteIndex = count(self::$routes[$method]) - 1;
        }

        self::$lastRouteMethod = $method;

        return new self();
    }

    /**
     * Compile a route path pattern into a regex.
     *
     * Supports:
     *   {param}     — named parameter (matches one path segment)
     *   {param:.*}  — catch-all parameter (matches remaining path)
     *
     * @return array{regex: string, paramNames: array<string>, catchAll: bool, catchAllName: string|null}
     */
    /**
     * Honour TINA4_TEMPLATE_ROUTING=off|false|0|no|disabled as an explicit
     * kill switch.
     *
     * Default: enabled. Drop a file in src/templates/pages/ and it serves at
     * the matching URL — the zero-config Tina4 convention. Operators who want
     * explicit-only routing can set TINA4_TEMPLATE_ROUTING=off and every URL
     * must be registered via Router::get / Router::post (or be a static file).
     */
    public static function isTemplateAutoRoutingEnabled(): bool
    {
        $val = strtolower(trim((string) DotEnv::getEnv('TINA4_TEMPLATE_ROUTING', 'on')));
        return !in_array($val, ['off', 'false', '0', 'no', 'disabled'], true);
    }

    /**
     * Reset the template cache. Used by tests so each test sees a fresh scan.
     */
    public static function resetTemplateCache(): void
    {
        self::$templateCache = null;
    }

    /**
     * Resolve a URL path to a template file in src/templates/pages/.
     *
     * Only files inside src/templates/pages/ auto-route from a URL. Anything
     * in src/templates/ outside pages/ (partials, layouts, base.twig, errors,
     * components) is never served standalone.
     *
     * Dev mode: checks filesystem every time for live changes.
     * Production: uses a cached lookup built once at startup.
     *
     * The whole feature can be turned off with TINA4_TEMPLATE_ROUTING=off.
     */
    public static function resolveTemplate(string $path): ?string
    {
        if (!self::isTemplateAutoRoutingEnabled()) {
            return null;
        }

        $cleanPath = trim($path, '/');
        if ($cleanPath === '') {
            $cleanPath = 'index';
        }

        $isDev = DotEnv::isTruthy(DotEnv::getEnv('TINA4_DEBUG', 'false'));

        if ($isDev) {
            // Skip underscore-prefixed segments even within pages/ — they're
            // private by Hugo/Jekyll convention (helpers, fragments) and
            // shouldn't auto-serve.
            foreach (explode('/', $cleanPath) as $segment) {
                if (str_starts_with($segment, '_')) {
                    return null;
                }
            }
            $pagesDir = (self::$basePath ?: getcwd())
                . DIRECTORY_SEPARATOR . 'src'
                . DIRECTORY_SEPARATOR . 'templates'
                . DIRECTORY_SEPARATOR . self::TEMPLATE_PAGES_DIR;
            foreach (['.twig', '.html'] as $ext) {
                if (is_file($pagesDir . DIRECTORY_SEPARATOR . $cleanPath . $ext)) {
                    return self::TEMPLATE_PAGES_DIR . '/' . $cleanPath . $ext;
                }
            }
            return null;
        }

        // Production: use cached lookup
        if (self::$templateCache === null) {
            self::buildTemplateCache();
        }
        return self::$templateCache[$cleanPath] ?? null;
    }

    /**
     * Build the template cache by scanning src/templates/pages/ once.
     *
     * Only files under pages/ are eligible — partials, layouts, base.twig,
     * errors etc remain renderable via $response->render() but never
     * auto-serve from a URL. Files starting with _ are skipped (private).
     *
     * Maps URL paths to template paths relative to src/templates/
     * (e.g. 'about' => 'pages/about.twig').
     */
    public static function buildTemplateCache(): void
    {
        self::$templateCache = [];
        $pagesDir = (self::$basePath ?: getcwd())
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . 'templates'
            . DIRECTORY_SEPARATOR . self::TEMPLATE_PAGES_DIR;
        if (!is_dir($pagesDir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pagesDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = $file->getExtension();
            if ($ext !== 'twig' && $ext !== 'html') {
                continue;
            }
            $relInsidePages = ltrim(str_replace($pagesDir, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            $relInsidePages = str_replace(DIRECTORY_SEPARATOR, '/', $relInsidePages);

            // Skip private files even within pages/ (e.g. pages/_helper.twig)
            $skip = false;
            foreach (explode('/', $relInsidePages) as $segment) {
                if (str_starts_with($segment, '_')) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            $urlPath = preg_replace('/\.(twig|html)$/', '', $relInsidePages);
            // First match wins (.twig takes priority over .html)
            if (!isset(self::$templateCache[$urlPath])) {
                self::$templateCache[$urlPath] = self::TEMPLATE_PAGES_DIR . '/' . $relInsidePages;
            }
        }
    }

    /**
     * Supported typed-parameter constraints. Keys are the type name written
     * in the route pattern (e.g. ``{id:int}``); values are the regex fragment
     * that the param must match. Mirrored verbatim in tina4-python /
     * tina4-ruby / tina4-nodejs for cross-framework parity.
     *
     * Any type name not in this table raises ``InvalidArgumentException`` at
     * route registration — we never silently fall through to the default
     * matcher, because a typo like ``{id:inetger}`` would otherwise match
     * anything and create a security footgun (see tina4-book#125).
     */
    private const PARAM_TYPE_PATTERNS = [
        'string'  => '[^/]+',                                            // default, any non-slash segment
        'int'     => '\d+',
        'integer' => '\d+',
        'float'   => '[\d.]+',
        'number'  => '[\d.]+',
        'alpha'   => '[A-Za-z]+',                                        // letters only
        'alnum'   => '[A-Za-z0-9]+',                                     // letters + digits
        'slug'    => '[a-z0-9-]+',                                       // URL slug
        'uuid'    => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
        'path'    => '.+',                                               // greedy — matches remaining path
        '.*'      => '.+',
    ];

    private static function compilePath(string $path): array
    {
        $paramNames = [];
        $paramTypes = [];
        $catchAll = false;
        $catchAllName = null;
        $regexParts = [];

        // Segment-by-segment, matching Python's/Ruby's compiler (the fix for
        // tina4-book real-bug audit, 3.13.99): a LITERAL segment is quoted
        // with preg_quote so every character in it -- ( ) . + ? [ { etc. --
        // matches only itself. Before this, the whole $path string was
        // scanned for {param} once and everything else passed through
        // UNESCAPED into the final regex, so a literal regex metacharacter in
        // a path silently changed what the compiled pattern matched: e.g.
        // registering `/blocked-xss(1)` compiled the trailing `(1)` as a
        // capture group, so the exact literal path it was registered for
        // 404'd (`preg_match` required the URL WITHOUT the parens). Only the
        // {name}/{name:type} placeholder syntax below still becomes a regex
        // capture group; every other character, in every other segment, is
        // now matched literally.
        $segments = explode('/', trim($path, '/'));
        foreach ($segments as $segment) {
            // Bare wildcard: matches the remainder of the path. Mirrors
            // Python, which also breaks out on the first bare `*` segment
            // wherever it appears (in practice always the last one).
            if ($segment === '*') {
                $paramNames[] = '*';
                $catchAll = true;
                $catchAllName = '*';
                $regexParts[] = '(?P<__wildcard__>.+)';
                break;
            }

            if (preg_match('#^\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([a-zA-Z.*]+))?\}$#', $segment, $m)) {
                $name = $m[1];
                $type = $m[2] ?? 'string';

                if (!array_key_exists($type, self::PARAM_TYPE_PATTERNS)) {
                    $valid = array_filter(array_keys(self::PARAM_TYPE_PATTERNS), fn($k) => $k !== '.*');
                    sort($valid);
                    throw new \InvalidArgumentException(
                        "Unknown param type '{$type}' in route '{$path}'. " .
                        "Valid types: " . implode(', ', $valid) . "."
                    );
                }

                $paramNames[] = $name;
                $paramTypes[$name] = $type;

                if ($type === 'path' || $type === '.*') {
                    $catchAll = true;
                    $catchAllName = $name;
                }

                $regexParts[] = '(?P<' . $name . '>' . self::PARAM_TYPE_PATTERNS[$type] . ')';
                continue;
            }

            // Literal segment -- quote every regex metacharacter (and the
            // `#` delimiter this class compiles with) so it matches itself
            // only.
            $regexParts[] = preg_quote($segment, '#');
        }

        $regex = '#^/' . implode('/', $regexParts) . '$#';

        return [
            'regex' => $regex,
            'paramNames' => $paramNames,
            'paramTypes' => $paramTypes,
            'catchAll' => $catchAll,
            'catchAllName' => $catchAllName,
        ];
    }

    /**
     * Check if the last route was registered via any().
     */
    private function wasAnyRoute(): bool
    {
        if (self::$lastRouteMethod === null || self::$lastRouteIndex === null) {
            return false;
        }

        $pattern = self::$routes[self::$lastRouteMethod][self::$lastRouteIndex]['pattern'];

        // Check if the same pattern exists for all methods
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            if ($this->findMatchingRoute($method, $pattern) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Find the index of a route by method and pattern.
     */
    private function findMatchingRoute(string $method, string $pattern): ?int
    {
        if (!isset(self::$routes[$method])) {
            return null;
        }

        foreach (self::$routes[$method] as $idx => $route) {
            if ($route['pattern'] === $pattern) {
                return $idx;
            }
        }

        return null;
    }
}
