<?php
/**
 * Created by PhpStorm.
 * User: andrevanzuydam
 * Date: 2019-06-18
 * Time: 22:10
 */

namespace Tina4;

/**
 * Class DataMySQL Instantiates database functions
 * @package Tina4
 */
class DataMySQL extends DataBase
{
    /**
     * @var integer Port number used by MySQL
     */
    public $port = 3306;

    /**
     * Opens database connection
     * @return bool|void
     * @throws \Exception
     */
    public function native_open()
    {
        inherited:
        if (!function_exists("mysqli_connect")) {
            throw new \Exception("Mysql extension for PHP needs to be installed");
        }


        $this->dbh = \mysqli_connect($this->hostName, $this->username, $this->password, $this->databaseName, $this->port);

    }

    /**
     * Closes database connection
     * @return bool|void
     */
    public function native_close()
    {
        mysqli_close($this->dbh);
    }

    /**
     * Executes
     * @return array|bool
     */
    public function native_exec()
    {
        $params = func_get_args();

        if (stripos($params[0], "returning") !== false) {
            $fetchData = $this->fetch($params[0]);
            return $fetchData;
        } else {


            $preparedQuery = @\mysqli_prepare($this->dbh, $params[0]);
            if (!empty($preparedQuery)) {
                $params[0] = $preparedQuery;
                @call_user_func_array("\mysqli_execute", $params);
            }

            return $this->error();
        }
    }

    /**
     * Gets MySQL errors
     * @return bool|DataError
     */
    public function native_error()
    {
        $errorNo = @\mysqli_errno($this->dbh);
        $errorMessage = @\mysqli_error($this->dbh);

        return (new DataError($errorNo, $errorMessage));
    }

    /**
     * Fetches records from database
     * @param string $sql SQL Query
     * @param integer $noOfRecords Number of records requested
     * @param integer $offSet Record offset
     * @return bool|DataResult
     */
    public function native_fetch($sql = "", $noOfRecords = 10, $offSet = 0)
    {
        $initialSQL = $sql;

        if (strpos($sql, "limit") === false) {
            $sql .= " limit {$offSet},{$noOfRecords}";
        }

        $recordCursor = @\mysqli_query($this->dbh, $sql);


        $error = $this->error();


        $records = null;
        $record = null;
        $fields = null;
        $resultCount = [];
        $resultCount["COUNT_RECORDS"] = 1;

        if ($error->getError()["errorCode"] == 0) {
            if ($recordCursor->num_rows > 0) {
                while ($record = @\mysqli_fetch_assoc($recordCursor)) {

                    if (is_array($record)) {
                        $records[] = (new DataRecord($record));
                    }
                }


                if (is_array($records) && count($records) >= 1) {
                    if (stripos($sql, "returning") === false) {
                        $sqlCount = "select count(*) as COUNT_RECORDS from ($initialSQL) t";

                        $recordCount = @\mysqli_query($this->dbh, $sqlCount);

                        $resultCount = @\mysqli_fetch_assoc($recordCount);

                        if (empty($resultCount)) {
                            $resultCount["COUNT_RECORDS"] = 0;
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
                $fields = @mysqli_fetch_fields($recordCursor);

                foreach ($fields as $fieldId => $fieldInfo) {
                    $fieldInfo = (array)json_decode(json_encode($fieldInfo));

                    $fields[] = (new DataField($fid, $fieldInfo["name"], $fieldInfo["orgname"], $fieldInfo["type"], $fieldInfo["length"]));
                    $fid++;
                }
            }
        } else {
            $resultCount["COUNT_RECORDS"] = 0;
        }


        return (new DataResult($records, $fields, $resultCount["COUNT_RECORDS"], $offSet, $error));
    }

    /**
     * Gets the last inserted row's ID from database
     * @return bool
     */
    public function native_getLastId()
    {
        $lastId = $this->fetch("SELECT LAST_INSERT_ID() as last_id");

        return $lastId->record(0)->LAST_ID;
    }

    /**
     * Commits
     */
    public function native_commit()
    {
        //No commit for sqlite
        \mysqli_commit($this->dbh);
    }
}
