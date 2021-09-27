<?php


namespace ComboStrap;


class Align
{

    /**
     * @param TagAttributes $attributes
     */
    public static function processAlignAttributes(&$attributes)
    {
        // The class shortcut
        $align = MediaLink::ALIGN_KEY;
        if ($attributes->hasComponentAttribute($align)) {

            $alignValue = $attributes->getValueAndRemove($align);

            if ($alignValue !== null && $alignValue !== "") {
                switch ($alignValue) {
                    case "center":
                        $attributes->addClassName(PluginUtility::CENTER_CLASS);
                        break;
                    case "right":
                    case "end":
                        if (Bootstrap::getBootStrapMajorVersion()==Bootstrap::BootStrapFourMajorVersion) {
                            $attributes->addStyleDeclaration("margin-left", "auto");
                        } else {
                            $attributes->addClassName("ms-auto");
                        }
                        $attributes->addStyleDeclaration("width", "fit-content");
                        break;
                }

                /**
                 * For inline element,
                 * center should be a block
                 * (svg is not a block by default for instance)
                 * !
                 * this should not be the case for flex block such as a row
                 * therefore the condition
                 * !
                 */
                if (in_array($attributes->getLogicalTag(), TagAttributes::INLINE_LOGICAL_ELEMENTS)) {
                    $attributes->addClassName("d-block");
                }
            }
        }
    }
}
