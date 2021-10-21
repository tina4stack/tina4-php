<?php


namespace ComboStrap;


class TextColor
{

    const TEXT_COLOR_ATTRIBUTE = "text-color";
    const CSS_ATTRIBUTE = "color";
    const CANONICAL = self::TEXT_COLOR_ATTRIBUTE;
    const TEXT_TAGS = [
        \syntax_plugin_combo_text::TAG,
        \syntax_plugin_combo_itext::TAG
    ];
    const TEXT_COLORS = array(
        'primary',
        'secondary',
        'success',
        'danger',
        'warning',
        'info',
        'light',
        'dark',
        'body',
        'muted',
        'white',
        'black-50',
        'white-50'
    );

    /**
     * @param TagAttributes $attributes
     */
    public static function processTextColorAttribute(TagAttributes &$attributes)
    {

        $colorAttributes = [TextColor::CSS_ATTRIBUTE, TextColor::TEXT_COLOR_ATTRIBUTE];
        foreach ($colorAttributes as $colorAttribute) {
            if ($attributes->hasComponentAttribute($colorAttribute)) {
                $colorValue = $attributes->getValueAndRemove($colorAttribute);
                $lowerCase = strtolower($colorValue);
                if (in_array($lowerCase, self::TEXT_COLORS)) {
                    /**
                     * The bootstrap text class
                     * https://getbootstrap.com/docs/5.0/utilities/colors/#colors
                     */
                    $attributes->addClassName("text-$lowerCase");
                } else {
                    /**
                     * Other Text Colors
                     */
                    $colorValue = ColorUtility::getColorValue($colorValue);
                    if (!empty($colorValue)) {
                        $attributes->addStyleDeclaration(TextColor::CSS_ATTRIBUTE, $colorValue);
                    }
                }
                break;
            }
        }


    }



}
