<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * Standard result from any database fetch operation.
 *
 * Mirrors Python's DatabaseResult dataclass. Implements Iterator, Countable,
 * ArrayAccess and JsonSerializable so it can be used naturally in foreach loops,
 * count(), array access, and json_encode().
 *
 * Usage:
 *   $result = $db->fetch("SELECT * FROM users");
 *   foreach ($result as $row) { ... }
 *   echo count($result);          // total row count
 *   echo $result[0]['name'];      // array access
 *   echo json_encode($result);    // JSON of records
 *   echo $result->toJson();       // same
 *   echo $result->toCsv();        // CSV with header row
 */
class DatabaseResult implements \Iterator, \Countable, \ArrayAccess, \JsonSerializable
{
    /** @var array<int, array<string, mixed>> The result rows */
    public array $records;

    /** @var array<int, string> Column names */
    public array $columns;

    /** @var int Total number of matching rows (may exceed count of $records when paginated) */
    public int $count;

    /** @var int The limit (page size) used for this query */
    public int $limit;

    /** @var int The offset (skip) used for this query */
    public int $offset;

    /** @var int Iterator position */
    private int $position = 0;

    /** @var ?DatabaseAdapter Reference to the database adapter for lazy column queries */
    private ?DatabaseAdapter $adapter;

    /** @var ?string The SQL query that produced this result */
    private ?string $sql;

    /** @var ?array Cached column metadata (null until first columnInfo() call) */
    private ?array $columnInfoCache = null;

    /**
     * @param array<int, array<string, mixed>> $records Result rows
     * @param array<int, string> $columns Column names
     * @param int $count Total matching rows
     * @param int $limit Page size
     * @param int $offset Offset
     * @param ?DatabaseAdapter $adapter Database adapter for lazy column queries
     * @param ?string $sql The SQL query that produced this result
     */
    public function __construct(
        array            $records = [],
        array            $columns = [],
        int              $count = 0,
        int              $limit = 100,
        int              $offset = 0,
        ?DatabaseAdapter $adapter = null,
        ?string          $sql = null,
    ) {
        $this->records = $records;
        $this->columns = $columns;
        $this->count   = $count;
        $this->limit   = $limit;
        $this->offset  = $offset;
        $this->adapter = $adapter;
        $this->sql     = $sql;
    }

    // ── Column metadata ──────────────────────────────────────────────

    /**
     * Return column metadata for the query's table.
     *
     * Lazy — only queries the database when explicitly called. Caches the
     * result so subsequent calls return immediately without re-querying.
     *
     * @return array<int, array{name: string, type: string, size: ?int, decimals: ?int, nullable: bool, primary_key: bool}>
     */
    public function columnInfo(): array
    {
        if ($this->columnInfoCache !== null) {
            return $this->columnInfoCache;
        }

        $table = $this->extractTableFromSql();

        if ($this->adapter !== null && $table !== null) {
            try {
                $this->columnInfoCache = $this->queryColumnMetadata($table);
                return $this->columnInfoCache;
            } catch (\Throwable) {
                // Fall through to fallback
            }
        }

        $this->columnInfoCache = $this->fallbackColumnInfo();
        return $this->columnInfoCache;
    }

