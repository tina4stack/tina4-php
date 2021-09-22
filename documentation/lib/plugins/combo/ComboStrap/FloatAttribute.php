<?php


namespace ComboStrap;

/**
 * Class Float
 * @package ComboStrap
 *
 *
 * Don't use float:
 * PHP Fatal error:  Cannot use 'Float' as class name as it is reserved
 */
class FloatAttribute
{
    const CANONICAL = "float";
    const CONF_FLOAT_DEFAULT_BREAKPOINT = "floatDefaultBreakpoint";
    const FLOAT_KEY = "float";

    /**
     * @param TagAttributes $attributes
     */
    public static function processFloat(&$attributes)
    {
        // The class shortcut
        $float = self::FLOAT_KEY;
        if ($attributes->hasComponentAttribute($float)) {
            $floatValue = $attributes->getValueAndRemove($float);
            $floatedValues = StringUtility::explodeAndTrim($floatValue, " ");
            foreach ($floatedValues as $floatedValue) {

                /**
                 * Bootstrap 5 has switch from left, right to start, end
                 */
                if (Bootstrap::getBootStrapMajorVersion() == Bootstrap::BootStrapFiveMajorVersion) {
                    switch ($floatedValue) {
                        case "left":
                            $floatedValue = "start";
                            break;
                        case "right":
                            $floatValue = "end";
                            break;
                    }
                }

                /**
                 * If there is no break point in the value
                 */
                switch ($floatedValue) {
                    case "left":
                    case "right":
                    case "start":
                    case "end":
                    case "none":
                        $defaultBreakpoint = PluginUtility::getConfValue(self::CONF_FLOAT_DEFAULT_BREAKPOINT, "sm");
                        $floatValue = "{$defaultBreakpoint}-$floatValue";
                        break;
                }

                /**
                 * Automatic spacing when on the right
                 * To not touch the text
                 */
                switch ($floatedValue) {
                    case "right":
                    case "end":
                        if (!$attributes->hasComponentAttribute("spacing")){
                            $attributes->addComponentAttributeValue("spacing","ms-3");
                        }
                        break;
                }

                $attributes->addClassName("float-{$floatValue}");
            }
            /**
             * By default, we don't float on extra small screen
             */
            if (!StringUtility::contain("xs", $floatValue)) {
                $attributes->addClassName("float-xs-none");
            }

            // position relative and z-index are needed to put the float above
            $attributes->addStyleDeclaration("position", "relative!important");
            $attributes->addStyleDeclaration("z-index", 1);
        }
    }
}
