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

/**
 * Class Sticky
 * Manage the stickiness of component
 * with https://sticksy.js.org/
 * @package ComboStrap
 */
class Position
{
    const STICKY_ATTRIBUTE = "sticky";
    const STICKY = "sticky";
    const STICKY_CLASS = "combo-sticky";

    const POSITION_ATTRIBUTE = "position";
    const POSITION_SNIPPET_ID = "position";
    const POSITION_QUARTILE_SNIPPET_ID = "position-quartile";

    /**
     * Process stickiness
     * @param TagAttributes $attributes
     */
    public static function processStickiness(&$attributes)
    {
        if ($attributes->hasComponentAttribute(self::STICKY_ATTRIBUTE)) {
            $sticky = strtolower($attributes->getValueAndRemove(self::STICKY_ATTRIBUTE));
            if ($sticky == "true") {
                $stickyClass = self::STICKY_CLASS;
                $attributes->addClassName($stickyClass);
                $snippetManager = PluginUtility::getSnippetManager();
                $snippetManager->upsertTagsForBar(self::STICKY,
                    array(
                        "script" => [
                            array(
                                "src" => "https://cdn.jsdelivr.net/npm/sticksy@0.2.0/dist/sticksy.min.js",
                                "integrity" => "sha256-H6uQ878/jyt6w1oBNhL6s01iAfWxACrWvVXCBjZsrGM=",
                                "crossorigin" => "anonymous"
                            )]
                    ));
                /**
                 * If top bar
                 */
                $jsSnippet = <<<EOF
let fixedNavbar = document.querySelector(".navbar.fixed-top");
let topSpacing = fixedNavbar.offsetHeight;
var stickyElements = Sticksy.initializeAll('.$stickyClass',{topSpacing: topSpacing})
EOF;
                $snippetManager
                    ->attachJavascriptSnippetForBar(self::STICKY)
                    ->setContent($jsSnippet);
            }

        }

    }

    /**
     * @param TagAttributes $attributes
     */
    public static function processPosition(&$attributes)
    {
        if ($attributes->hasComponentAttribute(self::POSITION_ATTRIBUTE)) {
            $position = strtolower($attributes->getValueAndRemove(self::POSITION_ATTRIBUTE));
            if (Bootstrap::getBootStrapMajorVersion() < Bootstrap::BootStrapFiveMajorVersion) {
                $snippetManager = PluginUtility::getSnippetManager();
                $snippetManager->attachCssSnippetForBar(self::POSITION_SNIPPET_ID);
            }

            // Class value comes from
            // https://getbootstrap.com/docs/5.0/utilities/position/#center-elements
            switch ($position) {
                case "top-left":
                case "left-top":
                    $attributes->addClassName("position-absolute top-0 start-0 translate-middle");
                    break;
                case "top-quartile-1":
                case "quartile-1-top":
                    self::addQuartileCss();
                    $attributes->addClassName("position-absolute top-0 start-25 translate-middle");
                    break;
                case "top-center":
                case "center-top":
                    $attributes->addClassName("position-absolute top-0 start-50 translate-middle");
                    break;
                case "top-quartile-3":
                case "quartile-3-top":
                    self::addQuartileCss();
                    $attributes->addClassName("position-absolute top-0 start-75 translate-middle");
                    break;
                case "top-right":
                case "right-top":
                    $attributes->addClassName("position-absolute top-0 start-100 translate-middle");
                    break;
                case "left-quartile-1":
                case "quartile-1-left":
                    self::addQuartileCss();
                    $attributes->addClassName("position-absolute top-25 start-0 translate-middle");
                    break;
                case "left-center":
                case "center-left":
                    $attributes->addClassName("position-absolute top-50 start-0 translate-middle");
                    break;
                case "center-center":
                case "center":
                    $attributes->addClassName("position-absolute top-50 start-50 translate-middle");
                    break;
                case "right-center":
                case "center-right":
                    $attributes->addClassName("position-absolute top-50 start-100 translate-middle");
                    break;
                case "bottom-left":
                case "left-bottom":
                    $attributes->addClassName("position-absolute top-100 start-0 translate-middle");
                    break;
                case "bottom-center":
                case "center-bottom":
                    $attributes->addClassName("position-absolute top-100 start-50 translate-middle");
                    break;
                case "bottom-right":
                case "right-bottom":
                    $attributes->addClassName("position-absolute top-100 start-100 translate-middle");
                    break;
                case "relative":
                case "absolute":
                case "fixed":
                case "static":
                    /**
                     * The css value are also supported
                     * (to define that for instance the parent of a background should be at minimum relative)
                     */
                    $attributes->addStyleDeclaration("position", $position);
                    break;

            }
        }

    }

    private static function addQuartileCss()
    {
        $snippetManager = PluginUtility::getSnippetManager();
        $snippetManager->attachCssSnippetForBar(self::POSITION_QUARTILE_SNIPPET_ID);
    }
}
