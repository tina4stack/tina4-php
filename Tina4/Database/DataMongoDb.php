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
    use DataBaseCore;
    use Utility;

    private $manager;

    public function open()
    {
        if (!class_exists("MongoDB\Client")) {
            throw new \Exception("MongoDb extension for PHP needs to be installed");
        }

        if (empty($this->hostName))
        {
            $this->hostName = "localhost";
        }

        if (empty($this->port))
        {
            $this->port = $this->getDefaultDatabasePort();
        }

        if (empty($this->databaseName))
        {
            $this->databaseName = "testing";
        }

        $connectionString = "mongodb://" . $this->hostName . ":" . $this->port;
        if (!empty($this->username)) {
            $connectionString = "mongodb://{$this->username}:{$this->password}@" . $this->hostName . ":" . $this->port;
        }

        $this->dbh = (new \MongoDB\Client($connectionString))->{$this->databaseName};

        $this->manager = new \MongoDB\Driver\Manager($connectionString);
    }

    public function close()
    {
        unset($this->dbh);
    }

    public function exec()
    {
        $params = $this->parseParams(func_get_args());

        $tranId = $params["tranId"];

        $params = $params["params"];

        $sql = $params[0];

        $statement = $this->parseSQLToNoSQL($sql);

        $data = [];

        foreach ($statement["columns"] as $id => $column) {
            $data[$this->camelCase($column)] = $params[$id+1];
        }

        if (strpos($sql, "update") !== false) {
            foreach ($this->dbh->{$statement["collectionName"]}->find($statement["filter"]) as $document) {
                $this->dbh->{$statement["collectionName"]}->findOneAndUpdate($statement["filter"], ['$set' => $data]);
            }
        } else {
            $this->dbh->{$statement["collectionName"]}->insertOne($data);
        }

        return $this->error();
    }

    public function getLastId(): string
    {
       return 0;
    }

    public function tableExists($tableName): bool
    {
        $collections = new \MongoDB\Command\ListCollections($this->databaseName);

        $collectionNames = [];

        foreach ($collections as $collection) {
            $collectionNames[] = $collection->getName();
        }

        return in_array($tableName, $collectionNames);
    }

    public function fetch($sql = "", int $noOfRecords = 10, int $offSet = 0, array $fieldMapping = []): DataResult
    {
        $statement = $this->parseSQLToNoSQL($sql);

        $collection = $this->dbh->{$statement["collectionName"]}->find($statement["filter"]);

        $countRecords = 0;

        $records = [];

        if (!empty($collection)) {
            foreach ($collection as  $document) {
                if (!empty($document)) {

                    $records[] = (new DataRecord((array)$document, $fieldMapping, $this->getDefaultDatabaseDateFormat(), $this->dateFormat));
                    $countRecords++;
                }
            }
        }

        $error = $this->error();

        return (new DataResult($records, $fields=[], $countRecords, $offSet, $error));
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
        return (new DataError("", ""));
    }

    public function getDatabase(): array
    {
        // TODO: Implement getDatabase() method.
    }

    public function getDefaultDatabaseDateFormat(): string
    {
        return "Y-m-d";
    }

    public function getDefaultDatabasePort(): ?int
    {
        return 27017;
    }

    public function getQueryParam($fieldName, $fieldIndex): string
    {
        return ":{$fieldName}";
    }

    public function commit($transactionId = null)
    {
        // TODO: Implement commit() method.
    }


    public function rollback($transactionId = null)
    {
        // TODO: Implement rollback() method.
    }

    /**
     * Is it a No SQL database?
     * @return bool
     */
    public function isNoSQL(): bool
    {
        return true;
    }
}
