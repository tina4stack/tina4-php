<?php


namespace ComboStrap;


class Toggle
{

    /**
     * An indicator attribute that tells if the target element is collapsed or not (accordion)
     */
    const COLLAPSED = "collapsed";


    /**
     * The collapse attribute are the same
     * for all component except a link
     * @param TagAttributes $attributes
     */
    public
    static function processToggle(&$attributes)
    {

        $collapse = "toggleTargetId";
        if ($attributes->hasComponentAttribute($collapse)) {
            $targetId = $attributes->getValueAndRemoveIfPresent($collapse);
        } else {
            $targetId = $attributes->getValueAndRemoveIfPresent("collapse");
        }
        if ($targetId != null) {
            $bootstrapNamespace = "bs-";
            if (Bootstrap::getBootStrapMajorVersion() == Bootstrap::BootStrapFourMajorVersion) {
                $bootstrapNamespace = "";
            }
            /**
             * We can use it in a link
             */
            if (substr($targetId, 0, 1) != "#") {
                $targetId = "#" . $targetId;
            }
            $attributes->addComponentAttributeValue("data-{$bootstrapNamespace}toggle", "collapse");
            $attributes->addComponentAttributeValue("data-{$bootstrapNamespace}target", $targetId);

        }

        $collapsed = self::COLLAPSED;
        if ($attributes->hasComponentAttribute($collapsed)) {
            $value = $attributes->getValueAndRemove($collapsed);
            if ($value) {
                $attributes->addClassName("collapse");
            }
        }
    }

}
