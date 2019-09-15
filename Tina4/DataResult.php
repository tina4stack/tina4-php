<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/03/01
 * Time: 04:30 PM
 * Note: A result from the data is a collection of records which can be in an OBJECT form or Key Value form
 */
namespace Tina4;

class DataResult
{
    private $records;
    private $fields;
    private $noOfRecords;
    private $offSet;
    private $error;

    /**
     * DataResult constructor.
     * @param $records
     * @param $fields Fields in the table
     * @param integer $noOfRecords Number of records
     * @param integer $offSet Which row to start recording
     * @param DataError $error
     */
    function __construct($records, $fields, $noOfRecords, $offSet=0, DataError $error=null)
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
    function record ($id) {
        if (!empty($this->records)) {
            return $this->records[$id];
        }
         else {
            return null;
         }
    }

    /**
     * Returns the fields and their types
     * @return mixed
     */
    function fields() {
        return $this->fields;
    }

    /**
     * @return array|null
     */
    function records() {
        $results = null;
        if (!empty($this->records)) {
            foreach ($this->records as $rid => $record) {
                $results[] = $record->asObject();
            }
        }

        return $results;
    }


    /**
     * @return false|string
     */
    function __toString()
    {
        $results = null;

        if (!empty($this->records)) {
            foreach ($this->records as $rid => $record) {
                $results[] = $record->asObject();
            }
        }

        if (!empty($results)) {
            return json_encode((object)["recordsTotal" => $this->noOfRecords, "recordsFiltered" => $this->noOfRecords, "data" => $results, "error" => null]);
        } else {
            return json_encode((object)["recordsTotal" => 0, "recordsFiltered" => 0, "data" => [], "error" => $this->error->getErrorText()]);
        }

    }

    /**
     * @return mixed
     */
    function getError() {
        return $this->error->getError();
    }

}
