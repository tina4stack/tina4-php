<?php


namespace ComboStrap;


class FontSize
{

    const FONT_SIZE = "font-size";
    const CANONICAL = self::FONT_SIZE;

    const SCALE_TO_HEADING_NUMBER = [
            6=>1,
            5=>2,
            4=>3,
            3=>4,
            2=>5,
            1=>6
        ];
    const HEADING_NUMBER = [
        "h1"=>1,
        "h2"=>2,
        "h3"=>3,
        "h4"=>4,
        "h5"=>5,
        "h6"=>6
    ];

    /**
     *
     * https://getbootstrap.com/docs/5.0/utilities/text/#font-size
     * @param TagAttributes $tagAttributes
     */
    public static function processFontSizeAttribute(TagAttributes &$tagAttributes)
    {

        if ($tagAttributes->hasComponentAttribute(self::FONT_SIZE)) {
            $value = $tagAttributes->getValueAndRemove(self::FONT_SIZE);
            if(is_numeric($value)){
                if (key_exists($value, self::SCALE_TO_HEADING_NUMBER)){
                    $headingValue = self::SCALE_TO_HEADING_NUMBER[$value];
                    $tagAttributes->addClassName("fs-$headingValue");
                } else {
                    LogUtility::msg("The font-size scale value ($value) is not between 1 and 6.",LogUtility::LVL_MSG_ERROR,self::CANONICAL);
                }

            } else {
                if (key_exists($value, self::HEADING_NUMBER)){
                    $headingValue = self::HEADING_NUMBER[$value];
                    $tagAttributes->addClassName("fs-$headingValue");
                } else {
                    $tagAttributes->addStyleDeclaration("font-size", $value);
                }
            }
        }

    }



}
