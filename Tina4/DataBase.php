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
trait DataBaseCore
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
    //public $port; //Declare in the implementation

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
     * DataBase constructor.
     * @param $database - In the form [host/port:database]
     * @param string $username Database user username
     * @param string $password Database user password
     * @param string $dateFormat Format of date
     */
    public function __construct($database, $username = "", $password = "", $dateFormat = "yyyy-mm-dd")
    {
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
        $this->open();
    }
}

/**
 * Interface DataBase and future database abstractions
 * @package Tina4
 */
interface DataBase
{
    public function close();

    /**
     * Sets database execute from currently used database
     * @return array|mixed
     */
    public function exec();

    /**
     * Sets database get last inserted row's id from currently used database
     * @return bool
     */
    public function getLastId();

    /**
     * Checks to see if a table exists
     * @param $tableName
     * @return mixed
     */
    public function tableExists($tableName);

    /**
     * Fetch a result set of DataResult
     * @param string $sql SQL Query to fetch wanted data
     * @param int $noOfRecords Number of records wanted to return
     * @param int $offSet Row offset for fetched data
     * @param array $fieldMapping Array of mapped fields for mapping to different results
     * @return array DataResult Array of query result data
     * @example examples\exampleDataBaseFetch.php
     */
    public function fetch($sql = "", $noOfRecords = 10, $offSet = 0, $fieldMapping = []);

    /**
     * Sets database commit from currently used database
     * @param $transactionId integer Id of the transaction
     * @return mixed
     */

    public function rollback($transactionId = null);

    /**
     * Set autocommit on or off
     * @param bool $onState
     * @return bool
     */
    public function autoCommit($onState = true);

    /**
     * Starts a transaction
     * @return integer
     */
    public function startTransaction();

    /**
     * Sets database errors from currently used database
     * @return bool
     */
    public function error();

    /**
     * Returns metadata of the database
     *  $database => [tables][fields]
     * @return mixed
     */
    public function getDatabase();
}