<?php


namespace Tina4;


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

    public function getLastId()
    {
        // TODO: Implement getLastId() method.
    }

    public function tableExists($tableName)
    {
        // TODO: Implement tableExists() method.
    }

    public function fetch($sql = "", $noOfRecords = 10, $offSet = 0, $fieldMapping = [])
    {
        // TODO: Implement fetch() method.
    }

    public function rollback($transactionId = null)
    {
        // TODO: Implement rollback() method.
    }

    public function autoCommit($onState = true)
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

    public function getDatabase()
    {
        // TODO: Implement getDatabase() method.
    }

    public function getDefaultDatabaseDateFormat()
    {
        // TODO: Implement getDefaultDatabaseDateFormat() method.
    }

    public function getDefaultDatabasePort()
    {
        // TODO: Implement getDefaultDatabasePort() method.
    }

    public function getQueryParam($fieldName, $fieldIndex): string
    {
        // TODO: Implement getQueryParam() method.
    }
}