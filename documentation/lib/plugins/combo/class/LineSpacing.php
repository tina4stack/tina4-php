<?php


namespace ComboStrap;


use syntax_plugin_combo_text;

class LineSpacing
{

    const CANONICAL = syntax_plugin_combo_text::TAG;


    /**
     * Process the line-spacing attribute
     * https://getbootstrap.com/docs/5.0/utilities/text/#line-height
     * @param TagAttributes $attributes
     */
    public static function processLineSpacingAttributes(&$attributes)
    {

        // Spacing is just a class
        $lineSpacing = "line-spacing";
        if ($attributes->hasComponentAttribute($lineSpacing)) {

            $bootstrapVersion = Bootstrap::getBootStrapMajorVersion();
            if ($bootstrapVersion != Bootstrap::BootStrapFiveMajorVersion) {
                LogUtility::msg("The line-spacing attribute is only implemented with Bootstrap 5. If you want to use this attribute, you should " . PluginUtility::getUrl(Bootstrap::CANONICAL, "change the Bootstrap version") . ".", self::CANONICAL);
                return;
            }

            $lineSpacingValue = trim(strtolower($attributes->getValueAndRemove($lineSpacing)));
            switch ($lineSpacingValue) {
                case "xs":
                case "extra-small":
                    $attributes->addClassName("lh-1");
                    break;
                case "sm":
                case "small":
                    $attributes->addClassName("lh-sm");
                    break;
                case "md":
                case "medium":
                    $attributes->addClassName("lh-base");
                    break;
                case "lg":
                case "large":
                    $attributes->addClassName("lh-lg");
                    break;
                default:
                    LogUtility::msg("The line-spacing value ($lineSpacingValue) is not a valid value.", LogUtility::LVL_MSG_ERROR, self::CANONICAL);
                    break;
            }
        }
    }

}
