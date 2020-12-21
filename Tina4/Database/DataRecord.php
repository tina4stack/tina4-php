<?php

namespace Tina4;

use JsonSerializable;

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Class DataRecord
 * A single data record
 * @package Tina4
 */
class DataRecord implements JsonSerializable
{
    use Utility;

    private $original;
    private $fieldMapping;

    /**
     * DataRecord constructor Converts array to object
     * @param array $record Array of records
     * @param array $fieldMapping Array of field mapping
     * @param string $databaseFormat Input format use the PHP date format conventions
     * @param string $outputFormat Output date format - use the PHP date format conventions
     */
    public function __construct($record = null, $fieldMapping = [], $databaseFormat = "Y-m-d", $outputFormat = "Y-m-d")
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

                if ($this->isDate($value, $databaseFormat)) {


                    $value = $this->formatDate($value, $databaseFormat, $outputFormat);


                    $this->original->{$column} = $value;
                }


                $this->{$column} = $value;
            }


        }
    }

    /**
     * Converts array to object
     * @param bool $original Whether to get the result as original field names
     * @return object
     */
    public function asObject($original = false)
    {
        if ($original) {
            return $this->original;
        } else {
            return $this->transformObject();
        }
    }

    /**
     * Transform to a camel case result
     */
    public function transformObject()
    {
        $object = (object)[];
        if (!empty($this->original)) {
            foreach ($this->original as $column => $value) {
                $columnName = $this->getObjectName($column);
                $object->{$column} = $value; // to be added in
                $object->{$columnName} = $value;
            }

        }
        return $object;
    }

    /**
     * Cast the object to an array
     * @return array
     */
    public function asArray()
    {
        return (array)$this->transformObject();
    }

    /**
     * Gets a proper object name for returning back data
     * @param string $name Improper object name
     * @param array $fieldMapping Field mapping to map fields
     * @return string Proper object name
     */
    public function getObjectName($name, $fieldMapping = [])
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
                for ($i = 0, $iMax = strlen($name); $i < $iMax; $i++) {
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
                for ($i = 0, $iMax = strlen($name); $i < $iMax; $i++) {
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
     * @return false|string
     */
    function asJSON()
    {
        return json_encode($this->original, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return json_encode($this->original, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Get the value by the field name
     * @param $name
     * @return false
     */
    public function byName($name)
    {
        $columnName = strtoupper($name);
        if (!empty($this->original) && !empty($this->$columnName)) {
            return $this->$columnName;
        } else {
            return false;
        }
    }

    /**
     * Serialize
     * @return false|mixed|string
     */
    public function jsonSerialize()
    {
        return json_encode($this->original, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
