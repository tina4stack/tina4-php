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

        $error = $this->error();

        if ($error->getError()["errorCode"] == 0 && !empty($preparedQuery)) {
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
        return (new \Tina4\DataError( $this->dbh->lastErrorCode(), $this->dbh->lastErrorMsg()));
    }

    /**
     * Native fetch for SQLite
     * @param string $sql
     * @param int $noOfRecords
     * @param int $offSet
     * @param array $fieldMapping
     * @return bool|DataResult
     */
    public function native_fetch($sql="", $noOfRecords=10, $offSet=0, $fieldMapping=[]) {
        $countRecords = 0;
        $countRecords = @$this->dbh->querySingle("select count(*) as count from (".$sql.")");
        
        $sql = $sql." limit {$offSet},{$noOfRecords}";

        $recordCursor = @$this->dbh->query($sql);
        $records = [];
        if (!empty($recordCursor)) {
            while ($recordArray = $recordCursor->fetchArray(SQLITE3_ASSOC)) {
                if (!empty($recordArray)) {
                    $records[] = (new DataRecord($recordArray, $fieldMapping));
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
            $noOfRecords = 0;
        }

        $error = $this->error();

        return (new DataResult($records, $fields, $countRecords, $offSet, $error));
    }

    public function native_tableExists($tableName)
    {
        $exists = $this->fetch ("SELECT name FROM sqlite_master WHERE type='table' AND name='{$tableName}'");

        return !empty($exists->records());
    }

    public function native_getLastId()
    {
        $lastId = $this->fetch("SELECT last_insert_rowid() as last_id");
        return $lastId->records(0)[0]->lastId;
    }

    /**
     * Commit
     * @param null $transactionId
     * @return bool
     */
    public function native_commit($transactionId=null) {
        //No commit for sqlite
        return true;
    }

    /**
     * Rollback
     * @param null $transactionId
     * @return bool
     */
    public function native_rollback($transactionId=null) {
        //No transactions for sqlite
        return true;
    }

    /**
     * Start transaction
     * @return bool
     */
    public function native_startTransaction() {
        //No transactions for sqlite
        return true;
    }

    /**
     * Auto commit on for SQlite
     * @param bool $onState
     * @return bool|void
     */
    public function native_autoCommit($onState=false)
    {
        //SQlite has no commits
        return true;
    }

    function native_getDatabase()
    {
        $sqlTables = "select name as table_name
                      from sqlite_master
                     where type='table'
                  order by name";
        $tables    = $this->fetch( $sqlTables, 1000, 0 )->asObject();
        $database = [];
        foreach ( $tables as $id => $record ) {
            $sqlInfo   = "pragma table_info($record->tableName);";
            $tableInfo = $this->fetch( $sqlInfo, 1000, 0 )->AsObject();

            //Go through the tables and extract their column information
            foreach ( $tableInfo as $tid => $trecord ) {
                $database[trim( $record->tableName )][$tid]["column"]      = $trecord->cid;
                $database[trim( $record->tableName )][$tid]["field"]       = trim( $trecord->name );
                $database[trim( $record->tableName )][$tid]["description"] = "";
                $database[trim( $record->tableName )][$tid]["type"]        = trim( $trecord->type );
                $database[trim( $record->tableName )][$tid]["length"]      = "";
                $database[trim( $record->tableName )][$tid]["precision"]   = "";
                $database[trim( $record->tableName )][$tid]["default"]     = trim( $trecord->dfltValue );
                $database[trim( $record->tableName )][$tid]["notnull"]     = trim( $trecord->notnull );
                $database[trim( $record->tableName )][$tid]["pk"]          = trim( $trecord->pk );
            }
        }
        return $database;
    }

}
