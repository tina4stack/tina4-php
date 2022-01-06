<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

use JsonSerializable;

/**
 * DataRecord result set for ORM and Database
 * A single data record
 * @package Tina4
 */
class DataRecord implements JsonSerializable
{
    use DataUtility;

    private $original;
    private $fieldMapping;

    /**
     * DataRecord constructor Converts array to object
     * @param array|null $record Array of records
     * @param array $fieldMapping Array of field mapping
     * @param string $databaseFormat Input format use the PHP date format conventions
     * @param string $outputFormat Output date format - use the PHP date format conventions
     */
    public function __construct(array $record = null, array $fieldMapping = [], string $databaseFormat = "Y-m-d", string $outputFormat = "Y-m-d")
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
    final public function asObject(bool $original = false): ?object
    {
        if ($original) {
            return $this->original;
        }

        return $this->transformObject();
    }

    /**
     * Transform the objects keys to camel case response
     * @param bool $original Do we want the original result returned in the object
     * @return object An object with the transformed column names
     */
    final public function transformObject(bool $original = false): object
    {
        $object = (object)[];
        if (!empty($this->original)) {
            foreach ($this->original as $column => $value) {
                $columnName = $this->getObjectName($column);
                if (!$original) {
                    $object->{$columnName} = $value;
                }
                $object->{$column} = $value;
            }
        }

        return $object;
    }

    /**
     * Gets a proper object name for returning back data
     * @param string $name Improper object name
     * @param array|null $fieldMapping Field mapping to map fields
     * @return string Proper object name
     */
    final public function getObjectName(string $name, ?array $fieldMapping = []): string
    {
        if (!empty($this->fieldMapping) && empty($fieldMapping)) {
            $fieldMapping = $this->fieldMapping;
        }
        $fieldMapping = array_flip($fieldMapping);

        if (!empty($fieldMapping) && isset($fieldMapping[$name])) {
            return $fieldMapping[$name];
        }

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

    /**
     * Cast the object to an array
     * @param bool $original Do we want the original key names passed back
     * @return array An array with transformed key names
     */
    final public function asArray(bool $original = false): array
    {
        return (array)$this->transformObject($original);
    }

    /**
     * Returns back the record as a JSON response
     * @return false|string
     * @throws \JsonException
     */
    final public function asJSON(): string
    {
        return json_encode($this->original, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Returns everything as a JSON string
     * @return string
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return json_encode($this->original, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Get the value by the field name
     * @param string $name Name of the data column
     * @return null|value Value of the named column
     */
    final public function byName(string $name): ?bool
    {
        $columnName = strtoupper($name);
        if (!empty($this->original) && !empty($this->$columnName)) {
            return $this->$columnName;
        }

        return null;
    }

    /**
     * Serialize
     * @return false|mixed|string
     * @throws \JsonException
     */
    final public function jsonSerialize(): string
    {
        return json_encode($this->original, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
