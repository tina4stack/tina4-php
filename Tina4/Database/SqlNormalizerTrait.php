<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * v3.13.12 — shared SQL-normalisation helpers for adapter classes.
 *
 * The framework wraps user-supplied SQL in fetch() — a COUNT(*)
 * subquery for the pagination probe and a LIMIT/OFFSET (or engine-
 * specific equivalent) appended to the real query. A trailing
 * semicolon in the user's input breaks both: the wrapped form
 * becomes either invalid SQL or two statements where the second
 * is broken.
 *
 * This trait gives every adapter a single normalisation entry point
 * — called at the top of fetch() and fetchOne() — that strips
 * trailing semicolons and whitespace before any wrapping happens.
 * Internal semicolons (between meaningful statements, or inside
 * string literals) are left alone; the driver will reject those
 * if the engine doesn't support multi-statement.
 */
trait SqlNormalizerTrait
{
    /**
     * Strip trailing semicolons and whitespace from user SQL.
     *
     * @param string $sql User-supplied SQL.
     * @return string Normalised SQL safe to wrap with COUNT/LIMIT.
     */
    protected static function stripTrailingSemicolons(string $sql): string
    {
        if ($sql === '') {
            return $sql;
        }
        $stripped = rtrim($sql);
        while ($stripped !== '' && substr($stripped, -1) === ';') {
            $stripped = rtrim(substr($stripped, 0, -1));
        }
        return $stripped;
    }
}
