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

namespace Tina4\Database;

/**
 * Shared SQLite database-path resolution for the two SQLite adapters.
 *
 * Both PdoSqliteAdapter and SQLite3Adapter open the same kind of path, so they
 * MUST treat a connection string identically — this trait is the single home
 * for that logic, replacing the byte-identical copy that used to live in each
 * adapter (and could silently drift). See the Python/Node masters, which keep
 * the same resolution in one place too.
 */
trait ResolvesDatabasePath
{
    /**
     * Resolve a SQLite path argument against the project root (cwd).
     *
     * Matches the tina4-python and tina4-nodejs convention:
     *   ":memory:"         → passthrough
     *   "data/app.db"      → {cwd}/data/app.db  (auto-mkdir under cwd)
     *   "/abs/app.db"      → /abs/app.db        (NO auto-mkdir)
     *   "C:/Users/app.db"  → C:/Users/app.db    (NO auto-mkdir)
     *
     * Never mkdir outside cwd — that was the root cause of the
     * `Read-only file system: '/data'` crash reported on macOS.
     *
     * @param string $dbPath SQLite file path, or ":memory:"
     * @return string        The resolved path to hand to the driver
     */
    private static function resolveDatabasePath(string $dbPath): string
    {
        if ($dbPath === ':memory:') {
            return $dbPath;
        }

        $isUnixAbs = str_starts_with($dbPath, '/');
        $isWindowsAbs = (
            strlen($dbPath) >= 3
            && ctype_alpha($dbPath[0])
            && $dbPath[1] === ':'
            && ($dbPath[2] === '/' || $dbPath[2] === '\\')
        );

        if ($isUnixAbs || $isWindowsAbs) {
            // Absolute — trust the user. Do NOT auto-mkdir outside cwd.
            return $dbPath;
        }

        // Relative — resolve under cwd and ensure parent exists.
        $resolved = getcwd() . DIRECTORY_SEPARATOR . $dbPath;
        $parent = dirname($resolved);
        if (!is_dir($parent)) {
            @mkdir($parent, 0775, true);
        }
        return $resolved;
    }
}
