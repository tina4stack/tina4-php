<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/03/27
 * Time: 11:29 PM
 * Notes: Make an array of these for a result set from a data query
 */

namespace Tina4;

/**
 * Class DataField
 * @package Tina4
 */
class DataField
{
    /**
     * @var integer Index
     */
    public $index = 0;

    /**
     * @var string Name of field
     */
    public $fieldName;

    /**
     * @var string Alias of field
     */
    public $fieldAlias;

    /**
     * @var integer|string Type of data e.g. NUMERIC
     */
    public $dataType = DATA_TYPE_TEXT;

    /**
     * @var integer Size of data
     */
    public $size = 0;

    /**
     * @var integer Number of decimals
     */
    public $decimals = 0;

    /**
     * @var integer Whether the data is aligned left or right
     */
    public $alignment = DATA_ALIGN_LEFT;

    /**
     * DataField constructor.
     * @param integer $index Index
     * @param string $fieldName Field name
     * @param string $fieldAlias Field alias
     * @param string $dataType Type of data e.g. NUMERIC
     * @param integer $size Size of data
     * @param integer $decimals Number of decimals
     * @param integer $alignment Whether the data is aligned left (0) or right (1)
     */
    function __construct($index, $fieldName, $fieldAlias, $dataType, $size = 0, $decimals = 0, $alignment = DATA_ALIGN_LEFT)
    {
        $this->index = $index;
        $this->fieldName = $fieldName;
        $this->fieldAlias = $fieldAlias;
        $this->dataType = $dataType;
        $this->size = $size;
        $this->decimals = $decimals;
        $this->alignment = $alignment;
    }

    /**
     * Gets the field name
     * @param string $case
     * @return string
     */
    function getFieldName($case = "")
    {
        if ($case = DATA_CASE_UPPER) {
            return strtoupper($this->fieldName);
        } else {
            return $this->fieldName;
        }
    }

    /**
     * Gets the field alias
     * @param string $case
     * @return string
     */
    function getFieldAlias($case = "")
    {
        if ($case = DATA_CASE_UPPER) {
            return strtoupper($this->fieldAlias);
        } else {
            return $this->fieldAlias;
        }
    }

}