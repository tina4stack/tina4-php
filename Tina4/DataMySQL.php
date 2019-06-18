<?php
/**
 * Created by PhpStorm.
 * User: andrevanzuydam
 * Date: 2019-06-18
 * Time: 22:10
 */
namespace Tina4;

class DataMYSQL extends DataBase
{
    public function native_open() {
        inherited:
        $this->dbh = mysqli_connect($this->hostName, $this->username, $this->password, $this->databaseName);

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
            $preparedQuery = @mysqli_prepare($params[0]);
            if (!empty($preparedQuery)) {
                $params[0] = $preparedQuery;
                @call_user_func_array("mysqli_execute", $params);
            }

            return $this->error();
        }
    }

    public function native_error() {
        $error = mysqli_error($this->dbh);

        return (new DataError( $errorCode="1000", $error));
    }

    public function native_fetch($sql="", $noOfRecords=10, $offSet=0) {
        $initialSQL = $sql;

        if (strpos($sql, "limit") === false) {
            $sql .= " limit {$noOfRecords},{$offSet}";
        }

        $recordCursor = @mysqli_query($this->dbh, $sql );

        $records = null;
        $record = null;

        while($record = @mysqli_fetch_assoc($recordCursor)) {
            $records[] = (new DataRecord( $record ));
        }


        if (count($records) > 1) {
            if (stripos($sql, "returning") === false) {
                $sqlCount = "select count(*) as COUNT_RECORDS from ($initialSQL) t";

                $recordCount = @mysqli_query($this->dbh, $sqlCount);

                $resultCount = @mysqli_fetch_assoc($recordCount);


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
            //$record = $records[0];
            $fields = mysqli_fetch_fields($recordCursor);
            foreach ($fields as $field => $fieldInfo) {
                $fieldInfo = (array) $fieldInfo;

                $fields[] = (new DataField($fid, $fieldInfo["name"], $fieldInfo["orgname"], $fieldInfo["type"], $fieldInfo["length"]));
                $fid++;
            }
        }

        $error = $this->error();
        return (new DataResult($records, $fields, $resultCount["COUNT_RECORDS"], $offSet, $error));
    }

    public function native_commit() {
        //No commit for sqlite
        mysqli_commit($this->dbh);
    }
}

