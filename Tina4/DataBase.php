<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/02/29
 * Time: 07:50 PM
 */

namespace Tina4;

/**
 * Class DataBase Instantiates all common database procedures
 * @package Tina4
 */
class DataBase
{
    /**
     * @var $dbh Database handle
     */
    public $dbh;

    /**
     * @var string $databaseName Name of database
     */
    public $databaseName;

    /**
     * @var integer $port Port number of database
     */
    public $port;

    /**
     * @var string $hostName Name of host
     */
    public $hostName;

    /**
     * @var string $username Username of user for database
     */
    public $username;

    /**
     * @var string $password Password of user for database
     */
    public $password;

    /**
     * @var string $dateFormat Format of dates
     */
    public $dateFormat;

    /**
     * @var integer $fetchLimit Limit an sql result set
     */
    public $fetchLimit = 100;

    public $cache;
    public $transaction;


    /**
     * Abstract database open connection
     * @return bool
     */
    public function native_open()
    {
        \Tina4\DebugLog::message("Implement the public method native_open for your database engine");
        return false;
    }

    /**
     * Abstract database close connection
     * @return bool
     */
    public function native_close()
    {
        \Tina4\DebugLog::message("Implement the public method native_close for your database engine");
        return false;
    }

    /**
     * Abstract database execute
     * @return bool
     */
    public function native_exec()
    {
        \Tina4\DebugLog::message("Implement the public method native_exec for your database engine");
        return false;
    }

    /**
     * Abstract database error return
     * @return bool
     */
    public function native_error()
    {
        \Tina4\DebugLog::message("Implement the public method native_error for your database engine");
        return false;
    }

    /**
     * Abstract get last inserted row's id from database
     * @return bool
     */
    public function native_getLastId()
    {
        \Tina4\DebugLog::message("Implement the public method native_getLastId for your database engine");
        return false;
    }

    /**
     * Abstract database fetch query
     * @return bool
     */
    public function native_fetch()
    {
        \Tina4\DebugLog::message("Implement the public method native_fetch for your database engine");
        return false;
    }

    /**
     * Abstract database table Exists
     * @param $tableName
     * @return bool
     */
    public function native_tableExists($tableName) {
        \Tina4\DebugLog::message("Implement the public method native_tableExists for your database engine");
        return false;
    }

    /**
     * Override this method for your specific database engine, this should work for most database engines
     * See https://datatables.net/manual/index
     * @return array
     */
    public function native_dataTablesFilter()
    {
        $request = $_REQUEST;

        if (!empty($request["columns"])) {
            $columns = $request["columns"];
        }

        if (!empty($request["order"])) {
            $orderBy = $request["order"];
        }

        $filter = null;
        if (!empty($request["search"])) {
            $search = $request["search"];

            foreach ($columns as $id => $column) {
                if (($column["searchable"] == "true") && !empty($search["value"])) {
                    $filter[] = "upper(" . $column["data"] . ") like '%" . strtoupper($search["value"]) . "%'";
                }
            }
        }

        $ordering = null;
        if (!empty($orderBy)) {
            foreach ($orderBy as $id => $orderEntry) {
                $ordering[] = $columns[$orderEntry["column"]]["data"] . " " . $orderEntry["dir"];
            }
        }

        $order = "";
        if (is_array($ordering) && count($ordering) > 0) {
            $order = "order by " . join(",", $ordering);
        }

        $where = "";
        if (is_array($filter) && count($filter) > 0) {
            $where = "(" . join(" or ", $filter) . ")";
        }

        if (!empty($request["start"])) {
            $start = $request["start"];
        } else {
            $start = 0;
        }

        if (!empty($request["length"])) {
            $length = $request["length"];
        } else {
            $length = 10;
        }

        return ["length" => $length, "start" => $start, "orderBy" => $order, "where" => $where];
    }

    function native_commit($transactionId=null) {
        \Tina4\DebugLog::message("Implement the public method native_commit for your database engine");
        return false;
    }

    /**
     * Rollback transactions
     * @param null $transactionId
     * @return bool
     */
    function native_rollback($transactionId=null) {
        \Tina4\DebugLog::message("Implement the public method native_rollback for your database engine");
        return false;
    }

    /**
     * @param bool $onState Turn autocommit on or off
     * @return bool
     */
    function native_autoCommit($onState=true) {
        \Tina4\DebugLog::message("Implement the public method native_autoCommit for your database engine");
        return false;
    }

