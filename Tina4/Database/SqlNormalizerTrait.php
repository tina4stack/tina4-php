<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * v3.13.12 — shared SQL-normalisation helpers for adapter classes.
 *
 * The framework wraps user-supplied SQL in fetch() — a COUNT(*)
 * subquery for the pagination probe and a LIMIT/OFFSET (or engine-
 * specific equivalent) appended to the real query. A trailing
 * semicolon in the user's input breaks both: the wrapped form
 * becomes either invalid SQL or two statements where the second
 * is broken.
 *
 * This trait gives every adapter a single normalisation entry point
 * — called at the top of fetch() and fetchOne() — that strips
 * trailing semicolons and whitespace before any wrapping happens.
 * Internal semicolons (between meaningful statements, or inside
 * string literals) are left alone; the driver will reject those
 * if the engine doesn't support multi-statement.
 */
trait SqlNormalizerTrait
{
    /**
     * Strip trailing semicolons and whitespace from user SQL.
     *
     * @param string $sql User-supplied SQL.
     * @return string Normalised SQL safe to wrap with COUNT/LIMIT.
     */
    protected static function stripTrailingSemicolons(string $sql): string
    {
        if ($sql === '') {
            return $sql;
        }
        $stripped = rtrim($sql);
        while ($stripped !== '' && substr($stripped, -1) === ';') {
            $stripped = rtrim(substr($stripped, 0, -1));
        }
        return $stripped;
    }

    /**
     * Detect whether user SQL already ends with a LIMIT clause.
     *
     * fetch()/fetchOne() append `LIMIT {n} OFFSET {m}` for pagination. When
     * the user's own SQL already ends with `... LIMIT 1` (or
     * `... LIMIT 5 OFFSET 10`), appending a second LIMIT produces invalid SQL
     * (`... LIMIT 1 LIMIT 100 OFFSET 0`) that the engine rejects — and the
     * adapter swallows the error and returns an empty result. Adapters that
     * paginate with LIMIT/OFFSET (PostgreSQL, MySQL, SQLite) must skip the
     * append when this returns true.
     *
     * Matches a trailing `LIMIT <int|?|$n|:name> [OFFSET <int|?|$n|:name>]`
     * (with an optional trailing semicolon already stripped by
     * stripTrailingSemicolons()). The MySQL `LIMIT offset, count` comma form
     * is also recognised.
     *
     * @param string $sql User-supplied SQL.
     * @return bool True when a LIMIT clause already terminates the statement.
     */
    protected static function hasTrailingLimit(string $sql): bool
    {
        $val = '(?:\d+|\?|\$\d+|:\w+)';
        return (bool) preg_match(
            '/\bLIMIT\s+' . $val . '(?:\s*,\s*' . $val . ')?'
            . '(?:\s+OFFSET\s+' . $val . ')?\s*$/i',
            $sql
        );
    }

