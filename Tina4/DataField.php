<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/03/27
 * Time: 11:29 PM
 * Notes: Make an array of these for a result set from a data query
 */
namespace Tina4;

class DataField {
    public $index=0;
    public $fieldName;
    public $fieldAlias;
    public $dataType=DATA_TYPE_TEXT;
    public $size=0;
    public $decimals=0;
    public $alignment=DATA_ALIGN_LEFT;

    function __construct($index,$fieldName,$fieldAlias,$dataType,$size=0,$decimals=0,$alignment=DATA_ALIGN_LEFT)
    {
        $this->index = $index;
        $this->fieldName = $fieldName;
        $this->fieldAlias = $fieldAlias;
        $this->dataType = $dataType;
        $this->size = $size;
        $this->decimals = $decimals;
        $this->alignment = $alignment;
    }

    function getFieldName($case="") {
        if ($case = DATA_CASE_UPPER) {
           return strtoupper($this->fieldName);
        }  else {
           return $this->fieldName;
        }
    }

    function getFieldAlias($case="") {
        if ($case = DATA_CASE_UPPER) {
            return strtoupper($this->fieldAlias);
        }  else {
            return $this->fieldAlias;
        }
    }

}