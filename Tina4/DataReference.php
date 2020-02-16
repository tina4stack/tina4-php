<?php


namespace Tina4;


class DataReference
{
    private $fieldName;
    private $tableName;
    private $foreignKey;
    private $displayField;

    function __construct($fieldName, $tableName, $foreignKey, $displayField)
    {
        $this->fieldName = $fieldName;
        $this->tableName = $tableName;
        $this->foreignKey = $foreignKey;
        $this->displayField = $displayField;
    }

    function getDisplayFieldQuery ()
    {
        return "(select {$this->displayField} from {$this->tableName} where {$this->foreignKey} = t.{$this->fieldName}) as {$this->tableName}_{$this->displayField}";
    }
}