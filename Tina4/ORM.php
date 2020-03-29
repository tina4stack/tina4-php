<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2018/03/19
 * Time: 13:40
 */

namespace Tina4;

use Exception;
use JsonSerializable;

/**
 * Class ORM
 * A very simple ORM for reading and writing data to a database or just for a simple NO SQL solution
 * @package Tina4
 */
class ORM implements JsonSerializable
{
    /**
     * @var string The primary key fields in the table, can have more than one separated by comma e.g. store_id,company_id
     */
    public $primaryKey = "id"; //Comma separated fields used for primary key
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
     * @example examples\exampleORMDBAConnection.php
     */
    public $DBA = null; //Specify a database connection

    /**
     * @var array A map of fields from the table to the object
     */
    public $fieldMapping = null;

    /**
     * @var array An array of fields that do not belong to the table and should not be considered in an update or insert when saving
     */
    public $virtualFields = null;

    /**
     * ORM constructor.
     * @param null $request A JSON input to populate the object\
     * @param boolean $fromDB True or false - is data from the database
     * @param string $tableName The name of the table that this object maps in the database
     * @param string $fieldMapping Mapping for fields in the array form ["field" => "table_field"]
     * @param string $primaryKey The primary key of the table
     * @param string $tableFilter A filter to limit the records when the data is fetched into the class
     * @param DataBase $DBA A relevant database connection to fetch information from
     * @throws Exception Error on failure
     * @example examples/exampleORMObject.php Create class object extending ORM
     */
    function __construct($request = null, $fromDB=false, $tableName = "",  $fieldMapping = "", $primaryKey = "", $tableFilter = "", $DBA = null)
    {
        $this->create ($request, $fromDB, $tableName, $fieldMapping, $primaryKey, $tableFilter, $DBA);
    }

