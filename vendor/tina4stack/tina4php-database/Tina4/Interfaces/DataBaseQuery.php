<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * General pattern for a database query
 */
interface DataBaseQuery
{
    /**
     * Runs a query against the database and returns a DataResult
     * @param $sql
     * @param int $noOfRecords
     * @param int $offSet
     * @param array $fieldMapping
     * @return DataResult|null
     */
    public function query($sql, int $noOfRecords = 10, int $offSet = 0, array $fieldMapping = []): ?DataResult;
}