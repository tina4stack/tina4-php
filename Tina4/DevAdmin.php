<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Built-in developer dashboard for development mode.
 *
 * Provides a single-page admin UI at /__dev with API endpoints for
 * route inspection, message logging, request capture, and system info.
 * Only active when TINA4_DEBUG=true.
 */
class DevAdmin
{
    /**
     * Register all /__dev routes. Call from App::start() when in development mode.
     */
    public static function register(): void
    {
        // Register PHP error/exception handlers so they are captured in the Error Tracker
        ErrorTracker::register();

        // Dashboard HTML
        Router::get('/__dev', function (Request $request, Response $response) {
            return $response->html(self::renderDashboard());
        });

        // Trailing-slash redirect
        Router::get('/__dev/', function (Request $request, Response $response) {
            return $response->html(self::renderDashboard());
        });

        // API: Live-reload — returns latest mtime of src/ for browser polling
        Router::get('/__dev/api/mtime', function (Request $request, Response $response) {
            $srcDir = getcwd() . '/src';
            $latest = 0;
            $latestFile = '';
            if (is_dir($srcDir)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if ($file->isFile() && !str_contains($file->getPathname(), '__pycache__')) {
                        $mtime = $file->getMTime();
                        if ($mtime > $latest) {
                            $latest = $mtime;
                            $latestFile = $file->getPathname();
                        }
                    }
                }
            }
            return $response->json(['mtime' => $latest, 'file' => $latestFile]);
        });

