<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2018/03/19
 * Time: 13:40
 */

namespace Tina4;

use Exception;

/**
 * Class ORM
 * A very simple ORM for reading and writing data to a database or just for a simple NO SQL solution
 * @package Tina4
 */
class ORM implements \JsonSerializable
{
    use Utility;
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
     * @var array An array of fields that belong to the table and should not be returned in the object
     */
    public $excludeFields = [];

    /**
     * @var array An array of fields that are readonly and should not be updated or inserted
     */
    public $readOnlyFields = [];

    /**
     * @var null A method which filters a record when it is read from the database
     */
    public $filterMethod = [];

    /**
     * @var bool $softDelete Default is up to you to handle
     */
    public $softDelete = false;

    /**
     * @var bool $genPrimaryKey Default is off because the process to generate a new key is slower use select max and could end up with race conditions, use at own risk
     */
    public $genPrimaryKey = false;

    /**
     * @var string[] Fields that do not need to be returned in the resulting ORM object serialization
     */
    public $protectedFields = ["primaryKey", "genPrimaryKey", "virtualFields", "tableFilter", "DBA", "tableName", "fieldMapping", "protectedFields", "hasOne", "hasMany", "excludeFields", "readOnlyFields", "filterMethod", "softDelete"];

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
    public function __construct($request = null, $fromDB = false, $tableName = "", $fieldMapping = "", $primaryKey = "", $tableFilter = "", $DBA = null)
    {
        $this->create($request, $fromDB, $tableName, $fieldMapping, $primaryKey, $tableFilter, $DBA);
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
    public function create($request = null, $fromDB = false, $tableName = "", $fieldMapping = "", $primaryKey = "", $tableFilter = "", $DBA = null)
    {
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
     * Gets a proper object name for returning back data
     * @param string $name Improper object name
     * @param boolean $camelCase Return the name as camel case
     * @return string Proper object name
     */
    public function getObjectName($name, $camelCase = false)
    {
        if (isset($this->fieldMapping) && !empty($this->fieldMapping)) {
            $fieldMap = array_change_key_case(array_flip($this->fieldMapping), CASE_LOWER);

            if (isset($fieldMap[$name])) {
                return $fieldMap[$name];
            }

            if (!$camelCase) {
                return $name;
            }

            return $this->camelCase($name);
        }

        if (!$camelCase) {
            return $name;
        }

        return $this->camelCase($name);
    }

    /**
     * Saves a file into the database
     * @param $fieldName
     * @param $fileInputName
     * @return bool
     */
    public function saveFile($fieldName, $fileInputName)
    {
        $tableName = $this->getTableName();
        $tableData = $this->getTableData();
        if (!empty($_FILES) && isset($_FILES[$fileInputName])) {
            $primaryCheck = $this->getPrimaryCheck($tableData);
            $fieldName = $this->getFieldName($fieldName);
            $sql = "update {$tableName} set {$fieldName} = ? where {$primaryCheck}";
            $this->DBA->exec($sql, file_get_contents($_FILES[$fileInputName]["tmp_name"]));
            $this->DBA->commit();
        } else {
            return false;
        }

        return true;
    }

    /**
     * Works out the table name from the class name
     * @param string $tableName The class name
     * @return string|null Returns the name of the table or null if it does not fit the if statements criteria
     */
    public function getTableName($tableName = "")
    {
        if (empty($tableName) && empty($this->tableName)) {
            $className = explode("\\", get_class($this));
            $className = $className[count($className) - 1];
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
     * Gets the field mapping in the database eg -> lastName maps to last_name
     * @param string $name Name of field required
     * @param array $fieldMapping Array of field mapping
     * @param bool $ignoreMapping Ignore the field mapping
     * @return string Required field name from database
     */
    public function getFieldName($name, $fieldMapping = [], $ignoreMapping = false)
    {
        if (!empty($fieldMapping) && isset($fieldMapping[$name]) && !$ignoreMapping) {
            return strtolower($fieldMapping[$name]);
        } else {
            $fieldName = "";
            for ($i = 0, $iMax = strlen($name); $i < $iMax; $i++) {
                if (\ctype_upper($name[$i]) && $i != 0 && $i < strlen($name) - 1 && (!\ctype_upper($name[$i - 1]) || !\ctype_upper($name[$i + 1]))) {
                    $fieldName .= "_" . $name[$i];
                } else {
                    $fieldName .= $name[$i];
                }
            }
            return strtolower($fieldName);
        }
    }

    /**
     * Gets the information to generate a DB friendly object, $fieldMapping is an array of fields mapping the object to table field names
     * e.g. ["companyId" => "company_id", "storeId" => ]
     * @param array $fieldMapping Array of field mapping
     * @param boolean $fromDB flags it so the result is ready to go back to the database
     * @return array Contains all table data
     */
    public function getTableData($fieldMapping = [], $fromDB = false)
    {
        $tableData = [];
        if (!empty($this->fieldMapping) && empty($fieldMapping) && $fromDB) {
            $fieldMapping = $this->fieldMapping;
        }

        if (empty($this->virtualFields)) {
            $this->virtualFields = [];
        }

        foreach ($this as $fieldName => $value) {
            if (!in_array($fieldName, $this->protectedFields)) {
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
     * Helper function to get the filter for the primary key
     * @param array $tableData Array of table data
     * @return string e.g. "id = ''"
     */
    public function getPrimaryCheck($tableData)
    {
        $primaryFields = explode(",", $this->primaryKey);
        $primaryFieldFilter = [];

        if (is_array($primaryFields)) {
            foreach ($primaryFields as $id => $primaryField) {
                $primaryTableField = $this->getFieldName($primaryField, $this->fieldMapping);
                if (key_exists($primaryTableField, $tableData)) {
                    $primaryFieldFilter[] = str_replace("= ''", "is null", "{$primaryTableField} = '" . $tableData[$primaryTableField] . "'");
                } else {
                    $primaryFieldFilter[] = "{$primaryTableField} is null";
                }
            }
        }

        return join(" and ", $primaryFieldFilter);
    }

    /**
     * Saves binary content to a field in the table
     * @param $fieldName
     * @param $content
     * @return bool
     */
    public function saveBlob($fieldName, $content)
    {
        $tableName = $this->getTableName();
        $tableData = $this->getTableData();
        if (!empty($content)) {
            $primaryCheck = $this->getPrimaryCheck($tableData);
            $fieldName = $this->getFieldName($fieldName);
            $sql = "update {$tableName} set {$fieldName} = ? where {$primaryCheck}";
            $this->DBA->exec($sql, $content);
            $this->DBA->commit();
        } else {
            return false;
        }
        return true;
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
    public function save($tableName = "", $fieldMapping = [])
    {
        if (!empty($fieldMapping) && empty($this->fieldMapping)) {
            $this->fieldMapping = $fieldMapping;
        }

        $tableName = $this->getTableName($tableName);
        $this->checkDBConnection($tableName);


        $tableData = $this->getTableData($fieldMapping, true);

        $primaryCheck = $this->getPrimaryCheck($tableData);

        //See if the record exists already using the primary key

        $sqlCheck = "select * from {$tableName} where {$primaryCheck}";
        if (defined("TINA4_DEBUG") && TINA4_DEBUG) {
            DebugLog::message("TINA4: check " . $sqlCheck, TINA4_DEBUG_LEVEL);
        }

        $exists = json_decode($this->DBA->fetch($sqlCheck, 1) . "");


        $getLastId = false;
        if ($exists->recordsTotal == 0 || $exists->error == "") {
            if (empty($exists->data)) { //insert
                //echo "Primary Key {$this->{$this->primaryKey}}";
                $getLastId = ("{$this->{$this->primaryKey}}" === "");
                $sqlStatement = $this->generateInsertSQL($tableData, $tableName);
            } else {  //update
                $sqlStatement = $this->generateUpdateSQL($tableData, $primaryCheck, $tableName);
            }

            $params = [];
            $params[] = $sqlStatement["sql"];
            $params = array_merge($params, $sqlStatement["fieldValues"]);
            $error = call_user_func_array([$this->DBA, "exec"],  $params);

            if (empty($error->getError()["errorCode"])) {
                $this->DBA->commit();

                //get last id
                if ($getLastId) {
                    $lastId = $this->DBA->getLastId();
                    if (!empty($lastId)) {
                        $this->{$this->primaryKey} = $lastId;
                    } else
                        if (method_exists($error, "records") && !empty($error->records())) {
                            $record = $error->asObject()[0];

                            $primaryFieldName = ($this->getObjectName($this->primaryKey));
                            if (isset($record->{$primaryFieldName}) && $record->{$primaryFieldName} !== "") {
                                $this->{$this->primaryKey} = $record->{$primaryFieldName}; //@todo test on other database engines
                            }
                        }

                }



                $tableData = $this->getTableData();
                $primaryCheck = $this->getPrimaryCheck($tableData);

                $sqlFetch = "select * from {$tableName} where {$primaryCheck}";

                $fetchData = $this->DBA->fetch($sqlFetch, 1, 0, $fieldMapping)->asArray();

                $this->mapFromRecord($fetchData[0], true);

                return $this->asObject();
            } else {
                throw new Exception(print_r($error->getError(), 1));
            }
        } else {
            throw new Exception(print_r($exists->error, 1));
        }
    }

    /**
     * Checks if there is a database connection assigned to the object
     * If the object is not empty $DBA is instantiated
     * @param string $tableName
     * @return bool
     * @throws \ReflectionException
     */
    public function checkDBConnection($tableName = "")
    {
        if (empty($tableName)) {
            $tableName = $this->getTableName();
        }

        if (empty($this->DBA)) {
            global $DBA;
            $this->DBA = $DBA;
        }

        if (empty($this->DBA)) {
            return false;
        } else {

            //Check to see if the table exists
            if (!$this->DBA->tableExists($tableName)) {
                if (defined("TINA4_DEBUG") && TINA4_DEBUG) {
                    DebugLog::message("TINA4: We need to make a table for " . $tableName, TINA4_DEBUG_LEVEL);
                }

                $sql = $this->generateCreateSQL($this->getTableData(), $tableName);

                //Make a migration for it
                //$migrate = (new Migration());
                //$migrate->createMigration("Create table {$tableName}", $sql);

                //$this->DBA->exec($sql);
                return false;
            }

            return true;
        }
    }

    /**
     * Generate SQL for creating a table
     * @param $tableData
     * @param string $tableName
     * @return string
     * @throws \ReflectionException
     */
    public function generateCreateSQL($tableData, $tableName = "")
    {
        $className = get_class($this);
        $tableName = $this->getTableName($tableName);
        $fields = [];


        foreach ($tableData as $fieldName => $fieldValue) {
            //@todo fix
            $property = new \ReflectionProperty($className, $this->getObjectName($fieldName, true));

            preg_match_all('#@(.*?)(\r\n|\n)#s', $property->getDocComment(), $annotations);
            if (!empty($annotations[1])) {
                $fieldInfo = explode(" ", $annotations[1][0], 2);
            } else {
                $fieldInfo = [];
            }
            if (empty($fieldInfo[1])) {
                if ($fieldName == "id") {
                    $fieldInfo[1] = "integer not null";
                } else {
                    $fieldInfo[1] = "varchar(1000)";
                }
            }
            $fields[] = "\t" . $this->getObjectName($fieldName) . " " . $fieldInfo[1];
        }

        $fields[] = "primary key (" . $this->primaryKey . ")";
        return "create table {$tableName} (\n" . join(",\n", $fields) . "\n)";
    }

    /**
     * Generates an insert statement
     * @param array $tableData Array of table data
     * @param string $tableName Name of the table
     * @return string Generated insert query
     * @throws Exception Error on failure
     */
    public function generateInsertSQL($tableData, $tableName)
    {
        $insertColumns = [];
        $insertValues = [];
        $fieldValues = [];
        $returningStatement = "";

        foreach ($this->hasOne() as $id => $hasOne) {
            $foreignField = $this->getObjectName($hasOne->getFieldName());
            unset($tableData[$foreignField]);
        }


        $keyInFieldList = false;
        foreach ($tableData as $fieldName => $fieldValue) {
            if (empty($fieldValue) && $fieldValue !== 0) {
                continue;
            }

            if ($fieldName === "form_token") {
                continue;
            } //form token is reserved

            if (in_array($this->getObjectName($fieldName, true), $this->virtualFields, true) || in_array($fieldName, $this->virtualFields, true) || in_array($fieldName, $this->readOnlyFields, true) || in_array($fieldName, $this->excludeFields, true)) {
                continue;
            }

            $insertColumns[] = $this->getFieldName($fieldName);

            if (strtoupper($this->getFieldName($fieldName)) === strtoupper($this->getFieldName($this->primaryKey))) {
                $keyInFieldList = true;
            }

            if (is_null($fieldValue)) {
                $fieldValue = "null";
            }

            if ($fieldValue === "null" || (is_numeric($fieldValue) && !gettype($fieldValue) === "string")) {
                $insertValues[] = "?";
                $fieldValues[] = $fieldValue;
            } else {
                if ($this->isDate($fieldValue, $this->DBA->dateFormat)) {
                    $fieldValue = $this->formatDate($fieldValue, $this->DBA->dateFormat, $this->DBA->getDefaultDatabaseDateFormat());
                }

                $insertValues[] = "?";
                $fieldValues[] = $fieldValue;
            }
        }

        //Create a new primary key because we are not using a generator or auto increment
        if (!$keyInFieldList && $this->genPrimaryKey) {
            $sqlGen = "select max(" . $this->getFieldName($this->primaryKey) . ") as new_id from {$tableName}";
            $maxResult = $this->DBA->fetch($sqlGen)->AsObject();
            $insertColumns[] = $this->getFieldName($this->primaryKey);
            $newId = $maxResult[0]->newId;
            $newId++;
            $this->{$this->primaryKey} = $newId;
            $insertValues[] = "?";
            $fieldValues[] = $fieldValue;
        }

        if (!empty($this->DBA) && !$keyInFieldList) {
            if (get_class($this->DBA) === "Tina4\DataFirebird") {
                $returningStatement = " returning (" . $this->getFieldName($this->primaryKey) . ")";
            } else if (get_class($this->DBA) === "Tina4\DataSQLite3") {
                $returningStatement = "";
            }
        }

        DebugLog::message("insert into {$tableName} (" . join(",", $insertColumns) . ")\nvalues (" . join(",", $insertValues) . "){$returningStatement}");
        return ["sql" => "insert into {$tableName} (" . join(",", $insertColumns) . ")\nvalues (" . join(",", $insertValues) . "){$returningStatement}", "fieldValues" => $fieldValues];
    }

    /**
     * Implements a relationship in the following form:
     * ["TableName" => ["foreignKey" => "primaryKey"], "TableName" => ["foreignKey" => "primaryKey"], ...]
     * @return array
     */
    public function hasOne()
    {
        return [];
    }

    /**
     * Generates an update statement
     * @param array $tableData Array of table data
     * @param string $filter The criteria of what you are searching for to update e.g. "id = 2"
     * @param string $tableName Name of the table
     * @return string Generated update query
     */
    public function generateUpdateSQL($tableData, $filter, $tableName)
    {
        $fieldValues = [];
        $updateValues = [];

        foreach ($this->hasOne() as $id => $hasOne) {
            $foreignField = $this->getObjectName($hasOne->getFieldName());
            unset($tableData[$foreignField]);
        }


        foreach ($tableData as $fieldName => $fieldValue) {
            if ($fieldName == "form_token") {
                continue;
            } //form token is reserved

            if (in_array($this->getObjectName($fieldName, true), $this->virtualFields, true) || in_array($fieldName, $this->virtualFields, true) || in_array($fieldName, $this->readOnlyFields, true) || in_array($fieldName, $this->excludeFields, true)) {
                continue;
            }

            if (is_null($fieldValue)) {
                $fieldValue = "null";
            }

            if ($fieldValue === "null" || (is_numeric($fieldValue) && !gettype($fieldValue) === "string")) {
                $updateValues[] = "{$fieldName} = ?";
                $fieldValues[] = $fieldValue;
            } else {
                if ($this->isDate($fieldValue, $this->DBA->dateFormat)) {
                    $fieldValue = $this->formatDate($fieldValue, $this->DBA->dateFormat, $this->DBA->getDefaultDatabaseDateFormat());
                }

                $updateValues[] = "{$fieldName} = ?";
                $fieldValues[] = $fieldValue;
            }
        }

        DebugLog::message("update {$tableName} set " . join(",", $updateValues) . " where {$filter}");
        return ["sql" => "update {$tableName} set " . join(",", $updateValues) . " where {$filter}", "fieldValues" => $fieldValues];

    }

    /**
     * Maps a result set or database load to the ORM object using the field mappings
     * @param $record
     * @param bool $overRide Overrides existing entries
     */
    function mapFromRecord($record, $overRide = false)
    {
        $databaseFields = [];
        foreach ($record as $fieldName => $fieldValue) {
            $ormField = $this->getObjectName($fieldName);

            //We ignore mapping because we want to use this to determine the virtual fields in the class
            $databaseFields[] = $this->getFieldName($ormField, null, true);

            if ($overRide) {
                $this->{$ormField} = $fieldValue;
            } else
            if (property_exists($this, $ormField)){
                if (property_exists($this, $ormField)){
                    if  ($this->{$ormField} === null && $this->{$ormField} !== "0" && $this->{$ormField} !== "") {
                        $this->{$ormField} = $fieldValue;
                    }
                }
            }
        }

        //work out the virtual fields here from the load
        $virtualFields = array_diff($this->getFieldNames(), $databaseFields);

        if (empty($this->virtualFields)) {
            $this->virtualFields = $virtualFields;
        }
    }

    /**
     * Returns back a list of field names on the ORM
     * @return array
     */
    public function getFieldNames()
    {
        $fields = [];
        foreach ($this as $fieldName => $value) {
            if (!in_array($fieldName, $this->protectedFields)) {
                $fields[] = $this->getFieldName($fieldName);
            }
        }

        return $fields;
    }

    /**
     * Get back an object
     * @return object
     */
    public function asObject()
    {
        return (object)$this->jsonSerialize();
    }

    /**
     * Makes a neat JSON response
     */
    public function jsonSerialize()
    {
        return $this->getObjectData();
    }

    /**
     * Gets back the object data from the ORM object without the additional protected bits
     * @return array
     */
    public function getObjectData()
    {
        //See if we have exclude fields for parsing
        if (!empty($this->excludeFields) && is_string($this->excludeFields)) {
            $this->excludeFields = explode(",", $this->excludeFields);
        }

        $tableData = [];
        foreach ($this as $fieldName => $value) {

            if (!in_array($fieldName, $this->protectedFields) && !in_array($fieldName, $this->excludeFields)) {
                $tableData[$fieldName] = $value;
            }
        }
        return $tableData;
    }

    /**
     * Deletes the record from the database
     * @param string $filter The criteria of what you are searching for to delete e.g. "id = 2"
     * @param string $tableName Name of the table
     * @param string $fieldMapping Array of field mapping
     * @return object
     * @throws Exception Error on failure
     */
    public function delete($filter = "", $tableName = "", $fieldMapping = "")
    {
        $tableName = $this->getTableName($tableName);
        if (!$this->checkDBConnection($tableName)) return false;

        $tableData = $this->getTableData($fieldMapping, true);
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
     * Excludes fields based on a json object or record
     * @param $request
     */
    function exclude($request)
    {
        if ($request) {
            if (!is_array($request) && !is_object($request) && json_decode((string)$request)) {
                $request = json_decode((string)$request);
                foreach ($request as $key => $value) {
                    if ($key === $this->primaryKey) continue;
                    unset($this->{$key});
                }
            } else {
                foreach ($request as $key => $value) {
                    if ($key === $this->primaryKey) continue;
                    if (property_exists($this, $key)) {
                        unset($this->{$key});
                    }
                }
            }
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

        if (!empty($fieldMapping) && empty($this->fieldMapping)) {
            $this->fieldMapping = $fieldMapping;
        }

        $tableName = $this->getTableName($tableName);
        if (!$this->checkDBConnection($tableName)) return false;

        if (!empty($filter)) {
            $sqlStatement = "select * from {$tableName} where {$filter}";
        } else {
            $tableData = $this->getTableData($fieldMapping, true);
            $primaryCheck = $this->getPrimaryCheck($tableData);
            $sqlStatement = "select * from {$tableName} where {$primaryCheck}";
        }

        $fetchData = $this->DBA->fetch($sqlStatement, 1, 0, $fieldMapping)->asObject();

        if (!empty($fetchData)) {
            //Get the first record
            $fetchData = $fetchData[0];
            $this->mapFromRecord($fetchData);
            return $this;
        } else {
            return false;
        }
    }

    /**
     * Returns back a JSON string of the table structure
     * @return false|string
     */
    function __toString()
    {
        return json_encode($this->jsonSerialize());
    }

    /**
     * Return the object as an array
     * @return array|mixed
     */
    function asArray()
    {
        return $this->jsonSerialize();
    }

    /**
     * Selects a data set of records
     * @param string $fields
     * @param int $limit
     * @param int $offset
     * @return SQL
     */
    public function select($fields = "*", $limit = 10, $offset = 0)
    {
        if ($this->checkDBConnection()) {
            $tableName = $this->getTableName($this->tableName);
            return (new SQL($this))->select($fields, $limit, $offset, $this->hasOne())->from($tableName);
        } else {
            return $this;
        }
    }

    /**
     * Implements a relationship in the following form:
     * ["TableName" => ["foreignKey" => "primaryKey"], "TableName" => ["foreignKey" => "primaryKey"], ...]
     * @return array
     */
    public function hasMany()
    {
        return [];
    }

    /**
     * Implements a relationship in the following form:
     * ["TableName" => ["foreignKey" => "primaryKey"], "TableName" => ["foreignKey" => "primaryKey"], ...]
     * @return array
     */
    public function belongsTo()
    {
        return [];
    }

    /**
     * Generates CRUD
     * @param string $path
     * @throws \Twig\Error\LoaderError
     */
    function generateCRUD($path = "")
    {
        $className = get_class($this);
        if (empty($path)) {
            $callingCode = '(new ' . $className . '())->generateCRUD();';
        } else {
            $callingCode = '(new ' . $className . '())->generateCRUD("' . $path . '");';
        }

        if (empty($path)) {
            $backtrace = debug_backtrace();
            $path = $backtrace[1]["args"][0];

            $path = str_replace(getcwd() . DIRECTORY_SEPARATOR . "src", "", $path);
            $path = str_replace(".php", "", $path);
            $path = str_replace(DIRECTORY_SEPARATOR, "/", $path);
        }


        $backTrace = debug_backtrace()[0];
        $fileName = ($backTrace["file"]);
        $line = $backTrace["line"];


        if (empty($path)) $path = str_replace($_SERVER["DOCUMENT_ROOT"], "", str_replace(".php", "", realpath($fileName)));

        $template = <<<'EOT'
/**
 * CRUD Prototype [OBJECT] Modify as needed
 * Creates  GET @ /path, /path/{id}, - fetch,form for whole or for single
            POST @ /path, /path/{id} - create & update
            DELETE @ /path/{id} - delete for single
 */
\Tina4\Crud::route ("[PATH]", new [OBJECT](), function ($action, [OBJECT] $[OBJECT_NAME], $filter, \Tina4\Request $request) {
    switch ($action) {
       case "form":
       case "fetch":
            //Return back a form to be submitted to the create
             
            if ($action == "form") {
                $title = "Add [OBJECT]";
                $savePath =  TINA4_BASE_URL . "[PATH]";
                $content = \Tina4\renderTemplate("[TEMPLATE_PATH]/form.twig", []);
            } else {
                $title = "Edit [OBJECT]";
                $savePath =  TINA4_BASE_URL . "[PATH]/".$[OBJECT_NAME]->[PRIMARY_KEY];
                $content = \Tina4\renderTemplate("[TEMPLATE_PATH]/form.twig", ["data" => $[OBJECT_NAME]]);
            }

            return \Tina4\renderTemplate("components/modalForm.twig", ["title" => $title, "onclick" => "if ( $('#[OBJECT_NAME]Form').valid() ) { saveForm('[OBJECT_NAME]Form', '" .$savePath."', 'message'); $('#formModal').modal('hide');}", "content" => $content]);
       break;
       case "read":
            //Return a dataset to be consumed by the grid with a filter
            $where = "";
            if (!empty($filter["where"])) {
                $where = "{$filter["where"]}";
            }
        
            return   $[OBJECT_NAME]->select ("*", $filter["length"], $filter["start"])
                ->where("{$where}")
                ->orderBy($filter["orderBy"])
                ->asResult();
        break;
        case "create":
            //Manipulate the $object here
        break;
        case "afterCreate":
           //return needed 
           return (object)["httpCode" => 200, "message" => "<script>[GRID_ID]Grid.ajax.reload(null, false); showMessage ('[OBJECT] Created');</script>"];
        break;
        case "update":
            //Manipulate the $object here
        break;    
        case "afterUpdate":
           //return needed 
           return (object)["httpCode" => 200, "message" => "<script>[GRID_ID]Grid.ajax.reload(null, false); showMessage ('[OBJECT] Updated');</script>"];
        break;   
        case "delete":
            //Manipulate the $object here
        break;
        case "afterDelete":
            //return needed 
            return (object)["httpCode" => 200, "message" => "<script>[GRID_ID]Grid.ajax.reload(null, false); showMessage ('[OBJECT] Deleted');</script>"];
        break;
    }
});
EOT;
        $template = str_replace("[PATH]", $path, $template);
        $template = str_replace("[OBJECT]", $className, $template);
        $template = str_replace("[OBJECT_NAME]", $this->camelCase($className), $template);
        $template = str_replace("[PRIMARY_KEY]", $this->primaryKey, $template);
        $template = str_replace("[GRID_ID]", $this->camelCase($className), $template);
        $template = str_replace("[TEMPLATE_PATH]", str_replace(DIRECTORY_SEPARATOR, "/", $path), $template);


        $content = file_get_contents($fileName);


        //create a crud grid and form
        $formData = $this->getObjectData();

        $tableColumns = [];
        $tableColumnMappings = [];
        $tableFields = [];
        foreach ($formData as $columnName => $value) {
            $tableColumns[] = $this->getTableColumnName($columnName);
            $tableColumnMappings[] = $columnName;
            $tableFields[] = ["fieldName" => $columnName, "fieldLabel" => $this->getTableColumnName($columnName)];
        }

        $componentPath = getcwd() . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . "templates" . str_replace("/", DIRECTORY_SEPARATOR, $path);

        if (!file_exists($componentPath)) {
            mkdir($componentPath, 0755, true);
        }

        $gridFilePath = $componentPath . DIRECTORY_SEPARATOR . "grid.twig";

        $formFilePath = $componentPath . DIRECTORY_SEPARATOR . "form.twig";

        //create the grid
        $gridHtml = renderTemplate("components/grid.twig", ["gridTitle" => $className, "gridId" => $this->camelCase($className), "primaryKey" => $this->primaryKey, "tableColumns" => $tableColumns, "tableColumnMappings" => $tableColumnMappings, "apiPath" => $path, "baseUrl" => TINA4_BASE_URL]);

        file_put_contents($gridFilePath, $gridHtml);

        //create the form
        $formHtml = renderTemplate("components/form.twig", ["formId" => $this->camelCase($className), "primaryKey" => $this->primaryKey, "tableFields" => $tableFields, "baseUrl" => TINA4_BASE_URL]);

        $formHtml = str_replace("&quot;", '"', $formHtml);
        file_put_contents($formFilePath, $formHtml);

        $gridRouterCode = '
\Tina4\Get::add("' . $path . '/landing", function (\Tina4\Response $response){
    return $response (\Tina4\renderTemplate("' . $path . '/grid.twig"), HTTP_OK, TEXT_HTML);
});
        ';

        $content = str_replace($callingCode, $gridRouterCode . PHP_EOL . $template, $content);

        file_put_contents($fileName, $content);

    }

    /**
     * Gets a nice name for a column for display purposes
     * @param $name
     * @return string
     */
    function getTableColumnName($name)
    {
        $fieldName = "";
        for ($i = 0; $i < strlen($name); $i++) {
            if (\ctype_upper($name[$i]) && $i != 0 && $i < strlen($name) - 1 && (!\ctype_upper($name[$i - 1]) || !\ctype_upper($name[$i + 1]))) {
                $fieldName .= " " . $name[$i];
            } else {
                $fieldName .= $name[$i];
            }
        }
        return ucwords($fieldName);
    }

}