<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * Database adapter interface — contract for all database drivers.
 *
 * Method names use PHP camelCase convention but map 1:1 to Python's snake_case:
 *   PHP: tableExists()   → Python: table_exists()
 *   PHP: fetchOne()      → Python: fetch_one()
 *   PHP: getColumns()    → Python: get_columns()
 *   PHP: startTransaction() → Python: start_transaction()
 *   PHP: lastInsertId()  → Python: last_insert_id()
 *   PHP: getTables()     → Python: get_tables()
 */
interface DatabaseAdapter
{
    /**
     * Open the database connection.
     */
    public function open(): void;

    /**
     * Close the database connection.
     */
    public function close(): void;

    /**
     * Execute a query with parameter binding and return results.
     *
     * @param string $sql SQL query
     * @param array<mixed> $params Bound parameters
     * @return array<int, array<string, mixed>> Array of associative arrays
     */
    public function query(string $sql, array $params = []): array;

    /**
     * Fetch results with pagination.
     * Maps to Python: fetch(sql, params, limit, skip)
     *
     * @param string $sql SQL query
     * @param int $limit Max rows to return
     * @param int $offset Starting offset (Python: skip)
     * @param array<mixed> $params Bound parameters
     * @return array{data: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function fetch(string $sql, int $limit = 10, int $offset = 0, array $params = []): array;

    /**
     * Run a query and return the first row or null.
     * Maps to Python: fetch_one(sql, params)
     *
     * @param string $sql SQL query
     * @param array<mixed> $params Bound parameters
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array;

    /**
     * Execute a DDL or data manipulation statement (no result set).
     * Maps to Python: execute(sql, params)
     *
     * @param string $sql SQL statement
     * @param array<mixed> $params Bound parameters
     * @return bool True on success
     */
    public function execute(string $sql, array $params = []): bool;

    /**
     * Build and execute an INSERT statement.
     * Maps to Python: insert(table, data)
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs
     * @return bool True on success
     */
    public function insert(string $table, array $data): bool;

    /**
     * Build and execute an UPDATE statement.
     * Maps to Python: update(table, data)
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Column => value pairs
     * @param string $where WHERE clause (without "WHERE")
     * @param array<mixed> $whereParams Bound parameters for WHERE clause
     * @return bool True on success
     */
    public function update(string $table, array $data, string $where = '', array $whereParams = []): bool;

    /**
     * Build and execute a DELETE statement.
     * Maps to Python: delete(table, where)
     *
     * @param string $table Table name
     * @param string $where WHERE clause (without "WHERE")
     * @param array<mixed> $whereParams Bound parameters for WHERE clause
     * @return bool True on success
     */
    public function delete(string $table, string $where = '', array $whereParams = []): bool;

    /**
     * Check if a table exists.
     * Maps to Python: table_exists(name)
     */
    public function tableExists(string $table): bool;

    /**
     * Get column information for a table.
     * Maps to Python: get_columns(table) / get_table_info(table)
     *
     * @return array<int, array{name: string, type: string, nullable: bool, default: mixed, primary: bool}>
     */
    public function getColumns(string $table): array;

    /**
     * Return a list of table names in the database.
     * Maps to Python: get_database_tables()
     *
     * @return array<int, string>
     */
    public function getTables(): array;

    /**
     * Get the last inserted auto-increment ID.
     * Maps to Python: last_insert_id() (via driver)
     */
    public function lastInsertId(): int|string;

    /**
     * Begin a transaction.
     * Maps to Python: start_transaction()
     */
    public function startTransaction(): void;

    /**
     * Commit the current transaction.
     */
    public function commit(): void;

    /**
     * Rollback the current transaction.
     */
    public function rollback(): void;

    /**
     * Get the last error message.
     */
    public function error(): ?string;
}
