<?php

namespace Tina4;
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Trait DataBaseCore Instantiates all common database methods
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
     * @param $database - In the form [host/port:database]
     * @param string $username Database user username
     * @param string $password Database user password
     * @param string $dateFormat Format of date
     */
    public function __construct($database, $username = "", $password = "", $dateFormat = "Y-m-d")
    {
        global $cache;

        if (!empty($cache)) {
            $this->cache = $cache;
        }

        $this->username = $username;
        $this->password = $password;

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
        } else {
            $this->hostName = "";
            $this->databaseName = $database;
        }
        $this->dateFormat = $dateFormat;
        $this->open();

    }

    /**
     * Parses the params into params & sql, checks for SQL injection
     * @param $params
     * @return array
     */
    public function parseParams($params)
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
     * @param $sql
     * @return mixed
     */
    public function fetchOne($sql)
    {
        $records = $this->fetch($sql)->records;
        if (is_array($records) && count($records) > 0)
        {
            return $records[0];
        } else {
            return null;
        }
    }

    /**
     * @param $fieldName
     * @param $fieldIndex
     * @return string
     */
    public function getQueryParam($fieldName,$fieldIndex): string
    {
        return "?";
    }

}