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
     * @param $fields
     * @param $noOfRecords
     * @param int $offSet
     */
    function __construct($records, $fields, $noOfRecords, $offSet=0)
    {
        $this->records = $records;
        $this->fields = $fields;
        $this->noOfRecords = $noOfRecords;
        $this->offSet = $offSet;
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
     *
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



    function __toString()
    {
        $results = null;

        if (!empty($this->records)) {
            foreach ($this->records as $rid => $record) {
                $results[] = $record->asObject();
            }
        }

        if (!empty($results)) {
            $json = json_encode((object)["recordsTotal" => $this->noOfRecords, "recordsFiltered" => $this->noOfRecords, "data" => $results]);

            return $json;
        } else {
            return json_encode((object)["recordsTotal" => 0, "recordsFiltered" => 0, "data" => [], "error" => "No records"]);
        }

    }

    function getError() {
        return $this->error;
    }

}