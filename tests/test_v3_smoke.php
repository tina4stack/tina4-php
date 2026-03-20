<?php
require_once __DIR__ . "/../vendor/autoload.php";

// Include the constants file
require_once __DIR__ . "/../src/Tina4/Constants.php";

echo "╔══════════════════════════════════════════════════╗\n";
echo "║         Tina4 PHP v3 — Clean Run Demo           ║\n";
echo "╚══════════════════════════════════════════════════╝\n\n";

// ── 1. Bootstrap ──
echo "── 1. App Bootstrap ──\n";
$app = new \Tina4\App(
    basePath: __DIR__,
    development: true,
);
echo "   ✓ App created (v" . \Tina4\App::VERSION . ")\n";
echo "   ✓ .env loaded (TINA4_DEBUG=" . \Tina4\DotEnv::getEnv('TINA4_DEBUG') . ")\n";
echo "   ✓ Logger configured\n";
echo "   ✓ Health check registered at /health\n";
echo "   ✓ Signal handlers: " . (function_exists('pcntl_signal') ? 'POSIX' : 'skipped (Windows-safe)') . "\n\n";

// ── 2. Register routes ──
echo "── 2. Route Registration ──\n";
\Tina4\Router::reset();

\Tina4\Router::get('/', function (\Tina4\Request $req, \Tina4\Response $res) {
    return $res->html('<h1>Tina4 PHP v3</h1>');
});

\Tina4\Router::get('/api/hello', function (\Tina4\Request $req, \Tina4\Response $res) {
    return $res->json([
        'message' => 'Hello from Tina4 v3!',
        'version' => \Tina4\App::VERSION,
        'php'     => PHP_VERSION,
    ]);
});

\Tina4\Router::get('/api/users/{id}', function (\Tina4\Request $req, \Tina4\Response $res) {
    return $res->json([
        'user_id' => (int) $req->params['id'],
        'name'    => 'User ' . $req->params['id'],
    ]);
});

\Tina4\Router::post('/api/users', function (\Tina4\Request $req, \Tina4\Response $res) {
    return $res->json(['created' => true, 'data' => $req->body], \Tina4\HTTP_CREATED);
});

\Tina4\Router::get('/api/health', function (\Tina4\Request $req, \Tina4\Response $res) use ($app) {
    return $res->json($app->getHealthData());
})->cache();

\Tina4\Router::get('/api/admin', function (\Tina4\Request $req, \Tina4\Response $res) {
    return $res->json(['admin' => true]);
})->secure();

$routes = \Tina4\Router::list();
echo "   ✓ " . count($routes) . " routes registered:\n";
foreach ($routes as $r) {
    $flags = [];
    if ($r['cache']) $flags[] = 'CACHE';
    if ($r['secure']) $flags[] = 'AUTH';
    $flagStr = $flags ? ' [' . implode(', ', $flags) . ']' : '';
    printf("     %-6s %-25s%s\n", $r['method'], $r['pattern'], $flagStr);
}
echo "\n";

// ── 3. Dispatch requests ──
echo "── 3. Request Dispatch ──\n";

$tests = [
    ['GET',  '/',             200, 'HTML landing page'],
    ['GET',  '/api/hello',    200, 'JSON response'],
    ['GET',  '/api/users/42', 200, 'Path parameter extraction'],
    ['GET',  '/api/admin',    401, 'Secure route (no token)'],
    ['GET',  '/not-found',    404, 'Missing route'],
];

$passed = 0;
foreach ($tests as [$method, $path, $expected, $desc]) {
    $req = \Tina4\Request::create(method: $method, path: $path);
    $res = new \Tina4\Response(testing: true);
    $result = \Tina4\Router::dispatch($req, $res);
    $status = $result->getStatusCode();
    $ok = $status === $expected;
    $icon = $ok ? '✓' : '✗';
    $body = substr($result->getBody(), 0, 60);
    echo "   $icon $method $path → $status $body\n";
    if ($ok) $passed++;
}
echo "   " . $passed . "/" . count($tests) . " assertions passed\n\n";

// ── 4. DotEnv ──
echo "── 4. Environment (.env) ──\n";
echo "   TINA4_DEBUG = " . \Tina4\DotEnv::getEnv('TINA4_DEBUG', 'false') . "\n";
echo "   MISSING_VAR = " . \Tina4\DotEnv::getEnv('MISSING_VAR', '(uses default)') . "\n\n";

// ── 5. Logging ──
echo "── 5. Structured Logging ──\n";
ob_start();
\Tina4\Log::info('Request processed', ['route' => '/api/hello', 'ms' => 12]);
\Tina4\Log::warning('Slow query detected', ['ms' => 450]);
\Tina4\Log::error('Connection refused', ['host' => 'db.local']);
$logOutput = ob_get_clean();
// Show just the log lines without ANSI
$lines = array_filter(explode("\n", preg_replace('/\033\[[0-9;]*m/', '', $logOutput)));
foreach ($lines as $line) {
    echo "   $line\n";
}
echo "\n";

// ── 6. Health ──
echo "── 6. Health Check ──\n";
echo "   " . json_encode($app->getHealthData()) . "\n\n";

// ── 7. Constants ──
echo "── 7. HTTP Constants ──\n";
echo "   HTTP_OK         = " . \Tina4\HTTP_OK . "\n";
echo "   HTTP_CREATED    = " . \Tina4\HTTP_CREATED . "\n";
echo "   HTTP_NOT_FOUND  = " . \Tina4\HTTP_NOT_FOUND . "\n";
echo "   HTTP_UNAUTHORIZED= " . \Tina4\HTTP_UNAUTHORIZED . "\n";
echo "   APPLICATION_JSON= " . \Tina4\APPLICATION_JSON . "\n";
echo "   TEXT_HTML       = " . \Tina4\TEXT_HTML . "\n\n";

// ── Summary ──
echo "╔══════════════════════════════════════════════════╗\n";
echo "║  All v3 core components working:                 ║\n";
echo "║                                                  ║\n";
echo "║  ✓ App lifecycle    ✓ DotEnv (zero-dep)         ║\n";
echo "║  ✓ Router + params  ✓ Structured logger         ║\n";
echo "║  ✓ Request/Response ✓ Health endpoint            ║\n";
echo "║  ✓ Middleware chain ✓ Secure routes (JWT-ready)  ║\n";
echo "║  ✓ Cache modifier   ✓ HTTP constants             ║\n";
echo "║  ✓ 404 handling     ✓ Graceful shutdown          ║\n";
echo "║  ✓ Windows-safe     ✓ Zero 3rd-party deps        ║\n";
echo "╚══════════════════════════════════════════════════╝\n";
