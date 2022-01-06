<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

use function PHPUnit\Framework\throwException;

/**
 * A very simple ORM for reading and writing data to a database or just for a simple NO SQL solution
 * @package Tina4
 */
class ORM implements \JsonSerializable
{
    use ORMUtility;
    use DataUtility;

    /**
     * @var string The primary key fields in the table, can have more than one separated by comma e.g. store_id,company_id
     */
    public $primaryKey = "id"; //Comma separated fields used for primary key

    /**
     * @var string  A filter for the table being referenced e.g. company_id = 10
     */
    public $tableFilter = ""; //May be used to filter table records for quicker updating

    /**
     * @var string defines the global name for the database handle
     */
    public $databaseGlobal = null;

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
    public $fieldMapping = [];

    /**
     * @var array An array of fields that do not belong to the table and should not be considered in an update or insert when saving
     */
    public $virtualFields = [];

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
     * @var array $hasOne
     */
    public $hasOne = [];

    /**
     * @var array $hasMany
     */
    public $hasMany = [];

    /**
     * @var int $hasManyLimit Amount of records to return for has Many
     */
    public $hasManyLimit = 100;

    /**
     * @var string[] Fields that do not need to be returned in the resulting ORM object serialization
     */
    public $protectedFields = ["databaseGlobal", "primaryKey", "genPrimaryKey", "PHPSESSID", "phpsessid", "virtualFields", "tableFilter", "DBA", "tableName", "fieldMapping", "protectedFields", "hasOne", "hasMany", "hasManyLimit", "excludeFields", "readOnlyFields", "filterMethod", "softDelete"];

    /**
     * ORM constructor.
     * @param null $request A JSON input to populate the object\
     * @param boolean $fromDB True or false - is data from the database
     * @param string $tableName The name of the table that this object maps in the database
     * @param string $fieldMapping Mapping for fields in the array form ["field" => "table_field"]
     * @param string $primaryKey The primary key of the table
     * @param string $tableFilter A filter to limit the records when the data is fetched into the class
     * @param DataBase $DBA A relevant database connection to fetch information from
     * @throws \Exception Error on failure
     * @example examples/exampleORMObject.php Create class object extending ORM
     */
    final public function __construct($request = null, bool $fromDB = false, string $tableName = "", $fieldMapping = [], string $primaryKey = "", string $tableFilter = "", $DBA = null)
    {
        $this->create($request, $fromDB, $tableName, $fieldMapping, $primaryKey, $tableFilter, $DBA);
    }

    /**
     * Allows ORM to be created
     * @param mixed $request
     * @param boolean $fromDB True or false - is data from the database
     * @param string $tableName
     * @param string $fieldMapping
     * @param string $primaryKey
     * @param string $tableFilter
     * @param null $DBA
     * @throws \Exception
     */
    final public function create($request = null, bool $fromDB = false, string $tableName = "", array $fieldMapping = [], string $primaryKey = "", string $tableFilter = "", $DBA = null): void
    {
        if (!empty($request) && !is_object($request) && !is_array($request) && !json_decode($request, true)) {
            throw new \Exception("Input is not an array or object");
        }

        if (!empty($tableName)) {
            $this->tableName = $tableName;
        } else {
            $this->tableName = $this->getTableName($tableName);
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

        if (!empty($this->databaseGlobal) && isset($GLOBALS[$this->databaseGlobal])) {
            $this->DBA = $GLOBALS[$this->databaseGlobal];
        }

        if (empty($this->DBA)) {
            global $DBA;

            if (!empty($DBA)) {
                $this->DBA = $DBA;
            }
        }

        if ($request) {
            if (!is_array($request) && !is_object($request) && json_decode((string)$request, true)) {
                $request = json_decode((string)$request, true);

                foreach ($request as $key => $value) {
                    if (!property_exists($this, $key)) { //Add to virtual and excluded fields anything that isn't in the object
                        $this->virtualFields[] = $key;
                        $this->excludeFields[] = $key;
                    }
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
                        $this->excludeFields[] = $key;
                        $this->virtualFields[] = $key;
                    }
                }
            }
        }
    }

