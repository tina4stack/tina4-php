<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
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
     * When true, renderToolbar() omits the hot-reload WebSocket/polling script.
     * Set by Server before dispatching requests arriving on the AI port.
     */
    public static bool $suppressReload = false;

    /**
     * When true, Server broadcasts a reload signal on the next tick.
     * Set by POST /__dev/api/reload (called by the Rust CLI on file changes).
     */
    public static bool $pendingReload = false;

    /**
     * Register all /__dev routes. Call from App::start() when in development mode.
     */
    public static function register(): void
    {
        // Register PHP error/exception handlers so they are captured in the Error Tracker
        ErrorTracker::register();

        // Serve the SPA JS bundle from framework's built-in assets
        Router::get('/__dev/js/tina4-dev-admin.min.js', function (Request $request, Response $response) {
            $jsPath = __DIR__ . '/../src/public/js/tina4-dev-admin.min.js';
            if (file_exists($jsPath)) {
                return $response->header('Content-Type', 'application/javascript')->html(file_get_contents($jsPath));
            }
            return $response->text('tina4-dev-admin.min.js not found', 404);
        });

        // Dashboard — unified SPA
        $spa = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Tina4 Dev Admin</title></head><body><div id="app" data-framework="php" data-color="#8b5cf6"></div><script src="/__dev/js/tina4-dev-admin.min.js"></script></body></html>';
        Router::get('/__dev', function (Request $request, Response $response) use ($spa) {
            return $response->html($spa);
        });
        Router::get('/__dev/', function (Request $request, Response $response) use ($spa) {
            return $response->html($spa);
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

        // API: Trigger browser reload — called by the Rust CLI when it detects file changes.
        // Sets a flag that Server reads on its next tick to broadcast via WebSocket,
        // and touches a sentinel file so the polling fallback also picks it up.
        Router::post('/__dev/api/reload', function (Request $request, Response $response) {
            // Touch a sentinel so the mtime polling fallback detects the change
            $sentinel = getcwd() . '/src/.reload_sentinel';
            @touch($sentinel);

            // Set a static flag for Server to pick up on the next tick
            self::$pendingReload = true;

            $file = $request->body['file'] ?? '';
            $type = $request->body['type'] ?? 'reload';
            Log::info("External reload trigger: {$type}" . ($file ? " ({$file})" : ''));

            return $response->json(['ok' => true, 'type' => $type]);
        });

        // API: Version check — proxy to avoid CORS issues with registry APIs
        Router::get('/__dev/api/version-check', function (Request $request, Response $response) {
            $current = App::$VERSION;
            $latest = $current;
            try {
                $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
                $json = @file_get_contents('https://repo.packagist.org/p2/tina4stack/tina4php.json', false, $ctx);
                if ($json) {
                    $data = json_decode($json, true);
                    $packages = $data['packages']['tina4stack/tina4php'] ?? [];
                    $versions = array_column($packages, 'version');
                    // Filter: stable only (no dev, no rc, no x)
                    $stable = array_filter($versions, fn($v) => preg_match('/^v?\d+\.\d+\.\d+$/', $v));
                    $stable = array_map(fn($v) => ltrim($v, 'v'), $stable);
                    usort($stable, 'version_compare');
                    $latest = end($stable) ?: $current;
                }
            } catch (\Throwable) {
                // Offline or timeout — return current as latest
            }
            return $response->json(['current' => $current, 'latest' => $latest]);
        });

        // API: System status (lightweight)
        Router::get('/__dev/api/status', function (Request $request, Response $response) {
            $dbTableCount = 0;
            try {
                $db = App::getDatabase();
                if ($db !== null) {
                    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
                    $dbTableCount = count($tables);
                }
            } catch (\Throwable) {
            }
            return $response->json([
                'php_version' => PHP_VERSION,
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'uptime_seconds' => round(microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)), 2),
                'framework' => 'tina4-php',
                'version' => App::$VERSION,
                'sapi' => PHP_SAPI,
                'route_count' => Router::count(),
                'db_tables' => $dbTableCount,
                'request_stats' => RequestInspector::stats(),
                'message_counts' => MessageLog::count(),
                'health' => ['unresolved' => ErrorTracker::unresolvedCount()],
            ]);
        });

        // API: List all registered routes
        Router::get('/__dev/api/routes', function (Request $request, Response $response) {
            $internalPrefixes = ['/__dev', '/health', '/swagger'];
            $allRoutes = Router::getRoutes();
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

        // API: GraphQL schema introspection — auto-discovers ORM models
        Router::get('/__dev/api/graphql/schema', function (Request $request, Response $response) {
            try {
                $gql = new GraphQL();

                // Auto-discover ORM files from src/orm/ to ensure they're loaded
                $ormDir = getcwd() . '/src/orm';
                if (is_dir($ormDir)) {
                    foreach (glob($ormDir . '/*.php') as $file) {
                        require_once $file;
                    }
                }

                // Register all loaded ORM subclasses
                foreach (get_declared_classes() as $class) {
                    if (is_subclass_of($class, ORM::class) && !(new \ReflectionClass($class))->isAbstract()) {
                        try {
                            $gql->fromOrm(new $class());
                        } catch (\Throwable $e) {
                            // Skip models that can't be introspected (no DB, etc.)
                        }
                    }
                }

                return $response->json([
                    'schema' => $gql->introspect(),
                    'sdl' => $gql->schemaSdl(),
                ]);
            } catch (\Throwable $e) {
                return $response->json(['error' => $e->getMessage()], 400);
            }
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

        // API: Run a database query (supports multi-statement SQL)
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

            // SQL mode — split multiple statements on semicolons
            try {
                $db = App::getDatabase();
                if ($db === null) {
                    return $response->json(['error' => 'No database configured'], 400);
                }

                $statements = array_filter(array_map('trim', explode(';', $query)));

                if (count($statements) === 1) {
                    $stmt = $statements[0];
                    $firstWord = strtoupper(strtok($stmt, " \t\n\r"));
                    $isRead = in_array($firstWord, ['SELECT', 'PRAGMA', 'EXPLAIN', 'SHOW', 'DESCRIBE'], true);

                    if ($isRead) {
                        $result = $db->query($stmt);
                        return $response->json([
                            'columns' => !empty($result) ? array_keys($result[0]) : [],
                            'rows' => $result,
                            'count' => count($result),
                        ]);
                    }
                }

                // Execute all statements (single write or multi-statement batch) in transaction
                $totalAffected = 0;
                $db->startTransaction();
                try {
                    foreach ($statements as $stmt) {
                        $ok = $db->execute($stmt);
                        if ($ok === false) {
                            $err = method_exists($db, 'error') ? $db->error() : 'Statement failed';
                            throw new \RuntimeException($err ?: 'Statement failed');
                        }
                        $totalAffected++;
                    }
                    $db->commit();
                } catch (\Throwable $e) {
                    $db->rollback();
                    return $response->json(['error' => $e->getMessage()], 400);
                }

                return $response->json(['affected' => $totalAffected, 'success' => true]);
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
                    $routes = Router::getRoutes();
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

        // API: Metrics — quick metrics
        Router::get('/__dev/api/metrics', function (Request $request, Response $response) {
            return $response->json(Metrics::quickMetrics());
        });

        // API: Metrics — full analysis
        Router::get('/__dev/api/metrics/full', function (Request $request, Response $response) {
            return $response->json(Metrics::fullAnalysis());
        });

        // API: Metrics — file detail
        Router::get('/__dev/api/metrics/file', function (Request $request, Response $response) {
            $path = $request->query['path'] ?? '';
            return $response->json(Metrics::fileDetail($path));
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
        (Router::post('/__dev/api/gallery/deploy', function (Request $request, Response $response) {
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
        }))->noAuth();

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
                    'version' => App::$VERSION,
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

    // Legacy renderDashboard() removed — UI served from tina4-dev-admin.min.js

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
        $version = App::$VERSION;
        $phpVersion = PHP_VERSION;
        $safeMethod = htmlspecialchars($method, ENT_QUOTES);
        $safePath = htmlspecialchars($path, ENT_QUOTES);
        $safePattern = htmlspecialchars($matchedPattern, ENT_QUOTES);
        $safeRequestId = htmlspecialchars($requestId, ENT_QUOTES);

        $toolbar = <<<HTML
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
    <a href="#" onclick="(function(e){e.preventDefault();var p=document.getElementById('tina4-dev-panel');if(p){p.style.display=p.style.display==='none'?'block':'none';return;}var c=document.createElement('div');c.id='tina4-dev-panel';c.style.cssText='position:fixed;top:3rem;left:0;right:0;bottom:2rem;z-index:99998;transition:all 0.2s';var f=document.createElement('iframe');f.src='/__dev';f.style.cssText='width:100%;height:100%;border:1px solid #7b1fa2;border-radius:0.5rem;box-shadow:0 8px 32px rgba(0,0,0,0.5);background:#0f172a';c.appendChild(f);document.body.appendChild(c);})(event)" style="color:#ef9a9a;margin-left:auto;text-decoration:none;cursor:pointer;">Dashboard &#8599;</a>
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
    fetch('/__dev/api/version-check')
    .then(function(r){return r.json()})
    .then(function(d){
        var latest=d.latest;
        var current=d.current;
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
            var isAhead=false;
            if(!isNewer){for(var i=0;i<Math.max(cParts.length,lParts.length);i++){var c2=cParts[i]||0,l2=lParts[i]||0;if(c2>l2){isAhead=true;break;}if(c2<l2)break;}}
            if(isNewer){
                var breaking=(lParts[0]!==cParts[0]||lParts[1]!==cParts[1]);
                el.innerHTML='Latest: <strong style="color:#f9e2af;">v'+latest+'</strong>';
                if(breaking){
                    el.innerHTML+='<div style="color:#f38ba8;margin-top:6px;">&#9888; Major/minor version change &mdash; check the <a href="https://github.com/tina4stack/tina4-php/releases" target="_blank" style="color:#89b4fa;">changelog</a> for breaking changes before upgrading.</div>';
                }else{
                    el.innerHTML+='<div style="color:#f9e2af;margin-top:6px;">Patch update available. Run: <code style="background:#313244;padding:2px 6px;border-radius:3px;">composer update tina4stack/tina4-php</code></div>';
                }
            }else if(isAhead){
                el.innerHTML='You are running <strong style="color:#cba6f7;">v'+current+'</strong> (ahead of Packagist <strong>v'+latest+'</strong> &mdash; not yet published).';
                el.style.color='#cba6f7';
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
HTML;

        if (!self::$suppressReload) {
            $toolbar .= <<<'RELOADSCRIPT'
<script>
(function(){var ws,delay=1000,maxDelay=30000,fails=0,mt=0;function tryWs(){var p=location.protocol==='https:'?'wss:':'ws:';try{ws=new WebSocket(p+'//'+location.host+'/__dev_reload');ws.onopen=function(){delay=1000;fails=0;};ws.onmessage=function(e){try{var d=JSON.parse(e.data);if(d.type==='reload')location.reload();if(d.type==='css')document.querySelectorAll('link[rel=stylesheet]').forEach(function(l){l.href=l.href.split('?')[0]+'?v='+Date.now();});}catch(x){}};ws.onclose=function(){if(fails<3){delay=Math.min(delay*2,maxDelay);fails++;setTimeout(tryWs,delay);}else{startPolling();}};ws.onerror=function(){ws.close();};}catch(e){startPolling();}}function startPolling(){var iv=parseInt('3000')||3000;var dbt=null;var cssExts=['.css','.scss'];function apply(d){var f=d.file||'';var isCss=cssExts.some(function(e){return f.endsWith(e)});if(isCss){document.querySelectorAll('link[rel=stylesheet]').forEach(function(l){l.href=l.href.split('?')[0]+'?v='+d.mtime;});}else{location.reload();}}setInterval(function(){fetch('/__dev/api/mtime').then(function(r){return r.json();}).then(function(d){if(d.mtime&&d.mtime>mt){mt=d.mtime;if(dbt)clearTimeout(dbt);dbt=setTimeout(function(){apply(d);},500);}}).catch(function(){});},iv);}tryWs();})();
</script>
RELOADSCRIPT;
        }

        return $toolbar;
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

    /** @var int Number of handler pairs pushed by register() */
    private static int $handlerDepth = 0;

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
     * Health summary — are there unresolved errors?
     *
     * @return array{healthy: bool, total: int, unresolved: int, resolved: int}
     */
    public static function health(): array
    {
        self::load();
        $total = count(self::$errors);
        $resolved = count(array_filter(self::$errors, fn($e) => $e['resolved']));
        $unresolved = $total - $resolved;
        return [
            'healthy' => $unresolved === 0,
            'total' => $total,
            'unresolved' => $unresolved,
            'resolved' => $resolved,
        ];
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
        self::$handlerDepth++;

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
        // Restore handlers that register() pushed
        while (self::$handlerDepth > 0) {
            restore_error_handler();
            restore_exception_handler();
            self::$handlerDepth--;
        }
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
