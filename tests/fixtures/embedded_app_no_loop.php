<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * A REAL Tina4 App embedded in someone else's runtime: constructed, then driven
 * by a loop that is NOT Tina4's. This is the documented integration shape (see
 * App::__invoke — Swoole, RoadRunner, FrankenPHP, ReactPHP) and the shape the
 * scaffolded index.php uses (`new App(); $app->handle();`).
 *
 * The point is what is ABSENT: no Server, no accept loop, and therefore nothing
 * that ever calls pcntl_signal_dispatch(). A signal handler installed at
 * construction would suppress the default terminate action while its PHP
 * callback could never run, leaving the process unkillable by SIGTERM — `kill`
 * and `docker stop` become no-ops, with no workaround available to the
 * embedder. GracefulShutdownTest signals this process for real and requires it
 * to die.
 *
 * The loop is WALL-CLOCK bounded rather than one long sleep so that an
 * EINTR-truncated sleep can never be mistaken for a process that died.
 *
 * Usage:
 *   php embedded_app_no_loop.php <stateFile> [liveSeconds]
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$stateFile   = $argv[1] ?? '';
$liveSeconds = (float)($argv[2] ?? 10.0);

if ($stateFile === '') {
    fwrite(STDERR, "usage: embedded_app_no_loop.php <stateFile> [liveSeconds]\n");
    exit(2);
}

$app = new \Tina4\App(basePath: sys_get_temp_dir());

file_put_contents($stateFile, "embedded-app-ready\n", FILE_APPEND | LOCK_EX);

$end = microtime(true) + $liveSeconds;
while (microtime(true) < $end) {
    usleep(20000);
}

// Only reachable if SIGTERM was swallowed. The test asserts this never appears.
file_put_contents($stateFile, "SURVIVED-SIGTERM\n", FILE_APPEND | LOCK_EX);
