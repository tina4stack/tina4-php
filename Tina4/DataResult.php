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
    private $records;

    /**
     * @var array Fields in the table and their types
     */
    private $fields;

    /**
     * @var integer Number of records
     */
    private $noOfRecords;

    /**
     * @var integer Data row offset
     */
    private $offSet;

    /**
     * @var DataError Database error
     */
    private $error;

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
     * Returns the fields and their types
     * @return mixed
     */
    function fields()
    {
        return $this->fields;
    }

    /**
     * Converts returned results as array of objects
     * @return array|null
     * @example examples\exampleDataResultRecords.php
     */
    function records()
    {
        $results = null;
        if (!empty($this->records)) {
            foreach ($this->records as $rid => $record) {
                $results[] = $record->asObject();
            }
        }

        return $results;
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
                    $results [] = (object) $record;
                }
            }
        }

        if (!empty($results)) {
            return json_encode((object)["recordsTotal" => $this->noOfRecords, "recordsFiltered" => $this->noOfRecords, "data" => $results, "error" => null]);
        } else {
            return json_encode((object)["recordsTotal" => 0, "recordsFiltered" => 0, "data" => [], "error" => $this->error->getErrorText()]);
        }

    }

    /**
     * Makes a neat JSON response
     */
    public function jsonSerialize() {
        $results = [];

        if (!empty($this->records)) {
            foreach ($this->records as $rid => $record) {
                if (get_class($record) == "Tina4\DataRecord") {
                    $results[] = $record->asObject();
                } else {
                    $results [] = (object) $record;
                }
            }
        }

        return (object)["recordsTotal" => $this->noOfRecords, "recordsFiltered" => $this->noOfRecords, "data" => $results, "error" => null];
    }

    /**
     * Gets the error from the result if the query failed
     * @return mixed
     */
    function getError()
    {
        return $this->error->getError();
    }

}
