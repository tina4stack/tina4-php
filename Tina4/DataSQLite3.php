<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/03/27
 * Time: 09:53 PM
 */
namespace Tina4;

class DataSQLite3 extends DataBase
{
    public $port = "";
    public function native_open() {
        inherited:
        $this->dbh = (new \SQLite3($this->databaseName)); //create the new database or open existing one
        $this->dbh->busyTimeout(5000); //prevent database locks
        $this->dbh->exec('PRAGMA journal_mode = wal;'); //help with concurrency
    }

    public function native_close() {
        $this->dbh->close();
    }

    public function native_exec() {
        $params = func_get_args();

        $sql = $params[0];

        @$preparedQuery = $this->dbh->prepare($sql);

        if (!empty($preparedQuery)) {
            unset($params[0]);

            foreach ($params as $pid => $param) {
                $param = $this->dbh->escapeString($param);
                if (is_numeric($param)) {
                    @$preparedQuery->bindValue("{$pid}", $param, SQLITE3_FLOAT);
                }
                else
                    if (is_integer($param))
                    {
                        @$preparedQuery->bindValue("{$pid}", $param, SQLITE3_INTEGER);
                    }
                    else
                        if (is_string($param))
                        {
                            @$preparedQuery->bindValue("{$pid}", $param, SQLITE3_TEXT);
                        }
                        else
                        {
                            @$preparedQuery->bindValue("{$pid}", $param, SQLITE3_BLOB);
                        }
            }
            @$preparedQuery->execute();
            @$preparedQuery->close();

        }

        return $this->error();
    }

    public function native_error() {
        return (new DataError( $this->dbh->lastErrorCode(), $this->dbh->lastErrorMsg()));
    }

    public function native_fetch($sql="", $noOfRecords=10, $offSet=0) {
        $sql = $sql." limit {$offSet},{$noOfRecords}";

        $recordCursor = $this->dbh->query($sql);
        $records = [];
        for ($i = 0; $i < $noOfRecords; $i++ ) {
            $recordArray = $recordCursor->fetchArray(SQLITE3_ASSOC);
            if (!empty($recordArray)) {
                $records[] = (new DataRecord($recordArray));
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
            $noOfRecords = 0;
        }

        return (new DataResult($records, $fields, $noOfRecords, $offSet));
    }

    public function native_commit() {
        //No commit for sqlite
    }

}
