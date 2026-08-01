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
     * Blank out string literals, quoted identifiers and comments so a keyword
     * search sees only real SQL.
     *
     * Blanks are spaces of the SAME LENGTH (newlines preserved), so offsets and
     * line structure still line up with the original — the caller keeps its own
     * copy and only ever searches this one.
     *
     * MEASURED 2026-08-01 against a real 150-row table with the 100-row cap in
     * force. A detector that looks at the raw text reads a LIMIT that is only
     * ever mentioned in a literal or a comment as "the caller supplied their
     * own cap", drops the cap, and returns the WHOLE TABLE:
     *
     *     SELECT * FROM t WHERE label != 'LIMIT' ORDER BY id     literal
     *     SELECT * FROM t ORDER BY id -- LIMIT 5                 line comment
     *     SELECT * FROM t ORDER BY id (slash-star LIMIT 5 star-slash)   block comment
     *
     * A silently uncapped read of a whole table is the production incident the
     * cap exists to prevent. Ported from the tina4-python master
     * (DatabaseAdapter._scrub_sql_text) so all four frameworks answer
     * identically: `'` and `"` quoting with the doubled-quote escape, `--` to
     * end of line, and slash-star ... star-slash blocks.
     *
     * @param string $sql User-supplied SQL.
     * @return string Same-length copy with literals and comments blanked.
     */
    protected static function scrubSqlText(string $sql): string
    {
        if ($sql === '') {
            return $sql;
        }

        $out = '';
        $i = 0;
        $n = strlen($sql);

        while ($i < $n) {
            $ch = $sql[$i];
            $next = $i + 1 < $n ? $sql[$i + 1] : '';

            // '...' / "..." — literal or quoted identifier. A doubled quote is
            // the embedded-quote escape ('it''s'), not the end of the literal.
            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $out .= ' ';
                $i++;
                while ($i < $n) {
                    if ($sql[$i] === $quote) {
                        if ($i + 1 < $n && $sql[$i + 1] === $quote) {
                            $out .= '  ';
                            $i += 2;
                            continue;
                        }
                        $out .= ' ';
                        $i++;
                        break;
                    }
                    $out .= $sql[$i] === "\n" ? "\n" : ' ';
                    $i++;
                }
                continue;
            }

            // -- line comment, to end of line (the newline itself is kept).
            if ($ch === '-' && $next === '-') {
                while ($i < $n && $sql[$i] !== "\n") {
                    $out .= ' ';
                    $i++;
                }
                continue;
            }

            // /* block comment */ — may span lines; an unterminated one runs
            // to end of input, exactly as the engine would treat it.
            if ($ch === '/' && $next === '*') {
                $out .= '  ';
                $i += 2;
                while ($i < $n && !($sql[$i] === '*' && $i + 1 < $n && $sql[$i + 1] === '/')) {
                    $out .= $sql[$i] === "\n" ? "\n" : ' ';
                    $i++;
                }
                if ($i < $n) {
                    $out .= '  ';
                    $i += 2;
                }
                continue;
            }

            $out .= $ch;
            $i++;
        }

        return $out;
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
     * Matches a trailing `LIMIT <int|?|$n|:name|%s> [OFFSET <...>]` (with an
     * optional trailing semicolon). The MySQL `LIMIT offset, count` comma form
     * is also recognised.
     *
     * Two properties carry the whole contract, and each fails on its own:
     *
     *  - ANCHORED to the end. A bare "contains LIMIT" also matches a LIMIT
     *    inside a SUBQUERY, where the OUTER statement still needs its cap.
     *  - SCRUBBED first ({@see scrubSqlText}). Otherwise a LIMIT living in a
     *    string literal, a `--` comment or a slash-star block reads as a
     *    caller-supplied cap and the row cap is silently dropped. MEASURED:
     *    `SELECT * FROM items LIMIT 3` + a trailing slash-star comment raised
     *    "near LIMIT: syntax error" — the block comment hid the real trailing
     *    LIMIT, so a SECOND one was appended.
     *
     * @param string $sql User-supplied SQL.
     * @return bool True when a LIMIT clause already terminates the statement.
     */
    protected static function hasTrailingLimit(string $sql): bool
    {
        $val = '(?:\d+|\?|\$\d+|:\w+|%s)';
        return (bool) preg_match(
            '/\bLIMIT\s+' . $val . '(?:\s*,\s*' . $val . ')?'
            . '(?:\s+OFFSET\s+' . $val . ')?\s*;?\s*$/i',
            self::scrubSqlText($sql)
        );
    }

    /**
     * Detect whether user SQL already ends with its own SQL-standard row cap
     * (`OFFSET n ROWS FETCH NEXT m ROWS ONLY`) — the MSSQL/ODBC spelling of
     * {@see hasTrailingLimit}.
     *
     * Same two properties: anchored to the end (a FETCH inside a subquery
     * leaves the outer statement uncapped) and scrubbed first (a FETCH named in
     * a comment or literal must not disarm the cap).
     *
     * @param string $sql User-supplied SQL.
     * @return bool True when a FETCH FIRST/NEXT clause terminates the statement.
     */
    protected static function hasTrailingFetch(string $sql): bool
    {
        $val = '(?:\d+|\?|\$\d+|:\w+|%s)';
        return (bool) preg_match(
            '/\bFETCH\s+(?:FIRST|NEXT)\s+' . $val . '\s+ROWS?\s+ONLY\s*;?\s*$/i',
            self::scrubSqlText($sql)
        );
    }

    /**
     * Wrap user SQL in the `SELECT COUNT(*)` probe fetch() uses for its total.
     *
     * The closing paren goes on a NEW LINE. MEASURED: with the paren appended
     * inline, `SELECT * FROM items LIMIT 3 -- c` produced
     * `SELECT COUNT(*) as total FROM (SELECT * FROM items LIMIT 3 -- c)` — the
     * trailing `--` comments out the `)` and the probe dies with "incomplete
     * input". A newline cannot be commented out by a `--` that started on the
     * line above. Trailing semicolons are stripped for the same reason.
     *
     * @param string $sql   User-supplied SQL (the inner SELECT).
     * @param string $alias Subquery alias; engines that require one pass it.
     * @return string The COUNT(*) probe statement.
     */
    protected static function wrapCountSubquery(string $sql, string $alias = ''): string
    {
        $inner = self::stripTrailingSemicolons($sql);
        $suffix = $alias !== '' ? ' AS ' . $alias : '';
        return "SELECT COUNT(*) as total FROM ({$inner}\n){$suffix}";
    }

    /**
     * Append a pagination clause to user SQL on a NEW LINE.
     *
     * The newline is the fix, not cosmetics. MEASURED: appended inline,
     * `SELECT * FROM t ORDER BY id -- note` + ` LIMIT 100 OFFSET 0` puts the
     * cap INSIDE the trailing comment, where the engine silently ignores it and
     * the whole table comes back — the same row-cap bug as a broken detector,
     * but at the append site. Trailing semicolons are stripped first
     * (`SELECT * FROM t;` + `LIMIT 100` is a syntax error).
     *
     * @param string $sql    User-supplied SQL.
     * @param string $clause Engine pagination clause (LIMIT/OFFSET, ROWS, FETCH).
     * @return string SQL with the clause on its own line.
     */
    protected static function appendSqlClause(string $sql, string $clause): string
    {
        return self::stripTrailingSemicolons($sql) . "\n" . $clause;
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
