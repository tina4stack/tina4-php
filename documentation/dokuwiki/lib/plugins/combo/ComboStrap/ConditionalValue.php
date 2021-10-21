<?php


namespace ComboStrap;


class ConditionalValue
{

    const CANONICAL = "conditional";
    /**
     * @var string
     */
    private $value;
    /**
     * @var string
     */
    private $breakpoint;

    /**
     * ConditionalValue constructor.
     */
    public function __construct($value)
    {
        $array = explode("-", $value);
        $sizeof = sizeof($array);
        switch ($sizeof) {
            case 0:
                LogUtility::msg("There is no value in ($value)", LogUtility::LVL_MSG_ERROR, self::CANONICAL);
                $this->breakpoint = "";
                $this->value = "";
                break;
            case 1:
                $this->breakpoint = "";
                $this->value = $array[0];
                break;
            case 2:
                $this->breakpoint = $array[0];
                $this->value = $array[1];
                break;
            default:
                LogUtility::msg("The screen conditional value ($value) should have only one separator character `-`", LogUtility::LVL_MSG_ERROR, self::CANONICAL);
                $this->breakpoint = $array[$sizeof-2];
                $this->value = $array[$sizeof-1];
                break;
        }
    }

    public static function createFrom($value)
    {
        return new ConditionalValue($value);
    }

    public function getBreakpoint()
    {
        return $this->breakpoint;
    }

    public function getValue()
    {
        return $this->value;
    }


}
