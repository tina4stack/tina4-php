<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Boots a REAL Tina4 App with a REAL `$app->background()` task and runs the
 * REAL server event loop (App::run -> Server::start -> stream_select idle ->
 * Server::runTickCallbacks). BackgroundOverlapTest spawns this as a genuine
 * subprocess and reads the concurrency probe it writes.
 *
 * NO MOCKS: the callback is the actual unit under test (a slow sweep), the
 * timing is wall-clock, the loop is the production accept loop, and the probe
 * is a real file guarded by a real flock().
 *
 * The probe lives in a FILE rather than a PHP variable on purpose: it must
 * still record concurrency if a (deliberately broken) tick loop runs the
 * callback in another process instead of waiting for it. A private variable
 * would silently miss exactly the overlap the test exists to catch.
 *
 * Usage:
 *   php background_overlap_server.php <probeFile> <workSeconds> <interval> <port> [mode]
 *
 * mode: 'normal' (default) — the callback sleeps for <workSeconds>
 *       'throw'            — the callback raises after recording the run
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$probeFile   = $argv[1] ?? '';
$workSeconds = (float)($argv[2] ?? 0.0);
$interval    = (float)($argv[3] ?? 1.0);
$port        = (int)($argv[4] ?? 0);
$mode        = $argv[5] ?? 'normal';

if ($probeFile === '' || $port === 0) {
    fwrite(STDERR, "usage: background_overlap_server.php <probeFile> <work> <interval> <port> [mode]\n");
    exit(2);
}

/**
 * Atomically read-modify-write the concurrency probe file under an exclusive lock.
 *
 * @param string   $file   Path to the probe file (created if missing).
 * @param callable $mutate fn(array $state): array — receives and returns the probe state.
 * @return void
 */
function probeUpdate(string $file, callable $mutate): void
{
    $handle = fopen($file, 'c+');
    if ($handle === false) {
        return;
    }
    flock($handle, LOCK_EX);
    $raw = stream_get_contents($handle);
    $state = ($raw !== '' && $raw !== false)
        ? (json_decode($raw, true) ?: [])
        : [];
    $state += ['in_flight' => 0, 'peak' => 0, 'runs' => 0];
    $state = $mutate($state);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($state));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

// Counts how many copies of the callback are in flight at once — the invariant
// under test is that the peak never exceeds 1.
$callback = function () use ($probeFile, $workSeconds, $mode): void {
    probeUpdate($probeFile, static function (array $state): array {
        $state['in_flight']++;
        $state['runs']++;
        $state['peak'] = max($state['peak'], $state['in_flight']);
        return $state;
    });
    try {
        if ($mode === 'throw') {
            throw new \RuntimeException('intentional');
        }
        // Real blocking work, deliberately longer than the interval.
        usleep((int)round($workSeconds * 1_000_000));
    } finally {
        probeUpdate($probeFile, static function (array $state): array {
            $state['in_flight']--;
            return $state;
        });
    }
};

$app = new \Tina4\App();
$app->background($callback, $interval);
$app->run('127.0.0.1', $port);