    /**
     * Start the transaction
     * @return bool
     */
    function native_startTransaction() {
        \Tina4\DebugLog::message("Implement the public method native_startTransaction for your database engine");
        return false;
    }

    /**
     * DataBase constructor.
     * @param $database - In the form [host/port:database]
     * @param string $username Database user username
     * @param string $password Database user password
     * @param string $dateFormat Format of date
     */
    public function __construct($database, $username = "", $password = "", $dateFormat = "yyyy-mm-dd")
    {
        if (!defined("DATA_ARRAY")) define("DATA_ARRAY", 0);
        if (!defined("DATA_OBJECT")) define("DATA_OBJECT", 1);
        if (!defined("DATA_NUMERIC")) define("DATA_NUMERIC", 2);

        if (!defined("DATA_TYPE_TEXT")) define("DATA_TYPE_TEXT", 0);
        if (!defined("DATA_TYPE_NUMERIC")) define("DATA_TYPE_NUMERIC", 1);
        if (!defined("DATA_TYPE_BINARY")) define("DATA_TYPE_BINARY", 2);

        if (!defined("DATA_ALIGN_LEFT")) define("DATA_ALIGN_LEFT", 0);
        if (!defined("DATA_ALIGN_RIGHT")) define("DATA_ALIGN_RIGHT", 1);

        if (!defined("DATA_CASE_UPPER")) define("DATA_CASE_UPPER", 1);

        if (!defined("DATA_NO_SQL"))  define("DATA_NO_SQL", "ERR001");

        global $cache;
        if (!empty($cache)) {
            $this->cache = $cache;
        }

        $this->username = $username;
        $this->password = $password;

        if (strpos($database, ":") !== false) {
            $database = explode(":", $database, 2);
            $this->hostName = $database[0];
            $this->databaseName = $database[1];

            if (strpos($this->hostName, "/") !== false) {
                $port = explode("/", $this->hostName);
                $this->hostName = $port[0];
                $this->port = $port[1];
            }
        } else {
            $this->hostName = "";
            $this->databaseName = $database;
        }
        $this->dateFormat = $dateFormat;
        $this->native_open();
    }


    /**
     * Sets database close connection from currently used database
     */
    public function close()
    {
        $this->native_close();
    }

    /**
     * Gets data tables filter
     * @return array
     */
    public function getDataTablesFilter()
    {
        return $this->native_dataTablesFilter();
    }

    /**
     * Sets database execute from currently used database
     * @return array|mixed
     */
    public function exec()
    {
        $params = func_get_args();
        if (count($params) > 0) {
            return call_user_func_array(array($this, "native_exec"), $params);
        } else {
            return (new DataError(DATA_NO_SQL, "No sql statement found for exec"))->getError();
        }

    }

    /**
     * Sets database get last inserted row's id from currently used database
     * @return bool
     */
    public function getLastId()
    {
        return $this->native_getLastId();
    }

    /**
     * Checks to see if a table exists
     * @param $tableName
     * @return mixed
     */
    public function tableExists($tableName) {
        return $this->native_tableExists($tableName);
    }

    /**
     * Fetch a result set of DataResult
     * @param string $sql SQL Query to fetch wanted data
     * @param int $noOfRecords Number of records wanted to return
     * @param int $offSet Row offset for fetched data
     * @param array $fieldMapping Array of mapped fields for mapping to different results
     * @return array DataResult Array of query result data
     * @example examples\exampleDataBaseFetch.php
     */
    public function fetch($sql = "", $noOfRecords = 10, $offSet = 0, $fieldMapping=[])
    {
        $params = func_get_args();
        return call_user_func_array(array($this, "native_fetch"), $params);
    }

    /**
     * Sets database commit from currently used database
     * @param $transactionId Id of the transaction
     * @return mixed
     */
    public function commit($transactionId=null)
    {
        return $this->native_commit($transactionId);
    }

    public function rollback($transactionId=null)
    {
        return $this->native_rollback($transactionId);
    }

    /**
     * Set autocommit on or off
     * @param bool $onState
     * @return bool
     */
    public function autoCommit($onState=true) {
        return $this->native_autoCommit($onState);
    }

    /**
     * Starts a transaction
     * @return integer
     */
    public function startTransaction() {
        return $this->native_startTransaction();
    }

    /**
     * Sets database errors from currently used database
     * @return bool
     */
    public function error()
    {
        return $this->native_error();
    }


}