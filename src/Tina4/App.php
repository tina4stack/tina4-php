<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Main application entry point for Tina4 v3.
 * Ties together configuration, routing, middleware, and lifecycle management.
 */
class App
{
    public const VERSION = '3.0.0';

    /** @var Database\DatabaseAdapter|null Shared database instance */
    private static ?Database\DatabaseAdapter $database = null;

    /** @var float Application start time for uptime tracking */
    private readonly float $startTime;

    /** @var bool Whether the app is running */
    private bool $running = false;

    /** @var array<callable> Registered shutdown callbacks */
    private array $shutdownCallbacks = [];

    /** @var array<string, mixed> Registered routes: method => [pattern => handler] */
    private array $routes = [];

    /** @var array<callable> Middleware stack */
    private array $middleware = [];

    public function __construct(
        private readonly string $basePath = '.',
        private readonly bool $development = false,
    ) {
        $this->startTime = microtime(true);

        // Set base path for static file serving
        Router::$basePath = $this->basePath;

        // Load environment
        $envFile = $this->basePath . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envFile)) {
            DotEnv::loadEnv($envFile);
        }

        // Configure logger
        $isDev = $this->development || DotEnv::getEnv('TINA4_DEBUG', 'false') === 'true';
        Log::configure(
            logDir: $this->basePath . DIRECTORY_SEPARATOR . 'logs',
            development: $isDev,
        );

        // Generate request ID
        Log::setRequestId($this->generateRequestId());

        // Register health check
        $this->registerHealthCheck();

        // Register signal handlers for graceful shutdown
        $this->registerSignalHandlers();
    }

    /**
     * Register a GET route.
     */
    public function get(string $path, callable $handler): self
    {
        $this->routes['GET'][$path] = $handler;
        return $this;
    }

    /**
     * Register a POST route.
     */
    public function post(string $path, callable $handler): self
    {
        $this->routes['POST'][$path] = $handler;
        return $this;
    }

    /**
     * Register a PUT route.
     */
    public function put(string $path, callable $handler): self
    {
        $this->routes['PUT'][$path] = $handler;
        return $this;
    }

    /**
     * Register a DELETE route.
     */
    public function delete(string $path, callable $handler): self
    {
        $this->routes['DELETE'][$path] = $handler;
        return $this;
    }

    /**
     * Register a PATCH route.
     */
    public function patch(string $path, callable $handler): self
    {
        $this->routes['PATCH'][$path] = $handler;
        return $this;
    }

    /**
     * Add middleware to the stack.
     */
    public function addMiddleware(callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Register a callback for graceful shutdown.
     */
    public function onShutdown(callable $callback): self
    {
        $this->shutdownCallbacks[] = $callback;
        return $this;
    }

    /**
     * Get the health check response data.
     *
     * @return array<string, mixed>
     */
    public function getHealthData(): array
    {
        return [
            'status' => 'ok',
            'version' => self::VERSION,
            'uptime' => round(microtime(true) - $this->startTime, 2),
            'framework' => 'tina4-php',
        ];
    }

    /**
     * Get the application start time.
     */
    public function getStartTime(): float
    {
        return $this->startTime;
    }

    /**
     * Check if the app is running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Get all registered routes.
     *
     * @return array<string, array<string, callable>>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Get the middleware stack.
     *
     * @return array<callable>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Perform graceful shutdown.
     */
    public function shutdown(): void
    {
        if (!$this->running) {
            return;
        }

        Log::info('Shutting down Tina4 application');

        foreach ($this->shutdownCallbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                Log::error('Shutdown callback error: ' . $e->getMessage());
            }
        }

        $this->running = false;
        Log::info('Tina4 application stopped');
    }

    /**
     * Start the application (mark as running).
     * Auto-discovers routes from src/routes/ and registers the default landing page
     * if no user-defined "/" route exists.
     */
    public function start(): void
    {
        $this->running = true;

        // Register dev admin dashboard (only in development mode)
        if ($this->isDevelopment()) {
            DevAdmin::register();
        }

        // Auto-discover routes from src/routes/
        $routesDir = $this->basePath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'routes';
        if (is_dir($routesDir)) {
            RouteDiscovery::scan($routesDir);
        }

        // Register Swagger/OpenAPI routes
        Swagger::register();

        // Register default landing page if no "/" route exists
        $this->registerLandingPage();

        Log::info('Tina4 v' . self::VERSION . ' started', [
            'base_path' => $this->basePath,
            'development' => $this->development,
        ]);
    }

    /**
     * Whether the app is running in development mode.
     */
    public function isDevelopment(): bool
    {
        return $this->development || DotEnv::getEnv('TINA4_DEBUG', 'false') === 'true';
    }

    /**
     * Register the /health endpoint.
     */
    private function registerHealthCheck(): void
    {
        $handler = function (Request $request, Response $response) {
            return $response->json($this->getHealthData());
        };

        Router::get('/health', $handler);
        $this->routes['GET']['/health'] = fn() => $this->getHealthData();
    }

    /**
     * Register the default Tina4 landing page.
     * Only shown when no user-defined "/" route exists and no src/templates/index.* template is found.
     */
    private function registerLandingPage(): void
    {
        // Check if user already registered a "/" route
        $routes = Router::list();
        foreach ($routes as $r) {
            if ($r['pattern'] === '/' && $r['method'] === 'GET') {
                return; // User has their own landing page
            }
        }

        // Check if user has a template for the root
        $templateDir = $this->basePath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'templates';
        foreach (['index.html', 'index.twig', 'index.php'] as $tpl) {
            if (is_file($templateDir . DIRECTORY_SEPARATOR . $tpl)) {
                return; // User has a template, framework should serve it
            }
        }

        $isDev = $this->isDevelopment();
        $version = self::VERSION;

        Router::get('/', function (Request $request, Response $response) use ($isDev, $version) {
            return $response->html(self::renderLandingPage($version, $isDev));
        });
    }

    /**
     * Render the built-in Tina4 landing page HTML.
     */
    private static function renderLandingPage(string $version, bool $isDev): string
    {
        $mode = $isDev ? '<span style="color:#4caf50">Development</span>' : '<span style="color:#ff9800">Production</span>';
        $routes = Router::list();
        $routeRows = '';
        foreach ($routes as $r) {
            $flags = [];
            if ($r['cache'] ?? false) {
                $flags[] = '<span style="background:#e3f2fd;color:#1565c0;padding:1px 6px;border-radius:3px;font-size:11px">CACHE</span>';
            }
            if ($r['secure'] ?? false) {
                $flags[] = '<span style="background:#fce4ec;color:#c62828;padding:1px 6px;border-radius:3px;font-size:11px">AUTH</span>';
            }
            $flagStr = implode(' ', $flags);
            $routeRows .= "<tr><td><code>{$r['method']}</code></td><td><a href=\"{$r['pattern']}\">{$r['pattern']}</a></td><td>{$flagStr}</td></tr>";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tina4 PHP v{$version}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; }
        .hero { background: linear-gradient(135deg, #7b1fa2, #9c27b0); color: white; padding: 60px 20px; text-align: center; }
        .hero h1 { font-size: 2.5em; margin-bottom: 10px; }
        .hero p { font-size: 1.2em; opacity: 0.9; }
        .container { max-width: 800px; margin: 0 auto; padding: 30px 20px; }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 24px; margin-bottom: 20px; }
        .card h2 { color: #7b1fa2; margin-bottom: 12px; font-size: 1.3em; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px 12px; border-bottom: 1px solid #eee; }
        th { color: #666; font-size: 0.85em; text-transform: uppercase; }
        code { background: #f5f0ff; padding: 2px 8px; border-radius: 4px; font-size: 0.9em; color: #7b1fa2; }
        a { color: #7b1fa2; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 0.8em; font-weight: 600; }
        .get-started { background: #f3e5f5; border-left: 4px solid #7b1fa2; padding: 16px; border-radius: 0 8px 8px 0; }
        .get-started code { display: block; margin-top: 8px; background: #333; color: #4caf50; padding: 8px 12px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="hero">
        <h1>Tina4 PHP</h1>
        <p>This is not a 4ramework &mdash; v{$version} &mdash; {$mode}</p>
    </div>
    <div class="container">
        <div class="card">
            <h2>Registered Routes</h2>
            <table>
                <thead><tr><th>Method</th><th>Path</th><th>Flags</th></tr></thead>
                <tbody>{$routeRows}</tbody>
            </table>
        </div>
        <div class="card get-started">
            <h2>Get Started</h2>
            <p>Create your first route file:</p>
            <code>src/routes/hello.php</code>
            <p style="margin-top: 12px">Or register routes in <code>index.php</code>:</p>
            <code>Router::get('/hello', fn(Request \$req, Response \$res) => \$res->json(['hello' => 'world']));</code>
        </div>
        <div class="card">
            <h2>Quick Links</h2>
            <p><a href="/health">/health</a> &mdash; Health check endpoint</p>
            <p style="margin-top: 8px"><a href="https://tina4.com" target="_blank">tina4.com</a> &mdash; Full documentation</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Register POSIX signal handlers for graceful shutdown.
     */
    private function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        if (defined('SIGTERM')) {
            pcntl_signal(SIGTERM, function () {
                Log::info('Received SIGTERM');
                $this->shutdown();
            });
        }

        if (defined('SIGINT')) {
            pcntl_signal(SIGINT, function () {
                Log::info('Received SIGINT');
                $this->shutdown();
            });
        }
    }

    /**
     * Set the shared database instance.
     */
    public static function setDatabase(Database\DatabaseAdapter $db): void
    {
        self::$database = $db;
    }

    /**
     * Get the shared database instance.
     */
    public static function getDatabase(): ?Database\DatabaseAdapter
    {
        return self::$database;
    }

    /**
     * Generate a short unique request ID.
     */
    private function generateRequestId(): string
    {
        return substr(bin2hex(random_bytes(8)), 0, 16);
    }
}
