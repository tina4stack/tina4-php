<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * SQL Translation Layer — translates portable SQL into dialect-specific SQL.
 * Matches the Python tina4_python.database.SQLTranslator implementation.
 */

namespace Tina4;

class SQLTranslator
{
    private const SPATIAL_ENGINES = ['postgres', 'postgresql'];
    private const SPATIAL_IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/';

    public static function requireSpatial(string $engine, string $feature): string
    {
        $name = strtolower($engine ?: 'unknown');
        if (!in_array($name, self::SPATIAL_ENGINES, true)) {
            throw new SpatialNotSupportedException(
                "{$feature} is not supported on the '{$name}' database engine. "
                . 'Tina4 GIS support is PostGIS-first: use PostgreSQL with CREATE EXTENSION postgis. '
                . 'Tina4 will not replace a spatial query with an approximate coordinate query. '
                . 'Use separate float longitude/latitude properties only when no GIS behavior is required.'
            );
        }
        return $name;
    }

    public static function spatialIdentifier(string $name, string $what = 'column'): string
    {
        if (!preg_match(self::SPATIAL_IDENTIFIER, $name)) {
            throw new \InvalidArgumentException("Spatial {$what} is not a valid SQL identifier: {$name}");
        }
        return $name;
    }

    public static function pointColumnType(string $engine, int $srid = 4326): string
    {
        self::requireSpatial($engine, 'PointField');
        return "geography(Point,{$srid})";
    }

    public static function spatialIndex(string $engine, string $table, string $column): string
    {
        self::requireSpatial($engine, 'spatial index creation');
        $table = self::spatialIdentifier($table, 'table');
        $column = self::spatialIdentifier($column);
        $index = str_replace('.', '_', $table) . "_{$column}_gist";
        return "CREATE INDEX IF NOT EXISTS {$index} ON {$table} USING GIST ({$column})";
    }

    public static function pointLiteral(string $engine, int $srid = 4326): string
    {
        self::requireSpatial($engine, 'spatial predicates');
        return "ST_SetSRID(ST_MakePoint(?, ?), {$srid})::geography";
    }

    public static function withinDistance(string $engine, string $column, int $srid = 4326): string
    {
        $column = self::spatialIdentifier($column);
        return "ST_DWithin({$column}, " . self::pointLiteral($engine, $srid) . ', ?)';
    }

    public static function distance(string $engine, string $column, int $srid = 4326): string
    {
        $column = self::spatialIdentifier($column);
        return "ST_Distance({$column}, " . self::pointLiteral($engine, $srid) . ')';
    }

    public static function distanceAs(string $engine, string $column, string $alias, int $srid = 4326): string
    {
        return self::distance($engine, $column, $srid) . ' AS ' . self::spatialIdentifier($alias, 'result alias');
    }

    public static function geometryLiteral(string $engine, string $form = 'ewkt', int $srid = 4326): string
    {
        self::requireSpatial($engine, 'spatial predicates');
        return match ($form) {
            'ewkt' => 'ST_GeogFromText(?)',
            'geojson' => "ST_SetSRID(ST_GeomFromGeoJSON(?), {$srid})::geography",
            default => throw new \InvalidArgumentException("Unsupported spatial geometry form: {$form}"),
        };
    }

    public static function intersects(string $engine, string $column, string $form = 'ewkt', int $srid = 4326): string
    {
        $column = self::spatialIdentifier($column);
        return "ST_Intersects({$column}, " . self::geometryLiteral($engine, $form, $srid) . ')';
    }

    public static function bbox(string $engine, string $column, int $srid = 4326): string
    {
        self::requireSpatial($engine, 'bbox()');
        $column = self::spatialIdentifier($column);
        return "ST_Intersects({$column}, ST_MakeEnvelope(?, ?, ?, ?, {$srid})::geography)";
    }
    /** @var array<string, array{value: mixed, expiresAt: float}> Query cache */
    private static array $cache = [];

