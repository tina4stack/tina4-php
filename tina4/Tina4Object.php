<?php
/**
 * Created by PhpStorm.
 * User: andre
 * Date: 2018/03/19
 * Time: 13:40
 */

class Tina4Object
{
    public $primaryKey = ""; //Comma separated fields used for primary key
    public $tableFilter = ""; //May be used to filter table records for quicker updating
    public $tableName = ""; //Used to set the table name for an object that doesn't look like the table in question
    /**
     * Tina4Object constructor.
     * @param null $request
     */
    function __construct($request=null)
    {
        if ($request) {
            foreach ($request as $key => $value) {
                if (isset($this->$key)) {
                    $this->{$key} = $value;
                }
            }
        }
    }

    /**
     * Gets the field mapping in the database
     * @param $name
     * @param $fieldMapping
     * @return string
     */
    function getFieldName($name, $fieldMapping=[]) {

        if (!empty($fieldMapping) && $fieldMapping[$name]) {
            $fieldName = $fieldMapping[$name];
            return $fieldName;
        } else {
            $fieldName = "";
            for ($i = 0; $i < strlen($name); $i++) {
                if (ctype_upper($name{$i})) {
                    $fieldName .= "_".$name{$i};
                } else {
                    $fieldName .= $name{$i};
                }
            }
            return strtolower($fieldName);
        }

    }

    /**
     * Gets a proper object name for returning back data
     * @param $name
     * @param $fieldMapping
     * @return string
     */
    function getObjectName($name, $fieldMapping=[]) {
        $name = strtolower($name);
        if (!empty($fieldMapping) && $fieldMapping[$name]) {
            $fieldName = $fieldMapping[$name];
            return $fieldName;
        } else {
            $fieldName = "";
            for ($i = 0; $i < strlen($name); $i++) {
                if ($name{$i} === "_") {
                    $i++;
                    $fieldName .= strtoupper($name{$i});
                } else {
                    $fieldName .= $name{$i};
                }
            }
            return $fieldName;
        }
    }

    /**
     * Generates an insert statement
     * @param $tableData
     * @param $tableName
     * @return string
     */
    function generateInsertSQL($tableData, $tableName) {
        $insertColumns = [];
        $insertValues = [];

        $returningStatement = "";

        foreach ($tableData as $fieldName => $fieldValue) {
            $insertColumns[] = $fieldName;
            if ($fieldName === "id") {
                $returningStatement = " returning (id)";
            }
            if (is_null($fieldValue)) $fieldValue = "null";
            if ($fieldValue === "null" || is_numeric($fieldValue) && $fieldValue[0] !== "0" ) {
                $insertValues[] = $fieldValue;
            } else {
                $insertValues[] = "'{$fieldValue}'";
            }
        }

        $sqlInsert = "insert into {$tableName} (".join(",", $insertColumns).")\nvalues (".join(",", $insertValues)."){$returningStatement}";

        return $sqlInsert;
    }

    /**
     * Generates an update statement
     * @param $tableData
     * @param $tableName
     * @param $primaryCheck
     * @return string
     */
    function generateUpdateSQL ($tableData, $tableName, $primaryCheck) {
        foreach ($tableData as $fieldName => $fieldValue) {
            if (is_null($fieldValue)) $fieldValue = "null";
            if ($fieldValue === "null" || is_numeric($fieldValue && $fieldValue[0] !== "0") ) {
                $updateValues[] = "{$fieldName} = {$fieldValue}";
            } else {
                $updateValues[] = "{$fieldName} = '{$fieldValue}'";
            }
        }
        $sqlUpdate = "update {$tableName} set ".join(",", $updateValues)." where {$primaryCheck}";

        return $sqlUpdate;
    }

    /**
     * Generates a delete statement
     * @param $tableName
     * @param $primaryCheck
     * @return string
     */
    function generateDeleteSQL ($tableName, $primaryCheck) {
        $sqlDelete = "delete from {$tableName} where {$primaryCheck}";
        return $sqlDelete;
    }

    /**
     * Helper function to get the Table data
     * @param array $fieldMapping
     * @return array
     */
    function getTableData($fieldMapping=[]) {
        $data = json_decode(json_encode ($this));
        $tableData = [];

        foreach ($data as $fieldName => $value) {
            if ($fieldName !== "primaryKey" && $fieldName != "tableFilter" && $fieldName != "tableName") {
                if (empty($this->primaryKey)) { //use first field as primary if not specified
                    $this->primaryKey = $this->getFieldName($fieldName, $fieldMapping);
                }
                $tableData[$this->getFieldName($fieldName, $fieldMapping)] = $value;
            }
        }

        return $tableData;
    }

