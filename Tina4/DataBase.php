<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/02/29
 * Time: 07:50 PM
 */
namespace Tina4;

class DataBase
{
    public $dbh;           //database handle
    public $databaseName;
    public $hostName;
    public $username;
    public $password;
    public $dateFormat;
    public $fetchLimit=100; //limit an sql result set
    public $cache;
    public $transaction;


    public function native_open() {
        die("Implement the public method native_open for your database engine");
    }

    public function native_close() {
        die("Implement the public method native_close for your database engine");
    }

    public function native_exec() {
        die("Implement the public method native_exec for your database engine");
    }

    public function native_error() {
        die("Implement the public method native_error for your database engine");
    }

    public function native_getLastId() {
        die("Implement the public method native_getLastId for your database engine");
    }

    public function native_fetch() {
        die("Implement the public method native_fetch for your database engine");
    }

    public function __construct($database, $username="", $password="", $dateFormat="yyyy-mm-dd") {
        define ("DATA_ARRAY", 0);
        define ("DATA_OBJECT", 1);
        define ("DATA_NUMERIC", 2);

        define ("DATA_TYPE_TEXT", 0);
        define ("DATA_TYPE_NUMERIC", 1);
        define ("DATA_TYPE_BINARY", 2);

        define ("DATA_ALIGN_LEFT", 0);
        define ("DATA_ALIGN_RIGHT", 1);

        define ("DATA_CASE_UPPER", 1);

        define ("DATA_NO_SQL", "ERR001");

        global $cache;
        if (!empty($cache)) {
            $this->cache = $cache;
        }

        $this->username = $username;
        $this->password = $password;

        if (strpos($database, ":") !== false) {
            $database = explode(":", $database,2);
            $this->hostName = $database[0];
            $this->databaseName = $database[1];
        } else {
            $this->hostName = "";
            $this->databaseName = $database;
        }
        $this->dateFormat = $dateFormat;
        $this->native_open();
    }


    public function close() {
        $this->native_close();
    }

    public function exec() {
        $params = func_get_args();
        if (count($params) > 0) {
            return call_user_func_array(array($this, "native_exec"), $params);
        } else  {
            return (new DataError(DATA_NO_SQL, "No sql statement found for exec"))->getError();
        }

    }

    public function getLastId() {
        return $this->native_getlastId();
    }

    /**
     * @return DataResult
     * First argument is the SQL statment followed by parameters....
     */
    public function fetch() {
        $params = func_get_args();
        return call_user_func_array(array($this, "native_fetch"), $params);
    }

    public function  commit() {
        return $this->native_commit();
    }


    public function error() {
        return $this->native_error();
    }


}