    /** @var int Default cache TTL in seconds */
    private static int $defaultTtl = 300;

    // ── LIMIT / OFFSET Translation ──────────────────────────────────

    /**
     * Translate LIMIT/OFFSET to Firebird ROWS X TO Y syntax.
     *
     * LIMIT 10 OFFSET 5 => ROWS 6 TO 15
     * LIMIT 10           => ROWS 1 TO 10
     */
    public static function limitToRows(string $sql): string
    {
        // LIMIT n OFFSET m
        if (preg_match('/^(.+)\s+LIMIT\s+(\d+)\s+OFFSET\s+(\d+)\s*$/is', $sql, $m)) {
            $offset = (int)$m[3];
            $limit = (int)$m[2];
            $start = $offset + 1;
            $end = $offset + $limit;
            return $m[1] . " ROWS {$start} TO {$end}";
        }

        // LIMIT n only
        if (preg_match('/^(.+)\s+LIMIT\s+(\d+)\s*$/is', $sql, $m)) {
            $limit = (int)$m[2];
            return $m[1] . " ROWS 1 TO {$limit}";
        }

        return $sql;
    }

    /**
     * Translate LIMIT to MSSQL TOP N syntax.
     * Only works for LIMIT without OFFSET (TOP doesn't support OFFSET).
     *
     * SELECT * FROM users LIMIT 10 => SELECT TOP 10 * FROM users
     */
    public static function limitToTop(string $sql): string
    {
        // If OFFSET is present, TOP can't handle it — return unchanged
        if (preg_match('/OFFSET\s+\d+/i', $sql)) {
            return $sql;
        }

        if (preg_match('/^(SELECT)\s+(.+)\s+LIMIT\s+(\d+)\s*$/is', $sql, $m)) {
            return $m[1] . ' TOP ' . $m[3] . ' ' . $m[2];
        }

        return $sql;
    }

    // ── Literal-safe rewriting ──────────────────────────────────────
    //
    // A dialect rewrite (|| -> CONCAT, TRUE -> 1, ILIKE -> LOWER LIKE) must NEVER
    // touch text inside a string literal, a quoted identifier or a comment: a
    // column value of 'a||b', a label 'TRUE', or a LIKE pattern that mentions
    // ILIKE is DATA, not SQL. Each transform masks every literal/identifier/
    // comment to an opaque token, rewrites the masked SQL, then restores the
    // tokens, so the rewrite only ever sees real SQL structure.

    /**
     * A concat/ilike operand: a masked literal-or-identifier token, a simple
     * function call, a (qualified) identifier, a placeholder, or a number. The
     * function-call args exclude `|` so a nested `||` never splits the chain.
     */
    private const PRIMARY = '(?:\x00\d+\x00|[A-Za-z_][\w$]*\s*\([^()|]*\)|[A-Za-z_][\w$]*(?:\.[A-Za-z_][\w$]*)*|:[A-Za-z_]\w*|\$\d+|\?|%s|\d+(?:\.\d+)?)';

