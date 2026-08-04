<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * ONE WORKER in the concurrent first-use storm driven by
 * tests/SessionDatabaseEnginesTest::testConcurrentFirstUseIsSafeOnEveryEngine.
 *
 * WHY A SEPARATE PROCESS. The property under test is a RACE: two workers both
 * pass DatabaseSessionHandler::ensureTable()'s existence check, both issue
 * CREATE TABLE, and the loser has to survive. PHP has no threads, so the only
 * honest way to produce two genuinely concurrent first uses against ONE real
 * database is two genuinely concurrent processes. Nothing here is a double -
 * this is the real handler, the real adapter, and the real engine.
 *
 * Connects BEFORE the barrier on purpose: the race is in ensureTable(), not in
 * connection setup, and connect times vary by tens of milliseconds - enough to
 * spread the workers out and hide the very interleave being measured.
 *
 * argv[1] float  wall-clock instant (microtime) every worker starts at
 * argv[2] string session id this worker writes
 *
 * Environment (deliberately NOT named TINA4_*, so nothing here can be confused
 * with a framework setting or trip the env-uniformity gate):
 *   T4_RACE_URL / T4_RACE_USERNAME / T4_RACE_PASSWORD
 *
 * Exit codes: 0 success, 1 the first use itself failed, 2 could not connect.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$startAt = (float)($argv[1] ?? 0.0);
$sessionId = (string)($argv[2] ?? '');

try {
    $database = \Tina4\Database\Database::create(
        (string)getenv('T4_RACE_URL'),
        username: (string)getenv('T4_RACE_USERNAME'),
        password: (string)getenv('T4_RACE_PASSWORD')
    );
    // The INJECTED-FACADE path: a Database wrapper satisfies DatabaseAdapter and
    // is kept as given, so the handler has to unwrap it to know which engine's
    // CREATE TABLE to send. Injecting one here exercises that unwrap for real.
    $handler = new \Tina4\Session\DatabaseSessionHandler(['db' => $database]);
} catch (\Throwable $connectFailure) {
    fwrite(STDERR, 'connect: ' . get_class($connectFailure) . ': ' . $connectFailure->getMessage());
    exit(2);
}

// The barrier. Sleep off the bulk of the wait, then spin the last few
// milliseconds - usleep alone lands with millisecond-scale jitter, which is the
// same order as the window being aimed at.
$remaining = $startAt - microtime(true);
if ($remaining > 0.01) {
    usleep((int)(($remaining - 0.01) * 1000000));
}
while (microtime(true) < $startAt) {
    // Spin.
}

try {
    // write() calls ensureTable() on its way in - this IS the first use.
    $handler->write($sessionId, ['worker' => $sessionId], 60);
} catch (\Throwable $firstUseFailure) {
    fwrite(STDERR, get_class($firstUseFailure) . ': ' . $firstUseFailure->getMessage());
    exit(1);
}

exit(0);
