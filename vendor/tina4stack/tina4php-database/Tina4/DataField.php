<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Data field layout for ORM and database result set
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
     * @var string Description of the field
     */
    public $description;

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
     * @var string Default value for the field
     */
    public $defaultValue;

    /**
     * @var bool is the field not null
     */
    public $isNotNull;

    /**
     * @var $isPrimaryKey;
     */
    public $isPrimaryKey;

    /**
     * @var $isForeignKey;
     */
    public $isForeignKey;

    /**
     * DataField constructor.
     * @param int $index Index
     * @param string $fieldName Field name
     * @param string $fieldAlias Field alias
     * @param string $dataType Type of data e.g. NUMERIC
     * @param int $size Size of data
     * @param int $decimals Number of decimals
     * @param int $alignment Whether the data is aligned left (0) or right (1)
     */
    public function __construct(int $index,
                                string $fieldName,
                                string $fieldAlias,
                                string $dataType,
                                int $size = 0,
                                int $decimals = 0,
                                int $alignment = DATA_ALIGN_LEFT
    ) {
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
     * @param bool $upperCase Return field name as upper case?
     * @return string The field name
     */
    final public function getFieldName(bool $upperCase = false) : string
    {
        if ($upperCase === DATA_CASE_UPPER) {
            return strtoupper($this->fieldName);
        }

        return $this->fieldName;
    }

    /**
     * Gets the field alias
     * @param bool $upperCase Return field name as upper case?
     * @return string The field alias
     */
    final public function getFieldAlias(bool $upperCase = false) : string
    {
        if ($upperCase === DATA_CASE_UPPER) {
            return strtoupper($this->fieldAlias);
        }

        return $this->fieldAlias;
    }

    /**
     * Gets the default value for the field
     * @return string
     */
    final public function getDefaultValue(): string
    {
        return $this->defaultValue;
    }

    /**
     * Is the field a not null field
     * @return bool
     */
    final public function isNotNull(): bool
    {
        return $this->isNotNull;
    }

    /**
     * Is the field a primary key
     * @return bool
     */
    final public function isPrimaryKey(): bool
    {
        return $this->isPrimaryKey;
    }

    /**
     * Is the field a foreign key
     * @return bool
     */
    final public function isForeignKey(): bool
    {
        return $this->isForeignKey;
    }
}
