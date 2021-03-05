<?php

namespace Tina4;
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Class SQL
 * A way to encapsulate standard SQL into an object form
 * @package Tina4
 */
class SQL implements \JsonSerializable
{
    public $DBA;
    public $ORM;
    public $fields;
    public $tableName;
    public $join;
    public $filter;
    public $groupBy;
    public $having;
    public $orderBy;
    public $nextAnd = "join";
    public $limit;
    public $offset;
    public $hasOne;
    public $noOfRecords;
    public $error;
    public $lastSQL;
    public $excludeFields;
    public $filterMethod = []; //Special variable pointing to an anonymous function which takes in a record for manipulation

    /**
     * SQL constructor.
     * @param null $ORM If the ORM object is empty it will default to the global $DBA variable for a database connection
     */
    public function __construct($ORM = null)
    {
        if (!empty($ORM)) {
            $this->ORM = clone $ORM;
            $this->DBA = $this->ORM->DBA;
        } else {
            //See if we can db connection from global $DBA
            global $DBA;
            if (!empty($DBA)) {
                $this->DBA = $DBA;
            }
        }
    }

    /**
     * Excludes fields from the result set
     * @param $fields
     */
    function exclude($fields)
    {
        if (is_array($fields)) {
            $this->excludeFields[] = $fields;
        } else {
            $this->excludeFields = explode(",", $fields);
        }
        return $this;
    }

    /**
     * Selects fields from the database
     * @param string $fields
     * @param int $limit
     * @param int $offset
     * @param array $hasOne
     * @param array $hasMany
     * @return $this
     */
    public function select($fields = "*", $limit = 10, $offset = 0, $hasOne = [], $hasMany = [])
    {
        if (is_array($fields)) {
            $this->fields[] = $fields;
        } else {
            $this->fields = array_map('trim', explode(',', $fields));
        }
        $this->limit = $limit;
        $this->offset = $offset;
        $this->hasOne = $hasOne;
        return $this;
    }

    function from($tableName)
    {
        $this->tableName = $tableName;
        return $this;
    }

    function where($filter)
    {
        //@todo parse filter
        if (trim($filter) !== "") {
            $this->nextAnd = "where";
            $this->filter[] = ["where", $filter];
        }
        return $this;
    }

    public function and($filter)
    {
        //@todo parse filter
        if (trim($filter) !== "") {
            if ($this->nextAnd == "join") {
                $this->join[] = ["and", $filter];
            } else if ($this->nextAnd == "having") {
                $this->having[] = ["and", $filter];
            } else {
                $this->filter[] = ["and", $filter];
            }
        }
        return $this;
    }

    public function or($filter)
    {
        //@todo parse filter
        $this->filter[] = ["or", $filter];
        return $this;
    }

    function join($tableName)
    {
        $this->nextAnd = "join";
        $this->join[] = ["join", $tableName];
        return $this;
    }

    function leftJoin($tableName)
    {
        $this->nextAnd = "join";
        $this->join[] = ["left join", $tableName];
        return $this;
    }

    function on($filter)
    {
        //@todo parse filter
        $this->join[] = ["on", $filter];
        return $this;
    }

    function groupBy($fields)
    {
        if (is_array($fields)) {
            $this->groupBy = $fields;
        } else {
            $this->groupBy = array_map('trim', explode(',', $fields));
        }

        $this->groupBy = $this->translateFields($this->groupBy);
        return $this;
    }

    /**
     * @param $fields
     * @return array
     */
    function translateFields($fields)
    {
        $result = [];
        foreach ($fields as $id => $field) {
            if (!empty($this->ORM)) {
                $result[] = $this->ORM->getFieldName($field);
            } else {
                $result[] = $field;
            }
        }
        return $result;
    }

    function having($filter)
    {
        $this->nextAnd = "having";
        $this->having[] = ["having", $filter];
        return $this;
    }

    function orderBy($fields)
    {
        if (!empty($fields)) {
            if (is_array($fields)) {
                $this->orderBy = $fields;
            } else {
                $this->orderBy = array_map('trim', explode(',', $fields));
            }
            $this->orderBy = $this->translateFields($this->orderBy);
        }


        return $this;
    }

    /**
     * A method which will filter the records
     * @param $filterMethod
     */
    function filter($filterMethod)
    {
        if (!empty($filterMethod)) {
            $this->filterMethod[] = $filterMethod;
        }
        return $this;
    }

    /**
     * Gets an array of objects
     * @return array|mixed
     */
    public function asObject(): array
    {
        return $this->jsonSerialize();
    }


