<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 5/19/2016
 * Time: 11:19 AM
 */
namespace Tina4;

class DataFirebird extends DataBase
{
    public $port = 3050;

    public function native_open() {
        inherited:

        $this->dbh = ibase_connect($this->hostName.":".$this->databaseName, $this->username, $this->password); //create the new database or open existing one
    }

    public function native_close() {
        ibase_close($this->dbh);
    }

    public function native_exec() {
        $params = func_get_args();

        if (stripos($params[0], "returning") !== false) {
            return $this->fetch($params[0]);
        } else {
            $preparedQuery = @ibase_prepare($params[0]);
            if (!empty($preparedQuery)) {
                $params[0] = $preparedQuery;
                @call_user_func_array("ibase_execute", $params);
            }

            return $this->error();
        }
    }

    public function native_error() {
        $errorCode = ibase_errcode();
        $errorMessage = ibase_errmsg();
        return (new DataError( $errorCode, $errorMessage));
    }

    /**
     * Firebird implementation of fetch
     * @param string $sql
     * @param int $noOfRecords
     * @param int $offSet
     * @param $fieldMapping
     * @return bool|DataResult
     */
    public function native_fetch($sql="", $noOfRecords=10, $offSet=0, $fieldMapping=[]) {
        $initialSQL = $sql;
        if (stripos($sql, "returning") === false) {
            //inject in the limits for the select - in Firebird select first x skip y
            $limit = " first {$noOfRecords} skip {$offSet} ";

            $posSelect = stripos($sql, "select") + strlen("select");

            $sql = substr($sql, 0, $posSelect) . $limit . substr($sql, $posSelect);   //select first 10 skip 10 from table
        }

        $recordCursor = @ibase_query($this->dbh, $sql );

        $records = null;
        $record = null;

        while($record = @ibase_fetch_assoc($recordCursor)) {
            foreach ($record as $key => $value) {
                if (substr($value, 0,2) === "0x") {
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
            $records[] = (new DataRecord( $record, $fieldMapping ));
        }



        if (is_array($records) && count($records) > 1) {
            if (stripos($sql, "returning") === false) {
                $sqlCount = "select count(*) as COUNT_RECORDS from ($initialSQL)";

                $recordCount = @ibase_query($this->dbh, $sqlCount);

                $resultCount = @ibase_fetch_assoc($recordCount);


            } else {
                $resultCount = null;
            }
        } else {
            $resultCount["COUNT_RECORDS"] = 1;
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
     * Commit
     * @param null $transactionId
     * @return bool
     */
    public function native_commit($transactionId=null) {
        if (!empty($transactionId)) {
            return @ibase_commit($transactionId);
        } else {
            return @ibase_commit($this->dbh);
        }
    }

    /**
     * Rollback
     * @param null $transactionId
     * @return bool
     */
    public function native_rollback($transactionId = null)
    {
        if (!empty($transactionId)) {
            return @ibase_rollback($transactionId);
        } else {
            return @ibase_rollback($this->dbh);
        }
    }

    /**
     * Auto commit on for Firebird
     * @param bool $onState
     * @return bool|void
     */
    public function native_autoCommit($onState=false)
    {
        //Firebird has commit off by default
        return true;
    }

    public function native_startTransaction()
    {
        return @ibase_trans(IBASE_COMMITTED, $this->dbh);
    }

    /**
     * Check if table exists
     * @param $tableName
     * @return bool
     */
    public function native_tableExists($tableName)
    {
        // table name must be in upper case
        $tableName = strtoupper($tableName);
        $exists = $this->fetch ("SELECT 1 FROM RDB\$RELATIONS WHERE RDB\$RELATION_NAME = '{$tableName}'");

        return !empty($exists->records());
    }

    public function native_getLastId()
    {
        return false;
    }

}
