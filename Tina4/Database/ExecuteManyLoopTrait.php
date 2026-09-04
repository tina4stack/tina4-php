<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * Shared executeMany() loop for adapters whose driver has no dedicated batch
 * primitive: run each parameter row through execute() and count the affected
 * rows.
 *
 * FAIL LOUD: a failing row must NOT be silently swallowed — the old
 * catch-and-continue counted only the rows that did not throw and returned a
 * partial count, hiding data loss. execute() raises on a bad row; let it
 * propagate so the caller (and the facade's transactional batch path) can roll
 * back the whole batch. Atomicity for a batch is provided by
 * Database::insert()/executeMany() (one pinned connection + one transaction);
 * this primitive just runs each row and never hides a failure.
 *
 * SQLite3Adapter does NOT use this trait — its native driver reuses one
 * prepared statement and enforces a strict binding-count check (ADR-0044),
 * so it keeps its own executeMany().
 *
 * Requires the using class to provide an execute() method.
 */
trait ExecuteManyLoopTrait
{
    public function executeMany(string $sql, array $paramsList = []): int
    {
        $totalAffected = 0;
        foreach ($paramsList as $params) {
            $this->execute($sql, $params);
            $totalAffected++;
        }
        return $totalAffected;
    }
}
