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

namespace Tina4;

/**
 * CrudData (ADR-0094) — the server-side LISTING data access for {@see Crud}.
 *
 * It resolves the safe ORDER BY column (ADR-0069), runs the paginated/filtered
 * query for either a model-driven or a custom-SQL listing, and normalises the
 * result into `[rows, total]`. It owns no HTML and no routes: {@see Crud} calls
 * it to shape the grid, {@see CrudView} renders the rows it returns. The custom
 * SQL path only shapes the DISPLAY — every write still goes through AutoCrud.
 *
 * @internal Part of the Crud frontend; not a public application API.
 */
class CrudData
{
    /**
     * Resolve the safe ORDER BY column (ADR-0069): a model field (resolved to
     * its DB column) or a column of the SQL query's own result set, else the
     * primary key. A rendered page ignores a bad sort, it never errors.
     */
    public static function crudSortColumn(ORM $instance, ?string $sql, ?string $requested, string $pk): string
    {
        if ($requested === null || $requested === '') {
            return $pk;
        }
        if ($sql === null) {
            return $instance->resolveFieldColumn($requested) ?? $pk;
        }
        $cols = self::sqlResultColumns($sql);
        if (!in_array($requested, $cols, true)) {
            return $instance->resolveFieldColumn($requested) ?? $pk;
        }
        return $requested;
    }

    /**
     * Fetch a page of records from the model (ADR-0069 safe search across the
     * model's declared string columns).
     *
     * @param array<string, string> $types
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    public static function fetchModelData(ORM $instance, array $types, string $search, string $sort, string $sortDir, int $limit, int $offset): array
    {
        $orderBy = "{$sort} " . strtoupper($sortDir);

        if ($search === '') {
            $collection = $instance->all($limit, $offset, null, $orderBy);
            $total = $instance->count();
            return [self::collectionToRecords($collection), $total];
        }

        $searchable = [];
        foreach ($types as $column => $type) {
            if ($type === 'string') {
                $searchable[] = $column;
            }
        }

        if ($searchable === []) {
            $collection = $instance->all($limit, $offset, null, $orderBy);
            return [self::collectionToRecords($collection), $instance->count()];
        }

        $clause = implode(' OR ', array_map(static fn (string $col): string => "{$col} LIKE ?", $searchable));
        $params = array_fill(0, count($searchable), "%{$search}%");
        $collection = $instance->where($clause, $params, $limit, $offset, null, $orderBy);
        return [self::collectionToRecords($collection), $collection->getTotalRecords()];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function collectionToRecords(ModelCollection $collection): array
    {
        $records = [];
        foreach ($collection as $model) {
            $records[] = $model->toDict();
        }
        return $records;
    }

    /**
     * Fetch a page of rows for a custom listing SQL. The SQL shapes the DISPLAY
     * only; writes/GET always go through AutoCrud.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    public static function fetchSqlData(string $sql, string $search, string $sort, string $sortDir, int $limit, int $offset): array
    {
        $db = ORM::database();
        $base = self::stripOrderAndLimit($sql);
        $dir = strtoupper($sortDir);

        if ($search === '') {
            $query = "{$base} ORDER BY {$sort} {$dir}";
            return self::normaliseFetch($db->fetch($query, [], $limit, $offset));
        }

        $cols = self::extractColumns($sql);
        $searchParts = array_map(static fn (string $col): string => "CAST({$col} AS TEXT) LIKE ?", $cols);
        $where = implode(' OR ', $searchParts);
        $params = array_fill(0, count($cols), "%{$search}%");
        $query = "SELECT * FROM ({$base}) AS _crud_sub WHERE {$where} ORDER BY {$sort} {$dir}";
        return self::normaliseFetch($db->fetch($query, $params, $limit, $offset));
    }

    /**
     * Normalise an adapter fetch() result (a DatabaseResult, or the SQLite
     * adapter's {data,total,limit,offset} array, or a bare row list) to
     * [rows, total].
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private static function normaliseFetch(mixed $result): array
    {
        if ($result instanceof \Tina4\Database\DatabaseResult) {
            return [$result->records, $result->count];
        }
        if (is_array($result) && array_key_exists('data', $result)) {
            $rows = is_array($result['data']) ? $result['data'] : [];
            return [$rows, (int)($result['total'] ?? count($rows))];
        }
        $rows = is_array($result) ? $result : [];
        return [$rows, count($rows)];
    }

    /**
     * The query with any ORDER BY / LIMIT clause removed, line by line with
     * plain string operations so it stays linear on any input.
     */
    private static function stripOrderAndLimit(string $sql): string
    {
        $out = [];
        foreach (preg_split('/(?<=\n)/', $sql) ?: [$sql] as $line) {
            $out[] = self::cutFromKeyword(self::cutFromKeyword($line, 'ORDER BY '), 'LIMIT ');
        }
        return trim(implode('', $out));
    }

    private static function cutFromKeyword(string $line, string $keyword): string
    {
        $ending = str_ends_with($line, "\n") ? "\n" : '';
        $content = $ending === '' ? $line : substr($line, 0, -1);
        $at = stripos($content, $keyword);
        if ($at === false || strlen($content) <= $at + strlen($keyword)) {
            return $line;
        }
        return substr($content, 0, $at) . $ending;
    }

    /**
     * @return array<int, string>
     */
    private static function sqlResultColumns(string $sql): array
    {
        try {
            $db = ORM::database();
        } catch (\Throwable $e) {
            return [];
        }
        $base = self::stripOrderAndLimit($sql);
        [$rows] = self::normaliseFetch($db->fetch("SELECT * FROM ({$base}) AS _crud_sub", [], 1, 0));
        return $rows === [] ? [] : array_keys($rows[0]);
    }

    /**
     * @return array<int, string>
     */
    private static function extractColumns(string $sql): array
    {
        if (!preg_match('/SELECT\s+(.+?)\s+FROM/is', $sql, $match)) {
            return ['*'];
        }
        $colsStr = trim($match[1]);
        if ($colsStr === '*') {
            return ['*'];
        }
        $columns = [];
        foreach (explode(',', $colsStr) as $c) {
            $c = trim($c);
            if (preg_match('/\bAS\s+(\w+)/i', $c, $m)) {
                $columns[] = $m[1];
            } elseif (str_contains($c, '.')) {
                $parts = explode('.', $c);
                $columns[] = trim(end($parts));
            } else {
                $columns[] = $c;
            }
        }
        return $columns;
    }
}
