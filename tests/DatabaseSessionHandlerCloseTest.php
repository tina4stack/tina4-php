<?php

/**
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;
use Tina4\DotEnv;
use Tina4\Session\DatabaseSessionHandler;

/**
 * DatabaseSessionHandler::close() releases the connection the handler opened
 * itself (from TINA4_DATABASE_URL), and never one the caller injected.
 *
 * It used to be a no-op, so an env-built SQLite session file stayed open for
 * the life of the process; on Windows an open file cannot be deleted. The
 * proof works on every OS: the adapter runs SQLite in WAL mode, and SQLite
 * removes the -wal side file when the LAST connection closes. Real SQLite
 * files, real adapters, no doubles.
 */
class DatabaseSessionHandlerCloseTest extends TestCase
{
    private string $dir;
    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = getcwd();
        $this->dir = sys_get_temp_dir() . '/tina4_sessclose_' . uniqid();
        mkdir($this->dir, 0755, true);
        chdir($this->dir);
        putenv('TINA4_DATABASE_URL=sqlite:///sessions.db');
        $_ENV['TINA4_DATABASE_URL'] = 'sqlite:///sessions.db';
        DotEnv::resetEnv();
    }

    protected function tearDown(): void
    {
        putenv('TINA4_DATABASE_URL');
        unset($_ENV['TINA4_DATABASE_URL'], $_SERVER['TINA4_DATABASE_URL']);
        DotEnv::resetEnv();
        chdir($this->cwd);
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testCloseReleasesAConnectionTheHandlerOpened(): void
    {
        $handler = new DatabaseSessionHandler();
        $handler->write('sess-1', ['user' => 'ada'], 3600);
        $this->assertFileExists($this->dir . '/sessions.db-wal', 'a write opens the database in WAL mode');

        $handler->close();
        clearstatcache();
        $this->assertFileDoesNotExist($this->dir . '/sessions.db-wal', 'SQLite removes -wal on the last close');

        $handler->close(); // idempotent: a second close must not throw

        $back = $handler->read('sess-1');
        $this->assertSame('ada', $back['user'] ?? null, 'the next read reopens the connection');
        $handler->close();

        clearstatcache();
        $this->assertTrue(@unlink($this->dir . '/sessions.db'), 'after close() the session file can be deleted');
    }

    public function testCloseLeavesAnInjectedConnectionOpen(): void
    {
        // A TEMP table lives only inside the one connection that made it, so it
        // proves the caller's connection survived - a reconnect would lose it.
        $adapter = new SQLite3Adapter($this->dir . '/injected.db');
        $adapter->execute('CREATE TEMP TABLE caller_state (x INTEGER)');
        $adapter->execute('INSERT INTO caller_state (x) VALUES (7)');
        $handler = new DatabaseSessionHandler(['db' => $adapter]);
        $handler->write('sess-2', ['user' => 'grace'], 3600);

        $handler->close();

        $rows = $adapter->fetch('SELECT x FROM caller_state');
        $this->assertSame(7, (int) ($rows['data'][0]['x'] ?? 0), 'the caller still owns its live connection');
        $adapter->close();
    }
}
