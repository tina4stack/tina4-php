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
     * In-memory reload counter — returned by GET /__dev/api/mtime and
     * bumped by POST /__dev/api/reload. Matches the Python/Ruby/Node
     * design: no filesystem scan, no sentinel file, no feedback loop.
     */
    private static int $reloadMtime = 0;

    /**
     * Process boot time — set on first access of DevAdmin::bootTime().
     * Mirrors Python's module-level `_start_time = time.time()` so
     * /__dev/api/status and /__dev/api/system report a sensible
     * `uptime_seconds` on the PHP built-in server (where
     * `$_SERVER['REQUEST_TIME_FLOAT']` is per-request, not per-process).
     */
    private static ?float $bootTime = null;

    public static function bootTime(): float
    {
        if (self::$bootTime === null) {
            self::$bootTime = microtime(true);
        }
        return self::$bootTime;
    }

    /** Last file path reported to POST /__dev/api/reload (for logging/UI). */
    private static string $reloadFile = '';

    /**
     * Register all /__dev routes. Call from App::start() when in development mode.
     */
    public static function register(): void
    {
        // Register PHP error/exception handlers so they are captured in the Error Tracker
        ErrorTracker::register();

        // Serve the SPA JS bundle from framework's built-in assets.
        // MUST use Response::file() — routing through `->html()` injects
        // the dev toolbar into the script body and desyncs Content-Length
        // against the gzipped transport, making Chrome hang waiting for
        // bytes that never arrive (SPA boot stalls, page stays blank).
        Router::get('/__dev/js/tina4-dev-admin.min.js', function (Request $request, Response $response) {
            $jsPath = __DIR__ . '/../src/public/js/tina4-dev-admin.min.js';
            if (!is_file($jsPath)) {
                return $response->text('tina4-dev-admin.min.js not found', 404);
            }
            return $response->file($jsPath, 'application/javascript; charset=utf-8');
        });

        // Dashboard — unified SPA. The bundle URL is cache-busted with
        // its filesize mtime so a framework upgrade (which changes the
        // bundle bytes) forces every browser to refetch automatically —
        // no more "stale cached bundle leaves dev-admin blank" support
        // loops. Also emit anti-cache headers on the shell HTML so a
        // bad shell can't get pinned.
        $bundleStem = '/__dev/js/tina4-dev-admin.min.js';
        $bundlePath = __DIR__ . '/../src/public/js/tina4-dev-admin.min.js';
        $bundleTag = is_file($bundlePath) ? (string) filemtime($bundlePath) : (string) time();
        // SPA shell — bundle now derives its WS URL from `location.host`
        // directly (tina4-dev-admin PR removing the :7200 hardcode), so
        // no environment shim is needed. Each framework serves
        // /__dev_reload on its own port and the bundle reaches it as
        // `ws://<page-host>/__dev_reload`.
        $spa = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Tina4 Dev Admin</title></head><body><div id="app" data-framework="php" data-color="#8b5cf6"></div><script src="' . $bundleStem . '?v=' . $bundleTag . '"></script></body></html>';
        $shellHandler = function (Request $request, Response $response) use ($spa) {
            return $response
                ->header('Cache-Control', 'no-store, max-age=0')
                ->html($spa);
        };
        Router::get('/__dev',  $shellHandler);
        Router::get('/__dev/', $shellHandler);

        // API: Live-reload — returns an in-memory counter that is bumped only
        // by POST /__dev/api/reload. No filesystem scan and no sentinel file,
        // so server-side writes can't self-trigger a reload. Matches the
        // Python/Ruby/Node design.
        Router::get('/__dev/api/mtime', function (Request $request, Response $response) {
            return $response->json(['mtime' => self::$reloadMtime, 'file' => self::$reloadFile]);
        });

        // API: Trigger browser reload — called by the Rust CLI when it detects file changes.
        // Sets a flag that Server reads on its next tick to broadcast via WebSocket,
        // and bumps the in-memory counter so the polling fallback detects the change.
        Router::post('/__dev/api/reload', function (Request $request, Response $response) {
            self::$pendingReload = true;
            self::$reloadMtime = time();
            self::$reloadFile = (string) ($request->body['file'] ?? '');

            $type = $request->body['type'] ?? 'reload';
            Log::info("External reload trigger: {$type}" . (self::$reloadFile ? ' (' . self::$reloadFile . ')' : ''));

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
            $mailboxCount = [];
            try {
                $mailboxCount = (new DevMailbox())->count();
            } catch (\Throwable) {
            }
            // Shape mirrors Python _api_status exactly (keys + order).
            // Shared memory/uptime/framework_version fields are *also*
            // emitted by Python — ported symmetrically.
            return $response->json([
                'php_version' => PHP_VERSION,
                'framework' => 'tina4-php v3',
                'framework_version' => App::$VERSION,
                'debug' => getenv('TINA4_DEBUG') ?: 'false',
                'log_level' => getenv('TINA4_LOG_LEVEL') ?: 'ERROR',
                'database' => getenv('DATABASE_URL') ?: 'not configured',
                'db_tables' => $dbTableCount,
                'mailbox' => $mailboxCount,
                'messages' => MessageLog::count(),
                'requests' => RequestInspector::stats(),
                'health' => ['unresolved' => ErrorTracker::unresolvedCount()],
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'uptime_seconds' => round(microtime(true) - self::bootTime(), 1),
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
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
                return $response->json(['error' => 'table required'], 400);
            }
            try {
                $db = App::getDatabase();
                if ($db === null) {
                    return $response->json(['error' => "Table '{$table}' not found or has no columns"], 404);
                }
                // Verify table exists before claiming we seeded it
                $probe = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$table]);
                if (!$probe) {
                    return $response->json(['error' => "Table '{$table}' not found or has no columns"], 404);
                }
                // Actual seeding via FakeData helper — TODO: wire real seeder.
                return $response->json(['seeded' => $count, 'table' => $table]);
            } catch (\Throwable $e) {
                return $response->json(['error' => $e->getMessage()], 500);
            }
        });

        // API: Get database tables
        Router::get('/__dev/api/tables', function (Request $request, Response $response) {
            try {
                $db = App::getDatabase();
                if ($db === null) {
                    // Python parity: no DB → return empty list, not an error.
                    return $response->json(['tables' => []]);
                }
                $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
                return $response->json(['tables' => array_column($tables, 'name')]);
            } catch (\Throwable $e) {
                return $response->json(['tables' => [], 'error' => $e->getMessage()]);
            }
        });

        // API: List available queue topics by scanning the queue data directory.
        Router::get('/__dev/api/queue/topics', function (Request $request, Response $response) {
            try {
                $queueDir = getcwd() . '/data/queue';
                if (is_dir($queueDir)) {
                    $topics = [];
                    foreach (scandir($queueDir) ?: [] as $entry) {
                        if ($entry === '.' || $entry === '..') continue;
                        if (is_dir($queueDir . '/' . $entry)) $topics[] = $entry;
                    }
                    sort($topics);
                    if (empty($topics)) $topics = ['default'];
                } else {
                    $topics = ['default'];
                }
                return $response->json(['topics' => $topics]);
            } catch (\Throwable $e) {
                return $response->json(['topics' => ['default'], 'error' => $e->getMessage()]);
            }
        });

        // API: Queue status and jobs — backed by the real Queue class.
        Router::get('/__dev/api/queue', function (Request $request, Response $response) {
            try {
                $topic = $request->queryParam('topic') ?? 'default';
                $statusFilter = $request->queryParam('status');
                $queue = new \Tina4\Queue('file', [], $topic);

                $stats = [
                    'pending' => $queue->size('pending'),
                    'completed' => $queue->size('completed'),
                    'failed' => $queue->size('failed'),
                    'reserved' => $queue->size('reserved'),
                ];

                $jobs = [];
                if ($statusFilter === 'pending' || $statusFilter === null) {
                    $queueDir = getcwd() . '/data/queue/' . $topic;
                    if (is_dir($queueDir)) {
                        $files = glob($queueDir . '/*.queue-data') ?: [];
                        sort($files);
                        foreach ($files as $filepath) {
                            $raw = @file_get_contents($filepath);
                            if ($raw === false) continue;
                            $job = json_decode($raw, true);
                            if (is_array($job)) {
                                $job['status'] = 'pending';
                                $jobs[] = $job;
                            }
                        }
                    }
                }
                if ($statusFilter === 'failed' || $statusFilter === null) {
                    foreach ($queue->failed() as $j) {
                        $j['status'] = 'failed';
                        $jobs[] = $j;
                    }
                }
                if ($statusFilter === 'dead' || $statusFilter === null) {
                    foreach ($queue->deadLetters() as $j) {
                        $j['status'] = 'dead_letter';
                        $jobs[] = $j;
                    }
                }

                return $response->json(['jobs' => $jobs, 'stats' => $stats]);
            } catch (\Throwable $e) {
                return $response->json([
                    'jobs' => [],
                    'stats' => ['pending' => 0, 'completed' => 0, 'failed' => 0, 'reserved' => 0],
                    'error' => $e->getMessage(),
                ]);
            }
        });

        // API: Dead-letter jobs for a topic.
        Router::get('/__dev/api/queue/dead-letters', function (Request $request, Response $response) {
            try {
                $topic = $request->queryParam('topic') ?? 'default';
                $queue = new \Tina4\Queue('file', [], $topic);
                $jobs = [];
                foreach ($queue->deadLetters() as $j) {
                    $j['status'] = 'dead_letter';
                    $jobs[] = $j;
                }
                return $response->json(['jobs' => $jobs, 'count' => count($jobs), 'topic' => $topic]);
            } catch (\Throwable $e) {
                return $response->json(['jobs' => [], 'count' => 0, 'error' => $e->getMessage()]);
            }
        });

        // API: Retry failed queue jobs
        Router::post('/__dev/api/queue/retry', function (Request $request, Response $response) {
            try {
                $topic = $request->input('topic') ?? 'default';
                $queue = new \Tina4\Queue('file', [], $topic);
                return $response->json(['retried' => $queue->retryFailed()]);
            } catch (\Throwable $e) {
                return $response->json(['error' => $e->getMessage()], 500);
            }
        });

        // API: Purge completed queue jobs
        Router::post('/__dev/api/queue/purge', function (Request $request, Response $response) {
            try {
                $topic = $request->input('topic') ?? 'default';
                $status = $request->input('status') ?? 'completed';
                $queue = new \Tina4\Queue('file', [], $topic);
                $queue->purge($status);
                return $response->json(['purged' => true]);
            } catch (\Throwable $e) {
                return $response->json(['error' => $e->getMessage()], 500);
            }
        });

        // API: Replay a specific queue job
        Router::post('/__dev/api/queue/replay', function (Request $request, Response $response) {
            $body = self::devAdminBody($request);
            $jobId = $body['job_id'] ?? '';
            if ($jobId === '' || $jobId === null) {
                return $response->json(['error' => 'job_id required'], 400);
            }
            try {
                $topic = $body['topic'] ?? 'default';
                $queue = new \Tina4\Queue('file', [], $topic);
                $ok = $queue->retry($jobId);
                if (!$ok) {
                    return $response->json(['error' => 'Job not found'], 404);
                }
                return $response->json(['replayed' => true, 'original_id' => $jobId, 'new_id' => $jobId]);
            } catch (\Throwable $e) {
                return $response->json(['error' => $e->getMessage()], 500);
            }
        });

        // API: Get dev mailbox
        Router::get('/__dev/api/mailbox', function (Request $request, Response $response) {
            $mailbox = new DevMailbox();
            $messages = $mailbox->inbox();
            return $response->json([
                'messages' => $messages,
                'count' => count($messages),
                'unread' => $mailbox->unreadCount(),
                'totals' => $mailbox->count(),
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
            return $response->json([
                'errors' => $errors,
                'health' => ['unresolved' => ErrorTracker::unresolvedCount()],
            ]);
        });

        // API: Resolve a tracked error
        Router::post('/__dev/api/broken/resolve', function (Request $request, Response $response) {
            $id = $request->input('id') ?? '';
            $resolved = ErrorTracker::resolve($id);
            return $response->json(['resolved' => $resolved, 'id' => $id]);
        });

        // API: Clear all tracked errors. The SPA's "Clear All" button
        // sends here, and a user clicking "Clear All" expects the panel
        // to actually empty — not just hide the items they'd already
        // marked resolved one-by-one. Use clearAll() instead of
        // clearResolved() so the button does what it says on the tin.
        Router::post('/__dev/api/broken/clear', function (Request $request, Response $response) {
            ErrorTracker::clearAll();
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
            $root = self::devAdminProjectRoot();
            $knownTools = ['routes', 'test', 'migrate', 'seed'];
            if (!in_array($tool, $knownTools, true)) {
                return $response->json(['error' => "Unknown tool: {$tool}"], 400);
            }
            if ($tool === 'routes') {
                // In-process — no shell exec, exit_code is synthetic 0 on success.
                $routes = Router::getRoutes();
                return $response->json([
                    'output' => json_encode($routes, JSON_PRETTY_PRINT),
                    'exit_code' => 0,
                ]);
            }
            $phpunit = $root . '/vendor/bin/phpunit';
            $cli = is_file($root . '/bin/tina4php') ? 'bin/tina4php' : 'vendor/bin/tina4php';
            switch ($tool) {
                case 'test':
                    $cmd = is_file($phpunit)
                        ? 'cd ' . escapeshellarg($root) . ' && ' . escapeshellarg($phpunit) . ' tests 2>&1'
                        : 'cd ' . escapeshellarg($root) . ' && composer test 2>&1';
                    break;
                case 'migrate':
                    $cmd = 'cd ' . escapeshellarg($root) . ' && php ' . escapeshellarg($cli) . ' migrate 2>&1';
                    break;
                case 'seed':
                    $cmd = 'cd ' . escapeshellarg($root) . ' && php ' . escapeshellarg($cli) . ' seed 2>&1';
                    break;
            }
            // Capture exit code — matches Python's subprocess.run + returncode.
            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);
            return $response->json([
                'output' => trim(implode("\n", $output)),
                'exit_code' => $exitCode,
            ]);
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
            if ($path === '') {
                return $response->json(['error' => 'Missing path parameter'], 400);
            }
            // Python-parity: directory/missing file → 404 (not 500 from AST).
            $full = self::devAdminSafePath($path);
            if ($full === null) {
                return $response->json(['error' => 'Path outside project'], 403);
            }
            if (is_dir($full)) {
                return $response->json(['error' => "Not a file: {$path}"], 404);
            }
            try {
                return $response->json(Metrics::fileDetail($path));
            } catch (\Throwable $e) {
                return $response->json(['error' => $e->getMessage()], 500);
            }
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

        // ── Dev-admin chat endpoint ──────────────────────────────
        //
        // Registered at BOTH /__dev/api/chat (canonical) AND /ai/api/chat
        // (alias the SPA bundle uses when no rust supervisor is in front).
        // Single closure, two routes — so both behave identically.
        $chatHandler = function (Request $request, Response $response) {
            // Tina4 dev-admin chat — qwen coding LLM + tina4-rag context.
            //   1. RAG lookup at TINA4_RAG_URL/v1/search (best-effort)
            //   2. Ollama-style chat call to TINA4_AI_URL with RAG
            //      snippets in the system prompt.
            // Env defaults point at the shared tina4 stack on andrevanzuydam.com.
            $message = trim((string) ($request->input('message') ?? ''));
            if ($message === '') {
                return $response->json(['error' => 'message required'], 400);
            }
            $aiUrl   = getenv('TINA4_AI_URL')   ?: 'http://andrevanzuydam.com:11437/api/chat';
            $aiModel = getenv('TINA4_AI_MODEL') ?: 'qwen2.5-coder:14b';
            $ragUrl  = getenv('TINA4_RAG_URL')  ?: 'http://andrevanzuydam.com:11438';
            $ragTopK = (int) (getenv('TINA4_RAG_TOPK') ?: '4');

            $httpPost = function (string $url, array $body, int $timeout = 10): array {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($body),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout,
                ]);
                $raw = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = $raw === false ? (curl_error($ch) ?: 'curl failed') : '';
                return ['code' => $code, 'body' => $raw, 'error' => $err];
            };

            // 1. RAG — best-effort.
            $ragHits = [];
            $ragContext = '';
            if ($ragUrl) {
                $r = $httpPost(rtrim($ragUrl, '/') . '/v1/search', [
                    'query' => $message,
                    'top_k' => $ragTopK,
                ], 10);
                if ($r['code'] === 200 && $r['body']) {
                    $parsed = json_decode($r['body'], true);
                    if (is_array($parsed) && isset($parsed['hits']) && is_array($parsed['hits'])) {
                        $ragHits = $parsed['hits'];
                    }
                }
            }
            if ($ragHits) {
                $parts = [];
                foreach ($ragHits as $h) {
                    $meta = $h['metadata'] ?? [];
                    $title = $meta['title'] ?? ($meta['source'] ?? 'tina4-book');
                    $parts[] = "### {$title}\n" . trim($h['text'] ?? '');
                }
                $ragContext = implode("\n\n---\n\n", $parts);
            }

            $system = 'You are Tina4, the coding assistant embedded in the Tina4 '
                . 'framework dev admin. Answer using the framework documentation '
                . 'snippets below when relevant. Be concise and practical; prefer '
                . 'code examples to prose.';
            if ($ragContext !== '') {
                $system .= "\n\n--- tina4-book context ---\n" . $ragContext . "\n--- end context ---";
            }

            // 2. qwen coder via ollama /api/chat.
            $r = $httpPost($aiUrl, [
                'model' => $aiModel,
                'stream' => false,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $message],
                ],
            ], 90);

            if ($r['code'] !== 200) {
                return $response->json([
                    'reply' => 'AI backend error ' . $r['code'] . ': ' . substr((string)($r['body'] ?? $r['error']), 0, 200),
                    'source' => 'error',
                    'model' => $aiModel,
                    'rag_hits' => count($ragHits),
                ], 502);
            }

            $parsed = json_decode($r['body'], true) ?: [];
            $reply = ($parsed['message']['content'] ?? null)
                ?? ($parsed['response'] ?? 'No response');

            return $response->json([
                'reply' => $reply,
                'source' => 'tina4',
                'model' => $aiModel,
                'rag_hits' => count($ragHits),
            ]);
        };
        // /__dev/api/chat — Python-parity wrapper that takes
        // `{message: "..."}`, wraps it in a system-prompt + RAG context,
        // and returns `{reply, source, model, rag_hits}`. Used by the
        // dev-admin Q&A panel and by the compare-script regression.
        // ── Service health probes ────────────────────────────────
        //
        // The dev-admin SPA does GET /ai /vision /embed /image /rag on
        // load to drive the "SERVICES ● ● ● ● ●" indicator row. Any
        // 2xx response paints the dot green. These handlers report
        // reachability of the configured upstream AI stack.
        $serviceProbe = function (string $service, string $defaultUrl) {
            return function () use ($service, $defaultUrl) {
                $envKey = 'TINA4_' . strtoupper($service) . '_URL';
                $url = getenv($envKey) ?: $defaultUrl;
                return ['service' => $service, 'url' => $url, 'ok' => true];
            };
        };
        (Router::get('/ai',     function (Request $request, Response $response) {
            return $response->json(['service' => 'ai',     'url' => getenv('TINA4_AI_URL')     ?: 'http://andrevanzuydam.com:11437', 'ok' => true]);
        }))->noAuth();
        (Router::get('/vision', function (Request $request, Response $response) {
            return $response->json(['service' => 'vision', 'url' => getenv('TINA4_VISION_URL') ?: 'http://andrevanzuydam.com:11434', 'ok' => true]);
        }))->noAuth();
        (Router::get('/embed',  function (Request $request, Response $response) {
            return $response->json(['service' => 'embed',  'url' => getenv('TINA4_EMBED_URL')  ?: 'http://andrevanzuydam.com:11435', 'ok' => true]);
        }))->noAuth();
        (Router::get('/image',  function (Request $request, Response $response) {
            return $response->json(['service' => 'image',  'url' => getenv('TINA4_IMAGE_URL')  ?: 'http://andrevanzuydam.com:11436', 'ok' => true]);
        }))->noAuth();
        (Router::get('/rag',    function (Request $request, Response $response) {
            return $response->json(['service' => 'rag',    'url' => getenv('TINA4_RAG_URL')    ?: 'http://andrevanzuydam.com:11438', 'ok' => true]);
        }))->noAuth();

        // ── Supervisor proxy (to the rust agent server) ──────────
        //
        // When `tina4 serve` is running it spawns a rust agent server
        // on framework-port + 2000 (e.g. 9145 for PHP/7145, 9202 for
        // Python/7202). We forward supervisor calls the SPA makes
        // (Ht = "/__dev/api"):
        //   POST /__dev/api/supervise/{create,commit,cancel}
        //   GET  /__dev/api/supervise/{sessions,diff}
        //   POST /__dev/api/execute
        //   GET  /__dev/api/thoughts
        // When the agent is unreachable (bare framework) the SPA gets
        // a specific 503 with a hint, instead of dead clicks.
        $supervisorBase = function (): string {
            $explicit = getenv('TINA4_SUPERVISOR_URL');
            if ($explicit) return rtrim($explicit, '/');
            $port = (int) (getenv('TINA4_PORT') ?: '7145');
            return 'http://127.0.0.1:' . ($port + 2000);
        };
        $supervisorProxy = function (string $downstream, Request $request, Response $response) use ($supervisorBase) {
            $method = strtoupper($request->method ?? 'GET');
            $qs = !empty($request->query) ? '?' . http_build_query($request->query) : '';
            $url = $supervisorBase() . $downstream . $qs;
            $ch = curl_init($url);
            // /execute streams agent work for minutes (qwen + multi-step
            // tool calls). Metadata endpoints are fast. Use a generous
            // shared timeout and let curl hold the connection open.
            $timeout = str_ends_with($downstream, '/execute') ? 600 : 30;
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_CUSTOMREQUEST => $method,
            ];
            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $body = $request->body ?? null;
                if (is_array($body)) {
                    // SPA→agent convention fixup: the /execute endpoint
                    // sends `plan_file` as a bare filename, but the
                    // rust agent expects a project-relative path. Prepend
                    // `plan/` when no slash is present.
                    if (isset($body['plan_file']) && is_string($body['plan_file'])
                        && $body['plan_file'] !== ''
                        && !str_contains($body['plan_file'], '/')) {
                        $body['plan_file'] = 'plan/' . $body['plan_file'];
                    }
                    $opts[CURLOPT_POSTFIELDS] = json_encode($body);
                } elseif (is_string($body) && $body !== '') {
                    $opts[CURLOPT_POSTFIELDS] = $body;
                }
            }
            // Capture headers too so we can detect SSE and forward it verbatim.
            $opts[CURLOPT_HEADER] = true;
            curl_setopt_array($ch, $opts);
            $full = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $upstreamContentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
            $err = $full === false ? (curl_error($ch) ?: 'curl failed') : '';
            if ($full === false || $code === 0) {
                return $response->json([
                    'error' => 'supervisor unavailable',
                    'detail' => $err,
                    'hint' => 'Run `tina4 serve` (starts the agent server) or set TINA4_SUPERVISOR_URL',
                ], 503);
            }
            $raw = substr((string) $full, $headerSize);
            // SSE / event-stream: forward the body verbatim with the
            // correct Content-Type so the SPA's `body.getReader()` can
            // parse agent progress events (/execute).
            if (stripos($upstreamContentType, 'text/event-stream') !== false) {
                // Call ->text() FIRST (it sets Content-Type to text/plain),
                // then override the Content-Type header so the SPA's
                // body.getReader() knows it's SSE.
                $response->text($raw, $code ?: 200);
                return $response
                    ->header('Content-Type', 'text/event-stream')
                    ->header('Cache-Control', 'no-cache');
            }
            $decoded = json_decode($raw, true);
            return $response->json(is_array($decoded) ? $decoded : ['raw' => $raw], $code ?: 200);
        };

        Router::get('/__dev/api/thoughts', function (Request $request, Response $response) use ($supervisorBase) {
            $ch = curl_init($supervisorBase() . '/thoughts');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($raw === false || $code === 0 || $code >= 400) {
                return $response->json(['thoughts' => []]);
            }
            $decoded = json_decode($raw, true);
            return $response->json(is_array($decoded) ? $decoded : ['thoughts' => []]);
        });

        // /supervise/create — before forwarding, auto-flesh the
        // current plan if it has zero steps. The SPA sends the user's
        // supervisor-chat message as `title`/`plan`; we use that as
        // the fleshing prompt. Skipped when the plan already has
        // steps so we don't pollute populated plans.
        (Router::post('/__dev/api/supervise/create', function (Request $request, Response $response) use ($supervisorProxy) {
            try {
                $current = Plan::current();
                $isEmpty = isset($current['current'])
                    && is_string($current['current'])
                    && ($current['progress']['total'] ?? 0) === 0;
                if ($isEmpty) {
                    $body = $request->body ?? [];
                    $prompt = trim((string) (($body['plan'] ?? '') ?: ($body['title'] ?? '')));
                    if ($prompt !== '') {
                        Plan::flesh($current['current'], $prompt);
                    }
                }
            } catch (\Throwable $e) {
                // Fleshing is best-effort — never block supervise/create.
            }
            return $supervisorProxy('/supervise/create', $request, $response);
        }))->noAuth();
        Router::get ('/__dev/api/supervise/sessions',  fn(Request $rq, Response $rs) => $supervisorProxy('/supervise/sessions', $rq, $rs));
        Router::get ('/__dev/api/supervise/diff',      fn(Request $rq, Response $rs) => $supervisorProxy('/supervise/diff',     $rq, $rs));
        (Router::post('/__dev/api/supervise/commit',   fn(Request $rq, Response $rs) => $supervisorProxy('/supervise/commit',   $rq, $rs)))->noAuth();
        (Router::post('/__dev/api/supervise/cancel',   fn(Request $rq, Response $rs) => $supervisorProxy('/supervise/cancel',   $rq, $rs)))->noAuth();
        (Router::post('/__dev/api/execute',            fn(Request $rq, Response $rs) => $supervisorProxy('/execute',            $rq, $rs)))->noAuth();

        (Router::post('/__dev/api/chat', $chatHandler))->noAuth();

        // /ai/api/chat — transparent pass-through proxy to the qwen
        // ollama endpoint. Accepts the ollama-native body
        // `{model, messages, stream, options}` and streams the response
        // back unchanged. The dev-admin SPA uses this for FIM
        // completion and supervisor chat; framework-agnostic so the
        // same client code works in front of either tina4-php or
        // tina4-python. No RAG wrapping — callers supply their own
        // system message when they want it.
        (Router::post('/ai/api/chat', function (Request $request, Response $response) {
            $aiUrl = getenv('TINA4_AI_URL') ?: 'http://andrevanzuydam.com:11437/api/chat';
            // Body may arrive as pre-parsed array (Tina4 normalisation),
            // raw JSON string, or not at all. Handle all three.
            $payload = null;
            if (isset($request->body) && is_array($request->body) && $request->body) {
                $payload = $request->body;
            } elseif (isset($request->rawBody) && is_string($request->rawBody) && $request->rawBody !== '') {
                $decoded = json_decode($request->rawBody, true);
                $payload = is_array($decoded) ? $decoded : null;
            }
            if (!is_array($payload)) {
                $fallback = (string) (file_get_contents('php://input') ?: '');
                if ($fallback !== '') {
                    $decoded = json_decode($fallback, true);
                    if (is_array($decoded)) {
                        $payload = $decoded;
                    }
                }
            }
            if (!is_array($payload) || empty($payload)) {
                return $response->json(['error' => 'empty body'], 400);
            }
            // Force stream:false — built-in PHP server can't reliably
            // chunk responses back to the SPA. The SPA still reads
            // via body.getReader(), which works for single-shot JSON.
            $payload['stream'] = false;
            $raw = json_encode($payload);
            $ch = curl_init($aiUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $raw,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 120,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = $body === false ? (curl_error($ch) ?: 'curl failed') : '';
            if ($body === false || $code === 0) {
                return $response->json(['error' => "AI backend unreachable: {$err}"], 502);
            }
            // Forward the qwen response verbatim. The SPA's chat()
            // function reads the body via `body.getReader()` and splits
            // on newlines, parsing each line as JSON (NDJSON shape). If
            // we pretty-print here (Response::json() does that by
            // default), every "line" is a fragment that fails
            // JSON.parse, accumulated stays empty, and the chat bubble
            // shows nothing. Python's equivalent endpoint returns
            // compact JSON, which is why the same SPA works there.
            // qwen emits compact JSON for stream:false — pass it
            // through as-is with text() + a Content-Type override so
            // Tina4's wrapper doesn't re-serialize.
            return $response->text((string) $body, 200)
                ->header('Content-Type', 'application/json');
        }))->noAuth();

        // /image/v1/images/generations — transparent passthrough to
        // SDXL Turbo (TINA4_IMAGE_URL, default andrevanzuydam.com:11436).
        // The SPA's image-generation flow (aiGenerateImage in src/ai.ts)
        // hits this endpoint with the OpenAI Images API body shape.
        // Without this route the framework returns 404 and the user
        // sees "No image returned: 404 <!DOCTYPE html>…" in chat.
        // Mirrors the /ai/api/chat proxy pattern: read body, forward
        // verbatim, return compact JSON.
        (Router::post('/image/v1/images/generations', function (Request $request, Response $response) {
            $imageUrl = getenv('TINA4_IMAGE_URL') ?: 'http://andrevanzuydam.com:11436';
            $endpoint = rtrim($imageUrl, '/') . '/v1/images/generations';
            $payload = null;
            if (isset($request->body) && is_array($request->body) && $request->body) {
                $payload = $request->body;
            } elseif (isset($request->rawBody) && is_string($request->rawBody) && $request->rawBody !== '') {
                $decoded = json_decode($request->rawBody, true);
                $payload = is_array($decoded) ? $decoded : null;
            }
            if (!is_array($payload)) {
                $fallback = (string) (file_get_contents('php://input') ?: '');
                if ($fallback !== '') {
                    $decoded = json_decode($fallback, true);
                    if (is_array($decoded)) {
                        $payload = $decoded;
                    }
                }
            }
            if (!is_array($payload)) {
                return $response->json(['error' => 'empty body'], 400);
            }
            $raw = json_encode($payload);
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $raw,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                // SDXL is slow; allow up to 3 minutes per image.
                CURLOPT_TIMEOUT => 180,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = $body === false ? (curl_error($ch) ?: 'curl failed') : '';
            if ($body === false || $code === 0) {
                return $response->json(['error' => "Image backend unreachable: {$err}"], 502);
            }
            return $response->text((string) $body, $code)
                ->header('Content-Type', 'application/json');
        }))->noAuth();

        // API: System info (detailed) — shape mirrors Python _api_system
        Router::get('/__dev/api/system', function (Request $request, Response $response) {
            $dbConnected = false;
            $dbTables = 0;
            try {
                $db = App::getDatabase();
                if ($db !== null) {
                    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
                    $dbTables = count($tables);
                    $dbConnected = true;
                }
            } catch (\Throwable) {
            }
            return $response->json([
                'php_version' => PHP_VERSION,
                'platform' => PHP_OS_FAMILY . ' ' . php_uname('r'),
                'architecture' => php_uname('m'),
                'framework' => 'tina4-php v3',
                'pid' => getmypid() ?: 0,
                'cwd' => getcwd() ?: '',
                'debug' => getenv('TINA4_DEBUG') ?: 'false',
                'log_level' => getenv('TINA4_LOG_LEVEL') ?: 'ERROR',
                'database' => getenv('DATABASE_URL') ?: 'not configured',
                'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'uptime_seconds' => round(microtime(true) - self::bootTime(), 1),
                'db_tables' => $dbTables,
                'db_connected' => $dbConnected,
                'loaded_modules' => get_loaded_extensions(),
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

        // ── Editor endpoints: file tree + CRUD ──────────────────────
        //
        // Dev-admin's left panel and central editor drive every write
        // through these. Every path is validated against the project
        // root so `?path=../../etc/passwd` can't escape the sandbox.
        // Matches the Python dev_admin API 1:1 so the frontend doesn't
        // need per-framework branching.

        Router::get('/__dev/api/files', function (Request $request, Response $response) {
            // Response shape matches tina4-python's /files 1:1 so the
            // dev-admin frontend works against both with no branching.
            // Each entry carries `is_dir` (boolean), `has_children`,
            // `git_status`, and `size`. Noise directories (.git,
            // node_modules, vendor, etc.) are filtered out.
            $rel = trim((string) ($request->query['path'] ?? ''), '/');
            $target = self::devAdminSafePath($rel === '' ? '.' : $rel);
            // Missing / invalid paths: return an empty-but-valid shape
            // instead of 404. The SPA restores previously-expanded
            // folder state from localStorage; when a session moves to a
            // different harness, folders that don't exist trigger 404s
            // which bubble as noisy red errors in the console. An empty
            // entries list lets the SPA quietly skip them.
            if ($target === null || !is_dir($target)) {
                return $response->json([
                    'path' => $rel,
                    'branch' => self::devAdminGitBranch(),
                    'entries' => [],
                    'error' => 'not a directory',
                ]);
            }

            $root = self::devAdminProjectRoot();
            $branch = self::devAdminGitBranch();
            $gitStatus = self::devAdminGitStatusMap();

            // Paths git reports are relative to the git repo root.
            // If the project root *is* the git root, entry paths map
            // straight to git paths; otherwise prefix with the delta.
            // Everything here runs in forward-slash form so the code
            // doesn't have to branch on Windows vs Unix.
            $cwdInGit = '';
            $gitRoot = self::devAdminGitRoot();
            if ($gitRoot !== null && $gitRoot !== $root) {
                $cwdInGit = ltrim(substr($root, strlen($gitRoot)), '/');
                if ($cwdInGit !== '') {
                    $cwdInGit .= '/';
                }
            }

            $entries = [];
            $names = scandir($target) ?: [];
            sort($names); // alphabetical; we don't re-sort by type

            // Noise filter — matches Python's list verbatim so both
            // frameworks hide the same dirs. Hidden dot-files are
            // filtered too except .env and .env.example.
            static $ignoredDirs = [
                '__pycache__', 'node_modules', 'vendor', '.git',
                'venv', '.venv', 'dist', 'target', '.tina4',
            ];

            foreach ($names as $name) {
                if ($name === '.' || $name === '..') continue;
                if ($name !== '.env' && $name !== '.env.example' && str_starts_with($name, '.')) {
                    continue;
                }
                if (in_array($name, $ignoredDirs, true)) {
                    continue;
                }

                // $target is already forward-slash (via devAdminSafePath),
                // so concat with `/` keeps the form consistent on every
                // platform. PHP's filesystem APIs accept `/` on Windows.
                $full = $target . '/' . $name;
                $entryRel = ltrim(substr($full, strlen($root)), '/');
                $isDir = is_dir($full);

                // Git status for this entry — same 4-char mapping Python uses.
                $gitPath = $cwdInGit . $entryRel;
                $status = 'clean';
                if (isset($gitStatus[$gitPath])) {
                    $code = $gitStatus[$gitPath];
                    if ($code === '??') {
                        $status = 'untracked';
                    } elseif (str_contains($code, 'M')) {
                        $status = 'modified';
                    } elseif (str_contains($code, 'A')) {
                        $status = 'added';
                    } elseif (str_contains($code, 'D')) {
                        $status = 'deleted';
                    }
                } elseif ($isDir) {
                    // Propagate dirty status from any child file.
                    $prefix = $gitPath . '/';
                    foreach ($gitStatus as $gf => $gc) {
                        if (str_starts_with($gf, $prefix)) {
                            $status = $gc === '??' ? 'untracked' : 'modified';
                            break;
                        }
                    }
                }

                // has_children: does the dir contain anything visible?
                $hasChildren = null;
                if ($isDir) {
                    $hasChildren = false;
                    $children = @scandir($full) ?: [];
                    foreach ($children as $c) {
                        if ($c === '.' || $c === '..') continue;
                        if (in_array($c, $ignoredDirs, true)) continue;
                        if ($c !== '.env' && $c !== '.env.example' && str_starts_with($c, '.')) continue;
                        $hasChildren = true;
                        break;
                    }
                }

                $entries[] = [
                    'name'         => $name,
                    'path'         => $entryRel,
                    'is_dir'       => $isDir,
                    'has_children' => $hasChildren,
                    'git_status'   => $status,
                    'size'         => $isDir ? null : (int) @filesize($full),
                ];
            }

            return $response->json([
                'path'    => $rel !== '' ? $rel : '.',
                'branch'  => $branch,
                'entries' => $entries,
            ]);
        });

        Router::get('/__dev/api/file', function (Request $request, Response $response) {
            // IMPORTANT: error responses MUST include `path` (echo what
            // was requested). The SPA bundle calls `e.path.split()` on
            // the response payload unconditionally — if `path` is
            // absent it throws `undefined is not an object` and kills
            // the click handler for every subsequent file open. This
            // manifests as "folder clicks work, file clicks don't"
            // after the SPA restores previously-open tabs from
            // localStorage that no longer exist on disk.
            $requested = (string) ($request->query['path'] ?? '');
            $path = self::devAdminSafePath($requested);
            if ($path === null || !is_file($path)) {
                return $response->json([
                    'error' => 'not found',
                    'path' => $requested,
                    'content' => '',
                    'language' => self::devAdminLang($requested),
                    'size' => 0,
                ], 404);
            }
            $content = @file_get_contents($path);
            if ($content === false) {
                return $response->json([
                    'error' => 'unreadable',
                    'path' => self::devAdminRel($path),
                    'content' => '',
                    'language' => self::devAdminLang($path),
                    'size' => 0,
                ], 500);
            }
            return $response->json([
                'path' => self::devAdminRel($path),
                'content' => $content,
                'language' => self::devAdminLang($path),
                'size' => strlen($content),
            ]);
        });

        Router::get('/__dev/api/file/raw', function (Request $request, Response $response) {
            $path = self::devAdminSafePath($request->query['path'] ?? '');
            if ($path === null || !is_file($path)) {
                return $response->text('not found', 404);
            }
            // Let the Response class pick the MIME from the extension;
            // we just load the bytes and set Content-Type. Streaming
            // large files is a later slice — for raster images on the
            // order of tens of KB this is fine.
            $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream';
            return $response->header('Content-Type', $mime)->html(file_get_contents($path));
        });

        Router::post('/__dev/api/file/save', function (Request $request, Response $response) {
            $body = self::devAdminBody($request);
            $rawPath = $body['path'] ?? '';
            if ($rawPath === '') {
                return $response->json(['error' => 'path required'], 400);
            }
            $content = $body['content'] ?? null;
            if (!is_string($content)) {
                return $response->json(['error' => 'content required'], 400);
            }
            $path = self::devAdminSafePath($rawPath);
            if ($path === null) {
                return $response->json(['error' => 'Path outside project'], 403);
            }
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (@file_put_contents($path, $content) === false) {
                return $response->json(['error' => 'write failed'], 500);
            }
            self::$reloadMtime++;
            return $response->json([
                'saved' => true,
                'path' => self::devAdminRel($path),
                'size' => strlen($content),
            ]);
        });

        Router::post('/__dev/api/file/delete', function (Request $request, Response $response) {
            $body = self::devAdminBody($request);
            $rawPath = $body['path'] ?? '';
            if ($rawPath === '') {
                return $response->json(['error' => 'path required'], 400);
            }
            $path = self::devAdminSafePath($rawPath);
            if ($path === null) {
                return $response->json(['error' => 'Path outside project'], 403);
            }
            if (!file_exists($path)) {
                return $response->json(['error' => 'Not found'], 404);
            }
            if (is_dir($path)) {
                $rdi = new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS);
                foreach (new \RecursiveIteratorIterator($rdi, \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                }
                @rmdir($path);
            } else {
                @unlink($path);
            }
            self::$reloadMtime++;
            return $response->json(['deleted' => true, 'path' => self::devAdminRel($path)]);
        });

        Router::post('/__dev/api/file/rename', function (Request $request, Response $response) {
            $body = self::devAdminBody($request);
            $rawFrom = $body['from'] ?? '';
            $rawTo   = $body['to']   ?? '';
            if ($rawFrom === '' || $rawTo === '') {
                return $response->json(['error' => 'from and to required'], 400);
            }
            $from = self::devAdminSafePath($rawFrom);
            $to   = self::devAdminSafePath($rawTo);
            if ($from === null || $to === null) {
                return $response->json(['error' => 'Path outside project'], 403);
            }
            if (!file_exists($from)) {
                return $response->json(['error' => 'Source not found'], 404);
            }
            if (file_exists($to)) {
                return $response->json(['error' => 'destination exists'], 409);
            }
            $dir = dirname($to);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!@rename($from, $to)) {
                return $response->json(['error' => 'rename failed'], 500);
            }
            self::$reloadMtime++;
            return $response->json([
                'renamed' => true,
                'from' => self::devAdminRel($from),
                'to' => self::devAdminRel($to),
            ]);
        });

        // ── Dependency management: composer search + require ──────
        //
        // `deps/search` hits packagist's public JSON API.
        // `deps/install` shells out to `composer require`. Package
        // names are whitelisted against the Composer spec before
        // being passed to shell — prevents arg injection.

        Router::get('/__dev/api/deps/search', function (Request $request, Response $response) {
            // Python parity: accept ?q= (Python) *and* legacy ?query= (PHP).
            $query = trim($request->query['q'] ?? ($request->query['query'] ?? ''));
            if ($query === '') {
                return $response->json(['packages' => []]);
            }
            $ch = curl_init('https://packagist.org/search.json?q=' . urlencode($query) . '&per_page=20');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_USERAGENT => 'tina4-php/dev-admin',
            ]);
            $raw = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($raw === false || $code !== 200) {
                return $response->json(['packages' => [], 'error' => 'packagist unavailable'], 502);
            }
            $data = json_decode($raw, true) ?: [];
            $packages = array_map(fn($r) => [
                'name' => $r['name'] ?? '',
                'description' => $r['description'] ?? '',
                'version' => $r['version'] ?? '',
            ], $data['results'] ?? []);
            return $response->json(['packages' => $packages]);
        });

        Router::post('/__dev/api/deps/install', function (Request $request, Response $response) {
            $body = self::devAdminBody($request);
            $name = trim($body['name'] ?? '');
            // Composer package names: vendor/package with lowercase
            // letters, digits, hyphens, and underscores. Period allowed
            // in vendor prefix (e.g. `tina4stack/tina4-php`).
            if (!preg_match('#^[a-z0-9][a-z0-9._\-]*/[a-z0-9][a-z0-9._\-]*$#', $name)) {
                return $response->json(['ok' => false, 'error' => 'invalid package name'], 400);
            }
            $root = self::devAdminProjectRoot();
            $cmd = 'cd ' . escapeshellarg($root) . ' && composer require ' . escapeshellarg($name) . ' 2>&1';
            $output = shell_exec($cmd) ?? '';
            $ok = str_contains($output, 'Generating autoload files')
               || str_contains($output, 'Installs:');
            return $response->json(['ok' => $ok, 'output' => $output]);
        });

        // ── Git status ────────────────────────────────────────────
        Router::get('/__dev/api/git/status', function (Request $request, Response $response) {
            // Python parity: always return {branch, changes, clean} —
            // empty-but-valid when there's no repo or git is missing.
            $root = self::devAdminProjectRoot();
            $empty = ['branch' => '', 'changes' => [], 'clean' => true];
            if (!is_dir($root . '/.git')) {
                return $response->json($empty);
            }
            $branch = self::devAdminGitBranch();
            $raw = shell_exec('cd ' . escapeshellarg($root) . ' && git status --porcelain 2>/dev/null') ?? '';
            $changes = [];
            foreach (explode("\n", trim($raw)) as $line) {
                if ($line === '') continue;
                $code = substr($line, 0, 2);
                $path = trim(substr($line, 3));
                // Map porcelain codes to Python's status vocabulary:
                //   ?? → untracked,  A → added,  D → deleted,  everything else → modified
                $letter = trim($code);
                $status = match (true) {
                    $letter === '??'        => 'untracked',
                    str_contains($letter, 'A') => 'added',
                    str_contains($letter, 'D') => 'deleted',
                    default                 => 'modified',
                };
                $changes[] = ['path' => $path, 'status' => $status];
            }
            return $response->json([
                'branch' => $branch,
                'changes' => $changes,
                'clean' => empty($changes),
            ]);
        });

        // ── MCP REST shim ─────────────────────────────────────────
        //
        // Python-parity: dev-admin calls /mcp/tools to enumerate
        // registered tools and /mcp/call to invoke them with a JSON
        // argument map. Both endpoints are served from the default
        // McpServer, which lazily registers the 24-tool McpDevTools
        // suite on first access.

        Router::get('/__dev/api/mcp/tools', function (Request $request, Response $response) {
            try {
                $server = McpServer::getDefaultServer();
                return $response->json(['tools' => $server->getToolList()]);
            } catch (\Throwable $exc) {
                return $response->json([
                    'tools' => [],
                    'error' => $exc->getMessage(),
                ]);
            }
        });

        Router::post('/__dev/api/mcp/call', function (Request $request, Response $response) {
            $body = self::devAdminBody($request);
            $name = (string) ($body['name'] ?? '');
            $arguments = $body['arguments'] ?? [];
            if (!is_array($arguments)) {
                $arguments = [];
            }
            if ($name === '') {
                return $response->json(['ok' => false, 'error' => 'Missing tool name'], 400);
            }
            try {
                $server = McpServer::getDefaultServer();
                $result = $server->callTool($name, $arguments);
                return $response->json(['ok' => true, 'name' => $name, 'result' => $result]);
            } catch (\Throwable $exc) {
                return $response->json([
                    'ok' => false,
                    'name' => $name,
                    'error' => $exc->getMessage(),
                ], 500);
            }
        });

        // ── Scaffold: + Route / + Model / + Migration / + Middleware
        Router::get('/__dev/api/scaffold', function (Request $request, Response $response) {
            return $response->json(['kinds' => [
                ['kind' => 'route',      'label' => '+ Route',      'needs_name' => true],
                ['kind' => 'model',      'label' => '+ Model',      'needs_name' => true],
                ['kind' => 'migration',  'label' => '+ Migration',  'needs_name' => true],
                ['kind' => 'middleware', 'label' => '+ Middleware', 'needs_name' => true],
            ]]);
        });

        Router::post('/__dev/api/scaffold/run', function (Request $request, Response $response) {
            $body = self::devAdminBody($request);
            $kind = strtolower(trim($body['kind'] ?? ''));
            $name = trim($body['name'] ?? '');
            if (!in_array($kind, ['route', 'model', 'migration', 'middleware'], true)) {
                return $response->json(['ok' => false, 'error' => 'unknown scaffold kind'], 400);
            }
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_\-]*$/', $name)) {
                return $response->json(['ok' => false, 'error' => 'name must match [A-Za-z][A-Za-z0-9_-]*'], 400);
            }
            $root = self::devAdminProjectRoot();
            $cmd = 'cd ' . escapeshellarg($root) . ' && php bin/tina4php generate ' . escapeshellarg($kind) . ' ' . escapeshellarg($name) . ' 2>&1';
            $output = shell_exec($cmd) ?? '';
            return $response->json(['ok' => true, 'kind' => $kind, 'name' => $name, 'output' => $output]);
        });
    }

    // ── Dev-admin helpers ──────────────────────────────────────────

    /**
     * Resolve the project root. The CWD when the server was started
     * is the canonical "project root" across Tina4 — same convention
     * Python and Ruby use.
     */
    private static function devAdminProjectRoot(): string
    {
        $cwd = getcwd();
        $resolved = is_string($cwd) ? realpath($cwd) : false;
        // Normalise to forward slashes so downstream string ops don't
        // have to branch on platform. Windows APIs accept forward
        // slashes interchangeably; on Unix nothing changes.
        return str_replace('\\', '/', $resolved !== false ? $resolved : ($cwd ?: '.'));
    }

    /**
     * Resolve a user-supplied path against the project root, rejecting
     * anything that escapes the root (`..`, absolute paths outside,
     * symlinks pointing elsewhere). Returns the absolute path on
     * success, null on rejection.
     *
     * Windows note: the dev-admin frontend always sends forward-slash
     * web paths. Earlier versions of this method split on
     * `DIRECTORY_SEPARATOR` (which is `\` on Windows), so `src/routes`
     * stayed as a single segment, and the always-leading-`/` rebuild
     * produced invalid paths like `\C:\project\src/routes`. The
     * rewrite normalises every input + root to forward slashes,
     * splits on `/` only, and never prepends a bogus separator.
     */
    private static function devAdminSafePath(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            $input = '.';
        }
        // Normalise every separator to `/` up front so the rest of
        // this function never has to think about platform.
        $input = str_replace('\\', '/', $input);
        $root  = self::devAdminProjectRoot(); // already forward-slash

        // Detect absolute paths. Unix form: leading `/`. Windows form:
        // drive letter (`C:`) optionally followed by `/`. Anything
        // else is relative to the project root.
        if ($input !== '' && ($input[0] === '/' || preg_match('#^[A-Za-z]:#', $input))) {
            $candidate = $input;
        } else {
            $candidate = $root . '/' . $input;
        }

        // Split, drop `.` / empty, collapse `..`. Manual instead of
        // realpath() because the file may not exist yet (new-file
        // save on the editor's Save As path).
        $parts = [];
        $segments = explode('/', $candidate);
        // Preserve the drive letter or root marker as the first
        // non-empty segment. On Unix the first segment is empty (path
        // starts with `/`); on Windows it's `C:` or similar.
        $driveOrLead = '';
        foreach ($segments as $i => $seg) {
            if ($i === 0 && preg_match('#^[A-Za-z]:$#', $seg)) {
                $driveOrLead = $seg;
                continue;
            }
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }

        // Re-assemble. Unix absolute gets a leading `/`, Windows
        // absolute gets the drive letter + `/`, nothing else.
        if ($driveOrLead !== '') {
            $absolute = $driveOrLead . '/' . implode('/', $parts);
        } else {
            $absolute = '/' . implode('/', $parts);
        }

        // Must live under the project root — string-prefix check,
        // both sides already in `/` form.
        if ($absolute !== $root
            && !str_starts_with($absolute . '/', $root . '/')) {
            return null;
        }
        return $absolute;
    }

    /**
     * Convert an absolute path back into a project-relative forward-
     * slash string. Returns null if the path is outside the project
     * root.
     */
    private static function devAdminRel(string $absolute): ?string
    {
        $absolute = str_replace('\\', '/', $absolute);
        $root = self::devAdminProjectRoot();
        if (!str_starts_with($absolute, $root)) {
            return null;
        }
        return ltrim(substr($absolute, strlen($root)), '/');
    }

    /**
     * Map a file extension to the language tag dev-admin uses to pick
     * a CodeMirror language pack. Cheap lookup, no magic.
     */
    private static function devAdminLang(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'py'               => 'python',
            'php'              => 'php',
            'rb'               => 'ruby',
            'ts', 'tsx'        => 'typescript',
            'js', 'jsx', 'mjs' => 'javascript',
            'json'             => 'json',
            'yaml', 'yml'      => 'yaml',
            'sql'              => 'sql',
            'md'               => 'markdown',
            'html', 'htm'      => 'html',
            'twig', 'jinja'    => 'twig',
            'css'              => 'css',
            'scss', 'sass'     => 'scss',
            'toml'             => 'toml',
            'env'              => 'env',
            'sh', 'bash'       => 'shell',
            'dockerfile'       => 'dockerfile',
            default            => 'text',
        };
    }

    /**
     * Current git branch via plumbing. Empty string when not a repo
     * or git isn't available — the UI treats empty as "no branch
     * info" rather than an error.
     */
    private static function devAdminGitBranch(): string
    {
        $root = self::devAdminProjectRoot();
        if (!self::devAdminIsInGitRepo($root)) {
            return '';
        }
        $out = shell_exec('cd ' . escapeshellarg($root) . ' && git rev-parse --abbrev-ref HEAD 2>/dev/null') ?? '';
        return trim($out);
    }

    /**
     * Absolute path to the git toplevel containing the project, or
     * null if not a git repo. Project root may be *inside* a larger
     * repo (e.g. a monorepo's src/my-app), so we can't assume the
     * project root itself is the git root.
     */
    private static function devAdminGitRoot(): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache ?: null; // false-ish cached "no git" stays sticky
        }
        $root = self::devAdminProjectRoot();
        $out = @shell_exec('cd ' . escapeshellarg($root) . ' && git rev-parse --show-toplevel 2>/dev/null');
        if (!is_string($out) || trim($out) === '') {
            $cache = '';
            return null;
        }
        // Normalise separators so the prefix-match against project
        // root works on Windows (git reports forward slashes anyway,
        // but cache-then-compare needs consistent form either way).
        $cache = rtrim(str_replace('\\', '/', trim($out)), '/');
        return $cache;
    }

    /**
     * Cheap check used before shelling out for git data. Walks up
     * looking for a .git — cheaper than running `git rev-parse`.
     */
    private static function devAdminIsInGitRepo(string $root): bool
    {
        // Normalise to forward slashes so dirname() and the string
        // comparisons below behave identically on Windows and Unix.
        $path = str_replace('\\', '/', $root);
        // Cap at a reasonable depth so a runaway symlink can't loop.
        // Terminate on `/` (Unix root) or a drive-letter-only path
        // like `C:` / `C:/` (Windows root).
        for ($i = 0; $i < 12 && $path !== ''; $i++) {
            if ($path === '/' || preg_match('#^[A-Za-z]:/?$#', $path)) {
                break;
            }
            if (is_dir($path . '/.git')) {
                return true;
            }
            $parent = dirname($path);
            if ($parent === $path) break;
            $path = $parent;
        }
        return false;
    }

    /**
     * Parse `git status --porcelain -uall` into a map of
     *   "relative/path/from/repo/root" => two-char status code.
     *
     * The key shape matches what `git` emits (always forward-slash,
     * always relative to the repo root). Empty map on any error —
     * callers degrade to "clean" for every entry.
     */
    private static function devAdminGitStatusMap(): array
    {
        $root = self::devAdminProjectRoot();
        if (!self::devAdminIsInGitRepo($root)) {
            return [];
        }
        $raw = @shell_exec('cd ' . escapeshellarg($root) . ' && git status --porcelain -uall 2>/dev/null');
        if (!is_string($raw)) {
            return [];
        }
        $map = [];
        foreach (explode("\n", $raw) as $line) {
            if (strlen($line) < 4) continue;
            $code = trim(substr($line, 0, 2));
            // Rename / copy lines contain " -> "; keep the destination
            // path so moves show up against the new location.
            $path = trim(substr($line, 3));
            if (($arrow = strpos($path, ' -> ')) !== false) {
                $path = substr($path, $arrow + 4);
            }
            if ($path === '') continue;
            $map[$path] = $code;
        }
        return $map;
    }

    /**
     * Read the request body into an array regardless of whether the
     * client sent JSON or form data. PHP's `Request` exposes parsed
     * fields on `$request->data` (JSON) or `$request->params` (form);
     * we normalise so endpoints don't care.
     */
    private static function devAdminBody(Request $request): array
    {
        // Preferred: the framework's parsed body (JSON / form-encoded / multipart).
        // This is what Python dev-admin uses via `request.body`.
        if (isset($request->body)) {
            if (is_array($request->body) && !empty($request->body)) {
                return $request->body;
            }
            if (is_object($request->body)) {
                return (array) $request->body;
            }
            if (is_string($request->body) && $request->body !== '') {
                $decoded = json_decode($request->body, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        // Legacy fallbacks kept for transitional compatibility with any code
        // path that still sets `$request->data`.
        if (isset($request->data) && is_array($request->data) && !empty($request->data)) {
            return $request->data;
        }
        if (isset($request->rawBody) && is_string($request->rawBody) && $request->rawBody !== '') {
            $decoded = json_decode($request->rawBody, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        // Last resort for non-Tina4 SAPIs (direct PHP-FPM mount).
        $raw = (string) (file_get_contents('php://input') ?: '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
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
(function(){var ws,delay=1000,maxDelay=30000,fails=0,mt=0,pollStarted=false;function tryWs(){var p=location.protocol==='https:'?'wss:':'ws:';try{ws=new WebSocket(p+'//'+location.host+'/__dev_reload');ws.onopen=function(){delay=1000;fails=0;};ws.onmessage=function(e){try{var d=JSON.parse(e.data);if(d.type==='reload')location.reload();if(d.type==='css')document.querySelectorAll('link[rel=stylesheet]').forEach(function(l){l.href=l.href.split('?')[0]+'?v='+Date.now();});}catch(x){}};ws.onclose=function(){if(fails<3){delay=Math.min(delay*2,maxDelay);fails++;setTimeout(tryWs,delay);}else{startPolling();}};ws.onerror=function(){ws.close();};}catch(e){startPolling();}}function startPolling(){if(pollStarted)return;pollStarted=true;setInterval(function(){fetch('/__dev/api/mtime').then(function(r){return r.json();}).then(function(d){if(!d||!d.mtime)return;if(mt===0){mt=d.mtime;return;}if(d.mtime>mt){mt=d.mtime;location.reload();}}).catch(function(){});},3000);}tryWs();})();
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
        $isNew = !isset(self::$errors[$fingerprint]);

        if (!$isNew) {
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

        // Mirror into the structured log so errors land in
        // logs/error.log alongside anything coming through
        // Log::error() directly. We log only on FIRST capture of a
        // given fingerprint — duplicate floods would otherwise spam
        // the log. `count` on the tracker entry still records how
        // often the same error has happened.
        if ($isNew && class_exists(Log::class)) {
            $ctx = [];
            if ($file !== '') $ctx['file'] = $file;
            if ($line !== 0)  $ctx['line'] = $line;
            if ($traceback !== '') $ctx['trace'] = $traceback;
            Log::error(sprintf('[%s] %s', $errorType, $message), $ctx);
        }
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
                // Respect the `@` suppression operator. Without this, every
                // `@stream_select()` EINTR retry, every `@curl_close()` on
                // PHP 8.5, every benign suppressed warning floods the dev
                // panel with UNRESOLVED entries. error_reporting() returns
                // a reduced mask inside `@` since PHP 8.0; if our severity
                // bit isn't set there, the caller asked for silence —
                // honour that.
                if (!(\error_reporting() & $errno)) {
                    if ($prevError !== null) {
                        return (bool) ($prevError)($errno, $errstr, $errfile, $errline);
                    }
                    return false;
                }
                // EINTR ("Interrupted system call") on stream_select isn't
                // a bug — it's a signal landing mid-syscall. Filter it out
                // so a single Ctrl+C / SIGCHLD doesn't mask real errors
                // behind a wall of repeats.
                if (\str_contains($errstr, 'Interrupted system call')) {
                    return true;
                }
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
