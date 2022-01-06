<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * A way to encapsulate standard SQL into an object form
 * @package Tina4
 */
class SQL implements \JsonSerializable
{
    /**
     * @var resource Database connection handle
     */
    public $DBA;

    /**
     * @var ORM A Tina4 ORM object
     */
    public $ORM;

    /**
     * @var array Array of fields
     */
    public $fields;

    /**
     * @var array Array of result fields
     */
    public $resultFields;

    /**
     * @var string Name of the table selecting from
     */
    public $tableName;

    /**
     * @var array Join
     */
    public $join;

    /**
     * @var array Filters
     */
    public $filters;

    /**
     * @var array GroupBys
     */
    public $groupBys;

    /**
     * @var array Having clause
     */
    public $havings;

    /**
     * @var array Order bys
     */
    public $orderBys;

    /**
     * @var string Variable to store join vars
     */
    public $nextAnd = "join";

    /**
     * @var int Limit of how many records to select
     */
    public $limit;

    /**
     * @var int offset from where to select from
     */
    public $offset;

    /**
     * @var array Link to ORM object for one to one reference
     */
    public $hasOne;

    /**
     * @var array Link to one to many ORM record
     */
    public $hasMany;

    /**
     * @var int Amount of records returned
     */
    public $noOfRecords;

    /**
     * @var string last error that happened
     */
    public $error;

    /**
     * @var string the last sql that was executed
     */
    public $lastSQL;

    /**
     * @var array List of fields to exclude in the request
     */
    public $excludeFields;

    /**
     * @var array Array of filters / functions to filter / transform the data
     */
    public $filterMethods = []; //Special variable pointing to an anonymous function which takes in a record for manipulation

    /**
     * SQL constructor.
     * @param null $ORM If the ORM object is empty it will default to the global $DBA variable for a database connection
     * @param null $DBA
     */
    public function __construct($ORM = null, $DBA = null)
    {
        if (!empty($ORM)) {
            $this->ORM = clone $ORM;
            $this->DBA = $this->ORM->DBA;
        } else {
            if (!empty($DBA)) {
                $this->DBA = $DBA;
            } else {
                //See if we can db connection from global $DBA
                global $DBA;
                if (!empty($DBA)) {
                    $this->DBA = $DBA;
                }
            }
        }
    }

    /**
     * Excludes fields from the result set
     * @param $fields
     * @return SQL
     */
    public function exclude($fields): SQL
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
    public function select(string $fields = "*", int $limit = 10, int $offset = 0, array $hasOne = [], array $hasMany = []): SQL
    {
        if (is_array($fields)) {
            $this->fields[] = $fields;
        } else {
            $this->fields = array_map('trim', explode(',', $fields));
        }

        //print_r ($this->fields);
        $this->limit = $limit;
        $this->offset = $offset;
        $this->hasOne = $hasOne;
        $this->hasMany = $hasMany;
        return $this;
    }

    /**
     * @param string $tableName
     * @return $this
     */
    final public function from(string $tableName): SQL
    {
        $this->tableName = $tableName;
        return $this;
    }

    /**
     * Filter the SQL statement
     * @param string $filter A valid SQL filter
     * @return $this
     */
    final public function where(string $filter): SQL
    {
        //@todo parse filter
        if (trim($filter) !== "") {
            $this->nextAnd = "where";
            $this->filters[] = ["where", $filter];
        }
        return $this;
    }

    /**
     * And clause for SQL
     * @param string $filter A valid SQL and expression
     * @return $this
     */
    final public function and(string $filter): SQL
    {
        //@todo parse filter
        if (trim($filter) !== "") {
            if ($this->nextAnd === "join") {
                $this->join[] = ["and", $filter];
            } elseif ($this->nextAnd === "having") {
                $this->havings[] = ["and", $filter];
            } else {
                $this->filters[] = ["and", $filter];
            }
        }
        return $this;
    }

    /**
     * Or statement for SQL
     * @param string $filter A valid or filter expression
     * @return $this
     */
    final public function or(string $filter): SQL
    {
        //@todo parse filter
        $this->filters[] = ["or", $filter];
        return $this;
    }

    /**
     * An SQL join expression
     * @param string $tableName Name of the table to join on
     * @return $this
     */
    final public function join(string $tableName): SQL
    {
        $this->nextAnd = "join";
        $this->join[] = ["join", $tableName];
        return $this;
    }

    /**
     * SQL left join statement
     * @param string $tableName Name of the table
     * @return $this
     */
    final public function leftJoin(string $tableName): SQL
    {
        $this->nextAnd = "join";
        $this->join[] = ["left join", $tableName];
        return $this;
    }

    /**
     * SQL on statement
     * @param string $filter Filter for the on statement
     * @return $this
     */
    final public function on(string $filter): SQL
    {
        //@todo parse filter
        $this->join[] = ["on", $filter];

        return $this;
    }

    /**
     * Group by for SQL statement
     * @param string|array $fields
     * @return $this
     */
    final public function groupBy($fields): SQL
    {
        if (is_array($fields)) {
            $this->groupBys = $fields;
        } else {
            $this->groupBys = array_map('trim', explode(',', $fields));
        }

        $this->groupBys = $this->translateFields($this->groupBys);

        return $this;
    }

