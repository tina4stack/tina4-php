<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Interface DataBase and future database abstractions
 * @package Tina4
 */
interface DataBase
{

    /**
     * DataBase constructor.
     * @param string $database - In the form [host/port:database]
     * @param string $username Database user username
     * @param string $password Database user password
     * @param string $dateFormat Format of date
     */
    public function __construct(string $database, string $username = "", string $password = "", string $dateFormat = "Y-m-d");


    /**
     * Open the database connection
     * @return mixed
     */
    public function open();

    /**
     * Close the database connection
     * @return mixed
     */
    public function close();

    /**
     * Sets database execute from currently used database
     * @return array|mixed
     */
    public function exec();

    /**
     * Sets database get last inserted row's id from currently used database
     * @return string
     */
    public function getLastId(): string;

    /**
     * Checks to see if a table exists
     * @param string $tableName
     * @return bool
     */
    public function tableExists(string $tableName): bool;

    /**
     * Fetch a result set of DataResult
     * @param string|array $sql SQL Query to fetch wanted data
     * @param int $noOfRecords Number of records wanted to return
     * @param int $offSet Row offset for fetched data
     * @param array $fieldMapping Array of mapped fields for mapping to different results
     * @return DataResult Array of query result data
     * @example examples\exampleDataBaseFetch.php
     */
    public function fetch($sql, int $noOfRecords = 10, int $offSet = 0, array $fieldMapping = []): ?DataResult;

    /**
     * Commits the transaction for the current database
     * @param null $transactionId
     * @return mixed
     */
    public function commit($transactionId = null);

    /**
     * Rolls back database commit from currently used database
     * @param $transactionId null Id of the transaction
     * @return mixed
     */
    public function rollback($transactionId = null);

    /**
     * Set autocommit on or off
     * @param bool $onState
     * @return void
     */
    public function autoCommit(bool $onState = true): void;

    /**
     * Starts a transaction
     * @return string
     */
    public function startTransaction();

    /**
     * Sets database errors from currently used database
     * @return bool
     */
    public function error();

    /**
     * Returns metadata of the database
     *  $database => [tables][fields]
     * @return mixed
     */
    public function getDatabase(): array;

    /**
     * Returns default date format
     * @return string
     */
    public function getDefaultDatabaseDateFormat(): string;

    /**
     * Returns the default database port
     * @return int
     */
    public function getDefaultDatabasePort(): ?int;

    /**
     * Returns back the correct param type convention for parameterised queries
     * Default is normally ?
     * @param string $fieldName
     * @param int $fieldIndex
     * @return mixed
     */
    public function getQueryParam(string $fieldName, int $fieldIndex): string;

    /**
     * Check if the database is a no SQL database
     * @return bool
     */
    public function isNoSQL(): bool;
}