    /**
     * Strip a trailing ORDER BY clause from a SELECT so it can be safely wrapped
     * in a COUNT(*) subquery on SQL Server.
     *
     * SQL Server rejects ``SELECT COUNT(*) FROM (<inner> ORDER BY x) t`` with
     * error 20018 — "The ORDER BY clause is invalid in views, inline functions,
     * derived tables, subqueries, and common table expressions, unless TOP,
     * OFFSET or FOR XML is also specified." So the MSSQL count probe in fetch()
     * silently fell back to 0 (its own try/catch) whenever the user SQL ended in
     * an ORDER BY, even though the rows came back fine (#262). The ORDER BY is
     * meaningless inside a COUNT anyway — strip it before wrapping.
     *
     * Only a TRAILING ORDER BY is removed, and only when it is NOT already
     * legalised by a following TOP / OFFSET / FETCH / FOR clause (those make the
     * ORDER BY valid in a subquery, so leave them intact). A nested ORDER BY
     * inside a derived table / parenthesised subquery is left alone — the regex
     * is anchored to the end of the statement and stops at the first unbalanced
     * close-paren so it never reaches into a subquery's own ORDER BY.
     *
     * @param string $sql User-supplied SQL (semicolons already stripped).
     * @return string SQL safe to wrap in COUNT(*) on SQL Server.
     */
    protected static function stripTrailingOrderBy(string $sql): string
    {
        if (!preg_match('/\bORDER\s+BY\b/i', $sql)) {
            return $sql;
        }

        // Find the LAST TOP-LEVEL ORDER BY — one that is not nested inside a
        // derived table / parenthesised subquery (its preceding text is
        // paren-balanced) AND whose tail to end-of-string stays paren-balanced
        // (so a following ``) z`` proves it actually belonged to a subquery).
        $offset = 0;
        $lastTopLevel = -1;
        while (preg_match('/\bORDER\s+BY\b/i', $sql, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $pos = $m[0][1];
            $before = substr($sql, 0, $pos);
            $balancedBefore = substr_count($before, '(') === substr_count($before, ')');

            $after = substr($sql, $pos);
            $depth = 0;
            $balancedAfter = true;
            for ($i = 0, $len = strlen($after); $i < $len; $i++) {
                if ($after[$i] === '(') {
                    $depth++;
                } elseif ($after[$i] === ')') {
                    if (--$depth < 0) {
                        $balancedAfter = false;
                        break;
                    }
                }
            }

            if ($balancedBefore && $balancedAfter) {
                $lastTopLevel = $pos;
            }
            $offset = $pos + strlen($m[0][0]);
        }

        if ($lastTopLevel === -1) {
            // Every ORDER BY is inside a subquery — none terminates the outer
            // statement, so wrapping in COUNT(*) is already valid. Leave intact.
            return $sql;
        }

        // The trailing ORDER BY is already legalised by a following
        // OFFSET / FETCH / FOR (XML/JSON) — those make it valid in a subquery on
        // SQL Server, so leave it; wrapping in COUNT(*) is fine.
        $tail = substr($sql, $lastTopLevel);
        if (preg_match('/\b(?:OFFSET|FETCH|FOR)\b/i', $tail)) {
            return $sql;
        }

        return rtrim(substr($sql, 0, $lastTopLevel));
    }

    /**
     * Split a possibly-qualified table name into [schema, table].
     *
     * v3.13.14 (#48): a model whose table name is qualified — PostgreSQL
     * "gift_cards.gift_card", MSSQL "dbo.widget", MySQL "otherdb.table",
     * SQLite "attached.table" — lives in that schema/catalog, not the
     * default. Adapters use this so tableExists()/getColumns() query the
     * right namespace instead of matching the whole dotted string as one
     * flat name. Returns [null, $name] for a bare name. Splits on the first
     * dot. Firebird has no schemas, so its adapter ignores this.
     *
     * @return array{0: string|null, 1: string}
     */
    protected static function splitSchema(string $name): array
    {
        $dot = strpos($name, '.');
        if ($dot === false) {
            return [null, $name];
        }
        return [substr($name, 0, $dot), substr($name, $dot + 1)];
    }

    /**
     * Normalise PHP booleans in a bound-parameter list to literals the driver
     * and column type accept.
     *
     * Every PHP DB extension stringifies a bound `false` to '' (ext-pgsql via
     * pg_query_params, mysqli bind_param, ext-sqlite3 SQLITE3_TEXT), which the
     * engine then rejects — PostgreSQL: `invalid input syntax for type
     * boolean: ""`. So `fetch('... WHERE active = ?', [false])` errored and
     * silently returned 0 rows. (Python's psycopg2 binds bool natively.)
     *
     * The literal depends on how the column is stored, mirroring the
     * engine-aware create_table mapping:
     *   - $nativeBoolean = true  (PostgreSQL native BOOLEAN) → 't' / 'f'
     *   - $nativeBoolean = false (SQLite, Firebird → INTEGER; MySQL TINYINT;
     *                             MSSQL BIT) → 1 / 0
     *
     * Only top-level scalars are touched; nulls/strings/numbers pass through.
     *
     * @param array<mixed> $params
     * @return array<mixed>
     */
    protected static function normalizeBoolParams(array $params, bool $nativeBoolean = false): array
    {
        foreach ($params as $key => $value) {
            if (is_bool($value)) {
                $params[$key] = $nativeBoolean ? ($value ? 't' : 'f') : ($value ? 1 : 0);
            }
        }
        return $params;
    }
}
