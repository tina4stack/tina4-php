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
