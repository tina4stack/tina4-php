<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * The MySQL database database implementation
 * @package Tina4
 */
class DataMySQL implements DataBase
{
    use DataBaseCore;
    use Utility;

    /**
     * Opens database connection
     * @return bool|void
     * @throws \Exception
     */
    public function open()
    {
        if (!function_exists("mysqli_connect")) {
            throw new \Exception("Mysql extension for PHP needs to be installed");
        }

        $this->dbh = mysqli_connect($this->hostName, $this->username, $this->password, $this->databaseName, $this->port);
        $this->dbh->set_charset("utf8mb4");
    }

    /**
     * Closes database connection
     * @return bool|void
     */
    public function close()
    {
        mysqli_close($this->dbh);
    }

    /**
     * Executes
     * @return array|bool
     */
    public function exec()
    {

        $params = $this->parseParams(func_get_args());
        $tranId = $params["tranId"];
        $params = $params["params"];


        if (stripos($params[0], "call") !== false) {
            return $this->fetch($params[0]);
        } else {
            $preparedQuery = $this->dbh->prepare($params[0]);
            $executeError = $this->error();
            if (!empty($preparedQuery)) {
                unset($params[0]);
                if (!empty($params)) {
                    $paramTypes = "";
                    foreach ($params as $pid => $param) {
                        if ($this->isBinary($param)) {
                            $paramTypes .= "s"; //Should be b but does not work as expected
                        } elseif (is_int($param)) {
                            $paramTypes .= "i";
                        } elseif (is_array($param)) {
                            if (array_key_exists(0, $param)) {
                                $paramTypes .= (($param[0] !== "0") ? "d" : "s");
                            } else {
                                $paramTypes .= "s";
                            }
                            if ($param[0] !== '+' && $param[0] !== '-') $paramTypes .= "d";
                        }
                         elseif ($param !== '' && is_numeric($param) && !empty($param)){
                            $paramTypes .= "d";
                        } else {
                            $paramTypes .= "s";
                        }
                    }

                    //Fix for reference values https://stackoverflow.com/questions/16120822/mysqli-bind-param-expected-to-be-a-reference-value-given

                    \mysqli_stmt_bind_param($preparedQuery, $paramTypes, ...$params);
                    \mysqli_stmt_execute($preparedQuery);
                    $executeError = $this->error(); //We need the error here!
                    \mysqli_stmt_affected_rows($preparedQuery);
                    \mysqli_stmt_close($preparedQuery);
                } else { //Execute a statement without params
                    $params[0] = $preparedQuery;
                    call_user_func_array("mysqli_execute", $params);
                    $executeError = $this->error(); //We need the error here!
                }
            }
            return $executeError;
        }
    }

    /**
     * Fetches records from database
     * @param string $sql SQL Query
     * @param integer $noOfRecords Number of records requested
     * @param integer $offSet Record offset
     * @param array $fieldMapping Mapped Fields
     * @return null|DataResult
     */
    public function fetch($sql = "", int $noOfRecords = 10, int $offSet = 0, array $fieldMapping = []): ?DataResult
    {
        $initialSQL = $sql;

        //Don't add a limit if there is a limit already or if there is a stored procedure call
        if (stripos($sql, "limit") === false && stripos($sql, "call") === false) {
            $sql .= " limit {$offSet},{$noOfRecords}";
        }

        $recordCursor = mysqli_query($this->dbh, $sql);
        $error = $this->error();

        $records = null;
        $record = null;
        $fields = null;
        $resultCount = [];
        $resultCount["COUNT_RECORDS"] = 1;

        if ($error->getError()["errorCode"] == 0) {
            if (isset($recordCursor, $recordCursor->num_rows) && !empty($recordCursor) && $recordCursor->num_rows > 0) {
                while ($record = mysqli_fetch_assoc($recordCursor)) {
                    if (is_array($record)) {
                        $records[] = (new DataRecord($record, $fieldMapping, $this->getDefaultDatabaseDateFormat(), $this->dateFormat));
                    }
                }

                if (is_array($records) && count($records) > 0) {
                    if (stripos($sql, "returning") === false) {
                        //Check to prevent second call of procedure
                        if (stripos($sql, "call") !== false) {
                            $resultCount["COUNT_RECORDS"] = count($records);
                        } else {
                            $sqlCount = "select count(*) as COUNT_RECORDS from ($initialSQL) tcount";

                            $recordCount = mysqli_query($this->dbh, $sqlCount);

                            $resultCount = mysqli_fetch_assoc($recordCount);

                            if (empty($resultCount)) {
                                $resultCount["COUNT_RECORDS"] = 0;
                            }
                        }
                    } else {
                        $resultCount["COUNT_RECORDS"] = 0;
                    }
                } else {
                    $resultCount["COUNT_RECORDS"] = 0;
                }
            } else {
                $resultCount["COUNT_RECORDS"] = 0;
            }

            //populate the fields
            $fid = 0;
            $fields = [];
            if (!empty($records)) {
                //$record = $records[0];
                $fields = mysqli_fetch_fields($recordCursor);

                foreach ($fields as $fieldId => $fieldInfo) {
                    $fieldInfo = (array)json_decode(json_encode($fieldInfo));

                    $fields[] = (new DataField($fid, $fieldInfo["name"], $fieldInfo["orgname"], $fieldInfo["type"], $fieldInfo["length"]));
                    $fid++;
                }
            }
        } else {
            $resultCount["COUNT_RECORDS"] = 0;
        }

        //Ensures the pointer is at the end in order to close the connection - Might be a buggy fix
        if (stripos($sql, "call") !== false) {
            while (mysqli_next_result($this->dbh)) {
            }
        }

        return (new DataResult($records, $fields, $resultCount["COUNT_RECORDS"], $offSet, $error));
    }

