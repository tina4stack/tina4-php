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

    public function __construct($ORM)
    {
        $this->ORM = clone $ORM;
    }

    public function select($fields="*", $limit=10)
    {
        if (is_array($fields)) {
            $this->fields = $fields;
        } else {
            $this->fields = explode(",", $fields);
        }
        $this->limit = $limit;
        return $this;
    }

    function from($tableName) {
        $this->tableName = $tableName;
        return $this;
    }

    function where ($filter) {
        $this->nextAnd = "where";
        $this->filter[] = ["where", $filter];
        return $this;
    }

    function and ($filter) {
        if ($this->nextAnd == "join") {
            $this->join[] = ["and", $filter];
        }else if ($this->nextAnd == "having") {
            $this->having[] = ["and", $filter];
        }
        else {
            $this->filter[] = ["and", $filter];
        }
        return $this;
    }

    function or ($filter) {
        $this->filter[] = ["or", $filter];
        return $this;
    }

    function join ($tableName) {
        $this->nextAnd = "join";
        $this->join[] = ["join", tableName];
        return $this;
    }

    function leftJoin ($tableName) {
        $this->nextAnd = "join";
        $this->join[] = ["left join", tableName];
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
        return $this;
    }

    function having ($filter) {
        $this->nextAnd = "having";
        $this->having[] = ["having", $filter];
        return $this;
    }

    function orderBy ($fields) {
        if (is_array($fields)) {
            $this->orderBy = $fields;
        } else {
            $this->orderBy = explode(",", $fields);
        }
        return $this;
    }

    function generateSQLStatement() {
        $sql = "select\t".join(",\n\t", $this->fields)."\nfrom\t{$this->tableName}\n";
        if (!empty($this->join) && is_array($this->join)) {
            foreach ($this->join as $join) {
                $sql .= $join[0]. " ".$join[1];
            }
        }

        if (!empty($this->filter) && is_array($this->filter)) {
            foreach ($this->filter as $filter) {
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

        return $sql;
    }

    /**
     * Makes a neat JSON response
     */
    public function jsonSerialize() {
        //run the query
        $sqlStatement = $this->generateSQLStatement();
        if (!empty($this->ORM) && !empty($this->ORM->DBA)) {
            $result = $this->ORM->DBA->fetch ($sqlStatement, $this->limit)->records();
            $records = [];
            //transform the records into an array of the ORM
            foreach ($result as $id => $data) {
                $record = clone $this->ORM;
                $record->create($data);
                $record->id = $id;
                $records[] = $record;
            }
        } else {
            $records = ["error" => "No database connection or ORM specified"];
        }


        return $records;
    }


}