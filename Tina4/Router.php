<?php

/**
 * Tina4 - This is not a 4ramework.
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

    /**
     * Register a GET route.
     */
    public static function get(string $path, callable $callback): self
    {
        return self::addRoute('GET', $path, $callback);
    }

    /**
     * Register a POST route.
     */
    public static function post(string $path, callable $callback): self
    {
        return self::addRoute('POST', $path, $callback);
    }

    /**
     * Register a PUT route.
     */
    public static function put(string $path, callable $callback): self
    {
        return self::addRoute('PUT', $path, $callback);
    }

    /**
     * Register a PATCH route.
     */
    public static function patch(string $path, callable $callback): self
    {
        return self::addRoute('PATCH', $path, $callback);
    }

    /**
     * Register a DELETE route.
     */
    public static function delete(string $path, callable $callback): self
    {
        return self::addRoute('DELETE', $path, $callback);
    }

    /**
     * Register a route for any HTTP method.
     */
    public static function any(string $path, callable $callback): self
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            self::addRoute($method, $path, $callback);
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

                // Extract named parameters
                foreach ($route['paramNames'] as $name) {
                    if (isset($matches[$name])) {
                        $params[$name] = $matches[$name];
                    }
                }

                // Extract catch-all parameter
                if ($route['catchAll'] && $route['catchAllName'] !== null && isset($matches[$route['catchAllName']])) {
                    $params[$route['catchAllName']] = $matches[$route['catchAllName']];
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
        $result = self::match($request->method, $request->path);

        if ($result === null) {
            // Try serving a static file before returning 404
            $staticResponse = StaticFiles::tryServe($request->path, self::$basePath);
            if ($staticResponse !== null) {
                return $staticResponse;
            }

            return self::renderError($response, 404, 'Not Found', $request->path);
        }

        $route = $result['route'];
        $request->params = $result['params'];

        // Check secure route
        if ($route['secure']) {
            $token = $request->bearerToken();
            if ($token === null) {
                return self::renderError($response, 401, 'Unauthorized', $request->path);
            }
            // JWT validation would go here — for now, presence of token is enough
        }

        // Run middleware chain
        foreach ($route['middleware'] as $mw) {
            $mwResult = $mw($request, $response);
            // If middleware returns a Response, short-circuit
            if ($mwResult instanceof Response) {
                return $mwResult;
            }
            // If middleware returns false, stop processing
            if ($mwResult === false) {
                return self::renderError($response, 403, 'Forbidden', $request->path);
            }
        }

        // Invoke the handler
        try {
            $handlerResult = ($route['callback'])($request, $response);
        } catch (\Throwable $e) {
            if (ErrorOverlay::isDebugMode()) {
                // Rich error overlay with stack trace, source context, and line numbers
                $overlayHtml = ErrorOverlay::render($e, [
                    'REQUEST_METHOD' => $request->method,
                    'REQUEST_URI' => $request->path,
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
    public static function list(): array
    {
        $list = [];

        foreach (self::$routes as $method => $routes) {
            foreach ($routes as $route) {
                // Derive handler name from the callback
                $handler = '';
                if (isset($route['handler'])) {
                    $cb = $route['handler'];
                    if ($cb instanceof \Closure) {
                        $ref = new \ReflectionFunction($cb);
                        $file = $ref->getFileName();
                        $handler = $file ? basename($file) . ':' . $ref->getStartLine() : 'Closure';
                    } elseif (is_string($cb)) {
                        $handler = $cb;
                    } elseif (is_array($cb) && count($cb) === 2) {
                        $handler = (is_object($cb[0]) ? get_class($cb[0]) : $cb[0]) . '::' . $cb[1];
                    }
                }
                $list[] = [
                    'method' => $method,
                    'pattern' => $route['pattern'],
                    'middleware' => count($route['middleware']),
                    'cache' => $route['cache'],
                    'secure' => $route['secure'],
                    'handler' => $handler,
                ];
            }
        }

        // Include WebSocket routes
        foreach (self::$wsRoutes as $wsRoute) {
            $list[] = [
                'method' => 'WS',
                'pattern' => $wsRoute['path'],
                'middleware' => 0,
                'cache' => false,
                'secure' => false,
                'handler' => '',
            ];
        }

        return $list;
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
                $frond = new Frond($userTemplateDir);
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
                $frond = new Frond($frameworkTemplateDir);
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
    public static function reset(): void
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
    private static function addRoute(string $method, string $path, callable $callback): self
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

        self::$routes[$method][] = [
            'pattern' => $fullPath,
            'regex' => $parsed['regex'],
            'paramNames' => $parsed['paramNames'],
            'callback' => $callback,
            'middleware' => self::$groupMiddleware,
            'cache' => false,
            'secure' => false,
            'catchAll' => $parsed['catchAll'],
            'catchAllName' => $parsed['catchAllName'],
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
    private static function compilePath(string $path): array
    {
        $paramNames = [];
        $catchAll = false;
        $catchAllName = null;

        // Replace catch-all params: {name:.*}
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*):(\.\*)\}#', function ($m) use (&$paramNames, &$catchAll, &$catchAllName) {
            $catchAll = true;
            $catchAllName = $m[1];
            $paramNames[] = $m[1];
            return '(?P<' . $m[1] . '>.+)';
        }, $path);

        // Replace standard params: {name}
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $regex);

        // Escape forward slashes and anchor
        $regex = '#^' . $regex . '$#';

        return [
            'regex' => $regex,
            'paramNames' => $paramNames,
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
