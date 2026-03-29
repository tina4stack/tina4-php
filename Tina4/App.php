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
    public const VERSION = '3.10.24';

    /** @var Database\Database|Database\DatabaseAdapter|null Shared database instance */
    private static Database\Database|Database\DatabaseAdapter|null $database = null;

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

        // Strict mode: convert all PHP warnings/notices to exceptions
        // so errors like "Undefined array key" are caught immediately
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false; // Respect @ suppression operator
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        // Set base path for static file serving
        Router::$basePath = $this->basePath;

        // Load environment
        $envFile = $this->basePath . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envFile)) {
            DotEnv::loadEnv($envFile);
        }

        // Configure logger
        $isDev = $this->development || DotEnv::isTruthy(DotEnv::getEnv('TINA4_DEBUG', 'false'));
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
        return $this->development || DotEnv::isTruthy(DotEnv::getEnv('TINA4_DEBUG', 'false'));
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

        // Check if user has a template for the root — register a route to serve it
        $templateDir = $this->basePath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'templates';
        foreach (['index.html', 'index.twig', 'index.php'] as $tpl) {
            $tplPath = $templateDir . DIRECTORY_SEPARATOR . $tpl;
            if (is_file($tplPath)) {
                $file = $tpl;
                Router::get('/', function (Request $request, Response $response) use ($file) {
                    return $response->render($file, []);
                });
                return;
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
    /**
     * Check if a gallery item's files exist in the project's src/ folder.
     */
    private static function isGalleryDeployed(string $name): bool
    {
        $galleryDir = __DIR__ . '/gallery/' . $name;
        $metaFile = $galleryDir . '/meta.json';
        if (!file_exists($metaFile)) {
            return false;
        }
        $srcDir = $galleryDir . '/src';
        if (!is_dir($srcDir)) {
            return false;
        }
        $projectSrc = getcwd() . '/src';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $srcDirNorm = str_replace('\\', '/', $srcDir);
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $pathname = str_replace('\\', '/', $file->getPathname());
                $rel = str_replace($srcDirNorm . '/', '', $pathname);
                if (!file_exists($projectSrc . '/' . $rel)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Render a Try It or View button depending on deployment state.
     */
    private static function galleryBtn(string $name, string $tryUrl): string
    {
        if (self::isGalleryDeployed($name)) {
            return '<button class="try-btn" style="background:#22c55e;" onclick="window.location.href=\'' . $tryUrl . '\'" data-deployed="1">View &#8599;</button>';
        }
        return '<button class="try-btn" onclick="deployGallery(\'' . $name . '\',\'' . $tryUrl . '\')">Try It</button>';
    }

    private static function renderLandingPage(string $version, bool $isDev): string
    {
        $port = $_SERVER['SERVER_PORT'] ?? getenv('PORT') ?: '7146';

        $btnRestApi = self::galleryBtn('rest-api', '/api/gallery/hello');
        $btnOrm = self::galleryBtn('orm', '/api/gallery/products');
        $btnAuth = self::galleryBtn('auth', '/gallery/auth');
        $btnQueue = self::galleryBtn('queue', '/api/gallery/queue/status');
        $btnTemplates = self::galleryBtn('templates', '/gallery/page');
        $btnDatabase = self::galleryBtn('database', '/api/gallery/db/tables');
        $btnErrorOverlay = self::galleryBtn('error-overlay', '/api/gallery/crash');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tina4Php</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh;display:flex;flex-direction:column;align-items:center;position:relative}
.bg-watermark{position:fixed;bottom:-5%;right:-5%;width:45%;opacity:0.04;pointer-events:none;z-index:0}
.hero{text-align:center;z-index:1;padding:3rem 2rem 2rem}
.logo{width:120px;height:120px;margin-bottom:1.5rem}
h1{font-size:3rem;font-weight:700;margin-bottom:0.25rem;letter-spacing:-1px}
.tagline{color:#64748b;font-size:1.1rem;margin-bottom:2rem}
.actions{display:flex;gap:0.75rem;justify-content:center;flex-wrap:wrap;margin-bottom:2.5rem}
.btn{padding:0.6rem 1.5rem;border-radius:0.5rem;font-size:0.9rem;font-weight:600;cursor:pointer;text-decoration:none;transition:all 0.15s;border:1px solid #334155;color:#94a3b8;background:transparent;min-width:140px;text-align:center;display:inline-block}
.btn:hover{border-color:#64748b;color:#e2e8f0}
.status{display:flex;gap:2rem;justify-content:center;align-items:center;color:#64748b;font-size:0.85rem;margin-bottom:1.5rem}
.status .dot{width:8px;height:8px;border-radius:50%;background:#22c55e;display:inline-block;margin-right:0.4rem}
.footer{color:#334155;font-size:0.8rem;letter-spacing:0.5px}
.section{z-index:1;width:100%;max-width:800px;padding:0 2rem;margin-bottom:2.5rem}
.card{background:#1e293b;border-radius:0.75rem;padding:2rem;border:1px solid #334155}
.card h2{font-size:1.4rem;font-weight:600;margin-bottom:1.25rem;color:#e2e8f0}
.code-block{background:#0f172a;border-radius:0.5rem;padding:1.25rem;overflow-x:auto;font-family:'SF Mono',SFMono-Regular,Consolas,'Liberation Mono',Menlo,monospace;font-size:0.85rem;line-height:1.6;color:#4ade80;border:1px solid #1e293b}
.gallery{z-index:1;width:100%;max-width:800px;padding:0 2rem;margin-bottom:3rem}
.gallery h2{font-size:1.4rem;font-weight:600;margin-bottom:1.25rem;color:#e2e8f0;text-align:center}
.gallery-grid{display:flex;gap:1rem;flex-wrap:wrap}
.gallery-card{flex:1 1 220px;background:#1e293b;border:1px solid #334155;border-radius:0.75rem;padding:1.5rem;position:relative;overflow:hidden}
.gallery-card .accent{position:absolute;top:0;left:0;right:0;height:3px}
.gallery-card .accent-purple{background:#7b1fa2}
.gallery-card .accent-green{background:#22c55e}
.gallery-card .accent-blue{background:#a78bfa}
.gallery-card .icon{font-size:1.5rem;margin-bottom:0.75rem}
.gallery-card h3{font-size:1rem;font-weight:600;margin-bottom:0.5rem;color:#e2e8f0}
.gallery-card p{font-size:0.85rem;color:#94a3b8;line-height:1.5}
.gallery-card .try-btn{display:inline-block;margin-top:0.75rem;padding:0.3rem 0.8rem;background:#7b1fa2;color:#fff;border:none;border-radius:0.375rem;font-size:0.75rem;font-weight:600;cursor:pointer;transition:opacity 0.15s}
.gallery-card .try-btn:hover{opacity:0.85}
</style>
</head>
<body>
<img src="/images/tina4-logo-icon.webp" class="bg-watermark" alt="">
<div class="hero">
    <img src="/images/tina4-logo-icon.webp" class="logo" alt="Tina4">
    <h1>Tina4Php</h1>
    <p class="tagline">This is not a framework</p>
    <div class="actions">
        <a href="https://tina4.com/php" class="btn" target="_blank">Website</a>
        <a href="/__dev" class="btn">Dev Admin</a>
        <a href="#gallery" class="btn">Gallery</a>
        <a href="https://github.com/tina4stack/tina4-php" class="btn" target="_blank">GitHub</a>
        <a href="https://github.com/tina4stack/tina4-php/stargazers" class="btn" target="_blank">&#11088; Star</a>
    </div>
    <div class="status">
        <span><span class="dot"></span>Server running</span>
        <span>Port {$port}</span>
        <span>v{$version}</span>
    </div>
    <p class="footer">Zero dependencies &middot; Convention over configuration</p>
</div>
<div class="section">
    <div class="card">
        <h2>Getting Started</h2>
        <pre class="code-block"><code><span style="color:#64748b">// index.php</span>
<span style="color:#c084fc">require_once</span> <span style="color:#4ade80">'vendor/autoload.php'</span>;

\$app = <span style="color:#c084fc">new</span> <span style="color:#38bdf8">\Tina4\App</span>();

<span style="color:#38bdf8">\Tina4\Router</span>::<span style="color:#fbbf24">get</span>(<span style="color:#4ade80">'/hello'</span>, <span style="color:#c084fc">function</span> (\$request, \$response) {
    <span style="color:#c084fc">return</span> \$response-&gt;json([<span style="color:#4ade80">'message'</span> =&gt; <span style="color:#4ade80">'Hello World!'</span>]);
});

\$app-&gt;run();  <span style="color:#64748b">// starts on port 7146</span></code></pre>
    </div>
</div>
<div class="gallery">
    <h2 id="gallery">What You Can Build</h2>
    <p style="color:#64748b;font-size:0.85rem;text-align:center;margin-bottom:1.25rem;">Click <strong style="color:#94a3b8;">Try It</strong> to deploy working example code into your <code style="color:#4ade80;">src/</code> folder</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem;">
        <div class="gallery-card">
            <div class="accent accent-purple"></div>
            <div class="icon">&#128640;</div>
            <h3>REST API</h3>
            <p>Define routes with one closure</p>
            <pre style="background:#0f172a;color:#4ade80;padding:0.75rem;border-radius:0.375rem;font-size:0.75rem;overflow-x:auto;margin-top:0.5rem;font-family:'SF Mono',SFMono-Regular,Consolas,monospace;">Router::get('/api/users', function(\$req, \$res) {
    return \$res-&gt;json(['users' =&gt; []]);
});</pre>
            {$btnRestApi}
        </div>
        <div class="gallery-card">
            <div class="accent accent-green"></div>
            <div class="icon">&#128451;</div>
            <h3>ORM</h3>
            <p>Active record models, zero config</p>
            <pre style="background:#0f172a;color:#4ade80;padding:0.75rem;border-radius:0.375rem;font-size:0.75rem;overflow-x:auto;margin-top:0.5rem;font-family:'SF Mono',SFMono-Regular,Consolas,monospace;">class User extends ORM {
    public \$tableName = "users";
    public \$primaryKey = "id";
}</pre>
            {$btnOrm}
        </div>
        <div class="gallery-card">
            <div class="accent accent-blue"></div>
            <div class="icon">&#128274;</div>
            <h3>Auth</h3>
            <p>JWT tokens built-in</p>
            <pre style="background:#0f172a;color:#4ade80;padding:0.75rem;border-radius:0.375rem;font-size:0.75rem;overflow-x:auto;margin-top:0.5rem;font-family:'SF Mono',SFMono-Regular,Consolas,monospace;">\$token = Auth::getToken(["user_id" =&gt; 1]);
\$valid = Auth::validToken(\$token);</pre>
            {$btnAuth}
        </div>
        <div class="gallery-card">
            <div class="accent accent-purple"></div>
            <div class="icon">&#9889;</div>
            <h3>Queue</h3>
            <p>Background jobs, no Redis needed</p>
            <pre style="background:#0f172a;color:#4ade80;padding:0.75rem;border-radius:0.375rem;font-size:0.75rem;overflow-x:auto;margin-top:0.5rem;font-family:'SF Mono',SFMono-Regular,Consolas,monospace;">\$producer = new Producer(new Queue("emails"));
\$producer-&gt;produce(["to" =&gt; "a@b.com"]);</pre>
            {$btnQueue}
        </div>
        <div class="gallery-card">
            <div class="accent accent-green"></div>
            <div class="icon">&#128196;</div>
            <h3>Templates</h3>
            <p>Twig templates with auto-reload</p>
            <pre style="background:#0f172a;color:#4ade80;padding:0.75rem;border-radius:0.375rem;font-size:0.75rem;overflow-x:auto;margin-top:0.5rem;font-family:'SF Mono',SFMono-Regular,Consolas,monospace;">Router::get('/dashboard', function(\$req, \$res) {
    return \$res-&gt;template("dashboard.twig", \$data);
});</pre>
            {$btnTemplates}
        </div>
        <div class="gallery-card">
            <div class="accent accent-blue"></div>
            <div class="icon">&#128225;</div>
            <h3>Database</h3>
            <p>Multi-engine, one API</p>
            <pre style="background:#0f172a;color:#4ade80;padding:0.75rem;border-radius:0.375rem;font-size:0.75rem;overflow-x:auto;margin-top:0.5rem;font-family:'SF Mono',SFMono-Regular,Consolas,monospace;">\$db = new Database("sqlite:///app.db");
\$result = \$db-&gt;fetch("SELECT * FROM users");</pre>
            {$btnDatabase}
        </div>
        <div class="gallery-card">
            <div class="accent accent-purple"></div>
            <div class="icon">&#128680;</div>
            <h3>Error Overlay</h3>
            <p>Rich debug page with source code</p>
            <pre style="background:#0f172a;color:#4ade80;padding:0.75rem;border-radius:0.375rem;font-size:0.75rem;overflow-x:auto;margin-top:0.5rem;font-family:'SF Mono',SFMono-Regular,Consolas,monospace;">\$user = ["name" =&gt; "Alice"];
\$role = \$user["role"];  // KeyError!</pre>
            {$btnErrorOverlay}
        </div>
    </div>
</div>
<script>
function deployGallery(name, tryUrl) {
    var btn = event.target;
    if (btn.dataset.deployed) {
        window.open(tryUrl, '_blank');
        return;
    }
    if (!confirm('This will add example code to your src/ folder. Continue?')) return;
    btn.textContent = 'Deploying...';
    btn.disabled = true;
    fetch('/__dev/api/gallery/deploy', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({name: name})
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.deployed) {
            btn.textContent = 'View \\u2197';
            btn.style.background = '#22c55e';
            btn.dataset.deployed = '1';
            btn.disabled = false;
            // Wait for the newly deployed route to become reachable before navigating
            var attempts = 0;
            var maxAttempts = 5;
            function pollRoute() {
                fetch(tryUrl, {method: 'HEAD'}).then(function() {
                    window.location.href = tryUrl;
                }).catch(function() {
                    attempts++;
                    if (attempts < maxAttempts) {
                        setTimeout(pollRoute, 500);
                    } else {
                        window.location.href = tryUrl;
                    }
                });
            }
            setTimeout(pollRoute, 500);
        } else {
            btn.textContent = 'Try It';
            btn.disabled = false;
            alert(d.error || 'Deploy failed');
        }
    }).catch(function() {
        btn.textContent = 'Try It';
        btn.disabled = false;
        alert('Deploy failed — check console');
    });
}
</script>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Run the application using the custom Tina4 Server.
     * Calls start() to register routes, then launches the non-blocking HTTP/WebSocket server.
     * Falls back to `php -S` if stream_socket_server fails.
     *
     * @param string $host Host to bind to (default: 0.0.0.0)
     * @param int    $port Port to listen on (default: 7146)
     */
    public function run(string $host = '0.0.0.0', int $port = 7146): void
    {
        $this->start();

        $actualPort = self::findAvailablePort($port);
        if ($actualPort !== $port) {
            echo "Port {$port} is in use, using port {$actualPort} instead.\n";
            $port = $actualPort;
        }

        try {
            $server = new Server($host, $port);
            self::openBrowser("http://localhost:{$port}");
            $server->start();
        } catch (\RuntimeException $e) {
            // Fallback to PHP built-in server
            Log::warning('Custom server failed, falling back to php -S: ' . $e->getMessage());
            $docRoot = $this->basePath;
            $indexFile = $docRoot . DIRECTORY_SEPARATOR . 'index.php';
            if (is_file($indexFile)) {
                passthru("php -S {$host}:{$port} -t " . escapeshellarg($docRoot) . " " . escapeshellarg($indexFile));
            } else {
                passthru("php -S {$host}:{$port} -t " . escapeshellarg($docRoot));
            }
        }
    }

    /**
     * Handle a request — universal entry point for any PHP server.
     *
     * Makes Tina4 a drop-in for Swoole, RoadRunner, FrankenPHP, ReactPHP, etc.
     *
     * Accepts:
     *   - Tina4\Request object (pass-through)
     *   - Swoole\HTTP\Request (auto-converted)
     *   - PSR-7 ServerRequestInterface (auto-converted)
     *   - Array with 'method', 'path', 'headers', 'body' keys
     *   - null (reads from PHP globals — $_SERVER, php://input)
     *
     * Returns a Tina4\Response that can be sent to any server.
     *
     * Usage:
     *   $app = new \Tina4\App(basePath: __DIR__);
     *
     *   // Swoole
     *   $http->on("request", function($req, $res) use ($app) {
     *       $response = $app($req);
     *       $res->status($response->getStatusCode());
     *       foreach ($response->getHeaders() as $k => $v) $res->header($k, $v);
     *       $res->end($response->getBody());
     *   });
     *
     *   // RoadRunner
     *   while ($req = $worker->waitRequest()) {
     *       $response = $app($req);
     *       $worker->respond(new \Nyholm\Psr7\Response($response->getStatusCode(), $response->getHeaders(), $response->getBody()));
     *   }
     *
     *   // Direct (PHP-FPM / php -S)
     *   $app();
     *
     * @param mixed $request Request object, array, or null for globals
     * @return Response
     */
    public function __invoke(mixed $request = null): Response
    {
        $this->start();

        // Build Tina4 Request from whatever we received
        if ($request instanceof Request) {
            $tina4Request = $request;
        } elseif (is_array($request)) {
            // Array format: ['method' => 'GET', 'path' => '/api/users', 'headers' => [...], 'body' => '...']
            $tina4Request = new Request(
                method: $request['method'] ?? 'GET',
                path: $request['path'] ?? $request['uri'] ?? '/',
                headers: $request['headers'] ?? [],
                body: $request['body'] ?? '',
                query: $request['query'] ?? [],
                ip: $request['ip'] ?? '127.0.0.1',
            );
        } elseif (is_object($request) && method_exists($request, 'getMethod')) {
            // PSR-7 ServerRequestInterface
            $tina4Request = new Request(
                method: $request->getMethod(),
                path: $request->getUri()->getPath(),
                headers: array_map(fn($v) => $v[0] ?? '', $request->getHeaders()),
                body: (string) $request->getBody(),
                query: $request->getQueryParams(),
                ip: $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1',
            );
        } elseif (is_object($request) && property_exists($request, 'server')) {
            // Swoole\HTTP\Request
            $tina4Request = new Request(
                method: $request->server['request_method'] ?? 'GET',
                path: $request->server['request_uri'] ?? '/',
                headers: $request->header ?? [],
                body: $request->rawContent() ?: '',
                query: $request->get ?? [],
                ip: $request->server['remote_addr'] ?? '127.0.0.1',
            );
        } else {
            // Read from PHP globals (php -S, PHP-FPM, Apache)
            $tina4Request = Request::fromGlobals();
        }

        $response = new Response();
        return Router::dispatch($tina4Request, $response);
    }

    /**
     * Handle the current request and send the response to the client.
     * Use this for PHP-FPM / php -S / Apache where output goes to stdout.
     */
    public function handle(): void
    {
        $response = $this();

        http_response_code($response->getStatusCode() ?? 200);
        foreach ($response->getHeaders() as $name => $value) {
            header("$name: $value");
        }
        echo $response->getBody();
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
    public static function setDatabase(Database\Database|Database\DatabaseAdapter $db): void
    {
        self::$database = $db;
    }

    /**
     * Get the shared database instance.
     * If none is set and DATABASE_URL is configured, auto-creates one via Database.
     */
    public static function getDatabase(): Database\Database|Database\DatabaseAdapter|null
    {
        if (self::$database === null) {
            $db = Database\Database::fromEnv('DATABASE_URL');
            if ($db !== null) {
                self::$database = $db;
            }
        }

        return self::$database;
    }

    /**
     * Create and set a Database wrapper from a connection URL.
     *
     * @param string $url Connection URL (e.g. "pgsql://user:pass@host/db", "sqlite::memory:")
     * @param bool|null $autoCommit Override auto-commit setting
     * @return Database\Database The created Database wrapper
     */
    public static function createDatabase(string $url, ?bool $autoCommit = null): Database\Database
    {
        $db = Database\Database::create($url, $autoCommit);
        self::$database = $db;
        return $db;
    }

    /**
     * Find the first available port starting from $start.
     */
    private static function findAvailablePort(int $start, int $maxTries = 10): int
    {
        for ($i = 0; $i < $maxTries; $i++) {
            $port = $start + $i;
            $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
            if ($socket !== false) {
                fclose($socket);
                return $port;
            }
        }
        return $start; // Fall back to original port and let Server handle the error
    }

    /**
     * Open the default browser at the given URL.
     */
    private static function openBrowser(string $url): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            exec("open " . escapeshellarg($url) . " > /dev/null 2>&1 &");
        } elseif (PHP_OS_FAMILY === 'Windows') {
            exec("start " . escapeshellarg($url));
        } else {
            exec("xdg-open " . escapeshellarg($url) . " > /dev/null 2>&1 &");
        }
    }

    /**
     * Generate a short unique request ID.
     */
    private function generateRequestId(): string
    {
        return substr(bin2hex(random_bytes(8)), 0, 16);
    }
}
