<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/03/01
 * Time: 04:35 PM
 * Notes: A record is a single part of the result set
 */
namespace Tina4;

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
        return $this->original;
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
        }
    }
}
