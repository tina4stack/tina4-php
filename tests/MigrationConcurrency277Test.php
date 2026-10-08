<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regression for tina4-php#277: startup auto-migration is serialized across
 * processes, so a data migration applies exactly once under concurrency.
 *
 * No mocks. This spawns several REAL php processes (not threads — the bug is
 * cross-process: PHP_CLI_SERVER_WORKERS=4 under `php -S` is four separate
 * processes, each booting from the top with its own static guard) that open the
 * SAME SQLite database and run the migration runner at the same moment. A data
 * migration sleeps before it inserts, to widen the window in which two
 * unsynchronized runs would both decide the migration is pending and both
 * insert. The assertion is the observable outcome: the row exists exactly once
 * and the tracker holds one row for the migration.
 *
 * Without the run-wide lock in Migration::migrate() every overlapping process
 * inserts its own copy; with it, the winner migrates while the rest block, then
 * re-read the applied set and find nothing pending.
 *
 * SQLite is the engine exercised here because every test host has ext-sqlite3.
 * The PostgreSQL/MySQL/MSSQL advisory-lock paths and the Firebird file-lock path
 * are covered by the lab's real-service concurrency run.
 */
class MigrationConcurrency277Test extends TestCase
{
    private const WORKERS = 8;

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tina4-277-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/migrations', 0777, true);

        file_put_contents(
            $this->dir . '/migrations/000001_create_widgets.sql',
            "CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL);\n"
        );

        // A data migration that takes a moment before it writes — a real
        // backfill. The sleep guarantees overlap, so an unsynchronized run
        // inserts WORKERS copies of 'first'.
        file_put_contents(
            $this->dir . '/migrations/000002_seed_first.php',
            <<<'PHP'
            <?php
            class SeedFirst277 extends \Tina4\MigrationBase
            {
                public function up($db): void
                {
                    usleep(600000); // 0.6s — widen the critical section
                    $db->execute("INSERT INTO widgets (name) VALUES ('first')");
                }

                public function down($db): void
                {
                    $db->execute("DELETE FROM widgets WHERE name = 'first'");
                }
            }
            PHP
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/migrations/*') ?: [] as $f) {
            @unlink($f);
        }
        @unlink($this->dir . '/app.db');
        @rmdir($this->dir . '/migrations');
        @rmdir($this->dir);
    }

    public function testConcurrentStartupMigrationsApplyEachMigrationOnce(): void
    {
        $worker = __DIR__ . '/fixtures/migration_concurrency_277_worker.php';
        $dbFile = $this->dir . '/app.db';
        $migDir = $this->dir . '/migrations';

        // Launch all workers as close to simultaneously as possible.
        $procs = [];
        $logs = [];
        for ($i = 0; $i < self::WORKERS; $i++) {
            $log = tempnam(sys_get_temp_dir(), 'tina4-277-worker-');
            $logs[] = $log;
            $cmd = 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker)
                . ' ' . escapeshellarg($dbFile) . ' ' . escapeshellarg($migDir);
            $procs[] = proc_open(
                $cmd,
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
                $pipes,
                $this->dir
            );
        }

        foreach ($procs as $proc) {
            if (is_resource($proc)) {
                // Drain until exit (workers finish within ~1s of the 0.6s sleep).
                $deadline = microtime(true) + 60;
                while (microtime(true) < $deadline) {
                    $status = proc_get_status($proc);
                    if (!$status['running']) {
                        break;
                    }
                    usleep(50000);
                }
                proc_close($proc);
            }
        }

        $workerOutput = '';
        foreach ($logs as $log) {
            $workerOutput .= (string) @file_get_contents($log);
            @unlink($log);
        }

        $sqlite = new \SQLite3($dbFile, SQLITE3_OPEN_READONLY);
        try {
            $firstRows = (int) $sqlite->querySingle("SELECT count(*) FROM widgets WHERE name = 'first'");
            $trackerRows = (int) $sqlite->querySingle(
                "SELECT count(*) FROM tina4_migration WHERE migration_name = '000002_seed_first.php'"
            );
        } finally {
            $sqlite->close();
        }

        $this->assertSame(
            1,
            $firstRows,
            'the data migration must apply exactly once under ' . self::WORKERS
            . " concurrent starts, got {$firstRows} rows named 'first'. Worker output: {$workerOutput}"
        );
        $this->assertSame(
            1,
            $trackerRows,
            "the tracker must hold exactly one row for the migration, got {$trackerRows}"
        );
    }
}
