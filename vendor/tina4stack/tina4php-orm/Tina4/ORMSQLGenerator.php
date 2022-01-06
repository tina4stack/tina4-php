<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * All the ORM SQL functions
 */
class ORMSQLGenerator
{
    /**
     * Generate SQL for creating a table
     * @param array $tableData
     * @param string $tableName
     * @param $orm
     * @return string
     * @throws \ReflectionException
     */
    final public function generateCreateSQL(array $tableData, string $tableName = "", $orm): string
    {
        $className = get_class($orm);
        $fields = [];

        foreach ($tableData as $fieldName => $fieldValue) {
            //@todo fix
            if (!property_exists($className, $orm->getObjectName($fieldName, true))) {
                continue;
            }

            $property = new \ReflectionProperty($className, $orm->getObjectName($fieldName, true));

            preg_match_all('#@(.*?)(\r\n|\n)#s', $property->getDocComment(), $annotations);

            if (!empty($annotations[1])) {
                $fieldInfo = explode(" ", $annotations[1][0], 2);
            } else {
                $fieldInfo = [];
            }

            if (empty($fieldInfo[1])) {
                if ($fieldName === "id") {
                    $fieldInfo[1] = "integer not null";
                } else {
                    $fieldInfo[1] = "varchar(1000)";
                }
            }

            $fields[] = "\t" . strtolower($orm->getObjectName($fieldName)) . " " . $fieldInfo[1];
        }

        $fields[] = "   primary key (" . $orm->primaryKey . ")";

        return "create table {$tableName} (\n" . implode(",\n", $fields) . "\n)";
    }

    /**
     * Generates an insert statement
     * @param array $tableData Array of table data
     * @param string $tableName Name of the table
     * @param $orm
     * @return array Generated insert query
     * @throws \Exception Error on failure
     */
    final public function generateInsertSQL(array $tableData, string $tableName, $orm): array
    {
        $insertColumns = [];

        $insertValues = [];

        $fieldValues = [];

        $returningStatement = "";

        $keyInFieldList = false;

        $fieldIndex = 0;

        foreach ($tableData as $fieldName => $fieldValue) {
            if (is_null($fieldValue) && $fieldValue !== 0) {
                continue;
            }

            if ($fieldName === "form_token") {
                continue;
            } //form token is reserved

            if (in_array($orm->getObjectName($fieldName, true), $orm->virtualFields, true) || in_array($fieldName, $orm->virtualFields, true) || in_array($fieldName, $orm->readOnlyFields, true)) {
                continue;
            }

            $fieldIndex++;

            $insertColumns[] = $orm->getFieldName($fieldName,$orm->fieldMapping);

            if (is_array($orm->primaryKey)) {
                if (strtoupper($orm->getFieldName($fieldName, $orm->fieldMapping)) === strtoupper($orm->getFieldName(join(",", $orm->primaryKey), $orm->fieldMapping))) {
                    $keyInFieldList = true;
                }
            } else {
                if (strtoupper($orm->getFieldName($fieldName, $orm->fieldMapping)) === strtoupper($orm->getFieldName($orm->primaryKey, $orm->fieldMapping))) {
                    $keyInFieldList = true;
                }
            }

            if (is_null($fieldValue)) {
                $fieldValue = "null";
            }

            if ($fieldValue === "null" || (is_numeric($fieldValue) && !gettype($fieldValue) === "string")) {
                $insertValues[] = $orm->DBA->getQueryParam($orm->getFieldName($fieldName,$orm->fieldMapping), $fieldIndex);
                $fieldValues[] = $fieldValue;
            } else {
                if ($orm->isDate($fieldValue, $orm->DBA->dateFormat)) {
                    $fieldValue = $orm->formatDate($fieldValue, $orm->DBA->dateFormat, $orm->DBA->getDefaultDatabaseDateFormat());
                }

                $insertValues[] = $orm->DBA->getQueryParam($orm->getFieldName($fieldName,$orm->fieldMapping), $fieldIndex);

                $fieldValues[] = $fieldValue;
            }
        }

        //Create a new primary key because we are not using a generator or auto increment
        if (!$keyInFieldList && $orm->genPrimaryKey) {
            $sqlGen = "select max(" . $orm->getFieldName($orm->primaryKey,$orm->fieldMapping) . ") as new_id from {$tableName}";

            $maxResult = $orm->DBA->fetch($sqlGen)->AsObject();

            $insertColumns[] = $orm->getFieldName($orm->primaryKey,$orm->fieldMapping);

            $newId = $maxResult[0]->newId;

            $newId++;

            $orm->{$orm->primaryKey} = $newId;

            $fieldIndex++;

            $insertValues[] = $orm->DBA->getQueryParam($orm->getFieldName($orm->primaryKey,$orm->fieldMapping), $fieldIndex);

            $fieldValues[] = $newId;
        }

        if (!empty($orm->DBA) && !$keyInFieldList) {
            if (get_class($orm->DBA) === "Tina4\DataFirebird") {
                if (!is_array($orm->primaryKey)) {
                    $primaryKeys = explode(",", $orm->primaryKey);
                } else {
                    $primaryKeys = $orm->primaryKey;
                }

                foreach ($primaryKeys as $id => $primaryKey) {
                    $primaryKeys[$id] = $orm->getFieldName($primaryKey,$orm->fieldMapping);
                }
                $returningStatement = " returning " . join(",", $primaryKeys);
            } elseif (get_class($orm->DBA) === "Tina4\DataSQLite3") {
                $returningStatement = "";
            }
        }

        Debug::message("SQL:\ninsert into {$tableName} (" . join(",", $insertColumns) . ")\nvalues (" . join(",", $insertValues) . "){$returningStatement}");

        Debug::message("Field Values:\n" . print_r($fieldValues, 1), TINA4_LOG_DEBUG);

        return ["sql" => "insert into {$tableName} (" . implode(",", $insertColumns) . ")\nvalues (" . join(",", $insertValues) . "){$returningStatement}", "fieldValues" => $fieldValues];
    }

