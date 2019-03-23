<?php

/**
 * Created by PhpStorm.
 * User: S946115
 * Date: 2016/03/01
 * Time: 04:35 PM
 * Notes: A record is a single part of the result set
 */
class DataRecord
{
    private $original;

    function __construct($record)
    {
        if (!empty($record)) {
            $this->original = (object)$record;

            foreach ($record as $column => $value) {
                $columnName = strtoupper($column);
                $this->$columnName = $value;
            }
        }
    }

    function asObject () {
        return json_decode(json_encode($this->original));
    }

    function asJSON () {
        return json_encode($this->original);
    }

    function __toString()
    {
        return json_encode($this->original);
    }

    function byName ($name) {
        $columnName = strtoupper($name);
        if (!empty($this->original) && !empty($this->$columnName)) {
            return $this->$columnName;
        }
    }
}