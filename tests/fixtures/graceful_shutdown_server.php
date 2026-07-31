<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Boots a REAL Tina4 App on the REAL socket event loop (App::run ->
 * Server::start -> stream_select) so GracefulShutdownTest can send it a REAL
 * POSIX signal and observe what the REAL server does.
 *
 * NO MOCKS: a real listening socket, a real HTTP route, a real WebSocket
 * route, a real SQLite connection, a real background tick callback.
 *
 * The /slow route is a WALL-CLOCK DEADLINE LOOP, never a single usleep(). A
 * signal makes a blocking sleep return early (EINTR), which truncates the
 * handler and makes the framework look like it drained a request when it was
 * only interrupted. That produced a false measurement once already, so the
 * deadline loop is load-bearing, not style.
 *
 * Usage:
 *   php graceful_shutdown_server.php <port> <stateFile> <slowSeconds> [flags]
 *
 * flags is a comma-separated set:
 *   background — register a real $app->background() tick callback
 *   database   — open a real SQLite connection and bind it to the app
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

$port        = (int)($argv[1] ?? 0);
$stateFile   = $argv[2] ?? '';
$slowSeconds = (float)($argv[3] ?? 2.0);
$flags       = array_filter(explode(',', $argv[4] ?? ''));

if ($port === 0 || $stateFile === '') {
    fwrite(STDERR, "usage: graceful_shutdown_server.php <port> <stateFile> <slowSeconds> [flags]\n");
    exit(2);
}

/**
 * Append one line of observable state for the test to read.
 *
 * @param string $file Path to the state file.
 * @param string $line Line to append (a newline is added).
 */
function recordState(string $file, string $line): void
{
    file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
}

$app = new \Tina4\App(basePath: sys_get_temp_dir());

if (in_array('database', $flags, true)) {
    // A REAL SQLite connection on a REAL file, bound exactly as an app binds it.
    // `sqlite:` + an absolute path, NOT `sqlite://` — the two-slash form eats the
    // leading slash and silently creates the file relative to the cwd.
    $databaseFile = $stateFile . '.sqlite';
    $database = \Tina4\Database\Database::create('sqlite:' . $databaseFile);
    $database->execute('create table if not exists shutdown_probe (id integer primary key, note varchar(50))');
    $database->insert('shutdown_probe', ['note' => 'in flight']);
    \Tina4\App::setDatabase($database);

    // Observed AFTER the server's cleanup() has run (register_shutdown_function
    // fires once the event loop has returned, and also on the shutdown-timeout
    // exit(0) path). SQLite keeps -wal and -shm sidecar files for exactly as
    // long as a connection is open and removes them on a real close, so this
    // records, from inside the live process, whether the connection was really
    // released during shutdown rather than merely dropped by process exit.
    $app->onShutdown(static function () use ($stateFile, $databaseFile) {
        clearstatcache();
        recordState($stateFile, 'database-still-open ' . (
            (file_exists($databaseFile . '-wal') || file_exists($databaseFile . '-shm')) ? 'yes' : 'no'
        ));
        // Closing an already-closed connection must be survivable, not fatal.
        \Tina4\App::closeDatabase();
        recordState($stateFile, 'second-close-survived');
    });
    recordState($stateFile, 'database-open');
}

Router::get('/ping', static function (Request $request, Response $response) {
    return $response('pong');
});

Router::get('/slow', static function (Request $request, Response $response) use ($stateFile, $slowSeconds) {
    recordState($stateFile, 'slow-started ' . round(microtime(true), 3));
    // Wall-clock bounded — a signal must not be able to cut this short and
    // make an interrupted handler look like a drained one.
    $end = microtime(true) + $slowSeconds;
    while (microtime(true) < $end) {
        usleep(20000);
    }
    recordState($stateFile, 'slow-finished ' . round(microtime(true), 3));

    return $response('slow-done');
});

Router::websocket('/ws', static function ($connection, $data, $event) {
    // No-op: the test only needs a live upgraded connection to observe the
    // RFC 6455 close frame the server sends it on shutdown.
});

if (in_array('background', $flags, true)) {
    $app->background(static function () use ($stateFile) {
        $server = \Tina4\Server::getInstance();
        if ($server !== null) {
            recordState($stateFile, 'tick ' . $server->tickCallbackCount());
        }
    }, 0.2);
}

$app->run('127.0.0.1', $port);
recordState($stateFile, 'run-returned');
