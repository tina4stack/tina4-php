<?php

namespace Tina4;
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
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
    public function __construct(int $index, string $fieldName, string $fieldAlias, string $dataType, $size = 0, $decimals = 0, $alignment = DATA_ALIGN_LEFT)
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
     * @param string $upperCase
     * @return string
     */
    public function getFieldName($upperCase = false)
    {
        if ($upperCase === DATA_CASE_UPPER) {
            return strtoupper($this->fieldName);
        } else {
            return $this->fieldName;
        }
    }

    /**
     * Gets the field alias
     * @param string $upperCase
     * @return string
     */
    public function getFieldAlias($upperCase = false)
    {
        if ($upperCase === DATA_CASE_UPPER) {
            return strtoupper($this->fieldAlias);
        } else {
            return $this->fieldAlias;
        }
    }

}