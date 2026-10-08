<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * Worker for tina4-php#277: run the migration runner against a shared SQLite
 * database. The test spawns several of these at once; the run-wide lock must
 * serialize them so the data migration applies exactly once.
 *
 * Usage: php migration_concurrency_277_worker.php <sqlite-file> <migrations-dir>
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$dbFile = $argv[1] ?? '';
$migDir = $argv[2] ?? '';

try {
    $db = \Tina4\Database\Database::create('sqlite:///' . $dbFile);
    $migration = new \Tina4\Migration($db, $migDir);
    $migration->migrate();
    $db->close();
} catch (\Throwable $e) {
    fwrite(STDERR, 'worker error: ' . $e->getMessage() . "\n");
    exit(1);
}
