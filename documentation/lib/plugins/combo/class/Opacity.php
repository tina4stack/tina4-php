<?php


namespace ComboStrap;


class Opacity
{

    const OPACITY_ATTRIBUTE = "opacity";

    /**
     * Set the opacity
     *
     * For a background image, the opacity is set on the {@link Background::processBackgroundAttributes()}
     * Because the image background parameters are in array, it seems
     * that they are not interfering
     *
     * @param TagAttributes $tagAttributes
     */
    public static function processOpacityAttribute(TagAttributes &$tagAttributes){

        if ($tagAttributes->hasComponentAttribute(self::OPACITY_ATTRIBUTE)) {
            $value = $tagAttributes->getValueAndRemove(self::OPACITY_ATTRIBUTE);
            $tagAttributes->addStyleDeclaration("opacity",$value);
        }

    }


}
