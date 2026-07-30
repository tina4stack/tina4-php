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

    // ── Boolean Translation ─────────────────────────────────────────

    /**
     * Translate boolean TRUE/FALSE literals to 1/0 (for Firebird and others
     * that don't support native boolean keywords in SQL).
     */
    public static function booleanToInt(string $sql): string
    {
        // Replace standalone TRUE/FALSE (not inside quotes)
        $sql = preg_replace('/\bTRUE\b/i', '1', $sql);
        $sql = preg_replace('/\bFALSE\b/i', '0', $sql);
        return $sql;
    }

    // ── ILIKE Translation ───────────────────────────────────────────

    /**
     * Translate ILIKE to LOWER(column) LIKE LOWER(value) for databases
     * that don't support ILIKE natively.
     */
    public static function ilikeToLike(string $sql): string
    {
        // Match: column ILIKE 'pattern'
        return preg_replace(
            '/(\S+)\s+ILIKE\s+(\S+)/i',
            'LOWER($1) LIKE LOWER($2)',
            $sql
        );
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

        // Split on || and wrap in CONCAT()
        $parts = preg_split('/\s*\|\|\s*/', $sql);
        if (count($parts) <= 1) {
            return $sql;
        }

        return 'CONCAT(' . implode(', ', array_map('trim', $parts)) . ')';
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
                // Replace "INTEGER PRIMARY KEY AUTOINCREMENT" with "SERIAL PRIMARY KEY"
                $sql = preg_replace(
                    '/INTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT/i',
                    'SERIAL PRIMARY KEY',
                    $sql
                );
                // Also handle standalone AUTOINCREMENT
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
        $out = preg_replace_callback(
            // Match a string literal, a line comment, or a block comment
            // first (preserved as-is); else match :name.
            "/(?:'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"|--[^\n]*|\\/\\*.*?\\*\\/)|:([a-zA-Z_][a-zA-Z0-9_]*)/s",
            function ($m) use ($params, &$reordered) {
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
                return '?';
            },
            $sql
        );

        return [$out, $reordered];
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
                break;

            case 'mssql':
                $sql = self::limitToTop($sql);
                $sql = self::autoIncrementSyntax($sql, 'mssql');
                $sql = self::concatPipesToFunc($sql);
                break;

            case 'mysql':
                $sql = self::autoIncrementSyntax($sql, 'mysql');
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