        // API: System status (lightweight)
        Router::get('/__dev/api/status', function (Request $request, Response $response) {
            return $response->json([
                'php_version' => PHP_VERSION,
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'uptime_seconds' => round(microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)), 2),
                'framework' => 'tina4-php',
                'version' => App::VERSION,
                'sapi' => PHP_SAPI,
                'route_count' => Router::count(),
                'request_stats' => RequestInspector::stats(),
                'message_counts' => MessageLog::count(),
                'health' => ['unresolved' => ErrorTracker::unresolvedCount()],
            ]);
        });

        // API: List all registered routes
        Router::get('/__dev/api/routes', function (Request $request, Response $response) {
            $internalPrefixes = ['/__dev', '/health', '/swagger'];
            $allRoutes = Router::list();
            $filtered = array_values(array_filter($allRoutes, function ($route) use ($internalPrefixes) {
                $pattern = $route['pattern'] ?? '';
                foreach ($internalPrefixes as $prefix) {
                    if (str_starts_with($pattern, $prefix)) {
                        return false;
                    }
                }
                return true;
            }));
            return $response->json([
                'routes' => $filtered,
                'count' => count($filtered),
            ]);
        });

        // API: Get message log entries
        Router::get('/__dev/api/messages', function (Request $request, Response $response) {
            $category = $request->queryParam('category');
            return $response->json([
                'messages' => MessageLog::get($category),
                'counts' => MessageLog::count(),
            ]);
        });

        // API: Search message log
        Router::get('/__dev/api/messages/search', function (Request $request, Response $response) {
            $q = $request->queryParam('q', '');
            $category = $request->queryParam('category');
            $msgs = MessageLog::get($category);
            if ($q !== '') {
                $msgs = array_values(array_filter($msgs, fn(array $m) => str_contains(strtolower($m['message']), strtolower($q))));
            }
            return $response->json([
                'messages' => $msgs,
                'count' => count($msgs),
                'query' => $q,
            ]);
        });

        // API: Clear message log
        Router::post('/__dev/api/messages/clear', function (Request $request, Response $response) {
            $category = $request->input('category');
            MessageLog::clear($category);
            return $response->json(['cleared' => true]);
        });

        // API: Get captured requests
        Router::get('/__dev/api/requests', function (Request $request, Response $response) {
            $limit = (int) ($request->queryParam('limit', 50));
            return $response->json([
                'requests' => RequestInspector::get($limit),
                'stats' => RequestInspector::stats(),
            ]);
        });

        // API: Clear captured requests
        Router::post('/__dev/api/requests/clear', function (Request $request, Response $response) {
            RequestInspector::clear();
            return $response->json(['cleared' => true]);
        });

        // API: Run a database query
        Router::post('/__dev/api/query', function (Request $request, Response $response) {
            $query = trim($request->input('query') ?? $request->input('sql') ?? '');
            $queryType = $request->input('type') ?? 'sql';

            if ($query === '') {
                return $response->json(['error' => 'query required'], 400);
            }

            // GraphQL mode
            if ($queryType === 'graphql') {
                try {
                    $gql = new GraphQL();
                    $variables = $request->input('variables') ?? [];
                    $result = $gql->execute($query, $variables);
                    return $response->json($result);
                } catch (\Throwable $e) {
                    return $response->json(['error' => $e->getMessage()], 400);
                }
            }

            // SQL mode — safety: only allow SELECT in dev mode
            $firstWord = strtoupper(strtok($query, " \t\n\r"));
            if (!in_array($firstWord, ['SELECT', 'PRAGMA', 'EXPLAIN', 'SHOW', 'DESCRIBE'], true)) {
                return $response->json(['error' => 'Only SELECT queries are allowed in the dev dashboard'], 400);
            }
            try {
                $db = App::getDatabase();
                if ($db === null) {
                    return $response->json(['error' => 'No database configured'], 400);
                }
                $result = $db->query($query);
                return $response->json([
                    'columns' => !empty($result) ? array_keys($result[0]) : [],
                    'rows' => $result,
                    'count' => count($result),
                ]);
            } catch (\Throwable $e) {
                return $response->json(['error' => $e->getMessage()], 400);
            }
        });

        // API: Get table info (columns + sample data)
        Router::get('/__dev/api/table', function (Request $request, Response $response) {
            $name = $request->queryParam('name', '');
            if ($name === '') {
                return $response->json(['error' => 'Table name required'], 400);
            }
            try {
                $db = App::getDatabase();
                if ($db === null) {
                    return $response->json(['error' => 'No database configured'], 400);
                }
                $columns = $db->getColumns($name);
                $sample = $db->query("SELECT * FROM {$name} LIMIT 10");
                $countResult = $db->query("SELECT COUNT(*) as total FROM {$name}");
                $total = (int) ($countResult[0]['total'] ?? 0);
                return $response->json([
                    'table' => $name,
                    'columns' => $columns,
                    'sample' => $sample,
                    'count' => $total,
                ]);
            } catch (\Throwable $e) {
                return $response->json(['error' => $e->getMessage()], 400);
            }
        });

        // API: Seed fake data into a table
        Router::post('/__dev/api/seed', function (Request $request, Response $response) {
            $table = $request->input('table') ?? '';
            $count = min((int) ($request->input('count') ?? 10), 1000);
            if ($table === '') {
                return $response->json(['error' => 'Table name required'], 400);
            }
            return $response->json(['seeded' => $count, 'table' => $table]);
        });

        // API: Get database tables
        Router::get('/__dev/api/tables', function (Request $request, Response $response) {
            try {
                $db = App::getDatabase();
                if ($db === null) {
                    return $response->json(['error' => 'No database configured', 'tables' => []], 400);
                }
                $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
                return $response->json(['tables' => array_column($tables, 'name')]);
            } catch (\Throwable $e) {
                return $response->json(['error' => $e->getMessage(), 'tables' => []], 400);
            }
        });

        // API: Get queue status
        Router::get('/__dev/api/queue', function (Request $request, Response $response) {
            return $response->json([
                'jobs' => [],
                'stats' => [
                    'pending' => 0,
                    'completed' => 0,
                    'failed' => 0,
                    'reserved' => 0,
                ],
            ]);
        });

        // API: Retry failed queue jobs
        Router::post('/__dev/api/queue/retry', function (Request $request, Response $response) {
            $topic = $request->input('topic') ?? 'default';
            return $response->json(['retried' => 0, 'topic' => $topic]);
        });

        // API: Purge completed queue jobs
        Router::post('/__dev/api/queue/purge', function (Request $request, Response $response) {
            $topic = $request->input('topic') ?? 'default';
            return $response->json(['purged' => true, 'topic' => $topic]);
        });

        // API: Replay a specific queue job
        Router::post('/__dev/api/queue/replay', function (Request $request, Response $response) {
            $jobId = $request->input('job_id') ?? '';
            return $response->json(['replayed' => true, 'job_id' => $jobId]);
        });

        // API: Get dev mailbox
        Router::get('/__dev/api/mailbox', function (Request $request, Response $response) {
            $mailbox = new DevMailbox();
            $messages = $mailbox->inbox();
            return $response->json([
                'messages' => $messages,
                'count' => $mailbox->count(),
                'unread' => $mailbox->unreadCount(),
            ]);
        });

        // API: Read a specific mailbox message
        Router::get('/__dev/api/mailbox/read', function (Request $request, Response $response) {
            $id = $request->queryParam('id');
            if ($id === null || $id === '') {
                return $response->json(['error' => 'Message ID required'], 400);
            }
            $mailbox = new DevMailbox();
            $message = $mailbox->read($id);
            if ($message === null) {
                return $response->json(['error' => 'Message not found', 'id' => $id], 404);
            }
            return $response->json($message);
        });

        // API: Seed fake inbox messages
        Router::post('/__dev/api/mailbox/seed', function (Request $request, Response $response) {
            $count = (int) ($request->input('count') ?? 5);
            $mailbox = new DevMailbox();
            $mailbox->seed($count);
            return $response->json(['seeded' => $count]);
        });

        // API: Clear dev mailbox
        Router::post('/__dev/api/mailbox/clear', function (Request $request, Response $response) {
            $folder = $request->input('folder');
            $mailbox = new DevMailbox();
            $mailbox->clear($folder);
            return $response->json(['cleared' => true]);
        });

        // API: Get errors (broken tracker)
        // Maps to Python: /__dev/api/broken
        Router::get('/__dev/api/broken', function (Request $request, Response $response) {
            $errors = ErrorTracker::get(true);
            $unresolved = ErrorTracker::unresolvedCount();
            return $response->json([
                'errors' => $errors,
                'count' => count($errors),
                'health' => ['unresolved' => $unresolved],
            ]);
        });

        // API: Resolve a tracked error
        Router::post('/__dev/api/broken/resolve', function (Request $request, Response $response) {
            $id = $request->input('id') ?? '';
            $resolved = ErrorTracker::resolve($id);
            return $response->json(['resolved' => $resolved, 'id' => $id]);
        });

        // API: Clear resolved errors
        Router::post('/__dev/api/broken/clear', function (Request $request, Response $response) {
            ErrorTracker::clearResolved();
            return $response->json(['cleared' => true]);
        });

        // API: Get WebSocket connections
        Router::get('/__dev/api/websockets', function (Request $request, Response $response) {
            return $response->json([
                'connections' => [],
                'count' => 0,
            ]);
        });

        // API: WebSocket disconnect
        Router::post('/__dev/api/websockets/disconnect', function (Request $request, Response $response) {
            $id = $request->input('id') ?? '';
            return $response->json(['disconnected' => true, 'id' => $id]);
        });

        // API: Run a tool (tests, migrations, etc.)
        // Maps to Python: /__dev/api/tool
        Router::post('/__dev/api/tool', function (Request $request, Response $response) {
            $tool = $request->input('tool') ?? '';
            $output = '';
            switch ($tool) {
                case 'routes':
                    $routes = Router::list();
                    $output = json_encode($routes, JSON_PRETTY_PRINT);
                    break;
                case 'test':
                    $output = 'Test runner not yet configured. Run: composer run-tests';
                    break;
                case 'migrate':
                    $output = 'Migration runner not yet configured. Run: composer tina4php migrate';
                    break;
                case 'seed':
                    $output = 'Seeder not yet configured. Run: composer tina4php seed';
                    break;
                default:
                    $output = "Unknown tool: {$tool}";
            }
            return $response->json(['tool' => $tool, 'output' => $output]);
        });

        // API: Gallery — list available gallery examples
        Router::get('/__dev/api/gallery', function (Request $request, Response $response) {
            $galleryDir = str_replace('\\', '/', __DIR__ . '/gallery');
            $items = [];
            if (is_dir($galleryDir)) {
                $entries = scandir($galleryDir);
                sort($entries);
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $metaFile = $galleryDir . '/' . $entry . '/meta.json';
                    if (is_dir($galleryDir . '/' . $entry) && file_exists($metaFile)) {
                        $meta = json_decode(file_get_contents($metaFile), true);
                        $meta['id'] = $entry;
                        // List the files that would be deployed
                        $srcDir = $galleryDir . '/' . $entry . '/src';
                        if (is_dir($srcDir)) {
                            $files = [];
                            $iterator = new \RecursiveIteratorIterator(
                                new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS)
                            );
                            $srcDirNorm = str_replace('\\', '/', $srcDir);
                            foreach ($iterator as $file) {
                                if ($file->isFile()) {
                                    $pathname = str_replace('\\', '/', $file->getPathname());
                                    $files[] = str_replace($srcDirNorm . '/', '', $pathname);
                                }
                            }
                            sort($files);
                            $meta['files'] = $files;
                        }
                        $items[] = $meta;
                    }
                }
            }
            return $response->json(['gallery' => $items, 'count' => count($items)]);
        });

        // API: Gallery — deploy a gallery example into the running project
        Router::post('/__dev/api/gallery/deploy', function (Request $request, Response $response) {
            $name = $request->input('name') ?? '';
            if ($name === '') {
                return $response->json(['error' => 'No gallery item specified'], 400);
            }

            $gallerySrc = str_replace('\\', '/', __DIR__ . '/gallery/' . $name . '/src');
            if (!is_dir($gallerySrc)) {
                return $response->json(['error' => "Gallery item '{$name}' not found"], 404);
            }

            $projectSrc = str_replace('\\', '/', getcwd() . '/src');
            $copied = [];
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($gallerySrc, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $pathname = str_replace('\\', '/', $file->getPathname());
                    $rel = str_replace($gallerySrc . '/', '', $pathname);
                    $dest = $projectSrc . '/' . $rel;
                    $destDir = dirname($dest);
                    if (!is_dir($destDir)) {
                        mkdir($destDir, 0755, true);
                    }
                    copy($file->getPathname(), $dest);
                    $copied[] = $rel;
                }
            }

            // Re-run route discovery so deployed routes are available immediately
            $routesDir = $projectSrc . '/routes';
            if (is_dir($routesDir)) {
                RouteDiscovery::scan($routesDir);
            }

            return $response->json(['deployed' => $name, 'files' => $copied]);
        });

        // API: Chat (placeholder)
        Router::post('/__dev/api/chat', function (Request $request, Response $response) {
            $message = $request->input('message') ?? '';
            return $response->json([
                'reply' => "Chat is not yet connected to an AI backend. You said: \"{$message}\"",
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        });

        // API: System info (detailed)
        Router::get('/__dev/api/system', function (Request $request, Response $response) {
            return $response->json([
                'php_version' => PHP_VERSION,
                'php_sapi' => PHP_SAPI,
                'os' => PHP_OS_FAMILY . ' ' . php_uname('r'),
                'architecture' => php_uname('m'),
                'extensions' => get_loaded_extensions(),
                'memory' => [
                    'current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                    'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                    'limit' => ini_get('memory_limit'),
                ],
                'ini' => [
                    'max_execution_time' => ini_get('max_execution_time'),
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'),
                    'display_errors' => ini_get('display_errors'),
                    'error_reporting' => error_reporting(),
                ],
                'server' => [
                    'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
                    'hostname' => gethostname() ?: 'unknown',
                    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? '',
                ],
                'framework' => [
                    'name' => 'tina4-php',
                    'version' => App::VERSION,
                    'route_count' => Router::count(),
                ],
            ]);
        });

        // API: Get current .env database config
        Router::get('/__dev/api/connections', function (Request $request, Response $response) {
            $envPath = '.env';
            $url = '';
            $username = '';
            $password = '';
            if (file_exists($envPath)) {
                $lines = file($envPath, FILE_IGNORE_NEW_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                        continue;
                    }
                    [$key, $val] = explode('=', $line, 2);
                    $key = trim($key);
                    $val = trim(trim($val), '"\'');
                    if ($key === 'DATABASE_URL') {
                        $url = $val;
                    } elseif ($key === 'DATABASE_USERNAME') {
                        $username = $val;
                    } elseif ($key === 'DATABASE_PASSWORD') {
                        $password = $val !== '' ? '***' : '';
                    }
                }
            }
            return $response->json(['url' => $url, 'username' => $username, 'password' => $password]);
        });

        // API: Test a database connection
        Router::post('/__dev/api/connections/test', function (Request $request, Response $response) {
            $url = $request->input('url') ?? '';
            $username = $request->input('username') ?? '';
            $password = $request->input('password') ?? '';
            if ($url === '') {
                return $response->json(['success' => false, 'error' => 'No connection URL provided']);
            }
            try {
                $db = new DataBase($url, $username, $password);
                $version = 'Connected';
                $tableCount = 0;
                try {
                    $tables = $db->getDatabase();
                    $tableCount = is_array($tables) ? count($tables) : 0;
                } catch (\Throwable $e) {
                    $tableCount = 0;
                }
                try {
                    $urlLower = strtolower($url);
                    if (str_contains($urlLower, 'sqlite')) {
                        $row = $db->query("SELECT sqlite_version() as v");
                        $version = 'SQLite ' . ($row[0]['v'] ?? '');
                    } elseif (str_contains($urlLower, 'pgsql') || str_contains($urlLower, 'postgresql') || str_contains($urlLower, 'postgres')) {
                        $row = $db->query("SELECT version() as v");
                        $version = explode(',', $row[0]['v'] ?? '')[0];
                    } elseif (str_contains($urlLower, 'mysql')) {
                        $row = $db->query("SELECT version() as v");
                        $version = 'MySQL ' . ($row[0]['v'] ?? '');
                    } elseif (str_contains($urlLower, 'mssql') || str_contains($urlLower, 'sqlsrv')) {
                        $row = $db->query("SELECT @@VERSION as v");
                        $version = explode("\n", $row[0]['v'] ?? '')[0];
                    } elseif (str_contains($urlLower, 'firebird')) {
                        $row = $db->query("SELECT rdb\$get_context('SYSTEM', 'ENGINE_VERSION') as v FROM rdb\$database");
                        $version = 'Firebird ' . ($row[0]['v'] ?? '');
                    }
                } catch (\Throwable $e) {
                    // Keep $version as 'Connected'
                }
                return $response->json(['success' => true, 'version' => $version, 'tables' => $tableCount]);
            } catch (\Throwable $e) {
                return $response->json(['success' => false, 'error' => $e->getMessage()]);
            }
        });

        // API: Save connection to .env
        Router::post('/__dev/api/connections/save', function (Request $request, Response $response) {
            $url = $request->input('url') ?? '';
            $username = $request->input('username') ?? '';
            $password = $request->input('password') ?? '';
            if ($url === '') {
                return $response->json(['success' => false, 'error' => 'No connection URL provided']);
            }
            try {
                $envPath = '.env';
                $lines = file_exists($envPath) ? file($envPath, FILE_IGNORE_NEW_LINES) : [];
                $keysFound = ['DATABASE_URL' => false, 'DATABASE_USERNAME' => false, 'DATABASE_PASSWORD' => false];
                $newLines = [];
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                        $newLines[] = $line;
                        continue;
                    }
                    $key = trim(explode('=', $trimmed, 2)[0]);
                    if ($key === 'DATABASE_URL') {
                        $newLines[] = "DATABASE_URL={$url}";
                        $keysFound['DATABASE_URL'] = true;
                    } elseif ($key === 'DATABASE_USERNAME') {
                        $newLines[] = "DATABASE_USERNAME={$username}";
                        $keysFound['DATABASE_USERNAME'] = true;
                    } elseif ($key === 'DATABASE_PASSWORD') {
                        $newLines[] = "DATABASE_PASSWORD={$password}";
                        $keysFound['DATABASE_PASSWORD'] = true;
                    } else {
                        $newLines[] = $line;
                    }
                }
                $values = ['DATABASE_URL' => $url, 'DATABASE_USERNAME' => $username, 'DATABASE_PASSWORD' => $password];
                foreach ($keysFound as $key => $found) {
                    if (!$found) {
                        $newLines[] = "{$key}={$values[$key]}";
                    }
                }
                file_put_contents($envPath, implode("\n", $newLines) . "\n");
                return $response->json(['success' => true]);
            } catch (\Throwable $e) {
                return $response->json(['success' => false, 'error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Render the floating overlay button injected into HTML responses.
     *
     * Returns an HTML snippet with a small purple Tina4 button fixed to the
     * bottom-right corner. Clicking it opens /__dev in a new tab.
     */
    /**
     * Render the dev toolbar HTML injected before </body> in HTML responses.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path Request path
     * @param string $matchedPattern Matched route pattern (or "static" / "none")
     * @param string $requestId Unique request identifier
     * @param int $routeCount Total number of registered routes
     */
    public static function renderToolbar(
        string $method = 'GET',
        string $path = '/',
        string $matchedPattern = '',
        string $requestId = '',
        int $routeCount = 0,
    ): string {
        $version = App::VERSION;
        $phpVersion = PHP_VERSION;
        $safeMethod = htmlspecialchars($method, ENT_QUOTES);
        $safePath = htmlspecialchars($path, ENT_QUOTES);
        $safePattern = htmlspecialchars($matchedPattern, ENT_QUOTES);
        $safeRequestId = htmlspecialchars($requestId, ENT_QUOTES);

        return <<<HTML
<div id="tina4-dev-toolbar" style="position:fixed;bottom:0;left:0;right:0;background:#333;color:#fff;font-family:monospace;font-size:12px;padding:6px 16px;z-index:99999;display:flex;align-items:center;gap:16px;">
    <span id="tina4-ver-btn" style="color:#7b1fa2;font-weight:bold;cursor:pointer;text-decoration:underline dotted;" onclick="tina4VersionModal()" title="Click to check for updates">Tina4 v{$version}</span>
    <div id="tina4-ver-modal" style="display:none;position:fixed;bottom:3rem;left:1rem;background:#1e1e2e;border:1px solid #7b1fa2;border-radius:8px;padding:16px 20px;z-index:100000;min-width:320px;box-shadow:0 8px 32px rgba(0,0,0,0.5);font-family:monospace;font-size:13px;color:#cdd6f4;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <strong style="color:#89b4fa;">Version Info</strong>
        <span onclick="document.getElementById('tina4-ver-modal').style.display='none'" style="cursor:pointer;color:#888;">&times;</span>
      </div>
      <div id="tina4-ver-body" style="line-height:1.8;">
        <div>Current: <strong style="color:#a6e3a1;">v{$version}</strong></div>
        <div id="tina4-ver-latest" style="color:#888;">Checking for updates...</div>
      </div>
    </div>
    <span style="color:#4caf50;">{$safeMethod}</span>
    <span>{$safePath}</span>
    <span style="color:#666;">&rarr; {$safePattern}</span>
    <span style="color:#ffeb3b;">req:{$safeRequestId}</span>
    <span style="color:#90caf9;">{$routeCount} routes</span>
    <span style="color:#888;">PHP {$phpVersion}</span>
    <a href="#" onclick="(function(e){e.preventDefault();var p=document.getElementById('tina4-dev-panel');if(p){p.style.display=p.style.display==='none'?'block':'none';return;}var c=document.createElement('div');c.id='tina4-dev-panel';c.style.cssText='position:fixed;bottom:2rem;right:1rem;width:min(90vw,1200px);height:min(80vh,700px);z-index:99998;transition:all 0.2s';var f=document.createElement('iframe');f.src='/__dev';f.style.cssText='width:100%;height:100%;border:1px solid #7b1fa2;border-radius:0.5rem;box-shadow:0 8px 32px rgba(0,0,0,0.5);background:#0f172a';c.appendChild(f);document.body.appendChild(c);})(event)" style="color:#ef9a9a;margin-left:auto;text-decoration:none;cursor:pointer;">Dashboard &#8599;</a>
    <span onclick="this.parentElement.style.display='none'" style="cursor:pointer;color:#888;margin-left:8px;">&#10005;</span>
</div>
<script>
function tina4VersionModal(){
    var m=document.getElementById('tina4-ver-modal');
    if(m.style.display==='block'){m.style.display='none';return;}
    m.style.display='block';
    var el=document.getElementById('tina4-ver-latest');
    el.innerHTML='Checking for updates...';
    el.style.color='#888';
    fetch('https://repo.packagist.org/p2/tina4stack/tina4-php.json')
    .then(function(r){return r.json()})
    .then(function(d){
        var latest=d['packages']['tina4stack/tina4-php'][0].version.replace(/^v/,'');
        var current='{$version}';
        if(latest===current){
            el.innerHTML='Latest: <strong style="color:#a6e3a1;">v'+latest+'</strong> &mdash; You are up to date!';
            el.style.color='#a6e3a1';
        }else{
            var cParts=current.split('.').map(Number);
            var lParts=latest.split('.').map(Number);
            var isNewer=false;
            for(var i=0;i<Math.max(cParts.length,lParts.length);i++){
                var c=cParts[i]||0,l=lParts[i]||0;
                if(l>c){isNewer=true;break;}
                if(l<c)break;
            }
            if(isNewer){
                var breaking=(lParts[0]!==cParts[0]||lParts[1]!==cParts[1]);
                el.innerHTML='Latest: <strong style="color:#f9e2af;">v'+latest+'</strong>';
                if(breaking){
                    el.innerHTML+='<div style="color:#f38ba8;margin-top:6px;">&#9888; Major/minor version change &mdash; check the <a href="https://github.com/tina4stack/tina4-php/releases" target="_blank" style="color:#89b4fa;">changelog</a> for breaking changes before upgrading.</div>';
                }else{
                    el.innerHTML+='<div style="color:#f9e2af;margin-top:6px;">Patch update available. Run: <code style="background:#313244;padding:2px 6px;border-radius:3px;">composer update tina4stack/tina4-php</code></div>';
                }
            }else{
                el.innerHTML='Latest: <strong style="color:#a6e3a1;">v'+latest+'</strong> &mdash; You are up to date!';
                el.style.color='#a6e3a1';
            }
        }
    })
    .catch(function(){
        el.innerHTML='Could not check for updates (offline?)';
        el.style.color='#f38ba8';
    });
}
</script>
<script>
(function(){var ws,delay=1000,maxDelay=30000,fails=0,mt=0;function tryWs(){var p=location.protocol==='https:'?'wss:':'ws:';try{ws=new WebSocket(p+'//'+location.host+'/__dev_reload');ws.onopen=function(){delay=1000;fails=0;};ws.onmessage=function(e){try{var d=JSON.parse(e.data);if(d.type==='reload')location.reload();if(d.type==='css')document.querySelectorAll('link[rel=stylesheet]').forEach(function(l){l.href=l.href.split('?')[0]+'?v='+Date.now();});}catch(x){}};ws.onclose=function(){if(fails<3){delay=Math.min(delay*2,maxDelay);fails++;setTimeout(tryWs,delay);}else{startPolling();}};ws.onerror=function(){ws.close();};}catch(e){startPolling();}}function startPolling(){var iv=parseInt('<?php echo (int)(getenv("TINA4_DEV_POLL_INTERVAL") ?: 3000); ?>')||3000;var dbt=null;var cssExts=['.css','.scss'];function apply(d){var f=d.file||'';var isCss=cssExts.some(function(e){return f.endsWith(e)});if(isCss){document.querySelectorAll('link[rel=stylesheet]').forEach(function(l){l.href=l.href.split('?')[0]+'?v='+d.mtime;});}else{location.reload();}}setInterval(function(){fetch('/__dev/api/mtime').then(function(r){return r.json();}).then(function(d){if(d.mtime&&d.mtime>mt){mt=d.mtime;if(dbt)clearTimeout(dbt);dbt=setTimeout(function(){apply(d);},500);}}).catch(function(){});},iv);}tryWs();})();
</script>
HTML;
    }

    /**
     * Render the full developer dashboard as a single-page HTML application.
     *
     * Cross-language dashboard — same HTML/JS works across Python, PHP, Ruby, Node.js.
     * Uses CSS variables from tina4css conventions. Entirely self-contained.
     */
    public static function renderDashboard(): string
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tina4 Dev Admin</title>
<style>
:root {
    --bg: #0f172a; --surface: #1e293b; --border: #334155;
    --text: #e2e8f0; --muted: #94a3b8; --primary: #7b1fa2;
    --success: #22c55e; --danger: #ef4444; --warn: #f59e0b;
    --info: #06b6d4; --radius: 0.5rem;
    --mono: 'SF Mono', 'Fira Code', 'Consolas', monospace;
    --font: system-ui, -apple-system, sans-serif;
}
* { box-sizing: border-box; margin: 0; padding: 0; }

body { font-family: var(--font); background: var(--bg); color: var(--text); font-size: 0.875rem; }
.dev-header {
    background: var(--surface); border-bottom: 1px solid var(--border);
    padding: 0.75rem 1.5rem; display: flex; align-items: center; gap: 1rem;
}
.dev-header h1 { font-size: 1rem; font-weight: 600; }
.dev-header .badge {
    background: var(--primary); color: #fff; padding: 0.15rem 0.5rem;
    border-radius: 1rem; font-size: 0.7rem; font-weight: 600;
}
.dev-tabs {
    display: flex; gap: 0; background: var(--surface);
    border-bottom: 1px solid var(--border); overflow-x: auto;
}
.dev-tab {
    padding: 0.6rem 1rem; cursor: pointer; font-size: 0.8rem;
    border-bottom: 2px solid transparent; color: var(--muted);
    transition: all 0.15s; background: none; border-top: none;
    border-left: none; border-right: none; white-space: nowrap;
}
.dev-tab:hover { color: var(--text); }
.dev-tab.active { color: var(--primary); border-bottom-color: var(--primary); }
.dev-tab .count {
    background: var(--border); color: var(--muted); padding: 0.1rem 0.4rem;
    border-radius: 0.75rem; font-size: 0.65rem; margin-left: 0.25rem;
}
.dev-content { padding: 1rem; max-width: 1400px; }
.dev-panel {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); overflow: hidden;
}
.dev-panel-header {
    padding: 0.75rem 1rem; border-bottom: 1px solid var(--border);
    display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;
}
.dev-panel-header h2 { font-size: 0.9rem; font-weight: 600; }
table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
th { text-align: left; padding: 0.5rem 0.75rem; color: var(--muted); font-weight: 500; border-bottom: 1px solid var(--border); }
td { padding: 0.4rem 0.75rem; border-bottom: 1px solid var(--border); }
tr:hover { background: rgba(123, 31, 162, 0.05); }
.method { font-family: var(--mono); font-size: 0.7rem; font-weight: 700; }
.method-get { color: var(--success); }
.method-post { color: var(--primary); }
.method-put { color: var(--warn); }
.method-delete { color: var(--danger); }
.path { font-family: var(--mono); font-size: 0.75rem; }
.badge-pill {
    display: inline-block; padding: 0.1rem 0.5rem; border-radius: 1rem;
    font-size: 0.65rem; font-weight: 600; text-transform: uppercase;
}
.bg-pending { background: rgba(245,158,11,0.15); color: var(--warn); }
.bg-completed, .bg-success { background: rgba(34,197,94,0.15); color: var(--success); }
.bg-failed, .bg-danger { background: rgba(239,68,68,0.15); color: var(--danger); }
.bg-reserved, .bg-primary { background: rgba(123,31,162,0.15); color: var(--primary); }
.bg-info { background: rgba(6,182,212,0.15); color: var(--info); }
.btn {
    padding: 0.3rem 0.65rem; border: 1px solid var(--border); border-radius: var(--radius);
    background: var(--surface); color: var(--text); cursor: pointer; font-size: 0.75rem;
    transition: all 0.15s;
}
.btn:hover { border-color: var(--primary); color: var(--primary); }
.btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
.btn-primary:hover { background: #9c27b0; }
.btn-danger { border-color: var(--danger); color: var(--danger); }
.btn-danger:hover { background: rgba(239,68,68,0.1); }
.btn-success { border-color: var(--success); color: var(--success); }
.btn-sm { padding: 0.2rem 0.5rem; font-size: 0.7rem; }
.empty { padding: 2rem; text-align: center; color: var(--muted); }
.input {
    background: var(--bg); color: var(--text); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 0.35rem 0.5rem; font-size: 0.8rem;
    font-family: var(--font);
}
.input:focus { outline: none; border-color: var(--primary); }
.input-mono { font-family: var(--mono); }
select.input { padding: 0.3rem; }
textarea.input { resize: vertical; font-family: var(--mono); }
.flex { display: flex; }
.gap-sm { gap: 0.5rem; }
.gap-md { gap: 1rem; }
.items-center { align-items: center; }
.justify-between { justify-content: space-between; }
.flex-1 { flex: 1; }
.p-sm { padding: 0.5rem; }
.p-md { padding: 1rem; }
.mb-sm { margin-bottom: 0.5rem; }
.text-sm { font-size: 0.75rem; }
.text-muted { color: var(--muted); }
.text-mono { font-family: var(--mono); }
.mail-item { padding: 0.6rem 0.75rem; border-bottom: 1px solid var(--border); cursor: pointer; }
.mail-item:hover { background: rgba(123,31,162,0.05); }
.mail-item.unread { border-left: 3px solid var(--primary); }
.msg-entry { padding: 0.4rem 0.75rem; border-bottom: 1px solid var(--border); font-size: 0.75rem; }
.msg-entry .cat {
    font-family: var(--mono); font-size: 0.65rem; padding: 0.1rem 0.35rem;
    border-radius: 0.25rem; background: rgba(123,31,162,0.15); color: var(--primary);
}
.msg-entry .time { color: var(--muted); font-size: 0.7rem; font-family: var(--mono); }
.level-error { color: var(--danger); }
.level-warn { color: var(--warn); }
.toolbar { display: flex; gap: 0.5rem; padding: 0.5rem 0.75rem; border-bottom: 1px solid var(--border); flex-wrap: wrap; align-items: center; }
.hidden { display: none; }
/* Chat panel */
.chat-container { display: flex; flex-direction: column; height: 500px; }
.chat-messages { flex: 1; overflow-y: auto; padding: 0.75rem; }
.chat-msg { margin-bottom: 0.75rem; padding: 0.5rem 0.75rem; border-radius: var(--radius); font-size: 0.8rem; max-width: 85%; }
.chat-user { background: var(--primary); color: #fff; margin-left: auto; }
.chat-bot { background: var(--bg); border: 1px solid var(--border); }
.chat-input-row { display: flex; gap: 0.5rem; padding: 0.75rem; border-top: 1px solid var(--border); }
.chat-input-row input { flex: 1; }
/* System cards */
.sys-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem; padding: 1rem; }
.sys-card { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 0.75rem; }
.sys-card .label { font-size: 0.7rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; }
.sys-card .value { font-size: 1.25rem; font-weight: 600; margin-top: 0.25rem; }
/* Request table */
.status-ok { color: var(--success); }
.status-err { color: var(--danger); }
.status-warn { color: var(--warn); }
/* Extension tags */
.ext-list { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.ext-tag { background: rgba(123, 31, 162, 0.15); color: #ce93d8; padding: 3px 10px; border-radius: 12px; font-size: 0.78em; }
/* System info cards (PHP fallback view) */
.card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1rem; margin-bottom: 0.75rem; }
.card h3 { color: #ce93d8; margin-bottom: 0.75rem; font-size: 0.95rem; }
.sys-item { display: flex; justify-content: space-between; padding: 0.4rem 0; border-bottom: 1px solid rgba(255,255,255,0.05); }
.sys-label { color: var(--muted); font-size: 0.82rem; }
.sys-value { font-weight: 500; font-size: 0.82rem; }
code, .mono { font-family: var(--mono); font-size: 0.82rem; }
</style>
</head>
<body>

<div class="dev-header">
    <img src="/images/logo.svg" style="width:1.5rem;height:1.5rem;cursor:pointer;opacity:0.7;transition:opacity 0.15s" title="Back to app" onclick="exitDevAdmin()" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'" alt="Tina4">
    <h1>Tina4 Dev Admin</h1>
    <span class="badge">DEV</span>
    <span style="margin-left:auto; font-size:0.75rem; color:var(--muted)" id="timestamp"></span>
</div>

<div class="dev-tabs">
    <button class="dev-tab active" onclick="showTab('routes', event)">Routes <span class="count" id="routes-count">0</span></button>
    <button class="dev-tab" onclick="showTab('queue', event)">Queue <span class="count" id="queue-count">0</span></button>
    <button class="dev-tab" onclick="showTab('mailbox', event)">Mailbox <span class="count" id="mailbox-count">0</span></button>
    <button class="dev-tab" onclick="showTab('messages', event)">Messages <span class="count" id="messages-count">0</span></button>
    <button class="dev-tab" onclick="showTab('database', event)">Database <span class="count" id="db-count">0</span></button>
    <button class="dev-tab" onclick="showTab('requests', event)">Requests <span class="count" id="req-count">0</span></button>
    <button class="dev-tab" onclick="showTab('errors', event)">Errors <span class="count" id="err-count">0</span></button>
    <button class="dev-tab" onclick="showTab('websockets', event)">WS <span class="count" id="ws-count">0</span></button>
    <button class="dev-tab" onclick="showTab('system', event)">System</button>
    <button class="dev-tab" onclick="showTab('tools', event)">Tools</button>
    <button class="dev-tab" onclick="showTab('connections', event)">Connections</button>
    <button class="dev-tab" onclick="showTab('chat', event)">Tina4</button>
</div>

<div class="dev-content">

<!-- Routes Panel -->
<div id="panel-routes" class="dev-panel">
    <div class="dev-panel-header">
        <h2>Registered Routes</h2>
        <button class="btn btn-sm" onclick="loadRoutes()">Refresh</button>
    </div>
    <table>
        <thead><tr><th>Method</th><th>Path</th><th>Auth</th><th>Handler</th></tr></thead>
        <tbody id="routes-body"></tbody>
    </table>
</div>

<!-- Queue Panel -->
<div id="panel-queue" class="dev-panel hidden">
    <div class="dev-panel-header">
        <h2>Queue Jobs</h2>
        <div class="flex gap-sm">
            <button class="btn btn-sm" onclick="loadQueue()">Refresh</button>
            <button class="btn btn-sm" onclick="retryQueue()">Retry Failed</button>
            <button class="btn btn-sm btn-danger" onclick="purgeQueue()">Purge Done</button>
        </div>
    </div>
    <div class="toolbar">
        <button class="btn btn-sm filter-btn active" onclick="filterQueue('', event)">All</button>
        <button class="btn btn-sm filter-btn" onclick="filterQueue('pending', event)">Pending <span id="q-pending">0</span></button>
        <button class="btn btn-sm filter-btn" onclick="filterQueue('completed', event)">Done <span id="q-completed">0</span></button>
        <button class="btn btn-sm filter-btn" onclick="filterQueue('failed', event)">Failed <span id="q-failed">0</span></button>
        <button class="btn btn-sm filter-btn" onclick="filterQueue('reserved', event)">Active <span id="q-reserved">0</span></button>
    </div>
    <table>
        <thead><tr><th>ID</th><th>Topic</th><th>Status</th><th>Attempts</th><th>Created</th><th>Data</th><th></th></tr></thead>
        <tbody id="queue-body"></tbody>
    </table>
    <div id="queue-empty" class="empty hidden">No queue jobs</div>
</div>

<!-- Mailbox Panel -->
<div id="panel-mailbox" class="dev-panel hidden">
    <div class="dev-panel-header">
        <h2>Dev Mailbox</h2>
        <div class="flex gap-sm">
            <button class="btn btn-sm" onclick="loadMailbox()">Refresh</button>
            <button class="btn btn-sm btn-primary" onclick="seedMailbox()">Seed 5</button>
            <button class="btn btn-sm btn-danger" onclick="clearMailbox()">Clear</button>
        </div>
    </div>
    <div class="toolbar">
        <button class="btn btn-sm filter-btn active" onclick="filterMailbox('', event)">All</button>
        <button class="btn btn-sm filter-btn" onclick="filterMailbox('inbox', event)">Inbox</button>
        <button class="btn btn-sm filter-btn" onclick="filterMailbox('outbox', event)">Outbox</button>
    </div>
    <div id="mailbox-list"></div>
    <div id="mail-detail" class="hidden p-md"></div>
</div>

<!-- Messages Panel -->
<div id="panel-messages" class="dev-panel hidden">
    <div class="dev-panel-header">
        <h2>Message Log</h2>
        <div class="flex gap-sm items-center">
            <input type="text" id="msg-search" class="input" placeholder="Search messages..." onkeydown="if(event.key==='Enter')searchMessages()">
            <button class="btn btn-sm" onclick="searchMessages()">Search</button>
            <button class="btn btn-sm" onclick="loadMessages()">All</button>
            <button class="btn btn-sm btn-danger" onclick="clearMessages()">Clear</button>
        </div>
    </div>
    <div id="messages-list"></div>
    <div id="messages-empty" class="empty">No messages logged</div>
</div>

<!-- Database Panel -->
<div id="panel-database" class="dev-panel hidden">
    <div class="dev-panel-header">
        <h2>Database</h2>
        <button class="btn btn-sm" onclick="loadTables()">Refresh</button>
    </div>
    <div class="flex gap-md p-md">
        <div class="flex-1">
            <div class="flex gap-sm items-center mb-sm">
                <select id="query-type" class="input">
                    <option value="sql">SQL</option>
                    <option value="graphql">GraphQL</option>
                </select>
                <button class="btn btn-sm btn-primary" onclick="runQuery()">Run</button>
                <span class="text-sm text-muted">Ctrl+Enter</span>
            </div>
            <textarea id="query-input" rows="4" placeholder="SELECT * FROM users LIMIT 20" class="input input-mono" style="width:100%"></textarea>
            <div id="query-error" class="hidden" style="color:var(--danger);font-size:0.75rem;margin-top:0.25rem"></div>
        </div>
        <div style="width:180px">
            <div class="text-sm text-muted" style="font-weight:600;margin-bottom:0.5rem">Tables</div>
            <div id="table-list" class="text-sm"></div>
            <div style="margin-top:0.75rem;border-top:1px solid var(--border);padding-top:0.75rem">
                <div class="text-sm text-muted" style="font-weight:600;margin-bottom:0.5rem">Seed Data</div>
                <select id="seed-table" class="input" style="width:100%;margin-bottom:0.25rem"><option value="">Pick table...</option></select>
                <div class="flex gap-sm items-center">
                    <input type="number" id="seed-count" class="input" value="10" min="1" max="1000" style="width:60px">
                    <button class="btn btn-sm btn-success" onclick="seedTable()">Seed</button>
                </div>
            </div>
        </div>
    </div>
    <div id="query-results" style="overflow-x:auto"></div>
</div>

<!-- Requests Panel -->
<div id="panel-requests" class="dev-panel hidden">
    <div class="dev-panel-header">
        <h2>Request Inspector</h2>
        <div class="flex gap-sm">
            <button class="btn btn-sm" onclick="loadRequests()">Refresh</button>
            <button class="btn btn-sm btn-danger" onclick="clearRequests()">Clear</button>
        </div>
    </div>
    <div id="req-stats" class="toolbar text-sm text-muted"></div>
    <table>
        <thead><tr><th>Time</th><th>Method</th><th>Path</th><th>Status</th><th>Duration</th><th>Size</th></tr></thead>
        <tbody id="req-body"></tbody>
    </table>
    <div id="req-empty" class="empty hidden">No requests captured</div>
</div>

<!-- Errors Panel -->
<div id="panel-errors" class="dev-panel hidden">
    <div class="dev-panel-header">
        <h2>Error Tracker</h2>
        <div class="flex gap-sm">
            <button class="btn btn-sm" onclick="loadErrors()">Refresh</button>
            <button class="btn btn-sm btn-danger" onclick="clearResolvedErrors()">Clear Resolved</button>
        </div>
    </div>
    <div id="errors-list"></div>
    <div id="errors-empty" class="empty">No errors tracked</div>
</div>

<!-- WebSocket Panel -->
<div id="panel-websockets" class="dev-panel hidden">
    <div class="dev-panel-header">
        <h2>WebSocket Connections</h2>
        <button class="btn btn-sm" onclick="loadWebSockets()">Refresh</button>
    </div>
    <table>
        <thead><tr><th>ID</th><th>Path</th><th>IP</th><th>Connected</th><th>Status</th><th></th></tr></thead>
        <tbody id="ws-body"></tbody>
    </table>
    <div id="ws-empty" class="empty">No active connections</div>
</div>

<!-- System Panel -->
<div id="panel-system" class="dev-panel hidden">
    <div class="dev-panel-header">
        <h2>System Overview</h2>
        <button class="btn btn-sm" onclick="loadSystem()">Refresh</button>
    </div>
    <div id="sys-cards" class="sys-grid"></div>
    <div id="sys-extensions" class="hidden"></div>
</div>

<!-- Tools Panel -->
<div id="panel-tools" class="dev-panel hidden">
    <div class="dev-panel-header">
        <h2>Developer Tools</h2>
    </div>
    <div class="sys-grid">
        <div class="sys-card" style="cursor:pointer" onclick="runTool('test')">
            <div class="label">Run Tests</div>
            <div style="font-size:0.8rem;margin-top:0.25rem">Execute the PHPUnit test suite</div>
        </div>
        <div class="sys-card" style="cursor:pointer" onclick="runTool('routes')">
            <div class="label">List Routes</div>
            <div style="font-size:0.8rem;margin-top:0.25rem">Show all registered routes with auth status</div>
        </div>
        <div class="sys-card" style="cursor:pointer" onclick="runTool('migrate')">
            <div class="label">Run Migrations</div>
            <div style="font-size:0.8rem;margin-top:0.25rem">Apply pending database migrations</div>
        </div>
        <div class="sys-card" style="cursor:pointer" onclick="runTool('seed')">
            <div class="label">Run Seeders</div>
            <div style="font-size:0.8rem;margin-top:0.25rem">Execute seed scripts</div>
        </div>
    </div>
    <div id="tool-output" class="hidden" style="margin:1rem">
        <div class="dev-panel-header">
            <h2 id="tool-title">Output</h2>
            <button class="btn btn-sm" onclick="document.getElementById('tool-output').classList.add('hidden')">Close</button>
        </div>
        <pre id="tool-result" style="padding:1rem;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);font-size:0.75rem;font-family:var(--mono);max-height:400px;overflow:auto;white-space:pre-wrap"></pre>
    </div>
</div>

<!-- Connections Panel -->
<div id="panel-connections" class="dev-panel hidden">
    <div class="dev-panel-header">
        <h2>Connection Builder</h2>
    </div>
    <div class="p-md">
        <div class="flex gap-md" style="flex-wrap:wrap">
            <div style="flex:1;min-width:300px">
                <div class="mb-sm">
                    <label class="text-sm text-muted" style="display:block;margin-bottom:0.25rem">Driver</label>
                    <select id="conn-driver" class="input" style="width:100%" onchange="connDriverChanged()">
                        <option value="sqlite">SQLite</option>
                        <option value="postgresql">PostgreSQL</option>
                        <option value="mysql">MySQL</option>
                        <option value="mssql">MSSQL</option>
                        <option value="firebird">Firebird</option>
                    </select>
                </div>
                <div class="mb-sm conn-server-field">
                    <label class="text-sm text-muted" style="display:block;margin-bottom:0.25rem">Host</label>
                    <input type="text" id="conn-host" class="input" style="width:100%" value="localhost" placeholder="localhost" oninput="updateConnectionUrl()">
                </div>
                <div class="mb-sm conn-server-field">
                    <label class="text-sm text-muted" style="display:block;margin-bottom:0.25rem">Port</label>
                    <input type="number" id="conn-port" class="input" style="width:100%" placeholder="5432" oninput="updateConnectionUrl()">
                </div>
                <div class="mb-sm">
                    <label class="text-sm text-muted" style="display:block;margin-bottom:0.25rem">Database</label>
                    <input type="text" id="conn-database" class="input" style="width:100%" placeholder="mydb" oninput="updateConnectionUrl()">
                </div>
                <div class="mb-sm conn-server-field">
                    <label class="text-sm text-muted" style="display:block;margin-bottom:0.25rem">Username</label>
                    <input type="text" id="conn-username" class="input" style="width:100%" placeholder="username">
                </div>
                <div class="mb-sm conn-server-field">
                    <label class="text-sm text-muted" style="display:block;margin-bottom:0.25rem">Password</label>
                    <input type="password" id="conn-password" class="input" style="width:100%" placeholder="password">
                </div>
                <div class="mb-sm">
                    <label class="text-sm text-muted" style="display:block;margin-bottom:0.25rem">Connection URL</label>
                    <input type="text" id="conn-url" class="input input-mono" style="width:100%" readonly>
                </div>
                <div class="flex gap-sm">
                    <button class="btn btn-primary" onclick="testConnection()">Test Connection</button>
                    <button class="btn btn-success" onclick="saveConnection()">Save to .env</button>
                </div>
            </div>
            <div style="width:300px">
                <div class="dev-panel" style="margin-bottom:1rem">
                    <div class="dev-panel-header"><h2>Test Result</h2></div>
                    <div id="conn-test-result" class="p-md text-sm text-muted">No test run yet</div>
                </div>
                <div class="dev-panel">
                    <div class="dev-panel-header"><h2>Current .env Values</h2></div>
                    <div id="conn-env-values" class="p-md text-sm text-muted">Loading...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function connDriverChanged() {
    var driver = document.getElementById('conn-driver').value;
    var ports = {postgresql: 5432, mysql: 3306, mssql: 1433, firebird: 3050};
    var isSqlite = (driver === 'sqlite');
    document.getElementById('conn-port').value = ports[driver] || '';
    var fields = document.querySelectorAll('.conn-server-field');
    for (var i = 0; i < fields.length; i++) {
        fields[i].style.display = isSqlite ? 'none' : '';
    }
    updateConnectionUrl();
}
function updateConnectionUrl() {
    var driver = document.getElementById('conn-driver').value;
    var host = document.getElementById('conn-host').value || 'localhost';
    var port = document.getElementById('conn-port').value;
    var database = document.getElementById('conn-database').value;
    if (driver === 'sqlite') {
        document.getElementById('conn-url').value = 'sqlite:///' + database;
    } else {
        document.getElementById('conn-url').value = driver + '://' + host + ':' + port + '/' + database;
    }
}
function testConnection() {
    var url = document.getElementById('conn-url').value;
    var username = document.getElementById('conn-username').value;
    var password = document.getElementById('conn-password').value;
    var el = document.getElementById('conn-test-result');
    el.innerHTML = '<span class="text-muted">Testing...</span>';
    fetch('/__dev/api/connections/test', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({url: url, username: username, password: password})
    }).then(function(r){return r.json()}).then(function(data) {
        if (data.success) {
            el.innerHTML = '<div style="color:var(--success);font-weight:600;margin-bottom:0.5rem">&#10004; Connected</div>' +
                '<div class="text-sm">Version: ' + (data.version || 'N/A') + '</div>' +
                '<div class="text-sm">Tables: ' + (data.tables !== undefined ? data.tables : 'N/A') + '</div>';
        } else {
            el.innerHTML = '<div style="color:var(--danger);font-weight:600;margin-bottom:0.5rem">&#10008; Failed</div>' +
                '<div class="text-sm" style="color:var(--danger)">' + (data.error || 'Unknown error') + '</div>';
        }
    }).catch(function(e) {
        el.innerHTML = '<div style="color:var(--danger)">Error: ' + e.message + '</div>';
    });
}
function saveConnection() {
    var url = document.getElementById('conn-url').value;
    var username = document.getElementById('conn-username').value;
    var password = document.getElementById('conn-password').value;
    if (!url) { alert('Please build a connection URL first'); return; }
    fetch('/__dev/api/connections/save', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({url: url, username: username, password: password})
    }).then(function(r){return r.json()}).then(function(data) {
        if (data.success) {
            alert('Connection saved to .env');
            loadConnectionEnv();
        } else {
            alert('Save failed: ' + (data.error || 'Unknown error'));
        }
    }).catch(function(e) { alert('Error: ' + e.message); });
}
function loadConnectionEnv() {
    fetch('/__dev/api/connections').then(function(r){return r.json()}).then(function(data) {
        var el = document.getElementById('conn-env-values');
        el.innerHTML = '<div class="mb-sm"><span class="text-muted">DATABASE_URL:</span> <code>' + (data.url || '<em>not set</em>') + '</code></div>' +
            '<div class="mb-sm"><span class="text-muted">DATABASE_USERNAME:</span> <code>' + (data.username || '<em>not set</em>') + '</code></div>' +
            '<div><span class="text-muted">DATABASE_PASSWORD:</span> <code>' + (data.password || '<em>not set</em>') + '</code></div>';
    }).catch(function() {
        document.getElementById('conn-env-values').innerHTML = '<span class="text-muted">Could not load .env values</span>';
    });
}
document.addEventListener('DOMContentLoaded', function() {
    var connTab = document.querySelector('[onclick*="connections"]');
    if (connTab) {
        connTab.addEventListener('click', function() { loadConnectionEnv(); }, {once: true});
    }
});
</script>

<!-- Chat Panel (Tina4) -->
<div id="panel-chat" class="dev-panel hidden">
    <div class="dev-panel-header">
        <h2>Tina4</h2>
        <div class="flex gap-sm items-center">
            <select id="ai-provider" class="input" style="width:120px">
                <option value="anthropic">Claude</option>
                <option value="openai">OpenAI</option>
            </select>
            <input type="password" id="ai-key" class="input" placeholder="Paste API key..." style="width:250px">
            <button class="btn btn-sm btn-primary" onclick="setAiKey()">Set Key</button>
            <span class="text-sm text-muted" id="ai-status">No key set</span>
        </div>
    </div>
    <div class="chat-container">
        <div class="chat-messages" id="chat-messages">
            <div class="chat-msg chat-bot">Hi! I'm Tina4. Ask me about routes, ORM, database, queues, templates, auth, or any Tina4 feature.</div>
        </div>
        <div class="chat-input-row">
            <input type="text" id="chat-input" class="input" placeholder="Ask Tina4..." onkeydown="if(event.key==='Enter')sendChat()">
            <button class="btn btn-primary" onclick="sendChat()">Send</button>
        </div>
    </div>
</div>

</div>

<script src="/js/tina4-dev-admin.min.js"></script>
<script>
// Self-diagnostic — detect if the external JS failed to load
(function() {
    if (typeof showTab !== 'function') {
        var banner = document.createElement('div');
        banner.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:#ef4444;color:#fff;padding:0.75rem 1rem;font-family:system-ui;font-size:0.85rem;text-align:center';
        banner.innerHTML = '<strong>Dev Admin Error:</strong> tina4-dev-admin.min.js failed to load. Check that /js/tina4-dev-admin.min.js is accessible.';
        document.body.insertBefore(banner, document.body.firstChild);
    }
})();
</script>
</body>
</html>
HTML;
        return $html;
    }
}


/**
 * In-memory message log for development mode.
 *
 * Captures categorized, levelled messages from anywhere in the application,
 * viewable in the /__dev dashboard. Messages are stored in a static array
 * and do not persist across requests (suitable for long-running servers).
 */
class MessageLog
{
    /** @var array<int, array{id: string, timestamp: string, category: string, level: string, message: string}> */
    private static array $messages = [];

    /** @var int Maximum messages to retain */
    private static int $maxMessages = 500;

    /**
     * Log a message to the dev admin tracker.
     *
     * @param string $category Category (e.g. "queue", "auth", "route", "database")
     * @param string $level    Severity: "info", "warn", "error", "debug"
     * @param string $message  Human-readable message text
     */
    public static function log(string $category, string $level, string $message): void
    {
        $entry = [
            'id' => (string) (int) (microtime(true) * 1000) . '_' . count(self::$messages),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'category' => $category,
            'level' => $level,
            'message' => $message,
        ];

        self::$messages[] = $entry;

        // Trim old messages
        if (count(self::$messages) > self::$maxMessages) {
            self::$messages = array_slice(self::$messages, -self::$maxMessages);
        }
    }

    /**
     * Get logged messages, newest first.
     *
     * @param string|null $category Filter by category (null for all)
     * @return array<int, array>
     */
    public static function get(?string $category = null): array
    {
        $msgs = self::$messages;

        if ($category !== null) {
            $msgs = array_filter($msgs, fn(array $m) => $m['category'] === $category);
        }

        return array_values(array_reverse($msgs));
    }

    /**
     * Clear messages.
     *
     * @param string|null $category Clear only a specific category (null for all)
     */
    public static function clear(?string $category = null): void
    {
        if ($category !== null) {
            self::$messages = array_values(
                array_filter(self::$messages, fn(array $m) => $m['category'] !== $category)
            );
        } else {
            self::$messages = [];
        }
    }

    /**
     * Get message counts by category.
     *
     * @return array<string, int> Category => count, plus "total"
     */
    public static function count(): array
    {
        $counts = [];
        foreach (self::$messages as $m) {
            $cat = $m['category'];
            $counts[$cat] = ($counts[$cat] ?? 0) + 1;
        }
        $counts['total'] = count(self::$messages);
        return $counts;
    }

    /**
     * Reset message storage (for testing).
     */
    public static function reset(): void
    {
        self::$messages = [];
    }
}


/**
 * Captures HTTP request metadata for the developer dashboard.
 *
 * Records method, path, status code, and timing for recent requests,
 * providing an at-a-glance view of application traffic in /__dev.
 */
class RequestInspector
{
    /** @var array<int, array{id: string, timestamp: string, method: string, path: string, status: int, duration_ms: float}> */
    private static array $requests = [];

    /** @var int Maximum requests to retain */
    private static int $maxRequests = 200;

    /**
     * Record a completed request.
     *
     * @param string $method   HTTP method (GET, POST, etc.)
     * @param string $path     Request path
     * @param int    $status   HTTP status code returned
     * @param float  $duration Duration in milliseconds
     */
    public static function capture(string $method, string $path, int $status, float $duration): void
    {
        $entry = [
            'id' => (string) (int) (microtime(true) * 1000) . '_' . count(self::$requests),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'method' => strtoupper($method),
            'path' => $path,
            'status' => $status,
            'duration_ms' => round($duration, 2),
        ];

        self::$requests[] = $entry;

        if (count(self::$requests) > self::$maxRequests) {
            self::$requests = array_slice(self::$requests, -self::$maxRequests);
        }
    }

    /**
     * Get captured requests, newest first.
     *
     * @param int $limit Maximum number of requests to return
     * @return array<int, array>
     */
    public static function get(int $limit = 50): array
    {
        $reversed = array_reverse(self::$requests);
        return array_slice($reversed, 0, $limit);
    }

    /**
     * Get aggregate request statistics.
     *
     * @return array{total: int, avg_ms: float, errors: int, slowest_ms: float}
     */
    public static function stats(): array
    {
        if (empty(self::$requests)) {
            return ['total' => 0, 'avg_ms' => 0, 'errors' => 0, 'slowest_ms' => 0];
        }

        $durations = array_column(self::$requests, 'duration_ms');
        $errors = count(array_filter(self::$requests, fn(array $r) => $r['status'] >= 400));

        return [
            'total' => count(self::$requests),
            'avg_ms' => round(array_sum($durations) / count($durations), 2),
            'errors' => $errors,
            'slowest_ms' => round(max($durations), 2),
        ];
    }

    /**
     * Clear all captured requests.
     */
    public static function clear(): void
    {
        self::$requests = [];
    }

    /**
     * Reset request storage (for testing).
     */
    public static function reset(): void
    {
        self::$requests = [];
    }
}


/**
 * Tracks PHP errors and uncaught exceptions for the developer dashboard Error Tracker.
 *
 * Errors are persisted to a temp file keyed by project directory, so they survive
 * across requests (PHP built-in server spawns a new process per request).
 *
 * Duplicate errors (same type + message + file + line) are grouped into a single
 * entry with a running count and last_seen timestamp.
 */
class ErrorTracker
{
    /** @var string Resolved path to the JSON store file */
    private static string $storePath = '';

    /** @var array<string, array>|null In-memory copy loaded on first use */
    private static ?array $errors = null;

    /** @var int Maximum error entries to retain */
    private static int $maxErrors = 200;

    /** @var bool Whether handlers are already registered in this process */
    private static bool $registered = false;

    // ---------------------------------------------------------------------------
    // Storage helpers
    // ---------------------------------------------------------------------------

    private static function getStorePath(): string
    {
        if (self::$storePath === '') {
            self::$storePath = sys_get_temp_dir()
                . '/tina4_dev_errors_' . md5(getcwd()) . '.json';
        }
        return self::$storePath;
    }

    private static function load(): void
    {
        if (self::$errors !== null) {
            return;
        }
        $path = self::getStorePath();
        if (file_exists($path)) {
            $raw = @file_get_contents($path);
            $data = $raw !== false ? json_decode($raw, true) : null;
            // The file is stored as a JSON array; re-key by fingerprint
            if (is_array($data)) {
                self::$errors = [];
                foreach ($data as $entry) {
                    if (isset($entry['id'])) {
                        self::$errors[$entry['id']] = $entry;
                    }
                }
            } else {
                self::$errors = [];
            }
        } else {
            self::$errors = [];
        }
    }

    private static function save(): void
    {
        // Trim to max, keeping newest (highest last_seen)
        if (count(self::$errors) > self::$maxErrors) {
            $sorted = array_values(self::$errors);
            usort($sorted, fn($a, $b) => strcmp($b['last_seen'], $a['last_seen']));
            self::$errors = [];
            foreach (array_slice($sorted, 0, self::$maxErrors) as $entry) {
                self::$errors[$entry['id']] = $entry;
            }
        }

        @file_put_contents(
            self::getStorePath(),
            json_encode(array_values(self::$errors), JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    // ---------------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------------

    /**
     * Capture a PHP error or exception into the tracker.
     *
     * Duplicate errors (same type + message + file + line) are de-duplicated:
     * the count increments and the entry is re-opened if it was resolved.
     */
    public static function capture(
        string $errorType,
        string $message,
        string $traceback = '',
        string $file = '',
        int $line = 0
    ): void {
        self::load();

        $fingerprint = md5($errorType . '|' . $message . '|' . $file . '|' . $line);
        $now = gmdate('Y-m-d\TH:i:s\Z');

        if (isset(self::$errors[$fingerprint])) {
            self::$errors[$fingerprint]['count']++;
            self::$errors[$fingerprint]['last_seen'] = $now;
            self::$errors[$fingerprint]['resolved'] = false; // Re-open resolved duplicates
        } else {
            self::$errors[$fingerprint] = [
                'id'         => $fingerprint,
                'error_type' => $errorType,
                'message'    => $message,
                'traceback'  => $traceback,
                'file'       => $file,
                'line'       => $line,
                'first_seen' => $now,
                'last_seen'  => $now,
                'count'      => 1,
                'resolved'   => false,
            ];
        }

        self::save();
    }

    /**
     * Get tracked errors, newest last_seen first.
     *
     * @param bool $includeResolved Whether to include resolved errors
     * @return array<int, array>
     */
    public static function get(bool $includeResolved = true): array
    {
        self::load();
        $errors = array_values(self::$errors);
        if (!$includeResolved) {
            $errors = array_values(array_filter($errors, fn($e) => !$e['resolved']));
        }
        usort($errors, fn($a, $b) => strcmp($b['last_seen'], $a['last_seen']));
        return $errors;
    }

    /**
     * Count unresolved errors.
     */
    public static function unresolvedCount(): int
    {
        self::load();
        return count(array_filter(self::$errors, fn($e) => !$e['resolved']));
    }

    /**
     * Mark a single error as resolved.
     *
     * @param string $id Error fingerprint
     * @return bool True if found and updated
     */
    public static function resolve(string $id): bool
    {
        self::load();
        if (isset(self::$errors[$id])) {
            self::$errors[$id]['resolved'] = true;
            self::save();
            return true;
        }
        return false;
    }

    /**
     * Remove all resolved errors from the store.
     */
    public static function clearResolved(): void
    {
        self::load();
        self::$errors = array_filter(self::$errors, fn($e) => !$e['resolved']);
        self::save();
    }

    /**
     * Remove all tracked errors.
     */
    public static function clearAll(): void
    {
        self::$errors = [];
        self::save();
    }

    /**
     * Register PHP error and exception handlers to feed the tracker.
     *
     * Safe to call multiple times — only registers once per process.
     * Chains to any previously-registered handlers so ErrorOverlay still works.
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        // Capture uncaught exceptions
        $prevException = set_exception_handler(
            function (\Throwable $e) use (&$prevException): void {
                self::capture(
                    get_class($e),
                    $e->getMessage(),
                    self::formatTrace($e),
                    $e->getFile(),
                    $e->getLine()
                );
                if ($prevException !== null) {
                    ($prevException)($e);
                }
            }
        );

        // Capture PHP errors at ERROR/WARNING level (avoid noise from notices/deprecations)
        $prevError = set_error_handler(
            function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use (&$prevError): bool {
                $capture = $errno & (
                    E_ERROR | E_WARNING | E_USER_ERROR | E_USER_WARNING | E_RECOVERABLE_ERROR
                );
                if ($capture) {
                    $typeMap = [
                        E_ERROR             => 'E_ERROR',
                        E_WARNING           => 'E_WARNING',
                        E_USER_ERROR        => 'E_USER_ERROR',
                        E_USER_WARNING      => 'E_USER_WARNING',
                        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
                    ];
                    $type = $typeMap[$errno] ?? "E_{$errno}";
                    self::capture($type, $errstr, '', $errfile, $errline);
                }
                if ($prevError !== null) {
                    return (bool) ($prevError)($errno, $errstr, $errfile, $errline);
                }
                return false;
            }
        );
    }

    /**
     * Reset the in-memory cache and delete the store file (for testing).
     */
    public static function reset(): void
    {
        self::$errors = [];
        self::$registered = false;
        $path = self::getStorePath();
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    // ---------------------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------------------

    private static function formatTrace(\Throwable $e): string
    {
        $lines = [
            get_class($e) . ': ' . $e->getMessage(),
            'in ' . $e->getFile() . ':' . $e->getLine(),
        ];
        foreach ($e->getTrace() as $i => $frame) {
            $loc  = ($frame['file'] ?? 'unknown') . ':' . ($frame['line'] ?? 0);
            $fn   = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
            $lines[] = "  #{$i} {$loc} {$fn}()";
        }
        return implode("\n", $lines);
    }
}
