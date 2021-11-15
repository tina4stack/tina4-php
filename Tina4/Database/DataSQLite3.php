<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
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
    public function open(): void
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
     * @return bool
     */
    public function exec()
    {
        $params = $this->parseParams(func_get_args());
        $tranId = $params["tranId"];
        $params = $params["params"];

        $sql = $params[0];

        $preparedQuery = $this->dbh->prepare($sql);

        $error = $this->error();

        if ($error->getError()["errorCode"] === 0 && !empty($preparedQuery)) {
            unset($params[0]);

            foreach ($params as $pid => $param) {
                if (is_numeric($param)) {
                    $preparedQuery->bindValue((string)($pid), $param, SQLITE3_FLOAT);
                } elseif (is_int($param)) {
                    $preparedQuery->bindValue((string)($pid), $param, SQLITE3_INTEGER);
                } elseif ($this->isBinary($param)) {
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
     * @return DataError
     */
    public function error(): DataError
    {
        return (new DataError($this->dbh->lastErrorCode(), $this->dbh->lastErrorMsg()));
    }

    /**
     * Check if table exists
     * @param string $tableName
     * @return bool
     */
    public function tableExists(string $tableName): bool
    {
        if (!empty($tableName)) {
            $exists = $this->fetch("SELECT name FROM sqlite_master WHERE type='table' AND name = '{$tableName}'");
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
     * @return DataResult
     */
    public function fetch($sql = "", int $noOfRecords = 10, int $offSet = 0, array $fieldMapping = []): DataResult
    {
        //check for one liners and reserved methods in sqlite3
        if (strpos($sql, "pragma") === false) {
            $countRecords = $this->dbh->querySingle("select count(*) as count from (" . $sql . ")");
            $sql .= " limit {$offSet},{$noOfRecords}";
        } else {
            $countRecords = 1;
        }

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

    /**
     * The default date format for this database type
     * @return string
     */
    public function getDefaultDatabaseDateFormat(): string
    {
        return "Y-m-d";
    }

    /**
     * Returns the last id after an insert to a table
     * @return string Gets the last id
     */
    public function getLastId(): string
    {
        $lastId = $this->fetch("SELECT last_insert_rowid() as last_id");
        return $lastId->records(0)[0]->lastId;
    }

    /**
     * Commit doesn't exist on SQlite3
     * @param null $transactionId
     * @return bool
     */
    public function commit($transactionId = null): bool
    {
        //No commit for sqlite
        return true;
    }

    /**
     * Rollback does not exist on SQLite3
     * @param null $transactionId
     * @return bool
     */
    public function rollback($transactionId = null): bool
    {
        //No transactions for sqlite
        return true;
    }

    /**
     * Start transaction
     * @return string
     */
    public function startTransaction(): string
    {
        //No transactions for sqlite

        return "Resource id #0";
    }

    /**
     * Auto commit on for SQlite
     * @param bool $onState
     * @return bool
     */
    public function autoCommit(bool $onState = false): void
    {
        //SQlite3 has no commits

    }

    /**
     * Determines the database layout in the form table -> columns
     * @return array
     */
    public function getDatabase(): array
    {

        $sqlTables = "select name as table_name
                      from sqlite_master
                     where type='table'
                  order by name";
        $tables = $this->fetch($sqlTables, 1000, 0)->asObject();
        $database = [];
        foreach ($tables as $id => $record) {
            $sqlInfo = "pragma table_info($record->tableName)";
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

    /**
     * SQlite3 does not have a port
     * @return int
     */
    public function getDefaultDatabasePort(): ?int
    {
        return null;
    }

    /**
     * @param string $fieldName
     * @param int $fieldIndex
     * @return string
     */
    public function getQueryParam(string $fieldName, int $fieldIndex): string
    {
        return ":{$fieldIndex}";
    }

    /**
     * Is it a No SQL database?
     * @return bool
     */
    public function isNoSQL(): bool
    {
        return false;
    }
}