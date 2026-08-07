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

    /** @var int Rows affected by a write (insert/update/delete); 0 for reads or when the adapter can't report it */
    public int $affectedRows;

    /** @var int|string|null Last inserted id (insert only); null for update/delete/reads */
    public int|string|null $lastId;

    /** @var ?string Error cause captured for this operation, or null on success */
    public ?string $error;

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
     * @param int $affectedRows Rows affected by a write (0 for reads)
     * @param int|string|null $lastId Last inserted id (insert only)
     * @param ?string $error Error cause, or null on success
     */
    public function __construct(
        array            $records = [],
        array            $columns = [],
        int              $count = 0,
        int              $limit = 100,
        int              $offset = 0,
        ?DatabaseAdapter $adapter = null,
        ?string          $sql = null,
        int              $affectedRows = 0,
        int|string|null  $lastId = null,
        ?string          $error = null,
    ) {
        $this->records      = $records;
        $this->columns      = $columns;
        $this->count        = $count;
        $this->limit        = $limit;
        $this->offset       = $offset;
        $this->adapter      = $adapter;
        $this->sql          = $sql;
        $this->affectedRows = $affectedRows;
        $this->lastId       = $lastId;
        $this->error        = $error;
    }

    // ── Column metadata ──────────────────────────────────────────────

    /**
     * Return column metadata for the query's table.
     *
     * Lazy — only queries the database when explicitly called. Caches the
     * result so subsequent calls return immediately without re-querying.
     *
     * @return array<int, array{name: string, type: string, size: ?int, decimals: ?int, nullable: bool, primaryKey: bool}>
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
     * @return array<int, array{name: string, type: string, size: ?int, decimals: ?int, nullable: bool, primaryKey: bool}>
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
     * @return array<int, array{name: string, type: string, size: ?int, decimals: ?int, nullable: bool, primaryKey: bool}>
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
                'primaryKey' => $col['primaryKey'] ?? false,
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
     * @return array<int, array{name: string, type: string, size: ?int, decimals: ?int, nullable: bool, primaryKey: bool}>
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
            'primaryKey' => false,
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
        // Pass $escape explicitly — default-value usage is deprecated in PHP 8.5,
        // and the default itself will change in PHP 9. Keep the historic backslash
        // escape so existing CSV consumers see no behavioural change.
        fputcsv($output, $headers, ',', '"', '\\');

        foreach ($this->records as $row) {
            $line = [];
            foreach ($headers as $col) {
                $line[] = $row[$col] ?? '';
            }
            fputcsv($output, $line, ',', '"', '\\');
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
     * Describe the page this result already IS as a pagination envelope.
     *
     * Takes NO arguments and derives every field from the query that produced
     * this result (ADR-0043): per_page is the query's limit, page is
     * floor(offset / limit) + 1, total is the true COUNT for the filter (never
     * the number of rows returned), total_pages is ceil(total / per_page), and
     * records is the rows the query returned verbatim, never re-sliced. The
     * envelope is EXACTLY seven snake_case keys, identical across all four Tina4
     * frameworks: records, total, page, per_page, total_pages, limit, offset.
     *
     * To read another page, fetch that page (limit + offset) and call this on
     * the result. An argument is refused: a DatabaseResult holds no database
     * connection, so one could only re-slice the rows already in memory and then
     * report total_pages for pages it can never reach.
     *
     * @return array{records: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int, limit: int, offset: int}
     * @throws \InvalidArgumentException When any argument is passed.
     */
    public function toPaginate(...$args): array
    {
        if ($args !== []) {
            throw new \InvalidArgumentException(
                'toPaginate() takes NO arguments - it describes the page this result '
                . 'already IS, derived from the query that produced it. A DatabaseResult '
                . 'holds no database connection, so an argument could only re-slice the rows '
                . 'already in memory and then report total_pages for pages it can never reach. '
                . 'MEASURED on 100,000 rows read under the default cap of 100: pages 1-5 of 20 '
                . 'were right and pages 6 onward returned NOTHING while the envelope still '
                . 'claimed those pages existed. Fetch the page you want instead: '
                . 'fetch($sql, [], $perPage, ($page - 1) * $perPage), then call toPaginate() '
                . 'with no arguments.'
            );
        }

        $perPage    = $this->limit > 0 ? $this->limit : count($this->records);
        $page       = $perPage > 0 ? (int) floor($this->offset / $perPage) + 1 : 1;
        $totalPages = $perPage > 0 ? max(1, (int) ceil($this->count / $perPage)) : 1;

        return [
            'records'     => $this->records,
            'total'       => $this->count,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
            'limit'       => $perPage,
            'offset'      => $this->offset,
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
