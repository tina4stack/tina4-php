<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * Database adapter interface — contract for all database drivers.
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
     *
     * @param string $sql SQL query
     * @param int $limit Max rows to return
     * @param int $offset Starting offset
     * @param array<mixed> $params Bound parameters
     * @return array{data: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function fetch(string $sql, int $limit = 10, int $offset = 0, array $params = []): array;

    /**
     * Execute a DDL or data manipulation statement (no result set).
     *
     * @param string $sql SQL statement
     * @param array<mixed> $params Bound parameters
     * @return bool True on success
     */
    public function exec(string $sql, array $params = []): bool;

    /**
     * Check if a table exists.
     */
    public function tableExists(string $table): bool;

    /**
     * Get column information for a table.
     *
     * @return array<int, array{name: string, type: string, nullable: bool, default: mixed, primary: bool}>
     */
    public function getColumns(string $table): array;

    /**
     * Get the last inserted auto-increment ID.
     */
    public function lastInsertId(): int|string;

    /**
     * Begin a transaction.
     */
    public function begin(): void;

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
