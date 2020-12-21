<?php

namespace Tina4;
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Class DataReference
 * Data references are supposed to help link / join tables together to the ORM
 * @package Tina4
 */
class DataReference
{
    private $fieldName;
    private $tableName;
    private $foreignKey;
    private $displayField;

    /**
     * DataReference constructor for linking ORM
     * @param $fieldName
     * @param $tableName
     * @param $foreignKey
     * @param $displayField
     */
    function __construct($fieldName, $tableName, $foreignKey, $displayField)
    {
        $this->fieldName = $fieldName;
        $this->tableName = $tableName;
        $this->foreignKey = $foreignKey;
        $this->displayField = $displayField;
    }

    function getDisplayFieldQuery()
    {
        return "{$this->getSubSelect()} as {$this->getFieldName()}";
    }

    function getSubSelect()
    {
        return "(select {$this->displayField} from {$this->tableName} where {$this->foreignKey} = t.{$this->fieldName})";
    }

    function getFieldName()
    {
        return "{$this->tableName}_{$this->displayField}";
    }
}