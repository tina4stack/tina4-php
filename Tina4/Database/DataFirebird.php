<?php

namespace Tina4;
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Class DataFirebird
 * The implementation for the firebird database engine
 * @package Tina4
 */
class DataFirebird implements DataBase
{
    use DataBaseCore;
    use Utility;

    /**
     * Open a Firebird database connection
     * @param bool $persistent
     * @throws \Exception
     */
    public function open($persistent = true)
    {
        if (!function_exists("ibase_pconnect")) {
            throw new \Exception("Firebird extension for PHP needs to be installed");
        }

        //Set the returning format to something we can expect to transform
        ini_set("ibase.dateformat", str_replace("Y", "%Y", str_replace("d", "%d", str_replace("m", "%m", $this->getDefaultDatabaseDateFormat()))));
        ini_set("ibase.timestampformat", str_replace("Y", "%Y", str_replace("d", "%d", str_replace("m", "%m", $this->getDefaultDatabaseDateFormat()))) . " %H:%M:%S");

        if ($persistent) {
            $this->dbh = ibase_pconnect($this->hostName . "/" . $this->port . ":" . $this->databaseName, $this->username, $this->password);
        } else {
            $this->dbh = ibase_connect($this->hostName . "/" . $this->port . ":" . $this->databaseName, $this->username, $this->password);
        }
    }

    /**
     * Close a Firebird database connection
     */
    public function close()
    {
        ibase_close($this->dbh);
    }

    /**
     * Execute a firebird query, format is query followed by params or variables
     * @return array|bool
     */
    public function exec()
    {
        $params = $this->parseParams(func_get_args());
        $tranId = $params["tranId"];
        $params = $params["params"];

        if (stripos($params[0], "returning") !== false) {
            return $this->fetch($params);
        } else {
            if (!empty($tranId)) {
                $preparedQuery = ibase_prepare($this->dbh, $tranId, $params[0]);
            } else {
                $preparedQuery = ibase_prepare($this->dbh, $params[0]);
            }

            if (!empty($preparedQuery)) {
                $params[0] = $preparedQuery;
                call_user_func_array("ibase_execute", $params);
            }

            return $this->error();
        }
    }

    /**
     * Firebird implementation of fetch
     * @param string $sql
     * @param int $noOfRecords
     * @param int $offSet
     * @param $fieldMapping
     * @return bool|DataResult
     */
    public function fetch($sql = "", $noOfRecords = 10, $offSet = 0, $fieldMapping = [])
    {
        if (is_array($sql)) {
            $initialSQL = $sql[0];
            $params = array_merge([$this->dbh], $sql);
        } else {
            $initialSQL = $sql;
        }
        if (stripos($initialSQL, "returning") === false) {
            //inject in the limits for the select - in Firebird select first x skip y
            $limit = " first {$noOfRecords} skip {$offSet} ";

            $posSelect = stripos($initialSQL, "select") + strlen("select");

            $sql = substr($initialSQL, 0, $posSelect) . $limit . substr($initialSQL, $posSelect);   //select first 10 skip 10 from table
        }

        if (is_array($sql)) {
            $recordCursor = call_user_func("ibase_query",  ...$params);
        } else {
            $recordCursor = ibase_query($this->dbh, $sql);
        }

        $records = null;
        $record = null;

        while ($record = ibase_fetch_assoc($recordCursor)) {
            foreach ($record as $key => $value) {
                if (substr($value, 0, 2) === "0x") {
                    //Get the blob information
                    $blobData = ibase_blob_info($this->dbh, $value);
                    //Get a handle to the blob
                    $blobHandle = ibase_blob_open($this->dbh, $value);
                    //Get the blob contents
                    $content = ibase_blob_get($blobHandle, $blobData[0]);
                    ibase_blob_close($blobHandle);
                    $record[$key] = $content;
                }
            }
            $records[] = (new DataRecord($record, $fieldMapping, $this->getDefaultDatabaseDateFormat(), $this->dateFormat));
        }

        if (is_array($records) && count($records) > 0) {
            if (stripos($initialSQL, "returning") === false) {
                $sqlCount = "select count(*) as COUNT_RECORDS from ($initialSQL)";

                $recordCount = ibase_query($this->dbh, $sqlCount);

                $resultCount = ibase_fetch_assoc($recordCount);

            } else {
                $resultCount["COUNT_RECORDS"] = 1; //used for insert into or update
            }
        } else {
            $resultCount["COUNT_RECORDS"] = 0;
        }

        //populate the fields
        $fid = 0;
        $fields = [];
        if (!empty($records)) {
            $record = $records[0];
            foreach ($record as $field => $value) {
                $fieldInfo = ibase_field_info($recordCursor, $fid);

                $fields[] = (new DataField($fid, $fieldInfo["name"], $fieldInfo["alias"], $fieldInfo["type"], $fieldInfo["length"]));
                $fid++;
            }
        }

        $error = $this->error();
        return (new DataResult($records, $fields, $resultCount["COUNT_RECORDS"], $offSet, $error));
    }

