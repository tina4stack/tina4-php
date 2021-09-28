<?php
/**
 * Copyright (c) 2021. ComboStrap, Inc. and its affiliates. All Rights Reserved.
 *
 * This source code is licensed under the GPL license found in the
 * COPYING  file in the root directory of this source tree.
 *
 * @license  GPL 3 (https://www.gnu.org/licenses/gpl-3.0.en.html)
 * @author   ComboStrap <support@combostrap.com>
 *
 */

namespace ComboStrap;


class Shadow
{

    const ELEVATION_ATT = "elevation";
    const SHADOW_ATT = "shadow";
    const CANONICAL = "shadow";

    const CONF_DEFAULT_VALUE = "defaultShadowLevel";

    /**
     * Historically, this is the shadow of material design for the button
     */
    const MEDIUM_ELEVATION_CLASS = "shadow-md-combo";
    const SNIPPET_ID = "shadow";

    const CONF_SMALL_LEVEL_VALUE = "small";
    const CONF_MEDIUM_LEVEL_VALUE = "medium";
    const CONF_LARGE_LEVEL_VALUE = "large";
    const CONF_EXTRA_LARGE_LEVEL_VALUE = "extra-large";

    /**
     * @param TagAttributes $attributes
     */
    public static function process(&$attributes)
    {
        $elevationValue = "";

        if ($attributes->hasComponentAttribute(self::ELEVATION_ATT)) {
            $elevationValue = $attributes->getValueAndRemove(self::ELEVATION_ATT);
        } else {
            if ($attributes->hasComponentAttribute(self::SHADOW_ATT)) {
                $elevationValue = $attributes->getValueAndRemove(self::SHADOW_ATT);
            }
        }

        if (!empty($elevationValue)) {

            $shadowClass = self::getClass($elevationValue);
            if (!empty($shadowClass)) {
                $attributes->addClassName($shadowClass);
            }

        }

    }


    public
    static function getDefaultClass()
    {
        $defaultValue = PluginUtility::getConfValue(self::CONF_DEFAULT_VALUE);
        return self::getClass($defaultValue);
    }

    public
    static function getClass($value)
    {
        // To string because if the value was true, the first string case
        // would be chosen :(
        if ($value === true) {
            $value = "true";
        }
        switch ($value) {
            case self::CONF_SMALL_LEVEL_VALUE:
            case "sm":
                return "shadow-sm";
                break;
            case self::CONF_MEDIUM_LEVEL_VALUE:
            case "md";
                PluginUtility::getSnippetManager()->upsertCssSnippetForBar(self::SNIPPET_ID);
                return self::MEDIUM_ELEVATION_CLASS;
            case self::CONF_LARGE_LEVEL_VALUE:
            case "lg":
                return "shadow";
                break;
            case self::CONF_EXTRA_LARGE_LEVEL_VALUE:
            case "xl":
            case "high":
                return "shadow-lg";
                // Old deprecated: $styleProperties["box-shadow"] = "0 0 0 .2em rgba(3,102,214,0),0 13px 27px -5px rgba(50,50,93,.25),0 8px 16px -8px rgba(0,0,0,.3),0 -6px 16px -6px rgba(0,0,0,.025)";
                break;
            case "true":
            case "1":
                return self::getDefaultClass();
                break;
            default:
                LogUtility::msg("The shadow / elevation value ($value) is unknown", LogUtility::LVL_MSG_ERROR, self::CANONICAL);
                return null;
                break;
        }
    }

    /**
     * @param TagAttributes $attributes
     */
    public
    static function addMediumElevation(&$attributes)
    {
        $attributes->addClassName(self::MEDIUM_ELEVATION_CLASS);
    }

}
