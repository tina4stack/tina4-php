<?php

namespace Tina4;
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Interface DataBase and future database abstractions
 * @package Tina4
 */
interface DataBase
{

    /**
     * DataBase constructor.
     * @param $database
     * @param string $username
     * @param string $password
     * @param string $dateFormat
     */
    public function __construct($database, $username = "", $password = "", $dateFormat = "Y-m-d");

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
     * @return bool
     */
    public function getLastId();

    /**
     * Checks to see if a table exists
     * @param $tableName
     * @return mixed
     */
    public function tableExists($tableName);

    /**
     * Fetch a result set of DataResult
     * @param string $sql SQL Query to fetch wanted data
     * @param int $noOfRecords Number of records wanted to return
     * @param int $offSet Row offset for fetched data
     * @param array $fieldMapping Array of mapped fields for mapping to different results
     * @return array DataResult Array of query result data
     * @example examples\exampleDataBaseFetch.php
     */
    public function fetch($sql = "", $noOfRecords = 10, $offSet = 0, $fieldMapping = []);

    /**
     * Sets database commit from currently used database
     * @param $transactionId integer Id of the transaction
     * @return mixed
     */
    public function rollback($transactionId = null);

    /**
     * Set autocommit on or off
     * @param bool $onState
     * @return bool
     */
    public function autoCommit($onState = true);

    /**
     * Starts a transaction
     * @return integer
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
    public function getDatabase();

    /**
     * Returns default date format
     * @return mixed
     */
    public function getDefaultDatabaseDateFormat();

    /**
     * Returns the default database port
     * @return mixed
     */
    public function getDefaultDatabasePort();

    /**
     * Returns back the correct param type convention for parameterised queries
     * Default is normally ?
     * @param $fieldName
     * @param $fieldIndex
     * @return mixed
     */
    public function getQueryParam($fieldName,$fieldIndex): string;
}