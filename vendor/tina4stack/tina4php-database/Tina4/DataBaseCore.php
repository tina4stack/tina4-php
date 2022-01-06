<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Trait DataBaseCore Instantiates all common database methods
 * @package Tina4
 */
trait DataBaseCore
{
    /**
     * @var resource $dbh Database handle
     */
    public $dbh;

    /**
     * @var string $databaseName Name of database
     */
    public $databaseName;

    /**
     * @var integer $port Port number of database
     */
    public $port; //Declare in the implementation

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

    /**
     * @var xcache
     */
    public $cache;

    /**
     * @var
     */
    public $transaction;

    /**
     * DataBase constructor.
     * @param string|null $database - In the form [host/port:database]
     * @param string|null $username Database user username
     * @param string|null $password Database user password
     * @param string $dateFormat Format of date
     */
    public function __construct(?string $database, ?string $username = "", ?string $password = "", string $dateFormat = "Y-m-d")
    {
        global $cache;

        if (!empty($cache)) {
            $this->cache = $cache;
        }

        $this->username = $username;
        $this->password = $password;

        //Ignore memory database for SQLite in this line
        if (strpos($database, ":") !== false && strpos($database, "memory") === false) {
            $database = explode(":", $database, 2);
            $this->hostName = $database[0];
            $this->databaseName = $database[1];

            if (strpos($this->hostName, "/") !== false) {
                $port = explode("/", $this->hostName);
                $this->hostName = $port[0];
                $this->port = $port[1];
            } else {
                $this->port = $this->getDefaultDatabasePort();
            }
        } else {  //Probably SQLite database
            $this->hostName = "";
            $this->databaseName = $database;
        }
        //Set the date format we want date results to display in
        $this->dateFormat = $dateFormat;
        $this->open();
    }

    /**
     * Parses the params into params & sql, checks for SQL injection
     * @param array $params
     * @return array
     */
    final public function parseParams(array $params): array
    {
        $tranId = "";
        $newParams = [];
        for ($i = 0, $iMax = count($params); $i < $iMax; $i++) {
            if (strpos($params[$i] . "", "Resource id #") !== false) {
                $tranId = $params[$i];
            } else {
                $newParams[] = $params[$i];
            }
        }

        return ["params" => $newParams, "tranId" => $tranId];
    }

    /**
     * Returns back only the first element of a result which can then be used as is or serialized to array or object
     * @param string $sql
     * @return mixed
     */
    final public function fetchOne(string $sql)
    {
        $records = $this->fetch($sql)->records;
        if (is_array($records) && count($records) > 0) {
            return $records[0];
        } else {
            return null;
        }
    }

    /**
     * This returns the default query params for an exec statement
     * @param string $fieldName
     * @param int $fieldIndex
     * @return string
     */
    final public function getQueryParam(string $fieldName, int $fieldIndex): string
    {
        return "?";
    }

    /**
     * The select statement is passed off to \Tina4\SQL
     * @param string $fields
     * @param int $limit
     * @param int $offset
     * @param array $hasOne
     * @param array $hasMany
     * @return SQL
     */
    final public function select(string $fields = "*", int $limit = 10, int $offset = 0, array $hasOne = [], array $hasMany = []): SQL
    {
        return (new SQL(null, $this))->select($fields, $limit, $offset, $hasOne, $hasMany);
    }
}
