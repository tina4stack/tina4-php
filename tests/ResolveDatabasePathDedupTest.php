<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\Database\PdoSqliteAdapter;
use Tina4\Database\SQLite3Adapter;

/**
 * Characterisation lock for the SQLite database-path resolver.
 *
 * `resolveDatabasePath()` used to be copy-pasted, byte-for-byte, into BOTH
 * PdoSqliteAdapter and SQLite3Adapter. It is now a single shared home
 * ({@see \Tina4\Database\ResolvesDatabasePath}) used by both. This test pins
 * the exact resolution behaviour for all four input classes against BOTH
 * adapters, so the shared extraction is proven behaviour-preserving and the
 * two adapters can never silently drift again.
 *
 * The resolver is a `private static` pure function (no I/O beyond the relative
 * auto-mkdir), so it is exercised directly by reflection — this is the real
 * method, not a double.
 *
 * Each case runs against both adapters via the `adapters()` data provider, so a
 * failure names the adapter that drifted.
 */
class ResolveDatabasePathDedupTest extends TestCase
{
    /** @var string[] Directories chdir'd out of, restored in tearDown. */
    private ?string $cwdBefore = null;

    protected function tearDown(): void
    {
        if ($this->cwdBefore !== null) {
            chdir($this->cwdBefore);
            $this->cwdBefore = null;
        }
    }

    /**
     * Invoke the private static resolver on the given adapter class.
     *
     * @param class-string $adapterClass
     */
    private function resolve(string $adapterClass, string $dbPath): string
    {
        $method = new \ReflectionMethod($adapterClass, 'resolveDatabasePath');
        return $method->invoke(null, $dbPath);
    }

    /** @return array<string, array{class-string}> */
    public static function adapters(): array
    {
        return [
            'PdoSqliteAdapter' => [PdoSqliteAdapter::class],
            'SQLite3Adapter' => [SQLite3Adapter::class],
        ];
    }

    /**
     * Case 1 — ":memory:" is passed through untouched (no cwd resolution, no mkdir).
     *
     * @param class-string $adapterClass
     */
    #[DataProvider('adapters')]
    public function testMemoryIsPassedThrough(string $adapterClass): void
    {
        $this->assertSame(':memory:', $this->resolve($adapterClass, ':memory:'));
    }

    /**
     * Case 2 — a Unix-absolute path ("/...") is passed through untouched.
     *
     * @param class-string $adapterClass
     */
    #[DataProvider('adapters')]
    public function testUnixAbsoluteIsPassedThrough(string $adapterClass): void
    {
        $abs = '/var/data/tina4/app.db';
        $this->assertSame($abs, $this->resolve($adapterClass, $abs));
    }

    /**
     * Case 3 — a Windows-absolute path (drive-letter, both slash styles) is
     * passed through untouched.
     *
     * @param class-string $adapterClass
     */
    #[DataProvider('adapters')]
    public function testWindowsAbsoluteIsPassedThrough(string $adapterClass): void
    {
        $this->assertSame('C:/Users/app.db', $this->resolve($adapterClass, 'C:/Users/app.db'));
        $this->assertSame('C:\\Users\\app.db', $this->resolve($adapterClass, 'C:\\Users\\app.db'));
    }

    /**
     * Case 4 — a relative path resolves under the current working directory and
     * its parent directory is created.
     *
     * @param class-string $adapterClass
     */
    #[DataProvider('adapters')]
    public function testRelativeResolvesUnderCwdAndCreatesParent(string $adapterClass): void
    {
        $tmpRoot = sys_get_temp_dir() . '/tina4_resolve_' . uniqid('', true);
        mkdir($tmpRoot, 0775, true);
        $this->cwdBefore = getcwd();
        chdir($tmpRoot);

        // Use realpath of the temp root: sys_get_temp_dir() on macOS is a
        // symlink (/var -> /private/var), and getcwd() returns the resolved
        // form, which is what resolveDatabasePath() concatenates against.
        $resolvedRoot = getcwd();
        $relative = 'sub/dir/app.db';
        $expected = $resolvedRoot . DIRECTORY_SEPARATOR . $relative;

        $result = $this->resolve($adapterClass, $relative);

        $this->assertSame($expected, $result, 'relative path resolves under cwd');
        $this->assertDirectoryExists($resolvedRoot . '/sub/dir', 'parent directory was auto-created');

        // Clean up the tree created under the temp root.
        @unlink($expected);
        @rmdir($resolvedRoot . '/sub/dir');
        @rmdir($resolvedRoot . '/sub');
        chdir($this->cwdBefore);
        $this->cwdBefore = null;
        @rmdir($tmpRoot);
    }
}
