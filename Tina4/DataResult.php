<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/03/01
 * Time: 04:30 PM
 * Note: A result from the data is a collection of records which can be in an OBJECT form or Key Value form
 */

namespace Tina4;

use JsonSerializable;

/**
 * Class DataResult Result of query
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
    public $fields;

    /**
     * @var integer Number of records
     */
    public $noOfRecords;

    /**
     * @var integer Data row offset
     */
    public $offSet;

    /**
     * @var DataError Database error
     */
    public $error;

    /**
     * DataResult constructor.
     * @param resource $records records returned from query
     * @param array $fields Fields in the table and their types
     * @param integer $noOfRecords Number of records
     * @param integer $offSet Which row to start recording
     * @param DataError $error Database error
     */
    function __construct($records, $fields, $noOfRecords, $offSet = 0, DataError $error = null)
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
    function record($id)
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
    function getNoOfRecords()
    {
        return $this->noOfRecords;
    }

    /**
     * Returns the fields and their types
     * @return mixed
     */
    function fields()
    {
        return $this->fields;
    }

    /**
     * Gets an array of objects
     * @param boolean $original Original field names
     * @return array|mixed
     */
    public function asObject($original = false)
    {
        return $this->records($original);
    }

    /**
     * Converts returned results as array of objects
     * @param boolean $original Original field name
     * @return array|null
     * @example examples\exampleDataResultRecords.php
     */
    function records($original = false)
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
    public function asOriginal()
    {
        return $this->records(true);
    }

    /**
     * Gets the result as a generic array without the extra object information
     * @param boolean $original Original field names
     * @return array
     */
    public function asArray($original = false)
    {
        //$records = $this->jsonSerialize();
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
     * @return false|string
     */
    function __toString()
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
    function getError()
    {
        if (!empty($this->error)) {
            return $this->error->getError();
        } else {
            return null;
        }
    }

}
