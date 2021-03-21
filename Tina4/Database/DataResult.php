<?php

namespace Tina4;

use JsonSerializable;

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Class DataResult Result of query
 *
 * @package Tina4
 */
class DataResult implements JsonSerializable
{
    /**
     * @var resource Records returned from query
     */
    public $records;

    /**
     * @var array Fields in the table and their types
     */
    public ?array $fields;

    /**
     * @var integer Number of records
     */
    public int $noOfRecords;

    /**
     * @var integer Data row offset
     */
    public int $offSet;

    /**
     * @var DataError Database error
     */
    public ?DataError $error;

    /**
     * DataResult constructor.
     * @param resource $records records returned from query
     * @param array $fields Fields in the table and their types
     * @param integer $noOfRecords Number of records
     * @param integer $offSet Which row to start recording
     * @param DataError $error Database error
     */
    public function __construct($records, $fields, $noOfRecords, $offSet = 0, DataError $error = null)
    {
        $this->records = $records;
        $this->fields = $fields;
        $this->noOfRecords = $noOfRecords;
        $this->offSet = $offSet;
        $this->error = $error;
    }

    /**
     * Returns back a certain record
     * @param $id
     * @return mixed
     */
    public function record($id): ?object
    {
        if (!empty($this->records)) {
            return $this->records[$id];
        } else {
            return null;
        }
    }

    /**
     * Gets back the number of records that were not filtered out by the pagination
     * @return int
     */
    public function getNoOfRecords(): int
    {
        return $this->noOfRecords;
    }

    /**
     * Returns the fields and their types
     * @return mixed
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Gets an array of objects
     * @param boolean $original Original field names
     * @return array|mixed
     */
    public function asObject($original = false): ?array
    {
        return $this->records($original);
    }

    /**
     * Converts returned results as array of objects
     * @param boolean $original Original field name
     * @return array|null
     * @example examples\exampleDataResultRecords.php
     */
    public function records($original = false): ?array
    {
        $results = [];
        if (!empty($this->records)) {
            foreach ($this->records as $rid => $record) {
                $results[] = $record->asObject($original);
            }
        }

        return $results;
    }

    /**
     * Gets an array of objects in the original form
     * @return array|mixed
     */
    public function asOriginal(): ?array
    {
        return $this->records(true);
    }

    /**
     * Gets the result as a generic array without the extra object information
     * @param boolean $original Original field names
     * @return array
     */
    public function asArray($original = false): array
    {
        $result = [];
        if (!empty($this->records)) {
            foreach ($this->records() as $id => $record) {
                $result[] = (array)$record;
            }
        }
        return $result;
    }

    /**
     * Converts array of records to array of objects
     * @return string
     */
    public function __toString(): string
    {
        $results = [];

        if (!empty($this->records)) {
            foreach ($this->records as $rid => $record) {
                if (get_class($record) == "Tina4\DataRecord") {
                    $results[] = $record->asObject();
                } else {
                    $results [] = (object)$record;
                }
            }
        }

        if (!empty($results)) {
            return json_encode((object)["recordsTotal" => $this->noOfRecords, "recordsFiltered" => $this->noOfRecords, "fields" => $this->fields, "data" => $results, "error" => null]);
        } else {
            return json_encode((object)["recordsTotal" => 0, "recordsFiltered" => 0, "fields" => [], "data" => [], "error" => $this->error->getErrorText()]);
        }

    }

    /**
     * Makes a neat JSON response
     */
    public function jsonSerialize()
    {
        $results = [];

        if (!empty($this->records)) {
            foreach ($this->records as $rid => $record) {
                if (get_class($record) == "Tina4\DataRecord") {
                    $results[] = $record->asObject();
                } else {
                    $results [] = (object)$record;
                }
            }
        }

        return (object)["recordsTotal" => $this->noOfRecords, "recordsFiltered" => $this->noOfRecords, "fields" => $this->fields, "data" => $results, "error" => $this->getError()];
    }

    /**
     * Gets the error from the result if the query failed
     * @return mixed
     */
    public function getError()
    {
        if (!empty($this->error)) {
            return $this->error->getError();
        } else {
            return null;
        }
    }

}