    /**
     * Gets MySQL errors
     * @return bool|DataError
     */
    public function error()
    {
        $errorNo = mysqli_errno($this->dbh);
        $errorMessage = mysqli_error($this->dbh);

        return (new DataError($errorNo, $errorMessage));
    }

    public function getDefaultDatabaseDateFormat(): string
    {
        return "Y-m-d";
    }

    /**
     * Gets the last inserted row's ID from database
     * @return string
     */
    public function getLastId(): string
    {
        $lastId = $this->fetch("SELECT LAST_INSERT_ID() as last_id");
        return $lastId->records(0)[0]->lastId;
    }

    /**
     * Check if the table exists
     * @param string $tableName
     * @return bool
     */
    public function tableExists(string $tableName): bool
    {
        if (!empty($tableName)) {
            $exists = $this->fetch("SELECT * 
                                    FROM information_schema.tables
                                    WHERE table_schema = '{$this->databaseName}' 
                                        AND table_name = '{$tableName}'", 1);
            return !empty($exists->records());
        } else {
            return false;
        }
    }

    /**
     * Commits
     * @param null $transactionId
     * @return bool
     */
    public function commit($transactionId = null)
    {
        return mysqli_commit($this->dbh);
    }

    /**
     * Rollback the transaction
     * @param null $transactionId
     * @return bool|mixed
     */
    public function rollback($transactionId = null)
    {
        return mysqli_rollback($this->dbh);
    }

    /**
     * Start the transaction
     * @return string
     */
    public function startTransaction(): string
    {
        $this->dbh->autocommit(false);
        mysqli_begin_transaction($this->dbh);
        return "Resource id #0";
    }

    /**
     * Auto commit on for mysql
     * @param bool $onState
     * @return void
     */
    public function autoCommit(bool $onState = true): void
    {
        $this->dbh->autocommit($onState);
    }

    /**
     * Gets the database metadata
     * @return array|mixed
     */
    public function getDatabase(): array
    {
        $sqlTables = "SELECT table_name, table_type, engine
                      FROM INFORMATION_SCHEMA.tables
                     WHERE upper(table_schema) = upper('{$this->databaseName}')
                     ORDER BY table_type ASC, table_name DESC";
        $tables = $this->fetch($sqlTables, 10000, 0)->asObject();
        $database = [];
        foreach ($tables as $id => $record) {
            $sqlInfo = "SELECT *
                        FROM information_schema.COLUMNS   
                        WHERE upper(table_schema) = upper('{$this->databaseName}')
                                    AND TABLE_NAME = '{$record->tableName}'
                         ORDER BY ORDINAL_POSITION";

            $tableInfo = $this->fetch($sqlInfo, 10000)->asObject();


            //Go through the tables and extract their column information
            foreach ($tableInfo as $tid => $tRecord) {
                $database[trim($record->tableName)][$tid]["column"] = $tRecord->ordinalPosition;
                $database[trim($record->tableName)][$tid]["field"] = trim($tRecord->columnName);
                $database[trim($record->tableName)][$tid]["description"] = trim($tRecord->extra);
                $database[trim($record->tableName)][$tid]["type"] = trim($tRecord->dataType);
                $database[trim($record->tableName)][$tid]["length"] = trim($tRecord->characterMaximumLength);
                $database[trim($record->tableName)][$tid]["precision"] = $tRecord->numericPrecision;
                $database[trim($record->tableName)][$tid]["default"] = trim($tRecord->columnDefault);
                $database[trim($record->tableName)][$tid]["notnull"] = trim($tRecord->isNullable);
                $database[trim($record->tableName)][$tid]["pk"] = trim($tRecord->columnKey);
            }
        }
        return $database;
    }

    /**
     * The default MySQL port
     * @return int
     */
    public function getDefaultDatabasePort(): int
    {
        return 3306;
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
