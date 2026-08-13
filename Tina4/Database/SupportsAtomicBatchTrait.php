<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * ADR-0044 / DBA-P02 (adapter_contract.json): whether this adapter's
 * deployment can guarantee an atomic multi-row batch write. Every built-in
 * adapter defaults to true. A deployment that genuinely cannot (a standalone
 * MongoDB without a replica set is the motivating real case) sets this false
 * so Database::executeMany() rejects BEFORE the first write instead of
 * silently providing partial durability.
 */
trait SupportsAtomicBatchTrait
{
    private bool $supportsAtomicBatch = true;

    /**
     * Get the current setting, or set it.
     *
     * @param bool|null $set Null reads without changing anything.
     * @return bool The setting in force after the call.
     */
    public function supportsAtomicBatch(?bool $set = null): bool
    {
        if ($set !== null) {
            $this->supportsAtomicBatch = $set;
        }
        return $this->supportsAtomicBatch;
    }
}
