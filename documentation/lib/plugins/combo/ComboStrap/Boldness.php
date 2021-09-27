<?php


namespace ComboStrap;


class Boldness
{

    const BOLDNESS_ATTRIBUTE = "boldness";
    const CANONICAL = self::BOLDNESS_ATTRIBUTE;

    const BOOLEAN_ATTRIBUTES = ["bold", "bolder"];

    /**
     *
     * https://getbootstrap.com/docs/5.0/utilities/text/#font-weight-and-italics
     * @param TagAttributes $tagAttributes
     */
    public static function processBoldnessAttribute(TagAttributes &$tagAttributes)
    {

        if ($tagAttributes->hasComponentAttribute(self::BOLDNESS_ATTRIBUTE)) {
            $value = $tagAttributes->getValueAndRemove(self::BOLDNESS_ATTRIBUTE);
        }

        foreach(Boldness::BOOLEAN_ATTRIBUTES as $booleanAttribute) {
            if ($tagAttributes->hasComponentAttribute($booleanAttribute)) {
                $tagAttributes->removeComponentAttribute($booleanAttribute);
                $value = $booleanAttribute;
            }
        }

        if (!empty($value)) {
            if (in_array($value, ["bolder", "lighter"])) {
                $tagAttributes->addClassName("fw-$value");
            } else {
                $value = Boldness::toNumericValue($value);
                $tagAttributes->addStyleDeclaration("font-weight", $value);
            }
        }


    }

    private static function toNumericValue($value)
    {
        if (is_numeric($value)) {
            return $value;
        }
        $value = strtolower($value);
        switch ($value) {
            case 'thin':
                return 100;
            case 'extra-light':
                return 200;
            case 'light':
                return 300;
            case 'normal':
                return 400;
            case 'medium':
                return 500;
            case 'semi-bold':
                return 600;
            case 'bold':
                return 700;
            case 'extra-bold':
                return 800;
            case 'black':
                return 900;
            case 'extra-black':
                return 950;
            default:
                LogUtility::msg("The boldness name ($value) is unknown. The attribute was not applied", LogUtility::LVL_MSG_ERROR, self::CANONICAL);
                return 400;
        }

    }


}