    /**
     * Cleans up column names for JsonSerializable
     * @param $fields
     * @return array
     */
    public function getColumnNames($fields)
    {
        $columnNames = [];
        foreach ($fields as $id => $field) {
            $columnName = $field;
            $field = str_replace([".", " as", " "], "^", $field);
            if (strpos($field, "^") !== false) {
                $explodedField = explode("^", $field);
                $columnName = array_pop($explodedField);
            }
            $columnNames[] = $columnName;
        }
        return $columnNames;
    }

    /**
     * Makes a neat JSON response
     */
    public function jsonSerialize()
    {
        //run the query
        $sqlStatement = $this->generateSQLStatement();

        if (!empty($this->DBA)) {
            $result = $this->DBA->fetch($sqlStatement, $this->limit, $this->offset);
            $this->noOfRecords = $result->getNoOfRecords();
            $records = [];
            //transform the records into an array of the ORM if ORM exists

            $this->lastSQL = $sqlStatement;
            $this->error = $this->DBA->error();

            if (!empty($result->records()) && $this->noOfRecords > 0) {
                $records = $result->AsObject();
                if (!empty($this->ORM)) {
                    foreach ($records as $id => $record) {
                        $newRecord = clone $this->ORM;
                        $newRecord->mapFromRecord($record, true);


                        if (!empty($this->excludeFields)) {
                            foreach ($this->excludeFields as $eid => $excludeField) {

                                if (property_exists($newRecord, $excludeField)) {
                                    unset($newRecord->{$excludeField});
                                }
                            }
                        }

                        //Only return what was requested
                        if (!empty($this->fields) && !in_array("*", $this->getColumnNames($this->fields))) {
                            foreach ($newRecord as $key => $value) {
                                if (in_array($key, $newRecord->protectedFields, true)) continue;
                                if (!in_array($this->ORM->getFieldName($key), $this->getColumnNames($this->fields), true) && strpos($key, " as") === false && strpos($key, ".") === false) {
                                    unset($newRecord->{$key});
                                }
                            }
                        }

                        //Apply a filter to the record
                        if (!empty($this->filterMethod)) {

                            foreach ($this->filterMethod as $fid => $filterMethod) {
                                call_user_func($filterMethod, $newRecord);
                            }
                        }

                        $records[$id] = $newRecord;
                    }
                }
            } else {
                $this->noOfRecords = 0;
            }
        } else {
            $records = ["error" => "No database connection or ORM specified"];
        }


        return $records;
    }

    /**
     * @return string
     */
    public function generateSQLStatement(): string
    {

        $sql = "select\t" . join(",\n\t", $this->fields) . "\nfrom\t{$this->tableName} t\n";
        if (!empty($this->join) && is_array($this->join)) {
            foreach ($this->join as $join) {
                $sql .= $join[0] . " " . $join[1] . "\n";
            }
        }

        if (!empty($this->filter) && is_array($this->filter)) {
            foreach ($this->filter as $filter) {
                $sql .= "" . $filter[0] . "\t" . $filter[1] . "\n";
            }
        }

        if (!empty($this->groupBy) && is_array($this->groupBy)) {
            $sql .= "group by " . join(",", $this->groupBy) . "\n";
        }

        if (!empty($this->having) && is_array($this->having)) {
            foreach ($this->having as $filter) {
                $sql .= $filter[0] . "\t" . $filter[1] . "\n";
            }
        }

        if (!empty($this->orderBy) && is_array($this->orderBy)) {
            $sql .= "order by " . join(",", $this->orderBy) . "\n";
        }

        \Tina4\Debug::message("SQL:" . $sql);
        return $sql;
    }

    /**
     * Gets the result as a generic array without the extra object information
     * @return array
     */
    public function asArray(): array
    {
        $records = $this->jsonSerialize();
        if (isset($records["error"]) && !empty($records["error"])) return $records;
        $result = [];
        foreach ($records as $id => $record) {
            if (get_parent_class($record) === "Tina4\ORM") {
                $result[] = $record->asArray();
            } else {
                $result[] = (array)$record;
            }

        }

        return $result;
    }

    /**
     * Gets the records as a result
     * @return mixed
     */
    public function asResult()
    {
        $records = $this->jsonSerialize();
        //error_log (print_r ($this->error,1));
        if (!empty($this->error) && $this->error->getError()["errorCode"] == 0) {
            $this->error = null;
        }
        return (new DataResult($records, null, $this->noOfRecords, $this->offset, $this->error));
    }
}