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

    /** @var array<int, array{path: string, handler: callable}> Registered WebSocket routes */
    private static array $wsRoutes = [];

    /** @var string Current group prefix */
    private static string $groupPrefix = '';

    /** @var array<callable> Current group middleware */
    private static array $groupMiddleware = [];

    /** @var string Base path for static file serving */
    public static string $basePath = '.';

    /** @var int Index of the last registered route (for chaining) */
    private static ?int $lastRouteIndex = null;

    /** @var string Method of the last registered route */
    private static ?string $lastRouteMethod = null;

    /** @var array<string, string>|null Cached template lookup: url_path => template_file */
    private static ?array $templateCache = null;

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

            // Custom middleware means developer handles auth — disable built-in
            // gate unless ->secure() was explicitly called.
            if (empty($route['secure'])) {
                $route['noAuth'] = true;
            }

            // If this was registered via any(), apply to all methods
            if ($this->wasAnyRoute()) {
                foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                    $idx = $this->findMatchingRoute($method, $route['pattern']);
                    if ($idx !== null) {
                        self::$routes[$method][$idx]['middleware'] = $route['middleware'];
                        if (empty($route['secure'])) {
                            self::$routes[$method][$idx]['noAuth'] = true;
                        }
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
     * @param array $meta Swagger metadata (summary, tags, description, example, etc.)
     * @return $this
     */
    public function swagger(array $meta): self
    {
        if (self::$lastRouteMethod !== null && self::$lastRouteIndex !== null) {
            self::$routes[self::$lastRouteMethod][self::$lastRouteIndex]['swagger'] = $meta;
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

        if (!isset(self::$routes[$method])) {
            return null;
        }

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
     * Dispatch a request — match, run middleware, invoke handler.
     */
    public static function dispatch(Request $request, Response $response): Response
    {
        // Auto-start session — read session ID from cookie, lazy-create on first use
        $sessionCookie = $_COOKIE['tina4_session'] ?? null;
        $session = new Session();
        $session->start($sessionCookie);
        $request->session = $session;

        $result = self::dispatchInner($request, $response);

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
            $ttl = (int)(getenv('TINA4_SESSION_TTL') ?: 3600);
            $sameSite = getenv('TINA4_SESSION_SAMESITE') ?: 'Lax';
            // SameSite=None requires the Secure flag — browsers reject it otherwise
            $secure = strcasecmp($sameSite, 'None') === 0 || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

            if (headers_sent()) {
                // Built-in server mode: headers are managed via the Response object,
                // so setcookie() would trigger a fatal error. Build the Set-Cookie
                // header manually and attach it to the Response instead.
                $expires = gmdate('D, d M Y H:i:s T', time() + $ttl);
                $cookie = "tina4_session={$sid}; Expires={$expires}; Path=/; HttpOnly; SameSite={$sameSite}";
                if ($secure) {
                    $cookie .= '; Secure';
                }
                $result->header('Set-Cookie', $cookie);
            } else {
                // Apache/nginx/FPM mode: use PHP's native setcookie()
                setcookie('tina4_session', $sid, [
                    'expires' => time() + $ttl,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => $sameSite,
                    'secure' => $secure,
                ]);
            }
        }

        return $result;
    }

    /**
     * Inner dispatch — handles route matching, middleware, and handler invocation.
     */
    private static function dispatchInner(Request $request, Response $response): Response
    {
        // Run global middleware "before" hooks.
        // CORS middleware runs first (separate pass) so CORS headers are always present —
        // even on short-circuited 4xx responses. This is required by the CORS spec: browsers
        // must see CORS headers on 401/403 responses or they report a CORS error instead.
        $globalMiddleware = Middleware::getGlobal();
        if (!empty($globalMiddleware)) {
            $corsMiddleware = array_filter($globalMiddleware, fn($c) => is_a($c, \Tina4\Middleware\CorsMiddleware::class, true));
            $otherMiddleware = array_filter($globalMiddleware, fn($c) => !is_a($c, \Tina4\Middleware\CorsMiddleware::class, true));

            if (!empty($corsMiddleware)) {
                [$request, $response] = Middleware::runBefore(array_values($corsMiddleware), $request, $response);
            }

            if (!empty($otherMiddleware)) {
                [$request, $response] = Middleware::runBefore(array_values($otherMiddleware), $request, $response);
            }

            // Short-circuit if a global middleware set a non-default status.
            // Covers both error responses (4xx/5xx) and CORS preflight (204).
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400 || $statusCode === 204) {
                return $response;
            }
        }

        $result = self::match($request->method, $request->path);

        if ($result === null) {
            // Try serving a static file before returning 404
            $staticResponse = StaticFiles::tryServe($request->path, self::$basePath);
            if ($staticResponse !== null) {
                return $staticResponse;
            }

            // Try serving a template file (e.g. /hello -> src/templates/hello.twig or hello.html)
            if ($request->method === 'GET') {
                $tplFile = self::resolveTemplate($request->path);
                if ($tplFile !== null) {
                    return $response->render($tplFile, []);
                }
            }

            return self::renderError($response, 404, 'Not Found', $request->path);
        }

        $route = $result['route'];
        $request->params = $result['params'];

        // ── Auth enforcement ──────────────────────────────────────
        // Dev admin routes (/__dev/) are always public — no auth required.
        // Write routes (POST/PUT/PATCH/DELETE) are secure by default.
        // Use ->noAuth() or @noauth to opt out.
        // GET/HEAD/OPTIONS are open by default; use ->secure() or @secured to require auth.
        $isDevAdmin = str_starts_with($request->url, '/__dev') || str_starts_with($request->url, '/api/gallery/') || str_starts_with($request->url, '/gallery/');
        $isWriteMethod = in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $requiresAuth = false;

        if ($isDevAdmin) {
            // Dev admin routes never require auth
            $requiresAuth = false;
        } elseif (!empty($route['middleware'])) {
            // Route has custom middleware — developer handles auth themselves.
            // Only enforce built-in auth if explicitly marked ->secure().
            $requiresAuth = !empty($route['secure']);
        } elseif ($isWriteMethod) {
            // Write routes with no custom middleware require auth unless ->noAuth()
            $requiresAuth = empty($route['noAuth']);
        } else {
            // Read routes require auth only when explicitly marked secure
            $requiresAuth = !empty($route['secure']);
        }

        if ($requiresAuth) {
            $secret = getenv('SECRET') ?: '';
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

            if ($token === null) {
                return $response->json(['error' => 'Unauthorized'], 401);
            }

            if (!Auth::validToken($token, $secret)) {
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

        // Run middleware chain
        foreach ($route['middleware'] as $mw) {
            // Resolve middleware: string class name → call before() methods (Python parity)
            if (is_string($mw)) {
                // Try as class name (exact, PascalCase, or ucfirst)
                $className = null;
                foreach ([$mw, ucfirst($mw)] as $candidate) {
                    if (class_exists($candidate)) {
                        $className = $candidate;
                        break;
                    }
                }

                if ($className !== null) {
                    // Call all before_* / before static methods (matches Python's middleware pattern)
                    $methods = get_class_methods($className);
                    foreach ($methods as $method) {
                        if (str_starts_with($method, 'before')) {
                            $mwResult = $className::$method($request, $response);
                            if ($mwResult instanceof Response) {
                                return $mwResult;
                            }
                            if ($mwResult === false) {
                                return self::renderError($response, 403, 'Forbidden', $request->path);
                            }
                            // If middleware returns [request, response], update them
                            if (is_array($mwResult) && count($mwResult) === 2) {
                                [$request, $response] = $mwResult;
                            }
                        }
                    }
                    continue;
                }

                // Fallback: try as callable function name
                if (is_callable($mw)) {
                    $mwResult = $mw($request, $response);
                } else {
                    Log::warning("Middleware not found: {$mw}");
                    continue;
                }
            } elseif (is_callable($mw)) {
                $mwResult = $mw($request, $response);
            } else {
                continue;
            }

            // If middleware returns a Response, short-circuit
            if ($mwResult instanceof Response) {
                return $mwResult;
            }
            // If middleware returns false, stop processing
            if ($mwResult === false) {
                return self::renderError($response, 403, 'Forbidden', $request->path);
            }
        }

        // Invoke the handler — inject path params by name, then request/response
        try {
            $ref = new \ReflectionFunction($route['callback']);
            $refParams = $ref->getParameters();
            $routeParams = $request->params;
            $args = [];

            foreach ($refParams as $p) {
                $name = $p->getName();
                if (array_key_exists($name, $routeParams)) {
                    // Path parameter — inject by name
                    $args[] = $routeParams[$name];
                } else {
                    // Not a path param — inject request or response based on type hint
                    $type = $p->getType();
                    $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';
                    if ($typeName === Request::class || $typeName === 'Tina4\\Request' || $name === 'request') {
                        $args[] = $request;
                    } else {
                        $args[] = $response;
                    }
                }
            }

            $handlerResult = count($args) === 0
                ? ($route['callback'])()
                : ($route['callback'])(...$args);
        } catch (\Throwable $e) {
            if (ErrorOverlay::isDebugMode()) {
                // Rich error overlay with stack trace, source context, and line numbers
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
            }
            $errorMessage = $e->getMessage() . "\n" . $e->getTraceAsString();
            return self::renderError($response, 500, 'Server Error', $request->path, [
                'error_message' => $errorMessage,
                'request_id' => substr(md5(uniqid('', true)), 0, 12),
            ]);
        }

        if ($handlerResult instanceof Response) {
            $finalResponse = $handlerResult;
        } elseif (is_array($handlerResult)) {
            $finalResponse = $response->json($handlerResult);
        } elseif (is_string($handlerResult)) {
            $finalResponse = $response->html($handlerResult);
        } else {
            $finalResponse = $response;
        }

        // Run global middleware "after" hooks
        if (!empty($globalMiddleware)) {
            [$request, $finalResponse] = Middleware::runAfter($globalMiddleware, $request, $finalResponse);
        }

        // Dev toolbar injection: inject a fixed bottom toolbar into HTML responses
        $isDev = DotEnv::isTruthy(DotEnv::getEnv('TINA4_DEBUG', 'false'));
        if ($isDev && !str_starts_with($request->path, '/__dev') && str_contains($finalResponse->getContentType() ?? '', 'text/html')) {
            $matchedPattern = $result !== null ? ($result['route']['pattern'] ?? '') : 'none';
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
                'secure' => false,
                'auth_required' => false,
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

    /**
     * Render an error page via Frond templates with fallback to JSON.
     *
     * Resolution order:
     *   1. User override:    src/templates/errors/{code}.twig
     *   2. Framework default: __DIR__/templates/errors/{code}.twig
     *   3. JSON fallback
     *
     * @param Response $response The response object
     * @param int $code HTTP status code
     * @param string $message Error message
     * @param string $path Request path (for display in template)
     * @param array $extraData Additional template variables
     * @return Response
     */
    private static function renderError(Response $response, int $code, string $message, string $path = '', array $extraData = []): Response
    {
        $templateFile = "errors/{$code}.twig";
        $data = array_merge(['path' => $path, 'error_message' => $message], $extraData);

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

        // 3. JSON fallback
        return $response->json(['error' => $message], $code);
    }

    /**
     * Register a WebSocket route.
     *
     * The handler receives ($connection, $message, $event) where:
     *   - $connection is a WebSocketConnection object with send(), broadcast(), close()
     *   - $message is the message payload (null for open/close events)
     *   - $event is 'open', 'message', or 'close'
     *
     * @param string   $path    WebSocket endpoint path (e.g. '/ws/chat')
     * @param callable $handler Handler function
     */
    public static function websocket(string $path, callable $handler): void
    {
        $fullPath = self::$groupPrefix . '/' . ltrim($path, '/');
        $fullPath = '/' . trim($fullPath, '/');
        $fullPath = preg_replace('#/+#', '/', $fullPath);

        self::$wsRoutes[] = [
            'path' => $fullPath,
            'handler' => $handler,
        ];
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

        self::$routes[$method][] = [
            'pattern' => $fullPath,
            'regex' => $parsed['regex'],
            'paramNames' => $parsed['paramNames'],
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

        self::$lastRouteMethod = $method;
        self::$lastRouteIndex = count(self::$routes[$method]) - 1;

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
     * Resolve a URL path to a template file in src/templates/.
     * In production: uses a cached lookup built once at startup.
     * In development: checks the filesystem every time for live changes.
     */
    private static function resolveTemplate(string $path): ?string
    {
        $cleanPath = ltrim($path, '/');
        if ($cleanPath === '') {
            $cleanPath = 'index';
        }

        $isDev = DotEnv::isTruthy(DotEnv::getEnv('TINA4_DEBUG', 'false'));

        if ($isDev) {
            // Dev mode: check filesystem directly so new files are picked up instantly
            $templateDir = (self::$basePath ?: getcwd()) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'templates';
            foreach (['.twig', '.html'] as $ext) {
                $tplFile = $cleanPath . $ext;
                if (is_file($templateDir . DIRECTORY_SEPARATOR . $tplFile)) {
                    return $tplFile;
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
     * Build the template cache by scanning src/templates/ once.
     * Maps URL paths to template filenames (e.g. 'hello' => 'hello.twig').
     */
    private static function buildTemplateCache(): void
    {
        self::$templateCache = [];
        $templateDir = (self::$basePath ?: getcwd()) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'templates';
        if (!is_dir($templateDir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templateDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = $file->getExtension();
            if ($ext !== 'twig' && $ext !== 'html') {
                continue;
            }
            $relativePath = ltrim(str_replace($templateDir, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            $urlPath = preg_replace('/\.(twig|html)$/', '', $relativePath);
            // First match wins (.twig takes priority over .html)
            if (!isset(self::$templateCache[$urlPath])) {
                self::$templateCache[$urlPath] = $relativePath;
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
        $catchAll = false;
        $catchAllName = null;

        // Support bare * wildcard: /docs/* becomes a catch-all with key "*"
        if (preg_match('#/\*$#', $path)) {
            $path = preg_replace('#/\*$#', '/(?P<__wildcard__>.+)', $path);
            $paramNames[] = '*';
            $catchAll = true;
            $catchAllName = '*';
        }

        // Replace typed and untyped params: {name}, {name:int}, {name:float}, {name:path}, {name:.*}
        $paramTypes = [];
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([a-zA-Z.*]+))?\}#', function ($m) use (&$paramNames, &$paramTypes, &$catchAll, &$catchAllName, $path) {
            $name = $m[1];
            $type = $m[2] ?? 'string';
            $paramNames[] = $name;
            $paramTypes[$name] = $type;

            if (!array_key_exists($type, self::PARAM_TYPE_PATTERNS)) {
                $valid = array_filter(array_keys(self::PARAM_TYPE_PATTERNS), fn($k) => $k !== '.*');
                sort($valid);
                throw new \InvalidArgumentException(
                    "Unknown param type '{$type}' in route '{$path}'. " .
                    "Valid types: " . implode(', ', $valid) . "."
                );
            }

            if ($type === 'path' || $type === '.*') {
                $catchAll = true;
                $catchAllName = $name;
            }

            return '(?P<' . $name . '>' . self::PARAM_TYPE_PATTERNS[$type] . ')';
        }, $path);

        // Escape forward slashes and anchor
        $regex = '#^' . $regex . '$#';

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