    /**
     * Generates an update statement
     * @param array $tableData Array of table data
     * @param string $filter The criteria of what you are searching for to update e.g. "id = 2"
     * @param string $tableName Name of the table
     * @param $orm
     * @return array Generated update query
     */
    final public function generateUpdateSQL(array $tableData, string $filter, string $tableName, $orm): array
    {
        $fieldValues = [];
        $updateValues = [];

        Debug::message("Table Data:\n" . print_r($tableData, 1), TINA4_LOG_DEBUG);

        $fieldIndex = 0;
        foreach ($tableData as $fieldName => $fieldValue) {
            if ($fieldName === "form_token") {
                continue;
            } //form token is reserved

            if ($orm->primaryKey === $fieldName) {
                continue;
            }

            if (in_array($orm->getObjectName($fieldName, true), $orm->virtualFields, true) || in_array($fieldName, $orm->virtualFields, true) || in_array($fieldName, $orm->readOnlyFields, true)) {
                continue;
            }

            if (is_null($fieldValue)) {
                //$updateValues[] = "{$fieldName} = null";
                continue;
            }

            $fieldIndex++;
            if ((strlen($fieldValue) > 1 && isset($fieldValue[0]) && $fieldValue[0] !== "0") && (is_numeric($fieldValue) && !gettype($fieldValue) === "string")) {
                $updateValues[] = "{$fieldName} = " . $orm->DBA->getQueryParam($fieldName, $fieldIndex);

                $fieldValues[] = $fieldValue;
            } else {
                if ($orm->isDate($fieldValue, $orm->DBA->dateFormat)) {
                    $fieldValue = $orm->formatDate($fieldValue, $orm->DBA->dateFormat, $orm->DBA->getDefaultDatabaseDateFormat());
                }

                $updateValues[] = "{$fieldName} = " . $orm->DBA->getQueryParam($fieldName, $fieldIndex);

                $fieldValues[] = $fieldValue;
            }
        }

        Debug::message("SQL:\nupdate {$tableName} set " . join(",", $updateValues) . " where {$filter}", TINA4_LOG_DEBUG);
        Debug::message("Field Values:\n" . print_r($fieldValues, 1), TINA4_LOG_DEBUG);

        return ["sql" => "update {$tableName} set " . join(",", $updateValues) . " where {$filter}", "fieldValues" => $fieldValues];
    }

    /**
     * Generates a delete statement
     * @param string $filter The criteria of what you are searching for to delete e.g. "id = 2"
     * @param string $tableName The name of the table
     * @return string Containing deletion query
     */
    final public function generateDeleteSQL(string $filter, string $tableName = ""): string
    {
        return "delete from {$tableName} where {$filter}";
    }
}