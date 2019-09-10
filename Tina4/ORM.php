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
    function __construct($request=null, $tableName="", $fieldMapping="", $primaryKey="", $tableFilter="", $DBA=null)
    {
        if (!empty($request) && !is_object($request) && !is_array($request) && !json_decode($request)) {
            throw new \Exception("Input is not an array or object");
        }

        if (!empty($tableName)) {
            $this->tableName = $tableName;
        }

        if (!empty($primaryKey)) {
            $this->primaryKey = $primaryKey;
        }

        if (!empty($fieldMapping)) {
            $this->fieldMapping = $fieldMapping;
        }

        if (!empty($tableFilter)) {
            $this->tableFilter = $tableFilter;
        }

        if (!empty($DBA)) {
            $this->DBA = $DBA;
        }

        if ($request) {
            if (json_decode($request)) {
                $request = json_decode($request);
                foreach ($request as $key => $value) {
                        $this->{$key} = $value;
                }
            } else {
                foreach ($request as $key => $value) {
                    if (property_exists($this, $key)) {
                        $this->{$key} = $value;
                    } else {
                        throw new \Exception("{$key} does not exist for " . get_class($this));
                    }
                }
            }
        }
    }

    /**
     * Gets the field mapping in the database
     * @param string $name Name of field required
     * @param array $fieldMapping Array of field mapping
     * @return string Required field name from database
     */
    function getFieldName($name, $fieldMapping=[]) {
        if (!empty($fieldMapping) && $fieldMapping[$name]) {
            $fieldName = $fieldMapping[$name];
            return $fieldName;
        } else {
            if (property_exists($this, $name)) return $name;
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
     * @param string $name Improper object name
     * @param array $fieldMapping Array of field mapping
     * @return string Proper object name
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
     * @param array $tableData Array of table data
     * @param string $tableName Name of the table
     * @return string Generated insert query
     * @throws \Exception
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
     * @param array $tableData Array of table data
     * @param string $filter The criteria of what you are searching for to update e.g. "id = 2"
     * @param string $tableName Name of the table
     * @return string Generated update query
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
     * @param string $filter The criteria of what you are searching for to delete e.g. "id = 2"
     * @param string $tableName The name of the table
     * @return string Containing deletion query
     */
    function generateDeleteSQL ($filter, $tableName="") {
        $tableName = $this->getTableName ($tableName);
        $sqlDelete = "delete from {$tableName} where {$filter}";
        return $sqlDelete;
    }

    /**
     * Gets the information to generate a REST friendly object, $fieldMapping is an array of fields mapping the object to table field names
     * e.g. ["companyId" => "company_id", "storeId" => ]
     * @param array $fieldMapping Array of field mapping
     * @return array Contains all table data
     */
    function getTableData($fieldMapping=[]) {
        $data = json_decode(json_encode ($this));
        $tableData = [];
        if (!empty($this->fieldMapping) && empty($fieldMapping)) {
            $fieldMapping = $this->fieldMapping;
        }

        $protectedFields = ["primaryKey", "tableFilter", "DBA", "tableName", "fieldMapping"];
        foreach ($data as $fieldName => $value) {
            if (!in_array( $fieldName, $protectedFields )) {
                if (empty($this->primaryKey)) { //use first field as primary if not specified
                    $this->primaryKey = $this->getFieldName($fieldName, $fieldMapping);
                }
                $tableData[$this->getFieldName($fieldName, $fieldMapping)] = $value;
            }
        }

        return $tableData;
    }

    /**
     * Gets records from a table using the default $DBA
     * @param int $limit Number of rows contained in result set
     * @param int $offset The row number of where to start receiving data
     * @return DataRecord
     * @throws \Exception
     */
    function getRecords ($limit=10, $offset=0) {
        $this->checkDBConnection();
        $tableName = $this->getTableName();

        $sql = "select * from {$tableName}";
        if($this->tableFilter) {
            $sql .= "where {$this->tableFilter}";
        }
        return $this->DBA->fetch ($sql, $limit, $offset);
    }

    /**
     * Checks if there is a database connection assigned to the object
     * If the object is not empty $DBA is instantiated
     * @throws \Exception If no database connection is assigned to the object an exception is thrown
     */
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
     * @param array $tableData Array of table data
     * @return string e.g. "id = ''"
     */
    function getPrimaryCheck($tableData) {
        $primaryFields = explode (",", $this->primaryKey);
        $primaryFieldFilter = [];
        if (is_array($primaryFields)) {
            foreach ($primaryFields as $id => $primaryField) {
                if (key_exists($primaryField, $tableData)) {
                    $primaryFieldFilter[] = "{$primaryField} = '" . $tableData[$primaryField] . "'";
                } else {
                    $primaryFieldFilter[] = "{$primaryField} is null";
                }
            }
        }

        $primaryCheck = join (" and ", $primaryFieldFilter);
        return $primaryCheck;
    }

    /**
     * Works out the table name from the class name
     * @param string $tableName The class name
     * @return string|null Returns the name of the table or null if it does not fit the if statements criteria
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
     * @param $DBA Database connection object
     * @param string $tableName Name of the table
     * @param array $fieldMapping Array of field mapping
     * @return object Result set
     * @throws \Exception Error on failure
     */
    function save($tableName="", $fieldMapping=[]) {
        $this->checkDBConnection();

        $tableName = $this->getTableName ($tableName);
        $tableData = $this->getTableData($fieldMapping);
        $primaryCheck = $this->getPrimaryCheck($tableData);

        //See if the record exists already using the primary key

        $sqlCheck = "select * from {$tableName} where {$primaryCheck}";
        if (TINA4_DEBUG) {
            error_log("TINA4: check " . $sqlCheck);
        }
        $exists = json_decode($this->DBA->fetch($sqlCheck, 1)."");

        if ($exists->error->errorCode == 0) {
            if (empty($exists->data)) { //insert
                $sqlStatement = $this->generateInsertSQL($tableData, $tableName);
            } else {  //update
                $sqlStatement = $this->generateUpdateSQL($tableData, $primaryCheck, $tableName);
            }

            $error = $this->DBA->exec($sqlStatement);


            if (empty($error->getError()["errorCode"])) {
                $this->DBA->commit();

                if (method_exists($error, "records") && !empty($error->records())) {
                    $record = $error->record(0);
                    if ($record->ID !== "") {
                        $this->id = $record->ID; //@todo test on other database engines
                        $tableData = $this->getTableData($fieldMapping);
                        $primaryCheck = $this->getPrimaryCheck($tableData);
                    }
                }

                $sqlFetch = "select * from {$tableName} where {$primaryCheck}";
                $fetchData = json_decode($this->DBA->fetch($sqlFetch, 1) . "")->data[0];

                $tableResult = [];
                foreach ($fetchData as $fieldName => $fieldValue) {
                    $tableResult[self::getObjectName($fieldName, $fieldMapping)] = $fieldValue;
                }
                return (object)$tableResult;
            } else {
                throw new \Exception(print_r($error->getError(), 1));
            }
        } else {
            throw new \Exception(print_r($exists->error, 1));
        }
    }



    /**
     * Loads the record from the database into the object
     * @param string $tableName Name of the table
     * @param array $fieldMapping Array of field mapping for the table
     * @param string $filter The criteria of what you are searching for to load e.g. "id = 2"
     * @return bool True on success, false on failure to load
     * @throws \Exception
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
     * @param string $tableName Name of the table
     * @param string $fieldMapping Array of field mapping
     * @return object
     * @throws \Exception
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
     * @param string $filter
     * @param string $tableName Name of the table
     * @param array $fieldMapping Array of field mapping
     * @return bool
     * @throws \Exception
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
