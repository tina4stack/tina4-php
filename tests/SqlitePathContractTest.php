<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Exposes the deduped trait's private resolver so the contract can exercise the
 * REAL code (no mock — this IS the shared resolution the two SQLite adapters run).
 */
class SqlitePathProbe
{
    use \Tina4\Database\ResolvesDatabasePath;

    public static function resolvePublic(string $dbPath): string
    {
        return self::resolveDatabasePath($dbPath);
    }
}

/**
 * SQLite path-resolution CONTRACT (ADR-0086), the five cases the four frameworks
 * must agree on. Real resolver, real files, real mkdir — no mocks.
 *
 * Fixture: tina4-documentation/plan/v3/fixtures/sqlite_path_contract.json
 */
class SqlitePathContractTest extends TestCase
{
    private ?string $origCwd = null;
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        if ($this->origCwd !== null) {
            chdir($this->origCwd);
            $this->origCwd = null;
        }
        foreach ($this->tmpDirs as $dir) {
            @exec('rm -rf ' . escapeshellarg($dir));
        }
        $this->tmpDirs = [];
    }

    private function tmpWorkdir(): string
    {
        $dir = sys_get_temp_dir() . '/tina4_pathcontract_' . uniqid('', true);
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    public function testMemoryPassthrough(): void
    {
        $this->assertSame(':memory:', SqlitePathProbe::resolvePublic(':memory:'));
    }

    public function testUnixAbsolutePassthroughNoMkdir(): void
    {
        $absDb = $this->tmpWorkdir() . '/missing/app.db';   // parent does NOT exist
        $this->assertSame($absDb, SqlitePathProbe::resolvePublic($absDb));
        $this->assertDirectoryDoesNotExist(dirname($absDb), 'absolute path must NOT auto-mkdir');
    }

    public function testDriveLetterAbsolutePassthroughNoMkdir(): void
    {
        // A Windows drive-letter path is recognised as absolute on EVERY OS and
        // returned untouched — never re-rooted under cwd.
        $this->assertSame('C:/Users/app.db', SqlitePathProbe::resolvePublic('C:/Users/app.db'));
        $this->assertSame('C:\\Users\\app.db', SqlitePathProbe::resolvePublic('C:\\Users\\app.db'));
    }

    public function testRelativeUnderCwdCreatesParentMode0775(): void
    {
        $this->origCwd = getcwd();
        chdir($this->tmpWorkdir());
        $old = umask(0);   // so the requested 0775 lands unmasked and can be asserted
        try {
            $resolved = SqlitePathProbe::resolvePublic('sub/dir/app.db');
        } finally {
            umask($old);
        }
        $parent = getcwd() . '/sub/dir';
        $this->assertSame($parent . '/app.db', $resolved);
        $this->assertDirectoryExists($parent, 'parent dir auto-created under cwd');
        $this->assertSame(0775, fileperms($parent) & 0777, 'parent created with mode 0775');
    }

    public function testRelativeEscapingCwdIsRefusedNoMkdir(): void
    {
        $root = $this->tmpWorkdir();
        mkdir($root . '/work', 0777, true);
        $this->origCwd = getcwd();
        chdir($root . '/work');
        $outside = $root . '/escaped';
        try {
            SqlitePathProbe::resolvePublic('../escaped/app.db');
            $this->fail('an escaping relative path must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->addToAssertionCount(1);
        }
        $this->assertDirectoryDoesNotExist($outside, 'an escaping relative path must NOT create any directory');
    }
}
