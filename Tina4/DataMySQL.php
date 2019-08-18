<?php
/**
 * Created by PhpStorm.
 * User: andrevanzuydam
 * Date: 2019-06-18
 * Time: 22:10
 */
namespace Tina4;

class DataMySQL extends DataBase
{
    public $port = 3306;

    public function native_open() {
        inherited:
        $this->dbh = \mysqli_connect($this->hostName, $this->username, $this->password, $this->databaseName, $this->port);

    }

    public function native_close() {
        mysqli_close($this->dbh);
    }

    public function native_exec() {
        $params = func_get_args();

        if (stripos($params[0], "returning") !== false) {
            $fetchData = $this->fetch($params[0]);
            return $fetchData;
        } else {


            $preparedQuery = @\mysqli_prepare($this->dbh, $params[0]);
            if (!empty($preparedQuery)) {
                $params[0] = $preparedQuery;
                $result = @call_user_func_array("\mysqli_execute", $params);
            }

            return $this->error();
        }
    }

    public function native_error() {
        $errorNo = @\mysqli_errno($this->dbh);
        $errorMessage = @\mysqli_error($this->dbh);

        return (new DataError( $errorNo, $errorMessage));
    }

    public function native_fetch($sql="", $noOfRecords=10, $offSet=0) {
        $initialSQL = $sql;

        if (strpos($sql, "limit") === false) {
            $sql .= " limit {$offSet},{$noOfRecords}";
        }

        $recordCursor = @\mysqli_query($this->dbh, $sql );


        $records = null;
        $record = null;

        if ($recordCursor->num_rows > 0) {
            while ($record = @\mysqli_fetch_assoc($recordCursor)) {
                if (is_array($record)) {
                    $records[] = (new DataRecord($record));
                }
            }


            if (is_array($records) && count($records) > 1) {
                if (stripos($sql, "returning") === false) {
                    $sqlCount = "select count(*) as COUNT_RECORDS from ($initialSQL) t";

                    $recordCount = @\mysqli_query($this->dbh, $sqlCount);

                    $resultCount = @\mysqli_fetch_assoc($recordCount);

                } else {
                    $resultCount = null;
                }
            } else {
                $resultCount["COUNT_RECORDS"] = 1;
            }
        } else {
            $resultCount["COUNT_RECORDS"] = 1;
        }

        //populate the fields
        $fid = 0;
        $fields = [];
        if (!empty($records)) {
            //$record = $records[0];
            $fields = @mysqli_fetch_fields($recordCursor);

            foreach ($fields as $fieldId => $fieldInfo) {
                $fieldInfo = (array)json_decode(json_encode($fieldInfo));

                $fields[] = (new DataField($fid, $fieldInfo["name"], $fieldInfo["orgname"], $fieldInfo["type"], $fieldInfo["length"]));
                $fid++;
            }
        }

        $error = $this->error();

        return (new DataResult($records, $fields, $resultCount["COUNT_RECORDS"], $offSet, $error));
    }

    public function native_commit() {
        //No commit for sqlite
        \mysqli_commit($this->dbh);
    }
}
