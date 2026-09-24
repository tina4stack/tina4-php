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
 * Classifies a SQL statement as a read or a write.
 *
 * fetch() and fetchOne() are the natural way to run a write that RETURNS rows
 * (INSERT ... RETURNING id, MSSQL's OUTPUT), so they cannot assume every
 * statement is a read. A write they receive runs exactly once: no COUNT probe,
 * no LIMIT/OFFSET/ROWS/TOP pagination, never served from or stored in the query
 * cache, committed like execute(). This is the one rule every adapter and the
 * cache share, identical to the Python master's
 * DatabaseAdapter._is_write_statement.
 */
final class SqlStatement
{
    use SqlNormalizerTrait;

    private const WRITE_VERBS = ['INSERT', 'UPDATE', 'DELETE', 'MERGE', 'UPSERT', 'REPLACE'];

    /**
     * Whether the statement changes data.
     *
     * After string literals, quoted identifiers and comments are blanked and
     * leading whitespace and brackets are stripped, the statement is a write
     * when its first word is INSERT, UPDATE, DELETE, MERGE, UPSERT or REPLACE,
     * or when it starts with WITH and its body holds INSERT, UPDATE, DELETE or
     * MERGE (a data-modifying CTE ends in a SELECT). Erring towards "write" is
     * the safe direction: a read misread as a write only loses pagination and
     * caching for that call.
     *
     * @param string $sql The statement as the caller wrote it
     * @return bool True when the statement writes
     */
    public static function isWrite(string $sql): bool
    {
        $scrubbed = ltrim(self::scrubSqlText($sql), " \t\r\n(");
        if (preg_match('/^[A-Za-z]+/', $scrubbed, $firstWord) !== 1) {
            return false;
        }
        $verb = strtoupper($firstWord[0]);
        if (in_array($verb, self::WRITE_VERBS, true)) {
            return true;
        }
        return $verb === 'WITH' && preg_match('/\b(INSERT|UPDATE|DELETE|MERGE)\b/i', $scrubbed) === 1;
    }

    /**
     * Whether the statement produces a result set.
     *
     * True for a query (SELECT, VALUES, TABLE, SHOW, DESCRIBE, EXPLAIN,
     * PRAGMA), a procedure call (CALL, EXEC, EXECUTE), a write that returns
     * rows (RETURNING, or SQL Server's OUTPUT INSERTED/DELETED), and a WITH
     * whose main statement is a query or returns rows. A plain write and DDL
     * produce none. Literals and comments are blanked first.
     *
     * @param string $sql The statement as the caller wrote it
     * @return bool True when running it yields rows (possibly zero of them)
     */
    public static function producesRows(string $sql): bool
    {
        $scrubbed = ltrim(self::scrubSqlText($sql), " \t\r\n(");
        if (preg_match('/^[A-Za-z]+/', $scrubbed, $firstWord) !== 1) {
            return false;
        }
        $verb = strtoupper($firstWord[0]);
        $returnsRows = preg_match('/\bRETURNING\b|\bOUTPUT\s+(INSERTED|DELETED)\b/i', $scrubbed) === 1;
        if (in_array($verb, self::QUERY_VERBS, true)) {
            return true;
        }
        if ($verb === 'WITH') {
            return in_array(self::mainVerbAfterCtes($scrubbed), self::QUERY_VERBS, true) || $returnsRows;
        }
        return $returnsRows;
    }

    private const QUERY_VERBS = [
        'SELECT', 'VALUES', 'TABLE', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'PRAGMA', 'CALL', 'EXEC', 'EXECUTE',
    ];

    /** The first statement verb at bracket depth 0 after WITH - the main statement of a CTE. */
    private static function mainVerbAfterCtes(string $scrubbed): string
    {
        $depth = 0;
        $length = strlen($scrubbed);
        for ($position = 4; $position < $length; $position++) {
            $char = $scrubbed[$position];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($depth === 0 && ctype_alpha($char)
                && preg_match('/\G(SELECT|VALUES|TABLE|INSERT|UPDATE|DELETE|MERGE)\b/i', $scrubbed, $match, 0, $position) === 1) {
                return strtoupper($match[1]);
            } elseif ($depth === 0 && ctype_alpha($char)) {
                while ($position + 1 < $length && (ctype_alnum($scrubbed[$position + 1]) || $scrubbed[$position + 1] === '_')) {
                    $position++;
                }
            }
        }
        return '';
    }
}
