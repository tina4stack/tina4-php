<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * One CRUD SQL builder for every adapter, instead of one per adapter.
 *
 * Feature 3's last open item, the 4.3x LOC finding. Building
 * `INSERT INTO x (a, b) VALUES (?, ?)` is not engine-specific work - Ruby has
 * always built it once - yet insert/update/delete were written out in every
 * adapter here.
 *
 * PHP turned out to be the cleanest of the four to consolidate. Hashing the
 * method bodies showed MySQL, MSSQL and Firebird byte-identical to each other,
 * SQLite3 and ODBC byte-identical to each other, and PostgreSQL differing only
 * by its RETURNING clause. There is no placeholder variance (every adapter uses
 * `?`) and no identifier quoting to reconcile.
 *
 * So the only seam is {@see insertReturningClause()}, which PostgreSQL and the
 * PDO-Firebird adapter already declared. Everything else is shared verbatim.
 *
 * Execution stays with the adapter: every method here ends at `$this->execute()`
 * or `$this->executeMany()`, which each adapter implements for its own driver.
 * MongoDB does not use this trait - it does not build SQL at all.
 */
trait CrudSqlTrait
{
    /**
     * Engine-specific suffix for a single-row INSERT.
     *
     * PostgreSQL returns the inserted row with " RETURNING *"; the PDO Firebird
     * adapter returns its generated key. Every other engine appends nothing.
     * An adapter overrides this rather than reimplementing the whole INSERT.
     *
     * @return string Appended verbatim to the INSERT statement
     */
    public function insertReturningClause(): string
    {
        return '';
    }

    /**
     * Insert one row, or a list of rows as a batch.
     *
     * @param string $table Table name
     * @param array  $data  One assoc array, or an indexed list of assoc arrays
     * @return bool|DatabaseResult True/result on success
     */
    public function insert(string $table, array $data): bool|DatabaseResult
    {
        // An indexed array of assoc arrays is a batch: ONE parameterised
        // statement run per row through executeMany, not N round trips.
        if (isset($data[0]) && is_array($data[0])) {
            $keys = array_keys($data[0]);
            $cols = implode(', ', $keys);
            $placeholders = implode(', ', array_fill(0, count($keys), '?'));
            $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";
            $paramsList = array_map(static fn($row) => array_values($row), $data);
            return $this->executeMany($sql, $paramsList) > 0;
        }

        $cols = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})"
            . $this->insertReturningClause();

        return $this->execute($sql, array_values($data));
    }

    /**
     * Update rows matching the WHERE fragment.
     *
     * @param string $table       Table name
     * @param array  $data        Column => value pairs to SET
     * @param string $where       WHERE fragment without the keyword, or '' for none
     * @param array  $whereParams Parameters bound to $where
     * @return bool|DatabaseResult True/result on success
     */
    public function update(string $table, array $data, string $where = '', array $whereParams = []): bool|DatabaseResult
    {
        $setParts = [];
        $params = [];
        foreach ($data as $col => $val) {
            $setParts[] = "{$col} = ?";
            $params[] = $val;
        }

        $sql = "UPDATE {$table} SET " . implode(', ', $setParts);
        if ($where !== '') {
            $sql .= " WHERE {$where}";
            $params = array_merge($params, $whereParams);
        }

        return $this->execute($sql, $params);
    }

    /**
     * Delete rows matching the filter.
     *
     * @param string       $table       Table name
     * @param string|array $filter      A WHERE fragment, an assoc array of
     *                                  column => value, or a list of such arrays
     * @param array        $whereParams Parameters bound to a string $filter
     * @return bool|DatabaseResult True/result on success
     */
    public function delete(string $table, string|array $filter = '', array $whereParams = []): bool|DatabaseResult
    {
        // A list of assoc arrays deletes each row in turn, stopping on the
        // first failure so a partial delete is never reported as success.
        if (is_array($filter) && isset($filter[0]) && is_array($filter[0])) {
            foreach ($filter as $row) {
                if (!$this->delete($table, $row)) {
                    return false;
                }
            }
            return true;
        }

        // An assoc array builds the WHERE from its keys, then falls through to
        // the string form below - one code path builds the statement.
        if (is_array($filter)) {
            $parts = [];
            $params = [];
            foreach ($filter as $col => $val) {
                $parts[] = "{$col} = ?";
                $params[] = $val;
            }
            return $this->delete($table, implode(' AND ', $parts), $params);
        }

        $sql = "DELETE FROM {$table}";
        if ($filter !== '') {
            $sql .= " WHERE {$filter}";
        }

        return $this->execute($sql, $whereParams);
    }
}
