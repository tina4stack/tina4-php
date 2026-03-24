<?php

/**
 * Tina4 - This is not a 4ramework.
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

    /**
     * @param array<int, array<string, mixed>> $records Result rows
     * @param array<int, string> $columns Column names
     * @param int $count Total matching rows
     * @param int $limit Page size
     * @param int $offset Offset
     */
    public function __construct(
        array $records = [],
        array $columns = [],
        int   $count = 0,
        int   $limit = 10,
        int   $offset = 0,
    ) {
        $this->records = $records;
        $this->columns = $columns;
        $this->count   = $count;
        $this->limit   = $limit;
        $this->offset  = $offset;
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
     * @return array{records: array, count: int, limit: int, offset: int}
     */
    public function toPaginate(): array
    {
        return [
            'records' => $this->records,
            'count'   => $this->count,
            'limit'   => $this->limit,
            'offset'  => $this->offset,
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