    /**
     * Returns an error
     * @return bool|DataError
     */
    public function error()
    {
        $errorCode = ibase_errcode();
        $errorMessage = ibase_errmsg();
        return (new DataError($errorCode, $errorMessage));
    }

    /**
     * Commit
     * @param null $transactionId
     * @return bool
     */
    public function commit($transactionId = null)
    {
        if (!empty($transactionId)) {
            return ibase_commit($transactionId);
        } else {
            return ibase_commit($this->dbh);
        }
    }

    /**
     * Rollback
     * @param null $transactionId
     * @return bool
     */
    public function rollback($transactionId = null)
    {
        if (!empty($transactionId)) {
            return ibase_rollback($transactionId);
        } else {
            return ibase_rollback($this->dbh);
        }
    }

    /**
     * Auto commit on for Firebird
     * @param bool $onState
     * @return bool|void
     */
    public function autoCommit($onState = false)
    {
        //Firebird has commit off by default
        return false;
    }

    /**
     * Start Transaction
     * @return false|int|resource
     */
    public function startTransaction()
    {

        return ibase_trans(IBASE_COMMITTED + IBASE_NOWAIT, $this->dbh);
    }

    /**
     * Check if table exists
     * @param $tableName
     * @return bool
     */
    public function tableExists($tableName): bool
    {
        if (!empty($tableName)) {
            // table name must be in upper case
            $tableName = strtoupper($tableName);
            $exists = $this->fetch("SELECT 1 AS CONSTANT FROM RDB\$RELATIONS WHERE RDB\$RELATION_NAME = '{$tableName}'");

            return !empty($exists->records());
        } else {
            return false;
        }
    }

    /**
     * Get the last id
     * @return int
     */
    public function getLastId()
    {
        return null;
    }

