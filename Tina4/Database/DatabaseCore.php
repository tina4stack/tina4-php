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
    public function parseParams(array $params): array
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
    public function fetchOne(string $sql)
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
    public function getQueryParam(string $fieldName, int $fieldIndex): string
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
    public function select(string $fields = "*", int $limit = 10, int $offset = 0, array $hasOne = [], array $hasMany = []): SQL
    {
        return (new SQL())->select($fields, $limit, $offset, $hasOne, $hasMany, $this);
    }

    /**
     * @param $sql
     * @return array
     * @tests tina4 sql
     *   assert("select id,test from tableName") === ["collectionName" => "tableName",  "columns" => ["id","test"], "filter" => []],"Testing to see if we can get fields and collection name"
     *   assert("select id,test from tableName where id = 1") === ["collectionName" => "tableName", "columns" => ["id","test"], "filter" => ['id' => ['$eq' => '1']]],"Testing to see if we can get fields and collection name"
     *   assert("select id,test from tableName where id = 1 and id > 0") === ["collectionName" => "tableName",  "columns" => ["id","test"],  "filter" => ['id' => ['$eq' => '1'], 'and' => ['id' => ['$gt' => '0']]] ],"Testing to see if we can get fields and collection name"
     */
    public function parseSQLToNoSQL($sql): array
    {
        $sql = str_replace("\n", " ", $sql);
        $sql = str_replace("\r", " ", $sql);


        $comparisonOperators = ['=' => '$eq', 'is' => '$eq', '>' => '$gt', '>=' => '$gte', '<' => '$lt', '<=' => '$lte', '<>' => '$ne', 'not in' => '$nin', 'in' => '$in'];

        $logicalOperators = ['and' => '$and', 'or' => '$or', 'not' => '$not'];

        $insert = '/(insert into)(.*)\((.*)\)(.*)(values)(.*)/m';

        $update = '/(update)(.*)(set)(.*)(where)(.*)/m';

        $plain = '/(select)(.*)(from)(.*)/m';

        $normal = '/(select)(.*)(from)(.*)(where)(.*)/m';

        $withOrderBy = '/(select)(.*)(from)(.*)(where)(.*)(order\ by)(.*)/m';

        if (stripos($sql, "insert") !== false) {
            preg_match_all($insert, $sql, $matches, PREG_SET_ORDER, 0);
        } else
        if (stripos($sql, "update") !== false) {
            preg_match_all($update, $sql, $matches, PREG_SET_ORDER, 0);
        }
          else
        if (stripos($sql, "where") === false) {
            preg_match_all($plain, $sql, $matches, PREG_SET_ORDER, 0);
        }
          else
        if (stripos($sql, "order by") !== false) {
            preg_match_all($withOrderBy, $sql, $matches, PREG_SET_ORDER, 0);
        } else {
            preg_match_all($normal, $sql, $matches, PREG_SET_ORDER, 0);
        }

        if (stripos($sql, "insert") !== false) {
            $columns = [];

            $tempColumns = explode(",", trim($matches[0][3]));

            foreach ($tempColumns as $id => $column) {
                array_push($columns, trim($column));
            }

            $filter = "";

            $collectionName = trim($matches[0][2]);
        }
          else
        if (stripos($sql, "update") === false) {
            $columns = [];

            $tempColumns = explode(",", trim($matches[0][2]));

            foreach ($tempColumns as $id => $column) {
                $part = explode("as", $column);

                array_push($columns, trim($part[0]));
            }

            $collectionName = trim($matches[0][4]);

            $filter = "";

            if (count($matches[0]) > 6) {
                $filter = $matches[0][6];
            }
        } else {
            $columns = [];

            $tempColumns = explode(",", trim($matches[0][4]));

            foreach ($tempColumns as $id => $column) {
                $part = explode("=", $column);

                array_push($columns, trim($part[0]));
            }

            $collectionName = trim($matches[0][2]);

            if (count($matches[0]) > 6) {
                $filter = $matches[0][6];
            }
        }

        $filters = [];
        //extract each of the operators

        $expressions = explode (" ", trim($filter));

        if (!empty($expressions) && count($expressions) > 0) {
            $tempArray = [];

            $lastOperator = "";

            foreach ($expressions as $id => $expression) {
                $tempArray[] = $expression;
                if (array_key_exists($expression, $logicalOperators)) {
                    if (!empty($lastOperator)) {
                        $tempArray[2] = str_replace("'", "", $tempArray[2]);
                        $filters[$lastOperator][$tempArray[0]][$comparisonOperators[$tempArray[1]]] = is_numeric(trim($tempArray[2]) * 1.00) ?  trim($tempArray[2]) * 1.00 : trim($tempArray[2]);
                    } else {
                        $tempArray[2] = str_replace("'", "", $tempArray[2]);
                        $filters[$tempArray[0]][$comparisonOperators[$tempArray[1]]] =  is_numeric(trim($tempArray[2]) * 1.00) ?  trim($tempArray[2]) * 1.00 : trim($tempArray[2]);
                    }

                    $lastOperator = $expression;

                    $tempArray = [];
                }
            }

            if (!empty($tempArray) && count($tempArray) > 1) {
                if (!empty($lastOperator)) {
                    $tempArray[2] = str_replace("'", "", $tempArray[2]);
                    $filters[$lastOperator][$tempArray[0]][$comparisonOperators[$tempArray[1]]] = is_numeric(trim($tempArray[2]) * 1.00) ?  trim($tempArray[2]) * 1.00 : trim($tempArray[2]);
                } else {
                    $tempArray[2] = str_replace("'", "", $tempArray[2]);
                    $filters[$tempArray[0]][$comparisonOperators[$tempArray[1]]] =  is_numeric(trim($tempArray[2]) * 1.00) ?  trim($tempArray[2]) * 1.00 : trim($tempArray[2]);
                }
            }

        }

        return ["collectionName" => $collectionName, "columns" => $columns, "filter" => $filters];
    }

}