    /**
     * Replace string literals, quoted identifiers and comments with opaque
     * `\x00N\x00` tokens (doubled-quote escapes handled).
     *
     * @return array{0: string, 1: array<int, string>} [maskedSql, literals]
     */
    private static function maskLiterals(string $sql): array
    {
        $literals = [];
        $out = '';
        $i = 0;
        $n = strlen($sql);
        while ($i < $n) {
            $c = $sql[$i];
            $next = $i + 1 < $n ? $sql[$i + 1] : '';
            if ($c === "'" || $c === '"' || $c === '`') {
                $start = $i;
                $i++;
                while ($i < $n) {
                    if ($sql[$i] === $c) {
                        if ($i + 1 < $n && $sql[$i + 1] === $c) {
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                $out .= "\x00" . count($literals) . "\x00";
                $literals[] = substr($sql, $start, $i - $start);
                continue;
            }
            if ($c === '-' && $next === '-') {
                $start = $i;
                while ($i < $n && $sql[$i] !== "\n") {
                    $i++;
                }
                $out .= "\x00" . count($literals) . "\x00";
                $literals[] = substr($sql, $start, $i - $start);
                continue;
            }
            if ($c === '/' && $next === '*') {
                $start = $i;
                $i += 2;
                while ($i < $n && !($sql[$i] === '*' && $i + 1 < $n && $sql[$i + 1] === '/')) {
                    $i++;
                }
                $i = min($i + 2, $n);
                $out .= "\x00" . count($literals) . "\x00";
                $literals[] = substr($sql, $start, $i - $start);
                continue;
            }
            $out .= $c;
            $i++;
        }
        return [$out, $literals];
    }

    /**
     * Inverse of {@see maskLiterals()}.
     *
     * @param array<int, string> $literals
     */
    private static function restoreLiterals(string $masked, array $literals): string
    {
        return preg_replace_callback('/\x00(\d+)\x00/', static fn($m) => $literals[(int) $m[1]], $masked);
    }

    // ── Boolean Translation ─────────────────────────────────────────

    /**
     * Translate a bare boolean TRUE/FALSE literal to 1/0 (for Firebird/MSSQL and
     * others without native boolean keywords in SQL). A TRUE/FALSE INSIDE a
     * string literal is data and is left untouched (`WHERE label = 'TRUE'`).
     */
    public static function booleanToInt(string $sql): string
    {
        if (!preg_match('/\b(?:TRUE|FALSE)\b/i', $sql)) {
            return $sql;
        }
        [$masked, $literals] = self::maskLiterals($sql);
        $masked = preg_replace('/\bTRUE\b/i', '1', $masked);
        $masked = preg_replace('/\bFALSE\b/i', '0', $masked);
        return self::restoreLiterals($masked, $literals);
    }

    // ── ILIKE Translation ───────────────────────────────────────────

    /**
     * Translate ILIKE to LOWER(column) LIKE LOWER(value) for databases
     * that don't support ILIKE natively.
     */
    public static function ilikeToLike(string $sql): string
    {
        if (stripos($sql, 'ilike') === false) {
            return $sql;
        }
        [$masked, $literals] = self::maskLiterals($sql);
        // The pattern operand is captured WHOLE (a masked token), so a multi-word
        // '%two words%' survives instead of being truncated by a greedy \S+.
        $pattern = '/(' . self::PRIMARY . ')\s+ILIKE\s+(' . self::PRIMARY . ')/i';
        $rewritten = preg_replace_callback(
            $pattern,
            static fn($m) => 'LOWER(' . $m[1] . ') LIKE LOWER(' . $m[2] . ')',
            $masked
        );
        return self::restoreLiterals($rewritten, $literals);
    }

    // ── Concatenation Translation ───────────────────────────────────

    /**
     * Translate || concatenation to CONCAT() function.
     * 'a' || 'b' || 'c' => CONCAT('a', 'b', 'c')
     */
    public static function concatPipesToFunc(string $sql): string
    {
        if (strpos($sql, '||') === false) {
            return $sql;
        }
        [$masked, $literals] = self::maskLiterals($sql);
        if (strpos($masked, '||') === false) {
            return $sql; // every || was inside a literal or comment
        }
        // Rewrite ONLY the operand chain, never the whole statement:
        //   SELECT a || b FROM t  ->  SELECT CONCAT(a, b) FROM t
        $chain = '/' . self::PRIMARY . '(?:\s*\|\|\s*' . self::PRIMARY . ')+/';
        $rewritten = preg_replace_callback(
            $chain,
            static fn($m) => 'CONCAT(' . implode(', ', preg_split('/\s*\|\|\s*/', $m[0])) . ')',
            $masked
        );
        return self::restoreLiterals($rewritten, $literals);
    }

    // ── Auto-Increment Syntax ───────────────────────────────────────

    /**
     * Translate generic AUTOINCREMENT to the appropriate syntax for
     * the target database dialect.
     *
     * @param string $sql    The SQL statement
     * @param string $dialect One of: mysql, postgresql, mssql, firebird, sqlite
     */
    public static function autoIncrementSyntax(string $sql, string $dialect): string
    {
        $dialect = strtolower($dialect);

        switch ($dialect) {
            case 'mysql':
                return str_ireplace('AUTOINCREMENT', 'AUTO_INCREMENT', $sql);

            case 'postgresql':
                // BIGINT  PRIMARY KEY AUTOINCREMENT -> BIGSERIAL (a real 64-bit
                // sequence); INTEGER PRIMARY KEY AUTOINCREMENT -> SERIAL. A plain
                // BIGINT with the keyword merely stripped has no sequence and
                // cannot auto-increment.
                $sql = preg_replace(
                    '/\bBIGINT\s+PRIMARY\s+KEY\s+AUTOINCREMENT\b/i',
                    'BIGSERIAL PRIMARY KEY',
                    $sql
                );
                $sql = preg_replace(
                    '/\bINTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT\b/i',
                    'SERIAL PRIMARY KEY',
                    $sql
                );
                // Any leftover AUTOINCREMENT is not valid PostgreSQL syntax.
                return str_ireplace('AUTOINCREMENT', '', $sql);

            case 'mssql':
                return str_ireplace('AUTOINCREMENT', 'IDENTITY(1,1)', $sql);

            case 'firebird':
                // Firebird uses generators/sequences, strip AUTOINCREMENT
                return str_ireplace('AUTOINCREMENT', '', $sql);

            case 'sqlite':
            default:
                return $sql;
        }
    }

    // ── DDL Type Translation ────────────────────────────────────────

    /**
     * Translate SQLite-canonical DDL column TYPES + CREATE-TABLE options to the
     * target engine.
     *
     * ONLY acts on `CREATE TABLE` / `ALTER TABLE` statements, so a query or
     * INSERT that happens to contain the word `TEXT` (a column name, a string
     * literal) is never rewritten. Complements {@see autoIncrementSyntax()}
     * (which maps the id keyword) so ONE portable migration — and every
     * `ORM::createTable()` DDL, which is also SQLite-canonical — applies on
     * every engine instead of failing on Firebird/MSSQL.
     *
     * - Firebird has no `TEXT` (-607), no `REAL`, and no
     *   `CREATE TABLE IF NOT EXISTS`.
     * - MSSQL has no `CREATE TABLE IF NOT EXISTS` and its `TIMESTAMP` is a
     *   rowversion, not a datetime — a `created_at TIMESTAMP` there is wrong.
     * - MySQL's `TIMESTAMP` carries auto-update / 2038 surprises, so a datetime
     *   column maps to `DATETIME` (matching ORM::createTable()).
     *
     * @param string $sql    The DDL statement (or any statement — non-DDL is returned unchanged)
     * @param string $engine One of: firebird, mssql, mysql (others pass through)
     */
    public static function ddlTypes(string $sql, string $engine): string
    {
        // Gate to DDL only, tolerating leading `-- ...` comment lines / blank
        // lines that a migration file carries before its CREATE TABLE. A SELECT
        // or INSERT that merely mentions a type keyword is never rewritten.
        $head = preg_replace('/^(?:\s*--[^\n]*\n)+/', '', $sql);
        if (!preg_match('/^\s*(CREATE\s+TABLE|ALTER\s+TABLE)\b/i', $head)) {
            return $sql;
        }

        switch (strtolower($engine)) {
            case 'firebird':
                $sql = preg_replace('/\bIF\s+NOT\s+EXISTS\b/i', '', $sql);
                // Map bare TEXT -> BLOB SUB_TYPE TEXT, but leave an existing
                // "BLOB SUB_TYPE TEXT" intact (it already contains the word TEXT).
                $sql = preg_replace('/\bBLOB\s+SUB_TYPE\s+TEXT\b/i', "\x00FBTEXT\x00", $sql);
                $sql = preg_replace('/\bTEXT\b/i', 'BLOB SUB_TYPE TEXT', $sql);
                $sql = str_replace("\x00FBTEXT\x00", 'BLOB SUB_TYPE TEXT', $sql);
                $sql = preg_replace('/\bREAL\b/i', 'DOUBLE PRECISION', $sql);
                return $sql;

            case 'mssql':
                $sql = preg_replace('/\bIF\s+NOT\s+EXISTS\b/i', '', $sql);
                return preg_replace('/\bTIMESTAMP\b/i', 'DATETIME2', $sql);

            case 'mysql':
                return preg_replace('/\bTIMESTAMP\b/i', 'DATETIME', $sql);

            default:
                return $sql;
        }
    }

    // ── Placeholder Translation ─────────────────────────────────────

    /**
     * Translate ? placeholders to a target style.
     *
     * @param string $sql   SQL with ? placeholders
     * @param string $style Target style: '%s' for sprintf-style, ':' for numbered (:1, :2, ...)
     */
    public static function placeholderStyle(string $sql, string $style): string
    {
        if ($style === '%s') {
            return str_replace('?', '%s', $sql);
        }

        if ($style === ':') {
            $counter = 0;
            return preg_replace_callback('/\?/', function () use (&$counter) {
                $counter++;
                return ':' . $counter;
            }, $sql);
        }

        return $sql;
    }

    /**
     * Hard per-statement bind-parameter ceiling per engine. 0 = never collapse.
     * Sourced from tests/fixtures/batch_write_contract.json, byte-identical in
     * all four frameworks.
     */
    public const MAX_BIND_PARAMS = [
        'sqlite' => 999,
        'postgres' => 65535,
        'mysql' => 65535,
        'mssql' => 2100,
        'firebird' => 0,
        'odbc' => 0,
        'mongodb' => 0,
    ];

    /**
     * The four frameworks do not agree on what an engine calls itself — PHP and
     * Python report "postgresql", Ruby and Node report "postgres". Without
     * normalising, the cap lookup misses and the collapse silently does nothing
     * on the engine with the largest win.
     */
    public const ENGINE_ALIASES = [
        'postgresql' => 'postgres',
        'pgsql' => 'postgres',
        'sqlite3' => 'sqlite',
        'sqlserver' => 'mssql',
        'sqlsrv' => 'mssql',
        'mariadb' => 'mysql',
    ];

    /**
     * Engines whose lastInsertId() reports the FIRST generated id of a
     * multi-row INSERT rather than the last. Verified live, not assumed: a
     * 3-row insert into a fresh MySQL table reports 1 while MAX(id) is 3.
     * SQLite, PostgreSQL and MSSQL already report the last, so collapsing a
     * batch does not change them.
     */
    public const FIRST_ID_ENGINES = ['mysql'];

    /**
     * Normalise a collapsed batch's last id to the LAST row's id.
     *
     * A row-at-a-time batch reports the last row's id simply because the last
     * statement inserted the last row. Collapsing rows into one statement
     * changes that on any engine that reports the FIRST generated id, so this
     * restores the contract instead of quietly redefining it.
     *
     * The ids generated by a single multi-row INSERT are consecutive, so the
     * last is `first + rows - 1`.
     *
     * @param mixed $reportedId What the driver reported
     * @param int $rowsInChunk Rows written by that one statement
     * @param string $engine Engine name as the adapter reports it (aliases ok)
     * @return mixed The last row's id, or $reportedId unchanged
     */
    public static function batchLastId(mixed $reportedId, int $rowsInChunk, string $engine): mixed
    {
        $name = strtolower($engine);
        $name = self::ENGINE_ALIASES[$name] ?? $name;
        if (!in_array($name, self::FIRST_ID_ENGINES, true)) {
            return $reportedId;
        }
        if (!is_numeric($reportedId)) {
            return $reportedId;              // UUID/ULID key — no successor
        }
        return (int)$reportedId + max($rowsInChunk, 1) - 1;
    }

    /**
     * Collapse a row-at-a-time INSERT batch into chunked multi-row VALUES.
     *
     * A batch that loops one INSERT per row pays a full network round-trip per
     * row, and the round-trip — not SQL building — is the entire cost of a batch
     * write. Measured over 500 rows: PostgreSQL 9848ms row-at-a-time against
     * 15.8ms as a single multi-row statement (625x), MySQL 216x, MSSQL 121x.
     *
     * Pure: no I/O and no engine contact, so the chunking rules are checkable
     * without a database. The live-engine runners prove the rows land.
     *
     * @param string $sql The single-row INSERT the batch would loop
     * @param array<int, array<int, mixed>> $paramsList One entry per row
     * @param string $engine Engine name as the adapter reports it (aliases ok)
     * @return array<int, array{0: string, 1: array<int, mixed>}> Statements to
     *         run INSTEAD of the loop, or an EMPTY array meaning "not
     *         collapsible — keep looping", which is always correct.
     */
    public static function buildBatchInserts(string $sql, array $paramsList, string $engine): array
    {
        if (count($paramsList) < 2) {
            return [];
        }

        $name = strtolower($engine);
        $name = self::ENGINE_ALIASES[$name] ?? $name;
        $cap = self::MAX_BIND_PARAMS[$name] ?? 0;
        if ($cap <= 0) {
            // Firebird has no multi-row VALUES syntax; ODBC's real ceiling
            // depends on the driver behind it. Emitting SQL the engine cannot
            // parse to save a round-trip is not a trade worth making.
            return [];
        }

        $upper = strtoupper($sql);
        // A collapsed statement returns N rows where the caller expects one, and
        // conflict arbitration changes once rows share a statement.
        if (str_contains($upper, 'RETURNING')
            || str_contains($upper, 'ON CONFLICT')
            || str_contains($upper, 'ON DUPLICATE KEY')) {
            return [];
        }

        if (!preg_match('/^\s*INSERT\s+INTO\s+.+?\s+VALUES\s*\(([^()]*)\)\s*$/is', $sql, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        // Every slot must be a bare placeholder. `now()` repeated per row inside
        // one statement is not the same write as `now()` evaluated per statement.
        $slots = array_map('trim', explode(',', $m[1][0]));
        if ($slots === []) {
            return [];
        }
        foreach ($slots as $slot) {
            if ($slot !== '?') {
                return [];
            }
        }

        $columns = count($slots);
        foreach ($paramsList as $params) {
            if (count($params) !== $columns) {
                return [];
            }
        }

        $chunkRows = max(1, intdiv($cap, $columns));
        if ($chunkRows < 2) {
            return [];
        }

        $head = rtrim(substr($sql, 0, $m[1][1] - 1));
        $oneRow = '(' . implode(', ', array_fill(0, $columns, '?')) . ')';

        $statements = [];
        foreach (array_chunk($paramsList, $chunkRows) as $chunk) {
            $flat = [];
            foreach ($chunk as $params) {
                foreach (array_values($params) as $value) {
                    $flat[] = $value;
                }
            }
            $statements[] = [
                $head . ' ' . implode(', ', array_fill(0, count($chunk), $oneRow)),
                $flat,
            ];
        }

        return $statements;
    }

    /**
     * Translate :named placeholders to ? positional, reordering $params to
     * match the order of occurrence in the SQL. Designed for adapters whose
     * underlying driver only speaks positional placeholders (mysqli, sqlsrv,
     * ibase/fbird, pgsql) but whose callers (ORM save(), QueryBuilder) emit
     * :named because that is what PDO would accept.
     *
     * Behaviour:
     *   - Skips string literals ('…' and "…") and SQL comments
     *     (-- … line comments and / * … * / block comments) so a literal
     *     :colon inside a string is never touched.
     *   - Duplicate names bind one value per occurrence — `WHERE id = :id
     *     AND parent_id = :id` adds the value to the output array twice.
     *   - Accepts both ':name' and 'name' as keys in $params (PDO-style
     *     and either-or).
     *   - Unknown :name (not in $params) is left in place; the driver
     *     surfaces the real "no such placeholder" error.
     *   - SQL with no colons or no :name tokens passes through unchanged,
     *     with $params reduced to array_values() for safety.
     *
     * @param string $sql    SQL that may contain :named placeholders.
     * @param array  $params Associative array keyed by :name or name.
     * @return array{0: string, 1: array} [translatedSql, orderedValues]
     */
    public static function namedToPositional(string $sql, array $params): array
    {
        if (!str_contains($sql, ':')) {
            return [$sql, array_values($params)];
        }

        $reordered = [];
        $didReplace = false;
        $out = preg_replace_callback(
            // Match a string literal, a line comment, or a block comment
            // first (preserved as-is); else match :name.
            "/(?:'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"|--[^\n]*|\\/\\*.*?\\*\\/)|(?<!:):([a-zA-Z_][a-zA-Z0-9_]*)/s",
            function ($m) use ($params, &$reordered, &$didReplace) {
                if (!isset($m[1]) || $m[1] === '') {
                    return $m[0]; // string or comment, preserved
                }
                $name = $m[1];
                if (array_key_exists(':' . $name, $params)) {
                    $reordered[] = $params[':' . $name];
                } elseif (array_key_exists($name, $params)) {
                    $reordered[] = $params[$name];
                } else {
                    return ':' . $name; // unknown — leave it for the driver to complain
                }
                $didReplace = true;
                return '?';
            },
            $sql
        );

        // PostgreSQL casts (`value::geography`) contain a colon but are not
        // named parameters. If no actual :name was replaced, retain ordinary
        // positional values instead of silently dropping every binding.
        return [$out, $didReplace ? $reordered : array_values($params)];
    }

    // ── RETURNING Clause Handling ────────────────────────────────────

    /**
     * Check if a SQL statement has a RETURNING clause.
     */
    public static function hasReturning(string $sql): bool
    {
        return (bool)preg_match('/\bRETURNING\b/i', $sql);
    }

    /**
     * Extract and strip the RETURNING clause from a SQL statement.
     *
     * @return array{sql: string, columns: string[]}
     */
    public static function extractReturning(string $sql): array
    {
        if (preg_match('/^(.+)\s+RETURNING\s+(.+)$/is', $sql, $m)) {
            $columns = array_map('trim', explode(',', $m[2]));
            return ['sql' => trim($m[1]), 'columns' => $columns];
        }

        return ['sql' => $sql, 'columns' => []];
    }

    // ── Custom Function Mapping ─────────────────────────────────────

    /** @var array<string, callable> Registered custom function mappings */
    private static array $functionMap = [];

    /**
     * Register a custom SQL function mapping.
     *
     * @param string   $name   The function name to recognize in SQL
     * @param callable $mapper Function receiving (string $sql) and returning translated SQL
     */
    public static function registerFunction(string $name, callable $mapper): void
    {
        self::$functionMap[strtoupper($name)] = $mapper;
    }

    /**
     * Apply all registered function mappings to a SQL statement.
     */
    public static function applyFunctionMappings(string $sql): string
    {
        foreach (self::$functionMap as $name => $mapper) {
            if (stripos($sql, $name) !== false) {
                $sql = $mapper($sql);
            }
        }
        return $sql;
    }

    /**
     * Clear all registered function mappings.
     */
    public static function clearFunctions(): void
    {
        self::$functionMap = [];
    }

    // ── Query Caching ───────────────────────────────────────────────

    /**
     * Set the default cache TTL.
     */
    public static function setCacheTtl(int $seconds): void
    {
        self::$defaultTtl = $seconds;
    }

    /**
     * Generate a cache key for a query + parameters.
     */
    public static function queryKey(string $sql, array $params = []): string
    {
        return 'query:' . md5($sql . json_encode($params));
    }

    /**
     * Get a cached query result.
     *
     * @return mixed|null The cached value or null if not found/expired
     */
    public static function cacheGet(string $key): mixed
    {
        if (!isset(self::$cache[$key])) {
            return null;
        }

        $entry = self::$cache[$key];
        if (microtime(true) > $entry['expiresAt']) {
            unset(self::$cache[$key]);
            return null;
        }

        return $entry['value'];
    }

    /**
     * Store a query result in the cache.
     *
     * @param string $key   Cache key
     * @param mixed  $value Value to cache
     * @param int    $ttl   TTL in seconds (0 = use default)
     */
    public static function cacheSet(string $key, mixed $value, int $ttl = 0): void
    {
        $ttl = $ttl > 0 ? $ttl : self::$defaultTtl;
        self::$cache[$key] = [
            'value' => $value,
            'expiresAt' => microtime(true) + $ttl,
        ];
    }

    /**
     * Remember pattern — return cached value or compute and cache it.
     *
     * @param string   $key     Cache key
     * @param int      $ttl     TTL in seconds
     * @param callable $factory Factory function to compute the value
     * @return mixed
     */
    public static function remember(string $key, int $ttl, callable $factory): mixed
    {
        $cached = self::cacheGet($key);
        if ($cached !== null) {
            return $cached;
        }

        $value = $factory();
        self::cacheSet($key, $value, $ttl);
        return $value;
    }

    /**
     * Remove expired entries from the cache.
     *
     * @return int Number of entries removed
     */
    public static function cacheSweep(): int
    {
        $removed = 0;
        $now = microtime(true);

        foreach (self::$cache as $key => $entry) {
            if ($now > $entry['expiresAt']) {
                unset(self::$cache[$key]);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Clear the entire query cache.
     */
    public static function cacheClear(): void
    {
        self::$cache = [];
    }

    /**
     * Get the number of entries in the cache.
     */
    public static function cacheSize(): int
    {
        return count(self::$cache);
    }

    // ── Full Dialect Translation ────────────────────────────────────

    /**
     * Translate a SQL statement for a specific database dialect.
     * Applies all relevant translations in order.
     *
     * @param string $sql     The SQL statement
     * @param string $dialect Target dialect: firebird, mssql, mysql, postgresql, sqlite
     * @return string Translated SQL
     */
    public static function translate(string $sql, string $dialect): string
    {
        $dialect = strtolower($dialect);

        switch ($dialect) {
            case 'firebird':
                $sql = self::limitToRows($sql);
                $sql = self::booleanToInt($sql);
                $sql = self::ilikeToLike($sql);
                $sql = self::autoIncrementSyntax($sql, 'firebird');
                $sql = self::ddlTypes($sql, 'firebird');
                break;

            case 'mssql':
                $sql = self::limitToTop($sql);
                $sql = self::autoIncrementSyntax($sql, 'mssql');
                // MSSQL has BIT, not a boolean type, so bare TRUE/FALSE must
                // become 1/0 (a TRUE/FALSE inside a string literal is left
                // untouched). Mirrors the Python master's mssql.py.
                $sql = self::booleanToInt($sql);
                $sql = self::ddlTypes($sql, 'mssql');
                $sql = self::concatPipesToFunc($sql);
                break;

            case 'mysql':
                $sql = self::autoIncrementSyntax($sql, 'mysql');
                $sql = self::ddlTypes($sql, 'mysql');
                break;

            case 'postgresql':
                $sql = self::autoIncrementSyntax($sql, 'postgresql');
                break;

            case 'sqlite':
            default:
                // SQLite supports most standard SQL, minimal translation needed
                break;
        }

        // Always apply custom function mappings
        $sql = self::applyFunctionMappings($sql);

        return $sql;
    }
}
