<?php

namespace Tina4;
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Class DataSQLite3
 * The SQLite3 functionality
 * @package Tina4
 */
class DataSQLite3 implements DataBase
{
    use DataBaseCore;
    use Utility;

    /**
     * Open an SQLite3 connection
     */
    public function open()
    {
        $this->dbh = (new \SQLite3($this->databaseName)); //create the new database or open existing one
        $this->dbh->busyTimeout(5000); //prevent database locks
        $this->dbh->exec('PRAGMA journal_mode = wal;'); //help with concurrency
    }

    /**
     * Closes the SQLite3 connection
     */
    public function close()
    {
        $this->dbh->close();
    }

    /**
     * Executes a query
     * @return array|bool|mixed
     */
    public function exec()
    {
        $params = $this->parseParams(func_get_args());
        $tranId = $params["tranId"];
        $params = $params["params"];

        $sql = $params[0];


        $preparedQuery = $this->dbh->prepare($sql);

        $error = $this->error();

        if ($error->getError()["errorCode"] == 0 && !empty($preparedQuery)) {
            unset($params[0]);

            foreach ($params as $pid => $param) {
                if (is_numeric($param)) {
                    $preparedQuery->bindValue((string)($pid), $param, SQLITE3_FLOAT);
                } else
                    if (is_int($param)) {
                        $preparedQuery->bindValue((string)($pid), $param, SQLITE3_INTEGER);
                    } else
                        if ($this->isBinary($param)) {
                            $preparedQuery->bindValue((string)($pid), $param, SQLITE3_BLOB);
                        } else {
                            $preparedQuery->bindValue((string)($pid), $param, SQLITE3_TEXT);
                        }
            }


            $preparedQuery->execute();
            $preparedQuery->close();

        }

        return $this->error();
    }

    /**
     * Error from the database
     * @return bool|DataError
     */
    public function error()
    {
        return (new DataError($this->dbh->lastErrorCode(), $this->dbh->lastErrorMsg()));
    }

    /**
     * Check if table exists
     * @param $tableName
     * @return bool|mixed
     */
    public function tableExists($tableName)
    {
        if (!empty($tableName)) {
            $exists = $this->fetch("SELECT name FROM sqlite_master WHERE type='table' AND name='{$tableName}'");
            return !empty($exists->records);
        } else {
            return false;
        }
    }

    /**
     * Native fetch for SQLite
     * @param string $sql
     * @param int $noOfRecords
     * @param int $offSet
     * @param array $fieldMapping
     * @return bool|DataResult
     */
    public function fetch($sql = "", $noOfRecords = 10, $offSet = 0, $fieldMapping = [])
    {

        $countRecords = $this->dbh->querySingle("select count(*) as count from (" . $sql . ")");

        $sql = $sql . " limit {$offSet},{$noOfRecords}";

        $recordCursor = $this->dbh->query($sql);
        $records = [];
        if (!empty($recordCursor)) {
            while ($recordArray = $recordCursor->fetchArray(SQLITE3_ASSOC)) {
                if (!empty($recordArray)) {
                    $records[] = (new DataRecord($recordArray, $fieldMapping, $this->getDefaultDatabaseDateFormat(), $this->dateFormat));
                }
            }
        }

        if (!empty($records)) {
            //populate the fields
            $fid = 0;
            $fields = [];
            foreach ($records[0] as $field => $value) {
                $fields[] = (new DataField($fid, $recordCursor->columnName($fid), $recordCursor->columnName($fid), $recordCursor->columnType($fid)));
                $fid++;
            }
        } else {
            $records = null;
            $fields = null;
        }

        $error = $this->error();

        return (new DataResult($records, $fields, $countRecords, $offSet, $error));
    }

    public function getLastId()
    {
        $lastId = $this->fetch("SELECT last_insert_rowid() as last_id");
        return $lastId->records(0)[0]->lastId;
    }

    /**
     * Commit
     * @param null $transactionId
     * @return bool
     */
    public function commit($transactionId = null)
    {
        //No commit for sqlite
        return true;
    }

    /**
     * Rollback
     * @param null $transactionId
     * @return bool
     */
    public function rollback($transactionId = null)
    {
        //No transactions for sqlite
        return true;
    }

    /**
     * Start transaction
     * @return bool
     */
    public function startTransaction()
    {
        //No transactions for sqlite
        return "Resource id #0";
    }

    /**
     * Auto commit on for SQlite
     * @param bool $onState
     * @return bool|void
     */
    public function autoCommit($onState = false)
    {
        //SQlite has no commits
        return true;
    }

    function getDatabase()
    {
        /** @noinspection SqlResolve */
        $sqlTables = "select name as table_name
                      from sqlite_master
                     where type='table'
                  order by name";
        $tables = $this->fetch($sqlTables, 1000, 0)->asObject();
        $database = [];
        foreach ($tables as $id => $record) {
            $sqlInfo = "pragma table_info($record->tableName);";
            $tableInfo = $this->fetch($sqlInfo, 1000, 0)->AsObject();

            //Go through the tables and extract their column information
            foreach ($tableInfo as $tid => $tRecord) {
                $database[trim($record->tableName)][$tid]["column"] = $tRecord->cid;
                $database[trim($record->tableName)][$tid]["field"] = trim($tRecord->name);
                $database[trim($record->tableName)][$tid]["description"] = "";
                $database[trim($record->tableName)][$tid]["type"] = trim($tRecord->type);
                $database[trim($record->tableName)][$tid]["length"] = "";
                $database[trim($record->tableName)][$tid]["precision"] = "";
                $database[trim($record->tableName)][$tid]["default"] = trim($tRecord->dfltValue);
                $database[trim($record->tableName)][$tid]["notnull"] = trim($tRecord->notnull);
                $database[trim($record->tableName)][$tid]["pk"] = trim($tRecord->pk);
            }
        }
        return $database;
    }

    public function getDefaultDatabaseDateFormat()
    {
        return "Y-m-d";
    }

    public function getDefaultDatabasePort()
    {
        return null;
    }

    /**
     * @param $fieldName
     * @param $fieldIndex
     * @return string
     */
    public function getQueryParam($fieldName,$fieldIndex): string
    {
        return ":$fieldIndex";
    }
}