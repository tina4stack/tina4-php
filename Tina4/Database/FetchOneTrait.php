<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * Shared fetchOne() for the native database adapters.
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
}
