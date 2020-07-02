<?php

namespace Tina4;

class SQL implements \JsonSerializable
{
    public $ORM;
    public $fields;
    public $tableName;
    public $join;
    public $filter;
    public $groupBy;
    public $having;
    public $orderBy;
    public $nextAnd="join";
    public $limit;
    public $offset;
    public $hasOne;
    public $noOfRecords;
    public $error;
    public $lastSQL;

    public function __construct($ORM)
    {
        $this->ORM = clone $ORM;
    }

    function translateFields ($fields) {
        $result = [];
        foreach ($fields as $id => $field) {
            $result[] = $this->ORM->getFieldName ($field);
        }
        return $result;
    }

    public function select($fields="*", $limit=10, $offset=0, $hasOne=[])
    {
        if (is_array($fields)) {
            $this->fields[] = $fields;
        } else {

            $this->fields = explode(",", $fields);

        }
        $this->limit = $limit;
        $this->offset = $offset;
        $this->hasOne = $hasOne;
        return $this;
    }

    function from($tableName) {
        $this->tableName = $tableName;
        return $this;
    }

    function where ($filter) {
        if (trim($filter) !== "") {
            $this->nextAnd = "where";
            $this->filter[] = ["where", $filter];
        }
        return $this;
    }

    function and ($filter) {
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

    function or ($filter) {
        $this->filter[] = ["or", $filter];
        return $this;
    }

    function join ($tableName) {
        $this->nextAnd = "join";
        $this->join[] = ["join", $tableName];
        return $this;
    }

    function leftJoin ($tableName) {
        $this->nextAnd = "join";
        $this->join[] = ["left join", $tableName];
        return $this;
    }

    function on ($filter) {
        $this->join[] = ["on", $filter];
        return $this;
    }

    function groupBy ($fields) {
        if (is_array($fields)) {
            $this->groupBy = $fields;
        } else {
            $this->groupBy = explode(",", $fields);
        }

        $this->groupBy = $this->translateFields($this->groupBy);
        return $this;
    }

    function having ($filter) {
        $this->nextAnd = "having";
        $this->having[] = ["having", $filter];
        return $this;
    }

    function orderBy ($fields) {
        if (!empty($fields)) {
            if (is_array($fields)) {
                $this->orderBy = $fields;
            } else {

                $this->orderBy = explode(",", $fields);
            }
            $this->orderBy = $this->translateFields($this->orderBy);
        }


        return $this;
    }

    function generateSQLStatement() {
        //see if we have some foreign key references
        if (!empty($this->hasOne)) {
            foreach ($this->hasOne as $foreignKey) {
                $this->fields[] = $foreignKey->getDisplayFieldQuery();
            }
        }

        $sql = "select\t".join(",\n\t", $this->fields)."\nfrom\t{$this->tableName} t\n";
        if (!empty($this->join) && is_array($this->join)) {
            foreach ($this->join as $join) {
                $sql .= $join[0]. " ".$join[1]."\n";
            }
        }

        if (!empty($this->filter) && is_array($this->filter)) {
            foreach ($this->filter as $filter) {
                if (!empty($this->hasOne)) {
                    foreach ($this->hasOne as $foreignKey) {
                        $filter[1] = str_replace( $foreignKey->getFieldName(),  $foreignKey->getSubSelect(), $filter[1]);
                    }
                }
                $sql .= "".$filter[0]. "\t".$filter[1]."\n";
            }
        }

        if (!empty($this->groupBy) && is_array($this->groupBy)) {
            $sql .= "group by ".join (",", $this->groupBy)."\n";
        }

        if (!empty($this->having) && is_array($this->having)) {
            foreach ($this->having as $filter) {
                $sql .= $filter[0]. "\t".$filter[1]."\n";
            }
        }

        if (!empty($this->orderBy) && is_array($this->orderBy)) {
            $sql .= "order by ".join (",", $this->orderBy)."\n";
        }


        return $sql;
    }

    /**
     * Makes a neat JSON response
     */
    public function jsonSerialize() {
        //run the query
        $sqlStatement = $this->generateSQLStatement();
        if (!empty($this->ORM) && !empty($this->ORM->DBA)) {
            $result = $this->ORM->DBA->fetch ($sqlStatement, $this->limit, $this->offset);
            $this->noOfRecords = $result->getNoOfRecords();
            $records = [];
            //transform the records into an array of the ORM

            $this->lastSQL = $sqlStatement;
            $this->error = $this->ORM->DBA->error();


            if (!empty($result->records()) && $this->noOfRecords > 0) {
                $records = $result->AsObject();
            } else {
                $this->noOfRecords = 0;
            }
        } else {
            $records = ["error" => "No database connection or ORM specified"];
        }

        return $records;
    }

    /**
     * Gets an array of objects
     * @return array|mixed
     */
    public function asObject() {
        return $this->jsonSerialize();
    }

    /**
     * Gets the result as a generic array without the extra object information
     * @return array
     */
    public function asArray() {
        $records = $this->jsonSerialize();
        $result = [];
        foreach ($records as $id => $record) {
            $result[] = $record->getTableData();
        }
        return $result;
    }

    /**
     * Gets the records as a result
     * @return mixed
     */
    public function asResult() {
        $records = $this->jsonSerialize();
        //error_log (print_r ($this->error,1));
        if (!empty($this->error) && $this->error->getError()["errorCode"] == 0) {
            $this->error = null;
        }
        return (new DataResult($records, null, $this->noOfRecords, $this->offset, $this->error));
    }


}
