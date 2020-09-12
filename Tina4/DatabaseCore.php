<?php
namespace Tina4;
/**
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