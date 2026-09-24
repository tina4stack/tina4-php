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

namespace Tina4\Database;

/**
 * Shared fetchOne() - and the write branch of fetch() - for the database adapters.
 *
 * Every adapter implemented the same body: strip trailing semicolons, run the
 * query, FAIL LOUD if the driver reported an error, otherwise return the first
 * row (or null for genuinely no row). The only engine-specific bit is the
 * human-readable label in the error message, which each adapter supplies via
 * engineLabel().
 *
 * Requires the using class to also provide stripTrailingSemicolons()
 * ({@see SqlNormalizerTrait}), a query() method, and a $lastError property.
 */
trait FetchOneTrait
{
    /** Human-readable engine name used in fetchOne() error messages (e.g. "SQLite3"). */
    abstract protected function engineLabel(): string;

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $sql = self::stripTrailingSemicolons($sql);
        // FAIL LOUD (v3.13.37, DB-contract A): query() clears lastError on
        // entry and records the driver error on failure (returning []), so a
        // non-null lastError after the call means the statement failed — RAISE
        // it instead of returning null (which a caller would read as "no row").
        $rows = $this->query($sql, $params);
        if ($this->lastError !== null) {
            throw new DatabaseException($this->engineLabel() . ' fetchOne() failed: ' . $this->lastError);
        }
        return $rows[0] ?? null;
    }

    /**
     * Run a write that fetch() received, exactly once.
     *
     * A write ({@see SqlStatement::isWrite()}) gets no COUNT probe - wrapping
     * an INSERT in a subquery is a syntax error on every engine - and no
     * LIMIT/OFFSET/ROWS/TOP clause, which is either a syntax error or, worse,
     * silently limits how many rows the write touches. It runs once through
     * query(), which commits like execute() outside a transaction, and total is
     * the number of rows it returned.
     *
     * @param string $sql The write, trailing semicolons already stripped
     * @param array $params Bound parameters
     * @param int $limit Echoed back unchanged
     * @param int $offset Echoed back unchanged
     * @return array{data: array, total: int, limit: int, offset: int}
     * @throws DatabaseException When the statement fails
     */
    protected function fetchWriteOnce(string $sql, array $params, int $limit, int $offset): array
    {
        $rows = $this->query($sql, $params);
        if ($this->lastError !== null) {
            throw new DatabaseException($this->engineLabel() . ' fetch() failed: ' . $this->lastError);
        }
        return ['data' => $rows, 'total' => count($rows), 'limit' => $limit, 'offset' => $offset];
    }
}
