<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Graph;

/**
 * A raw-query result — records + columns, the same shape as the relational
 * DatabaseResult. Iterable and countable, so `foreach ($result as $row)` yields
 * the associative records directly.
 */
class GraphResult implements \IteratorAggregate, \Countable, \JsonSerializable
{
    /** @var array<int, array<string, mixed>> */
    public array $records;

    /** @var array<int, string> */
    public array $columns;

    /**
     * @param array<int, array<string, mixed>>|null $records Associative rows
     * @param array<int, string>|null $columns Column names
     */
    public function __construct(?array $records = null, ?array $columns = null)
    {
        $this->records = array_values($records ?? []);
        $this->columns = array_values($columns ?? []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return $this->records;
    }

    /**
     * The first cell of the first record, or null.
     *
     * @return mixed
     */
    public function scalar(): mixed
    {
        if ($this->records === []) {
            return null;
        }
        $first = $this->records[0];

        return $first === [] ? null : reset($first);
    }

    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->records);
    }

    public function count(): int
    {
        return count($this->records);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function jsonSerialize(): array
    {
        return $this->records;
    }
}
