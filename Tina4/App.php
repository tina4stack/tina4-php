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
        $port = $_SERVER['SERVER_PORT'] ?? getenv('PORT') ?: '7146';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tina4</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh;position:relative}
.bg-watermark{position:fixed;bottom:-5%;right:-5%;width:45%;opacity:0.04;pointer-events:none;z-index:0}
.content{text-align:center;z-index:1;padding:2rem;max-width:960px;margin:0 auto}
.logo{width:120px;height:120px;margin-bottom:1.5rem}
h1{font-size:3rem;font-weight:700;margin-bottom:0.25rem;letter-spacing:-1px}
.tagline{color:#64748b;font-size:1.1rem;margin-bottom:2rem}
.actions{display:flex;gap:0.75rem;justify-content:center;flex-wrap:wrap;margin-bottom:2.5rem}
.btn{padding:0.6rem 1.5rem;border-radius:0.5rem;font-size:0.9rem;font-weight:600;cursor:pointer;text-decoration:none;transition:all 0.15s;border:1px solid #334155;color:#94a3b8;background:transparent}
.btn:hover{border-color:#64748b;color:#e2e8f0}
.btn-primary{background:#7b1fa2;color:#fff;border-color:#7b1fa2}
.btn-primary:hover{opacity:0.9;transform:translateY(-1px)}
.status{display:flex;gap:2rem;justify-content:center;align-items:center;color:#64748b;font-size:0.85rem;margin-bottom:1.5rem}
.status .dot{width:8px;height:8px;border-radius:50%;background:#22c55e;display:inline-block;margin-right:0.4rem}
.footer{color:#334155;font-size:0.8rem;letter-spacing:0.5px;margin-top:2rem;padding-bottom:2rem}
.card{background:#1e293b;border-radius:0.75rem;padding:2rem;margin-top:2.5rem;text-align:left}
.card h2{font-size:1.4rem;font-weight:600;margin-bottom:1rem}
.code-block{background:#0f172a;border-radius:0.5rem;padding:1.25rem;overflow-x:auto;font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace;font-size:0.85rem;line-height:1.6;color:#4ade80}
.gallery{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:1rem;margin-top:2.5rem}
.gallery-title{font-size:1.4rem;font-weight:600;margin-top:2.5rem;margin-bottom:0}
.gallery-card{background:#1e293b;border:1px solid #334155;border-radius:0.75rem;padding:1.5rem;text-align:left;position:relative;overflow:hidden}
.gallery-card .accent{position:absolute;top:0;left:0;right:0;height:3px}
.accent-purple{background:#7b1fa2}
.accent-green{background:#22c55e}
.accent-blue{background:#3b82f6}
.gallery-card .icon{font-size:1.5rem;margin-bottom:0.75rem}
.gallery-card h3{font-size:1.05rem;font-weight:600;margin-bottom:0.5rem}
.gallery-card p{color:#94a3b8;font-size:0.9rem;line-height:1.5}
</style>
</head>
<body>
<img src="/images/logo.png" class="bg-watermark" alt="">
<div class="content">
    <img src="/images/logo.png" class="logo" alt="Tina4">
    <h1>Tina4</h1>
    <p class="tagline">This is not a framework</p>
    <div class="actions">
        <a href="/__dev/" class="btn btn-primary">Dev Admin</a>
        <a href="#gallery" class="btn">Gallery</a>
    </div>
    <div class="status">
        <span><span class="dot"></span>Server running</span>
        <span>Port {$port}</span>
        <span>v{$version}</span>
    </div>

    <div class="card">
        <h2>Getting Started</h2>
        <div class="code-block"><pre>&lt;?php
// index.php
require_once 'vendor/autoload.php';

\$app = new \Tina4\App();

\Tina4\Router::get('/hello', function (\$request, \$response) {
    return \$response-&gt;json(['message' =&gt; 'Hello World!']);
});

\$app-&gt;run();  // starts on port 7146</pre></div>
    </div>

    <h2 id="gallery" class="gallery-title">What You Can Build</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem;">
        <div class="gallery-card">
            <div class="accent accent-purple"></div>
            <div class="icon">&#128640;</div>
            <h3>REST API</h3>
            <p>Define routes with one decorator</p>
            <pre style="background:#0f172a;color:#4ade80;padding:0.75rem;border-radius:0.375rem;font-size:0.75rem;overflow-x:auto;margin-top:0.5rem;font-family:'SFMono-Regular',Consolas,monospace;">Router::get('/api/users', function(\$req, \$res) {
    return \$res-&gt;json(['users' =&gt; []]);
});</pre>
        </div>
        <div class="gallery-card">
            <div class="accent accent-green"></div>
            <div class="icon">&#128451;</div>
            <h3>ORM</h3>
            <p>Active record models, zero config</p>
            <pre style="background:#0f172a;color:#4ade80;padding:0.75rem;border-radius:0.375rem;font-size:0.75rem;overflow-x:auto;margin-top:0.5rem;font-family:'SFMono-Regular',Consolas,monospace;">class User extends ORM {
    public \$tableName = "users";
    public \$primaryKey = "id";
}</pre>
        </div>
        <div class="gallery-card">
            <div class="accent accent-blue"></div>
            <div class="icon">&#128274;</div>
            <h3>Auth</h3>
            <p>JWT tokens built-in</p>
            <pre style="background:#0f172a;color:#4ade80;padding:0.75rem;border-radius:0.375rem;font-size:0.75rem;overflow-x:auto;margin-top:0.5rem;font-family:'SFMono-Regular',Consolas,monospace;">\$token = Auth::getToken(["user_id" =&gt; 1]);
\$valid = Auth::validToken(\$token);</pre>
        </div>
        <div class="gallery-card">
            <div class="accent accent-purple"></div>
            <div class="icon">&#9889;</div>
            <h3>Queue</h3>
            <p>Background jobs, no Redis needed</p>
            <pre style="background:#0f172a;color:#4ade80;padding:0.75rem;border-radius:0.375rem;font-size:0.75rem;overflow-x:auto;margin-top:0.5rem;font-family:'SFMono-Regular',Consolas,monospace;">\$producer = new Producer(new Queue("emails"));
\$producer-&gt;produce(["to" =&gt; "a@b.com"]);</pre>
        </div>
        <div class="gallery-card">
            <div class="accent accent-green"></div>
            <div class="icon">&#128196;</div>
            <h3>Templates</h3>
            <p>Twig templates with auto-reload</p>
            <pre style="background:#0f172a;color:#4ade80;padding:0.75rem;border-radius:0.375rem;font-size:0.75rem;overflow-x:auto;margin-top:0.5rem;font-family:'SFMono-Regular',Consolas,monospace;">Router::get('/dashboard', function(\$req, \$res) {
    return \$res-&gt;render("dashboard.twig", \$data);
});</pre>
        </div>
        <div class="gallery-card">
            <div class="accent accent-blue"></div>
            <div class="icon">&#128225;</div>
            <h3>Database</h3>
            <p>Multi-engine, one API</p>
            <pre style="background:#0f172a;color:#4ade80;padding:0.75rem;border-radius:0.375rem;font-size:0.75rem;overflow-x:auto;margin-top:0.5rem;font-family:'SFMono-Regular',Consolas,monospace;">\$db = new Database("sqlite:///app.db");
\$result = \$db-&gt;fetch("SELECT * FROM users");</pre>
        </div>
    </div>

    <p class="footer">Zero dependencies &middot; Convention over configuration</p>
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
     * If none is set and DATABASE_URL is configured, auto-creates one via DatabaseFactory.
     */
    public static function getDatabase(): ?Database\DatabaseAdapter
    {
        if (self::$database === null) {
            $db = Database\DatabaseFactory::fromEnv('DATABASE_URL');
            if ($db !== null) {
                self::$database = $db;
            }
        }

        return self::$database;
    }

    /**
     * Create and set a database adapter from a connection URL.
     *
     * @param string $url Connection URL (e.g. "pgsql://user:pass@host/db", "sqlite::memory:")
     * @param bool|null $autoCommit Override auto-commit setting
     * @return Database\DatabaseAdapter The created adapter
     */
    public static function createDatabase(string $url, ?bool $autoCommit = null): Database\DatabaseAdapter
    {
        $db = Database\DatabaseFactory::create($url, $autoCommit);
        self::$database = $db;
        return $db;
    }

    /**
     * Generate a short unique request ID.
     */
    private function generateRequestId(): string
    {
        return substr(bin2hex(random_bytes(8)), 0, 16);
    }
}