    /**
     * Allows ORM to be created
     * @param null $request
     * @param boolean $fromDB True or false - is data from the database
     * @param string $tableName
     * @param string $fieldMapping
     * @param string $primaryKey
     * @param string $tableFilter
     * @param null $DBA
     * @throws Exception
     */
    function create($request = null, $fromDB=false, $tableName = "", $fieldMapping = "", $primaryKey = "", $tableFilter = "", $DBA = null) {
        if (!empty($request) && !is_object($request) && !is_array($request) && !json_decode($request)) {
            throw new Exception("Input is not an array or object");
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

        if (empty($this->DBA)) {
            global $DBA;
            if (!empty($DBA)) {
                $this->DBA = $DBA;
            }
        }


        if ($request) {
            if (!is_array($request) && !is_object($request) && json_decode((string)$request)) {
                $request = json_decode((string)$request);
                foreach ($request as $key => $value) {
                    $this->{$key} = $value;
                }
            } else {
                foreach ($request as $key => $value) {
                    if ($fromDB) { //map fields like first_name to firstName
                        $key = $this->getObjectName($key, $fromDB);
                    }

                    if (property_exists($this, $key)) {
                        $this->{$key} = $value;
                    } else {
                        $this->{$key} = $value;
                        $this->virtualFields[] = $key;
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
    function getFieldName($name, $fieldMapping = [])
    {
        if (!empty($fieldMapping) && $fieldMapping[$name]) {
            return $fieldMapping[$name];
        } else {
            if (property_exists($this, $name)) return $name;
            $fieldName = "";
            for ($i = 0; $i < strlen($name); $i++) {
                if (strtoupper($name[$i]) && $i != 0 && ($i > 0 && $name[$i-1] !== strtoupper($name[$i-1]))) {
                    $fieldName .= "_" . $name[$i];
                } else {
                    $fieldName .= $name[$i];
                }
            }
            return strtolower($fieldName);
        }

    }

    /**
     * Gets a proper object name for returning back data
     * @param string $name Improper object name
     * @param boolean $dbResult Is this a result set from the database?
     * @return string Proper object name
     */
    function getObjectName($name, $dbResult=false)
    {
        if (!empty($this->fieldMapping) && $this->fieldMapping[$name]) {
            return $this->fieldMapping[$name];
        } else {
            $fieldName = "";
            if (strpos($name, "_") !== false) {
                $name = strtolower($name);
                for ($i = 0; $i < strlen($name); $i++) {
                    if ($name[$i] === "_") {
                        $i++;
                        $fieldName .= strtoupper($name[$i]);
                    } else {
                        $fieldName .= $name[$i];
                    }
                }
            } else {
                if ($dbResult) {
                    $fieldName = strtolower($name);
                } else {
                    for ($i = 0; $i < strlen($name); $i++) {
                        if ($name[$i] !== strtolower($name[$i]) && ($i > 0 && $name[$i-1] !== strtoupper($name[$i-1]))) {
                            $fieldName .= "_" . strtolower($name[$i]);
                        } else {
                            $fieldName .= $name[$i];
                        }
                    }
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
     * @throws Exception Error on failure
     */
    function generateInsertSQL($tableData, $tableName = "")
    {
        $this->checkDBConnection();
        $tableName = $this->getTableName($tableName);
        $insertColumns = [];
        $insertValues = [];
        $returningStatement = "";

        foreach ($this->hasOne() as $id => $hasOne) {
            $foreignField = $this->getObjectName($hasOne->getFieldName());
            unset($tableData[$foreignField]);
        }

        foreach ($tableData as $fieldName => $fieldValue) {
            if (empty($fieldValue)) continue;
            if (in_array($fieldName, $this->virtualFields)) continue;
            $insertColumns[] = $this->getObjectName($fieldName);
            if ($fieldName === "id") {
                if (!empty($this->DBA)) {
                    if (get_class($this->DBA) === "Tina4\DataFirebird") {
                        $returningStatement = " returning (id)";
                    } else if (get_class($this->DBA) === "Tina4\DataSQLite3") {
                        $returningStatement = "";
                    }
                }
            }
            if (is_null($fieldValue)) $fieldValue = "null";

            if ($fieldValue === "null" || is_numeric($fieldValue) && !gettype($fieldValue) === "string") {
                $insertValues[] = $fieldValue;
            } else {
                $fieldValue = str_replace("'", "''", $fieldValue);
                $insertValues[] = "'{$fieldValue}'";
            }
        }

        return "insert into {$tableName} (" . join(",", $insertColumns) . ")\nvalues (" . join(",", $insertValues) . "){$returningStatement}";
    }

    /**
     * Generates an update statement
     * @param array $tableData Array of table data
     * @param string $filter The criteria of what you are searching for to update e.g. "id = 2"
     * @param string $tableName Name of the table
     * @return string Generated update query
     */
    function generateUpdateSQL($tableData, $filter, $tableName = "")
    {
        $tableName = $this->getTableName($tableName);
        $updateValues = [];

        foreach ($this->hasOne() as $id => $hasOne) {
            $foreignField = $this->getObjectName($hasOne->getFieldName());
            unset($tableData[$foreignField]);
        }

        foreach ($tableData as $fieldName => $fieldValue) {
            if (in_array($fieldName, $this->virtualFields)) continue;

            $fieldName = $this->getObjectName($fieldName);

            if (is_null($fieldValue)) $fieldValue = "null";
            if ($fieldValue === "null" || is_numeric($fieldValue) && !gettype($fieldValue) === "string") {
                $updateValues[] = "{$fieldName} = {$fieldValue}";
            } else {
                $fieldValue = str_replace("'", "''", $fieldValue);
                $updateValues[] = "{$fieldName} = '{$fieldValue}'";
            }
        }

        return  "update {$tableName} set " . join(",", $updateValues) . " where {$filter}";

    }

    /**
     * Generates a delete statement
     * @param string $filter The criteria of what you are searching for to delete e.g. "id = 2"
     * @param string $tableName The name of the table
     * @return string Containing deletion query
     */
    function generateDeleteSQL($filter, $tableName = "")
    {
        $tableName = $this->getTableName($tableName);

        return "delete from {$tableName} where {$filter}";
    }

    /**
     * Gets the information to generate a REST friendly object, $fieldMapping is an array of fields mapping the object to table field names
     * e.g. ["companyId" => "company_id", "storeId" => ]
     * @param array $fieldMapping Array of field mapping
     * @return array Contains all table data
     */
    function getTableData($fieldMapping = [])
    {
        $tableData = [];
        if (!empty($this->fieldMapping) && empty($fieldMapping)) {
            $fieldMapping = $this->fieldMapping;
        }

        if (empty($this->virtualFields)) {
            $this->virtualFields = [];
        }

        $protectedFields = ["primaryKey", "virtualFields", "tableFilter", "DBA", "tableName", "fieldMapping"];
        foreach ($this as $fieldName => $value) {
            if (!in_array($fieldName, $protectedFields)) {
                if (empty($this->primaryKey)) { //use first field as primary if not specified
                    $this->primaryKey = $this->getFieldName($fieldName, $fieldMapping);
                }
                $tableData[$this->getFieldName($fieldName, $fieldMapping)] = $value;
            }
        }

        //Now we need to add the foreign keys hasMany & hasOne

        return $tableData;
    }

    /**
     * Gets records from a table using the default $DBA
     * @param integer $limit Number of rows contained in result set
     * @param integer $offset The row number of where to start receiving data
     * @return DataRecord
     * @throws Exception Error on failure
     */
    function getRecords($limit = 10, $offset = 0)
    {
        $this->checkDBConnection();
        $tableName = $this->getTableName();

        $sql = "select * from {$tableName}";
        if ($this->tableFilter) {
            $sql .= "where {$this->tableFilter}";
        }
        return $this->DBA->fetch($sql, $limit, $offset);
    }

    /**
     * Checks if there is a database connection assigned to the object
     * If the object is not empty $DBA is instantiated
     * @throws Exception If no database connection is assigned to the object an exception is thrown
     */
    function checkDBConnection()
    {
        if (empty($this->DBA)) {
            global $DBA;
            $this->DBA = $DBA;
        }

        if (empty($this->DBA)) {
           return false;
        } else {
            return true;
        }
    }

    /**
     * Helper function to get the filter for the primary key
     * @param array $tableData Array of table data
     * @return string e.g. "id = ''"
     */
    function getPrimaryCheck($tableData)
    {
        //print_r ($tableData);
        $primaryFields = explode(",", $this->primaryKey);
        $primaryFieldFilter = [];
        if (is_array($primaryFields)) {
            foreach ($primaryFields as $id => $primaryField) {
                $primaryTableField = $this->getObjectName($primaryField);
                if (key_exists($primaryField, $tableData)) {
                    $primaryFieldFilter[] = str_replace ("= ''",  "is null",  "{$primaryTableField} = '" . $tableData[$primaryField] . "'");

                } else {
                    $primaryFieldFilter[] = "{$primaryTableField} is null";
                }
            }
        }

        return join(" and ", $primaryFieldFilter);
    }

    /**
     * Works out the table name from the class name
     * @param string $tableName The class name
     * @return string|null Returns the name of the table or null if it does not fit the if statements criteria
     */
    function getTableName($tableName)
    {
        if (empty($tableName) && empty($this->tableName)) {
            $className = explode("\\", get_class($this));
            $className = $className[count($className)-1];
            return strtolower($this->getFieldName($className));
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
     * @param string $tableName Name of the table
     * @param array $fieldMapping Array of field mapping
     * @return object Result set
     * @throws Exception Error on failure
     * @example examples\exampleORMGenerateInsertSQL.php For insert of database row
     * @example examples\exampleORMGenerateUpdateSQL.php For update of database row
     * @example examples\exampleORMCreateTriggerUsingGenerateUpdateSQL.php For creating an external database trigger
     */
    function save($tableName = "", $fieldMapping = [])
    {
        $this->checkDBConnection();

        $tableName = $this->getTableName($tableName);
        $tableData = $this->getTableData($fieldMapping);
        $primaryCheck = $this->getPrimaryCheck($tableData);

        //See if the record exists already using the primary key

        $sqlCheck = "select * from {$tableName} where {$primaryCheck}";
        if (TINA4_DEBUG) {
            \Tina4\DebugLog::message("TINA4: check " . $sqlCheck, TINA4_DEBUG_LEVEL);
        }

        $exists = json_decode($this->DBA->fetch($sqlCheck, 1) . "");

        if ($exists->recordsTotal == 0 || $exists->error == "") {
            if (empty($exists->data)) { //insert
                $sqlStatement = $this->generateInsertSQL($tableData, $tableName);
            } else {  //update
                $sqlStatement = $this->generateUpdateSQL($tableData, $primaryCheck, $tableName);
            }

            $error = $this->DBA->exec($sqlStatement);


            if (empty($error->getError()["errorCode"])) {
                $this->DBA->commit();

                //get last id
                $lastId = $this->DBA->getLastId();

                if (!empty($lastId)) {
                    $this->{$this->primaryKey} = $lastId;
                } else
                    if (method_exists($error, "records") && !empty($error->records())) {
                        $record = $error->record(0);
                        $primaryFieldName = strtoupper($this->getObjectName($this->primaryKey));
                        if ($record->{$primaryFieldName} !== "") {
                            $this->{$this->primaryKey} = $record->{$primaryFieldName}; //@todo test on other database engines
                        }
                    }

                $tableData = $this->getTableData();
                $primaryCheck = $this->getPrimaryCheck($tableData);


                $sqlFetch = "select * from {$tableName} where {$primaryCheck}";

                $fetchData = $this->DBA->fetch($sqlFetch, 1)->record(0);

                $tableResult = [];
                if (!empty($fetchData)) {
                    foreach ($fetchData as $fieldName => $fieldValue) {
                        $tableResult[self::getObjectName($fieldName, true)] = $fieldValue;
                    }
                }

                return (object)$tableResult;
            } else {
                throw new Exception(print_r($error->getError(), 1));
            }
        } else {
            throw new Exception(print_r($exists->error, 1));
        }
    }


    /**
     * Loads the record from the database into the object
     * @param string $filter The criteria of what you are searching for to load e.g. "id = 2"
     * @param string $tableName Name of the table
     * @param array $fieldMapping Array of field mapping for the table
     * @return ORM|bool True on success, false on failure to load
     * @throws Exception Error on failure
     * @example examples\exampleORMLoadData.php for loading table row data
     */
    function load($filter = "", $tableName = "", $fieldMapping = [])
    {
        if (!$this->checkDBConnection()) return;

        $tableName = $this->getTableName($tableName);

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
                if (property_exists($this, $propertyName) && empty($this->{$propertyName}) && $this->{$propertyName} !== "0") {
                    $this->{self::getObjectName($fieldName, $fieldMapping)} = $fieldValue;
                }
            }
            return $this;
        } else {
            return $this;
        }
    }


    /**
     * Deletes the record from the database
     * @param string $filter The criteria of what you are searching for to delete e.g. "id = 2"
     * @param string $tableName Name of the table
     * @param string $fieldMapping Array of field mapping
     * @return object
     * @throws Exception Error on failure
     */
    function delete($filter = "", $tableName = "", $fieldMapping = "")
    {
        if (!$this->checkDBConnection()) return;

        $tableName = $this->getTableName($tableName);

        $tableData = $this->getTableData($fieldMapping);
        if (empty($filter)) {
            $filter = $this->getPrimaryCheck($tableData);
        }

        $sqlStatement = $this->generateDeleteSQL($filter, $tableName);

        $error = $this->DBA->exec($sqlStatement);

        if (empty($error->getError()["errorCode"])) {
            return (object)["success" => true];
        } else {
            return (object)$error->getError();
        }

    }

    /**
     * Alias of load just with different parameter order for neatness
     * @param string $filter
     * @param string $tableName Name of the table
     * @param array $fieldMapping Array of field mapping
     * @return bool
     * @throws Exception Error on failure
     */
    function find($filter = "", $tableName = "", $fieldMapping = [])
    {
        //Translate filter
        $data = $this->getTableData($fieldMapping);

        foreach ($data as $fieldName => $value) {
            $objectName = $this->getObjectName($fieldName);
            $filter = str_replace($objectName, $fieldName, $filter);
        }

        return $this->load($filter, $tableName, $fieldMapping);
    }

    /**
     * Returns back a JSON string of the table structure
     * @return false|string
     */
    function __toString()
    {
        return json_encode($this->getTableData());
    }

    /**
     * Makes a neat JSON response
     */
    public function jsonSerialize() {
        return $this->getTableData();
    }

    /**
     * Selects a data set of records
     * @param string $fields
     * @param int $limit
     * @param int $offset
     * @return SQL
     */
    public function select($fields="*", $limit=10, $offset=0) {
        $tableName = $this->getTableName($this->tableName);
        return (new \Tina4\SQL($this))->select($fields, $limit, $offset, $this->hasOne())->from($tableName);
    }

    /**
     * Implements a relationship in the following form:
     * ["TableName" => ["foreignKey" => "primaryKey"], "TableName" => ["foreignKey" => "primaryKey"], ...]
     * @return array
     */
    public function hasMany() {
        return [];
    }

    /**
     * Implements a relationship in the following form:
     * ["TableName" => ["foreignKey" => "primaryKey"], "TableName" => ["foreignKey" => "primaryKey"], ...]
     * @return array
     */
    public function hasOne() {
        return [];
    }

    /**
     * Implements a relationship in the following form:
     * ["TableName" => ["foreignKey" => "primaryKey"], "TableName" => ["foreignKey" => "primaryKey"], ...]
     * @return array
     */
    public function belongsTo () {
        return [];
    }

    function generateCRUD($path="") {
        $className = get_class($this);

        if (empty($path)) {
            $callingCode = '(new '.$className.'())->generateCRUD();';
        } else {
            $callingCode = '(new '.$className.'())->generateCRUD("' . $path . '");';
        }

        $backTrace = debug_backtrace()[0];
        $fileName =  ($backTrace["file"]);
        $line = $backTrace["line"];


        if (empty($path)) $path = str_replace($_SERVER["DOCUMENT_ROOT"], "", str_replace (".php", "", realpath($fileName)));

            $template = <<<'EOT'
/**
 * CRUD Prototype Example
 */
\Tina4\Crud::route ("[PATH]", new [OBJECT](), function ($action, $object, $filter, $request) {
    switch ($action) {
        case "form":
            //Render a form here
            return $htmlForm;
            break;
        case "create":   
            //Return a script or html message here to reload the grid
            //Modify variables on the object
            return  "<script>grid.ajax.reload(null, false); showMessage ('Added');</script>";
            break;
        case "read":
            //Return a dataset to be consumed by the grid with a filter
            $where = "";
            if (!empty($filter["where"])) {
                $where = "and {$filter["where"]}";
            }
            
            return   $object->select ("*", $filter["length"], $filter["start"])
                            ->where("id <> 0 {$where}")
                            ->orderBy($filter["orderBy"])
                            ->asResult();
            break;
        case "update":
            return "<script>grid.ajax.reload(null, false); showMessage ('Edited');</script>";
            break;
        case "delete":
            return "<script>grid.ajax.reload(null, false); showMessage ('Deleted'); </script>";
            break;
    }
});
EOT;
        $template = str_replace("[PATH]", $path, $template);
        $template = str_replace("[OBJECT]", $className, $template);


        $content = file_get_contents($fileName);

        $content = str_replace ($callingCode, $template, $content);

        file_put_contents( $fileName, $content);
    }


}