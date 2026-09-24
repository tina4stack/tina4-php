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
