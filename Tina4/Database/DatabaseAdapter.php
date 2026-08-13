<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
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
     * Connect to the database (ADR-0044 canonical lifecycle name).
     *
     * PHP adapters resolve their connection target from the constructor
     * (config-injected), so this takes no arguments — unlike the connect
     * (connectionString, username, password) shape in Python/Ruby/Node.
     * `open()` is the pre-3.14 spelling; ConnectAliasTrait provides `connect`
     * as a thin forwarding alias for every built-in adapter.
     */
    public function connect(): void;

    /**
     * Open the database connection.
     *
     * @deprecated Pre-3.14 spelling of connect(). Kept as a required member
     *   for backward compatibility; a temporary alias per ADR-0044, to be
     *   removed or explicitly deprecated before the 3.14 stability boundary.
     */
    public function open(): void;

    /**
     * Close the database connection.
     */
    public function close(): void;

    /**
     * Return the canonical, credential-free engine name (e.g. "sqlite",
     * "postgres", "mysql") — what the translator and DDL builders dispatch
     * on. ADR-0044 required adapter capability.
     */
    public function getDatabaseType(): string;

    /**
     * Get the current autocommit setting, or set it. A native boolean,
     * readable AND writable. ADR-0044 required adapter capability.
     *
     * @param bool|null $on Null reads without changing anything.
     * @return bool The setting in force after the call.
     */
    public function autocommit(?bool $on = null): bool;

    /**
     * Fetch results with pagination.
     * Maps to Python: fetch(sql, params, limit, skip)
     *
     * @param string $sql SQL query
     * @param array<mixed> $params Bound parameters
     * @param int $limit Max rows to return
     * @param int $offset Starting offset (Python: skip)
     * @return array{data: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function fetch(string $sql, array $params = [], int $limit = 100, int $offset = 0): array|DatabaseResult;

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
     * @return bool|DatabaseResult True on success, DatabaseResult for RETURNING/stored proc queries
     */
    public function execute(string $sql, array $params = []): bool|DatabaseResult;

    // insert/update/delete (ADR-0044 NOT_REQUIRED_ON_ADAPTER: engine-neutral
    // composition) are deliberately absent from the DECLARED interface —
    // DBA-S03. Every built-in SQL adapter still HAS working insert/update/
    // delete methods via CrudSqlTrait, just not as part of the required
    // contract; MongoDB keeps its own (it does not build SQL at all).

    /**
     * Execute a single SQL statement with multiple parameter sets (batch) as
     * ONE aggregate result. Maps to Python: execute_many(sql, params_list)
     *
     * @param string $sql SQL statement with placeholders
     * @param array<int, array<mixed>> $paramsList List of parameter arrays
     * @return int|DatabaseResult Total affected rows (Database::executeMany
     *   normalises whichever shape an adapter returns into a DatabaseResult)
     */
    public function executeMany(string $sql, array $paramsList = []): int|DatabaseResult;

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

    // lastInsertId/error (ADR-0044 NOT_REQUIRED_ON_ADAPTER) left the required
    // interface — generated ids come from execute()/executeMany()'s own
    // DatabaseResult->lastId, and a failure throws rather than being read
    // back via a second call. Every built-in adapter still implements both as
    // extras; SQL adapters' lastInsertId() feeds Database::executeMany()'s
    // own last-id read, for example.

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
}
