<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/03/01
 * Time: 04:35 PM
 * Notes: A record is a single part of the result set
 */
namespace Tina4;

/**
 * Class DataRecord
 * @package Tina4
 */
class DataRecord
{
    private $original;

    public function isBinary($string):bool
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
     * DataRecord constructor Converts array to object
     * @param array $record Array of records
     */
    function __construct($record)
    {
        if (!empty($record)) {
            $this->original = (object)$record;

            foreach ($record as $column => $value) {
                if ($this->isBinary($value)) {
                    $value = \base64_encode($value);
                    $this->original->{$column} = $value;
                }
                $columnName = $column;
                $this->$columnName = $value;
                $columnName = strtoupper($column);
                $this->$columnName = $value;
            }
        }

        //print_r ($record);
    }

    /**
     * Converts array to object
     * @return object
     */
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
