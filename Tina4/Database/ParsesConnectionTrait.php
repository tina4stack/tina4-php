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
 * One connection-string parser for the URL adapters whose parse differs ONLY by
 * default port, instead of one copy per adapter.
 *
 * MySQL and MSSQL parsed a `driver://user:pass@host:port/dbname` URL — or a bare
 * host — into the same {host, port, username, password, database} shape, byte
 * for byte, apart from the default port each supplied. That copy is collapsed
 * here; each adapter keeps a one-line parseConnection() that names its own
 * default port.
 *
 * The consuming adapter provides the private connection fields the bare-host
 * branch falls back to: $username, $password, $port and $database (the
 * constructor-promoted properties MySQLAdapter and MSSQLAdapter already declare).
 *
 * Firebird is deliberately NOT a consumer: its parse adds a host default of "",
 * a TINA4_DATABASE_FIREBIRD_PATH override and path normalisation, and its
 * adapters declare no $port/$database property — engine-specific concerns that
 * live in {@see FirebirdAdapter::parseFirebirdConnection()} instead. PostgreSQL
 * and ODBC build a libpq / PDO DSN string rather than this array, so they are
 * not consumers either.
 */
trait ParsesConnectionTrait
{
    /**
     * Parse a connection string (URL or bare host) into connection params.
     *
     * A `driver://user:pass@host:port/dbname` URL is decomposed field by field,
     * falling back to the constructor credentials when the URL omits them and to
     * $defaultPort when it omits the port. A bare host string uses the
     * constructor params wholesale.
     *
     * @param string $input       A connection URL, or a bare host, or ''
     * @param int    $defaultPort The engine's default port when the URL omits one
     * @return array{host:string, port:int, username:string, password:string, database:string}
     */
    private function parseUrlConnection(string $input, int $defaultPort): array
    {
        if (str_contains($input, '://')) {
            $parts = parse_url($input);
            return [
                'host' => $parts['host'] ?? 'localhost',
                'port' => $parts['port'] ?? $defaultPort,
                'username' => isset($parts['user']) ? urldecode($parts['user']) : $this->username,
                'password' => isset($parts['pass']) ? urldecode($parts['pass']) : $this->password,
                'database' => ltrim($parts['path'] ?? '', '/'),
            ];
        }

        return [
            'host' => $input ?: 'localhost',
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
            'database' => $this->database,
        ];
    }
}
