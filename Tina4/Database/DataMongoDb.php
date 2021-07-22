<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * The Mongodb database implementation
 * @package Tina4
 */
class DataMongoDb implements Database
{

    public function __construct($database, $username = "", $password = "", $dateFormat = "Y-m-d")
    {
    }

    public function close()
    {
        // TODO: Implement close() method.
    }

    public function exec()
    {
        // TODO: Implement exec() method.
    }

    public function getLastId(): string
    {
        // TODO: Implement getLastId() method.
    }

    public function tableExists($tableName): bool
    {
        // TODO: Implement tableExists() method.
    }

    public function fetch(string $sql = "", int $noOfRecords = 10, int $offSet = 0, array $fieldMapping = []): DataResult
    {
        // TODO: Implement fetch() method.
    }

    public function rollback(int $transactionId = null)
    {
        // TODO: Implement rollback() method.
    }

    public function autoCommit(bool $onState = true):void
    {
        // TODO: Implement autoCommit() method.
    }

    public function startTransaction()
    {
        // TODO: Implement startTransaction() method.
    }

    public function error()
    {
        // TODO: Implement error() method.
    }

    public function getDatabase(): array
    {
        // TODO: Implement getDatabase() method.
    }

    public function getDefaultDatabaseDateFormat(): string
    {
        // TODO: Implement getDefaultDatabaseDateFormat() method.
    }

    public function getDefaultDatabasePort(): ?int
    {
        // TODO: Implement getDefaultDatabasePort() method.
    }

    public function getQueryParam($fieldName, $fieldIndex): string
    {
        // TODO: Implement getQueryParam() method.
    }

    public function commit($transactionId = null)
    {
        // TODO: Implement commit() method.
    }
}
