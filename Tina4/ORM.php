<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2018/03/19
 * Time: 13:40
 */
namespace Tina4;


/**
 * Class ORM
 * A very simple ORM for reading and writing data to a database or just for a simple NO SQL solution
 * @package Tina4
 */
class ORM
{
    /**
     * @var string The primary key fields in the table, can have more than one separated by comma e.g. store_id,company_id
     */
    public $primaryKey = ""; //Comma separated fields used for primary key
    /**
     * @var string  A filter for the table being referenced e.g. company_id = 10
     */
    public $tableFilter = ""; //May be used to filter table records for quicker updating
    /**
     * @var string A specific table to reference if the class name is not the name of the table
     */
    public $tableName = ""; //Used to set the table name for an object that doesn't look like the table in question

    /**
     * @var null The database connection, if it is null it will look for global $DBA variable
     */
    public $DBA = null; //Specify a database connection

    /**
     * @var array A map of fields from the table to the object
     */
    public $fieldMapping = null;

    /**
     * ORM constructor.
     * @param null $request A JSON input to populate the object
     * @param string $tableName The name of the table that this object maps in the database
     * @param string $tableFilter A filter to limit the records when the data is fetched into the class
     * @param string $fieldMapping Mapping for fields in the array form ["field" => "table_field"]
     * @param DataBase A relevant database connection to fetch information from
     * @throws \Exception
     */
    function __construct($request=null, $tableName="", $tableFilter="", $fieldMapping="", $DBA=null)
    {
        if (!empty($request) && !is_object($request) && !is_array($request)) {
            throw new \Exception("Input is not an array or object");
        }

        if (!empty($tableName)) {
            $this->tableName = $tableName;
        }

        if (!empty($tableFilter)) {
            $this->tableFilter = $tableFilter;
        }

        if (!empty($DBA)) {
            $this->DBA = $DBA;
        }

        if ($request) {
            foreach ($request as $key => $value) {
                if (property_exists($this, $key )) {
                    $this->{$key} = $value;
                } else {
                    throw new \Exception("{$key} does not exist for ".get_class($this));
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
        if (property_exists($this, $name)) return $name;

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
        if (property_exists($this, $name)) return $name;
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
    function generateInsertSQL($tableData, $tableName="") {
        $this->checkDBConnection();
        $tableName = $this->getTableName ($tableName);
        $insertColumns = [];
        $insertValues = [];
        $returningStatement = "";
        foreach ($tableData as $fieldName => $fieldValue) {
            $insertColumns[] = $fieldName;
            if ($fieldName === "id" ) {


                if (!empty($this->DBA)) {
                    if (get_class($this->DBA) === "Tina4\DataFirebird") {
                        $returningStatement = " returning (id)";
                    } else if (get_class($this->DBA) === "Tina4\DataSQLite3")    {
                        $returningStatement = "";
                    }
                }

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
     * @param $filter
     * @param $tableName
     * @return string
     */
    function generateUpdateSQL ($tableData, $filter, $tableName="") {
        $tableName = $this->getTableName ($tableName);
        $updateValues = [];
        foreach ($tableData as $fieldName => $fieldValue) {
            if (is_null($fieldValue)) $fieldValue = "null";
            if ($fieldValue === "null" || is_numeric($fieldValue && $fieldValue[0] !== "0") ) {
                $updateValues[] = "{$fieldName} = {$fieldValue}";
            } else {
                $updateValues[] = "{$fieldName} = '{$fieldValue}'";
            }
        }
        $sqlUpdate = "update {$tableName} set ".join(",", $updateValues)." where {$filter}";

        return $sqlUpdate;
    }

    /**
     * Generates a delete statement
     * @param $filter
     * @param $tableName
     * @return string
     */
    function generateDeleteSQL ($filter, $tableName="") {
        $tableName = $this->getTableName ($tableName);
        $sqlDelete = "delete from {$tableName} where {$filter}";
        return $sqlDelete;
    }

    /**
     * Gets the information to generate a REST friendly object, $fieldMapping is an array of fields mapping the object to table field names
     * e.g. ["companyId" => "company_id", "storeId" => ]
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

    function getRecords ($limit=10, $offset=0) {
        $this->checkDBConnection();

    }

    function checkDBConnection() {
        if (empty($this->DBA)) {
            global $DBA;
            $this->DBA = $DBA;
        }

        if (empty($this->DBA)) {
            throw new Exception("No database connection assigned to the object");
        }
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

    /**
     * Works out the table name from the class name
     * @param $tableName
     * @return string|null
     */
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
    function save($DBA=null, $tableName="", $fieldMapping=[]) {
        $this->checkDBConnection();

        $tableName = $this->getTableName ($tableName);
        $tableData = $this->getTableData($fieldMapping);
        $primaryCheck = $this->getPrimaryCheck($tableData);

        //See if the record exists already using the primary key

        $sqlCheck = "select * from {$tableName} where {$primaryCheck}";
        if (TINA4_DEBUG) {
            error_log("TINA4: check " . $sqlCheck);
        }
        $exists = json_decode($DBA->fetch($sqlCheck, 1)."");


        if (empty($exists->data)) { //insert
            $sqlStatement = $this->generateInsertSQL($tableData, $tableName);
        } else {  //update
            $sqlStatement = $this->generateUpdateSQL($tableData, $primaryCheck, $tableName);
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
    function load($tableName= "", $fieldMapping=[], $filter="") {
        $this->checkDBConnection();

        $tableName = $this->getTableName ($tableName);

        if (!empty($filter)) {
            $sqlStatement = "select * from {$tableName} where {$filter}";
        } else {
            $tableData = $this->getTableData($fieldMapping);
            $primaryCheck = $this->getPrimaryCheck($tableData);
            $sqlStatement = "select * from {$tableName} where {$primaryCheck}";
        }

        $fetchData = json_decode($this->DBA->fetch($sqlStatement, 1) . "");

        if (!empty($fetchData->data)) {
            $fetchData = $fetchData->data[0];
            foreach ($fetchData as $fieldName => $fieldValue) {
                $propertyName = self::getObjectName($fieldName, $fieldMapping);
                if (property_exists($this, $propertyName ) && empty($this->{$propertyName})) {
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
    function delete($tableName="", $fieldMapping="") {
        $this->checkDBConnection();

        $tableName = $this->getTableName ($tableName);

        $tableData = $this->getTableData($fieldMapping);
        $primaryCheck = $this->getPrimaryCheck($tableData);

        $sqlStatement = $this->generateDeleteSQL($primaryCheck, $tableName);

        $error = $this->DBA->exec ($sqlStatement);

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
    function find($filter="", $tableName= "", $fieldMapping=[]) {
        //Translate filter
        $data = $this->getTableData($fieldMapping);

        foreach ($data as $fieldName => $value) {
            $objectName = $this->getObjectName($fieldName);


            $filter = str_replace($objectName, $fieldName, $filter);
        }

        return $this->load($tableName, $fieldMapping, $filter);
    }

    /**
     * Returns back a JSON string of the table structure
     * @return false|string
     */
    function __toString()
    {
        // TODO: Implement __toString() method.
        return json_encode($this->getTableData());
    }


}