    /**
     * Extract table name from a SQL query using regex.
     */
    private function extractTableFromSql(): ?string
    {
        if (empty($this->sql)) {
            return null;
        }

        if (preg_match('/\bFROM\s+["\']?(\w+)["\']?/i', $this->sql, $m)) {
            return $m[1];
        }
        if (preg_match('/\bINSERT\s+INTO\s+["\']?(\w+)["\']?/i', $this->sql, $m)) {
            return $m[1];
        }
        if (preg_match('/\bUPDATE\s+["\']?(\w+)["\']?/i', $this->sql, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Query the database adapter for column metadata.
     *
     * @return array<int, array{name: string, type: string, size: ?int, decimals: ?int, nullable: bool, primary_key: bool}>
     */
    private function queryColumnMetadata(string $table): array
    {
        // Try using the adapter's getColumns method
        try {
            $rawCols = $this->adapter->getColumns($table);
            return $this->normalizeAdapterColumns($rawCols);
        } catch (\Throwable) {
            // Fall through to fallback
        }

        return $this->fallbackColumnInfo();
    }

    /**
     * Normalize output from adapter->getColumns() to standard format.
     *
     * @param array<int, array> $rawCols
     * @return array<int, array{name: string, type: string, size: ?int, decimals: ?int, nullable: bool, primary_key: bool}>
     */
    private function normalizeAdapterColumns(array $rawCols): array
    {
        $columns = [];
        foreach ($rawCols as $col) {
            $colType = strtoupper($col['type'] ?? 'UNKNOWN');
            [$size, $decimals] = $this->parseTypeSize($colType);
            $columns[] = [
                'name'        => $col['name'] ?? '',
                'type'        => preg_replace('/\(.*\)/', '', $colType),
                'size'        => $size,
                'decimals'    => $decimals,
                'nullable'    => $col['nullable'] ?? true,
                'primary_key' => $col['primary'] ?? $col['primary_key'] ?? false,
            ];
        }
        return $columns;
    }

    /**
     * Parse size and decimals from a type string like VARCHAR(255) or NUMERIC(10,2).
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function parseTypeSize(string $typeStr): array
    {
        if (preg_match('/\((\d+)(?:\s*,\s*(\d+))?\)/', $typeStr, $m)) {
            $size = (int) $m[1];
            $decimals = isset($m[2]) ? (int) $m[2] : null;
            return [$size, $decimals];
        }
        return [null, null];
    }

    /**
     * Derive basic column info from record keys when no adapter is available.
     *
     * @return array<int, array{name: string, type: string, size: ?int, decimals: ?int, nullable: bool, primary_key: bool}>
     */
    private function fallbackColumnInfo(): array
    {
        $headers = $this->columns;
        if (empty($headers) && !empty($this->records)) {
            $headers = array_keys($this->records[0]);
        }
        if (empty($headers)) {
            return [];
        }

        return array_map(fn(string $name) => [
            'name'        => $name,
            'type'        => 'UNKNOWN',
            'size'        => null,
            'decimals'    => null,
            'nullable'    => true,
            'primary_key' => false,
        ], $headers);
    }

    // ── Conversion methods ──────────────────────────────────────────

    /**
     * Return records as a JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->records, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * Return records as a CSV string with a header row.
     *
     * Uses the $columns property for headers. If columns is empty and records
     * exist, headers are derived from the first record's keys.
     */
    public function toCsv(): string
    {
        $headers = $this->columns;

        if (empty($headers) && !empty($this->records)) {
            $headers = array_keys($this->records[0]);
        }

        if (empty($headers)) {
            return '';
        }

        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers);

        foreach ($this->records as $row) {
            $line = [];
            foreach ($headers as $col) {
                $line[] = $row[$col] ?? '';
            }
            fputcsv($output, $line);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Return the records array (same as accessing ->records directly).
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return $this->records;
    }

    /**
     * Return a pagination-friendly associative array.
     *
     * Includes both canonical keys and aliases for backwards compatibility
     * with clients that use either naming convention.
     *
     * @return array{records: array, data: array, count: int, total: int, limit: int, per_page: int, offset: int, page: int, totalPages: int, total_pages: int}
     */
    public function toPaginate(): array
    {
        $limit      = $this->limit > 0 ? $this->limit : 1;
        $page       = (int) floor($this->offset / $limit) + 1;
        $totalPages = (int) ceil($this->count / $limit);

        return [
            'records'     => $this->records,
            'data'        => $this->records,       // alias for records
            'count'       => $this->count,
            'total'       => $this->count,          // alias for count
            'limit'       => $this->limit,
            'per_page'    => $this->limit,          // alias for limit
            'offset'      => $this->offset,
            'page'        => $page,                 // computed: floor(offset/limit) + 1
            'totalPages'  => $totalPages,           // computed: ceil(count/limit)
            'total_pages' => $totalPages,           // snake_case alias
        ];
    }

    // ── Iterator ────────────────────────────────────────────────────

    public function current(): mixed
    {
        return $this->records[$this->position];
    }

    public function key(): int
    {
        return $this->position;
    }

    public function next(): void
    {
        ++$this->position;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function valid(): bool
    {
        return isset($this->records[$this->position]);
    }

    // ── Countable ───────────────────────────────────────────────────

    /**
     * Returns the total count (not just the number of records in this page).
     */
    public function count(): int
    {
        return $this->count;
    }

    /**
     * Returns the total count — alias for count() for cross-framework parity.
     */
    public function size(): int
    {
        return $this->count;
    }

    // ── ArrayAccess ─────────────────────────────────────────────────

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->records[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->records[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->records[] = $value;
        } else {
            $this->records[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->records[$offset]);
    }

    // ── JsonSerializable ────────────────────────────────────────────

    public function jsonSerialize(): mixed
    {
        return $this->records;
    }
}
