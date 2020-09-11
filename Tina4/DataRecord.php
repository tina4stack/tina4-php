<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/03/01
 * Time: 04:35 PM
 * Notes: A record is a single part of the result set
 */
namespace Tina4;
use JsonSerializable;

/**
 * Class DataRecord
 * @package Tina4
 */
class DataRecord implements JsonSerializable
{
    private $original;
    private $fieldMapping;

    /**
     * This tests a string result from the DB to see if it is binary or not so it gets base64 encoded on the result
     * @param $string
     * @return bool
     */
    public function isBinary($string)
    {
        $isBinary=false;
        $string=str_ireplace("\t","",$string);
        $string=str_ireplace("\n","",$string);
        $string=str_ireplace("\r","",$string);
        if(is_string($string) && ctype_print($string) === false){
            $isBinary=true;
        }
        return $isBinary;
    }

    /**
     * Gets a proper object name for returning back data
     * @param string $name Improper object name
     * @param array $fieldMapping Field mapping to map fields
     * @return string Proper object name
     */
    function getObjectName($name, $fieldMapping=[])
    {
        if (!empty($this->fieldMapping) && empty($fieldMapping)) {
            $fieldMapping = $this->fieldMapping;
        }
        $fieldMapping = array_flip($fieldMapping);

        if (!empty($fieldMapping) && isset($fieldMapping[$name])) {
            return $fieldMapping[$name];
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
                //We have a field which is all uppercase
                if (strtoupper($name) === $name || strtolower($name) === $name) {
                    return strtolower($name);
                }
                for ($i = 0; $i < strlen($name); $i++) {
                    if ($name[$i] !== strtolower($name[$i])) {
                        $fieldName .= "_" . strtolower($name[$i]);
                    } else {
                        $fieldName .= $name[$i];
                    }
                }
            }
            return $fieldName;
        }
    }


    /**
     * DataRecord constructor Converts array to object
     * @param array $record Array of records
     * @param array $fieldMapping Array of field mapping
     */
    function __construct($record=null, $fieldMapping=[])
    {
        if (!empty($fieldMapping)) {
            $this->fieldMapping = $fieldMapping;
        }

        if (!empty($record)) {
            $this->original = (object)$record;
            foreach ($record as $column => $value) {
                if ($this->isBinary($value)) {
                    $value = \base64_encode($value);
                    $this->original->{$column} = $value;
                }
                $this->{$column} = $value;
            }
        }
    }

    /**
     * Transform to a camel case result
     */
    function transformObject () {
        $object = (object)[];
        foreach ($this->original as $column => $value) {
            $columnName = $this->getObjectName($column);
            $object->$columnName = $value;
        }

       return $object;
    }

    /**
     * Converts array to object
     * @param bool $original Whether to get the result as original field names
     * @return object
     */
    function asObject ($original=false) {
        if ($original) {
            return $this->original;
        } else {
            return $this->transformObject();
        }
    }

    function asJSON () {
        return json_encode($this->original,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    function __toString()
    {
        return json_encode($this->original, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    function byName ($name) {
        $columnName = strtoupper($name);
        if (!empty($this->original) && !empty($this->$columnName)) {
            return $this->$columnName;
        } else {
            return false;
        }
    }

    public function jsonSerialize()
    {
        return json_encode($this->original,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
}