    /**
     * Translates fields from camel case to database format
     * @param array $fields
     * @return array
     */
    final public function translateFields(array $fields): array
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

    /**
     * Having clause
     * @param string $filter Filter for having clause
     * @return $this
     */
    final public function having(string $filter): SQL
    {
        $this->nextAnd = "having";
        $this->havings[] = ["having", $filter];

        return $this;
    }

    /**
     * Order by clause
     * @param array $fields
     * @return $this
     */
    final public function orderBy($fields): SQL
    {
        if (!empty($fields)) {
            if (is_array($fields)) {
                $this->orderBys = $fields;
            } else {
                $this->orderBys = array_map('trim', explode(',', $fields));
            }
            $this->orderBys = $this->translateFields($this->orderBys);
        }

        return $this;
    }

    /**
     * A method which will filter the records
     * @param  $filterMethod
     * @return SQL
     */
    final public function filter($filterMethod): SQL
    {
        if (!empty($filterMethod)) {
            $this->filterMethods[] = $filterMethod;
        }

        return $this;
    }

    /**
     * Gets an array of objects
     * @return array|mixed
     */
    final public function asObject(): array
    {
        return $this->jsonSerialize();
    }

    /**
     * Makes a neat JSON response
     */
    final public function jsonSerialize(): array
    {
        //run the query
        $sqlStatement = $this->generateSQLStatement();

        if (!empty($this->DBA)) {
            $result = $this->DBA->fetch($sqlStatement, $this->limit, $this->offset);

            $this->noOfRecords = $result->getNoOfRecords();
            $this->resultFields = $result->fields;
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

                        if (!empty($this->hasOne)) {
                            $newRecord->loadHasOne();
                        }

                        if (!empty($this->hasMany)) {
                            $newRecord->loadHasMany();
                        }

                        if (!empty($this->excludeFields)) {
                            foreach ($this->excludeFields as $eid => $excludeField) {
                                if (property_exists($newRecord, $excludeField)) {
                                    unset($newRecord->{$excludeField});
                                }
                            }
                        }


                        //Only return what was requested
                        if (!empty($this->fields) && !in_array("*", $this->getColumnNames($this->fields), true)) {
                            foreach ($newRecord as $key => $value) {
                                if (in_array($key, $newRecord->protectedFields, true)) {
                                    continue;
                                }

                                if (strpos($key, " as") === false && strpos($key, ".") === false && !in_array($key, $this->getColumnNames($this->fields), true)) {
                                    unset($newRecord->{$key});
                                }
                            }
                        }


                        //Apply a filter to the record
                        if (!empty($this->filterMethods)) {
                            foreach ($this->filterMethods as $fid => $filterMethod) {
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
     * Generates and SQL Statement
     * @return string A valid SQL statement
     */
    final public function generateSQLStatement(): string
    {

        $sql = "select\t" . implode(",\n\t", $this->fields) . "\nfrom\t{$this->tableName} t\n";
        if (!empty($this->join) && is_array($this->join)) {
            foreach ($this->join as $join) {
                $sql .= $join[0] . " " . $join[1] . "\n";
            }
        }

        if (!empty($this->filters) && is_array($this->filters)) {
            foreach ($this->filters as $filter) {
                $sql .= "" . $filter[0] . "\t" . $filter[1] . "\n";
            }
        }

        if (!empty($this->groupBys) && is_array($this->groupBys)) {
            $sql .= "group by " . implode(",", $this->groupBys) . "\n";
        }

        if (!empty($this->havings) && is_array($this->havings)) {
            foreach ($this->havings as $filter) {
                $sql .= $filter[0] . "\t" . $filter[1] . "\n";
            }
        }

        if (!empty($this->orderBys) && is_array($this->orderBys)) {
            $sql .= "order by " . implode(",", $this->orderBys) . "\n";
        }

        \Tina4\Debug::message("SQL:\n" . $sql, TINA4_LOG_DEBUG);

        return $sql;
    }

    /**
     * Cleans up column names for JsonSerializable
     * @param array $fields
     * @return array
     */
    final public function getColumnNames(array $fields): array
    {
        $columnNames = [];
        foreach ($fields as $id => $field) {
            $columnName = $field;
            $field = str_replace([".", " as", " "], "^", $field);
            if (strpos($field, "^") !== false) {
                $explodedField = explode("^", $field);
                $columnName = array_pop($explodedField);
            }
            $columnNames[] = $this->ORM->getObjectName($columnName, true);
            $columnNames[] = $columnName;
        }

        return $columnNames;
    }

    /**
     * Gets the result as a generic array without the extra object information
     * @return array
     */
    final public function asArray(): array
    {
        $records = $this->jsonSerialize();
        if (isset($records["error"]) && !empty($records["error"])) {
            return $records;
        }
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
    final public function asResult(): ?object
    {
        $records = $this->jsonSerialize();
        //error_log (print_r ($this->error,1));
        if (!empty($this->error) && $this->error->getError()["errorCode"] === 0) {
            $this->error = null;
        }

        return (new DataResult($records, $this->resultFields, $this->noOfRecords, $this->offset, $this->error));
    }
}