    /**
     * Saves a file into the database
     * @param $fieldName
     * @param $fileInputName
     * @return bool
     */
    final public function saveFile(string $fieldName, string $fileInputName): bool
    {
        $tableName = $this->getTableName();

        $tableData = $this->getTableData();

        if (!empty($_FILES) && isset($_FILES[$fileInputName])) {
            if ($_FILES[$fileInputName]["error"] === 0) {
                $primaryCheck = $this->getPrimaryCheck($tableData);

                $fieldName = $this->getFieldName($fieldName);

                $sql = "update {$tableName} set {$fieldName} = ? where {$primaryCheck}";

                $this->DBA->exec($sql, file_get_contents($_FILES[$fileInputName]["tmp_name"]));

                $this->DBA->commit();
            } else {
                \Tina4\Debug::message("File error occurred\n" . print_r($_FILES[$fileInputName], 1));

                return false;
            }
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
    final public function getTableName(string $tableName = ""): string
    {
        if (empty($tableName) && empty($this->tableName)) {
            $className = explode("\\", get_class($this));
            $className = $className[count($className) - 1];

            return strtolower($this->getFieldName($className));
        }

        return $this->tableName;
    }

    /**
     * Gets the field mapping in the database eg -> lastName maps to last_name
     * @param string $name Name of field required
     * @param array $fieldMapping Array of field mapping
     * @param bool $ignoreMapping Ignore the field mapping
     * @return string Required field name from database
     */
    final public function getFieldName(string $name, array $fieldMapping = [], bool $ignoreMapping = false): ?string
    {
        if (strpos($name, "_")) {
            return $name;
        }

        if (!empty($fieldMapping) && isset($fieldMapping[$name]) && !$ignoreMapping) {

            return ($fieldMapping[$name]);
        }

        $fieldName = "";

        for ($i = 0, $iMax = strlen($name); $i < $iMax; $i++) {
            if (\ctype_upper($name[$i]) && $i != 0 && $i < strlen($name) - 1 && (!\ctype_upper($name[$i - 1]) || !\ctype_upper($name[$i + 1]))) {
                $fieldName .= "_" . $name[$i];
            } else {
                $fieldName .= $name[$i];
            }
        }

        return ($fieldName);
    }

    /**
     * Gets the information to generate a DB friendly object, $fieldMapping is an array of fields mapping the object to table field names
     * e.g. ["companyId" => "company_id", "storeId" => ]
     * @param array $fieldMapping Array of field mapping
     * @param boolean $fromDB flags it so the result is ready to go back to the database
     * @return array Contains all table data
     */
    final public function getTableData(array $fieldMapping = [], bool $fromDB = false): array
    {
        $tableData = [];
        if (!empty($this->fieldMapping) && empty($fieldMapping) && $fromDB) {
            $fieldMapping = $this->fieldMapping;
        }

        if (empty($this->virtualFields)) {
            $this->virtualFields = [];
        }

        foreach ($this as $fieldName => $value) {
            if (!in_array($fieldName, $this->protectedFields, true)) {
                if (empty($this->primaryKey)) { //use first field as primary if not specified
                    $this->primaryKey = $this->getFieldName($fieldName, $fieldMapping);
                }
                if (is_array($value)) {
                    foreach ($value as $arrId => $arrayValue) {
                        if (is_object($arrayValue) && get_parent_class($arrayValue) === "Tina4\ORM") {
                            $value[$arrId] = $arrayValue->getTableData();
                        }
                    }
                    $tableData[$this->getFieldName($fieldName, $fieldMapping)] = $value;
                } else
                    if (is_object($value) && get_parent_class($value) === "Tina4\ORM") {
                        $tableData[$this->getFieldName($fieldName, $fieldMapping)] = $value->getTableData();
                    } else {
                        $tableData[$this->getFieldName($fieldName, $fieldMapping)] = $value;
                    }
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
    final public function getPrimaryCheck(array $tableData): string
    {
        if (!is_array($this->primaryKey)) {
            $primaryFields = explode(",", $this->primaryKey);
        } else {
            $primaryFields = $this->primaryKey;
        }

        $primaryFieldFilter = [];

        if (is_array($primaryFields)) {
            foreach ($primaryFields as $id => $primaryField) {
                $primaryTableField = $this->getFieldName($primaryField, $this->fieldMapping);

                if (array_key_exists($primaryTableField, $tableData)) {
                    $primaryFieldFilter[] = str_replace("= ''", "is null", "{$primaryTableField} = '" . $tableData[$primaryTableField] . "'");
                } else {
                    $primaryFieldFilter[] = "{$primaryTableField} is null";
                }
            }
        }

        return implode(" and ", $primaryFieldFilter);
    }

    /**
     * Saves binary content to a field in the table
     * @param $fieldName
     * @param $content
     * @return bool
     */
    final public function saveBlob(string $fieldName, string $content): bool
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
     * @param string $tableName Name of the table if default not wanted
     * @param array $fieldMapping Array of field mapping if defaults not used
     * @return object Result set
     * @throws \Exception Error on failure
     * @example examples\exampleORMGenerateInsertSQL.php For insert of database row
     * @example examples\exampleORMGenerateUpdateSQL.php For update of database row
     * @example examples\exampleORMCreateTriggerUsingGenerateUpdateSQL.php For creating an external database trigger
     */
    final public function save(string $tableName = "", array $fieldMapping = [])
    {
        if (empty($tableName)) {
            $tableName = $this->tableName;
        }

        if (!$this->checkDBConnection($tableName)) {
            throw new \Exception("No table found, please see migrations or create table using generate create SQL");
        }

        if (!empty($fieldMapping) && empty($this->fieldMapping)) {
            $this->fieldMapping = $fieldMapping;
        }

        $tableData = $this->getTableData($this->fieldMapping, false);

        $primaryCheck = $this->getPrimaryCheck($tableData);

        //See if the record exists already using the primary key
        $sqlCheck = "select * from {$tableName} where {$primaryCheck}";

        //See if we can get the fetch from the cached data
        $key = "orm" . md5($sqlCheck);

        (new ORMCache())->set($key, null, 0);

        if (defined("TINA4_DEBUG") && TINA4_DEBUG) {
            Debug::message("TINA4: check " . $sqlCheck, TINA4_LOG_DEBUG);
        }

        $exists = $this->DBA->fetch($sqlCheck, 1);

        $getLastId = false;

        //@todo this next piece needs to standardize the errors from the different database sources - perhaps with a getNoneError on the database abstraction
        if ($exists->error->getErrorMessage() === "" || strtolower($exists->error->getErrorMessage()) === "none" || $exists->error->getErrorMessage() === "no more rows available" || $exists->error->getErrorMessage() === "unknown error") {
            if ($exists->noOfRecords === 0) { //insert
                if (is_array($this->primaryKey) || strpos($this->primaryKey, ",") !== false) {
                    $getLastId = false;
                }    else {
                    $getLastId = ((string)($this->{$this->primaryKey}) === "");
                }
                $sqlStatement = (new ORMSQLGenerator())->generateInsertSQL($tableData, $tableName, $this);
            } else {  //update
                $sqlStatement = (new ORMSQLGenerator())->generateUpdateSQL($tableData, $primaryCheck, $tableName, $this);
            }

            $params = [];

            $params[] = $sqlStatement["sql"];

            $params = array_merge($params, $sqlStatement["fieldValues"]);

            // Return can be a DataResult or a DataError. We need to pickup the difference
            $returning = call_user_func([$this->DBA, "exec"], ...$params);

            if ($returning instanceof \Tina4\DataError) {
                $error = $returning;
            } else {
                $error = $returning->error;
            }

            if (empty($error->getErrorMessage()) || $error->getErrorMessage() === "not an error") {
                $this->DBA->commit();

                //get last id
                if ($getLastId) {
                    $lastId = $this->DBA->getLastId();

                    if (!empty($lastId)) {
                        $this->{$this->primaryKey} = $lastId;
                    } elseif (method_exists($returning, "records") && !empty($returning->records())) {
                        $record = $returning->asObject()[0];

                        $primaryFieldName = ($this->getObjectName($this->primaryKey));

                        if (isset($record->{$primaryFieldName}) && $record->{$primaryFieldName} !== "") {
                            $this->{$this->primaryKey} = $record->{$primaryFieldName}; //@todo test on other database engines (Firebird works)
                        }
                    }
                }

                $tableData = $this->getTableData();

                $primaryCheck = $this->getPrimaryCheck($tableData);

                $sqlFetch = "select * from {$tableName} where {$primaryCheck}";

                $fetchData = $this->DBA->fetchOne($sqlFetch, 1, 0, $fieldMapping);

                if (!empty($fetchData)) {
                    $this->mapFromRecord($fetchData->asArray(), false);

                    return $this;
                } else {
                    return null;
                }
            } else {
                //$this->getDebugBackTrace();

                throw new \Exception("Error:\n" . print_r($error->getError(), 1));
            }
        } else {
            //$this->getDebugBackTrace();

            throw new \Exception("Error:\n" . print_r($exists->error, 1));
        }
    }

    /**
     * Checks if there is a database connection assigned to the object
     * If the object is not empty $DBA is instantiated
     * @param string $tableName
     * @return bool
     * @throws \ReflectionException
     */
    final public function checkDBConnection(string $tableName = ""): bool
    {

        if (empty($this->DBA)) {
            global $DBA;

            $this->DBA = $DBA;
        }

        if (empty($this->DBA)) {
            return false;
        }

        if ($this->DBA->isNoSQL())
        {
            return true; //NoSQL databases do not need table to exist
        }

        //Check to see if the table exists
        if (!$this->DBA->tableExists($tableName)) {
            $sqlCreate = $this->generateCreateSQL($tableName);

            if (defined("TINA4_DEBUG") && TINA4_DEBUG) {
                Debug::message("TINA4: We need to make a table for " . $tableName . "\n" . $sqlCreate, TINA4_LOG_DEBUG);
                //Make a migration for it
                if (class_exists("Tina4\Migration")) {
                    $migrate = (new Migration());
                    $migrate->createMigration(" create table {$tableName}", $sqlCreate, true);
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Maps a result set or database load to the ORM object using the field mappings
     * @param $record
     * @param bool $overRide Overrides existing entries
     */
    final public function mapFromRecord($record, bool $overRide = false): void
    {
        $databaseFields = [];

        foreach ($record as $fieldName => $fieldValue) {
            $ormField = $this->getObjectName($fieldName);

            //We ignore mapping because we want to use this to determine the virtual fields in the class
            $databaseFields[] = $this->getFieldName($ormField, [], true);

            if ($overRide) {
                $this->{$ormField} = $fieldValue;
            } elseif (property_exists($this, $ormField)) {
                    if ($this->{$ormField} === null && $this->{$ormField} !== "0" && $this->{$ormField} !== "") {
                        $this->{$ormField} = $fieldValue;
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
    final public function getFieldNames(): array
    {
        $fields = [];
        foreach ($this as $fieldName => $value) {
            if (!in_array($fieldName, $this->protectedFields, true)) {
                $fields[] = $this->getFieldName($fieldName);
            }
        }

        return $fields;
    }

    /**
     * Get back an object
     * @return object
     */
    final public function asObject()
    {
        return (object)$this->jsonSerialize(true);
    }

    /**
     * Makes a neat JSON response
     * @param bool $isObject
     * @return array
     */
    public function jsonSerialize(bool $isObject = false): array
    {
        return $this->getObjectData($isObject);
    }

    /**
     * Gets back the object data from the ORM object without the additional protected bits
     * @param bool $isObject
     * @return array
     */
    final public function getObjectData(bool $isObject = false): array
    {
        //See if we have exclude fields for parsing
        if (!empty($this->excludeFields) && is_string($this->excludeFields)) {
            $this->excludeFields = explode(",", $this->excludeFields);
        }

        $tableData = [];
        foreach ($this as $fieldName => $value) {
            if (!in_array($fieldName, $this->protectedFields, true) && !in_array($fieldName, $this->excludeFields, true)) {
                if (is_object($value)) {
                    if (get_parent_class(get_class($value)) === "Tina4\ORM") {
                        if ($isObject) {
                            $value = $value->asObject();
                        } else {
                            $value = $value->asArray();
                        }
                    }
                } elseif (is_array($value)) {
                    foreach ($value as $vid => $vvalue) {
                        if (is_object($vvalue) && get_parent_class(get_class($vvalue)) === "Tina4\ORM") {
                            if ($isObject) {
                                $value[$vid] = $vvalue->asObject();
                            } else {
                                $value[$vid] = $vvalue->asArray();
                            }
                        }
                    }
                }
                $tableData[$fieldName] = $value;
            }
        }
        return $tableData;
    }

    /**
     * Deletes the record from the database
     * @param string $filter The criteria of what you are searching for to delete e.g. "id = 2"
     * @param string $tableName Name of the table
     * @param array $fieldMapping Array of field mapping
     * @return object
     * @throws \Exception Error on failure
     */
    final public function delete(string $filter = "", string $tableName = "", array $fieldMapping = [])
    {
        if (empty($tableName)) {
            $tableName = $this->tableName;
        }

        if (!$this->checkDBConnection($tableName)) {
            return false;
        }

        $tableData = $this->getTableData($fieldMapping, true);
        if (empty($filter)) {
            $filter = $this->getPrimaryCheck($tableData);
        }

        $sqlStatement = (new ORMSQLGenerator())->generateDeleteSQL($filter, $tableName);

        if (!$this->softDelete) {
            $error = $this->DBA->exec($sqlStatement);
        } else {
            $sql = "select * from {$tableName} where {$filter}";
            $record = $this->DBA->fetchOne($sql)->asArray();
            if (!isset($record->isDeleted)) {
                $sql = "alter table {$tableName} add is_deleted integer default 0";
                $this->DBA->exec($sql);
                $this->DBA->commit();
                $sql = "update {$tableName} set is_deleted = 0";
                $this->DBA->exec($sql);
            }

            $sql = "update {$tableName} set is_deleted = 1 where {$filter}";
            $error = $this->DBA->exec ($sql);
        }

        if (empty($error->getError()["errorCode"])) {
            $this->DBA->commit();
            return (object)["success" => true];
        } else {
            return (object)$error->getError();
        }
    }

    /**
     * Gets the field definitions for the table
     * @return array
     */
    final public function getFieldDefinitions() : array
    {
        $tableName = strtolower($this->getTableName());

        if ($this->DBA !== null) {
            return $this->DBA->getDatabase()[$tableName];
        }

        return [];
    }

    /**
     * Excludes fields based on a json object or record
     * @param $request
     */
    final public function exclude($request): void
    {
        if ($request) {
            if (!is_array($request) && !is_object($request) && json_decode((string)$request)) {
                $request = json_decode((string)$request, true);
                foreach ($request as $key => $value) {
                    if ($key === $this->primaryKey) {
                        continue;
                    }
                    unset($this->{$key});
                }
            } else {
                foreach ($request as $key => $value) {
                    if ($key === $this->primaryKey) {
                        continue;
                    }
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
    final public function find($filter = "", $tableName = "", $fieldMapping = [])
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
    final public function load($filter = "", $tableName = "", $fieldMapping = [])
    {
        if (!empty($fieldMapping) && empty($this->fieldMapping)) {
            $this->fieldMapping = $fieldMapping;
        }

        if (empty($tableName)) {
            $tableName = $this->tableName;
        }

        if (!$this->checkDBConnection($tableName)) {
            return false;
        }

        if (!empty($filter)) {
            $sqlStatement = "select * from {$tableName} where {$filter}";
        } else {
            $tableData = $this->getTableData($fieldMapping, true);
            $primaryCheck = $this->getPrimaryCheck($tableData);
            $sqlStatement = "select * from {$tableName} where {$primaryCheck}";
        }

        //See if we can get the fetch from the cached data

        $key = "orm" . md5($sqlStatement);

        if ($cacheData = (new ORMCache())->get($key)) {
            Debug::message("Loaded {$sqlStatement} from cache", TINA4_LOG_DEBUG);
            $fetchData = $cacheData;
        } else {
            Debug::message("Creating {$sqlStatement} into cache", TINA4_LOG_DEBUG);
            $fetchData = $this->DBA->fetch($sqlStatement, 1, 0, $fieldMapping)->asObject();
            (new ORMCache())->set($key, $fetchData);
        }

        if (!empty($fetchData)) {
            //Get the first record
            $fetchData = $fetchData[0];
            $this->mapFromRecord($fetchData);

            //load up the has one
            if (!empty($this->hasOne())) {
                $this->loadHasOne();
            }

            //load up the has many
            if (!empty($this->hasMany())) {
                $this->loadHasMany();
            }

            return $this;
        }

        return false;
    }

    /**
     * Implements a relationship in the following form:
     * The link field is tied directly to whatever is indicated as the primary key in the external table
     * [["linkField" => "ORM Object"], ["linkField" => "ORM Object"], .....]
     * @return array
     */
    final public function hasOne(): array
    {
        return $this->hasOne;
    }

    /**
     * Loads all the references to the table with a one to one relationship
     */
    final public function loadHasOne(): void
    {
        foreach ($this->hasOne() as $id => $item) {
            foreach ($item as $className => $foreignKey) {
                $class = new $className();
                $primaryKey = $this->getFieldName($class->primaryKey);
                $records = $class->select("*", $this->hasManyLimit)->where("{$primaryKey} = {$this->{$foreignKey}}");
                $className = strtolower($className);
                $this->readOnlyFields[] = $className;
                $this->{$className} = $records->asObject()[0];
            }
        }
    }

    /**
     * Implements a relationship in the following form:
     * The link field is tied directly to whatever is indicated as the primary key
     * [["ORM OBJECT" => "linkField"], ["ORM OBJECT" => "linkField"], .... ]
     * //Creates a variable on the ORM object of the same name as the ORM Object which is an array / result set of that object
     * @return array
     */
    final public function hasMany(): array
    {
        return $this->hasMany;
    }

    /**
     * Loads all the references to the table with a one to many relationship
     */
    final public function loadHasMany(): void
    {
        foreach ($this->hasMany() as $id => $item) {
            foreach ($item as $className => $foreignKey) {
                $class = new $className();

                if ($class->checkDBConnection()) {
                    $foreignKey = $this->getFieldName($foreignKey);
                    $records = $class->select("*", $this->hasManyLimit)->where("{$foreignKey} = '{$this->{$this->primaryKey}}'");
                    $className = $this->pluralize($className);
                    $this->readOnlyFields[] = $className;
                    $this->{$className} = $records->asObject();
                }
            }
        }
    }

    /**
     * @param $word
     * @return string
     */
    final public function pluralize($word): string
    {
        $word = strtolower($word);
        $lastLetter = strtolower($word[strlen($word) - 1]);
        switch ($lastLetter) {
            case 'y':
                return substr($word, 0, -1) . 'ies';
            case 's':
                return $word . 'es';
            default:
                return $word . 's';
        }
    }

    /**
     * Returns back a JSON string of the table structure
     * @return string
     */
    public function __toString(): string
    {
        return json_encode($this->jsonSerialize());
    }

    /**
     * Return the object as an array
     * @return array|mixed
     */
    final public function asArray(): array
    {
        return $this->jsonSerialize();
    }

    /**
     * Selects a data set of records
     * @param string $fields
     * @param int $limit
     * @param int $offset
     * @return mixed|SQL
     * @throws \ReflectionException
     */
    final public function select(string $fields = "*", int $limit = 10, int $offset = 0)
    {
        $tableName = $this->getTableName();

        if ($this->checkDBConnection($tableName)) {
            return (new SQL($this))->select($fields, $limit, $offset, $this->hasOne(), $this->hasMany())->from($tableName);
        }

        return $this;
    }

    /**
     * Gets a nice name for a column for display purposes
     * @param $name
     * @return string
     */
    final public function getTableColumnName($name) : string
    {
        $fieldName = "";
        for ($i = 0, $iMax = strlen($name); $i < $iMax; $i++) {
            if (\ctype_upper($name[$i]) && $i != 0 && $i < strlen($name) - 1 && (!\ctype_upper($name[$i - 1]) || !\ctype_upper($name[$i + 1]))) {
                $fieldName .= " " . $name[$i];
            } else {
                $fieldName .= $name[$i];
            }
        }
        return ucwords($fieldName);
    }

    /**
     * Generates the create table function
     * @param string $tableName
     * @return string
     * @throws \ReflectionException
     */
    final public function generateCreateSQL(string $tableName=""): string
    {
        if (empty($tableName)) {
            $tableName = $this->tableName;
        }
        return (new ORMSQLGenerator())->generateCreateSQL($this->getTableData(), $tableName, $this);
    }


    /**
     * Generates CRUD code
     * @param string $path
     * @param bool $return
     * @return string|null
     */
    final public function generateCRUD(string $path, bool $return=false): ?string
    {
        $result = (new ORMCRUDGenerator($this))->generateCRUD($path, $return);

        if (!$return) {
            return null;
        }

        return $result;
    }
}