    /**
     * Get the database metadata
     * @return array|mixed
     */
    public function getDatabase()
    {
        $sqlTables = 'select distinct rdb$relation_name as table_name
                      from rdb$relation_fields
                     where rdb$system_flag=0
                       and rdb$view_context is null';

        $tables = $this->fetch($sqlTables, 1000, 0)->AsObject();
        $database = [];
        foreach ($tables as $id => $record) {
            $sqlInfo = 'SELECT r.RDB$FIELD_NAME AS field_name,
                           r.RDB$DESCRIPTION AS field_description,
                           r.RDB$DEFAULT_VALUE AS field_default_value,
                           r.RDB$NULL_FLAG AS field_not_null_constraint,
                           f.RDB$FIELD_LENGTH AS field_length,
                           f.RDB$FIELD_PRECISION AS field_precision,
                           f.RDB$FIELD_SCALE AS field_scale,
                           CASE f.RDB$FIELD_TYPE
                              WHEN 261 THEN \'BLOB\'
                              WHEN 14 THEN \'CHAR\'
                              WHEN 40 THEN \'CSTRING\'
                              WHEN 11 THEN \'D_FLOAT\'
                              WHEN 27 THEN \'DOUBLE\'
                              WHEN 10 THEN \'FLOAT\'
                              WHEN 16 THEN \'INT64\'
                              WHEN 8 THEN \'INTEGER\'
                              WHEN 9 THEN \'QUAD\'
                              WHEN 7 THEN \'SMALLINT\'
                              WHEN 12 THEN \'DATE\'
                              WHEN 13 THEN \'TIME\'
                              WHEN 35 THEN \'TIMESTAMP\'
                              WHEN 37 THEN \'VARCHAR\'
                              ELSE \'UNKNOWN\'
                            END AS field_type,
                            f.RDB$FIELD_SUB_TYPE AS field_subtype,
                            coll.RDB$COLLATION_NAME AS field_collation,
                            cset.RDB$CHARACTER_SET_NAME AS field_charset
                       FROM RDB$RELATION_FIELDS r
                       LEFT JOIN RDB$FIELDS f ON r.RDB$FIELD_SOURCE = f.RDB$FIELD_NAME
                       LEFT JOIN RDB$COLLATIONS coll ON r.RDB$COLLATION_ID = coll.RDB$COLLATION_ID
                        AND f.RDB$CHARACTER_SET_ID = coll.RDB$CHARACTER_SET_ID
                       LEFT JOIN RDB$CHARACTER_SETS cset ON f.RDB$CHARACTER_SET_ID = cset.RDB$CHARACTER_SET_ID
                      WHERE r.RDB$RELATION_NAME = \'' . $record->tableName . '\'
                    ORDER BY r.RDB$FIELD_POSITION';
            $tableInfo = $this->fetch($sqlInfo, 1000, 0)->AsObject();


            $primaryKeys = $this->fetch('SELECT rc.RDB$CONSTRAINT_NAME,
                                                      s.RDB$FIELD_NAME AS field_name,
                                                      rc.RDB$CONSTRAINT_TYPE AS constraint_type,
                                                      i.RDB$DESCRIPTION AS description,
                                                      rc.RDB$DEFERRABLE AS is_deferrable,
                                                      rc.RDB$INITIALLY_DEFERRED AS is_deferred,
                                                      refc.RDB$UPDATE_RULE AS on_update,
                                                      refc.RDB$DELETE_RULE AS on_delete,
                                                      refc.RDB$MATCH_OPTION AS match_type,
                                                      i2.RDB$RELATION_NAME AS references_table,
                                                      s2.RDB$FIELD_NAME AS references_field,
                                                      (s.RDB$FIELD_POSITION + 1) AS field_position
                                                 FROM RDB$INDEX_SEGMENTS s
                                            LEFT JOIN RDB$INDICES i ON i.RDB$INDEX_NAME = s.RDB$INDEX_NAME
                                            LEFT JOIN RDB$RELATION_CONSTRAINTS rc ON rc.RDB$INDEX_NAME = s.RDB$INDEX_NAME
                                            LEFT JOIN RDB$REF_CONSTRAINTS refc ON rc.RDB$CONSTRAINT_NAME = refc.RDB$CONSTRAINT_NAME
                                            LEFT JOIN RDB$RELATION_CONSTRAINTS rc2 ON rc2.RDB$CONSTRAINT_NAME = refc.RDB$CONST_NAME_UQ
                                            LEFT JOIN RDB$INDICES i2 ON i2.RDB$INDEX_NAME = rc2.RDB$INDEX_NAME
                                            LEFT JOIN RDB$INDEX_SEGMENTS s2 ON i2.RDB$INDEX_NAME = s2.RDB$INDEX_NAME
                                                WHERE i.RDB$RELATION_NAME=\'' . $record->tableName . '\'
                                                  AND rc.RDB$CONSTRAINT_TYPE IS NOT NULL
                                             ORDER BY s.RDB$FIELD_POSITION')->AsObject();


            $PK = [];
            foreach ($primaryKeys as $pkid => $primaryKey) {
                $PK[$primaryKey->fieldName]["IS_PRIMARY_KEY"] = ($primaryKey->constraintType === "PRIMARY KEY");
                $PK[$primaryKey->fieldName]["IS_FOREIGN_KEY"] = ($primaryKey->constraintType === "FOREIGN KEY");
                $PK[$primaryKey->fieldName]["CONSTRAINT_TYPE"] = $primaryKey->constraintType;
            }


            //Go through the tables and extract their column information
            foreach ($tableInfo as $tid => $tRecord) {
                $database[trim($record->tableName)][$tid]["column"] = $tid;
                $database[trim($record->tableName)][$tid]["field"] = trim($tRecord->fieldName);
                $database[trim($record->tableName)][$tid]["description"] = trim($tRecord->fieldDescription);
                $database[trim($record->tableName)][$tid]["type"] = trim($tRecord->fieldType);
                $database[trim($record->tableName)][$tid]["length"] = trim($tRecord->fieldLength);
                $database[trim($record->tableName)][$tid]["precision"] = trim($tRecord->fieldPrecision);
                $database[trim($record->tableName)][$tid]["default"] = trim($tRecord->fieldDefaultValue);
                if (!empty($tRecord->fieldNotNullContraint)) {
                    $database[trim($record->tableName)][$tid]["notnull"] = trim($tRecord->fieldNotNullContraint);
                }
                if (!empty($PK[$tRecord->fieldName])) {
                    $database[trim($record->tableName)][$tid]["pk"] = trim($PK[$tRecord->fieldName]["CONSTRAINT_TYPE"]);
                } else {
                    $database[trim($record->tableName)][$tid]["pk"] = "";
                }
            }
        }

        return $database;
    }

    /**
     * Gets the default database date format
     * @return mixed|string
     */
    public function getDefaultDatabaseDateFormat()
    {
        return "m/d/Y";
    }

    /**
     * Gets the default database port
     * @return int|mixed
     */
    public function getDefaultDatabasePort()
    {
        return 3050;
    }

    /**
     * Specific to firebird generators
     * @param $generatorName
     * @param int $increment
     * @return mixed
     */
    public function getGeneratorId($generatorName, $increment = 1)
    {
        return ibase_gen_id(strtoupper($generatorName), $increment, $this->dbh);
    }
}
