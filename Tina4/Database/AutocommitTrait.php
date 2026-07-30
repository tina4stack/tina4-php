<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * The adapter contract's `autocommit` accessor (feature 3 of the feature audit).
 *
 * The autocommit contract - ON for a standalone write, suppressed inside an
 * explicit transaction - is already agreed behaviour across all four frameworks,
 * and until now it was ENFORCED in one. Three of the four had no way for a
 * caller to ask an adapter what its setting was, or to change it after
 * construction.
 *
 * Every PHP adapter already resolves `$autoCommit` identically in its
 * constructor (from the argument, else TINA4_AUTOCOMMIT, else true), so this is
 * one accessor rather than ten. The three PDO adapters take the property from
 * PdoAdapterTrait; traits flatten into the using class, so it is reachable here.
 *
 * This is deliberately an ACCESSOR and not a driver call. Changing the flag
 * governs whether Tina4 issues its own commit after a standalone write, which is
 * framework behaviour; an adapter that must also tell its driver (MySQLAdapter
 * calls `$this->db->autocommit()`) overrides this and does both.
 */
trait AutocommitTrait
{
    /**
     * Get the current autocommit setting, or set it.
     *
     * @param bool|null $on Null reads without changing anything.
     * @return bool The setting in force after the call.
     */
    public function autocommit(?bool $on = null): bool
    {
        if ($on !== null) {
            $this->autoCommit = $on;
        }

        return $this->autoCommit;
    }
}