    /**
     * Helper function to get the filter for the primary key
     * @param $tableData
     * @return string
     */
    function getPrimaryCheck($tableData) {
        $primaryFields = explode (",", $this->primaryKey);
        $primaryFieldFilter = [];
        foreach ($primaryFields as $id => $primaryField) {
            $primaryFieldFilter[] = "{$primaryField} = '".$tableData[$primaryField]."'";
        }

        $primaryCheck = join (" and ", $primaryFieldFilter);
        return $primaryCheck;
    }

    function getTableName($tableName) {
        if (empty($tableName) && empty($this->tableName)) {
            return strtolower(get_class($this));
        } else {
            if (!empty($tableName)) {
                return $tableName;
            } else if (!empty($this->tableName)) {
                return $this->tableName;
            }
        }
        return null;
    }

    /**
     * Save the data populated into the object to the provided data connection
     * @param $DBA
     * @param string $tableName
     * @param array $fieldMapping
     * @return object
     * @throws Exception
     */
    function save($DBA, $tableName="", $fieldMapping=[]) {
        $tableName = $this->getTableName ($tableName);
        $tableData = $this->getTableData($fieldMapping);
        $primaryCheck = $this->getPrimaryCheck($tableData);

        //See if the record exists already using the primary key

        $sqlCheck = "select * from {$tableName} where {$primaryCheck}";
        $exists = json_decode($DBA->fetch($sqlCheck, 1)."");

        if (empty($exists->data)) { //insert
            $sqlStatement = $this->generateInsertSQL($tableData, $tableName);
        } else {  //update
            $sqlStatement = $this->generateUpdateSQL($tableData, $tableName, $primaryCheck);
        }

        $error = $DBA->exec ($sqlStatement);

        if (empty($error->getError()["errorCode"])) {
            $DBA->commit();

            if (method_exists($error, "records") && !empty($error->records())) {
                $record = $error->record(0);
                if ($record->ID !== "") {
                    $this->id = $record->ID; //@todo test on other database engines
                    $tableData = $this->getTableData($fieldMapping);
                    $primaryCheck = $this->getPrimaryCheck($tableData);
                }
            }

            $sqlFetch = "select * from {$tableName} where {$primaryCheck}";
            $fetchData = json_decode($DBA->fetch($sqlFetch, 1) . "")->data[0];

            $tableResult = [];
            foreach ($fetchData as $fieldName => $fieldValue) {
                $tableResult[self::getObjectName($fieldName, $fieldMapping)] = $fieldValue;
            }
            return (object) $tableResult;
        } else {
            throw new Exception(print_r ($error->getError(), 1));
        }
    }

    /**
     * Loads the record from the database into the object
     * @param $DBA
     * @param string $tableName
     * @param array $fieldMapping
     * @param string $filter
     * @return bool
     */
    function load($DBA, $tableName= "", $fieldMapping=[], $filter="") {
        $tableName = $this->getTableName ($tableName);

        if (!empty($filter)) {
            $sqlStatement = "select * from {$tableName} where {$filter}";
        } else {
            $tableData = $this->getTableData($fieldMapping);
            $primaryCheck = $this->getPrimaryCheck($tableData);
            $sqlStatement = "select * from {$tableName} where {$primaryCheck}";
        }


        $fetchData = json_decode($DBA->fetch($sqlStatement, 1) . "");

        if (!empty($fetchData->data)) {
            $fetchData = $fetchData->data[0];
            foreach ($fetchData as $fieldName => $fieldValue) {
                if (property_exists($this, self::getObjectName($fieldName, $fieldMapping))) {
                    $this->{self::getObjectName($fieldName, $fieldMapping)} = $fieldValue;
                }
            }
            return true;
        } else {
            return false;
        }
    }


    /**
     * Deletes the record from the database
     * @param $DBA
     * @param string $tableName
     * @param string $fieldMapping
     * @return object
     */
    function delete($DBA, $tableName="", $fieldMapping="") {
        $tableName = $this->getTableName ($tableName);

        $tableData = $this->getTableData($fieldMapping);
        $primaryCheck = $this->getPrimaryCheck($tableData);

        $sqlStatement = $this->generateDeleteSQL($tableName, $primaryCheck);

        $error = $DBA->exec ($sqlStatement);

        if (empty($error->getError()["errorCode"])) {
            return (object) ["success" => true];
        } else {
            return (object) $error->getError();
        }

    }

    /**
     * Alias of load just with different parameter order for neatness
     * @param $DBA
     * @param string $filter
     * @param string $tableName
     * @param array $fieldMapping
     * @return bool
     */
    function find($DBA, $filter="", $tableName= "", $fieldMapping=[]) {
        //Translate filter
        $data = $this->getTableData($fieldMapping);

        foreach ($data as $fieldName => $value) {
            $objectName = $this->getObjectName($fieldName);


            $filter = str_replace($objectName, $fieldName, $filter);
        }

        return $this->load($DBA, $tableName, $fieldMapping, $filter);
    }


}