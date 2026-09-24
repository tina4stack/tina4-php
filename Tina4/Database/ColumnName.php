<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * The column-key check shared by every SQL-building write helper.
 *
 * tina4: ADR-0069 G3 - insert()/update()/delete() (and their batch forms) turn
 * the KEYS of a data or filter map into SQL identifiers. A key is accepted only
 * when it is a plain identifier - letters, digits, underscore and dollar, not
 * starting with a digit - and is still emitted exactly as before (bare). The
 * table-name argument is developer code and is not checked here.
 */
final class ColumnName
{
    /**
     * @param iterable<int|string> $keys Data or filter-map keys, as given.
     * @throws \InvalidArgumentException On the first key that is not a plain identifier.
     */
    public static function assertAll(iterable $keys): void
    {
        foreach ($keys as $key) {
            $key = (string)$key;
            // \z, not $: `$` would also accept a trailing newline.
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_$]*\z/', $key)) {
                throw new \InvalidArgumentException("Invalid column name '{$key}'");
            }
        }
    }
}
